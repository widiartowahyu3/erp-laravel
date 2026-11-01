<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DocumentType;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('sales order and items can be created with relationships and remaining quantity', function (): void {
    $customerId = TestDataFactory::createCustomer();
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

    $so = SalesOrder::create([
        'number' => 'SO/202611/00001',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'payment_term_id' => $termId,
        'status' => SalesOrderStatus::DRAFT,
        'subtotal' => '100000.00',
        'discount_amount' => '0.00',
        'tax_amount' => '11000.00',
        'total' => '111000.00',
        'notes' => 'Catatan SO',
    ]);

    expect($so->isEditable())->toBeTrue();
    expect($so->customer->id)->toBe($customerId);
    expect($so->warehouse->id)->toBe($warehouseId);
    expect($so->paymentTerm?->id)->toBe($termId);

    $item = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'delivered_quantity' => '3.5000',
        'unit_price' => '10000.0000',
        'discount_amount' => '0.00',
        'tax_rate_id' => $taxRateId,
        'tax_amount' => '1100.00',
        'line_total' => '11100.00',
    ]);

    expect($item->remainingQuantity())->toBe('6.5000');
    expect($item->isFullyDelivered())->toBeFalse();
    expect($item->salesOrder->id)->toBe($so->id);
    expect($item->product->id)->toBe($productId);
    expect($item->taxRate?->id)->toBe($taxRateId);

    expect($so->items)->toHaveCount(1);
    expect($so->items->first()?->id)->toBe($item->id);

    $so->update(['status' => SalesOrderStatus::APPROVED]);
    expect($so->fresh()->isEditable())->toBeFalse();
});

test('delivery order and items can be created with relationships to sales order', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $so = SalesOrder::create([
        'number' => 'SO/202611/00002',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'total' => '50000.00',
    ]);

    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'line_total' => '50000.00',
    ]);

    $do = DeliveryOrder::create([
        'number' => 'DO/202611/00001',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'shipping_address' => 'Jl. Pelanggan No. 123',
        'status' => DeliveryOrderStatus::DRAFT,
        'notes' => 'Pengiriman perdana',
    ]);

    expect($do->isEditable())->toBeTrue();
    expect($do->salesOrder->id)->toBe($so->id);
    expect($do->customer->id)->toBe($customerId);
    expect($do->warehouse->id)->toBe($warehouseId);

    $doItem = DeliveryOrderItem::create([
        'delivery_order_id' => $do->id,
        'line_no' => 1,
        'sales_order_item_id' => $soItem->id,
        'product_id' => $productId,
        'quantity' => '2.0000',
        'unit_cost' => '8000.0000',
    ]);

    expect($doItem->deliveryOrder->id)->toBe($do->id);
    expect($doItem->salesOrderItem->id)->toBe($soItem->id);
    expect($doItem->product->id)->toBe($productId);

    expect($do->items)->toHaveCount(1);
    expect($do->items->first()?->id)->toBe($doItem->id);

    $do->update(['status' => DeliveryOrderStatus::POSTED]);
    expect($do->fresh()->isEditable())->toBeFalse();
});

test('state transitions for SalesOrderStatus and DeliveryOrderStatus behave according to state machine', function (): void {
    // SalesOrderStatus transitions
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DRAFT, SalesOrderStatus::APPROVED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DRAFT, SalesOrderStatus::VOID))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DRAFT, SalesOrderStatus::DELIVERED))->toBeFalse();

    expect(DocumentStateMachine::canTransition(SalesOrderStatus::APPROVED, SalesOrderStatus::PARTIALLY_DELIVERED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::APPROVED, SalesOrderStatus::DELIVERED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::APPROVED, SalesOrderStatus::VOID))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::APPROVED, SalesOrderStatus::DRAFT))->toBeFalse();

    expect(DocumentStateMachine::canTransition(SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::DELIVERED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::APPROVED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::CLOSED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::VOID))->toBeFalse();

    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DELIVERED, SalesOrderStatus::CLOSED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DELIVERED, SalesOrderStatus::PARTIALLY_DELIVERED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DELIVERED, SalesOrderStatus::APPROVED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::DELIVERED, SalesOrderStatus::VOID))->toBeFalse();

    expect(DocumentStateMachine::canTransition(SalesOrderStatus::CLOSED, SalesOrderStatus::DRAFT))->toBeFalse();
    expect(DocumentStateMachine::canTransition(SalesOrderStatus::VOID, SalesOrderStatus::DRAFT))->toBeFalse();

    // DeliveryOrderStatus transitions
    expect(DocumentStateMachine::canTransition(DeliveryOrderStatus::DRAFT, DeliveryOrderStatus::POSTED))->toBeTrue();
    expect(DocumentStateMachine::canTransition(DeliveryOrderStatus::DRAFT, DeliveryOrderStatus::VOID))->toBeTrue();
    expect(DocumentStateMachine::canTransition(DeliveryOrderStatus::POSTED, DeliveryOrderStatus::VOID))->toBeTrue();
    expect(DocumentStateMachine::canTransition(DeliveryOrderStatus::POSTED, DeliveryOrderStatus::DRAFT))->toBeFalse();
    expect(DocumentStateMachine::canTransition(DeliveryOrderStatus::VOID, DeliveryOrderStatus::DRAFT))->toBeFalse();

    // Assert throws exception on invalid transition
    expect(fn () => DocumentStateMachine::assertTransition(SalesOrderStatus::DRAFT, SalesOrderStatus::DELIVERED))
        ->toThrow(InvalidStateTransitionException::class);
});

test('document number service generates gapless sequence for SO and DO', function (): void {
    DB::transaction(function (): void {
        $soNum1 = DocumentNumberService::generate(DocumentType::SO, '2026-11-01');
        $soNum2 = DocumentNumberService::generate(DocumentType::SO, '2026-11-01');
        $doNum1 = DocumentNumberService::generate(DocumentType::DO, '2026-11-01');

        expect($soNum1)->toBe('SO/202611/00001');
        expect($soNum2)->toBe('SO/202611/00002');
        expect($doNum1)->toBe('DO/202611/00001');
    });
});
