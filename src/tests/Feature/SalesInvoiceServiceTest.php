<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JournalStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Journal;
use App\Models\PaymentTerm;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use App\Support\Decimal;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->service = new SalesInvoiceService;
});

/**
 * @return list<array{string, string, string}>
 */
function invoiceJournalLines(Journal $journal): array
{
    return $journal->lines()->with('account')->orderBy('line_no')->get()->map(
        fn ($l): array => [$l->account->code, $l->debit, $l->credit]
    )->all();
}

/**
 * @return array{field: string|null, code: int, message: string}
 */
function ruleFailure(callable $action): array
{
    try {
        $action();
    } catch (BusinessRuleException $e) {
        return ['field' => $e->field, 'code' => $e->getCode(), 'message' => $e->getMessage()];
    }

    test()->fail('Expected a BusinessRuleException.');
}

test('a full delivery becomes an invoice with exact amounts and a balanced JT-SI journal', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $so = SalesInvoiceFixtures::approvedOrder([
        ['quantity' => '10.0000', 'unit_price' => '12500.5000', 'discount_amount' => '1000.00', 'tax_rate_id' => $tax],
        ['quantity' => '4.0000', 'unit_price' => '2500.0000'],
    ]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['10.0000', '4.0000']);

    $invoice = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    expect($invoice->status)->toBe(SalesInvoiceStatus::DRAFT)
        ->and($invoice->number)->toMatch('#^SI/202611/\d{5}$#')
        ->and($invoice->customer_id)->toBe($so->customer_id)
        ->and($invoice->sales_order_id)->toBe($so->id)
        ->and($invoice->subtotal)->toBe('135005.00')
        ->and($invoice->discount_amount)->toBe('1000.00')
        ->and($invoice->tax_amount)->toBe('13640.55')
        ->and($invoice->total)->toBe('147645.55')
        ->and($invoice->paid_amount)->toBe('0.00')
        ->and($invoice->journal_id)->toBeNull();

    $first = $invoice->items[0];

    expect($invoice->items)->toHaveCount(2)
        ->and($first->line_no)->toBe(1)
        ->and($first->delivery_order_item_id)->toBe($do->items[0]->id)
        ->and($first->product_id)->toBe($so->items[0]->product_id)
        ->and($first->description)->toBe('Product Test')
        ->and($first->quantity)->toBe('10.0000')
        ->and($first->unit_price)->toBe('12500.5000')
        ->and($first->discount_amount)->toBe('1000.00')
        ->and($first->tax_rate_id)->toBe($tax)
        ->and($first->tax_amount)->toBe('13640.55')
        ->and($first->line_total)->toBe('137645.55');

    $posted = $this->service->post($invoice);

    expect($posted->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and($posted->posted_by)->toBe(Auth::id())
        ->and($posted->posted_at)->not->toBeNull()
        ->and($posted->journal_id)->not->toBeNull();

    $journal = Journal::findOrFail($posted->journal_id);

    expect($journal->status)->toBe(JournalStatus::POSTED)
        ->and($journal->source_type)->toBe('sales_invoice')
        ->and($journal->source_id)->toBe($posted->id)
        ->and($journal->journal_date->toDateString())->toBe('2026-11-03')
        ->and($journal->description)->toContain($posted->number)
        ->and($journal->total_debit)->toBe('148645.55')
        ->and($journal->total_credit)->toBe('148645.55')
        ->and(invoiceJournalLines($journal))->toBe([
            ['1201', '147645.55', '0.00'],
            ['4201', '1000.00', '0.00'],
            ['4101', '0.00', '135005.00'],
            ['2201', '0.00', '13640.55'],
        ]);
});

test('zero discount and zero tax produce no journal lines for them', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);

    $posted = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do]));

    expect(invoiceJournalLines(Journal::findOrFail($posted->journal_id)))->toBe([
        ['1201', '2000.00', '0.00'],
        ['4101', '0.00', '2000.00'],
    ]);
});

test('one invoice can bill two deliveries of the same order, with the discount spread over them', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00']]);
    $first = SalesInvoiceFixtures::postedDelivery($so, ['1.0000']);
    $second = SalesInvoiceFixtures::postedDelivery($so->fresh(['items']), ['2.0000']);

    $invoice = SalesInvoiceFixtures::draftInvoice($so, [$first, $second]);

    expect($invoice->items)->toHaveCount(2)
        ->and($invoice->items[0]->discount_amount)->toBe('33.33')
        ->and($invoice->items[1]->discount_amount)->toBe('66.67')
        ->and($invoice->discount_amount)->toBe('100.00')
        ->and($invoice->subtotal)->toBe('3000.00')
        ->and($invoice->total)->toBe('2900.00');
});

test('posting recalculates under lock, so three drafts of one line add up to the order discount', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);

    $drafts = [
        SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'),
        SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'),
        SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'),
    ];

    expect($drafts[2]->discount_amount)->toBe('33.33');

    $posted = array_map(fn (SalesInvoice $draft): SalesInvoice => $this->service->post($draft), $drafts);

    expect($posted[0]->discount_amount)->toBe('33.33')
        ->and($posted[1]->discount_amount)->toBe('33.33')
        ->and($posted[2]->discount_amount)->toBe('33.34')
        ->and($posted[2]->items[0]->discount_amount)->toBe('33.34')
        ->and($posted[2]->total)->toBe('966.66');

    expect(DB::table('sales_invoices')->where('sales_order_id', $so->id)->sum('discount_amount'))->toEqual('100.00');
});

test('voiding a billed invoice frees its quantity and the residue is worked out again', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);

    $first = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'));
    $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do], '2.0000'));

    expect(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'))['field'])->toBe('items');

    $this->service->void($first, 'Salah tagih');

    $replacement = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'));

    expect($replacement->discount_amount)->toBe('33.33');
});

test('billing more than the remainder is refused on create, update and post', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);

    $tooMuch = ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], '5.0001'));

    expect($tooMuch['field'])->toBe('items')
        ->and($tooMuch['code'])->toBe(422)
        ->and($tooMuch['message'])->toContain('melebihi sisa');

    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do], '5.0000');

    expect(ruleFailure(fn () => $this->service->update($draft, [
        'invoice_date' => '2026-11-03',
        'items' => [['delivery_order_item_id' => $do->items[0]->id, 'quantity' => '6.0000']],
    ]))['field'])->toBe('items');

    $this->service->post($draft);

    $positive = ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], '0.0001'));
    expect($positive['message'])->toContain('melebihi sisa');
});

test('two drafts competing for the same remainder: the second posting fails and changes nothing', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);

    $first = SalesInvoiceFixtures::draftInvoice($so, [$do]);
    $second = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    $this->service->post($first);

    $failure = ruleFailure(fn () => $this->service->post($second));

    expect($failure['field'])->toBe('items')
        ->and($failure['code'])->toBe(422);

    $reloaded = $second->fresh();

    expect($reloaded->status)->toBe(SalesInvoiceStatus::DRAFT)
        ->and($reloaded->posted_at)->toBeNull()
        ->and($reloaded->journal_id)->toBeNull()
        ->and(Journal::where('source_type', 'sales_invoice')->where('source_id', $second->id)->exists())->toBeFalse();
});

test('only posted deliveries can be billed and listed as eligible', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '6.0000', 'unit_price' => '1000.0000']]);
    $draft = SalesInvoiceFixtures::draftDelivery($so, ['1.0000']);
    $voided = SalesInvoiceFixtures::postedDelivery($so, ['1.0000']);
    (new DeliveryOrderService(new StockService))->void($voided, 'Salah kirim');
    $posted = SalesInvoiceFixtures::postedDelivery($so->fresh(['items']), ['2.0000']);

    expect(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$draft]))['field'])->toBe('items')
        ->and(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$voided]))['field'])->toBe('items');

    $eligible = $this->service->eligibleItems($so);

    expect($eligible)->toHaveCount(1)
        ->and($eligible[0]['delivery_order_item_id'])->toBe($posted->items[0]->id)
        ->and($eligible[0]['delivery_order_number'])->toBe($posted->number)
        ->and($eligible[0]['remaining_quantity'])->toBe('2.0000');

    SalesInvoiceFixtures::draftInvoice($so, [$posted], '2.0000');

    expect($this->service->eligibleItems($so))->toHaveCount(1);

    $this->service->post(SalesInvoice::where('sales_order_id', $so->id)->firstOrFail());

    expect($this->service->eligibleItems($so))->toBe([]);
});

test('eligible items carry the amounts the server would charge for the full remainder', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00', 'tax_rate_id' => $tax]]);
    SalesInvoiceFixtures::postedDelivery($so, ['1.0000']);

    $line = $this->service->eligibleItems($so)[0];

    expect($line['quantity'])->toBe('1.0000')
        ->and($line['unit_price'])->toBe('1000.0000')
        ->and($line['discount_amount'])->toBe('33.33')
        ->and($line['tax_amount'])->toBe('106.33')
        ->and($line['line_total'])->toBe('1073.00');
});

test('a delivery line of another order, a duplicate line, a zero quantity and an empty invoice are refused', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);
    $otherSo = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $otherDo = SalesInvoiceFixtures::postedDelivery($otherSo, ['5.0000']);
    $itemId = $do->items[0]->id;

    $attempt = fn (array $items): array => ruleFailure(fn () => $this->service->create([
        'sales_order_id' => $so->id,
        'invoice_date' => '2026-11-03',
        'items' => $items,
    ]));

    expect($attempt([['delivery_order_item_id' => $otherDo->items[0]->id, 'quantity' => '1.0000']])['field'])->toBe('items')
        ->and($attempt([
            ['delivery_order_item_id' => $itemId, 'quantity' => '1.0000'],
            ['delivery_order_item_id' => $itemId, 'quantity' => '1.0000'],
        ])['field'])->toBe('items')
        ->and($attempt([['delivery_order_item_id' => $itemId, 'quantity' => '0.0000']])['field'])->toBe('items')
        ->and($attempt([])['field'])->toBe('items');

    expect(SalesInvoice::where('sales_order_id', $so->id)->exists())->toBeFalse();
});

test('a draft or voided order cannot be billed while approved through closed orders can', function (): void {
    $orderService = new SalesOrderService;
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);

    foreach ([SalesOrderStatus::DRAFT, SalesOrderStatus::VOID] as $status) {
        DB::table('sales_orders')->where('id', $so->id)->update(['status' => $status->value]);
        expect(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do]))['field'])->toBe('sales_order_id');
    }

    DB::table('sales_orders')->where('id', $so->id)->update(['status' => SalesOrderStatus::DELIVERED->value]);
    $orderService->close($so->fresh());

    expect($so->fresh()->status)->toBe(SalesOrderStatus::CLOSED)
        ->and(SalesInvoiceFixtures::draftInvoice($so, [$do])->status)->toBe(SalesInvoiceStatus::DRAFT);
});

test('an inactive customer blocks create and post, with an active customer as the control', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);

    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000');

    DB::table('customers')->where('id', $so->customer_id)->update(['is_active' => false]);

    expect(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'))['field'])->toBe('customer_id')
        ->and(ruleFailure(fn () => $this->service->post($draft))['field'])->toBe('customer_id')
        ->and($draft->fresh()->status)->toBe(SalesInvoiceStatus::DRAFT);

    DB::table('customers')->where('id', $so->customer_id)->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(SalesInvoiceStatus::POSTED);
});

test('a posting is refused when a source delivery is no longer posted', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    DB::table('delivery_orders')->where('id', $do->id)->update(['status' => 'void']);

    expect(ruleFailure(fn () => $this->service->post($draft))['field'])->toBe('items');
});

test('illegal transitions are refused', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);
    $posted = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'));
    $voided = $this->service->void(SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000'), 'Tidak jadi');

    expect(fn () => $this->service->post($posted))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->post($voided))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->void($voided, 'Lagi'))->toThrow(InvalidStateTransitionException::class);

    $update = [
        'invoice_date' => '2026-11-03',
        'items' => [['delivery_order_item_id' => $do->items[0]->id, 'quantity' => '1.0000']],
    ];

    expect(fn () => $this->service->update($posted, $update))->toThrow(DocumentNotEditableException::class);
    expect(fn () => $this->service->update($voided, $update))->toThrow(DocumentNotEditableException::class);
});

test('voiding a draft needs a reason of 1 to 255 characters and creates no journal', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['5.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    expect(ruleFailure(fn () => $this->service->void($draft, '   '))['field'])->toBe('reason')
        ->and(ruleFailure(fn () => $this->service->void($draft, str_repeat('a', 256)))['field'])->toBe('reason');

    $voided = $this->service->void($draft, '  x  ');

    expect($voided->status)->toBe(SalesInvoiceStatus::VOID)
        ->and($voided->void_reason)->toBe('x')
        ->and($voided->voided_by)->toBe(Auth::id())
        ->and($voided->voided_at)->not->toBeNull()
        ->and(Journal::where('source_type', 'sales_invoice')->where('source_id', $voided->id)->exists())->toBeFalse();
});

test('voiding a posted invoice without payments writes a reversal that neutralises the journal', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('12.00');
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00', 'tax_rate_id' => $tax]]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);
    $posted = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do]));

    $voided = $this->service->void($posted, 'Salah harga');

    expect($voided->status)->toBe(SalesInvoiceStatus::VOID);

    $journals = Journal::where('source_type', 'sales_invoice')->where('source_id', $posted->id)->orderBy('id')->get();

    expect($journals)->toHaveCount(2)
        ->and($journals[0]->status)->toBe(JournalStatus::POSTED)
        ->and($journals[1]->reversal_of_id)->toBe($journals[0]->id)
        ->and($journals[1]->journal_date->toDateString())->toBe(now()->toDateString());

    $net = [];
    foreach ($journals as $journal) {
        foreach (invoiceJournalLines($journal) as [$code, $debit, $credit]) {
            $net[$code] = Decimal::sub(Decimal::add($net[$code] ?? '0', $debit), $credit);
        }
    }

    expect($net)->toBe(['1201' => '0.00', '4201' => '0.00', '4101' => '0.00', '2201' => '0.00']);
});

test('voiding an invoice with money received is refused until the payment is cancelled', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);
    $posted = $this->service->post(SalesInvoiceFixtures::draftInvoice($so, [$do]));

    foreach ([['1000.00', 'partially_paid'], ['3000.00', 'paid'], ['1000.00', 'posted']] as [$paid, $status]) {
        DB::table('sales_invoices')->where('id', $posted->id)->update(['paid_amount' => $paid, 'status' => $status]);

        $failure = ruleFailure(fn () => $this->service->void($posted->fresh(), 'Salah harga'));

        expect($failure['field'])->toBe('status')
            ->and($failure['code'])->toBe(409)
            ->and($failure['message'])->toContain('batalkan pembayaran terlebih dahulu')
            ->and($posted->fresh()->voided_at)->toBeNull();
    }

    DB::table('sales_invoices')->where('id', $posted->id)->update(['paid_amount' => '0.00', 'status' => 'posted']);

    expect($this->service->void($posted->fresh(), 'Salah harga')->status)->toBe(SalesInvoiceStatus::VOID);
});

test('a journal failure rolls the whole posting back', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    DB::table('chart_of_accounts')->where('code', '1201')->update(['is_active' => false]);

    expect(fn () => $this->service->post($draft))->toThrow(BusinessRuleException::class);

    $reloaded = $draft->fresh();

    expect($reloaded->status)->toBe(SalesInvoiceStatus::DRAFT)
        ->and($reloaded->posted_at)->toBeNull()
        ->and($reloaded->journal_id)->toBeNull();

    DB::table('chart_of_accounts')->where('code', '1201')->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(SalesInvoiceStatus::POSTED);
});

test('the due date comes from the order term, then the customer term, then zero days', function (): void {
    $term = fn (int $days): int => (int) PaymentTerm::create([
        'code' => 'TOP'.bin2hex(random_bytes(4)),
        'name' => "Tempo {$days}",
        'days' => $days,
    ])->id;
    $line = [['quantity' => '1.0000', 'unit_price' => '1000.0000']];
    $invoiceFor = function (array $customer, array $order) use ($line): SalesInvoice {
        $so = SalesInvoiceFixtures::approvedOrder($line, $customer, $order);
        $do = SalesInvoiceFixtures::postedDelivery($so, ['1.0000']);

        return SalesInvoiceFixtures::draftInvoice($so, [$do]);
    };

    $fromOrder = $invoiceFor(['payment_term_id' => $term(45)], ['payment_term_id' => $term(30)]);
    $fromCustomer = $invoiceFor(['payment_term_id' => $term(45)], []);
    $none = $invoiceFor([], []);

    expect($fromOrder->due_date->toDateString())->toBe('2026-12-03')
        ->and($fromCustomer->due_date->toDateString())->toBe('2026-12-18')
        ->and($none->due_date->toDateString())->toBe('2026-11-03');
});

test('dates are validated: no future invoice date and no due date before it', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);

    expect(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], null, ['invoice_date' => now()->addDay()->toDateString()]))['field'])->toBe('invoice_date')
        ->and(ruleFailure(fn () => SalesInvoiceFixtures::draftInvoice($so, [$do], null, ['due_date' => '2026-11-02']))['field'])->toBe('due_date');

    $sameDay = SalesInvoiceFixtures::draftInvoice($so, [$do], null, ['due_date' => '2026-11-03']);

    expect($sameDay->due_date->toDateString())->toBe('2026-11-03');
});

test('updating a draft replaces its lines and recalculates', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['3.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    $updated = $this->service->update($draft, [
        'invoice_date' => '2026-11-04',
        'due_date' => '2026-11-20',
        'notes' => 'Tagih sebagian',
        'items' => [['delivery_order_item_id' => $do->items[0]->id, 'quantity' => '1.0000']],
    ]);

    expect($updated->items)->toHaveCount(1)
        ->and($updated->items[0]->quantity)->toBe('1.0000')
        ->and($updated->subtotal)->toBe('1000.00')
        ->and($updated->discount_amount)->toBe('33.33')
        ->and($updated->total)->toBe('966.67')
        ->and($updated->invoice_date->toDateString())->toBe('2026-11-04')
        ->and($updated->due_date->toDateString())->toBe('2026-11-20')
        ->and($updated->notes)->toBe('Tagih sebagian')
        ->and($updated->number)->toBe($draft->number);
});

test('each invoice gets its own number and every change is audited', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);

    $first = SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000');
    $second = SalesInvoiceFixtures::draftInvoice($so, [$do], '1.0000');

    expect($first->number)->not->toBe($second->number);

    $this->service->post($first);
    $this->service->void($second, 'Tidak jadi');

    $events = fn (int $id): array => DB::table('audit_logs')
        ->where('auditable_type', SalesInvoice::class)->where('auditable_id', $id)
        ->orderBy('id')->pluck('event')->all();

    expect($events($first->id))->toBe(['sales_invoice.created', 'sales_invoice.items_saved', 'sales_invoice.updated'])
        ->and($events($second->id))->toBe(['sales_invoice.created', 'sales_invoice.items_saved', 'sales_invoice.updated']);
});

test('a failed audit write rolls the invoice back', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);
    $validUser = (int) Auth::id();

    $missingUser = new User;
    $missingUser->id = 99999999;
    Auth::setUser($missingUser);

    expect(fn () => $this->service->create([
        'sales_order_id' => $so->id,
        'invoice_date' => '2026-11-03',
        'items' => [['delivery_order_item_id' => $do->items[0]->id, 'quantity' => '2.0000']],
    ], $validUser))->toThrow(QueryException::class);

    expect(SalesInvoice::where('sales_order_id', $so->id)->exists())->toBeFalse();
});

test('posting follows the global lock order', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->service->post($draft);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $position = function (string $table, string $needle) use ($queries): int {
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, $table) && str_contains($sql, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    $order = $position('from `sales_orders`', 'for update');
    $delivery = $position('from `delivery_orders`', 'for update');
    $orderItems = $position('from `sales_order_items`', 'for update');
    $deliveryItems = $position('from `delivery_order_items`', 'for update');
    $invoice = $position('from `sales_invoices`', 'for update');
    $journal = $position('insert into `journals`', 'insert');

    expect($order)->toBeGreaterThanOrEqual(0)
        ->and($delivery)->toBeGreaterThan($order)
        ->and($orderItems)->toBeGreaterThan($delivery)
        ->and($deliveryItems)->toBeGreaterThan($orderItems)
        ->and($invoice)->toBeGreaterThan($deliveryItems)
        ->and($journal)->toBeGreaterThan($invoice);
});

test('posting twice through a stale model never doubles the journal', function (): void {
    $so = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '1000.0000']]);
    $do = SalesInvoiceFixtures::postedDelivery($so, ['2.0000']);
    $draft = SalesInvoiceFixtures::draftInvoice($so, [$do]);

    $this->service->post($draft);

    expect(fn () => $this->service->post($draft))->toThrow(InvalidStateTransitionException::class);
    expect(Journal::where('source_type', 'sales_invoice')->where('source_id', $draft->id)->count())->toBe(1);
});
