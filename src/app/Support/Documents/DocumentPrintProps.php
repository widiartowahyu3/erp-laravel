<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockAdjustment;
use App\Services\Accounting\JournalSourceResolver;

/**
 * What each printed document may show. These are explicit lists because a warehouse document
 * goes to drivers and customers, so a price or cost must never reach the page at all.
 */
final class DocumentPrintProps
{
    /**
     * @return array<string, mixed>
     */
    public static function purchaseOrder(PurchaseOrder $order): array
    {
        $order->load(['items.product.baseUnit:id,code,name', 'supplier:id,code,name,address', 'warehouse:id,code,name']);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'orderDate' => $order->order_date->toDateString(),
            'expectedDate' => $order->expected_date?->toDateString(),
            'notes' => $order->notes,
            'supplier' => ['name' => $order->supplier->name, 'address' => $order->supplier->address],
            'warehouse' => ['name' => $order->warehouse->name],
            'subtotal' => $order->subtotal,
            'discountAmount' => $order->discount_amount,
            'taxAmount' => $order->tax_amount,
            'total' => $order->total,
            'items' => $order->items->map(fn ($item): array => self::pricedLine($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function salesOrder(SalesOrder $order): array
    {
        $order->load(['items.product.baseUnit:id,code,name', 'customer:id,code,name,address', 'warehouse:id,code,name']);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'orderDate' => $order->order_date->toDateString(),
            'notes' => $order->notes,
            'customer' => ['name' => $order->customer->name, 'address' => $order->customer->address],
            'warehouse' => ['name' => $order->warehouse->name],
            'subtotal' => $order->subtotal,
            'discountAmount' => $order->discount_amount,
            'taxAmount' => $order->tax_amount,
            'total' => $order->total,
            'items' => $order->items->map(fn ($item): array => self::pricedLine($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function goodsReceipt(GoodsReceipt $receipt): array
    {
        $receipt->load(['items.product.baseUnit:id,code,name', 'purchaseOrder:id,number', 'supplier:id,code,name,address', 'warehouse:id,code,name']);

        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'status' => $receipt->status->value,
            'receiptDate' => $receipt->receipt_date->toDateString(),
            'notes' => $receipt->notes,
            'purchaseOrder' => ['id' => $receipt->purchaseOrder->id, 'number' => $receipt->purchaseOrder->number],
            'supplier' => ['name' => $receipt->supplier->name, 'address' => $receipt->supplier->address],
            'warehouse' => ['name' => $receipt->warehouse->name],
            'items' => $receipt->items->map(fn ($item): array => self::quantityLine($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deliveryOrder(DeliveryOrder $delivery): array
    {
        $delivery->load(['items.product.baseUnit:id,code,name', 'salesOrder:id,number', 'customer:id,code,name,address', 'warehouse:id,code,name']);

        return [
            'id' => $delivery->id,
            'number' => $delivery->number,
            'status' => $delivery->status->value,
            'deliveryDate' => $delivery->delivery_date->toDateString(),
            'shippingAddress' => $delivery->shipping_address ?? $delivery->customer->address,
            'notes' => $delivery->notes,
            'salesOrder' => ['id' => $delivery->salesOrder->id, 'number' => $delivery->salesOrder->number],
            'customer' => ['name' => $delivery->customer->name, 'address' => $delivery->customer->address],
            'warehouse' => ['name' => $delivery->warehouse->name],
            'items' => $delivery->items->map(fn ($item): array => self::quantityLine($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stockAdjustment(StockAdjustment $adjustment): array
    {
        $adjustment->load(['items.product.baseUnit:id,code,name', 'warehouse:id,code,name']);

        return [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'status' => $adjustment->status->value,
            'adjustmentDate' => $adjustment->adjustment_date->toDateString(),
            'reason' => $adjustment->reason,
            'notes' => $adjustment->notes,
            'warehouse' => ['name' => $adjustment->warehouse->name],
            'items' => $adjustment->items->map(fn ($item): array => [
                'lineNo' => $item->line_no,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
                'unit' => $item->product->baseUnit?->code,
                'systemQuantity' => $item->system_quantity,
                'countedQuantity' => $item->counted_quantity,
                'differenceQuantity' => $item->difference_quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function journal(Journal $journal): array
    {
        $journal->load(['lines.account:id,code,name', 'reversalOf:id,number']);

        return [
            'id' => $journal->id,
            'number' => $journal->number,
            'status' => $journal->status->value,
            'journalDate' => $journal->journal_date->toDateString(),
            'description' => $journal->description,
            'sourceLabel' => JournalSourceResolver::label($journal->source_type),
            'reversalOfNumber' => $journal->reversalOf?->number,
            'totalDebit' => $journal->total_debit,
            'totalCredit' => $journal->total_credit,
            'lines' => $journal->lines->sortBy('line_no')->map(fn ($line): array => [
                'lineNo' => $line->line_no,
                'code' => $line->account->code,
                'name' => $line->account->name,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pricedLine(object $item): array
    {
        return [
            'lineNo' => $item->line_no,
            'sku' => $item->product->sku,
            'name' => $item->product->name,
            'unit' => $item->product->baseUnit?->code,
            'quantity' => $item->quantity,
            'unitPrice' => $item->unit_price,
            'discountAmount' => $item->discount_amount,
            'taxAmount' => $item->tax_amount,
            'lineTotal' => $item->line_total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quantityLine(object $item): array
    {
        return [
            'lineNo' => $item->line_no,
            'sku' => $item->product->sku,
            'name' => $item->product->name,
            'unit' => $item->product->baseUnit?->code,
            'quantity' => $item->quantity,
        ];
    }
}
