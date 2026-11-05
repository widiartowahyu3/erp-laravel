<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Services\Accounting\Builders\PaymentJournalBuilder;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Services\Sales\InvoiceSettlement;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts and their allocation to sales invoices. Authorization lives in the routes and requests.
 *
 * Lock order (docs/ACCOUNTING.md): payment, invoices by ascending id, document sequence, journal.
 * Drafts reserve nothing, so every rule is checked again under lock when posting.
 */
class PaymentService
{
    /**
     * @var list<SalesInvoiceStatus>
     */
    private const array PAYABLE_INVOICE_STATUSES = [
        SalesInvoiceStatus::POSTED,
        SalesInvoiceStatus::PARTIALLY_PAID,
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
                throw $this->onlyReceipts();
            }

            $fields = $this->validatedFields($data);
            $allocations = $this->validatedAllocations($fields['customer_id'], $data['allocations'] ?? [], $fields['amount']);

            $payment = Payment::create([
                'number' => DocumentNumberService::generate(DocumentType::PAY, $fields['payment_date']),
                'payment_type' => PaymentType::RECEIPT,
                'status' => PaymentStatus::DRAFT,
                ...$fields,
                'created_by' => $effectiveUserId,
                'updated_by' => $effectiveUserId,
            ]);

            $this->writeAllocations($payment, $allocations);

            return $payment->load('allocations');
        });
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
        return DB::transaction(function () use ($payment, $data, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockPayment($payment);

            $this->assertReceipt($locked);

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf pembayaran yang dapat diubah.');
            }

            $fields = $this->validatedFields($data);
            $allocations = $this->validatedAllocations($fields['customer_id'], $data['allocations'] ?? [], $fields['amount']);

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

    /**
     * Post a draft: settles its invoices and writes journal JT-PAYR, all or nothing.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(Payment $payment, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($payment, $userId): Payment {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockPayment($payment);

            $this->assertReceipt($locked);
            DocumentStateMachine::assertTransition($locked->status, PaymentStatus::POSTED);

            $this->assertCustomerActive((int) $locked->customer_id);
            $account = $this->assertCashBankAccount($locked->account_id);

            $allocations = PaymentAllocation::query()->where('payment_id', $locked->id)->orderBy('id')->get();
            $invoices = $this->lockInvoices($allocations);

            $total = '0.00';

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get((int) $allocation->sales_invoice_id);

                $this->assertAllocatable($invoice, (int) $locked->customer_id, $allocation->amount);
                $total = Decimal::add($total, $allocation->amount);
            }

            if ($allocations->isEmpty() || Decimal::cmp($total, $locked->amount) !== 0) {
                throw new BusinessRuleException('Jumlah alokasi harus sama persis dengan jumlah pembayaran.', 422, 'allocations');
            }

            foreach ($allocations as $allocation) {
                $this->settlement->apply($invoices->firstOrFail('id', (int) $allocation->sales_invoice_id), $allocation->amount);
            }

            $journal = $this->journals->postAutomatic(
                PaymentJournalBuilder::receipt($locked, $account->code),
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

            $this->assertReceipt($locked);
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
                $invoices = $this->lockInvoices($allocations);

                foreach ($allocations as $allocation) {
                    $this->settlement->revert($invoices->firstOrFail('id', (int) $allocation->sales_invoice_id), $allocation->amount);
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

    private function lockPayment(Payment $payment): Payment
    {
        return Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return Collection<int, SalesInvoice>
     */
    private function lockInvoices(Collection $allocations): Collection
    {
        return SalesInvoice::query()
            ->whereIn('id', $allocations->pluck('sales_invoice_id')->filter()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     customer_id: int,
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
    private function validatedFields(array $data): array
    {
        $customerId = (int) $data['customer_id'];
        $this->assertCustomerActive($customerId);

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
            'customer_id' => $customerId,
            'payment_date' => $paymentDate->toDateString(),
            'method' => $method,
            'account_id' => $accountId,
            'amount' => $amount,
            'reference' => $reference,
            'notes' => $this->cleanText($data['notes'] ?? null),
        ];
    }

    /**
     * @param  iterable<int, array{sales_invoice_id: int|string, amount: string}>  $lines
     * @return array<int, string> amount per invoice, in request order
     *
     * @throws BusinessRuleException
     */
    private function validatedAllocations(int $customerId, iterable $lines, string $paymentAmount): array
    {
        $allocations = [];
        $total = '0.00';

        foreach ($lines as $line) {
            $invoiceId = (int) $line['sales_invoice_id'];

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

        $invoices = SalesInvoice::query()->whereIn('id', array_keys($allocations))->get()->keyBy('id');

        foreach ($allocations as $invoiceId => $amount) {
            $this->assertAllocatable($invoices->get($invoiceId), $customerId, $amount);
        }

        return $allocations;
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertAllocatable(?SalesInvoice $invoice, int $customerId, string $amount): void
    {
        if ($invoice === null || $invoice->customer_id !== $customerId) {
            throw new BusinessRuleException('Faktur yang dialokasikan harus milik pelanggan pembayaran ini.', 422, 'allocations');
        }

        if (! in_array($invoice->status, self::PAYABLE_INVOICE_STATUSES, true)) {
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
        foreach ($allocations as $invoiceId => $amount) {
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'sales_invoice_id' => $invoiceId,
                'amount' => $amount,
            ]);
        }

        AuditLogger::record(
            event: 'payment.allocations_saved',
            auditableType: Payment::class,
            auditableId: $payment->id,
            new: ['allocations' => array_map(
                fn (int $invoiceId, string $amount): array => ['sales_invoice_id' => $invoiceId, 'amount' => $amount],
                array_keys($allocations),
                array_values($allocations)
            )],
            strict: true
        );
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertReceipt(Payment $payment): void
    {
        if ($payment->payment_type !== PaymentType::RECEIPT) {
            throw $this->onlyReceipts();
        }
    }

    private function onlyReceipts(): BusinessRuleException
    {
        return new BusinessRuleException('Pembayaran pengeluaran ke pemasok belum tersedia.', 422, 'payment_type');
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCustomerActive(int $customerId): void
    {
        $active = Customer::query()->whereKey($customerId)->where('is_active', true)->exists();

        if (! $active) {
            throw new BusinessRuleException('Pelanggan tidak aktif atau tidak ditemukan.', 422, 'customer_id');
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
