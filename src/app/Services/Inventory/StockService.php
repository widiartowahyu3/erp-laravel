<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use App\Support\Inventory\MovingAverageCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StockService
{
    use RequiresTransaction;

    public const string SOURCE_STOCK_ADJUSTMENT = 'stock_adjustment';

    public const string SOURCE_STOCK_ADJUSTMENT_VOID = 'stock_adjustment_void';

    public const string SOURCE_GOODS_RECEIPT = 'goods_receipt';

    public const string SOURCE_GOODS_RECEIPT_VOID = 'goods_receipt_void';

    public const string SOURCE_DELIVERY_ORDER = 'delivery_order';

    public const string SOURCE_DELIVERY_ORDER_VOID = 'delivery_order_void';

    /**
     * Lock the stock balance row for update, creating a zero-balance row if it does not exist.
     */
    public function lockBalance(int $productId, int $warehouseId): StockBalance
    {
        self::ensureInTransaction();

        DB::table('stock_balances')->insertOrIgnore([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => '0.0000',
            'avg_cost' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Increase inventory stock and record receipt/adjustment_in movement.
     *
     * @throws BusinessRuleException
     * @throws InvalidArgumentException
     * @throws \LogicException
     */
    public function increase(
        int $productId,
        int $warehouseId,
        string $quantity,
        string $unitCost,
        StockMovementType $type,
        string $sourceType,
        int $sourceId,
        ?CarbonInterface $movementDate = null
    ): StockMovement {
        self::ensureInTransaction();

        if (! in_array($type, [StockMovementType::RECEIPT, StockMovementType::ADJUSTMENT_IN], true)) {
            throw new InvalidArgumentException("Invalid movement type '{$type->value}' for stock increase.");
        }

        $this->validateQuantity($quantity);
        $this->validateUnitCost($unitCost);

        $product = $this->findAndValidateProduct($productId);
        $warehouse = $this->findAndValidateWarehouse($warehouseId);

        $balance = $this->lockBalance($product->id, $warehouse->id);

        $newAvgCost = MovingAverageCost::afterReceipt(
            $balance->quantity,
            $balance->avg_cost,
            $quantity,
            $unitCost
        );
        $newQuantity = Decimal::add($balance->quantity, $quantity, Decimal::SCALE_QUANTITY);

        $balance->quantity = $newQuantity;
        $balance->avg_cost = $newAvgCost;
        $balance->save();

        return StockMovement::create([
            'movement_date' => $movementDate ?? now(),
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => $type,
            'quantity' => Decimal::round($quantity, Decimal::SCALE_QUANTITY),
            'unit_cost' => Decimal::round($unitCost, Decimal::SCALE_COST),
            'balance_after' => $balance->quantity,
            'avg_cost_after' => $balance->avg_cost,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => Auth::id() ? (int) Auth::id() : null,
        ]);
    }

    /**
     * Decrease inventory stock and record delivery/adjustment_out movement.
     *
     * @throws BusinessRuleException
     * @throws InsufficientStockException
     * @throws InvalidArgumentException
     * @throws \LogicException
     */
    public function decrease(
        int $productId,
        int $warehouseId,
        string $quantity,
        StockMovementType $type,
        string $sourceType,
        int $sourceId,
        ?CarbonInterface $movementDate = null
    ): StockMovement {
        self::ensureInTransaction();

        if (! in_array($type, [StockMovementType::DELIVERY, StockMovementType::ADJUSTMENT_OUT], true)) {
            throw new InvalidArgumentException("Invalid movement type '{$type->value}' for stock decrease.");
        }

        $this->validateQuantity($quantity);

        $product = $this->findAndValidateProduct($productId);
        $warehouse = $this->findAndValidateWarehouse($warehouseId);

        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            throw new InsufficientStockException(
                "Stok produk '{$product->sku}' di gudang '{$warehouse->code}' tidak mencukupi (tersedia: 0.0000, diminta: {$quantity}).",
                422,
                'quantity'
            );
        }

        if (Decimal::cmp($balance->quantity, $quantity, Decimal::SCALE_QUANTITY) < 0) {
            throw new InsufficientStockException(
                "Stok produk '{$product->sku}' di gudang '{$warehouse->code}' tidak mencukupi (tersedia: {$balance->quantity}, diminta: {$quantity}).",
                422,
                'quantity'
            );
        }

        $unitCost = $balance->avg_cost;
        $newQuantity = Decimal::sub($balance->quantity, $quantity, Decimal::SCALE_QUANTITY);

        $balance->quantity = $newQuantity;
        $balance->save();

        $signedQuantity = '-'.Decimal::round($quantity, Decimal::SCALE_QUANTITY);

        return StockMovement::create([
            'movement_date' => $movementDate ?? now(),
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => $type,
            'quantity' => $signedQuantity,
            'unit_cost' => $unitCost,
            'balance_after' => $balance->quantity,
            'avg_cost_after' => $balance->avg_cost,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => Auth::id() ? (int) Auth::id() : null,
        ]);
    }

    /**
     * Validate positive quantity string with up to 4 decimal places.
     *
     * @throws BusinessRuleException
     */
    private function validateQuantity(string $quantity): void
    {
        if (preg_match('/^[0-9]+(\.[0-9]{1,4})?$/', $quantity) !== 1 || Decimal::cmp($quantity, '0') <= 0) {
            throw new BusinessRuleException(
                "Kuantitas harus berupa angka positif dengan maksimal 4 desimal, got '{$quantity}'.",
                422,
                'quantity'
            );
        }
    }

    /**
     * Validate non-negative unit cost string with up to 4 decimal places.
     *
     * @throws BusinessRuleException
     */
    private function validateUnitCost(string $unitCost): void
    {
        if (preg_match('/^[0-9]+(\.[0-9]{1,4})?$/', $unitCost) !== 1 || Decimal::cmp($unitCost, '0') < 0) {
            throw new BusinessRuleException(
                "Biaya satuan harus berupa angka non-negatif dengan maksimal 4 desimal, got '{$unitCost}'.",
                422,
                'unit_cost'
            );
        }
    }

    /**
     * Find product and validate it is not soft-deleted and tracks stock.
     *
     * @throws BusinessRuleException
     */
    private function findAndValidateProduct(int $productId): Product
    {
        $product = Product::find($productId);

        if ($product === null) {
            throw new BusinessRuleException("Produk dengan ID {$productId} tidak ditemukan.", 422, 'product_id');
        }

        if (! $product->track_stock) {
            throw new BusinessRuleException(
                "Produk '{$product->sku}' tidak dikonfigurasi untuk pelacakan stok.",
                422,
                'product_id'
            );
        }

        return $product;
    }

    /**
     * Find warehouse and validate it is not soft-deleted.
     *
     * @throws BusinessRuleException
     */
    private function findAndValidateWarehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::find($warehouseId);

        if ($warehouse === null) {
            throw new BusinessRuleException("Gudang dengan ID {$warehouseId} tidak ditemukan.", 422, 'warehouse_id');
        }

        return $warehouse;
    }
}
