<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('purchase order and items can be created with relationships and remaining quantity', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $uniqueSuffix = uniqid();
    $termId = DB::table('payment_terms')->insertGetId([
        'code' => 'PT_'.$uniqueSuffix,
        'name' => 'Tempo '.$uniqueSuffix,
        'days' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $taxRateId = DB::table('tax_rates')->insertGetId([
        'code' => 'TX_'.$uniqueSuffix,
        'name' => 'PPN '.$uniqueSuffix,
        'rate' => '11.00',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00001',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'expected_date' => '2026-11-05',
        'payment_term_id' => $termId,
        'status' => PurchaseOrderStatus::DRAFT,
        'subtotal' => '100000.00',
        'discount_amount' => '0.00',
        'tax_amount' => '11000.00',
        'total' => '111000.00',
        'notes' => 'Catatan PO',
    ]);

    expect($po->isEditable())->toBeTrue();
    expect($po->supplier->id)->toBe($supplierId);
    expect($po->warehouse->id)->toBe($warehouseId);
    expect($po->paymentTerm?->id)->toBe($termId);

    $item = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'received_quantity' => '3.5000',
        'unit_price' => '10000.0000',
        'discount_amount' => '0.00',
        'tax_rate_id' => $taxRateId,
        'tax_amount' => '1100.00',
        'line_total' => '11100.00',
    ]);

    expect($item->remainingQuantity())->toBe('6.5000');
    expect($item->purchaseOrder->id)->toBe($po->id);
    expect($item->product->id)->toBe($productId);
    expect($item->taxRate?->id)->toBe($taxRateId);

    expect($po->items)->toHaveCount(1);
    expect($po->items->first()?->id)->toBe($item->id);

    $po->update(['status' => PurchaseOrderStatus::APPROVED]);
    expect($po->fresh()->isEditable())->toBeFalse();
});

test('goods receipt and items can be created with relationships to purchase order', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00002',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'total' => '50000.00',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'received_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'line_total' => '50000.00',
    ]);

    $gr = GoodsReceipt::create([
        'number' => 'GR/202610/00001',
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'status' => GoodsReceiptStatus::DRAFT,
        'notes' => 'Penerimaan bertahap',
    ]);

    expect($gr->isEditable())->toBeTrue();
    expect($gr->purchaseOrder->id)->toBe($po->id);
    expect($gr->supplier->id)->toBe($supplierId);
    expect($gr->warehouse->id)->toBe($warehouseId);

    $grItem = GoodsReceiptItem::create([
        'goods_receipt_id' => $gr->id,
        'line_no' => 1,
        'purchase_order_item_id' => $poItem->id,
        'product_id' => $productId,
        'quantity' => '2.0000',
        'unit_cost' => '10000.0000',
    ]);

    expect($grItem->goodsReceipt->id)->toBe($gr->id);
    expect($grItem->purchaseOrderItem->id)->toBe($poItem->id);
    expect($grItem->product->id)->toBe($productId);

    expect($gr->items)->toHaveCount(1);
    expect($gr->items->first()?->id)->toBe($grItem->id);

    $gr->update(['status' => GoodsReceiptStatus::POSTED]);
    expect($gr->fresh()->isEditable())->toBeFalse();
});
