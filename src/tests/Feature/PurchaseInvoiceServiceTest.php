<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JournalStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Journal;
use App\Models\PaymentTerm;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Support\Decimal;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->service = new PurchaseInvoiceService;
});

/**
 * @return list<array{string, string, string}>
 */
function purchaseJournalLines(Journal $journal): array
{
    return $journal->lines()->with('account')->orderBy('line_no')->get()->map(
        fn ($l): array => [$l->account->code, $l->debit, $l->credit]
    )->all();
}

/**
 * @return array{field: string|null, code: int, message: string}
 */
function purchaseRuleFailure(callable $action): array
{
    try {
        $action();
    } catch (BusinessRuleException $e) {
        return ['field' => $e->field, 'code' => $e->getCode(), 'message' => $e->getMessage()];
    }

    test()->fail('Expected a BusinessRuleException.');
}

/**
 * Credit minus debit of an account across every posted journal.
 */
function creditBalanceOf(string $code): string
{
    $row = DB::table('journal_lines as l')
        ->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('a.code', $code)
        ->where('j.status', 'posted')
        ->selectRaw('COALESCE(SUM(l.credit), 0) as credits, COALESCE(SUM(l.debit), 0) as debits')
        ->first();

    return Decimal::sub((string) $row->credits, (string) $row->debits);
}

test('a full receipt becomes an invoice with exact amounts and a balanced JT-PI journal', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $po = PurchaseInvoiceFixtures::approvedOrder([
        ['quantity' => '10.0000', 'unit_price' => '12500.5000', 'discount_amount' => '1000.00', 'tax_rate_id' => $tax],
        ['quantity' => '4.0000', 'unit_price' => '2500.0000'],
    ]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['10.0000', '4.0000']);

    $invoice = PurchaseInvoiceFixtures::draftInvoice($po, [$gr], null, ['supplier_invoice_number' => ' INV-777 ']);

    expect($invoice->status)->toBe(PurchaseInvoiceStatus::DRAFT)
        ->and($invoice->number)->toMatch('#^PI/202611/\d{5}$#')
        ->and($invoice->supplier_invoice_number)->toBe('INV-777')
        ->and($invoice->supplier_id)->toBe($po->supplier_id)
        ->and($invoice->purchase_order_id)->toBe($po->id)
        ->and($invoice->subtotal)->toBe('135005.00')
        ->and($invoice->discount_amount)->toBe('1000.00')
        ->and($invoice->tax_amount)->toBe('13640.55')
        ->and($invoice->total)->toBe('147645.55')
        ->and($invoice->paid_amount)->toBe('0.00')
        ->and($invoice->journal_id)->toBeNull();

    $first = $invoice->items[0];

    expect($invoice->items)->toHaveCount(2)
        ->and($first->goods_receipt_item_id)->toBe($gr->items[0]->id)
        ->and($first->product_id)->toBe($po->items[0]->product_id)
        ->and($first->description)->toBe('Product Test')
        ->and($first->quantity)->toBe('10.0000')
        ->and($first->unit_price)->toBe('12500.5000')
        ->and($first->discount_amount)->toBe('1000.00')
        ->and($first->tax_rate_id)->toBe($tax)
        ->and($first->tax_amount)->toBe('13640.55')
        ->and($first->line_total)->toBe('137645.55');

    $posted = $this->service->post($invoice);

    expect($posted->status)->toBe(PurchaseInvoiceStatus::POSTED)
        ->and($posted->posted_by)->toBe(Auth::id())
        ->and($posted->posted_at)->not->toBeNull()
        ->and($posted->journal_id)->not->toBeNull();

    $journal = Journal::findOrFail($posted->journal_id);

    expect($journal->status)->toBe(JournalStatus::POSTED)
        ->and($journal->source_type)->toBe('purchase_invoice')
        ->and($journal->source_id)->toBe($posted->id)
        ->and($journal->journal_date->toDateString())->toBe('2026-11-03')
        ->and($journal->description)->toContain($posted->number)
        ->and($journal->total_debit)->toBe('147645.55')
        ->and($journal->total_credit)->toBe('147645.55')
        ->and(purchaseJournalLines($journal))->toBe([
            ['2103', '134005.00', '0.00'],
            ['1401', '13640.55', '0.00'],
            ['2101', '0.00', '147645.55'],
        ]);

    expect(creditBalanceOf('2103'))->toBe('0.00');
});

test('partial receipts billed partly in two invoices leave goods received not invoiced at exactly zero', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $po = PurchaseInvoiceFixtures::approvedOrder([
        ['quantity' => '10.0000', 'unit_price' => '33.3333', 'discount_amount' => '1.00', 'tax_rate_id' => $tax],
        ['quantity' => '7.0000', 'unit_price' => '1000.0000', 'discount_amount' => '10.00'],
    ]);
    $firstGr = PurchaseInvoiceFixtures::postedReceipt($po, ['4.0000', '3.0000']);
    $secondGr = PurchaseInvoiceFixtures::postedReceipt($po->fresh(['items']), ['6.0000', '4.0000']);

    expect(creditBalanceOf('2103'))->not->toBe('0.00');

    $this->service->post($this->service->create([
        'purchase_order_id' => $po->id,
        'supplier_invoice_number' => 'A-1',
        'invoice_date' => '2026-11-03',
        'items' => [
            ['goods_receipt_item_id' => $firstGr->items[0]->id, 'quantity' => '1.0000'],
            ['goods_receipt_item_id' => $firstGr->items[1]->id, 'quantity' => '2.0000'],
            ['goods_receipt_item_id' => $secondGr->items[0]->id, 'quantity' => '5.0000'],
        ],
    ]));

    expect(creditBalanceOf('2103'))->not->toBe('0.00');

    $last = $this->service->post($this->service->create([
        'purchase_order_id' => $po->id,
        'supplier_invoice_number' => 'A-2',
        'invoice_date' => '2026-11-03',
        'items' => [
            ['goods_receipt_item_id' => $firstGr->items[0]->id, 'quantity' => '3.0000'],
            ['goods_receipt_item_id' => $firstGr->items[1]->id, 'quantity' => '1.0000'],
            ['goods_receipt_item_id' => $secondGr->items[0]->id, 'quantity' => '1.0000'],
            ['goods_receipt_item_id' => $secondGr->items[1]->id, 'quantity' => '4.0000'],
        ],
    ]));

    expect($last->status)->toBe(PurchaseInvoiceStatus::POSTED)
        ->and(creditBalanceOf('2103'))->toBe('0.00')
        ->and(DB::table('purchase_invoices')->where('purchase_order_id', $po->id)->sum('discount_amount'))->toEqual('11.00');

    foreach (Journal::where('source_type', 'purchase_invoice')->get() as $journal) {
        expect($journal->total_debit)->toBe($journal->total_credit);
    }
});

test('the large order that broke the original tolerance posts, with the difference credited to 5201', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3000.0000', 'unit_price' => '100.0000', 'discount_amount' => '100.00']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3000.0000']);

    $posted = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr]));

    expect(purchaseJournalLines(Journal::findOrFail($posted->journal_id)))->toBe([
        ['2103', '299900.10', '0.00'],
        ['2101', '0.00', '299900.00'],
        ['5201', '0.00', '0.10'],
    ])->and(creditBalanceOf('2103'))->toBe('0.00');
});

test('a difference the other way is debited to 5201', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '1500.0000', 'unit_price' => '12500.0000', 'discount_amount' => '1000.00']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['1500.0000']);

    $posted = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr]));

    expect(purchaseJournalLines(Journal::findOrFail($posted->journal_id)))->toBe([
        ['2103', '18748999.95', '0.00'],
        ['5201', '0.05', '0.00'],
        ['2101', '0.00', '18749000.00'],
    ])->and(creditBalanceOf('2103'))->toBe('0.00');
});

test('a receipt cost that no longer matches the order is refused when posting', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    DB::table('goods_receipt_items')->where('id', $gr->items[0]->id)->update(['unit_cost' => '90.0000']);

    $failure = purchaseRuleFailure(fn () => $this->service->post($draft));

    expect($failure['field'])->toBe('items')
        ->and($failure['message'])->toContain('selisih')
        ->and($draft->fresh()->status)->toBe(PurchaseInvoiceStatus::DRAFT);
});

test('the supplier invoice number must be present, short enough and unique per supplier', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['2.0000']);

    PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000', ['supplier_invoice_number' => 'INV-1']);

    foreach (['INV-1', ' INV-1 ', '   ', str_repeat('a', 51)] as $number) {
        expect(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000', ['supplier_invoice_number' => $number]))['field'])
            ->toBe('supplier_invoice_number');
    }

    $otherPo = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $otherGr = PurchaseInvoiceFixtures::postedReceipt($otherPo, ['2.0000']);

    expect(PurchaseInvoiceFixtures::draftInvoice($otherPo, [$otherGr], null, ['supplier_invoice_number' => 'INV-1'])->supplier_invoice_number)
        ->toBe('INV-1');
});

test('updating a draft may keep its own supplier invoice number but not take another', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['2.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000', ['supplier_invoice_number' => 'INV-1']);
    PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000', ['supplier_invoice_number' => 'INV-2']);

    $items = [['goods_receipt_item_id' => $gr->items[0]->id, 'quantity' => '1.0000']];

    expect($this->service->update($draft, ['supplier_invoice_number' => 'INV-1', 'invoice_date' => '2026-11-03', 'items' => $items])->supplier_invoice_number)
        ->toBe('INV-1')
        ->and(purchaseRuleFailure(fn () => $this->service->update($draft, ['supplier_invoice_number' => 'INV-2', 'invoice_date' => '2026-11-03', 'items' => $items]))['field'])
        ->toBe('supplier_invoice_number');
});

test('billing more than the remainder is refused on create, update and post', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);

    $tooMuch = purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '5.0001'));

    expect($tooMuch['field'])->toBe('items')
        ->and($tooMuch['code'])->toBe(422)
        ->and($tooMuch['message'])->toContain('melebihi sisa');

    $first = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);
    $second = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    expect(purchaseRuleFailure(fn () => $this->service->update($first, [
        'supplier_invoice_number' => $first->supplier_invoice_number,
        'invoice_date' => '2026-11-03',
        'items' => [['goods_receipt_item_id' => $gr->items[0]->id, 'quantity' => '6.0000']],
    ]))['field'])->toBe('items');

    $this->service->post($first);

    $failure = purchaseRuleFailure(fn () => $this->service->post($second));

    expect($failure['field'])->toBe('items')
        ->and($second->fresh()->status)->toBe(PurchaseInvoiceStatus::DRAFT)
        ->and($second->fresh()->journal_id)->toBeNull();
});

test('only posted receipts can be billed and listed as eligible', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '6.0000', 'unit_price' => '100.0000']]);
    $draftGr = PurchaseInvoiceFixtures::draftReceipt($po, ['1.0000']);
    $voidedGr = PurchaseInvoiceFixtures::postedReceipt($po->fresh(['items']), ['1.0000']);
    (new GoodsReceiptService(new StockService))->void($voidedGr, 'Salah terima');
    $postedGr = PurchaseInvoiceFixtures::postedReceipt($po->fresh(['items']), ['2.0000']);

    expect(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$draftGr]))['field'])->toBe('items')
        ->and(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$voidedGr]))['field'])->toBe('items');

    $eligible = $this->service->eligibleItems($po);

    expect($eligible)->toHaveCount(1)
        ->and($eligible[0]['goods_receipt_item_id'])->toBe($postedGr->items[0]->id)
        ->and($eligible[0]['goods_receipt_number'])->toBe($postedGr->number)
        ->and($eligible[0]['remaining_quantity'])->toBe('2.0000');

    $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$postedGr]));

    expect($this->service->eligibleItems($po))->toBe([]);
});

test('eligible items carry the amounts for the full remainder', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('11.00');
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00', 'tax_rate_id' => $tax]]);
    PurchaseInvoiceFixtures::postedReceipt($po, ['1.0000']);

    $line = $this->service->eligibleItems($po)[0];

    expect($line['quantity'])->toBe('1.0000')
        ->and($line['unit_price'])->toBe('1000.0000')
        ->and($line['discount_amount'])->toBe('33.33')
        ->and($line['tax_amount'])->toBe('106.33')
        ->and($line['line_total'])->toBe('1073.00');
});

test('a receipt line of another order, a duplicate, a zero quantity and an empty invoice are refused', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);
    $otherPo = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $otherGr = PurchaseInvoiceFixtures::postedReceipt($otherPo, ['5.0000']);
    $itemId = $gr->items[0]->id;

    $attempt = fn (array $items): array => purchaseRuleFailure(fn () => $this->service->create([
        'purchase_order_id' => $po->id,
        'supplier_invoice_number' => 'INV-X',
        'invoice_date' => '2026-11-03',
        'items' => $items,
    ]));

    expect($attempt([['goods_receipt_item_id' => $otherGr->items[0]->id, 'quantity' => '1.0000']])['field'])->toBe('items')
        ->and($attempt([
            ['goods_receipt_item_id' => $itemId, 'quantity' => '1.0000'],
            ['goods_receipt_item_id' => $itemId, 'quantity' => '1.0000'],
        ])['field'])->toBe('items')
        ->and($attempt([['goods_receipt_item_id' => $itemId, 'quantity' => '0.0000']])['field'])->toBe('items')
        ->and($attempt([])['field'])->toBe('items');
});

test('a draft or voided order cannot be billed while approved through closed orders can', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);

    foreach ([PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::VOID] as $status) {
        DB::table('purchase_orders')->where('id', $po->id)->update(['status' => $status->value]);
        expect(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr]))['field'])->toBe('purchase_order_id');
    }

    foreach ([PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::PARTIALLY_RECEIVED, PurchaseOrderStatus::RECEIVED, PurchaseOrderStatus::CLOSED] as $status) {
        DB::table('purchase_orders')->where('id', $po->id)->update(['status' => $status->value]);
        expect(PurchaseInvoiceFixtures::draftInvoice($po, [$gr])->status)->toBe(PurchaseInvoiceStatus::DRAFT);
    }
});

test('an inactive supplier blocks create and post, with an active supplier as the control', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000');

    DB::table('suppliers')->where('id', $po->supplier_id)->update(['is_active' => false]);

    expect(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'))['field'])->toBe('supplier_id')
        ->and(purchaseRuleFailure(fn () => $this->service->post($draft))['field'])->toBe('supplier_id');

    DB::table('suppliers')->where('id', $po->supplier_id)->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(PurchaseInvoiceStatus::POSTED);
});

test('a posting is refused when a source receipt is no longer posted', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    DB::table('goods_receipts')->where('id', $gr->id)->update(['status' => 'void']);

    expect(purchaseRuleFailure(fn () => $this->service->post($draft))['field'])->toBe('items');
});

test('illegal transitions are refused', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);
    $posted = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'));
    $voided = $this->service->void(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'), 'Tidak jadi');

    expect(fn () => $this->service->post($posted))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->post($voided))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->void($voided, 'Lagi'))->toThrow(InvalidStateTransitionException::class);

    $update = [
        'supplier_invoice_number' => 'INV-Y',
        'invoice_date' => '2026-11-03',
        'items' => [['goods_receipt_item_id' => $gr->items[0]->id, 'quantity' => '1.0000']],
    ];

    expect(fn () => $this->service->update($posted, $update))->toThrow(DocumentNotEditableException::class);
    expect(fn () => $this->service->update($voided, $update))->toThrow(DocumentNotEditableException::class);
    expect(Journal::where('source_type', 'purchase_invoice')->where('source_id', $posted->id)->count())->toBe(1);
});

test('voiding a draft needs a reason of 1 to 255 characters and creates no journal', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    expect(purchaseRuleFailure(fn () => $this->service->void($draft, '   '))['field'])->toBe('reason')
        ->and(purchaseRuleFailure(fn () => $this->service->void($draft, str_repeat('a', 256)))['field'])->toBe('reason');

    $voided = $this->service->void($draft, '  x  ');

    expect($voided->status)->toBe(PurchaseInvoiceStatus::VOID)
        ->and($voided->void_reason)->toBe('x')
        ->and($voided->voided_by)->toBe(Auth::id())
        ->and(Journal::where('source_type', 'purchase_invoice')->where('source_id', $voided->id)->exists())->toBeFalse();
});

test('voiding a posted invoice without payments neutralises the journal and restores goods not invoiced', function (): void {
    $tax = SalesInvoiceFixtures::taxRate('12.00');
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3000.0000', 'unit_price' => '100.0000', 'discount_amount' => '100.00', 'tax_rate_id' => $tax]]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3000.0000']);
    $before = creditBalanceOf('2103');

    $posted = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr]));
    $voided = $this->service->void($posted, 'Salah harga');

    expect($voided->status)->toBe(PurchaseInvoiceStatus::VOID)
        ->and(creditBalanceOf('2103'))->toBe($before);

    $journals = Journal::where('source_type', 'purchase_invoice')->where('source_id', $posted->id)->orderBy('id')->get();

    expect($journals)->toHaveCount(2)
        ->and($journals[1]->reversal_of_id)->toBe($journals[0]->id)
        ->and($journals[1]->journal_date->toDateString())->toBe(now()->toDateString());

    $net = [];
    foreach ($journals as $journal) {
        foreach (purchaseJournalLines($journal) as [$code, $debit, $credit]) {
            $net[$code] = Decimal::sub(Decimal::add($net[$code] ?? '0', $debit), $credit);
        }
    }

    foreach ($net as $balance) {
        expect($balance)->toBe('0.00');
    }

    $replacement = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr]));

    expect($replacement->status)->toBe(PurchaseInvoiceStatus::POSTED)
        ->and(creditBalanceOf('2103'))->toBe('0.00');
});

test('voiding an invoice with money paid is refused until the payment is cancelled', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3.0000']);
    $posted = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr]));

    foreach ([['100.00', 'partially_paid'], ['300.00', 'paid'], ['100.00', 'posted']] as [$paid, $status]) {
        DB::table('purchase_invoices')->where('id', $posted->id)->update(['paid_amount' => $paid, 'status' => $status]);

        $failure = purchaseRuleFailure(fn () => $this->service->void($posted->fresh(), 'Salah'));

        expect($failure['field'])->toBe('status')
            ->and($failure['code'])->toBe(409)
            ->and($failure['message'])->toContain('batalkan pembayaran terlebih dahulu');
    }

    DB::table('purchase_invoices')->where('id', $posted->id)->update(['paid_amount' => '0.00', 'status' => 'posted']);

    expect($this->service->void($posted->fresh(), 'Salah')->status)->toBe(PurchaseInvoiceStatus::VOID);
});

test('an earlier billing cannot be voided while a later invoice completes the same receipt line', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '66.6667']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3.0000']);

    $first = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'));
    $second = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '2.0000'));

    expect(creditBalanceOf('2103'))->toBe('0.00');

    $failure = purchaseRuleFailure(fn () => $this->service->void($first, 'Salah'));

    expect($failure['code'])->toBe(409)
        ->and($failure['field'])->toBe('status')
        ->and($failure['message'])->toContain($second->number);

    $this->service->void($second, 'Salah');
    $this->service->void($first, 'Salah');

    $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '2.0000'));
    $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'));

    expect(creditBalanceOf('2103'))->toBe('0.00');
});

test('an unfinished earlier billing can be voided in any order', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['5.0000']);

    $first = $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000'));
    $this->service->post(PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '2.0000'));

    expect($this->service->void($first, 'Salah')->status)->toBe(PurchaseInvoiceStatus::VOID);
});

test('a journal failure rolls the whole posting back', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    DB::table('chart_of_accounts')->where('code', '2101')->update(['is_active' => false]);

    expect(fn () => $this->service->post($draft))->toThrow(BusinessRuleException::class);

    expect($draft->fresh()->status)->toBe(PurchaseInvoiceStatus::DRAFT)
        ->and($draft->fresh()->journal_id)->toBeNull();

    DB::table('chart_of_accounts')->where('code', '2101')->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(PurchaseInvoiceStatus::POSTED);
});

test('the due date comes from the order term, then the supplier term, then zero days', function (): void {
    $term = fn (int $days): int => (int) PaymentTerm::create([
        'code' => 'TOP'.bin2hex(random_bytes(4)),
        'name' => "Tempo {$days}",
        'days' => $days,
    ])->id;
    $line = [['quantity' => '1.0000', 'unit_price' => '100.0000']];
    $invoiceFor = function (array $supplier, array $order) use ($line): PurchaseInvoice {
        $po = PurchaseInvoiceFixtures::approvedOrder($line, $supplier, $order);
        $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['1.0000']);

        return PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);
    };

    expect($invoiceFor(['payment_term_id' => $term(45)], ['payment_term_id' => $term(30)])->due_date->toDateString())->toBe('2026-12-03')
        ->and($invoiceFor(['payment_term_id' => $term(45)], [])->due_date->toDateString())->toBe('2026-12-18')
        ->and($invoiceFor([], [])->due_date->toDateString())->toBe('2026-11-03');
});

test('dates are validated: no future invoice date and no due date before it', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['2.0000']);

    expect(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr], null, ['invoice_date' => now()->addDay()->toDateString()]))['field'])->toBe('invoice_date')
        ->and(purchaseRuleFailure(fn () => PurchaseInvoiceFixtures::draftInvoice($po, [$gr], null, ['due_date' => '2026-11-02']))['field'])->toBe('due_date');
});

test('updating a draft replaces its lines and recalculates', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000', 'discount_amount' => '100.00']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['3.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

    $updated = $this->service->update($draft, [
        'supplier_invoice_number' => 'BARU-1',
        'invoice_date' => '2026-11-04',
        'due_date' => '2026-11-20',
        'notes' => 'Tagih sebagian',
        'items' => [['goods_receipt_item_id' => $gr->items[0]->id, 'quantity' => '1.0000']],
    ]);

    expect($updated->items)->toHaveCount(1)
        ->and($updated->subtotal)->toBe('1000.00')
        ->and($updated->discount_amount)->toBe('33.33')
        ->and($updated->total)->toBe('966.67')
        ->and($updated->supplier_invoice_number)->toBe('BARU-1')
        ->and($updated->due_date->toDateString())->toBe('2026-11-20')
        ->and($updated->number)->toBe($draft->number);
});

test('every change is audited and a failed audit write rolls the invoice back', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['2.0000']);
    $invoice = PurchaseInvoiceFixtures::draftInvoice($po, [$gr], '1.0000');
    $this->service->post($invoice);

    expect(DB::table('audit_logs')->where('auditable_type', PurchaseInvoice::class)->where('auditable_id', $invoice->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['purchase_invoice.created', 'purchase_invoice.items_saved', 'purchase_invoice.updated']);

    $validUser = (int) Auth::id();
    $missingUser = new User;
    $missingUser->id = 99999999;
    Auth::setUser($missingUser);
    $count = PurchaseInvoice::count();

    expect(fn () => $this->service->create([
        'purchase_order_id' => $po->id,
        'supplier_invoice_number' => 'AUDIT-1',
        'invoice_date' => '2026-11-03',
        'items' => [['goods_receipt_item_id' => $gr->items[0]->id, 'quantity' => '1.0000']],
    ], $validUser))->toThrow(QueryException::class);

    expect(PurchaseInvoice::count())->toBe($count);
});

test('posting follows the global lock order', function (): void {
    $po = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '100.0000']]);
    $gr = PurchaseInvoiceFixtures::postedReceipt($po, ['2.0000']);
    $draft = PurchaseInvoiceFixtures::draftInvoice($po, [$gr]);

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

    $order = $position('from `purchase_orders`', 'for update');
    $receipt = $position('from `goods_receipts`', 'for update');
    $orderItems = $position('from `purchase_order_items`', 'for update');
    $receiptItems = $position('from `goods_receipt_items`', 'for update');
    $invoice = $position('from `purchase_invoices`', 'for update');
    $journal = $position('insert into `journals`', 'insert');

    expect($order)->toBeGreaterThanOrEqual(0)
        ->and($receipt)->toBeGreaterThan($order)
        ->and($orderItems)->toBeGreaterThan($receipt)
        ->and($receiptItems)->toBeGreaterThan($orderItems)
        ->and($invoice)->toBeGreaterThan($receiptItems)
        ->and($journal)->toBeGreaterThan($invoice);
});
