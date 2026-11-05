<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Decimal;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->service = new PaymentService;
    $this->supplierId = TestDataFactory::createSupplier();
    $this->cash = disbursementAccountId('1101');
    $this->bank = disbursementAccountId('1102');
});

function disbursementAccountId(string $code): int
{
    return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
}

function postedPurchaseInvoiceOf(int $supplierId, string $total): PurchaseInvoice
{
    $order = PurchaseInvoiceFixtures::approvedOrder(
        [['quantity' => '1.0000', 'unit_price' => $total]],
        [],
        ['supplier_id' => $supplierId]
    );
    $receipt = PurchaseInvoiceFixtures::postedReceipt($order, ['1.0000']);

    return (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($order, [$receipt]));
}

/**
 * @param  list<array{0: PurchaseInvoice, 1: string}>  $allocations
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function disbursementData(int $supplierId, int $accountId, string $amount, array $allocations, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $supplierId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => $accountId,
        'amount' => $amount,
        'reference' => 'OUT-001',
        'allocations' => array_map(fn (array $line): array => [
            'purchase_invoice_id' => $line[0]->id,
            'amount' => $line[1],
        ], $allocations),
    ], $overrides);
}

/**
 * @return array{field: string|null, code: int, message: string}
 */
function disbursementFailure(callable $action): array
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
function disbursementJournalLines(Journal $journal): array
{
    return $journal->lines()->with('account')->orderBy('line_no')->get()->map(
        fn ($l): array => [$l->account->code, $l->debit, $l->credit]
    )->all();
}

test('a draft disbursement names the supplier, not a customer, and takes a PAY number', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '3000.0000');

    $payment = $this->service->createDisbursement(disbursementData($this->supplierId, $this->bank, '1000.00', [[$invoice, '1000.00']]));

    expect($payment->status)->toBe(PaymentStatus::DRAFT)
        ->and($payment->payment_type->value)->toBe('disbursement')
        ->and($payment->number)->toMatch('#^PAY/202611/\d{5}$#')
        ->and($payment->supplier_id)->toBe($this->supplierId)
        ->and($payment->customer_id)->toBeNull()
        ->and($payment->allocations[0]->purchase_invoice_id)->toBe($invoice->id)
        ->and($payment->allocations[0]->sales_invoice_id)->toBeNull();

    expect($invoice->fresh()->paid_amount)->toBe('0.00');
});

test('paying an invoice in full marks it paid and writes journal JT-PAYD exactly', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '3000.0000');

    $posted = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->bank, '3000.00', [[$invoice, '3000.00']])));

    expect($posted->status)->toBe(PaymentStatus::POSTED)
        ->and($posted->journal_id)->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::PAID)
        ->and($invoice->fresh()->paid_amount)->toBe('3000.00');

    $journal = Journal::findOrFail($posted->journal_id);

    expect($journal->source_type)->toBe('payment')
        ->and($journal->description)->toContain($posted->number)
        ->and(disbursementJournalLines($journal))->toBe([
            ['2101', '3000.00', '0.00'],
            ['1102', '0.00', '3000.00'],
        ]);
});

test('a partial payment leaves the exact remainder', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '3000.0000');

    $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.50', [[$invoice, '1000.50']])));

    expect($invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::PARTIALLY_PAID)
        ->and($invoice->fresh()->outstanding())->toBe('1999.50');
});

test('one payment can settle three invoices with different amounts', function (): void {
    $full = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $partial = postedPurchaseInvoiceOf($this->supplierId, '2000.0000');
    $small = postedPurchaseInvoiceOf($this->supplierId, '300.5000');

    $posted = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->bank, '1800.50', [
        [$full, '1000.00'],
        [$partial, '500.00'],
        [$small, '300.50'],
    ])));

    expect($full->fresh()->status)->toBe(PurchaseInvoiceStatus::PAID)
        ->and($partial->fresh()->status)->toBe(PurchaseInvoiceStatus::PARTIALLY_PAID)
        ->and($partial->fresh()->outstanding())->toBe('1500.00')
        ->and($small->fresh()->status)->toBe(PurchaseInvoiceStatus::PAID)
        ->and(disbursementJournalLines(Journal::findOrFail($posted->journal_id)))->toBe([
            ['2101', '1800.50', '0.00'],
            ['1102', '0.00', '1800.50'],
        ]);
});

test('allocations below the amount may be saved as a draft but cannot be posted', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '3000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$invoice, '999.99']]));

    $failure = disbursementFailure(fn () => $this->service->post($draft));

    expect($failure['field'])->toBe('allocations')
        ->and($failure['code'])->toBe(422)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00');
});

test('allocations above the amount or above what the invoice owes are refused', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');

    expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '500.00', [[$invoice, '500.01']])))['field'])->toBe('allocations');

    $failure = disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.01', [[$invoice, '1000.01']])));

    expect($failure['field'])->toBe('allocations')
        ->and($failure['message'])->toContain($invoice->number);
});

test('an invoice of another supplier is refused', function (): void {
    $invoice = postedPurchaseInvoiceOf(TestDataFactory::createSupplier(), '1000.0000');

    expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$invoice, '1000.00']])))['field'])
        ->toBe('allocations');
});

test('draft, void and paid invoices cannot be allocated', function (): void {
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '1000.0000']], [], ['supplier_id' => $this->supplierId]);
    $receipt = PurchaseInvoiceFixtures::postedReceipt($order, ['3.0000']);
    $invoices = new PurchaseInvoiceService;

    $draft = PurchaseInvoiceFixtures::draftInvoice($order, [$receipt], '1.0000');
    $voided = $invoices->void(PurchaseInvoiceFixtures::draftInvoice($order, [$receipt], '1.0000'), 'Salah');
    $paid = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$paid, '1000.00']])));

    foreach ([$draft, $voided, $paid->fresh()] as $invoice) {
        expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1.00', [[$invoice, '1.00']])))['field'])
            ->toBe('allocations');
    }
});

test('an invoice cannot appear twice in one payment', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');

    expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '2.00', [[$invoice, '1.00'], [$invoice, '1.00']])))['field'])
        ->toBe('allocations');
});

test('only cash and bank accounts are accepted', function (string $code, bool $accepted): void {
    $data = disbursementData($this->supplierId, disbursementAccountId($code), '1.00', []);

    if ($accepted) {
        expect($this->service->createDisbursement($data)->account_id)->toBe(disbursementAccountId($code));

        return;
    }

    expect(disbursementFailure(fn () => $this->service->createDisbursement($data))['field'])->toBe('account_id');
})->with([
    'cash' => ['1101', true],
    'bank' => ['1102', true],
    'payable' => ['2101', false],
    'sales' => ['4101', false],
]);

test('an inactive supplier is refused when saving and when posting', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    DB::table('suppliers')->where('id', $this->supplierId)->update(['is_active' => false]);

    expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1.00', [])))['field'])->toBe('supplier_id')
        ->and(disbursementFailure(fn () => $this->service->post($draft))['field'])->toBe('supplier_id');

    DB::table('suppliers')->where('id', $this->supplierId)->update(['is_active' => true]);

    expect($this->service->post($draft)->status)->toBe(PaymentStatus::POSTED);
});

test('two drafts competing for the same remainder: the second posting fails and changes nothing', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $first = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '700.00', [[$invoice, '700.00']]));
    $second = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '700.00', [[$invoice, '700.00']]));

    $this->service->post($first);

    expect(disbursementFailure(fn () => $this->service->post($second))['field'])->toBe('allocations')
        ->and($second->fresh()->status)->toBe(PaymentStatus::DRAFT)
        ->and($second->fresh()->journal_id)->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('700.00');
});

test('voiding works the invoice status back step by step with a neutralising reversal', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '3000.0000');
    $first = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->bank, '1000.00', [[$invoice, '1000.00']])));
    $second = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->bank, '2000.00', [[$invoice, '2000.00']])));

    expect($invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::PAID);

    $voided = $this->service->void($first, ' Salah ');

    expect($voided->status)->toBe(PaymentStatus::VOID)
        ->and($voided->void_reason)->toBe('Salah')
        ->and($invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::PARTIALLY_PAID)
        ->and($invoice->fresh()->paid_amount)->toBe('2000.00');

    $this->service->void($second, 'Salah');

    expect($invoice->fresh()->status)->toBe(PurchaseInvoiceStatus::POSTED)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00');

    $journals = Journal::where('source_type', 'payment')->where('source_id', $first->id)->orderBy('id')->get();

    expect($journals)->toHaveCount(2)
        ->and($journals[1]->reversal_of_id)->toBe($journals[0]->id);

    $net = [];
    foreach ($journals as $journal) {
        foreach (disbursementJournalLines($journal) as [$code, $debit, $credit]) {
            $net[$code] = Decimal::sub(Decimal::add($net[$code] ?? '0', $debit), $credit);
        }
    }

    expect($net)->toBe(['2101' => '0.00', '1102' => '0.00']);
});

test('voiding a draft has no effect', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    expect(disbursementFailure(fn () => $this->service->void($draft, '  '))['field'])->toBe('reason');

    expect($this->service->void($draft, 'x')->status)->toBe(PaymentStatus::VOID)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and(Journal::where('source_type', 'payment')->where('source_id', $draft->id)->exists())->toBeFalse();
});

test('an invoice with money paid cannot be voided until the payment is voided', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $posted = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '400.00', [[$invoice, '400.00']])));
    $invoices = new PurchaseInvoiceService;

    expect(disbursementFailure(fn () => $invoices->void($invoice->fresh(), 'Salah'))['code'])->toBe(409);

    $this->service->void($posted, 'Salah');

    expect($invoices->void($invoice->fresh(), 'Salah')->status)->toBe(PurchaseInvoiceStatus::VOID);
});

test('updating a draft replaces its allocations and keeps to its own type', function (): void {
    $first = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $second = postedPurchaseInvoiceOf($this->supplierId, '2000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$first, '1000.00']]));

    $updated = $this->service->updateDisbursement($draft, disbursementData($this->supplierId, $this->bank, '1500.00', [[$second, '1500.00']]));

    expect($updated->number)->toBe($draft->number)
        ->and($updated->amount)->toBe('1500.00')
        ->and($updated->allocations)->toHaveCount(1)
        ->and($updated->allocations[0]->purchase_invoice_id)->toBe($second->id);

    expect(disbursementFailure(fn () => $this->service->updateReceipt($draft, [
        'customer_id' => TestDataFactory::createCustomer(),
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $this->cash,
        'amount' => '1.00',
    ]))['field'])->toBe('payment_type');
});

test('illegal transitions are refused', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $posted = $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '100.00', [[$invoice, '100.00']])));
    $voided = $this->service->void($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '100.00', [[$invoice, '100.00']])), 'Salah');
    $data = disbursementData($this->supplierId, $this->cash, '100.00', []);

    expect(fn () => $this->service->post($posted))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->void($voided, 'Lagi'))->toThrow(InvalidStateTransitionException::class);
    expect(fn () => $this->service->updateDisbursement($posted, $data))->toThrow(DocumentNotEditableException::class);
});

test('a journal failure rolls the whole posting back', function (): void {
    $invoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1000.00', [[$invoice, '1000.00']]));

    DB::table('chart_of_accounts')->where('code', '2101')->update(['is_active' => false]);

    expect(fn () => $this->service->post($draft))->toThrow(BusinessRuleException::class);

    expect($draft->fresh()->status)->toBe(PaymentStatus::DRAFT)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00');
});

test('every change is audited and a failed audit write rolls the payment back', function (): void {
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1.00', []));

    expect(DB::table('audit_logs')->where('auditable_type', Payment::class)->where('auditable_id', $draft->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['payment.created', 'payment.allocations_saved']);

    $validUser = (int) Auth::id();
    $missingUser = new User;
    $missingUser->id = 99999999;
    Auth::setUser($missingUser);
    $count = Payment::count();

    expect(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1.00', []), $validUser))
        ->toThrow(QueryException::class);

    expect(Payment::count())->toBe($count);
});

test('posting locks the payment, then the invoices in id order, then writes the journal', function (): void {
    $first = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $second = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $draft = $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '2000.00', [[$second, '1000.00'], [$first, '1000.00']]));

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
    $invoices = $position('from `purchase_invoices`', 'for update');
    $journal = $position('insert into `journals`', 'insert');

    expect($payment)->toBeGreaterThanOrEqual(0)
        ->and($invoices)->toBeGreaterThan($payment)
        ->and($journal)->toBeGreaterThan($invoices)
        ->and($queries[$invoices])->toContain('order by `id` asc');
});

test('the database check on party and type holds', function (): void {
    expect(fn () => DB::table('payments')->insert([
        'number' => 'PAY/202611/99997',
        'payment_type' => 'disbursement',
        'customer_id' => TestDataFactory::createCustomer(),
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $this->cash,
        'amount' => '1.00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'chk_payments_party');
});

test('a receipt cannot be allocated to a purchase invoice, nor a disbursement to a sales invoice', function (): void {
    $purchaseInvoice = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $customerId = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.0000']], [], ['customer_id' => $customerId]);
    $salesInvoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [SalesInvoiceFixtures::postedDelivery($order, ['1.0000'])]));

    expect(disbursementFailure(fn () => $this->service->createReceipt([
        'customer_id' => $customerId,
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => $this->cash,
        'amount' => '1.00',
        'allocations' => [['purchase_invoice_id' => $purchaseInvoice->id, 'amount' => '1.00']],
    ]))['field'])->toBe('allocations');

    expect(disbursementFailure(fn () => $this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '1.00', [], [
        'allocations' => [['sales_invoice_id' => $salesInvoice->id, 'amount' => '1.00']],
    ])))['field'])->toBe('allocations');
});

test('outstanding purchase invoices list only what is owed to this supplier, oldest due first', function (): void {
    $later = postedPurchaseInvoiceOf($this->supplierId, '1000.0000');
    $sooner = postedPurchaseInvoiceOf($this->supplierId, '2000.0000');
    $paid = postedPurchaseInvoiceOf($this->supplierId, '500.0000');
    postedPurchaseInvoiceOf(TestDataFactory::createSupplier(), '999.0000');

    DB::table('purchase_invoices')->where('id', $later->id)->update(['due_date' => '2026-12-01']);
    DB::table('purchase_invoices')->where('id', $sooner->id)->update(['due_date' => '2026-11-10']);

    $this->service->post($this->service->createDisbursement(disbursementData($this->supplierId, $this->cash, '500.00', [[$paid, '500.00']])));

    $list = $this->service->outstandingPurchaseInvoices(Supplier::findOrFail($this->supplierId));

    expect(array_column($list, 'id'))->toBe([$sooner->id, $later->id])
        ->and($list[0]['number'])->toBe($sooner->number)
        ->and($list[0]['supplier_invoice_number'])->toBe($sooner->supplier_invoice_number)
        ->and($list[0]['outstanding'])->toBe('2000.00');
});
