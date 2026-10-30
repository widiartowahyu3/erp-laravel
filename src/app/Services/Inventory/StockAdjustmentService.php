<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class StockAdjustmentService
{
    public function __construct(
        private readonly StockService $stockService
    ) {}

    /**
     * Create a new draft stock adjustment with items.
     *
     * @param  array{warehouse_id: int, adjustment_date: string, reason: string, notes?: string|null, items: list<array{product_id: int, counted_quantity: string, unit_cost?: string|null}>}  $data
     */
    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data): StockAdjustment {
            $warehouseId = (int) $data['warehouse_id'];
            $adjustmentDate = $data['adjustment_date'];

            $number = DocumentNumberService::generate(DocumentType::SA, $adjustmentDate);

            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $adjustment = StockAdjustment::create([
                'number' => $number,
                'warehouse_id' => $warehouseId,
                'adjustment_date' => $adjustmentDate,
                'reason' => trim((string) $data['reason']),
                'status' => StockAdjustmentStatus::DRAFT,
                'notes' => $notes,
            ]);

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $countedQty = Decimal::round((string) $itemData['counted_quantity'], Decimal::SCALE_QUANTITY);
                $unitCost = isset($itemData['unit_cost']) && trim((string) $itemData['unit_cost']) !== ''
                    ? Decimal::round((string) $itemData['unit_cost'], Decimal::SCALE_COST)
                    : '0.0000';

                $currentBalance = StockBalance::query()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                $systemQty = $currentBalance !== null ? $currentBalance->quantity : '0.0000';
                $diffQty = Decimal::sub($countedQty, $systemQty, Decimal::SCALE_QUANTITY);

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'line_no' => $lineNo++,
                    'product_id' => $productId,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference_quantity' => $diffQty,
                    'unit_cost' => $unitCost,
                ]);
            }

            return $adjustment->load(['warehouse', 'items.product']);
        });
    }

    /**
     * Update an existing draft stock adjustment and replace its items.
     *
     * @param  array{warehouse_id: int, adjustment_date: string, reason: string, notes?: string|null, items: list<array{product_id: int, counted_quantity: string, unit_cost?: string|null}>}  $data
     *
     * @throws DocumentNotEditableException
     */
    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data): StockAdjustment {
            $locked = StockAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== StockAdjustmentStatus::DRAFT) {
                throw new DocumentNotEditableException('Hanya draf penyesuaian stok yang dapat diubah.');
            }

            $warehouseId = (int) $data['warehouse_id'];
            $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
                ? trim((string) $data['notes'])
                : null;

            $locked->warehouse_id = $warehouseId;
            $locked->adjustment_date = $data['adjustment_date'];
            $locked->reason = trim((string) $data['reason']);
            $locked->notes = $notes;
            $locked->save();

            // @audit-bypass: stock_adjustment_items has no audit columns and items are replaced atomically with draft update
            StockAdjustmentItem::query()->where('stock_adjustment_id', $locked->id)->delete();

            $lineNo = 1;
            foreach ($data['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $countedQty = Decimal::round((string) $itemData['counted_quantity'], Decimal::SCALE_QUANTITY);
                $unitCost = isset($itemData['unit_cost']) && trim((string) $itemData['unit_cost']) !== ''
                    ? Decimal::round((string) $itemData['unit_cost'], Decimal::SCALE_COST)
                    : '0.0000';

                $currentBalance = StockBalance::query()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                $systemQty = $currentBalance !== null ? $currentBalance->quantity : '0.0000';
                $diffQty = Decimal::sub($countedQty, $systemQty, Decimal::SCALE_QUANTITY);

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $locked->id,
                    'line_no' => $lineNo++,
                    'product_id' => $productId,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference_quantity' => $diffQty,
                    'unit_cost' => $unitCost,
                ]);
            }

            return $locked->load(['warehouse', 'items.product']);
        });
    }

    /**
     * Post a draft stock adjustment, updating inventory and recording movements.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment): StockAdjustment {
            $locked = StockAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, StockAdjustmentStatus::POSTED);

            // D10, D14: Order items by product_id ASC for consistent locking order
            $items = StockAdjustmentItem::query()
                ->where('stock_adjustment_id', $locked->id)
                ->orderBy('product_id', 'asc')
                ->get();

            foreach ($items as $item) {
                $balance = $this->stockService->lockBalance($item->product_id, $locked->warehouse_id);

                $systemQty = $balance->quantity;
                $diffQty = Decimal::sub($item->counted_quantity, $systemQty, Decimal::SCALE_QUANTITY);
                $cmp = Decimal::cmp($diffQty, '0');

                if ($cmp > 0) {
                    if (Decimal::cmp($item->unit_cost, '0') <= 0) {
                        throw new BusinessRuleException(
                            'Item dengan selisih lebih wajib memiliki biaya satuan lebih dari 0.',
                            422,
                            'items'
                        );
                    }

                    $this->stockService->increase(
                        productId: $item->product_id,
                        warehouseId: $locked->warehouse_id,
                        quantity: $diffQty,
                        unitCost: $item->unit_cost,
                        type: StockMovementType::ADJUSTMENT_IN,
                        sourceType: StockService::SOURCE_STOCK_ADJUSTMENT,
                        sourceId: $locked->id
                    );
                } elseif ($cmp < 0) {
                    $absQty = Decimal::sub('0', $diffQty, Decimal::SCALE_QUANTITY);

                    $movement = $this->stockService->decrease(
                        productId: $item->product_id,
                        warehouseId: $locked->warehouse_id,
                        quantity: $absQty,
                        type: StockMovementType::ADJUSTMENT_OUT,
                        sourceType: StockService::SOURCE_STOCK_ADJUSTMENT,
                        sourceId: $locked->id
                    );

                    $item->unit_cost = $movement->unit_cost;
                }

                $item->system_quantity = $systemQty;
                $item->difference_quantity = $diffQty;
                $item->save();
            }

            $locked->status = StockAdjustmentStatus::POSTED;
            $locked->posted_by = Auth::id() ? (int) Auth::id() : null;
            $locked->posted_at = now();
            $locked->save();

            return $locked->load(['warehouse', 'items.product']);
        });
    }

    /**
     * Void a draft or posted stock adjustment, reversing stock if previously posted.
     *
     * @throws BusinessRuleException
     * @throws InsufficientStockException
     * @throws InvalidStateTransitionException
     */
    public function void(StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason): StockAdjustment {
            $cleanReason = trim($reason);
            if ($cleanReason === '' || mb_strlen($cleanReason) > 255) {
                throw new BusinessRuleException(
                    'Alasan pembatalan wajib diisi (maksimal 255 karakter).',
                    422,
                    'reason'
                );
            }

            $locked = StockAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentStateMachine::assertTransition($locked->status, StockAdjustmentStatus::VOID);

            if ($locked->status === StockAdjustmentStatus::POSTED) {
                // D11: Reverse movements ordered by product_id ASC
                $items = StockAdjustmentItem::query()
                    ->where('stock_adjustment_id', $locked->id)
                    ->orderBy('product_id', 'asc')
                    ->get();

                foreach ($items as $item) {
                    $diffQty = $item->difference_quantity;
                    $cmp = Decimal::cmp($diffQty, '0');

                    if ($cmp > 0) {
                        // Originally increased by diffQty; reverse with decrease
                        $this->stockService->decrease(
                            productId: $item->product_id,
                            warehouseId: $locked->warehouse_id,
                            quantity: $diffQty,
                            type: StockMovementType::ADJUSTMENT_OUT,
                            sourceType: StockService::SOURCE_STOCK_ADJUSTMENT_VOID,
                            sourceId: $locked->id
                        );
                    } elseif ($cmp < 0) {
                        // Originally decreased by |diffQty|; reverse with increase
                        $absQty = Decimal::sub('0', $diffQty, Decimal::SCALE_QUANTITY);

                        $this->stockService->increase(
                            productId: $item->product_id,
                            warehouseId: $locked->warehouse_id,
                            quantity: $absQty,
                            unitCost: $item->unit_cost,
                            type: StockMovementType::ADJUSTMENT_IN,
                            sourceType: StockService::SOURCE_STOCK_ADJUSTMENT_VOID,
                            sourceId: $locked->id
                        );
                    }
                }
            }

            $locked->status = StockAdjustmentStatus::VOID;
            $locked->voided_by = Auth::id() ? (int) Auth::id() : null;
            $locked->voided_at = now();
            $locked->void_reason = $cleanReason;
            $locked->save();

            return $locked->load(['warehouse', 'items.product']);
        });
    }
}
