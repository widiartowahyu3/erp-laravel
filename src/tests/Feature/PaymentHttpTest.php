<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Services\Accounting\PaymentService;
use App\Services\Sales\SalesInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    (new RoleSeeder)->run();
    $this->finance = userWithRole('finance');
    $this->actingAs($this->finance);

    $this->customerId = TestDataFactory::createCustomer(['credit_limit' => 0, 'name' => 'PT Maju Jaya']);
    $this->invoice = httpInvoiceOf($this->customerId, '3000.0000');
    $this->bank = (int) DB::table('chart_of_accounts')->where('code', '1102')->value('id');
    $this->payload = [
        'customer_id' => $this->customerId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => $this->bank,
        'amount' => '1000.00',
        'reference' => 'TRF-1',
        'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => '1000.00']],
    ];
});

function httpInvoiceOf(int $customerId, string $total): SalesInvoice
{
    $order = SalesInvoiceFixtures::approvedOrder(
        [['quantity' => '1.0000', 'unit_price' => $total]],
        [],
        ['customer_id' => $customerId]
    );
    $delivery = SalesInvoiceFixtures::postedDelivery($order, ['1.0000']);

    return (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [$delivery]));
}

test('the list renders camelCase rows newest first with its filter options', function (): void {
    $service = new PaymentService;
    $older = $service->createReceipt(array_merge($this->payload, ['payment_date' => '2026-11-01']));
    $newer = $service->createReceipt($this->payload);

    $this->get('/accounting/payments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Payments/Index')
            ->has('items.data', 2)
            ->where('items.data.0.id', $newer->id)
            ->where('items.data.1.id', $older->id)
            ->where('items.data.0.number', $newer->number)
            ->where('items.data.0.paymentDate', '2026-11-04')
            ->where('items.data.0.customer.name', 'PT Maju Jaya')
            ->where('items.data.0.method', 'bank_transfer')
            ->where('items.data.0.methodLabel', 'Transfer Bank')
            ->where('items.data.0.account.code', '1102')
            ->where('items.data.0.reference', 'TRF-1')
            ->where('items.data.0.amount', '1000.00')
            ->where('items.data.0.status', 'draft')
            ->has('customers')
            ->has('methods', 3)
            ->where('statuses', ['draft', 'posted', 'void'])
        );
});

test('the list filters by status, customer, method, dates, type and search', function (): void {
    $service = new PaymentService;
    $cash = (int) DB::table('chart_of_accounts')->where('code', '1101')->value('id');
    $draft = $service->createReceipt(array_merge($this->payload, ['payment_date' => '2026-11-01', 'reference' => 'ALFA', 'method' => 'cash', 'account_id' => $cash]));
    $posted = $service->post($service->createReceipt($this->payload));

    $ids = fn (string $query): array => collect(
        $this->get('/accounting/payments?'.$query)->assertOk()->viewData('page')['props']['items']['data']
    )->pluck('id')->sort()->values()->all();

    expect($ids('filter[status]=draft'))->toBe([$draft->id])
        ->and($ids('filter[status]=posted'))->toBe([$posted->id])
        ->and($ids('filter[method]=cash'))->toBe([$draft->id])
        ->and($ids('filter[customer_id]=999999999'))->toBe([])
        ->and($ids('filter[customer_id]='.$this->customerId))->toHaveCount(2)
        ->and($ids('filter[payment_type]=disbursement'))->toBe([])
        ->and($ids('filter[payment_type]=receipt'))->toHaveCount(2)
        ->and($ids('filter[date_from]=2026-11-02&filter[date_to]=2026-11-30'))->toBe([$posted->id])
        ->and($ids('search=ALFA'))->toBe([$draft->id])
        ->and($ids('search='.urlencode($posted->number)))->toBe([$posted->id]);
});

test('the list runs a bounded number of queries however many rows it has', function (): void {
    $service = new PaymentService;
    $small = array_merge($this->payload, ['amount' => '1.00', 'allocations' => []]);

    foreach (range(1, 5) as $i) {
        $service->createReceipt($small);
    }

    $this->get('/accounting/payments')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/accounting/payments')->assertOk();
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(1, 15) as $i) {
        $service->createReceipt($small);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/accounting/payments')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(12);
});

test('create sends the options and no invoices until a customer is chosen', function (): void {
    $this->get('/accounting/payments/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Payments/Create')
            ->has('customers')
            ->has('cashBankAccounts', 2)
            ->where('cashBankAccounts.0.code', '1101')
            ->where('cashBankAccounts.1.code', '1102')
            ->has('methods', 3)
            ->where('selectedCustomerId', null)
            ->where('outstandingInvoices', [])
            ->where('defaults.paymentDate', now()->toDateString())
        );
});

test('create with a customer sends only that customer\'s open invoices', function (): void {
    $other = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $foreign = httpInvoiceOf($other, '999.0000');

    $response = $this->get('/accounting/payments/create?customer_id='.$this->customerId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedCustomerId', $this->customerId)
            ->has('outstandingInvoices', 1)
            ->where('outstandingInvoices.0.id', $this->invoice->id)
            ->where('outstandingInvoices.0.number', $this->invoice->number)
            ->where('outstandingInvoices.0.invoiceDate', '2026-11-03')
            ->where('outstandingInvoices.0.dueDate', '2026-11-03')
            ->where('outstandingInvoices.0.total', '3000.00')
            ->where('outstandingInvoices.0.paidAmount', '0.00')
            ->where('outstandingInvoices.0.outstanding', '3000.00')
        );

    expect(collect($response->viewData('page')['props']['outstandingInvoices'])->pluck('id'))->not->toContain($foreign->id);
});

test('an unknown or inactive customer gets no invoices', function (): void {
    $this->get('/accounting/payments/create?customer_id=999999999')
        ->assertInertia(fn (Assert $page) => $page->where('selectedCustomerId', null)->where('outstandingInvoices', []));

    $this->get('/accounting/payments/create?customer_id=abc')
        ->assertInertia(fn (Assert $page) => $page->where('selectedCustomerId', null));

    DB::table('customers')->where('id', $this->customerId)->update(['is_active' => false]);

    $this->get('/accounting/payments/create?customer_id='.$this->customerId)
        ->assertInertia(fn (Assert $page) => $page->where('selectedCustomerId', null)->where('outstandingInvoices', []));
});

test('open invoices are available through a partial reload', function (): void {
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Accounting/Payments/Create',
        'X-Inertia-Partial-Data' => 'outstandingInvoices,selectedCustomerId',
    ])->get('/accounting/payments/create?customer_id='.$this->customerId)
        ->assertOk()
        ->assertJsonCount(1, 'props.outstandingInvoices')
        ->assertJsonPath('props.selectedCustomerId', $this->customerId)
        ->assertJsonMissingPath('props.customers');
});

test('store creates a draft and ignores fields it does not accept', function (): void {
    $response = $this->post('/accounting/payments', $this->payload + [
        'status' => 'posted',
        'journal_id' => 1,
        'number' => 'PAY/000000/00000',
        'supplier_id' => 1,
    ]);

    $payment = Payment::where('customer_id', $this->customerId)->firstOrFail();

    $response->assertRedirect("/accounting/payments/{$payment->id}");

    expect($payment->status)->toBe(PaymentStatus::DRAFT)
        ->and($payment->number)->toMatch('#^PAY/\d{6}/\d{5}$#')
        ->and($payment->number)->not->toBe('PAY/000000/00000')
        ->and($payment->journal_id)->toBeNull()
        ->and($payment->supplier_id)->toBeNull()
        ->and($payment->created_by)->toBe($this->finance->id);
});

test('store refuses a disbursement on payment_type', function (): void {
    $this->withHeader('X-Inertia', 'true')
        ->from('/accounting/payments/create')
        ->post('/accounting/payments', array_merge($this->payload, ['payment_type' => 'disbursement']))
        ->assertSessionHasErrors('payment_type');

    expect(Payment::count())->toBe(0);
});

test('store and update validate their input', function (array $change, string $field): void {
    $draft = (new PaymentService)->createReceipt($this->payload);
    $count = Payment::count();

    $this->from('/accounting/payments/create')
        ->post('/accounting/payments', array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);

    $this->from("/accounting/payments/{$draft->id}/edit")
        ->put("/accounting/payments/{$draft->id}", array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);

    expect(Payment::count())->toBe($count);
})->with([
    'missing customer' => [['customer_id' => null], 'customer_id'],
    'unknown customer' => [['customer_id' => 999999999], 'customer_id'],
    'missing date' => [['payment_date' => null], 'payment_date'],
    'bad date' => [['payment_date' => '04-11-2026'], 'payment_date'],
    'future date' => [['payment_date' => '2099-01-01'], 'payment_date'],
    'unknown method' => [['method' => 'crypto'], 'method'],
    'missing account' => [['account_id' => null], 'account_id'],
    'unknown account' => [['account_id' => 999999999], 'account_id'],
    'missing amount' => [['amount' => null], 'amount'],
    'zero amount' => [['amount' => '0'], 'amount'],
    'negative amount' => [['amount' => '-1'], 'amount'],
    'not a decimal' => [['amount' => '1e3'], 'amount'],
    'too many decimals' => [['amount' => '1.001'], 'amount'],
    'zero allocation' => [['allocations' => [['sales_invoice_id' => 1, 'amount' => '0']]], 'allocations.0.amount'],
    'unknown invoice' => [['allocations' => [['sales_invoice_id' => 999999999, 'amount' => '1.00']]], 'allocations.0.sales_invoice_id'],
]);

test('business rules come back on their field', function (): void {
    $sales = (int) DB::table('chart_of_accounts')->where('code', '4101')->value('id');

    $this->withHeader('X-Inertia', 'true')
        ->from('/accounting/payments/create')
        ->post('/accounting/payments', array_merge($this->payload, ['account_id' => $sales]))
        ->assertRedirect('/accounting/payments/create')
        ->assertSessionHasErrors('account_id');

    $this->withHeader('X-Inertia', 'true')
        ->from('/accounting/payments/create')
        ->post('/accounting/payments', array_merge($this->payload, [
            'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => '1000.01']],
        ]))
        ->assertSessionHasErrors('allocations');
});

test('reference, notes and the number of allocations are limited', function (): void {
    $this->post('/accounting/payments', array_merge($this->payload, ['reference' => str_repeat('a', 101)]))
        ->assertSessionHasErrors('reference');

    $this->post('/accounting/payments', array_merge($this->payload, ['notes' => str_repeat('a', 1001)]))
        ->assertSessionHasErrors('notes');

    $this->post('/accounting/payments', array_merge($this->payload, [
        'allocations' => array_fill(0, 201, ['sales_invoice_id' => $this->invoice->id, 'amount' => '1']),
    ]))->assertSessionHasErrors('allocations');
});

test('show sends the header, allocations and journal', function (): void {
    $posted = (new PaymentService)->post((new PaymentService)->createReceipt($this->payload));

    $this->get("/accounting/payments/{$posted->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Payments/Show')
            ->where('item.id', $posted->id)
            ->where('item.number', $posted->number)
            ->where('item.status', 'posted')
            ->where('item.typeLabel', 'Penerimaan')
            ->where('item.paymentDate', '2026-11-04')
            ->where('item.method', 'bank_transfer')
            ->where('item.methodLabel', 'Transfer Bank')
            ->where('item.account.code', '1102')
            ->where('item.amount', '1000.00')
            ->where('item.reference', 'TRF-1')
            ->where('item.customer.id', $this->customerId)
            ->where('item.poster.id', $this->finance->id)
            ->has('item.allocations', 1)
            ->where('item.allocations.0.salesInvoiceId', $this->invoice->id)
            ->where('item.allocations.0.invoiceNumber', $this->invoice->number)
            ->where('item.allocations.0.amount', '1000.00')
            ->where('journal.id', $posted->journal_id)
            ->has('journal.number')
        );
});

test('a viewer without journal access sees the payment but not its journal', function (): void {
    $posted = (new PaymentService)->post((new PaymentService)->createReceipt($this->payload));

    $this->flushSession();
    $this->actingAs(userWithPermissions(['payments.view']))
        ->get("/accounting/payments/{$posted->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('journal', null));
});

test('the invoice page lists its payment allocations', function (): void {
    $posted = (new PaymentService)->post((new PaymentService)->createReceipt($this->payload));

    $this->get("/sales/invoices/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 1)
            ->where('allocations.0.paymentId', $posted->id)
            ->where('allocations.0.number', $posted->number)
            ->where('allocations.0.paymentDate', '2026-11-04')
            ->where('allocations.0.status', 'posted')
            ->where('allocations.0.amount', '1000.00')
            ->where('item.paidAmount', '1000.00')
            ->where('item.outstanding', '2000.00')
            ->where('item.status', 'partially_paid')
        );
});

test('edit is only for drafts and pre-fills the saved values', function (): void {
    $draft = (new PaymentService)->createReceipt($this->payload);

    $this->get("/accounting/payments/{$draft->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Payments/Edit')
            ->where('item.id', $draft->id)
            ->where('item.customerId', $this->customerId)
            ->where('item.accountId', $this->bank)
            ->where('item.amount', '1000.00')
            ->where('item.allocations.0.salesInvoiceId', $this->invoice->id)
            ->where('item.allocations.0.amount', '1000.00')
            ->where('selectedCustomerId', $this->customerId)
            ->has('outstandingInvoices', 1)
            ->has('cashBankAccounts', 2)
        );

    $posted = (new PaymentService)->post($draft);

    $this->get("/accounting/payments/{$posted->id}/edit")
        ->assertRedirect("/accounting/payments/{$posted->id}")
        ->assertSessionHas('error');
});

test('update replaces a draft through the service', function (): void {
    $draft = (new PaymentService)->createReceipt($this->payload);

    $this->put("/accounting/payments/{$draft->id}", array_merge($this->payload, [
        'amount' => '500.00',
        'reference' => '',
        'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => '500.00']],
    ]))->assertRedirect("/accounting/payments/{$draft->id}");

    $reloaded = $draft->fresh(['allocations']);

    expect($reloaded->amount)->toBe('500.00')
        ->and($reloaded->reference)->toBeNull()
        ->and($reloaded->allocations[0]->amount)->toBe('500.00');
});

test('post and void go through their routes with the rules intact', function (): void {
    $draft = (new PaymentService)->createReceipt($this->payload);

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/post")
        ->assertRedirect("/accounting/payments/{$draft->id}")
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PaymentStatus::POSTED)
        ->and($this->invoice->fresh()->status)->toBe(SalesInvoiceStatus::PARTIALLY_PAID);

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/void", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/void", ['reason' => str_repeat('a', 256)])
        ->assertSessionHasErrors('reason');

    expect($draft->fresh()->status)->toBe(PaymentStatus::POSTED);

    $this->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/void", ['reason' => 'Salah'])
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PaymentStatus::VOID)
        ->and($this->invoice->fresh()->status)->toBe(SalesInvoiceStatus::POSTED);
});

test('posting allocations that fall short comes back on allocations', function (): void {
    $draft = (new PaymentService)->createReceipt(array_merge($this->payload, ['allocations' => []]));

    $this->withHeader('X-Inertia', 'true')
        ->from("/accounting/payments/{$draft->id}")
        ->post("/accounting/payments/{$draft->id}/post")
        ->assertRedirect("/accounting/payments/{$draft->id}")
        ->assertSessionHasErrors('allocations');
});

test('unknown payments answer 404 and guests are redirected to login', function (): void {
    $this->get('/accounting/payments/999999999')->assertNotFound();
    $this->post('/accounting/payments/999999999/post')->assertNotFound();

    auth()->logout();

    foreach ([
        ['GET', '/accounting/payments'],
        ['GET', '/accounting/payments/create'],
        ['POST', '/accounting/payments'],
        ['GET', '/accounting/payments/1'],
        ['GET', '/accounting/payments/1/edit'],
        ['PUT', '/accounting/payments/1'],
        ['POST', '/accounting/payments/1/post'],
        ['POST', '/accounting/payments/1/void'],
    ] as [$method, $uri]) {
        $response = $this->call($method, $uri);

        expect($response->status())->toBe(302)
            ->and($response->headers->get('Location'))->toContain('/login');
    }
});

test('every route needs exactly its own permission', function (string $method, string $uri, string $permission): void {
    $draft = (new PaymentService)->createReceipt($this->payload);
    $target = str_replace('{id}', (string) $draft->id, $uri);

    $this->flushSession();
    $this->actingAs(userWithPermissions([]))->call($method, $target)->assertForbidden();

    $allOthers = array_values(array_diff(
        ['payments.view', 'payments.create', 'payments.update', 'payments.post', 'payments.void', 'sales_invoices.view', 'journals.view'],
        [$permission]
    ));

    $this->flushSession();
    $this->actingAs(userWithPermissions($allOthers))->call($method, $target)->assertForbidden();

    $this->flushSession();
    $status = $this->actingAs(userWithPermissions([$permission]))->call($method, $target)->status();

    expect($status)->not->toBe(403);
})->with([
    'index' => ['GET', '/accounting/payments', 'payments.view'],
    'create' => ['GET', '/accounting/payments/create', 'payments.create'],
    'store' => ['POST', '/accounting/payments', 'payments.create'],
    'show' => ['GET', '/accounting/payments/{id}', 'payments.view'],
    'edit' => ['GET', '/accounting/payments/{id}/edit', 'payments.update'],
    'update' => ['PUT', '/accounting/payments/{id}', 'payments.update'],
    'post' => ['POST', '/accounting/payments/{id}/post', 'payments.post'],
    'void' => ['POST', '/accounting/payments/{id}/void', 'payments.void'],
]);

test('the open invoice list needs payments.create', function (): void {
    $this->flushSession();
    $this->actingAs(userWithPermissions(['payments.view', 'sales_invoices.view']))
        ->get('/accounting/payments/create?customer_id='.$this->customerId)
        ->assertForbidden();
});

test('the seeded roles get what the role table says', function (string $role, bool $view, bool $write): void {
    $draft = (new PaymentService)->createReceipt($this->payload);
    $user = userWithRole($role);

    $this->flushSession();
    $this->actingAs($user)->get('/accounting/payments')->assertStatus($view ? 200 : 403);
    $this->actingAs($user)->get("/accounting/payments/{$draft->id}")->assertStatus($view ? 200 : 403);

    expect($this->actingAs($user)->post('/accounting/payments', $this->payload)->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/accounting/payments/{$draft->id}/post")->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/accounting/payments/{$draft->id}/void", ['reason' => 'x'])->status() === 403)->toBe(! $write);
})->with([
    'finance' => ['finance', true, true],
    'admin' => ['admin', true, false],
    'viewer' => ['viewer', true, false],
    'sales' => ['sales', false, false],
    'warehouse' => ['warehouse', false, false],
    'purchasing' => ['purchasing', false, false],
]);

test('all payment routes carry auth, one can and the write limiter', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'accounting/payments'));

    expect($routes->count())->toBe(8);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')
            ->and(collect($middleware)->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:payments.'))->count())->toBe(1);

        if (! in_array('GET', $route->methods(), true)) {
            expect($middleware)->toContain('throttle:erp-write');
        }
    }
});

test('the write limiter answers 429 on the payment routes', function (): void {
    $this->flushSession();
    $this->actingAs(userWithPermissions(['payments.create']));

    $statuses = [];
    foreach (range(1, 80) as $i) {
        $statuses[] = $this->post('/accounting/payments', [])->status();
    }

    expect($statuses)->toContain(429);
});

test('journals of a payment link back to it', function (): void {
    $posted = (new PaymentService)->post((new PaymentService)->createReceipt($this->payload));

    $this->flushSession();
    $this->actingAs(userWithRole('super_admin'))
        ->get('/accounting/journals?filter[source_type]=payment')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.sourceLabel', 'Pembayaran')
            ->where('items.data.0.sourceUrl', route('accounting.payments.show', $posted->id))
        );
});
