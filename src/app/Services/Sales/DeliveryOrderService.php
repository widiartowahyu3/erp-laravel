<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DocumentType;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Services\Inventory\StockService;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use App\Support\Sales\SalesOrderProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryOrderService
{
    use RequiresTransaction;

    public function __construct(
        private readonly StockService $stockService
    ) {}

    /**
     * Create a new draft Delivery Order for an approved or partially delivered Sales Order.
     *
     * @param array{
     *     sales_order_id: int|string,
     *     customer_id: int|string,
     *     warehouse_id: int|string,
     *     delivery_date: string,
     *     shipping_address?: string|null,
     *     notes?: string|null,
     *     items: list<array{
     *         sales_order_item_id: int|string,
     *         product_id: int|string,
     *         quantity: string|int|float
     *     }>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, ?int $userId = null): DeliveryOrder
    {
        return DB::transaction(function () use ($data, $userId): DeliveryOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict S7 lock order: Lock SO first
            $so = SalesOrder::query()
                ->whereKey($data['sales_order_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($so->status !== SalesOrderStatus::APPROVED && $so->status !== SalesOrderStatus::PARTIALLY_DELIVERED) {
                throw new BusinessRuleException(
                    'Surat jalan hanya dapat dibuat untuk pesanan penjualan yang disetujui atau terkirim sebagian.',
                    422,
                    'sales_order_id'
                );
            }

            if ((int) $data['customer_id'] !== $so->customer_id) {
                throw new BusinessRuleException('Pelanggan pada surat jalan tidak cocok dengan pesanan penjualan.', 422, 'customer_id');
            }

            if ((int) $data['warehouse_id'] !== $so->warehouse_id) {
                throw new BusinessRuleException('Gudang pada surat jalan tidak cocok dengan pesanan penjualan.', 422, 'warehouse_id');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Surat jalan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $soItemIds = array_map(fn (array $item): int => (int) $item['sales_order_item_id'], $data['items']);
            if (count($soItemIds) !== count(array_unique($soItemIds))) {
                throw new BusinessRuleException('Item pesanan penjualan tidak boleh duplikat pada baris surat jalan.', 422, 'items');
            }

            // 2. Strict S7 lock order: Lock SO items ordered by id ASC
            $soItems = SalesOrderItem::query()
                ->where('sales_order_id', $so->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $aggregatedQuantities = [];
            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $soItemId = (int) $itemData['sales_order_item_id'];
                $soItem = $soItems->get($soItemId);

                if ($soItem === null) {
                    throw new BusinessRuleException("Item pesanan penjualan #{$soItemId} tidak ditemukan pada pesanan penjualan ini.", 422, 'items');
                }

                if ((int) $itemData['product_id'] !== $soItem->product_id) {
                    throw new BusinessRuleException("Produk pada baris ke-{$lineNo} tidak cocok dengan rincian pesanan penjualan.", 422, 'items');
                }

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);
                if (Decimal::cmp($qty, '0') <= 0) {
                    throw new BusinessRuleException("Kuantitas pengiriman baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
                }

                $currentAggregated = $aggregatedQuantities[$soItemId] ?? '0.0000';
                $aggregatedQuantities[$soItemId] = Decimal::add($currentAggregated, $qty, Decimal::SCALE_QUANTITY);

                $remaining = $soItem->remainingQuantity();
                if (Decimal::cmp($aggregatedQuantities[$soItemId], $remaining) > 0) {
                    throw new BusinessRuleException("Kuantitas pengiriman baris ke-{$lineNo} melebihi sisa pesanan penjualan.", 422, 'items');
                }

                $lineNo++;
            }

            $deliveryDate = (string) $data['delivery_date'];
            $number = DocumentNumberService::generate(DocumentType::DO, $deliveryDate);

            // Default shipping address from customer if omitted (S11)
            $shippingAddress = isset($data['shipping_address']) && trim((string) $data['shipping_address']) !== ''
                ? trim((string) $data['shipping_address'])
                : Customer::query()->whereKey($so->customer_id)->value('address');

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $do = DeliveryOrder::create([
                'number' => $number,
                'sales_order_id' => $so->id,
                'customer_id' => (int) $data['customer_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'delivery_date' => $deliveryDate,
                'shipping_address' => $shippingAddress,
                'status' => DeliveryOrderStatus::DRAFT,
                'notes' => $notes,
                'created_by' => $effectiveUserId,
                'updated_by' => $effectiveUserId,
            ]);

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                DeliveryOrderItem::create([
                    'delivery_order_id' => $do->id,
                    'line_no' => $lineNo++,
                    'sales_order_item_id' => (int) $itemData['sales_order_item_id'],
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'unit_cost' => '0.0000', // S4: snapshot cost is 0.0000 until posted
                ]);
            }

            return $do->load(['items.product', 'salesOrder', 'customer', 'warehouse']);
        });
    }

    /**
     * Update a draft Delivery Order and replace its items atomically.
     *
     * @param array{
     *     delivery_date: string,
     *     shipping_address?: string|null,
     *     notes?: string|null,
     *     customer_id?: int|string,
     *     warehouse_id?: int|string,
     *     items: list<array{
     *         sales_order_item_id: int|string,
     *         product_id: int|string,
     *         quantity: string|int|float
     *     }>
     * } $data
     *
     * @throws DocumentNotEditableException
     * @throws BusinessRuleException
     */
    public function update(DeliveryOrder $deliveryOrder, array $data, ?int $userId = null): DeliveryOrder
    {
        return DB::transaction(function () use ($deliveryOrder, $data, $userId): DeliveryOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict S7 lock order: Lock SO first
            $so = SalesOrder::query()
                ->whereKey($deliveryOrder->sales_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock DO second
            $lockedDo = DeliveryOrder::query()
                ->whereKey($deliveryOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedDo->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf surat jalan yang dapat diubah.');
            }

            if ($so->status !== SalesOrderStatus::APPROVED && $so->status !== SalesOrderStatus::PARTIALLY_DELIVERED) {
                throw new BusinessRuleException(
                    'Surat jalan hanya dapat diubah jika pesanan penjualan berstatus disetujui atau terkirim sebagian.',
                    422,
                    'sales_order_id'
                );
            }

            if (isset($data['customer_id']) && (int) $data['customer_id'] !== $so->customer_id) {
                throw new BusinessRuleException('Pelanggan pada surat jalan tidak cocok dengan pesanan penjualan.', 422, 'customer_id');
            }

            if (isset($data['warehouse_id']) && (int) $data['warehouse_id'] !== $so->warehouse_id) {
                throw new BusinessRuleException('Gudang pada surat jalan tidak cocok dengan pesanan penjualan.', 422, 'warehouse_id');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Surat jalan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $soItemIds = array_map(fn (array $item): int => (int) $item['sales_order_item_id'], $data['items']);
            if (count($soItemIds) !== count(array_unique($soItemIds))) {
                throw new BusinessRuleException('Item pesanan penjualan tidak boleh duplikat pada baris surat jalan.', 422, 'items');
            }

            // 3. Strict S7 lock order: Lock SO items ordered by id ASC
            $soItems = SalesOrderItem::query()
                ->where('sales_order_id', $so->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $aggregatedQuantities = [];
            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $soItemId = (int) $itemData['sales_order_item_id'];
                $soItem = $soItems->get($soItemId);

                if ($soItem === null) {
                    throw new BusinessRuleException("Item pesanan penjualan #{$soItemId} tidak ditemukan pada pesanan penjualan ini.", 422, 'items');
                }

                if ((int) $itemData['product_id'] !== $soItem->product_id) {
                    throw new BusinessRuleException("Produk pada baris ke-{$lineNo} tidak cocok dengan rincian pesanan penjualan.", 422, 'items');
                }

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);
                if (Decimal::cmp($qty, '0') <= 0) {
                    throw new BusinessRuleException("Kuantitas pengiriman baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
                }

                $currentAggregated = $aggregatedQuantities[$soItemId] ?? '0.0000';
                $aggregatedQuantities[$soItemId] = Decimal::add($currentAggregated, $qty, Decimal::SCALE_QUANTITY);

                $remaining = $soItem->remainingQuantity();
                if (Decimal::cmp($aggregatedQuantities[$soItemId], $remaining) > 0) {
                    throw new BusinessRuleException("Kuantitas pengiriman baris ke-{$lineNo} melebihi sisa pesanan penjualan.", 422, 'items');
                }

                $lineNo++;
            }

            $shippingAddress = array_key_exists('shipping_address', $data)
                ? (isset($data['shipping_address']) && trim((string) $data['shipping_address']) !== ''
                    ? trim((string) $data['shipping_address'])
                    : ($so->customer->address ?? null))
                : $lockedDo->shipping_address;

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $lockedDo->delivery_date = (string) $data['delivery_date'];
            $lockedDo->shipping_address = $shippingAddress;
            $lockedDo->notes = $notes;
            if ($effectiveUserId !== null) {
                $lockedDo->updated_by = $effectiveUserId;
            }
            $lockedDo->save();

            // @audit-bypass: delivery_order_items has no audit columns and items are replaced atomically with draft update
            DeliveryOrderItem::query()->where('delivery_order_id', $lockedDo->id)->delete();

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                DeliveryOrderItem::create([
                    'delivery_order_id' => $lockedDo->id,
                    'line_no' => $lineNo++,
                    'sales_order_item_id' => (int) $itemData['sales_order_item_id'],
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'unit_cost' => '0.0000',
                ]);
            }

            return $lockedDo->load(['items.product', 'salesOrder', 'customer', 'warehouse']);
        });
    }

    /**
     * Post a draft Delivery Order: deducts inventory stock, increments delivered_quantity,
     * snapshots unit_cost from moving average, and advances Sales Order status.
     *
     * @throws BusinessRuleException
     * @throws InsufficientStockException
     * @throws InvalidStateTransitionException
     */
    public function post(DeliveryOrder $deliveryOrder, ?int $userId = null): DeliveryOrder
    {
        return DB::transaction(function () use ($deliveryOrder, $userId): DeliveryOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict S7 lock order: Lock SO first
            $lockedSo = SalesOrder::query()
                ->whereKey($deliveryOrder->sales_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Strict S7 lock order: Lock DO second
            $lockedDo = DeliveryOrder::query()
                ->whereKey($deliveryOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($lockedDo->status, DeliveryOrderStatus::POSTED);

            if ($lockedSo->status === SalesOrderStatus::CLOSED) {
                throw new BusinessRuleException(
                    'Surat jalan tidak dapat diposting untuk pesanan penjualan yang sudah ditutup.',
                    409,
                    'status'
                );
            }

            if ($lockedSo->status !== SalesOrderStatus::APPROVED
                && $lockedSo->status !== SalesOrderStatus::PARTIALLY_DELIVERED
                && $lockedSo->status !== SalesOrderStatus::DELIVERED
            ) {
                throw new BusinessRuleException(
                    'Pesanan penjualan harus berstatus disetujui atau terkirim sebagian untuk diposting pengirimannya.',
                    422,
                    'sales_order_id'
                );
            }

            if ($lockedDo->items()->count() === 0) {
                throw new BusinessRuleException('Surat jalan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $doItems = $lockedDo->items;
            $soItemIds = $doItems->map(fn (DeliveryOrderItem $item): int => (int) $item->sales_order_item_id)->all();
            if (count($soItemIds) !== count(array_unique($soItemIds))) {
                throw new BusinessRuleException('Item pesanan penjualan tidak boleh duplikat pada baris surat jalan.', 422, 'items');
            }

            // 3. Strict S7 lock order: Lock SO items ordered by id ASC
            $soItems = SalesOrderItem::query()
                ->where('sales_order_id', $lockedSo->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 4. Verify remaining quantities under lock with aggregation
            $aggregatedQuantities = [];
            foreach ($doItems as $doItem) {
                $soItemId = (int) $doItem->sales_order_item_id;
                $soItem = $soItems->get($soItemId);
                if ($soItem === null) {
                    throw new BusinessRuleException("Item pesanan penjualan #{$soItemId} tidak ditemukan.", 422, 'items');
                }

                $currentAggregated = $aggregatedQuantities[$soItemId] ?? '0.0000';
                $aggregatedQuantities[$soItemId] = Decimal::add($currentAggregated, (string) $doItem->quantity, Decimal::SCALE_QUANTITY);

                $remaining = $soItem->remainingQuantity();
                if (Decimal::cmp($aggregatedQuantities[$soItemId], $remaining) > 0) {
                    $qtyTrimmed = Decimal::trim((string) $doItem->quantity);
                    $remainingTrimmed = Decimal::trim($remaining);
                    throw new BusinessRuleException(
                        "Kuantitas pengiriman baris ke-{$doItem->line_no} ({$qtyTrimmed}) melebihi sisa pesanan ({$remainingTrimmed}).",
                        422,
                        'items'
                    );
                }
            }

            // 5. Strict S7 lock order: DO lines sorted by product_id ASC for consistent stock balance locking
            $doItemsSorted = DeliveryOrderItem::query()
                ->where('delivery_order_id', $lockedDo->id)
                ->orderBy('product_id', 'asc')
                ->get();

            foreach ($doItemsSorted as $doItem) {
                $soItem = $soItems->get($doItem->sales_order_item_id);
                if ($soItem !== null) {
                    $soItem->delivered_quantity = Decimal::add($soItem->delivered_quantity, $doItem->quantity, Decimal::SCALE_QUANTITY);
                    $soItem->save();
                }

                // Decrease stock via StockService (throws InsufficientStockException if stock < requested, triggering full rollback)
                $movement = $this->stockService->decrease(
                    productId: $doItem->product_id,
                    warehouseId: $lockedDo->warehouse_id,
                    quantity: $doItem->quantity,
                    type: StockMovementType::DELIVERY,
                    sourceType: StockService::SOURCE_DELIVERY_ORDER,
                    sourceId: $lockedDo->id
                );

                // S4: Snapshot moving average unit cost into DO item
                $doItem->unit_cost = $movement->unit_cost;
                $doItem->save();
            }

            // 6. Recalculate Sales Order status based on delivered quantities
            $newSoStatus = SalesOrderProgress::statusFor($soItems, $lockedSo->status);
            if ($newSoStatus !== $lockedSo->status) {
                $lockedSo->status = $newSoStatus;
                if ($effectiveUserId !== null) {
                    $lockedSo->updated_by = $effectiveUserId;
                }
                $lockedSo->save();
            }

            // 7. Update Delivery Order status to POSTED
            $lockedDo->status = DeliveryOrderStatus::POSTED;
            $lockedDo->posted_by = $effectiveUserId;
            $lockedDo->posted_at = now();
            if ($effectiveUserId !== null) {
                $lockedDo->updated_by = $effectiveUserId;
            }
            $lockedDo->save();

            return $lockedDo->load(['items.product', 'salesOrder', 'customer', 'warehouse']);
        });
    }

    /**
     * Void a Delivery Order (draft or posted).
     * If posted, restores stock via increase at snapshot unit_cost and decrements delivered_quantity.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(DeliveryOrder $deliveryOrder, string $reason, ?int $userId = null): DeliveryOrder
    {
        return DB::transaction(function () use ($deliveryOrder, $reason, $userId): DeliveryOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict S7 lock order: Lock SO first
            $lockedSo = SalesOrder::query()
                ->whereKey($deliveryOrder->sales_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Strict S7 lock order: Lock DO second
            $lockedDo = DeliveryOrder::query()
                ->whereKey($deliveryOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($lockedDo->status, DeliveryOrderStatus::VOID);

            $trimmedReason = trim($reason);
            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            // If previously posted, revert delivered quantities and return stock
            if ($lockedDo->status === DeliveryOrderStatus::POSTED) {
                if ($lockedSo->status === SalesOrderStatus::CLOSED) {
                    throw new BusinessRuleException(
                        'Surat jalan pada pesanan penjualan yang sudah ditutup tidak dapat dibatalkan.',
                        409,
                        'status'
                    );
                }

                // 3. Strict S7 lock order: Lock SO items ordered by id ASC
                $soItems = SalesOrderItem::query()
                    ->where('sales_order_id', $lockedSo->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 4. Strict S7 lock order: DO lines sorted by product_id ASC for stock balances locking
                $doItemsSorted = DeliveryOrderItem::query()
                    ->where('delivery_order_id', $lockedDo->id)
                    ->orderBy('product_id', 'asc')
                    ->get();

                foreach ($doItemsSorted as $doItem) {
                    $soItem = $soItems->get($doItem->sales_order_item_id);
                    if ($soItem !== null) {
                        $soItem->delivered_quantity = Decimal::sub($soItem->delivered_quantity, $doItem->quantity, Decimal::SCALE_QUANTITY);
                        if (Decimal::isNegative($soItem->delivered_quantity)) {
                            $soItem->delivered_quantity = '0.0000';
                        }
                        $soItem->save();
                    }

                    // S5: Restore inventory stock with snapshot unit_cost from DO item
                    $this->stockService->increase(
                        productId: $doItem->product_id,
                        warehouseId: $lockedDo->warehouse_id,
                        quantity: $doItem->quantity,
                        unitCost: $doItem->unit_cost,
                        type: StockMovementType::RECEIPT,
                        sourceType: StockService::SOURCE_DELIVERY_ORDER_VOID,
                        sourceId: $lockedDo->id
                    );
                }

                // 5. Recalculate Sales Order status based on updated delivered quantities
                $newSoStatus = SalesOrderProgress::statusFor($soItems, $lockedSo->status);
                if ($newSoStatus !== $lockedSo->status) {
                    $lockedSo->status = $newSoStatus;
                    if ($effectiveUserId !== null) {
                        $lockedSo->updated_by = $effectiveUserId;
                    }
                    $lockedSo->save();
                }
            }

            // 6. Update Delivery Order status to VOID
            $lockedDo->status = DeliveryOrderStatus::VOID;
            $lockedDo->voided_by = $effectiveUserId;
            $lockedDo->voided_at = now();
            $lockedDo->void_reason = $trimmedReason;
            if ($effectiveUserId !== null) {
                $lockedDo->updated_by = $effectiveUserId;
            }
            $lockedDo->save();

            return $lockedDo->load(['items.product', 'salesOrder', 'customer', 'warehouse']);
        });
    }
}
