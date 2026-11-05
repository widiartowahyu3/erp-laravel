<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Sales\CreditExposureService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->orders = new SalesOrderService;
    $this->invoices = new SalesInvoiceService;

    // A customer owing 5000 on one posted invoice.
    $this->billedOrder = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $this->billedInvoice = $this->invoices->post(SalesInvoiceFixtures::draftInvoice(
        $this->billedOrder,
        [SalesInvoiceFixtures::postedDelivery($this->billedOrder, ['5.0000'])]
    ));
    $this->customerId = $this->billedOrder->customer_id;
    $this->warehouseId = $this->billedOrder->warehouse_id;

    $this->draftOrder = fn (string $total, ?int $customerId = null): SalesOrder => $this->orders->create([
        'customer_id' => $customerId ?? $this->customerId,
        'warehouse_id' => $this->warehouseId,
        'order_date' => '2026-11-03',
        'items' => [[
            'product_id' => TestDataFactory::createProduct(),
            'quantity' => '1.0000',
            'unit_price' => $total,
        ]],
    ]);

    $this->setLimit = function (string $limit, ?int $customerId = null): void {
        DB::table('customers')->where('id', $customerId ?? $this->customerId)->update(['credit_limit' => $limit]);
    };
});

test('an order under the limit is approved', function (): void {
    ($this->setLimit)('10000.00');

    $approved = $this->orders->approve(($this->draftOrder)('4999.9900'));

    expect($approved->status)->toBe(SalesOrderStatus::APPROVED);
});

test('an order reaching the limit exactly is approved', function (): void {
    ($this->setLimit)('10000.00');

    $approved = $this->orders->approve(($this->draftOrder)('5000.0000'));

    expect($approved->status)->toBe(SalesOrderStatus::APPROVED);
});

test('an order past the limit is refused with the figures and stays a draft', function (): void {
    ($this->setLimit)('10000.00');
    $order = ($this->draftOrder)('5000.0100');

    try {
        $this->orders->approve($order);
        $this->fail('The approval should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->field)->toBe('customer_id')
            ->and($e->getMessage())->toBe('Batas kredit pelanggan terlampaui (batas 10000, piutang beredar 5000, pesanan ini 5000.01)');
    }

    $reloaded = $order->fresh();

    expect($reloaded->status)->toBe(SalesOrderStatus::DRAFT)
        ->and($reloaded->approved_at)->toBeNull();
});

test('a limit of zero means no limit', function (): void {
    ($this->setLimit)('0.00');

    expect($this->orders->approve(($this->draftOrder)('999999.0000'))->status)->toBe(SalesOrderStatus::APPROVED);
});

test('only what is still unpaid counts', function (): void {
    ($this->setLimit)('10000.00');

    DB::table('sales_invoices')->where('id', $this->billedInvoice->id)->update(['paid_amount' => '2000.00', 'status' => 'partially_paid']);

    expect(app(CreditExposureService::class)->outstanding($this->customerId))->toBe('3000.00')
        ->and($this->orders->approve(($this->draftOrder)('7000.0000'))->status)->toBe(SalesOrderStatus::APPROVED);
});

test('paid and voided invoices do not count', function (): void {
    ($this->setLimit)('1000.00');

    expect(fn () => $this->orders->approve(($this->draftOrder)('1000.0000')))->toThrow(BusinessRuleException::class);

    DB::table('sales_invoices')->where('id', $this->billedInvoice->id)->update(['paid_amount' => '5000.00', 'status' => 'paid']);

    expect(app(CreditExposureService::class)->outstanding($this->customerId))->toBe('0.00')
        ->and($this->orders->approve(($this->draftOrder)('1000.0000'))->status)->toBe(SalesOrderStatus::APPROVED);

    DB::table('sales_invoices')->where('id', $this->billedInvoice->id)->update(['paid_amount' => '0.00', 'status' => 'void']);

    expect(app(CreditExposureService::class)->outstanding($this->customerId))->toBe('0.00');
});

test('draft invoices do not count', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    SalesInvoiceFixtures::draftInvoice($order, [SalesInvoiceFixtures::postedDelivery($order, ['2.0000'])]);

    expect(app(CreditExposureService::class)->outstanding($order->customer_id))->toBe('0.00');
});

test('a customer is only charged with their own invoices', function (): void {
    $other = TestDataFactory::createCustomer(['credit_limit' => '1000.00']);

    expect($this->orders->approve(($this->draftOrder)('1000.0000', $other))->status)->toBe(SalesOrderStatus::APPROVED);
});

test('open orders and uninvoiced deliveries are the documented gap', function (): void {
    ($this->setLimit)('6000.00');

    $this->orders->approve(($this->draftOrder)('1000.0000'));

    expect(app(CreditExposureService::class)->outstanding($this->customerId))->toBe('5000.00')
        ->and($this->orders->approve(($this->draftOrder)('1000.0000'))->status)->toBe(SalesOrderStatus::APPROVED);
});

test('approving locks the customer before the order', function (): void {
    $order = ($this->draftOrder)('1000.0000');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->orders->approve($order);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $position = function (string $table) use ($queries): int {
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update')) {
                return $index;
            }
        }

        return -1;
    };

    expect($position('customers'))->toBeGreaterThanOrEqual(0)
        ->and($position('sales_orders'))->toBeGreaterThan($position('customers'));
});

test('the state check still comes first for an order that is not a draft', function (): void {
    ($this->setLimit)('1.00');
    $order = ($this->draftOrder)('1.0000');
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'void']);

    expect(fn () => $this->orders->approve($order->fresh()))
        ->toThrow(BusinessRuleException::class, 'Transisi status');
});
