<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JournalStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use App\Services\Sales\CreditExposureService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Decimal;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->service = new PaymentService;
    $this->customerId = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $this->cash = accountIdByCode('1101');
    $this->bank = accountIdByCode('1102');
});

function accountIdByCode(string $code): int
{
    return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
}

function postedInvoiceOf(int $customerId, string $total): SalesInvoice
{
    $order = SalesInvoiceFixtures::approvedOrder(
        [['quantity' => '1.0000', 'unit_price' => $total]],
        [],
        ['customer_id' => $customerId]
    );
    $delivery = SalesInvoiceFixtures::postedDelivery($order, ['1.0000']);

    return (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [$delivery]));
}

/**
 * @param  list<array{0: SalesInvoice, 1: string}>  $allocations
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function receiptData(int $customerId, int $accountId, string $amount, array $allocations, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customerId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => $accountId,
        'amount' => $amount,
        'reference' => 'TRF-001',
        'allocations' => array_map(fn (array $line): array => [
            'sales_invoice_id' => $line[0]->id,
            'amount' => $line[1],
        ], $allocations),
    ], $overrides);
}

/**
 * @return array{field: string|null, code: int, message: string}
 */
function paymentRuleFailure(callable $action): array
{
    try {
        $action();
    } catch (BusinessRuleException $e) {
        return ['field' => $e->field, 'code' => $e->getCode(), 'message' => $e->getMessage()];
    }

    test()->fail('Expected a BusinessRuleException.');
}

/**
 * @return list<array{string, string, string}>
 */
function paymentJournalLines(Journal $journal): array
{
    return $journal->lines()->with('account')->orderBy('line_no')->get()->map(
        fn ($l): array => [$l->account->code, $l->debit, $l->credit]
    )->all();
}

test('a draft receipt stores its header and allocations and takes a PAY number', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');

    $payment = $this->service->createReceipt(receiptData($this->customerId, $this->bank, '1000.00', [[$invoice, '1000.00']], ['notes' => ' Catatan ']));

    expect($payment->status)->toBe(PaymentStatus::DRAFT)
        ->and($payment->number)->toMatch('#^PAY/202611/\d{5}$#')
        ->and($payment->payment_type->value)->toBe('receipt')
        ->and($payment->customer_id)->toBe($this->customerId)
        ->and($payment->supplier_id)->toBeNull()
        ->and($payment->amount)->toBe('1000.00')
        ->and($payment->reference)->toBe('TRF-001')
        ->and($payment->notes)->toBe('Catatan')
        ->and($payment->journal_id)->toBeNull()
        ->and($payment->allocations)->toHaveCount(1)
        ->and($payment->allocations[0]->sales_invoice_id)->toBe($invoice->id)
        ->and($payment->allocations[0]->amount)->toBe('1000.00');

    expect($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($invoice->fresh()->status)->toBe(SalesInvoiceStatus::POSTED);
});

test('paying an invoice in full marks it paid and writes the exact journal', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->bank, '3000.00', [[$invoice, '3000.00']]));

    $posted = $this->service->post($draft);

    expect($posted->status)->toBe(PaymentStatus::POSTED)
        ->and($posted->posted_by)->toBe(Auth::id())
        ->and($posted->posted_at)->not->toBeNull()
        ->and($posted->journal_id)->not->toBeNull();

    $reloaded = $invoice->fresh();

    expect($reloaded->status)->toBe(SalesInvoiceStatus::PAID)
        ->and($reloaded->paid_amount)->toBe('3000.00')
        ->and($reloaded->outstanding())->toBe('0.00');

    $journal = Journal::findOrFail($posted->journal_id);

    expect($journal->status)->toBe(JournalStatus::POSTED)
        ->and($journal->source_type)->toBe('payment')
        ->and($journal->source_id)->toBe($posted->id)
        ->and($journal->journal_date->toDateString())->toBe('2026-11-04')
        ->and($journal->description)->toContain($posted->number)
        ->and(paymentJournalLines($journal))->toBe([
            ['1102', '3000.00', '0.00'],
            ['1201', '0.00', '3000.00'],
        ]);
});

test('a partial payment leaves the exact remainder', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');

    $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.50', [[$invoice, '1000.50']])));

    $reloaded = $invoice->fresh();

    expect($reloaded->status)->toBe(SalesInvoiceStatus::PARTIALLY_PAID)
        ->and($reloaded->paid_amount)->toBe('1000.50')
        ->and($reloaded->outstanding())->toBe('1999.50');
});

test('further payments settle a partly paid invoice', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');

    foreach (['1000.00', '500.00'] as $amount) {
        $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, $amount, [[$invoice, $amount]])));
    }

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::PARTIALLY_PAID)
        ->and($invoice->fresh()->paid_amount)->toBe('1500.00');

    $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1500.00', [[$invoice, '1500.00']])));

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::PAID)
        ->and($invoice->fresh()->paid_amount)->toBe('3000.00');
});

test('one payment can settle three invoices with different amounts', function (): void {
    $full = postedInvoiceOf($this->customerId, '1000.0000');
    $partial = postedInvoiceOf($this->customerId, '2000.0000');
    $small = postedInvoiceOf($this->customerId, '300.5000');

    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->bank, '1800.50', [
        [$full, '1000.00'],
        [$partial, '500.00'],
        [$small, '300.50'],
    ])));

    expect($full->fresh()->status)->toBe(SalesInvoiceStatus::PAID)
        ->and($partial->fresh()->status)->toBe(SalesInvoiceStatus::PARTIALLY_PAID)
        ->and($partial->fresh()->outstanding())->toBe('1500.00')
        ->and($small->fresh()->status)->toBe(SalesInvoiceStatus::PAID)
        ->and(paymentJournalLines(Journal::findOrFail($posted->journal_id)))->toBe([
            ['1102', '1800.50', '0.00'],
            ['1201', '0.00', '1800.50'],
        ]);
});

test('allocations below the amount may be saved as a draft but cannot be posted', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');

    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '999.99']]));

    $failure = paymentRuleFailure(fn () => $this->service->post($draft));

    expect($failure['field'])->toBe('allocations')
        ->and($failure['code'])->toBe(422)
        ->and($draft->fresh()->status)->toBe(PaymentStatus::DRAFT)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00');

    $empty = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', []));

    expect($empty->allocations)->toHaveCount(0)
        ->and(paymentRuleFailure(fn () => $this->service->post($empty))['field'])->toBe('allocations');
});

test('allocations above the amount are refused even on a draft', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');

    $failure = paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.01']])));

    expect($failure['field'])->toBe('allocations')->and($failure['code'])->toBe(422);
});

test('an allocation above what the invoice still owes is refused', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');

    $failure = paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.01', [[$invoice, '1000.01']])));

    expect($failure['field'])->toBe('allocations')
        ->and($failure['message'])->toContain($invoice->number);

    expect($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]))->status)
        ->toBe(PaymentStatus::DRAFT);
});

test('an invoice of another customer is refused', function (): void {
    $other = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $invoice = postedInvoiceOf($other, '1000.0000');

    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']])))['field'])
        ->toBe('allocations');
});

test('draft, void and paid invoices cannot be allocated', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000']], [], ['customer_id' => $this->customerId]);
    $delivery = SalesInvoiceFixtures::postedDelivery($order, ['3.0000']);
    $invoices = new SalesInvoiceService;

    $draft = SalesInvoiceFixtures::draftInvoice($order, [$delivery], '1.0000');
    $voided = $invoices->void(SalesInvoiceFixtures::draftInvoice($order, [$delivery], '1.0000'), 'Salah');
    $paid = postedInvoiceOf($this->customerId, '1000.0000');
    $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$paid, '1000.00']])));

    foreach ([$draft, $voided, $paid->fresh()] as $invoice) {
        expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [[$invoice, '1.00']])))['field'])
            ->toBe('allocations');
    }
});

test('an invoice cannot appear twice in one payment, and a zero allocation is refused', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');

    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '2.00', [[$invoice, '1.00'], [$invoice, '1.00']])))['field'])
        ->toBe('allocations')
        ->and(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [[$invoice, '0.00']])))['field'])
        ->toBe('allocations');
});

test('only cash and bank accounts from the configuration are accepted', function (string $code, bool $accepted): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $data = receiptData($this->customerId, accountIdByCode($code), '1000.00', [[$invoice, '1000.00']]);

    if ($accepted) {
        expect($this->service->createReceipt($data)->account_id)->toBe(accountIdByCode($code));

        return;
    }

    expect(paymentRuleFailure(fn () => $this->service->createReceipt($data))['field'])->toBe('account_id');
})->with([
    'cash' => ['1101', true],
    'bank' => ['1102', true],
    'sales' => ['4101', false],
    'receivable' => ['1201', false],
]);

test('an inactive cash account is refused when saving and when posting', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    DB::table('chart_of_accounts')->where('code', '1101')->update(['is_active' => false]);

    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']])))['field'])
        ->toBe('account_id')
        ->and(paymentRuleFailure(fn () => $this->service->post($draft))['field'])->toBe('account_id')
        ->and($draft->fresh()->status)->toBe(PaymentStatus::DRAFT);
});

test('an inactive customer is refused when saving and when posting', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    DB::table('customers')->where('id', $this->customerId)->update(['is_active' => false]);

    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']])))['field'])
        ->toBe('customer_id')
        ->and(paymentRuleFailure(fn () => $this->service->post($draft))['field'])->toBe('customer_id');

    DB::table('customers')->where('id', $this->customerId)->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(PaymentStatus::POSTED);
});

test('the amount must be positive and the date cannot be in the future', function (): void {
    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '0.00', [])))['field'])->toBe('amount')
        ->and(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '-1.00', [])))['field'])->toBe('amount')
        ->and(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [], ['payment_date' => now()->addDay()->toDateString()])))['field'])->toBe('payment_date');

    expect($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [], ['payment_date' => now()->toDateString()]))->status)
        ->toBe(PaymentStatus::DRAFT);
});

test('an unknown method and an overlong reference are refused', function (): void {
    expect(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [], ['method' => 'crypto'])))['field'])->toBe('method')
        ->and(paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', [], ['reference' => str_repeat('a', 101)])))['field'])->toBe('reference');
});

test('disbursements are refused until they exist', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');

    $failure = paymentRuleFailure(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']], ['payment_type' => 'disbursement'])));

    expect($failure['field'])->toBe('payment_type')->and($failure['code'])->toBe(422);

    $id = DB::table('payments')->insertGetId([
        'number' => 'PAY/202611/99998',
        'payment_type' => 'disbursement',
        'supplier_id' => TestDataFactory::createSupplier(),
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $this->cash,
        'amount' => '1.00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $disbursement = Payment::findOrFail($id);

    expect(paymentRuleFailure(fn () => $this->service->post($disbursement))['field'])->toBe('payment_type')
        ->and(paymentRuleFailure(fn () => $this->service->void($disbursement, 'Salah'))['field'])->toBe('payment_type')
        ->and(paymentRuleFailure(fn () => $this->service->updateReceipt($disbursement, receiptData($this->customerId, $this->cash, '1.00', [])))['field'])->toBe('payment_type');
});

test('two drafts competing for the same remainder: the second posting fails and changes nothing', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $first = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '700.00', [[$invoice, '700.00']]));
    $second = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '700.00', [[$invoice, '700.00']]));

    $this->service->post($first);

    $failure = paymentRuleFailure(fn () => $this->service->post($second));

    expect($failure['field'])->toBe('allocations')->and($failure['code'])->toBe(422);

    $reloaded = $second->fresh();

    expect($reloaded->status)->toBe(PaymentStatus::DRAFT)
        ->and($reloaded->posted_at)->toBeNull()
        ->and($reloaded->journal_id)->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('700.00')
        ->and(Journal::where('source_type', 'payment')->where('source_id', $second->id)->exists())->toBeFalse();
});

test('a posting is refused when the invoice was voided after the draft was saved', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    (new SalesInvoiceService)->void($invoice, 'Salah tagih');

    expect(paymentRuleFailure(fn () => $this->service->post($draft))['field'])->toBe('allocations');
});

test('voiding a full payment returns the invoice to posted with a neutralising reversal', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->bank, '1000.00', [[$invoice, '1000.00']])));

    $voided = $this->service->void($posted, '  Salah transfer  ');

    expect($voided->status)->toBe(PaymentStatus::VOID)
        ->and($voided->void_reason)->toBe('Salah transfer')
        ->and($voided->voided_by)->toBe(Auth::id())
        ->and($voided->voided_at)->not->toBeNull();

    $reloaded = $invoice->fresh();

    expect($reloaded->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and($reloaded->paid_amount)->toBe('0.00');

    $journals = Journal::where('source_type', 'payment')->where('source_id', $posted->id)->orderBy('id')->get();

    expect($journals)->toHaveCount(2)
        ->and($journals[1]->reversal_of_id)->toBe($journals[0]->id)
        ->and($journals[1]->journal_date->toDateString())->toBe(now()->toDateString())
        ->and(paymentJournalLines($journals[1]))->toBe([
            ['1102', '0.00', '1000.00'],
            ['1201', '1000.00', '0.00'],
        ]);

    $net = [];
    foreach ($journals as $journal) {
        foreach (paymentJournalLines($journal) as [$code, $debit, $credit]) {
            $net[$code] = Decimal::sub(Decimal::add($net[$code] ?? '0', $debit), $credit);
        }
    }

    expect($net)->toBe(['1102' => '0.00', '1201' => '0.00']);
});

test('voiding works the invoice status back step by step', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '3000.0000');
    $first = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']])));
    $second = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '2000.00', [[$invoice, '2000.00']])));

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::PAID);

    $this->service->void($first, 'Salah');

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::PARTIALLY_PAID)
        ->and($invoice->fresh()->paid_amount)->toBe('2000.00');

    $this->service->void($second, 'Salah');

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00');
});

test('voiding a payment across invoices restores each of them', function (): void {
    $full = postedInvoiceOf($this->customerId, '1000.0000');
    $partial = postedInvoiceOf($this->customerId, '2000.0000');
    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1500.00', [[$full, '1000.00'], [$partial, '500.00']])));

    $this->service->void($posted, 'Salah');

    expect($full->fresh()->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and($full->fresh()->paid_amount)->toBe('0.00')
        ->and($partial->fresh()->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and($partial->fresh()->paid_amount)->toBe('0.00');
});

test('voiding a draft has no effect on invoices or journals and needs a reason', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    expect(paymentRuleFailure(fn () => $this->service->void($draft, '   '))['field'])->toBe('reason')
        ->and(paymentRuleFailure(fn () => $this->service->void($draft, str_repeat('a', 256)))['field'])->toBe('reason');

    $voided = $this->service->void($draft, 'x');

    expect($voided->status)->toBe(PaymentStatus::VOID)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($invoice->fresh()->status)->toBe(SalesInvoiceStatus::POSTED)
        ->and(Journal::where('source_type', 'payment')->where('source_id', $draft->id)->exists())->toBeFalse();
});

test('an invoice with money received cannot be voided until the payment is voided', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '400.00', [[$invoice, '400.00']])));
    $invoices = new SalesInvoiceService;

    $failure = paymentRuleFailure(fn () => $invoices->void($invoice->fresh(), 'Salah'));

    expect($failure['code'])->toBe(409)->and($failure['message'])->toContain('batalkan pembayaran terlebih dahulu');

    $this->service->void($posted, 'Salah');

    expect($invoices->void($invoice->fresh(), 'Salah')->status)->toBe(SalesInvoiceStatus::VOID);
});

test('receipts lower the customer exposure and voiding raises it again', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $credit = new CreditExposureService;

    expect($credit->outstanding($this->customerId))->toBe('1000.00');

    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '400.00', [[$invoice, '400.00']])));

    expect($credit->outstanding($this->customerId))->toBe('600.00');

    $this->service->void($posted, 'Salah');

    expect($credit->outstanding($this->customerId))->toBe('1000.00');
});

test('updating a draft replaces its allocations whole', function (): void {
    $first = postedInvoiceOf($this->customerId, '1000.0000');
    $second = postedInvoiceOf($this->customerId, '2000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$first, '1000.00']]));

    $updated = $this->service->updateReceipt($draft, receiptData($this->customerId, $this->bank, '1500.00', [[$second, '1500.00']], [
        'payment_date' => '2026-11-03',
        'method' => 'cash',
        'reference' => null,
    ]));

    expect($updated->number)->toBe($draft->number)
        ->and($updated->amount)->toBe('1500.00')
        ->and($updated->account_id)->toBe($this->bank)
        ->and($updated->method->value)->toBe('cash')
        ->and($updated->reference)->toBeNull()
        ->and($updated->payment_date->toDateString())->toBe('2026-11-03')
        ->and($updated->allocations)->toHaveCount(1)
        ->and($updated->allocations[0]->sales_invoice_id)->toBe($second->id);
});

test('illegal transitions are refused', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '100.00', [[$invoice, '100.00']])));
    $voided = $this->service->void($this->service->createReceipt(receiptData($this->customerId, $this->cash, '100.00', [[$invoice, '100.00']])), 'Salah');
    $data = receiptData($this->customerId, $this->cash, '100.00', []);

    expect(fn () => $this->service->post($posted))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->post($voided))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->void($voided, 'Lagi'))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->updateReceipt($posted, $data))->toThrow(DocumentNotEditableException::class);
    expect(fn () => $this->service->updateReceipt($voided, $data))->toThrow(DocumentNotEditableException::class);

    expect($invoice->fresh()->paid_amount)->toBe('100.00')
        ->and(Journal::where('source_type', 'payment')->where('source_id', $posted->id)->count())->toBe(1);
});

test('a journal failure rolls the whole posting back', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    DB::table('chart_of_accounts')->where('code', '1201')->update(['is_active' => false]);

    expect(fn () => $this->service->post($draft))->toThrow(BusinessRuleException::class);

    expect($draft->fresh()->status)->toBe(PaymentStatus::DRAFT)
        ->and($draft->fresh()->journal_id)->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($invoice->fresh()->status)->toBe(SalesInvoiceStatus::POSTED);

    DB::table('chart_of_accounts')->where('code', '1201')->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(PaymentStatus::POSTED);
});

test('every change is audited and a failed audit write rolls the payment back', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']]));
    $this->service->post($draft);

    $events = DB::table('audit_logs')
        ->where('auditable_type', Payment::class)->where('auditable_id', $draft->id)
        ->orderBy('id')->pluck('event')->all();

    expect($events)->toBe(['payment.created', 'payment.allocations_saved', 'payment.updated']);

    $validUser = (int) Auth::id();
    $missingUser = new User;
    $missingUser->id = 99999999;
    Auth::setUser($missingUser);
    $count = Payment::count();

    expect(fn () => $this->service->createReceipt(receiptData($this->customerId, $this->cash, '1.00', []), $validUser))
        ->toThrow(QueryException::class);

    expect(Payment::count())->toBe($count);
});

test('posting locks the payment, then the invoices in id order, then writes the journal', function (): void {
    $first = postedInvoiceOf($this->customerId, '1000.0000');
    $second = postedInvoiceOf($this->customerId, '1000.0000');
    $draft = $this->service->createReceipt(receiptData($this->customerId, $this->cash, '2000.00', [[$second, '1000.00'], [$first, '1000.00']]));

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

    $payment = $position('from `payments`', 'for update');
    $invoices = $position('from `sales_invoices`', 'for update');
    $journal = $position('insert into `journals`', 'insert');

    expect($payment)->toBeGreaterThanOrEqual(0)
        ->and($invoices)->toBeGreaterThan($payment)
        ->and($journal)->toBeGreaterThan($invoices)
        ->and($queries[$invoices])->toContain('order by `id` asc');
});

test('voiding a posted payment takes the same locks', function (): void {
    $invoice = postedInvoiceOf($this->customerId, '1000.0000');
    $posted = $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '1000.00', [[$invoice, '1000.00']])));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->service->void($posted, 'Salah');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $paymentLock = array_key_first(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `payments`') && str_contains($sql, 'for update')));
    $invoiceLock = array_key_first(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `sales_invoices`') && str_contains($sql, 'for update')));

    expect($paymentLock)->not->toBeNull()
        ->and($invoiceLock)->toBeGreaterThan($paymentLock);
});

test('outstanding invoices list only what this customer owes, oldest due first', function (): void {
    $later = postedInvoiceOf($this->customerId, '1000.0000');
    $sooner = postedInvoiceOf($this->customerId, '2000.0000');
    $paid = postedInvoiceOf($this->customerId, '500.0000');
    $partial = postedInvoiceOf($this->customerId, '800.0000');
    postedInvoiceOf(TestDataFactory::createCustomer(['credit_limit' => 0]), '999.0000');

    DB::table('sales_invoices')->where('id', $later->id)->update(['due_date' => '2026-12-01']);
    DB::table('sales_invoices')->where('id', $sooner->id)->update(['due_date' => '2026-11-10']);
    DB::table('sales_invoices')->where('id', $partial->id)->update(['due_date' => '2026-11-20']);

    $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '500.00', [[$paid, '500.00']])));
    $this->service->post($this->service->createReceipt(receiptData($this->customerId, $this->cash, '300.00', [[$partial, '300.00']])));

    $list = $this->service->outstandingInvoices(Customer::findOrFail($this->customerId));

    expect(array_column($list, 'id'))->toBe([$sooner->id, $partial->id, $later->id])
        ->and($list[1]['number'])->toBe($partial->number)
        ->and($list[1]['invoice_date'])->toBe('2026-11-03')
        ->and($list[1]['due_date'])->toBe('2026-11-20')
        ->and($list[1]['total'])->toBe('800.00')
        ->and($list[1]['paid_amount'])->toBe('300.00')
        ->and($list[1]['outstanding'])->toBe('500.00');
});
