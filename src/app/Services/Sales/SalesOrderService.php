<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DocumentType;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use App\Support\Sales\SalesOrderCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    use RequiresTransaction;

    public function __construct(
        private readonly CreditExposureService $credit = new CreditExposureService
    ) {}

    /**
     * Create a new draft Sales Order with line items.
     *
     * @param array{
     *     customer_id: int|string,
     *     warehouse_id: int|string,
     *     order_date: string,
     *     payment_term_id?: int|string|null,
     *     notes?: string|null,
     *     items: list<array{
     *         product_id: int|string,
     *         quantity: string|int|float,
     *         unit_price: string|int|float,
     *         discount_amount?: string|int|float|null,
     *         tax_rate_id?: int|string|null
     *     }>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($data, $userId): SalesOrder {
            $effectiveUserId = $userId ?? Auth::id();

            if (empty($data['items'])) {
                throw new BusinessRuleException('Pesanan penjualan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $this->validateCustomer((int) $data['customer_id']);
            $this->validateWarehouse((int) $data['warehouse_id']);
            $this->validateProducts($data['items']);
            $this->validateItemDiscounts($data['items']);

            $orderDate = (string) $data['order_date'];
            $number = DocumentNumberService::generate(DocumentType::SO, $orderDate);

            $calcData = $this->prepareCalculatorInput($data['items']);
            $calc = SalesOrderCalculator::calculate($calcData);

            $paymentTermId = isset($data['payment_term_id']) && (int) $data['payment_term_id'] > 0
                ? (int) $data['payment_term_id']
                : null;

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $so = SalesOrder::create([
                'number' => $number,
                'customer_id' => (int) $data['customer_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'order_date' => $orderDate,
                'payment_term_id' => $paymentTermId,
                'status' => SalesOrderStatus::DRAFT,
                'subtotal' => $calc['header']['subtotal'],
                'discount_amount' => $calc['header']['discount_amount'],
                'tax_amount' => $calc['header']['tax_amount'],
                'total' => $calc['header']['total'],
                'notes' => $notes,
                'created_by' => $effectiveUserId,
                'updated_by' => $effectiveUserId,
            ]);

            $lineNo = 1;
            foreach ($data['items'] as $index => $itemData) {
                $calcItem = $calc['items'][$index];
                $taxRateId = isset($itemData['tax_rate_id']) && (int) $itemData['tax_rate_id'] > 0
                    ? (int) $itemData['tax_rate_id']
                    : null;

                SalesOrderItem::create([
                    'sales_order_id' => $so->id,
                    'line_no' => $lineNo++,
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'delivered_quantity' => '0.0000',
                    'unit_price' => Decimal::round((string) $itemData['unit_price'], Decimal::SCALE_COST),
                    'discount_amount' => $calcItem['discount_amount'],
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $calcItem['tax_amount'],
                    'line_total' => $calcItem['line_total'],
                ]);
            }

            return $so->load(['items.product', 'customer', 'warehouse', 'paymentTerm']);
        });
    }

    /**
     * Update a draft Sales Order and replace its line items atomically.
     *
     * @param array{
     *     customer_id: int|string,
     *     warehouse_id: int|string,
     *     order_date: string,
     *     payment_term_id?: int|string|null,
     *     notes?: string|null,
     *     items: list<array{
     *         product_id: int|string,
     *         quantity: string|int|float,
     *         unit_price: string|int|float,
     *         discount_amount?: string|int|float|null,
     *         tax_rate_id?: int|string|null
     *     }>
     * } $data
     *
     * @throws DocumentNotEditableException
     * @throws BusinessRuleException
     */
    public function update(SalesOrder $order, array $data, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $data, $userId): SalesOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // S7 locking order: Lock SO
            $locked = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf pesanan penjualan yang dapat diubah.');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Pesanan penjualan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $this->validateCustomer((int) $data['customer_id']);
            $this->validateWarehouse((int) $data['warehouse_id']);
            $this->validateProducts($data['items']);
            $this->validateItemDiscounts($data['items']);

            $calcData = $this->prepareCalculatorInput($data['items']);
            $calc = SalesOrderCalculator::calculate($calcData);

            $paymentTermId = isset($data['payment_term_id']) && (int) $data['payment_term_id'] > 0
                ? (int) $data['payment_term_id']
                : null;

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $locked->customer_id = (int) $data['customer_id'];
            $locked->warehouse_id = (int) $data['warehouse_id'];
            $locked->order_date = (string) $data['order_date'];
            $locked->payment_term_id = $paymentTermId;
            $locked->subtotal = $calc['header']['subtotal'];
            $locked->discount_amount = $calc['header']['discount_amount'];
            $locked->tax_amount = $calc['header']['tax_amount'];
            $locked->total = $calc['header']['total'];
            $locked->notes = $notes;
            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }
            $locked->save();

            // @audit-bypass: sales_order_items has no audit columns and items are replaced atomically with draft update
            SalesOrderItem::query()->where('sales_order_id', $locked->id)->delete();

            $lineNo = 1;
            foreach ($data['items'] as $index => $itemData) {
                $calcItem = $calc['items'][$index];
                $taxRateId = isset($itemData['tax_rate_id']) && (int) $itemData['tax_rate_id'] > 0
                    ? (int) $itemData['tax_rate_id']
                    : null;

                SalesOrderItem::create([
                    'sales_order_id' => $locked->id,
                    'line_no' => $lineNo++,
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'delivered_quantity' => '0.0000',
                    'unit_price' => Decimal::round((string) $itemData['unit_price'], Decimal::SCALE_COST),
                    'discount_amount' => $calcItem['discount_amount'],
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $calcItem['tax_amount'],
                    'line_total' => $calcItem['line_total'],
                ]);
            }

            return $locked->load(['items.product', 'customer', 'warehouse', 'paymentTerm']);
        });
    }

    /**
     * Approve a draft Sales Order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function approve(SalesOrder $order, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // Global lock order: customer before sales order, so credit checks run one at a time per customer
            $customerId = SalesOrder::query()->whereKey($order->id)->value('customer_id');
            $customer = Customer::withTrashed()
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // S7 locking order: Lock SO
            $locked = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, SalesOrderStatus::APPROVED);

            if ($locked->customer_id !== $customer->id) {
                throw new BusinessRuleException('Pelanggan pada pesanan baru saja berubah. Silakan ulangi persetujuan.', 409, 'customer_id');
            }

            if ($locked->items()->count() === 0) {
                throw new BusinessRuleException('Pesanan penjualan harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $this->credit->assertWithinLimit($customer, $locked->total);

            $locked->status = SalesOrderStatus::APPROVED;
            $locked->approved_by = $effectiveUserId;
            $locked->approved_at = now();
            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Void a draft or approved Sales Order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(SalesOrder $order, string $reason, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason, $userId): SalesOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // S7 locking order: Lock SO
            $locked = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, SalesOrderStatus::VOID);

            $trimmedReason = trim($reason);
            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            $hasActiveDeliveries = DeliveryOrder::query()
                ->where('sales_order_id', $locked->id)
                ->where('status', '!=', DeliveryOrderStatus::VOID)
                ->exists();

            if ($hasActiveDeliveries) {
                throw new BusinessRuleException(
                    'Pesanan penjualan yang memiliki surat jalan aktif tidak dapat dibatalkan langsung.',
                    409,
                    'status'
                );
            }

            $locked->status = SalesOrderStatus::VOID;
            $locked->voided_by = $effectiveUserId;
            $locked->voided_at = now();
            $locked->void_reason = $trimmedReason;
            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Close a delivered or partially delivered Sales Order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function close(SalesOrder $order, ?int $userId = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $effectiveUserId = $userId ?? Auth::id();

            // S7 locking order: Lock SO
            $locked = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, SalesOrderStatus::CLOSED);

            $hasDraftDeliveries = DeliveryOrder::query()
                ->where('sales_order_id', $locked->id)
                ->where('status', DeliveryOrderStatus::DRAFT)
                ->exists();

            if ($hasDraftDeliveries) {
                throw new BusinessRuleException(
                    'Masih ada surat jalan berstatus draf. Posting atau batalkan terlebih dahulu.',
                    409,
                    'status'
                );
            }

            $locked->status = SalesOrderStatus::CLOSED;
            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    private function validateCustomer(int $customerId): void
    {
        $active = Customer::query()
            ->whereKey($customerId)
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            throw new BusinessRuleException('Pelanggan tidak aktif atau tidak ditemukan.', 422, 'customer_id');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function validateWarehouse(int $warehouseId): void
    {
        $active = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            throw new BusinessRuleException('Gudang tidak aktif atau tidak ditemukan.', 422, 'warehouse_id');
        }
    }

    /**
     * Validate that all products exist, are active, and have track_stock = true (S3).
     *
     * @param  iterable<int, array<string, mixed>>  $items
     *
     * @throws BusinessRuleException
     */
    private function validateProducts(iterable $items): void
    {
        foreach ($items as $index => $itemData) {
            $productId = (int) ($itemData['product_id'] ?? 0);
            $valid = Product::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->where('track_stock', true)
                ->exists();

            if (! $valid) {
                throw new BusinessRuleException(
                    'Produk harus aktif dan melacak persediaan (track_stock).',
                    422,
                    "items.{$index}.product_id"
                );
            }
        }
    }

    /**
     * Prepare calculator input from raw request item data by resolving tax rates.
     *
     * @param list<array{
     *     product_id: int|string,
     *     quantity: string|int|float,
     *     unit_price: string|int|float,
     *     discount_amount?: string|int|float|null,
     *     tax_rate_id?: int|string|null
     * }> $items
     * @return list<array{
     *     quantity: string,
     *     unit_price: string,
     *     discount_amount: string,
     *     tax_rate_percent: string
     * }>
     */
    private function prepareCalculatorInput(array $items): array
    {
        $taxRateIds = [];
        foreach ($items as $item) {
            if (isset($item['tax_rate_id']) && (int) $item['tax_rate_id'] > 0) {
                $taxRateIds[] = (int) $item['tax_rate_id'];
            }
        }

        $taxRates = ! empty($taxRateIds)
            ? TaxRate::query()->whereIn('id', array_unique($taxRateIds))->pluck('rate', 'id')->all()
            : [];

        $prepared = [];
        foreach ($items as $item) {
            $taxRateId = isset($item['tax_rate_id']) ? (int) $item['tax_rate_id'] : null;
            $taxRatePercent = $taxRateId !== null && isset($taxRates[$taxRateId])
                ? (string) $taxRates[$taxRateId]
                : '0.00';

            $prepared[] = [
                'quantity' => (string) $item['quantity'],
                'unit_price' => (string) $item['unit_price'],
                'discount_amount' => isset($item['discount_amount']) ? (string) $item['discount_amount'] : '0.00',
                'tax_rate_percent' => $taxRatePercent,
            ];
        }

        return $prepared;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $items
     *
     * @throws BusinessRuleException
     */
    private function validateItemDiscounts(iterable $items): void
    {
        foreach ($items as $index => $itemData) {
            $qty = (string) $itemData['quantity'];
            $price = (string) $itemData['unit_price'];
            $disc = isset($itemData['discount_amount']) && trim((string) $itemData['discount_amount']) !== ''
                ? (string) $itemData['discount_amount']
                : '0.00';

            $gross = Decimal::mul($qty, $price, Decimal::SCALE_MONEY);
            $discRounded = Decimal::round($disc, Decimal::SCALE_MONEY);

            if (Decimal::cmp($discRounded, $gross) > 0) {
                throw new BusinessRuleException(
                    'Diskon tidak boleh melebihi nilai baris.',
                    422,
                    "items.{$index}.discount_amount"
                );
            }
        }
    }
}
