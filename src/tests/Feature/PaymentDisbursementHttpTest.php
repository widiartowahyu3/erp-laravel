<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Services\Accounting\PaymentService;
use App\Services\Purchasing\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    (new RoleSeeder)->run();
    $this->finance = userWithRole('finance');
    $this->actingAs($this->finance);

    $this->supplierId = TestDataFactory::createSupplier(['name' => 'PT Pemasok Utama']);
    $this->invoice = disbursementHttpInvoice($this->supplierId, '3000.0000');
    $this->bank = (int) DB::table('chart_of_accounts')->where('code', '1102')->value('id');
    $this->payload = [
        'payment_type' => 'disbursement',
        'supplier_id' => $this->supplierId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => $this->bank,
        'amount' => '1000.00',
        'reference' => 'OUT-1',
        'allocations' => [['purchase_invoice_id' => $this->invoice->id, 'amount' => '1000.00']],
    ];
});

function disbursementHttpInvoice(int $supplierId, string $total): PurchaseInvoice
{
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => $total]], [], ['supplier_id' => $supplierId]);
    $receipt = PurchaseInvoiceFixtures::postedReceipt($order, ['1.0000']);

    return (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($order, [$receipt]));
}

test('store creates a draft disbursement and the page shows it', function (): void {
    $response = $this->post('/accounting/payments', $this->payload);
    $payment = Payment::where('supplier_id', $this->supplierId)->firstOrFail();

    $response->assertRedirect("/accounting/payments/{$payment->id}");

    expect($payment->status)->toBe(PaymentStatus::DRAFT)
        ->and($payment->payment_type->value)->toBe('disbursement')
        ->and($payment->customer_id)->toBeNull();

    $this->get("/accounting/payments/{$payment->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Payments/Show')
            ->where('item.paymentType', 'disbursement')
            ->where('item.typeLabel', 'Pengeluaran')
            ->where('item.customer', null)
            ->where('item.supplier.name', 'PT Pemasok Utama')
            ->where('item.allocations.0.purchaseInvoiceId', $this->invoice->id)
            ->where('item.allocations.0.salesInvoiceId', null)
            ->where('item.allocations.0.invoiceNumber', $this->invoice->number)
        );
});

test('create in disbursement mode sends suppliers and only that supplier\'s open invoices', function (): void {
    $other = disbursementHttpInvoice(TestDataFactory::createSupplier(), '999.0000');

    $response = $this->get("/accounting/payments/create?payment_type=disbursement&supplier_id={$this->supplierId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('paymentType', 'disbursement')
            ->has('suppliers')
            ->where('selectedSupplierId', $this->supplierId)
            ->has('outstandingPurchaseInvoices', 1)
            ->where('outstandingPurchaseInvoices.0.id', $this->invoice->id)
            ->where('outstandingPurchaseInvoices.0.number', $this->invoice->number)
            ->where('outstandingPurchaseInvoices.0.supplierInvoiceNumber', $this->invoice->supplier_invoice_number)
            ->where('outstandingPurchaseInvoices.0.outstanding', '3000.00')
            ->where('outstandingInvoices', [])
        );

    expect(collect($response->viewData('page')['props']['outstandingPurchaseInvoices'])->pluck('id'))->not->toContain($other->id);
});

test('create defaults to receipts and an unknown type stays a receipt', function (): void {
    $this->get('/accounting/payments/create')
        ->assertInertia(fn (Assert $page) => $page->where('paymentType', 'receipt'));

    $this->get('/accounting/payments/create?payment_type=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('paymentType', 'receipt'));
});

test('an unknown or inactive supplier gets no invoices', function (): void {
    $this->get('/accounting/payments/create?payment_type=disbursement&supplier_id=999999999')
        ->assertInertia(fn (Assert $page) => $page->where('selectedSupplierId', null)->where('outstandingPurchaseInvoices', []));

    DB::table('suppliers')->where('id', $this->supplierId)->update(['is_active' => false]);

    $this->get("/accounting/payments/create?payment_type=disbursement&supplier_id={$this->supplierId}")
        ->assertInertia(fn (Assert $page) => $page->where('selectedSupplierId', null));
});

test('the list filters by type and supplier and names the party', function (): void {
    $service = new PaymentService;
    $disbursement = $service->createDisbursement($this->payload);
    $customerId = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $receipt = $service->createReceipt([
        'customer_id' => $customerId,
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $this->bank,
        'amount' => '1.00',
    ]);

    $ids = fn (string $query): array => collect(
        $this->get('/accounting/payments?'.$query)->assertOk()->viewData('page')['props']['items']['data']
    )->pluck('id')->all();

    expect($ids('filter[payment_type]=disbursement'))->toBe([$disbursement->id])
        ->and($ids('filter[payment_type]=receipt'))->toBe([$receipt->id])
        ->and($ids('filter[supplier_id]='.$this->supplierId))->toBe([$disbursement->id]);

    $this->get('/accounting/payments?filter[payment_type]=disbursement')
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.paymentType', 'disbursement')
            ->where('items.data.0.supplier.name', 'PT Pemasok Utama')
            ->where('items.data.0.customer', null)
            ->has('suppliers')
        );
});

test('store and update validate by type', function (array $change, string $field): void {
    $draft = (new PaymentService)->createDisbursement($this->payload);

    $this->from('/accounting/payments/create')
        ->post('/accounting/payments', array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);

    $this->from("/accounting/payments/{$draft->id}/edit")
        ->put("/accounting/payments/{$draft->id}", array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);
})->with([
    'missing supplier' => [['supplier_id' => null], 'supplier_id'],
    'unknown supplier' => [['supplier_id' => 999999999], 'supplier_id'],
    'sales invoice line' => [['allocations' => [['sales_invoice_id' => 1, 'amount' => '1.00']]], 'allocations.0.purchase_invoice_id'],
    'unknown invoice' => [['allocations' => [['purchase_invoice_id' => 999999999, 'amount' => '1.00']]], 'allocations.0.purchase_invoice_id'],
    'zero amount' => [['amount' => '0'], 'amount'],
]);

test('a receipt request cannot allocate to a purchase invoice', function (): void {
    $this->from('/accounting/payments/create')
        ->post('/accounting/payments', [
            'customer_id' => TestDataFactory::createCustomer(),
            'payment_date' => '2026-11-04',
            'method' => 'cash',
            'account_id' => $this->bank,
            'amount' => '1.00',
            'allocations' => [['purchase_invoice_id' => $this->invoice->id, 'amount' => '1.00']],
        ])
        ->assertSessionHasErrors('allocations.0.sales_invoice_id');
});

test('edit pre-fills a draft disbursement and update keeps its type', function (): void {
    $draft = (new PaymentService)->createDisbursement($this->payload);

    $this->get("/accounting/payments/{$draft->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('paymentType', 'disbursement')
            ->where('item.supplierId', $this->supplierId)
            ->where('item.allocations.0.purchaseInvoiceId', $this->invoice->id)
            ->where('selectedSupplierId', $this->supplierId)
            ->has('outstandingPurchaseInvoices', 1)
        );

    $this->put("/accounting/payments/{$draft->id}", array_merge($this->payload, [
        'amount' => '500.00',
        'allocations' => [['purchase_invoice_id' => $this->invoice->id, 'amount' => '500.00']],
    ]))->assertRedirect("/accounting/payments/{$draft->id}");

    expect($draft->fresh()->amount)->toBe('500.00')
        ->and($draft->fresh()->payment_type->value)->toBe('disbursement');
});

test('posting and voiding go through the same routes', function (): void {
    $draft = (new PaymentService)->createDisbursement($this->payload);

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/post")
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PaymentStatus::POSTED)
        ->and($this->invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::PARTIALLY_PAID);

    $this->get("/purchasing/invoices/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 1)
            ->where('allocations.0.paymentId', $draft->id)
            ->where('item.paidAmount', '1000.00')
        );

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/void", ['reason' => 'Salah'])
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PaymentStatus::VOID)
        ->and($this->invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::POSTED);
});

test('disbursements need the same payments permissions as receipts', function (string $method, string $uri, string $permission): void {
    $draft = (new PaymentService)->createDisbursement($this->payload);
    $target = str_replace('{id}', (string) $draft->id, $uri);

    $this->flushSession();
    $this->actingAs(userWithPermissions(['purchase_invoices.view', 'purchase_invoices.create', 'purchase_invoices.post']))->call($method, $target)->assertForbidden();

    $this->flushSession();
    expect($this->actingAs(userWithPermissions([$permission]))->call($method, $target)->status())->not->toBe(403);
})->with([
    'create page' => ['GET', '/accounting/payments/create?payment_type=disbursement', 'payments.create'],
    'open invoices' => ['GET', '/accounting/payments/create?payment_type=disbursement&supplier_id=1', 'payments.create'],
    'store' => ['POST', '/accounting/payments', 'payments.create'],
    'show' => ['GET', '/accounting/payments/{id}', 'payments.view'],
    'update' => ['PUT', '/accounting/payments/{id}', 'payments.update'],
    'post' => ['POST', '/accounting/payments/{id}/post', 'payments.post'],
    'void' => ['POST', '/accounting/payments/{id}/void', 'payments.void'],
]);
