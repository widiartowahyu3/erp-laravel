<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    (new RoleSeeder)->run();
    $this->finance = userWithRole('finance');
    $this->actingAs($this->finance);

    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $this->order = PurchaseInvoiceFixtures::approvedOrder([[
        'quantity' => '3.0000',
        'unit_price' => '1000.0000',
        'discount_amount' => '100.00',
        'tax_rate_id' => $tax,
    ]]);
    $this->receipt = PurchaseInvoiceFixtures::postedReceipt($this->order, ['3.0000']);
    $this->receiptItem = $this->receipt->items[0];
    $this->payload = [
        'purchase_order_id' => $this->order->id,
        'supplier_invoice_number' => 'INV-100',
        'invoice_date' => '2026-11-03',
        'items' => [['goods_receipt_item_id' => $this->receiptItem->id, 'quantity' => '3.0000']],
    ];
});

function postedPurchaseInvoiceForOrder(PurchaseOrder $order, array $overrides = []): PurchaseInvoice
{
    $receipt = PurchaseInvoiceFixtures::postedReceipt($order, ['1.0000']);

    return (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($order, [$receipt], null, $overrides));
}

test('the list renders camelCase rows newest first', function (): void {
    $older = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['invoice_date' => '2026-11-01', 'supplier_invoice_number' => 'INV-OLD']);
    $newer = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['invoice_date' => '2026-11-03', 'supplier_invoice_number' => 'INV-NEW']);

    $this->get('/purchasing/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Invoices/Index')
            ->has('items.data', 2)
            ->where('items.data.0.id', $newer->id)
            ->where('items.data.1.id', $older->id)
            ->where('items.data.0.number', $newer->number)
            ->where('items.data.0.supplierInvoiceNumber', 'INV-NEW')
            ->where('items.data.0.invoiceDate', '2026-11-03')
            ->where('items.data.0.status', 'draft')
            ->where('items.data.0.total', '1073.00')
            ->where('items.data.0.outstanding', '1073.00')
            ->where('items.data.0.purchaseOrderNumber', $this->order->number)
            ->where('items.data.0.supplier.id', $this->order->supplier_id)
            ->has('suppliers')
            ->where('statuses', ['draft', 'posted', 'partially_paid', 'paid', 'void'])
        );
});

test('the list filters by status, supplier, dates, both numbers and overdue', function (): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['invoice_date' => '2026-11-01', 'due_date' => '2026-11-02', 'supplier_invoice_number' => 'ALFA-1']);
    $service = new PurchaseInvoiceService;
    $overdue = $service->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['invoice_date' => '2026-10-01', 'due_date' => '2026-10-15']));
    $notDue = $service->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['invoice_date' => '2026-11-02', 'due_date' => now()->addDays(30)->toDateString()]));

    $ids = fn (string $query): array => collect(
        $this->get('/purchasing/invoices?'.$query)->assertOk()->viewData('page')['props']['items']['data']
    )->pluck('id')->sort()->values()->all();

    expect($ids('filter[status]=draft'))->toBe([$draft->id])
        ->and($ids('filter[status]=posted'))->toBe([$overdue->id, $notDue->id])
        ->and($ids('filter[supplier_id]=999999999'))->toBe([])
        ->and($ids('filter[supplier_id]='.$this->order->supplier_id))->toHaveCount(3)
        ->and($ids('filter[date_from]=2026-11-01&filter[date_to]=2026-11-01'))->toBe([$draft->id])
        ->and($ids('search='.urlencode($notDue->number)))->toBe([$notDue->id])
        ->and($ids('search=ALFA-1'))->toBe([$draft->id])
        ->and($ids('filter[overdue]=1'))->toBe([$overdue->id]);
});

test('the list runs a bounded number of queries however many rows it has', function (): void {
    foreach (range(1, 5) as $i) {
        postedPurchaseInvoiceForOrder(PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '100.0000']]));
    }

    $this->get('/purchasing/invoices')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/purchasing/invoices')->assertOk();
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(1, 10) as $i) {
        postedPurchaseInvoiceForOrder(PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '100.0000']]));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/purchasing/invoices')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(12);
});

test('create without an order lists the orders that still have something to bill', function (): void {
    $nothingReceived = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '100.0000']]);
    $fullyBilled = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '100.0000']]);
    postedPurchaseInvoiceForOrder($fullyBilled);

    $response = $this->get('/purchasing/invoices/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Invoices/Create')
            ->where('purchaseOrder', null)
            ->where('eligibleItems', [])
            ->has('billableOrders', 1)
            ->where('billableOrders.0.id', $this->order->id)
            ->has('billableOrders.0.supplier.name')
        );

    $ids = collect($response->viewData('page')['props']['billableOrders'])->pluck('id');

    expect($ids)->not->toContain($nothingReceived->id)->and($ids)->not->toContain($fullyBilled->id);

    $this->get('/purchasing/invoices/create?search=tidak-ada-yang-cocok')
        ->assertInertia(fn (Assert $page) => $page->has('billableOrders', 0));
});

test('create with an order sends its lines priced by the server and the suggested dates', function (): void {
    $this->get('/purchasing/invoices/create?purchase_order_id='.$this->order->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.id', $this->order->id)
            ->where('purchaseOrder.supplier.id', $this->order->supplier_id)
            ->where('purchaseOrder.termDays', 0)
            ->where('defaults.invoiceDate', now()->toDateString())
            ->has('eligibleItems', 1)
            ->where('eligibleItems.0.goodsReceiptItemId', $this->receiptItem->id)
            ->where('eligibleItems.0.goodsReceiptNumber', $this->receipt->number)
            ->where('eligibleItems.0.receivedQuantity', '3.0000')
            ->where('eligibleItems.0.remainingQuantity', '3.0000')
            ->where('eligibleItems.0.unitPrice', '1000.0000')
            ->where('eligibleItems.0.discountAmount', '100.00')
            ->where('eligibleItems.0.taxAmount', '319.00')
            ->where('eligibleItems.0.lineTotal', '3219.00')
        );
});

test('an order that cannot be billed sends the user back to the list', function (): void {
    $this->get('/purchasing/invoices/create?purchase_order_id=999999999')
        ->assertRedirect('/purchasing/invoices/create')
        ->assertSessionHas('error');
});

test('store creates a draft computed by the server and ignores any amounts sent', function (): void {
    $response = $this->post('/purchasing/invoices', $this->payload + [
        'total' => '1.00',
        'status' => 'posted',
        'paid_amount' => '999.00',
        'supplier_id' => 999999,
    ]);

    $invoice = PurchaseInvoice::where('purchase_order_id', $this->order->id)->firstOrFail();

    $response->assertRedirect("/purchasing/invoices/{$invoice->id}");

    expect($invoice->status)->toBe(PurchaseInvoiceStatus::DRAFT)
        ->and($invoice->total)->toBe('3219.00')
        ->and($invoice->paid_amount)->toBe('0.00')
        ->and($invoice->supplier_id)->toBe($this->order->supplier_id)
        ->and($invoice->created_by)->toBe($this->finance->id);
});

test('store and update validate their input', function (array $change, string $field): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000');

    $this->from('/purchasing/invoices/create')
        ->post('/purchasing/invoices', array_merge($this->payload, $change))
        ->assertSessionHasErrors($field);

    if (! array_key_exists('purchase_order_id', $change)) {
        $this->from("/purchasing/invoices/{$draft->id}/edit")
            ->put("/purchasing/invoices/{$draft->id}", array_merge($this->payload, $change))
            ->assertSessionHasErrors($field);
    }

    expect(PurchaseInvoice::where('purchase_order_id', $this->order->id)->count())->toBe(1);
})->with([
    'missing order' => [['purchase_order_id' => null], 'purchase_order_id'],
    'unknown order' => [['purchase_order_id' => 999999999], 'purchase_order_id'],
    'missing supplier number' => [['supplier_invoice_number' => ''], 'supplier_invoice_number'],
    'blank supplier number' => [['supplier_invoice_number' => '   '], 'supplier_invoice_number'],
    'missing date' => [['invoice_date' => null], 'invoice_date'],
    'future date' => [['invoice_date' => '2099-01-01'], 'invoice_date'],
    'due before invoice' => [['due_date' => '2026-11-02'], 'due_date'],
    'no items' => [['items' => []], 'items'],
    'zero quantity' => [['items' => [['goods_receipt_item_id' => 1, 'quantity' => '0']]], 'items.0.quantity'],
    'not a decimal' => [['items' => [['goods_receipt_item_id' => 1, 'quantity' => '1e3']]], 'items.0.quantity'],
    'unknown receipt line' => [['items' => [['goods_receipt_item_id' => 999999999, 'quantity' => '1']]], 'items.0.goods_receipt_item_id'],
]);

test('the supplier number length and the number of lines are limited', function (): void {
    $this->post('/purchasing/invoices', array_merge($this->payload, ['supplier_invoice_number' => str_repeat('a', 51)]))
        ->assertSessionHasErrors('supplier_invoice_number');

    $this->post('/purchasing/invoices', array_merge($this->payload, [
        'items' => array_fill(0, 201, ['goods_receipt_item_id' => $this->receiptItem->id, 'quantity' => '1']),
    ]))->assertSessionHasErrors('items');
});

test('a duplicate supplier number comes back on its field from the service', function (): void {
    PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['supplier_invoice_number' => 'INV-100']);

    $this->withHeader('X-Inertia', 'true')
        ->from('/purchasing/invoices/create')
        ->post('/purchasing/invoices', $this->payload)
        ->assertRedirect('/purchasing/invoices/create')
        ->assertSessionHasErrors('supplier_invoice_number');
});

test('a duplicate that races past the service still comes back on its field from the database', function (): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000', ['supplier_invoice_number' => 'INV-RACE']);

    Route::post('/_test/supplier-number-race', function () use ($draft) {
        DB::table('purchase_invoices')->insert([
            'number' => 'PI/209912/99999',
            'supplier_id' => $draft->supplier_id,
            'supplier_invoice_number' => 'INV-RACE',
            'invoice_date' => '2026-11-03',
            'due_date' => '2026-11-03',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->middleware('web');

    $this->from('/purchasing/invoices/create')
        ->post('/_test/supplier-number-race')
        ->assertRedirect('/purchasing/invoices/create')
        ->assertSessionHasErrors('supplier_invoice_number');
});

test('billing more than the remainder comes back as an error on items', function (): void {
    $this->withHeader('X-Inertia', 'true')
        ->from('/purchasing/invoices/create')
        ->post('/purchasing/invoices', array_merge($this->payload, [
            'items' => [['goods_receipt_item_id' => $this->receiptItem->id, 'quantity' => '3.0001']],
        ]))
        ->assertRedirect('/purchasing/invoices/create')
        ->assertSessionHasErrors('items');
});

test('show sends the header, lines, journal, allocations and outstanding amount', function (): void {
    $invoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], null, ['notes' => 'Catatan', 'supplier_invoice_number' => 'INV-S']));

    $this->get("/purchasing/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Invoices/Show')
            ->where('item.id', $invoice->id)
            ->where('item.number', $invoice->number)
            ->where('item.supplierInvoiceNumber', 'INV-S')
            ->where('item.status', 'posted')
            ->where('item.subtotal', '3000.00')
            ->where('item.discountAmount', '100.00')
            ->where('item.taxAmount', '319.00')
            ->where('item.total', '3219.00')
            ->where('item.outstanding', '3219.00')
            ->where('item.purchaseOrder.id', $this->order->id)
            ->where('item.supplier.id', $this->order->supplier_id)
            ->where('item.poster.id', $this->finance->id)
            ->has('item.items', 1)
            ->where('item.items.0.goodsReceiptId', $this->receipt->id)
            ->where('item.items.0.goodsReceiptNumber', $this->receipt->number)
            ->where('item.items.0.lineTotal', '3219.00')
            ->where('journal.id', $invoice->journal_id)
            ->where('allocations', [])
        );
});

test('edit is only for drafts and pre-fills the saved quantities', function (): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '2.0000', ['supplier_invoice_number' => 'INV-E']);

    $this->get("/purchasing/invoices/{$draft->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Invoices/Edit')
            ->where('item.id', $draft->id)
            ->where('item.supplierInvoiceNumber', 'INV-E')
            ->where('purchaseOrder.id', $this->order->id)
            ->where('eligibleItems.0.remainingQuantity', '3.0000')
            ->where('eligibleItems.0.quantity', '2.0000')
        );

    $posted = (new PurchaseInvoiceService)->post($draft);

    $this->get("/purchasing/invoices/{$posted->id}/edit")
        ->assertRedirect("/purchasing/invoices/{$posted->id}")
        ->assertSessionHas('error');
});

test('update, post and void go through their routes with the rules intact', function (): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt]);

    $this->put("/purchasing/invoices/{$draft->id}", [
        'supplier_invoice_number' => 'INV-U',
        'invoice_date' => '2026-11-04',
        'items' => [['goods_receipt_item_id' => $this->receiptItem->id, 'quantity' => '1.0000']],
    ])->assertRedirect("/purchasing/invoices/{$draft->id}");

    expect($draft->fresh()->supplier_invoice_number)->toBe('INV-U')
        ->and($draft->fresh()->subtotal)->toBe('1000.00');

    $this->from("/purchasing/invoices/{$draft->id}")
        ->post("/purchasing/invoices/{$draft->id}/post")
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PurchaseInvoiceStatus::POSTED);

    $this->from("/purchasing/invoices/{$draft->id}")
        ->post("/purchasing/invoices/{$draft->id}/void", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->from("/purchasing/invoices/{$draft->id}")
        ->post("/purchasing/invoices/{$draft->id}/void", ['reason' => 'x'])
        ->assertSessionHas('success');

    expect($draft->fresh()->status)->toBe(PurchaseInvoiceStatus::VOID);
});

test('unknown invoices answer 404 and guests are redirected to login', function (): void {
    $this->get('/purchasing/invoices/999999999')->assertNotFound();

    auth()->logout();

    foreach ([
        ['GET', '/purchasing/invoices'],
        ['GET', '/purchasing/invoices/create'],
        ['POST', '/purchasing/invoices'],
        ['GET', '/purchasing/invoices/1'],
        ['GET', '/purchasing/invoices/1/edit'],
        ['PUT', '/purchasing/invoices/1'],
        ['POST', '/purchasing/invoices/1/post'],
        ['POST', '/purchasing/invoices/1/void'],
    ] as [$method, $uri]) {
        $response = $this->call($method, $uri);

        expect($response->status())->toBe(302)
            ->and($response->headers->get('Location'))->toContain('/login');
    }
});

test('every route needs exactly its own permission', function (string $method, string $uri, string $permission): void {
    $invoice = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000');
    $target = str_replace('{id}', (string) $invoice->id, $uri);

    $this->flushSession();
    $this->actingAs(userWithPermissions([]))->call($method, $target)->assertForbidden();

    $allOthers = array_values(array_diff(
        ['purchase_invoices.view', 'purchase_invoices.create', 'purchase_invoices.update', 'purchase_invoices.post', 'purchase_invoices.void', 'purchase_orders.view', 'goods_receipts.view'],
        [$permission]
    ));

    $this->flushSession();
    $this->actingAs(userWithPermissions($allOthers))->call($method, $target)->assertForbidden();

    $this->flushSession();
    expect($this->actingAs(userWithPermissions([$permission]))->call($method, $target)->status())->not->toBe(403);
})->with([
    'index' => ['GET', '/purchasing/invoices', 'purchase_invoices.view'],
    'create' => ['GET', '/purchasing/invoices/create', 'purchase_invoices.create'],
    'store' => ['POST', '/purchasing/invoices', 'purchase_invoices.create'],
    'show' => ['GET', '/purchasing/invoices/{id}', 'purchase_invoices.view'],
    'edit' => ['GET', '/purchasing/invoices/{id}/edit', 'purchase_invoices.update'],
    'update' => ['PUT', '/purchasing/invoices/{id}', 'purchase_invoices.update'],
    'post' => ['POST', '/purchasing/invoices/{id}/post', 'purchase_invoices.post'],
    'void' => ['POST', '/purchasing/invoices/{id}/void', 'purchase_invoices.void'],
]);

test('the seeded roles get what the role table says', function (string $role, bool $view, bool $write): void {
    $invoice = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt], '1.0000');
    $user = userWithRole($role);

    $this->flushSession();
    $this->actingAs($user)->get('/purchasing/invoices')->assertStatus($view ? 200 : 403);
    $this->actingAs($user)->get("/purchasing/invoices/{$invoice->id}")->assertStatus($view ? 200 : 403);

    expect($this->actingAs($user)->post('/purchasing/invoices', $this->payload)->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/purchasing/invoices/{$invoice->id}/post")->status() === 403)->toBe(! $write);
    expect($this->actingAs($user)->post("/purchasing/invoices/{$invoice->id}/void", ['reason' => 'x'])->status() === 403)->toBe(! $write);
})->with([
    'finance' => ['finance', true, true],
    'admin' => ['admin', true, false],
    'purchasing' => ['purchasing', true, false],
    'viewer' => ['viewer', true, false],
    'sales' => ['sales', false, false],
    'warehouse' => ['warehouse', false, false],
]);

test('all invoice routes carry auth, one can and the write limiter', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'purchasing/invoices'));

    expect($routes->count())->toBe(9);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')
            ->and(collect($middleware)->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:purchase_invoices.'))->count())->toBe(1);

        if (! in_array('GET', $route->methods(), true)) {
            expect($middleware)->toContain('throttle:erp-write');
        }
    }
});

test('the write limiter answers 429 on the invoice routes', function (): void {
    $this->flushSession();
    $this->actingAs(userWithPermissions(['purchase_invoices.create']));

    $statuses = [];
    foreach (range(1, 80) as $i) {
        $statuses[] = $this->post('/purchasing/invoices', [])->status();
    }

    expect($statuses)->toContain(429);
});

test('journals of an invoice link back to it', function (): void {
    $invoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt]));

    $this->flushSession();
    $this->actingAs(userWithRole('super_admin'))
        ->get('/accounting/journals?filter[source_type]=purchase_invoice')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.sourceLabel', 'Faktur Pembelian')
            ->where('items.data.0.sourceUrl', route('purchasing.invoices.show', $invoice->id))
        );
});
