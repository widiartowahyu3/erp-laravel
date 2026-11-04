<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\StockMovementType;
use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\DB;

final class AccountingFixtures
{
    /**
     * @param  list<array{product: int, quantity: string, cost: string}>  $lines
     */
    public static function draftReceipt(array $lines, ?int $warehouseId = null): GoodsReceipt
    {
        $supplierId = TestDataFactory::createSupplier();
        $warehouseId ??= TestDataFactory::createWarehouse();
        $poService = new PurchaseOrderService;

        $po = $poService->create([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-20',
            'items' => array_map(fn (array $l): array => [
                'product_id' => $l['product'], 'quantity' => $l['quantity'], 'unit_price' => $l['cost'],
            ], $lines),
        ]);
        $poService->approve($po);

        return (new GoodsReceiptService(new StockService))->create([
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'receipt_date' => '2026-10-25',
            'items' => $po->items()->get()->map(fn ($item): array => [
                'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $item->quantity,
            ])->all(),
        ]);
    }

    public static function seedStock(int $productId, int $warehouseId, string $quantity, string $cost): void
    {
        DB::transaction(fn () => (new StockService)->increase(
            $productId, $warehouseId, $quantity, $cost,
            StockMovementType::ADJUSTMENT_IN, StockService::SOURCE_STOCK_ADJUSTMENT, 1
        ));
    }

    public static function draftDelivery(int $productId, int $warehouseId, string $quantity, string $price): DeliveryOrder
    {
        $customerId = TestDataFactory::createCustomer();
        $soService = new SalesOrderService;

        $so = $soService->create([
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-20',
            'items' => [['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $price]],
        ]);
        $soService->approve($so);
        $soItem = $so->items()->firstOrFail();

        return (new DeliveryOrderService(new StockService))->create([
            'sales_order_id' => $so->id,
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'delivery_date' => '2026-10-26',
            'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => $quantity]],
        ]);
    }
}
