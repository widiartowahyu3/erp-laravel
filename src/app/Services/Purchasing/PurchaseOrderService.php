<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TaxRate;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use App\Support\Purchasing\PurchaseOrderCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    use RequiresTransaction;

    /**
     * Create a new draft Purchase Order with line items.
     *
     * @param array{
     *     supplier_id: int|string,
     *     warehouse_id: int|string,
     *     order_date: string,
     *     expected_date?: string|null,
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
    public function create(array $data, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $userId): PurchaseOrder {
            $effectiveUserId = $userId ?? Auth::id();

            if (empty($data['items'])) {
                throw new BusinessRuleException('Pesanan pembelian harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $this->validateItemDiscounts($data['items']);

            $orderDate = (string) $data['order_date'];
            $number = DocumentNumberService::generate(DocumentType::PO, $orderDate);

            $calcData = $this->prepareCalculatorInput($data['items']);
            $calc = PurchaseOrderCalculator::calculate($calcData);

            $expectedDate = isset($data['expected_date']) && trim((string) $data['expected_date']) !== ''
                ? (string) $data['expected_date']
                : null;

            $paymentTermId = isset($data['payment_term_id']) && (int) $data['payment_term_id'] > 0
                ? (int) $data['payment_term_id']
                : null;

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $po = PurchaseOrder::create([
                'number' => $number,
                'supplier_id' => (int) $data['supplier_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'payment_term_id' => $paymentTermId,
                'status' => PurchaseOrderStatus::DRAFT,
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

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'line_no' => $lineNo++,
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'received_quantity' => '0.0000',
                    'unit_price' => Decimal::round((string) $itemData['unit_price'], Decimal::SCALE_COST),
                    'discount_amount' => $calcItem['discount_amount'],
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $calcItem['tax_amount'],
                    'line_total' => $calcItem['line_total'],
                ]);
            }

            return $po->load(['items.product', 'supplier', 'warehouse', 'paymentTerm']);
        });
    }

    /**
     * Update a draft Purchase Order and replace its line items atomically.
     *
     * @param array{
     *     supplier_id: int|string,
     *     warehouse_id: int|string,
     *     order_date: string,
     *     expected_date?: string|null,
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
    public function update(PurchaseOrder $order, array $data, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $userId): PurchaseOrder {
            $effectiveUserId = $userId ?? Auth::id();

            $locked = PurchaseOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf pesanan pembelian yang dapat diubah.');
            }

            if (empty($data['items'])) {
                throw new BusinessRuleException('Pesanan pembelian harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $this->validateItemDiscounts($data['items']);

            $calcData = $this->prepareCalculatorInput($data['items']);
            $calc = PurchaseOrderCalculator::calculate($calcData);

            $expectedDate = isset($data['expected_date']) && trim((string) $data['expected_date']) !== ''
                ? (string) $data['expected_date']
                : null;

            $paymentTermId = isset($data['payment_term_id']) && (int) $data['payment_term_id'] > 0
                ? (int) $data['payment_term_id']
                : null;

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $locked->supplier_id = (int) $data['supplier_id'];
            $locked->warehouse_id = (int) $data['warehouse_id'];
            $locked->order_date = (string) $data['order_date'];
            $locked->expected_date = $expectedDate;
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

            // @audit-bypass: purchase_order_items has no audit columns and items are replaced atomically with draft update
            PurchaseOrderItem::query()->where('purchase_order_id', $locked->id)->delete();

            $lineNo = 1;
            foreach ($data['items'] as $index => $itemData) {
                $calcItem = $calc['items'][$index];
                $taxRateId = isset($itemData['tax_rate_id']) && (int) $itemData['tax_rate_id'] > 0
                    ? (int) $itemData['tax_rate_id']
                    : null;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $locked->id,
                    'line_no' => $lineNo++,
                    'product_id' => (int) $itemData['product_id'],
                    'quantity' => Decimal::round((string) $itemData['quantity'], Decimal::SCALE_QUANTITY),
                    'received_quantity' => '0.0000',
                    'unit_price' => Decimal::round((string) $itemData['unit_price'], Decimal::SCALE_COST),
                    'discount_amount' => $calcItem['discount_amount'],
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $calcItem['tax_amount'],
                    'line_total' => $calcItem['line_total'],
                ]);
            }

            return $locked->load(['items.product', 'supplier', 'warehouse', 'paymentTerm']);
        });
    }

    /**
     * Approve a draft Purchase Order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function approve(PurchaseOrder $order, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $effectiveUserId = $userId ?? Auth::id();

            $locked = PurchaseOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, PurchaseOrderStatus::APPROVED);

            if ($locked->items()->count() === 0) {
                throw new BusinessRuleException('Pesanan pembelian harus memiliki minimal 1 baris barang.', 422, 'items');
            }

            $locked->status = PurchaseOrderStatus::APPROVED;
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
     * Void a draft or approved Purchase Order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(PurchaseOrder $order, string $reason, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $userId): PurchaseOrder {
            $effectiveUserId = $userId ?? Auth::id();

            $locked = PurchaseOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, PurchaseOrderStatus::VOID);

            $trimmedReason = trim($reason);
            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            $hasActiveReceipts = GoodsReceipt::query()
                ->where('purchase_order_id', $locked->id)
                ->where('status', '!=', GoodsReceiptStatus::VOID)
                ->exists();

            if ($hasActiveReceipts) {
                throw new BusinessRuleException(
                    'Pesanan pembelian yang memiliki penerimaan barang aktif tidak dapat dibatalkan langsung.',
                    409,
                    'status'
                );
            }

            $locked->status = PurchaseOrderStatus::VOID;
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
     * Close a received or partially received Purchase Order.
     *
     * @throws InvalidStateTransitionException
     */
    public function close(PurchaseOrder $order, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $effectiveUserId = $userId ?? Auth::id();

            $locked = PurchaseOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, PurchaseOrderStatus::CLOSED);

            $hasDraftReceipts = GoodsReceipt::query()
                ->where('purchase_order_id', $locked->id)
                ->where('status', GoodsReceiptStatus::DRAFT)
                ->exists();

            if ($hasDraftReceipts) {
                throw new BusinessRuleException(
                    'Masih ada penerimaan barang berstatus draf. Posting atau batalkan terlebih dahulu.',
                    409,
                    'status'
                );
            }

            $locked->status = PurchaseOrderStatus::CLOSED;
            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }
            $locked->save();

            return $locked;
        });
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
