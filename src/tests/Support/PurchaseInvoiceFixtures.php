<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderService;

final class PurchaseInvoiceFixtures
{
    /**
     * @param  list<array{quantity: string, unit_price: string, discount_amount?: string, tax_rate_id?: int}>  $lines
     * @param  array<string, mixed>  $supplier
     * @param  array<string, mixed>  $order
     */
    public static function approvedOrder(array $lines, array $supplier = [], array $order = []): PurchaseOrder
    {
        $service = new PurchaseOrderService;

        $po = $service->create(array_merge([
            'supplier_id' => TestDataFactory::createSupplier($supplier),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'order_date' => '2026-11-01',
            'items' => array_map(fn (array $line): array => array_merge([
                'product_id' => TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]),
            ], $line), $lines),
        ], $order));

        $service->approve($po);

        return $po->fresh(['items']);
    }

    /**
     * @param  array<int, string>  $quantities  keyed by the order line's position
     */
    public static function draftReceipt(PurchaseOrder $po, array $quantities): GoodsReceipt
    {
        $items = [];

        foreach ($quantities as $position => $quantity) {
            $orderItem = $po->items[$position];
            $items[] = [
                'purchase_order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'quantity' => $quantity,
            ];
        }

        return (new GoodsReceiptService(new StockService))->create([
            'purchase_order_id' => $po->id,
            'supplier_id' => $po->supplier_id,
            'warehouse_id' => $po->warehouse_id,
            'receipt_date' => '2026-11-01',
            'items' => $items,
        ]);
    }

    /**
     * @param  array<int, string>  $quantities
     */
    public static function postedReceipt(PurchaseOrder $po, array $quantities): GoodsReceipt
    {
        return (new GoodsReceiptService(new StockService))->post(self::draftReceipt($po, $quantities));
    }

    /**
     * @param  list<GoodsReceipt>  $receipts
     * @param  array<string, mixed>  $overrides
     */
    public static function draftInvoice(PurchaseOrder $po, array $receipts, ?string $quantity = null, array $overrides = []): PurchaseInvoice
    {
        $items = [];

        foreach ($receipts as $receipt) {
            foreach ($receipt->items as $item) {
                $items[] = [
                    'goods_receipt_item_id' => $item->id,
                    'quantity' => $quantity ?? $item->quantity,
                ];
            }
        }

        return (new PurchaseInvoiceService)->create(array_merge([
            'purchase_order_id' => $po->id,
            'supplier_invoice_number' => 'INV-'.bin2hex(random_bytes(6)),
            'invoice_date' => '2026-11-03',
            'items' => $items,
        ], $overrides));
    }
}
