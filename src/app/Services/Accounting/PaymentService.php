<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Services\Accounting\Builders\PaymentJournalBuilder;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Services\Sales\InvoiceSettlement;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts allocated to sales invoices and supplier disbursements allocated to purchase invoices.
 * Authorization lives in the routes and requests.
 *
 * Lock order (docs/ACCOUNTING.md): payment, invoices by ascending id, document sequence, journal.
 * Drafts reserve nothing, so every rule is checked again under lock when posting.
 */
class PaymentService
{
    /**
     * Invoice statuses that can still take money, the same values on both kinds of invoice.
     *
     * @var list<string>
     */
    private const array PAYABLE_INVOICE_STATUSES = [
        'posted',
        'partially_paid',
    ];

    public function __construct(
        private readonly JournalService $journals = new JournalService,
        private readonly InvoiceSettlement $settlement = new InvoiceSettlement
    ) {}

    /**
     * Allocations may fall short of the amount on a draft; they must match it exactly to post.
     *
     * @param array{
     *     payment_type?: string|null,
     *     customer_id: int|string,
     *     payment_date: string,
     *     method: string,
     *     account_id: int|string,
     *     amount: string,
     *     reference?: string|null,
     *     notes?: string|null,
     *     allocations?: list<array{sales_invoice_id: int|string, amount: string}>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function createReceipt(array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($data, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();

            if (($data['payment_type'] ?? PaymentType::RECEIPT->value) !== PaymentType::RECEIPT->value) {
                throw $this->wrongType(PaymentType::RECEIPT);
            }

            return $this->createDraft(PaymentType::RECEIPT, $data, $effectiveUserId);
        });
    }

    /**
     * The supplier counterpart of createReceipt, with the same rules.
     *
     * @param array{
     *     supplier_id: int|string,
     *     payment_date: string,
     *     method: string,
     *     account_id: int|string,
     *     amount: string,
     *     reference?: string|null,
     *     notes?: string|null,
     *     allocations?: list<array{purchase_invoice_id: int|string, amount: string}>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function createDisbursement(array $data, ?int $userId = null): Payment
    {
        return DB::transaction(fn (): Payment => $this->createDraft(PaymentType::DISBURSEMENT, $data, $userId ?? Auth::id()));
    }

    /**
     * Replace a draft's fields and its allocations whole.
     *
     * @param array{
     *     customer_id: int|string,
     *     payment_date: string,
     *     method: string,
     *     account_id: int|string,
     *     amount: string,
     *     reference?: string|null,
     *     notes?: string|null,
     *     allocations?: list<array{sales_invoice_id: int|string, amount: string}>
     * } $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function updateReceipt(Payment $payment, array $data, ?int $userId = null): Payment
    {
        return $this->updateDraft(PaymentType::RECEIPT, $payment, $data, $userId);
    }

    /**
     * The supplier counterpart of updateReceipt.
     *
     * @param array{
     *     supplier_id: int|string,
     *     payment_date: string,
     *     method: string,
     *     account_id: int|string,
     *     amount: string,
     *     reference?: string|null,
     *     notes?: string|null,
     *     allocations?: list<array{purchase_invoice_id: int|string, amount: string}>
     * } $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function updateDisbursement(Payment $payment, array $data, ?int $userId = null): Payment
    {
        return $this->updateDraft(PaymentType::DISBURSEMENT, $payment, $data, $userId);
    }

    /**
     * Post a draft: settles its invoices and writes journal JT-PAYR or JT-PAYD, all or nothing.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(Payment $payment, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($payment, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockPayment($payment);
            $type = $locked->payment_type;

            DocumentStateMachine::assertTransition($locked->status, PaymentStatus::POSTED);

            $partyId = $this->partyIdOf($locked);
            $this->assertPartyActive($type, $partyId);
            $account = $this->assertCashBankAccount($locked->account_id);

            $allocations = PaymentAllocation::query()->where('payment_id', $locked->id)->orderBy('id')->get();
            $invoices = $this->lockInvoices($type, $allocations);

            $total = '0.00';

            foreach ($allocations as $allocation) {
                $this->assertAllocatable($type, $invoices->get($this->invoiceIdOf($type, $allocation)), $partyId, $allocation->amount);
                $total = Decimal::add($total, $allocation->amount);
            }

            if ($allocations->isEmpty() || Decimal::cmp($total, $locked->amount) !== 0) {
                throw new BusinessRuleException('Jumlah alokasi harus sama persis dengan jumlah pembayaran.', 422, 'allocations');
            }

            foreach ($allocations as $allocation) {
                $this->settlement->apply($invoices->firstOrFail('id', $this->invoiceIdOf($type, $allocation)), $allocation->amount);
            }

            $journal = $this->journals->postAutomatic(
                $type === PaymentType::RECEIPT
                    ? PaymentJournalBuilder::receipt($locked, $account->code)
                    : PaymentJournalBuilder::disbursement($locked, $account->code),
                Payment::JOURNAL_SOURCE,
                $locked->id,
                CarbonImmutable::parse($locked->payment_date)
            );

            $locked->status = PaymentStatus::POSTED;
            $locked->journal_id = $journal?->id;
            $locked->posted_by = $effectiveUserId;
            $locked->posted_at = now();

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            return $locked->load('allocations');
        });
    }

    /**
     * Void a draft without effect, or a posted payment by giving the money back and reversing its journal.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(Payment $payment, string $reason, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockPayment($payment);
            $type = $locked->payment_type;

            DocumentStateMachine::assertTransition($locked->status, PaymentStatus::VOID);

            $trimmedReason = trim($reason);

            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            if (mb_strlen($trimmedReason) > 255) {
                throw new BusinessRuleException('Alasan pembatalan maksimal 255 karakter.', 422, 'reason');
            }

            if ($locked->status === PaymentStatus::POSTED) {
                $allocations = PaymentAllocation::query()->where('payment_id', $locked->id)->orderBy('id')->get();
                $invoices = $this->lockInvoices($type, $allocations);

                foreach ($allocations as $allocation) {
                    $this->settlement->revert($invoices->firstOrFail('id', $this->invoiceIdOf($type, $allocation)), $allocation->amount);
                }

                $this->journals->reverseAutomatic(Payment::JOURNAL_SOURCE, $locked->id, now());
            }

            $locked->status = PaymentStatus::VOID;
            $locked->voided_by = $effectiveUserId;
            $locked->voided_at = now();
            $locked->void_reason = $trimmedReason;

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            return $locked->load('allocations');
        });
    }

    /**
     * Invoices the customer still owes on, oldest due date first.
     *
     * @return list<array{
     *     id: int,
     *     number: string,
     *     invoice_date: string,
     *     due_date: string,
     *     total: string,
     *     paid_amount: string,
     *     outstanding: string
     * }>
     */
    public function outstandingInvoices(Customer $customer): array
    {
        return SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::PAYABLE_INVOICE_STATUSES)
            ->whereColumn('total', '>', 'paid_amount')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SalesInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'total' => $invoice->total,
                'paid_amount' => $invoice->paid_amount,
                'outstanding' => $invoice->outstanding(),
            ])
            ->values()
            ->all();
    }

    /**
     * Purchase invoices still owed to the supplier, oldest due date first.
     *
     * @return list<array{
     *     id: int,
     *     number: string,
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     due_date: string,
     *     total: string,
     *     paid_amount: string,
     *     outstanding: string
     * }>
     */
    public function outstandingPurchaseInvoices(Supplier $supplier): array
    {
        return PurchaseInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', self::PAYABLE_INVOICE_STATUSES)
            ->whereColumn('total', '>', 'paid_amount')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'total' => $invoice->total,
                'paid_amount' => $invoice->paid_amount,
                'outstanding' => $invoice->outstanding(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cash and bank accounts a payment may use.
     *
     * @return Collection<int, ChartOfAccount>
     */
    public function cashBankAccounts(): Collection
    {
        return ChartOfAccount::query()
            ->select(['id', 'code', 'name'])
            ->whereIn('code', (array) config('accounting.cash_bank'))
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    private function createDraft(PaymentType $type, array $data, ?int $effectiveUserId): Payment
    {
        $fields = $this->validatedFields($type, $data);
        $allocations = $this->validatedAllocations($type, $this->partyIdFromFields($fields), $data['allocations'] ?? [], $fields['amount']);

        $payment = Payment::create([
            'number' => DocumentNumberService::generate(DocumentType::PAY, $fields['payment_date']),
            'payment_type' => $type,
            'status' => PaymentStatus::DRAFT,
            ...$fields,
            'created_by' => $effectiveUserId,
            'updated_by' => $effectiveUserId,
        ]);

        $this->writeAllocations($payment, $allocations);

        return $payment->load('allocations');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    private function updateDraft(PaymentType $type, Payment $payment, array $data, ?int $userId): Payment
    {
        return DB::transaction(function () use ($type, $payment, $data, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockPayment($payment);

            if ($locked->payment_type !== $type) {
                throw $this->wrongType($type);
            }

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf pembayaran yang dapat diubah.');
            }

            $fields = $this->validatedFields($type, $data);
            $allocations = $this->validatedAllocations($type, $this->partyIdFromFields($fields), $data['allocations'] ?? [], $fields['amount']);

            $locked->fill($fields);

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            // @audit-bypass: payment_allocations has no audit columns; the new lines are audited explicitly in writeAllocations
            PaymentAllocation::query()->where('payment_id', $locked->id)->delete();

            $this->writeAllocations($locked, $allocations);

            return $locked->load('allocations');
        });
    }

    private function lockPayment(Payment $payment): Payment
    {
        return Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return Collection<int, SalesInvoice>|Collection<int, PurchaseInvoice>
     */
    private function lockInvoices(PaymentType $type, Collection $allocations): Collection
    {
        $invoiceIds = $allocations->map(fn (PaymentAllocation $allocation): int => $this->invoiceIdOf($type, $allocation))->all();

        if ($type === PaymentType::RECEIPT) {
            return SalesInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        }

        return PurchaseInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @return Builder<SalesInvoice>|Builder<PurchaseInvoice>
     */
    private function invoiceQuery(PaymentType $type)
    {
        return $type === PaymentType::RECEIPT ? SalesInvoice::query() : PurchaseInvoice::query();
    }

    private function invoiceColumn(PaymentType $type): string
    {
        return $type === PaymentType::RECEIPT ? 'sales_invoice_id' : 'purchase_invoice_id';
    }

    private function invoiceIdOf(PaymentType $type, PaymentAllocation $allocation): int
    {
        return (int) $allocation->getAttribute($this->invoiceColumn($type));
    }

    private function partyIdOf(Payment $payment): int
    {
        return (int) ($payment->payment_type === PaymentType::RECEIPT ? $payment->customer_id : $payment->supplier_id);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function partyIdFromFields(array $fields): int
    {
        return (int) ($fields['customer_id'] ?? $fields['supplier_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     customer_id?: int,
     *     supplier_id?: int,
     *     payment_date: string,
     *     method: PaymentMethod,
     *     account_id: int,
     *     amount: string,
     *     reference: string|null,
     *     notes: string|null
     * }
     *
     * @throws BusinessRuleException
     */
    private function validatedFields(PaymentType $type, array $data): array
    {
        $partyColumn = $type === PaymentType::RECEIPT ? 'customer_id' : 'supplier_id';
        $partyId = (int) $data[$partyColumn];
        $this->assertPartyActive($type, $partyId);

        $paymentDate = CarbonImmutable::parse((string) $data['payment_date'])->startOfDay();

        if ($paymentDate->greaterThan(now()->startOfDay())) {
            throw new BusinessRuleException('Tanggal pembayaran tidak boleh di masa depan.', 422, 'payment_date');
        }

        $method = PaymentMethod::tryFrom((string) $data['method']);

        if ($method === null) {
            throw new BusinessRuleException('Metode pembayaran tidak dikenal.', 422, 'method');
        }

        $accountId = (int) $data['account_id'];
        $this->assertCashBankAccount($accountId);

        $amount = Decimal::round((string) $data['amount']);

        if (Decimal::cmp($amount, '0') <= 0) {
            throw new BusinessRuleException('Jumlah pembayaran harus lebih besar dari 0.', 422, 'amount');
        }

        $reference = $this->cleanText($data['reference'] ?? null);

        if ($reference !== null && mb_strlen($reference) > 100) {
            throw new BusinessRuleException('Referensi maksimal 100 karakter.', 422, 'reference');
        }

        return [
            $partyColumn => $partyId,
            'payment_date' => $paymentDate->toDateString(),
            'method' => $method,
            'account_id' => $accountId,
            'amount' => $amount,
            'reference' => $reference,
            'notes' => $this->cleanText($data['notes'] ?? null),
        ];
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $lines
     * @return array<int, string> amount per invoice, in request order
     *
     * @throws BusinessRuleException
     */
    private function validatedAllocations(PaymentType $type, int $partyId, iterable $lines, string $paymentAmount): array
    {
        $allocations = [];
        $total = '0.00';
        $column = $this->invoiceColumn($type);

        foreach ($lines as $line) {
            if (! isset($line[$column])) {
                throw new BusinessRuleException($type === PaymentType::RECEIPT
                    ? 'Penerimaan hanya dapat dialokasikan ke faktur penjualan.'
                    : 'Pembayaran ke pemasok hanya dapat dialokasikan ke faktur pembelian.', 422, 'allocations');
            }

            $invoiceId = (int) $line[$column];

            if (isset($allocations[$invoiceId])) {
                throw new BusinessRuleException('Satu faktur hanya boleh dialokasikan sekali per pembayaran.', 422, 'allocations');
            }

            $amount = Decimal::round((string) $line['amount']);
            $allocations[$invoiceId] = $amount;
            $total = Decimal::add($total, $amount);
        }

        if (Decimal::cmp($total, $paymentAmount) > 0) {
            throw new BusinessRuleException('Jumlah alokasi melebihi jumlah pembayaran.', 422, 'allocations');
        }

        $invoices = $this->invoiceQuery($type)->whereIn('id', array_keys($allocations))->get()->keyBy('id');

        foreach ($allocations as $invoiceId => $amount) {
            $this->assertAllocatable($type, $invoices->get($invoiceId), $partyId, $amount);
        }

        return $allocations;
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertAllocatable(PaymentType $type, SalesInvoice|PurchaseInvoice|null $invoice, int $partyId, string $amount): void
    {
        $owner = match (true) {
            $invoice instanceof SalesInvoice => $invoice->customer_id,
            $invoice instanceof PurchaseInvoice => $invoice->supplier_id,
            default => null,
        };

        if ($invoice === null || $owner !== $partyId) {
            throw new BusinessRuleException($type === PaymentType::RECEIPT
                ? 'Faktur yang dialokasikan harus milik pelanggan pembayaran ini.'
                : 'Faktur yang dialokasikan harus milik pemasok pembayaran ini.', 422, 'allocations');
        }

        if (! in_array($invoice->status->value, self::PAYABLE_INVOICE_STATUSES, true)) {
            throw new BusinessRuleException("Faktur {$invoice->number} tidak dapat menerima pembayaran pada statusnya saat ini.", 422, 'allocations');
        }

        if (Decimal::cmp($amount, '0') <= 0) {
            throw new BusinessRuleException("Alokasi untuk faktur {$invoice->number} harus lebih besar dari 0.", 422, 'allocations');
        }

        $outstanding = $invoice->outstanding();

        if (Decimal::cmp($amount, $outstanding) > 0) {
            $amountText = Decimal::trim($amount);
            $outstandingText = Decimal::trim($outstanding);

            throw new BusinessRuleException("Alokasi {$amountText} melebihi sisa tagihan {$outstandingText} pada faktur {$invoice->number}.", 422, 'allocations');
        }
    }

    /**
     * @param  array<int, string>  $allocations
     */
    private function writeAllocations(Payment $payment, array $allocations): void
    {
        $column = $this->invoiceColumn($payment->payment_type);

        foreach ($allocations as $invoiceId => $amount) {
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                $column => $invoiceId,
                'amount' => $amount,
            ]);
        }

        AuditLogger::record(
            event: 'payment.allocations_saved',
            auditableType: Payment::class,
            auditableId: $payment->id,
            new: ['allocations' => array_map(
                fn (int $invoiceId, string $amount): array => [$column => $invoiceId, 'amount' => $amount],
                array_keys($allocations),
                array_values($allocations)
            )],
            strict: true
        );
    }

    private function wrongType(PaymentType $expected): BusinessRuleException
    {
        return new BusinessRuleException(
            $expected === PaymentType::RECEIPT
                ? 'Operasi ini hanya untuk penerimaan dari pelanggan.'
                : 'Operasi ini hanya untuk pembayaran ke pemasok.',
            422,
            'payment_type'
        );
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertPartyActive(PaymentType $type, int $partyId): void
    {
        if ($type === PaymentType::RECEIPT) {
            if (! Customer::query()->whereKey($partyId)->where('is_active', true)->exists()) {
                throw new BusinessRuleException('Pelanggan tidak aktif atau tidak ditemukan.', 422, 'customer_id');
            }

            return;
        }

        if (! Supplier::query()->whereKey($partyId)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Pemasok tidak aktif atau tidak ditemukan.', 422, 'supplier_id');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCashBankAccount(int $accountId): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->whereKey($accountId)
            ->whereIn('code', (array) config('accounting.cash_bank'))
            ->where('is_active', true)
            ->where('is_postable', true)
            ->first();

        if ($account === null) {
            throw new BusinessRuleException('Akun harus berupa akun kas atau bank yang aktif.', 422, 'account_id');
        }

        return $account;
    }

    private function cleanText(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
