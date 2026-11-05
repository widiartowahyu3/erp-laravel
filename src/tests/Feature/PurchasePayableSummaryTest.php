<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Purchasing\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurchaseInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->invoices = new PurchaseInvoiceService;
    $this->order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '100.0000', 'unit_price' => '1000.0000']]);
    $this->receipt = PurchaseInvoiceFixtures::postedReceipt($this->order, ['100.0000']);
    $this->invoice = fn (string $quantity, string $dueDate): PurchaseInvoice => $this->invoices->post(PurchaseInvoiceFixtures::draftInvoice(
        $this->order,
        [$this->receipt],
        $quantity,
        ['invoice_date' => '2026-10-01', 'due_date' => $dueDate]
    ));
});

test('the dashboard adds up what is owed and counts what is overdue, exactly', function (): void {
    ($this->invoice)('2.0000', now()->addDays(10)->toDateString());
    ($this->invoice)('3.0000', now()->toDateString());
    ($this->invoice)('5.0000', '2026-10-15');

    $partial = ($this->invoice)('7.0000', '2026-10-15');
    DB::table('purchase_invoices')->where('id', $partial->id)->update(['paid_amount' => '2500.00', 'status' => 'partially_paid']);

    $paid = ($this->invoice)('11.0000', '2026-10-15');
    DB::table('purchase_invoices')->where('id', $paid->id)->update(['paid_amount' => $paid->total, 'status' => 'paid']);

    $voided = ($this->invoice)('13.0000', '2026-10-15');
    $this->invoices->void($voided, 'Salah tagih');

    PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '17.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']);

    $this->actingAs(userWithPermissions(['purchase_invoices.view']))
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('purchaseInvoices.outstanding_total', '14500.00')
            ->where('purchaseInvoices.overdue_count', 2)
        );
});

test('an empty ledger reports zero', function (): void {
    $this->actingAs(userWithPermissions(['purchase_invoices.view']))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseInvoices.outstanding_total', '0.00')
            ->where('purchaseInvoices.overdue_count', 0)
        );
});

test('the cards need purchase_invoices.view', function (): void {
    $this->actingAs(userWithPermissions(['purchase_orders.view']))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('purchaseInvoices', null));
});

test('purchase order and goods receipt pages know whether anything is left to bill', function (): void {
    $this->flushSession();
    $this->actingAs(userWithPermissions(['purchase_orders.view', 'goods_receipts.view']));

    $this->get("/purchasing/purchase-orders/{$this->order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', true));

    $this->get("/purchasing/goods-receipts/{$this->receipt->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', true));

    ($this->invoice)('100.0000', '2026-10-15');

    $this->get("/purchasing/purchase-orders/{$this->order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', false));

    $this->get("/purchasing/goods-receipts/{$this->receipt->id}")
        ->assertInertia(fn (Assert $page) => $page->where('hasBillableItems', false));
});
