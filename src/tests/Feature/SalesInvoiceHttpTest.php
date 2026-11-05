<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Sales\SalesInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    (new RoleSeeder)->run();
    $this->finance = userWithRole('finance');
    $this->actingAs($this->finance);

    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $this->order = SalesInvoiceFixtures::approvedOrder([[
        'quantity' => '3.0000',
        'unit_price' => '1000.0000',
        'discount_amount' => '100.00',
        'tax_rate_id' => $tax,
    ]]);
    $this->delivery = SalesInvoiceFixtures::postedDelivery($this->order, ['3.0000']);
    $this->deliveryItem = $this->delivery->items[0];
    $this->payload = [
        'sales_order_id' => $this->order->id,
        'invoice_date' => '2026-11-03',
        'items' => [['delivery_order_item_id' => $this->deliveryItem->id, 'quantity' => '3.0000']],
    ];
});

/**
 * @param  array<string, mixed>  $overrides
 */
function postedInvoiceFor(SalesOrder $order, array $overrides = []): SalesInvoice
{
    $delivery = SalesInvoiceFixtures::postedDelivery($order, ['1.0000']);

    return (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [$delivery], null, $overrides));
}

test('the list renders camelCase rows newest first', function (): void {
    $older = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-11-01']);
    $newer = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-11-03']);

    $this->get('/sales/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Invoices/Index')
            ->has('items.data', 2)
            ->where('items.data.0.id', $newer->id)
            ->where('items.data.1.id', $older->id)
            ->where('items.data.0.number', $newer->number)
            ->where('items.data.0.invoiceDate', '2026-11-03')
            ->where('items.data.0.status', 'draft')
            ->where('items.data.0.total', '1073.00')
            ->where('items.data.0.paidAmount', '0.00')
            ->where('items.data.0.outstanding', '1073.00')
            ->where('items.data.0.salesOrderNumber', $this->order->number)
            ->where('items.data.0.customer.id', $this->order->customer_id)
            ->has('customers')
            ->where('statuses', ['draft', 'posted', 'partially_paid', 'paid', 'void'])
        );
});

test('the list filters by status, customer, dates, search and overdue', function (): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-11-01', 'due_date' => '2026-11-02']);
    $service = new SalesInvoiceService;
    $overdue = $service->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']));
    $notDue = $service->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-11-02', 'due_date' => now()->addDays(30)->toDateString()]));

    $ids = fn (string $query): array => collect(
        $this->get('/sales/invoices?'.$query)->assertOk()->viewData('page')['props']['items']['data']
    )->pluck('id')->sort()->values()->all();

    expect($ids('filter[status]=draft'))->toBe([$draft->id])
        ->and($ids('filter[status]=posted'))->toBe([$overdue->id, $notDue->id])
        ->and($ids('filter[customer_id]=999999999'))->toBe([])
        ->and($ids('filter[customer_id]='.$this->order->customer_id))->toHaveCount(3)
        ->and($ids('filter[date_from]=2026-11-01&filter[date_to]=2026-11-01'))->toBe([$draft->id])
        ->and($ids('search='.urlencode($notDue->number)))->toBe([$notDue->id])
        ->and($ids('filter[overdue]=1'))->toBe([$overdue->id]);
});

test('a fully paid or voided invoice is never overdue', function (): void {
    $service = new SalesInvoiceService;
    $paid = $service->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']));
    $voided = $service->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']));
    $partial = $service->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']));

    DB::table('sales_invoices')->where('id', $paid->id)->update(['paid_amount' => $paid->total, 'status' => 'paid']);
    $service->void($voided, 'Salah tagih');
    DB::table('sales_invoices')->where('id', $partial->id)->update(['paid_amount' => '1.00', 'status' => 'partially_paid']);

    $rows = $this->get('/sales/invoices?filter[overdue]=1')->viewData('page')['props']['items']['data'];

    expect(array_column($rows, 'id'))->toBe([$partial->id])
        ->and($rows[0]['isOverdue'])->toBeTrue();
});

test('the list runs a bounded number of queries however many rows it has', function (): void {
    foreach (range(1, 6) as $i) {
        $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']]);
        postedInvoiceFor($order);
    }

    // The first request also warms per-user lookups, so the comparison starts from the second one.
    $this->get('/sales/invoices')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/sales/invoices')->assertOk();
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(1, 12) as $i) {
        $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']]);
        postedInvoiceFor($order);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/sales/invoices')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(12);
});

test('create without an order lists the orders that still have something to bill', function (): void {
    $nothingDelivered = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']]);
    $fullyBilled = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']]);
    postedInvoiceFor($fullyBilled);

    $this->get('/sales/invoices/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Invoices/Create')
            ->where('salesOrder', null)
            ->where('eligibleItems', [])
            ->has('billableOrders', 1)
            ->where('billableOrders.0.id', $this->order->id)
            ->where('billableOrders.0.number', $this->order->number)
            ->has('billableOrders.0.customer.name')
        );

    $missing = collect($this->get('/sales/invoices/create')->viewData('page')['props']['billableOrders'])->pluck('id');

    expect($missing)->not->toContain($nothingDelivered->id)
        ->and($missing)->not->toContain($fullyBilled->id);
});

test('the order list can be searched by number and customer', function (): void {
    $this->get('/sales/invoices/create?search='.urlencode($this->order->number))
        ->assertInertia(fn (Assert $page) => $page->has('billableOrders', 1));

    $this->get('/sales/invoices/create?search=tidak-ada-yang-cocok')
        ->assertInertia(fn (Assert $page) => $page->has('billableOrders', 0));
});

test('create with an order sends its lines priced by the server and the suggested dates', function (): void {
    $this->get('/sales/invoices/create?sales_order_id='.$this->order->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Invoices/Create')
            ->where('salesOrder.id', $this->order->id)
            ->where('salesOrder.number', $this->order->number)
            ->where('salesOrder.customer.id', $this->order->customer_id)
            ->where('salesOrder.termDays', 0)
            ->where('defaults.invoiceDate', now()->toDateString())
            ->where('defaults.dueDate', now()->toDateString())
            ->has('eligibleItems', 1)
            ->where('eligibleItems.0.deliveryOrderItemId', $this->deliveryItem->id)
            ->where('eligibleItems.0.deliveryOrderNumber', $this->delivery->number)
            ->where('eligibleItems.0.productName', 'Product Test')
            ->where('eligibleItems.0.deliveredQuantity', '3.0000')
            ->where('eligibleItems.0.remainingQuantity', '3.0000')
            ->where('eligibleItems.0.quantity', '3.0000')
            ->where('eligibleItems.0.unitPrice', '1000.0000')
            ->where('eligibleItems.0.discountAmount', '100.00')
            ->where('eligibleItems.0.taxAmount', '319.00')
            ->where('eligibleItems.0.lineTotal', '3219.00')
        );
});

test('an order that cannot be billed sends the user back to the list', function (): void {
    $nothingDelivered = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']]);

    $this->get('/sales/invoices/create?sales_order_id='.$nothingDelivered->id)
        ->assertRedirect('/sales/invoices/create')
        ->assertSessionHas('error');

    $this->get('/sales/invoices/create?sales_order_id=999999999')
        ->assertRedirect('/sales/invoices/create')
        ->assertSessionHas('error');
});

test('store creates a draft computed by the server and ignores any amounts sent', function (): void {
    $response = $this->post('/sales/invoices', $this->payload + [
        'subtotal' => '1.00',
        'total' => '1.00',
        'status' => 'posted',
        'paid_amount' => '999.00',
        'customer_id' => 999999,
    ]);

    $invoice = SalesInvoice::where('sales_order_id', $this->order->id)->firstOrFail();

    $response->assertRedirect("/sales/invoices/{$invoice->id}");

    expect($invoice->status)->toBe(SalesInvoiceStatus::DRAFT)
        ->and($invoice->total)->toBe('3219.00')
        ->and($invoice->paid_amount)->toBe('0.00')
        ->and($invoice->customer_id)->toBe($this->order->customer_id)
        ->and($invoice->created_by)->toBe($this->finance->id);
});

test('store and update validate their input', function (array $change, string $field): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000');

    $this->from('/sales/invoices/create')
        ->post('/sales/invoices', array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);

    if (! array_key_exists('sales_order_id', $change)) {
        $this->from("/sales/invoices/{$draft->id}/edit")
            ->put("/sales/invoices/{$draft->id}", array_merge($this->payload, $change))
            ->assertSessionHasErrors($field);
    }

    expect(SalesInvoice::where('sales_order_id', $this->order->id)->count())->toBe(1);
})->with([
    'missing order' => [['sales_order_id' => null], 'sales_order_id'],
    'unknown order' => [['sales_order_id' => 999999999], 'sales_order_id'],
    'missing date' => [['invoice_date' => null], 'invoice_date'],
    'bad date' => [['invoice_date' => '03-11-2026'], 'invoice_date'],
    'future date' => [['invoice_date' => '2099-01-01'], 'invoice_date'],
    'due before invoice' => [['due_date' => '2026-11-02'], 'due_date'],
    'no items' => [['items' => []], 'items'],
    'zero quantity' => [['items' => [['delivery_order_item_id' => 1, 'quantity' => '0']]], 'items.0.quantity'],
    'negative quantity' => [['items' => [['delivery_order_item_id' => 1, 'quantity' => '-1']]], 'items.0.quantity'],
    'not a decimal' => [['items' => [['delivery_order_item_id' => 1, 'quantity' => '1e3']]], 'items.0.quantity'],
    'too many decimals' => [['items' => [['delivery_order_item_id' => 1, 'quantity' => '1.00001']]], 'items.0.quantity'],
    'unknown delivery line' => [['items' => [['delivery_order_item_id' => 999999999, 'quantity' => '1']]], 'items.0.delivery_order_item_id'],
]);

test('notes and the number of lines are limited', function (): void {
    $this->post('/sales/invoices', array_merge($this->payload, ['notes' => str_repeat('a', 1001)]))
        ->assertSessionHasErrors('notes');

    $this->post('/sales/invoices', array_merge($this->payload, [
        'items' => array_fill(0, 201, ['delivery_order_item_id' => $this->deliveryItem->id, 'quantity' => '1']),
    ]))->assertSessionHasErrors('items');
});

test('billing more than the remainder comes back as an error on items', function (): void {
    $this->withHeader('X-Inertia', 'true')
        ->from('/sales/invoices/create')
        ->post('/sales/invoices', array_merge($this->payload, [
            'items' => [['delivery_order_item_id' => $this->deliveryItem->id, 'quantity' => '3.0001']],
        ]))
        ->assertRedirect('/sales/invoices/create')
        ->assertSessionHasErrors('items');
});

test('show sends the header, lines, journal, allocations and outstanding amount', function (): void {
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], null, ['notes' => 'Catatan']));

    $this->get("/sales/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Invoices/Show')
            ->where('item.id', $invoice->id)
            ->where('item.number', $invoice->number)
            ->where('item.status', 'posted')
            ->where('item.invoiceDate', '2026-11-03')
            ->where('item.dueDate', '2026-11-03')
            ->where('item.subtotal', '3000.00')
            ->where('item.discountAmount', '100.00')
            ->where('item.taxAmount', '319.00')
            ->where('item.total', '3219.00')
            ->where('item.paidAmount', '0.00')
            ->where('item.outstanding', '3219.00')
            ->where('item.notes', 'Catatan')
            ->where('item.salesOrder.id', $this->order->id)
            ->where('item.customer.id', $this->order->customer_id)
            ->where('item.poster.id', $this->finance->id)
            ->has('item.items', 1)
            ->where('item.items.0.lineNo', 1)
            ->where('item.items.0.description', 'Product Test')
            ->where('item.items.0.deliveryOrderId', $this->delivery->id)
            ->where('item.items.0.deliveryOrderNumber', $this->delivery->number)
            ->where('item.items.0.quantity', '3.0000')
            ->where('item.items.0.unitPrice', '1000.0000')
            ->where('item.items.0.discountAmount', '100.00')
            ->where('item.items.0.taxAmount', '319.00')
            ->where('item.items.0.lineTotal', '3219.00')
            ->where('journal.id', $invoice->journal_id)
            ->has('journal.number')
            ->where('allocations', [])
        );
});

test('show lists payment allocations read only', function (): void {
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));
    $accountId = (int) DB::table('chart_of_accounts')->where('code', '1101')->value('id');
    $paymentId = DB::table('payments')->insertGetId([
        'number' => 'PAY/202611/99999',
        'payment_type' => 'receipt',
        'customer_id' => $this->order->customer_id,
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $accountId,
        'amount' => '1000.00',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('payment_allocations')->insert([
        'payment_id' => $paymentId,
        'sales_invoice_id' => $invoice->id,
        'amount' => '1000.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get("/sales/invoices/{$invoice->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 1)
            ->where('allocations.0.paymentId', $paymentId)
            ->where('allocations.0.number', 'PAY/202611/99999')
            ->where('allocations.0.paymentDate', '2026-11-04')
            ->where('allocations.0.status', 'posted')
            ->where('allocations.0.amount', '1000.00')
        );
});

test('a viewer without journal access sees the invoice but not its journal', function (): void {
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));

    $this->actingAs(userWithPermissions(['sales_invoices.view']))
        ->get("/sales/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('journal', null));
});

test('edit is only for drafts and pre-fills the saved quantities', function (): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '2.0000', ['notes' => 'Draf']);

    $this->get("/sales/invoices/{$draft->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Invoices/Edit')
            ->where('item.id', $draft->id)
            ->where('item.number', $draft->number)
            ->where('item.invoiceDate', '2026-11-03')
            ->where('item.notes', 'Draf')
            ->where('salesOrder.id', $this->order->id)
            ->has('eligibleItems', 1)
            ->where('eligibleItems.0.remainingQuantity', '3.0000')
            ->where('eligibleItems.0.quantity', '2.0000')
        );

    $posted = (new SalesInvoiceService)->post($draft);

    $this->get("/sales/invoices/{$posted->id}/edit")
        ->assertRedirect("/sales/invoices/{$posted->id}")
        ->assertSessionHas('error');
});

test('update replaces a draft through the service', function (): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]);

    $this->put("/sales/invoices/{$draft->id}", [
        'invoice_date' => '2026-11-04',
        'due_date' => '2026-11-10',
        'notes' => 'Diubah',
        'items' => [['delivery_order_item_id' => $this->deliveryItem->id, 'quantity' => '1.0000']],
    ])->assertRedirect("/sales/invoices/{$draft->id}");

    $reloaded = $draft->fresh();

    expect($reloaded->subtotal)->toBe('1000.00')
        ->and($reloaded->notes)->toBe('Diubah')
        ->and($reloaded->due_date->toDateString())->toBe('2026-11-10');
});

test('post and void go through their routes with the rules intact', function (): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]);

    $this->from("/sales/invoices/{$draft->id}")
        ->post("/sales/invoices/{$draft->id}/post")
        ->assertRedirect("/sales/invoices/{$draft->id}")
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(SalesInvoiceStatus::POSTED);

    $this->from("/sales/invoices/{$draft->id}")
        ->post("/sales/invoices/{$draft->id}/void", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->from("/sales/invoices/{$draft->id}")
        ->post("/sales/invoices/{$draft->id}/void", ['reason' => str_repeat('a', 256)])
        ->assertSessionHasErrors('reason');

    expect($draft->fresh()->status)->toBe(SalesInvoiceStatus::POSTED);

    $this->from("/sales/invoices/{$draft->id}")
        ->post("/sales/invoices/{$draft->id}/void", ['reason' => 'x'])
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(SalesInvoiceStatus::VOID);
});

test('refusing to void a paid invoice comes back on status', function (): void {
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));
    DB::table('sales_invoices')->where('id', $invoice->id)->update(['paid_amount' => '1.00', 'status' => 'partially_paid']);

    $this->withHeader('X-Inertia', 'true')
        ->from("/sales/invoices/{$invoice->id}")
        ->post("/sales/invoices/{$invoice->id}/void", ['reason' => 'Salah'])
        ->assertRedirect("/sales/invoices/{$invoice->id}")
        ->assertSessionHasErrors('status');
});

test('unknown invoices answer 404 and guests are redirected to login', function (): void {
    $this->get('/sales/invoices/999999999')->assertNotFound();
    $this->post('/sales/invoices/999999999/post')->assertNotFound();

    auth()->logout();

    foreach ([
        ['GET', '/sales/invoices'],
        ['GET', '/sales/invoices/create'],
        ['POST', '/sales/invoices'],
        ['GET', '/sales/invoices/1'],
        ['GET', '/sales/invoices/1/edit'],
        ['PUT', '/sales/invoices/1'],
        ['POST', '/sales/invoices/1/post'],
        ['POST', '/sales/invoices/1/void'],
    ] as [$method, $uri]) {
        $response = $this->call($method, $uri);

        expect($response->status())->toBe(302)
            ->and($response->headers->get('Location'))->toContain('/login');
    }
});

test('every route needs exactly its own permission', function (string $method, string $uri, string $permission): void {
    $invoice = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000');
    $target = str_replace('{id}', (string) $invoice->id, $uri);

    $this->actingAs(userWithPermissions([]))->call($method, $target)->assertForbidden();

    $allOthers = array_values(array_diff(
        ['sales_invoices.view', 'sales_invoices.create', 'sales_invoices.update', 'sales_invoices.post', 'sales_invoices.void', 'sales_orders.view', 'delivery_orders.view'],
        [$permission]
    ));

    // Each identity gets a fresh session: the app logs out a session whose user changes.
    $this->flushSession();
    $this->actingAs(userWithPermissions($allOthers))->call($method, $target)->assertForbidden();

    $this->flushSession();
    $status = $this->actingAs(userWithPermissions([$permission, 'sales_orders.view']))->call($method, $target)->status();

    expect($status)->not->toBe(403);
})->with([
    'index' => ['GET', '/sales/invoices', 'sales_invoices.view'],
    'create' => ['GET', '/sales/invoices/create', 'sales_invoices.create'],
    'store' => ['POST', '/sales/invoices', 'sales_invoices.create'],
    'show' => ['GET', '/sales/invoices/{id}', 'sales_invoices.view'],
    'edit' => ['GET', '/sales/invoices/{id}/edit', 'sales_invoices.update'],
    'update' => ['PUT', '/sales/invoices/{id}', 'sales_invoices.update'],
    'post' => ['POST', '/sales/invoices/{id}/post', 'sales_invoices.post'],
    'void' => ['POST', '/sales/invoices/{id}/void', 'sales_invoices.void'],
]);

test('the seeded roles get what the role table says', function (string $role, bool $view, bool $write): void {
    $invoice = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery], '1.0000');
    $user = userWithRole($role);

    $this->actingAs($user)->get('/sales/invoices')->assertStatus($view ? 200 : 403);
    $this->actingAs($user)->get("/sales/invoices/{$invoice->id}")->assertStatus($view ? 200 : 403);

    expect($this->actingAs($user)->post('/sales/invoices', $this->payload)->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/sales/invoices/{$invoice->id}/post")->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/sales/invoices/{$invoice->id}/void", ['reason' => 'x'])->status() === 403)->toBe(! $write);
})->with([
    'finance' => ['finance', true, true],
    'admin' => ['admin', true, false],
    'sales' => ['sales', true, false],
    'viewer' => ['viewer', true, false],
    'warehouse' => ['warehouse', false, false],
    'purchasing' => ['purchasing', false, false],
]);

test('all invoice routes carry auth, can and the write limiter', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'sales/invoices'));

    expect($routes->count())->toBe(8);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')
            ->and(collect($middleware)->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:sales_invoices.')))->toBeTrue();

        if (! in_array('GET', $route->methods(), true)) {
            expect($middleware)->toContain('throttle:erp-write');
        }
    }
});

test('the write limiter answers 429 on the invoice routes', function (): void {
    $this->actingAs(userWithPermissions(['sales_invoices.create']));

    $statuses = [];
    foreach (range(1, 80) as $i) {
        $statuses[] = $this->post('/sales/invoices', [])->status();
    }

    expect($statuses)->toContain(429);
});

test('journals of an invoice link back to it', function (): void {
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));

    $this->actingAs(userWithRole('super_admin'))
        ->get('/accounting/journals?filter[source_type]=sales_invoice')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.sourceLabel', 'Faktur Penjualan')
            ->where('items.data.0.sourceUrl', route('sales.invoices.show', $invoice->id))
        );
});

test('the sales order form shows each customer\'s credit position with one query for all of them', function (): void {
    (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));
    DB::table('customers')->where('id', $this->order->customer_id)->update(['credit_limit' => '50000.00']);
    $this->actingAs(userWithRole('super_admin'));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->get('/sales/sales-orders/create')->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $customer = collect($response->viewData('page')['props']['customers'])->firstWhere('id', $this->order->customer_id);

    expect($customer['creditLimit'])->toBe('50000.00')
        ->and($customer['outstandingReceivable'])->toBe('3219.00')
        ->and($customer)->toHaveKeys(['id', 'code', 'name', 'payment_term_id', 'address']);

    $invoiceQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `sales_invoices`'));

    expect($invoiceQueries)->toHaveCount(1);
});
