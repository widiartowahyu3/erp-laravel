<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Reports\CustomerStatementReport;
use App\Services\Reports\PayableAgingReport;
use App\Services\Reports\ReceivableAgingReport;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\TrialBalanceReport;
use App\Services\Sales\SalesInvoiceService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->customerId = TestDataFactory::createCustomer(['code' => 'CST-A', 'name' => 'Pelanggan A', 'credit_limit' => 0]);
    $this->supplierId = TestDataFactory::createSupplier(['code' => 'SUP-A', 'name' => 'Pemasok A']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Keeps only the rows of the party under test, because the report covers every party.
 *
 * @return array<string, mixed>|null
 */
function agingRowOf(array $rows, int $partyId): ?array
{
    return collect($rows)->firstWhere('party_id', $partyId);
}

test('each invoice falls in the receivable bucket of its exact days past due', function (): void {
    // Days past due at 2026-11-05 => amount, so each bucket total names its members.
    $cases = [
        '2026-11-15' => '1.00',    // not due
        '2026-11-05' => '2.00',    // due today, not due
        '2026-11-04' => '4.00',    // 1
        '2026-10-06' => '8.00',    // 30
        '2026-10-05' => '16.00',   // 31
        '2026-09-06' => '32.00',   // 60
        '2026-09-05' => '64.00',   // 61
        '2026-08-07' => '128.00',  // 90
        '2026-08-06' => '256.00',  // 91
    ];

    foreach ($cases as $dueDate => $amount) {
        ReportFixtures::invoiceRow('sales_invoices', $this->customerId, $dueDate, $amount);
    }

    $result = (new ReceivableAgingReport)->handle(new ReportFilter);
    $row = agingRowOf($result->rows, $this->customerId);

    expect($row['party_code'])->toBe('CST-A')
        ->and($row['party_name'])->toBe('Pelanggan A')
        ->and($row['not_due'])->toBe('3.00')
        ->and($row['days_1_30'])->toBe('12.00')
        ->and($row['days_31_60'])->toBe('48.00')
        ->and($row['days_61_90'])->toBe('192.00')
        ->and($row['days_over_90'])->toBe('256.00')
        ->and($row['total'])->toBe('511.00')
        ->and($result->summary['as_of'])->toBe('2026-11-05')
        ->and($result->summary['total'])->toBe('511.00');
});

test('the same boundaries apply to payables', function (): void {
    foreach (['2026-11-05' => '1.00', '2026-10-06' => '2.00', '2026-10-05' => '4.00', '2026-09-05' => '8.00', '2026-08-06' => '16.00'] as $dueDate => $amount) {
        ReportFixtures::invoiceRow('purchase_invoices', $this->supplierId, $dueDate, $amount);
    }

    $row = agingRowOf((new PayableAgingReport)->handle(new ReportFilter)->rows, $this->supplierId);

    expect($row['party_code'])->toBe('SUP-A')
        ->and($row['not_due'])->toBe('1.00')
        ->and($row['days_1_30'])->toBe('2.00')
        ->and($row['days_31_60'])->toBe('4.00')
        ->and($row['days_61_90'])->toBe('8.00')
        ->and($row['days_over_90'])->toBe('16.00')
        ->and($row['total'])->toBe('31.00');
});

test('aging counts what is left on open invoices and skips paid, void and draft ones', function (): void {
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-30', '1000.00', '250.00', 'partially_paid');
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-30', '700.00', '700.00', 'paid');
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-30', '500.00', '0.00', 'void');
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-30', '300.00', '0.00', 'draft');

    $row = agingRowOf((new ReceivableAgingReport)->handle(new ReportFilter)->rows, $this->customerId);

    expect($row['total'])->toBe('750.00')
        ->and($row['days_1_30'])->toBe('750.00')
        ->and($row['invoices'])->toHaveCount(1)
        ->and($row['invoices'][0]['total'])->toBe('1000.00')
        ->and($row['invoices'][0]['paid_amount'])->toBe('250.00')
        ->and($row['invoices'][0]['outstanding'])->toBe('750.00')
        ->and($row['invoices'][0]['days_overdue'])->toBe(6)
        ->and($row['invoices'][0]['bucket'])->toBe('days_1_30');
});

test('a party with nothing open does not appear, and invoice details keep to their party', function (): void {
    $other = TestDataFactory::createCustomer(['name' => 'Pelanggan B', 'credit_limit' => 0]);
    $settled = TestDataFactory::createCustomer(['name' => 'Pelanggan C', 'credit_limit' => 0]);
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-12-01', '10.00');
    ReportFixtures::invoiceRow('sales_invoices', $other, '2026-12-01', '20.00');
    ReportFixtures::invoiceRow('sales_invoices', $settled, '2026-12-01', '30.00', '30.00', 'paid');

    $rows = (new ReceivableAgingReport)->handle(new ReportFilter)->rows;

    expect(agingRowOf($rows, $settled))->toBeNull()
        ->and(agingRowOf($rows, $this->customerId)['invoices'])->toHaveCount(1)
        ->and(agingRowOf($rows, $other)['invoices'][0]['outstanding'])->toBe('20.00');
});

test('every bucket adds up to the outstanding invoices and to the receivable account', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '500.00']], [], ['customer_id' => $this->customerId]);
    $delivery = SalesInvoiceFixtures::postedDelivery($order, ['2.0000']);
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [$delivery]));

    $payments = new PaymentService;
    $payments->post($payments->createReceipt([
        'customer_id' => $this->customerId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => ReportFixtures::accountId('1102'),
        'amount' => '400.00',
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => '400.00']],
    ]));

    $result = (new ReceivableAgingReport)->handle(new ReportFilter);
    $balance = collect((new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2026-01-01', dateTo: '2026-11-30'))->rows)->firstWhere('code', '1201');

    expect($result->summary['total'])->toBe('600.00')
        ->and($balance['closing'])->toBe('600.00')
        ->and($invoice->fresh()->total)->toBe('1000.00');
});

test('every bucket adds up to the outstanding invoices and to the payable account', function (): void {
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '500.00']], [], ['supplier_id' => $this->supplierId]);
    $receipt = PurchaseInvoiceFixtures::postedReceipt($order, ['2.0000']);
    $invoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($order, [$receipt]));

    $payments = new PaymentService;
    $payments->post($payments->createDisbursement([
        'supplier_id' => $this->supplierId,
        'payment_date' => '2026-11-04',
        'method' => 'bank_transfer',
        'account_id' => ReportFixtures::accountId('1102'),
        'amount' => '300.00',
        'allocations' => [['purchase_invoice_id' => $invoice->id, 'amount' => '300.00']],
    ]));

    $result = (new PayableAgingReport)->handle(new ReportFilter);
    $balance = collect((new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2026-01-01', dateTo: '2026-11-30'))->rows)->firstWhere('code', '2101');

    expect($result->summary['total'])->toBe('700.00')
        ->and($balance['closing'])->toBe('700.00');
});

/**
 * @return array{0: int, 1: int}
 */
function statementInvoices(int $customerId): array
{
    $payments = new PaymentService;
    $service = new SalesInvoiceService;

    $september = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.00']], [], ['customer_id' => $customerId]);
    $septemberInvoice = $service->post(SalesInvoiceFixtures::draftInvoice($september, [SalesInvoiceFixtures::postedDelivery($september, ['1.0000'])], null, ['invoice_date' => '2026-09-10']));

    $payments->post($payments->createReceipt([
        'customer_id' => $customerId,
        'payment_date' => '2026-09-20',
        'method' => 'cash',
        'account_id' => ReportFixtures::accountId('1101'),
        'amount' => '300.00',
        'allocations' => [['sales_invoice_id' => $septemberInvoice->id, 'amount' => '300.00']],
    ]));

    $october = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '2000.00']], [], ['customer_id' => $customerId]);
    $octoberInvoice = $service->post(SalesInvoiceFixtures::draftInvoice($october, [SalesInvoiceFixtures::postedDelivery($october, ['1.0000'])], null, ['invoice_date' => '2026-10-10']));

    $payments->post($payments->createReceipt([
        'customer_id' => $customerId,
        'payment_date' => '2026-10-15',
        'method' => 'cash',
        'account_id' => ReportFixtures::accountId('1101'),
        'amount' => '900.00',
        'allocations' => [
            ['sales_invoice_id' => $septemberInvoice->id, 'amount' => '700.00'],
            ['sales_invoice_id' => $octoberInvoice->id, 'amount' => '200.00'],
        ],
    ]));

    return [$septemberInvoice->id, $octoberInvoice->id];
}

test('a customer statement opens from history and runs through invoices and payments', function (): void {
    statementInvoices($this->customerId);

    $result = (new CustomerStatementReport)->handle(new ReportFilter(dateFrom: '2026-10-01', dateTo: '2026-10-31', customerId: $this->customerId));

    expect($result->summary['customer_code'])->toBe('CST-A')
        ->and($result->summary['opening'])->toBe('700.00')
        ->and($result->summary['total_debit'])->toBe('2000.00')
        ->and($result->summary['total_credit'])->toBe('900.00')
        ->and($result->summary['closing'])->toBe('1800.00')
        ->and(array_map(fn (array $row): array => [$row['kind'], $row['entry_date'], $row['debit'], $row['credit'], $row['balance']], $result->rows))->toBe([
            ['invoice', '2026-10-10', '2000.00', '0.00', '2700.00'],
            ['payment', '2026-10-15', '0.00', '700.00', '2000.00'],
            ['payment', '2026-10-15', '0.00', '200.00', '1800.00'],
        ]);
});

test('the statement closing balance equals what the customer still owes', function (): void {
    statementInvoices($this->customerId);

    $statement = (new CustomerStatementReport)->handle(new ReportFilter(dateFrom: '2026-01-01', dateTo: '2026-11-05', customerId: $this->customerId));
    $aging = agingRowOf((new ReceivableAgingReport)->handle(new ReportFilter)->rows, $this->customerId);

    expect($statement->summary['opening'])->toBe('0.00')
        ->and($statement->summary['closing'])->toBe('1800.00')
        ->and($aging['total'])->toBe('1800.00');
});

test('void and draft invoices stay off the statement, and other customers do not leak in', function (): void {
    $other = TestDataFactory::createCustomer(['credit_limit' => 0]);
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-20', '100.00', '0.00', 'posted', '2026-10-05');
    ReportFixtures::invoiceRow('sales_invoices', $other, '2026-10-20', '999.00', '0.00', 'posted', '2026-10-05');
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-20', '500.00', '0.00', 'void', '2026-10-05');
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-20', '600.00', '0.00', 'draft', '2026-10-05');

    $result = (new CustomerStatementReport)->handle(new ReportFilter(dateFrom: '2026-10-01', dateTo: '2026-10-31', customerId: $this->customerId));

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['debit'])->toBe('100.00')
        ->and($result->summary['closing'])->toBe('100.00');
});

test('a voided payment leaves the statement while a paid invoice stays on it', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '400.00']], [], ['customer_id' => $this->customerId]);
    $invoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($order, [SalesInvoiceFixtures::postedDelivery($order, ['1.0000'])], null, ['invoice_date' => '2026-10-10']));
    $payments = new PaymentService;
    $data = [
        'customer_id' => $this->customerId,
        'method' => 'cash',
        'account_id' => ReportFixtures::accountId('1101'),
        'amount' => '400.00',
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => '400.00']],
    ];
    $voided = $payments->post($payments->createReceipt($data + ['payment_date' => '2026-10-11']));
    $payments->void($voided, 'Salah input');
    $payments->post($payments->createReceipt($data + ['payment_date' => '2026-10-12']));

    $result = (new CustomerStatementReport)->handle(new ReportFilter(dateFrom: '2026-10-01', dateTo: '2026-10-31', customerId: $this->customerId));

    expect($invoice->fresh()->status->value)->toBe('paid')
        ->and(array_column($result->rows, 'entry_date'))->toBe(['2026-10-10', '2026-10-12'])
        ->and($result->summary['closing'])->toBe('0.00');
});

test('a statement needs a known customer and a valid period', function (array $filter, string $field): void {
    try {
        (new CustomerStatementReport)->handle(new ReportFilter(...$filter));
    } catch (BusinessRuleException $e) {
        expect($e->field)->toBe($field)->and($e->getCode())->toBe(422);

        return;
    }

    test()->fail('Expected a BusinessRuleException.');
})->with([
    'no customer' => [['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-31'], 'customer_id'],
    'unknown customer' => [['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-31', 'customerId' => 999999999], 'customer_id'],
    'reversed period' => [['dateFrom' => '2026-10-31', 'dateTo' => '2026-10-01', 'customerId' => 999999999], 'date_to'],
]);
