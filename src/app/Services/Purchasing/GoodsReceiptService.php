<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Services\Inventory\StockService;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use App\Support\Purchasing\PurchaseOrderProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    use RequiresTransaction;

    public function __construct(
        private readonly StockService $stockService
    ) {}

    /**
     * Create a new draft Goods Receipt for an approved or partially received Purchase Order.
     *
     * @param array{
     *     purchase_order_id: int|string,
     *     supplier_id: int|string,
     *     warehouse_id: int|string,
     *     receipt_date: string,
     *     notes?: string|null,
     *     items: list<array{
     *         purchase_order_item_id: int|string,
     *         product_id: int|string,
     *         quantity: string|int|float
     *     }>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $userId): GoodsReceipt {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict lock order: Lock PO first
            $po = PurchaseOrder::query()
                ->whereKey($data['purchase_order_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($po->status !== PurchaseOrderStatus::APPROVED && $po->status !== PurchaseOrderStatus::PARTIALLY_RECEIVED) {
                throw new BusinessRuleException(
                    'Penerimaan barang hanya dapat dibuat untuk pesanan pembelian yang disetujui atau diterima sebagian.',
                    422,
                    'purchase_order_id'
                );
            }

            if ((int) $data['supplier_id'] !== $po->supplier_id) {
                throw new BusinessRuleException('Pemasok pada penerimaan barang tidak cocok dengan pesanan pembelian.', 422, 'supplier_id');
            }

            if ((int) $data['warehouse_id'] !== $po->warehouse_id) {
                throw new BusinessRuleException('Gudang pada penerimaan barang tidak cocok dengan pesanan pembelian.', 422, 'warehouse_id');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Penerimaan barang harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            // 2. Lock PO items ordered by id ASC
            $poItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', $po->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $poItemId = (int) $itemData['purchase_order_item_id'];
                $poItem = $poItems->get($poItemId);

                if ($poItem === null) {
                    throw new BusinessRuleException("Item pesanan pembelian #{$poItemId} tidak ditemukan pada pesanan pembelian ini.", 422, 'items');
                }

                if ((int) $itemData['product_id'] !== $poItem->product_id) {
                    throw new BusinessRuleException("Produk pada baris ke-{$lineNo} tidak cocok dengan rincian pesanan pembelian.", 422, 'items');
                }

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);
                if (Decimal::cmp($qty, '0') <= 0) {
                    throw new BusinessRuleException("Kuantitas penerimaan baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
                }

                $remaining = $poItem->remainingQuantity();
                if (Decimal::cmp($qty, $remaining) > 0) {
                    throw new BusinessRuleException("Kuantitas penerimaan baris ke-{$lineNo} melebihi sisa pesanan.", 422, 'items');
                }

                $lineNo++;
            }

            $receiptDate = (string) $data['receipt_date'];
            $number = DocumentNumberService::generate(DocumentType::GR, $receiptDate);

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $gr = GoodsReceipt::create([
                'number' => $number,
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'warehouse_id' => $po->warehouse_id,
                'receipt_date' => $receiptDate,
                'status' => GoodsReceiptStatus::DRAFT,
                'notes' => $notes,
                'created_by' => $effectiveUserId,
                'updated_by' => $effectiveUserId,
            ]);

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $poItem = $poItems->get((int) $itemData['purchase_order_item_id']);
                assert($poItem !== null);

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $gr->id,
                    'line_no' => $lineNo++,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $poItem->unit_price,
                ]);
            }

            return $gr->load(['items.product', 'purchaseOrder', 'supplier', 'warehouse']);
        });
    }

    /**
     * Update an existing draft Goods Receipt.
     *
     * @param array{
     *     receipt_date: string,
     *     notes?: string|null,
     *     items: list<array{
     *         purchase_order_item_id: int|string,
     *         product_id: int|string,
     *         quantity: string|int|float
     *     }>
     * } $data
     *
     * @throws DocumentNotEditableException
     * @throws BusinessRuleException
     */
    public function update(GoodsReceipt $receipt, array $data, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $userId): GoodsReceipt {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict lock order: Lock PO first
            $po = PurchaseOrder::query()
                ->whereKey($receipt->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock GR
            $lockedGr = GoodsReceipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedGr->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf penerimaan barang yang dapat diubah.');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Penerimaan barang harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            // 3. Lock PO items ordered by id ASC
            $poItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', $po->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $poItemId = (int) $itemData['purchase_order_item_id'];
                $poItem = $poItems->get($poItemId);

                if ($poItem === null) {
                    throw new BusinessRuleException("Item pesanan pembelian #{$poItemId} tidak ditemukan pada pesanan pembelian ini.", 422, 'items');
                }

                if ((int) $itemData['product_id'] !== $poItem->product_id) {
                    throw new BusinessRuleException("Produk pada baris ke-{$lineNo} tidak cocok dengan rincian pesanan pembelian.", 422, 'items');
                }

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);
                if (Decimal::cmp($qty, '0') <= 0) {
                    throw new BusinessRuleException("Kuantitas penerimaan baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
                }

                $remaining = $poItem->remainingQuantity();
                if (Decimal::cmp($qty, $remaining) > 0) {
                    throw new BusinessRuleException("Kuantitas penerimaan baris ke-{$lineNo} melebihi sisa pesanan.", 422, 'items');
                }

                $lineNo++;
            }

            $lockedGr->receipt_date = (string) $data['receipt_date'];
            $lockedGr->notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;
            if ($effectiveUserId !== null) {
                $lockedGr->updated_by = $effectiveUserId;
            }
            $lockedGr->save();

            // @audit-bypass: goods_receipt_items has no audit columns and items are replaced atomically with draft update
            GoodsReceiptItem::query()->where('goods_receipt_id', $lockedGr->id)->delete();

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $poItem = $poItems->get((int) $itemData['purchase_order_item_id']);
                assert($poItem !== null);

                $qty = Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY);

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $lockedGr->id,
                    'line_no' => $lineNo++,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $poItem->unit_price,
                ]);
            }

            return $lockedGr->load(['items.product', 'purchaseOrder', 'supplier', 'warehouse']);
        });
    }

    /**
     * Post a draft Goods Receipt, increasing physical inventory and updating Purchase Order status.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(GoodsReceipt $receipt, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): GoodsReceipt {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict lock order: Lock PO first
            $lockedPo = PurchaseOrder::query()
                ->whereKey($receipt->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock GR
            $lockedGr = GoodsReceipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($lockedGr->status, GoodsReceiptStatus::POSTED);

            // 3. Lock PO items ordered by id ASC
            $poItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', $lockedPo->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 4. GR items ordered by product_id ASC for consistent stock balance locking order (D10, D14)
            $grItems = GoodsReceiptItem::query()
                ->where('goods_receipt_id', $lockedGr->id)
                ->orderBy('product_id', 'asc')
                ->get();

            if ($grItems->isEmpty()) {
                throw new BusinessRuleException('Penerimaan barang harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            foreach ($grItems as $grItem) {
                $poItem = $poItems->get($grItem->purchase_order_item_id);
                if ($poItem === null) {
                    throw new BusinessRuleException('Rincian pesanan pembelian terkait tidak ditemukan.', 422, 'items');
                }

                if (Decimal::cmp($grItem->quantity, $poItem->remainingQuantity()) > 0) {
                    throw new BusinessRuleException(
                        "Kuantitas penerimaan baris ke-{$grItem->line_no} melebihi sisa pesanan saat posting.",
                        422,
                        'items'
                    );
                }

                // Increase received_quantity on PO item
                $poItem->received_quantity = Decimal::add($poItem->received_quantity, $grItem->quantity, Decimal::SCALE_QUANTITY);
                $poItem->save();

                // 5. Increase inventory stock balance and record movement
                $this->stockService->increase(
                    productId: $grItem->product_id,
                    warehouseId: $lockedGr->warehouse_id,
                    quantity: $grItem->quantity,
                    unitCost: $grItem->unit_cost,
                    type: StockMovementType::RECEIPT,
                    sourceType: StockService::SOURCE_GOODS_RECEIPT,
                    sourceId: $lockedGr->id
                );
            }

            // 6. Update Purchase Order status based on progress
            $newPoStatus = PurchaseOrderProgress::statusFor($poItems, $lockedPo->status);
            if ($newPoStatus !== $lockedPo->status) {
                $lockedPo->status = $newPoStatus;
                if ($effectiveUserId !== null) {
                    $lockedPo->updated_by = $effectiveUserId;
                }
                $lockedPo->save();
            }

            // 7. Update Goods Receipt status
            $lockedGr->status = GoodsReceiptStatus::POSTED;
            $lockedGr->posted_by = $effectiveUserId;
            $lockedGr->posted_at = now();
            if ($effectiveUserId !== null) {
                $lockedGr->updated_by = $effectiveUserId;
            }
            $lockedGr->save();

            return $lockedGr->load(['items.product', 'purchaseOrder', 'supplier', 'warehouse']);
        });
    }

    /**
     * Void a Goods Receipt (draft or posted).
     *
     * @throws BusinessRuleException
     * @throws InsufficientStockException
     * @throws InvalidStateTransitionException
     */
    public function void(GoodsReceipt $receipt, string $reason, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $userId): GoodsReceipt {
            $effectiveUserId = $userId ?? Auth::id();

            // 1. Strict lock order: Lock PO first
            $lockedPo = PurchaseOrder::query()
                ->whereKey($receipt->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock GR
            $lockedGr = GoodsReceipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($lockedGr->status, GoodsReceiptStatus::VOID);

            $trimmedReason = trim($reason);
            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            // If previously posted, revert received quantities and inventory stock
            if ($lockedGr->status === GoodsReceiptStatus::POSTED) {
                // 3. Lock PO items ordered by id ASC
                $poItems = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $lockedPo->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 4. GR items ordered by product_id ASC for consistent stock balance locking order (D10, D14)
                $grItems = GoodsReceiptItem::query()
                    ->where('goods_receipt_id', $lockedGr->id)
                    ->orderBy('product_id', 'asc')
                    ->get();

                foreach ($grItems as $grItem) {
                    $poItem = $poItems->get($grItem->purchase_order_item_id);
                    if ($poItem !== null) {
                        $poItem->received_quantity = Decimal::sub($poItem->received_quantity, $grItem->quantity, Decimal::SCALE_QUANTITY);
                        if (Decimal::isNegative($poItem->received_quantity)) {
                            $poItem->received_quantity = '0.0000';
                        }
                        $poItem->save();
                    }

                    // 5. Decrease stock via StockService (throws InsufficientStockException if balance is insufficient)
                    $this->stockService->decrease(
                        productId: $grItem->product_id,
                        warehouseId: $lockedGr->warehouse_id,
                        quantity: $grItem->quantity,
                        type: StockMovementType::DELIVERY,
                        sourceType: StockService::SOURCE_GOODS_RECEIPT_VOID,
                        sourceId: $lockedGr->id
                    );
                }

                // 6. Rollback Purchase Order status based on remaining quantities
                $newPoStatus = PurchaseOrderProgress::statusFor($poItems, $lockedPo->status);
                if ($newPoStatus !== $lockedPo->status) {
                    $lockedPo->status = $newPoStatus;
                    if ($effectiveUserId !== null) {
                        $lockedPo->updated_by = $effectiveUserId;
                    }
                    $lockedPo->save();
                }
            }

            $lockedGr->status = GoodsReceiptStatus::VOID;
            $lockedGr->voided_by = $effectiveUserId;
            $lockedGr->voided_at = now();
            $lockedGr->void_reason = $trimmedReason;
            if ($effectiveUserId !== null) {
                $lockedGr->updated_by = $effectiveUserId;
            }
            $lockedGr->save();

            return $lockedGr->load(['items.product', 'purchaseOrder', 'supplier', 'warehouse']);
        });
    }
}
