<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\StockMovementType;
use App\Models\DeliveryOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\TaxRate;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;

final class SalesInvoiceFixtures
{
    public static function taxRate(string $rate): int
    {
        return (int) TaxRate::create([
            'code' => 'T_'.bin2hex(random_bytes(4)),
            'name' => "PPN {$rate}",
            'rate' => $rate,
            'is_active' => true,
        ])->id;
    }

    /**
     * An approved sales order whose products have stock to deliver.
     *
     * @param  list<array{quantity: string, unit_price: string, discount_amount?: string, tax_rate_id?: int}>  $lines
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $order
     */
    public static function approvedOrder(array $lines, array $customer = [], array $order = []): SalesOrder
    {
        $customerId = TestDataFactory::createCustomer(array_merge(['credit_limit' => 0], $customer));
        $warehouseId = TestDataFactory::createWarehouse();
        $stock = new StockService;
        $orderService = new SalesOrderService;

        $items = [];
        foreach ($lines as $line) {
            $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);
            $stock->increase($productId, $warehouseId, '100000.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
            $items[] = array_merge(['product_id' => $productId], $line);
        }

        $so = $orderService->create(array_merge([
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-11-01',
            'items' => $items,
        ], $order));

        $orderService->approve($so);

        return $so->fresh(['items']);
    }

    /**
     * A delivery order for the given quantities, keyed by the order line's position.
     *
     * @param  array<int, string>  $quantities
     */
    public static function draftDelivery(SalesOrder $so, array $quantities): DeliveryOrder
    {
        $items = [];
        foreach ($quantities as $position => $quantity) {
            $soItem = $so->items[$position];
            $items[] = [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $soItem->product_id,
                'quantity' => $quantity,
            ];
        }

        return (new DeliveryOrderService(new StockService))->create([
            'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id,
            'warehouse_id' => $so->warehouse_id,
            'delivery_date' => '2026-11-01',
            'items' => $items,
        ]);
    }

    /**
     * @param  array<int, string>  $quantities
     */
    public static function postedDelivery(SalesOrder $so, array $quantities): DeliveryOrder
    {
        return (new DeliveryOrderService(new StockService))->post(self::draftDelivery($so, $quantities));
    }

    /**
     * Draft invoice for every line of the given deliveries, each at the given quantity or in full.
     *
     * @param  list<DeliveryOrder>  $deliveries
     * @param  array<string, mixed>  $overrides
     */
    public static function draftInvoice(SalesOrder $so, array $deliveries, ?string $quantity = null, array $overrides = []): SalesInvoice
    {
        $items = [];
        foreach ($deliveries as $delivery) {
            foreach ($delivery->items as $item) {
                $items[] = [
                    'delivery_order_item_id' => $item->id,
                    'quantity' => $quantity ?? $item->quantity,
                ];
            }
        }

        return (new SalesInvoiceService)->create(array_merge([
            'sales_order_id' => $so->id,
            'invoice_date' => '2026-11-03',
            'items' => $items,
        ], $overrides));
    }
}
