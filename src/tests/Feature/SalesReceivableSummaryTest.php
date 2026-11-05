<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->invoices = new SalesInvoiceService;
    $this->order = SalesInvoiceFixtures::approvedOrder([['quantity' => '100.0000', 'unit_price' => '1000.0000']]);
    $this->delivery = SalesInvoiceFixtures::postedDelivery($this->order, ['100.0000']);
    $this->invoice = fn (string $quantity, string $dueDate): mixed => $this->invoices->post(SalesInvoiceFixtures::draftInvoice(
        $this->order,
        [$this->delivery],
        $quantity,
        ['invoice_date' => '2026-10-01', 'due_date' => $dueDate]
    ));
});

test('the dashboard adds up what is owed and counts what is overdue, exactly', function (): void {
    $future = now()->addDays(10)->toDateString();
    $today = now()->toDateString();

    ($this->invoice)('2.0000', $future);
    ($this->invoice)('3.0000', $today);
    ($this->invoice)('5.0000', '2026-10-15');

    $partial = ($this->invoice)('7.0000', '2026-10-15');
    DB::table('sales_invoices')->where('id', $partial->id)->update(['paid_amount' => '2500.00', 'status' => 'partially_paid']);

    $paid = ($this->invoice)('11.0000', '2026-10-15');
    DB::table('sales_invoices')->where('id', $paid->id)->update(['paid_amount' => $paid->total, 'status' => 'paid']);

    $voided = ($this->invoice)('13.0000', '2026-10-15');
    $this->invoices->void($voided, 'Salah tagih');

    SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '17.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']);

    $this->actingAs(userWithPermissions(['sales_invoices.view']))
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('salesInvoices.outstanding_total', '14500.00')
            ->where('salesInvoices.overdue_count', 2)
        );
});

test('an empty ledger reports zero', function (): void {
    $this->actingAs(userWithPermissions(['sales_invoices.view']))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('salesInvoices.outstanding_total', '0.00')
            ->where('salesInvoices.overdue_count', 0)
        );
});

test('the cards need sales_invoices.view', function (): void {
    ($this->invoice)('1.0000', '2026-10-15');

    $this->actingAs(userWithPermissions(['sales_orders.view']))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('salesInvoices', null));
});

test('sales order and delivery pages know whether anything is left to bill', function (): void {
    $user = userWithPermissions(['sales_orders.view', 'delivery_orders.view']);
    $this->flushSession();
    $this->actingAs($user);

    $this->get("/sales/sales-orders/{$this->order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', true));

    $this->get("/sales/delivery-orders/{$this->delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', true));

    ($this->invoice)('100.0000', '2026-10-15');

    $this->get("/sales/sales-orders/{$this->order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', false));

    $this->get("/sales/delivery-orders/{$this->delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', false));
});

test('an approval over the credit limit comes back on customer_id', function (): void {
    $draft = (new SalesOrderService)->create([
        'customer_id' => $this->order->customer_id,
        'warehouse_id' => $this->order->warehouse_id,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $this->order->items[0]->product_id, 'quantity' => '1.0000', 'unit_price' => '20000.0000']],
    ]);
    DB::table('customers')->where('id', $this->order->customer_id)->update(['credit_limit' => '100.00']);

    $this->flushSession();
    $this->actingAs(userWithPermissions(['sales_orders.approve']))
        ->withHeader('X-Inertia', 'true')
        ->from("/sales/sales-orders/{$draft->id}")
        ->post("/sales/sales-orders/{$draft->id}/approve")
        ->assertSessionHasErrors('customer_id');
});
