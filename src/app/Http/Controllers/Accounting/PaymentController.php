<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PaymentStoreRequest;
use App\Http\Requests\Accounting\PaymentUpdateRequest;
use App\Http\Requests\Accounting\PaymentVoidRequest;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Accounting\PaymentService;
use App\Services\Reports\ListExports;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(Payment::query()->with(['customer:id,code,name', 'supplier:id,code,name', 'account:id,code,name']))
            ->searchable(['number', 'reference'])
            ->sortable(['id', 'number', 'payment_date', 'status', 'amount', 'created_at'])
            ->filterable([
                'payment_type' => 'payment_type',
                'status' => 'status',
                'customer_id' => 'customer_id',
                'supplier_id' => 'supplier_id',
                'method' => 'method',
                'date_from' => function (Builder $query, mixed $value): void {
                    $query->where('payment_date', '>=', $value);
                },
                'date_to' => function (Builder $query, mixed $value): void {
                    $query->where('payment_date', '<=', $value);
                },
            ])
            ->defaultSort('payment_date', 'desc');

        if (ListExports::requested($request)) {
            return $exports->payments($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Accounting/Payments/Index', [
            'items' => Paginated::from($paginator, fn (Payment $payment): array => [
                'id' => $payment->id,
                'number' => $payment->number,
                'paymentDate' => $payment->payment_date->toDateString(),
                'paymentType' => $payment->payment_type->value,
                'customer' => $payment->customer === null ? null : [
                    'id' => $payment->customer->id,
                    'code' => $payment->customer->code,
                    'name' => $payment->customer->name,
                ],
                'supplier' => $payment->supplier === null ? null : [
                    'id' => $payment->supplier->id,
                    'code' => $payment->supplier->code,
                    'name' => $payment->supplier->name,
                ],
                'method' => $payment->method->value,
                'methodLabel' => $payment->method->label(),
                'account' => $this->accountProps($payment->account),
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
            ], $request),
            'customers' => $this->activeCustomers(),
            'suppliers' => $this->activeSuppliers(),
            'methods' => $this->methodOptions(),
            'statuses' => array_map(fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()),
        ]);
    }

    /**
     * The form reloads this route with customer_id or supplier_id, as a partial Inertia visit, to get that party's open invoices.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/Payments/Create', [
            'paymentType' => $request->query('payment_type') === PaymentType::DISBURSEMENT->value
                ? PaymentType::DISBURSEMENT->value
                : PaymentType::RECEIPT->value,
            ...$this->formOptions(),
            ...$this->outstandingProps($this->requestedId($request, 'customer_id')),
            ...$this->outstandingPurchaseProps($this->requestedId($request, 'supplier_id')),
            'defaults' => ['paymentDate' => now()->toDateString()],
        ]);
    }

    public function store(PaymentStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $payment = ($data['payment_type'] ?? null) === PaymentType::DISBURSEMENT->value
            ? $this->paymentService->createDisbursement($data)
            : $this->paymentService->createReceipt($data);

        return redirect()
            ->route('accounting.payments.show', $payment)
            ->with('success', 'Pembayaran berhasil dibuat.');
    }

    public function show(Payment $payment): Response
    {
        $payment->load([
            'customer:id,code,name',
            'supplier:id,code,name',
            'account:id,code,name',
            'allocations.salesInvoice:id,number',
            'allocations.purchaseInvoice:id,number',
            'creator:id,name',
            'poster:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Accounting/Payments/Show', [
            'item' => [
                ...$this->headerProps($payment),
                'typeLabel' => $payment->payment_type->label(),
                'status' => $payment->status->value,
                'customer' => $payment->customer === null ? null : [
                    'id' => $payment->customer->id,
                    'code' => $payment->customer->code,
                    'name' => $payment->customer->name,
                ],
                'supplier' => $payment->supplier === null ? null : [
                    'id' => $payment->supplier->id,
                    'code' => $payment->supplier->code,
                    'name' => $payment->supplier->name,
                ],
                'methodLabel' => $payment->method->label(),
                'account' => $this->accountProps($payment->account),
                'creator' => $payment->creator === null ? null : ['id' => $payment->creator->id, 'name' => $payment->creator->name],
                'poster' => $payment->poster === null ? null : ['id' => $payment->poster->id, 'name' => $payment->poster->name],
                'voider' => $payment->voider === null ? null : ['id' => $payment->voider->id, 'name' => $payment->voider->name],
                'postedAt' => $payment->posted_at?->toIso8601String(),
                'voidedAt' => $payment->voided_at?->toIso8601String(),
                'voidReason' => $payment->void_reason,
                'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'salesInvoiceId' => $allocation->sales_invoice_id,
                    'purchaseInvoiceId' => $allocation->purchase_invoice_id,
                    'invoiceNumber' => $allocation->salesInvoice->number ?? $allocation->purchaseInvoice->number ?? null,
                    'amount' => $allocation->amount,
                ])->values()->all(),
            ],
            'journal' => JournalSourceResolver::related(Payment::JOURNAL_SOURCE, $payment->id),
        ]);
    }

    public function edit(Request $request, Payment $payment): Response|RedirectResponse
    {
        if (! $payment->isEditable()) {
            return redirect()
                ->route('accounting.payments.show', $payment)
                ->with('error', 'Hanya draf pembayaran yang dapat diubah.');
        }

        $payment->load('allocations');

        return Inertia::render('Accounting/Payments/Edit', [
            'paymentType' => $payment->payment_type->value,
            ...$this->formOptions(),
            ...$this->outstandingProps($this->requestedId($request, 'customer_id') ?? $payment->customer_id),
            ...$this->outstandingPurchaseProps($this->requestedId($request, 'supplier_id') ?? $payment->supplier_id),
            'item' => [
                ...$this->headerProps($payment),
                'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                    'salesInvoiceId' => $allocation->sales_invoice_id,
                    'purchaseInvoiceId' => $allocation->purchase_invoice_id,
                    'amount' => $allocation->amount,
                ])->values()->all(),
            ],
        ]);
    }

    public function update(PaymentUpdateRequest $request, Payment $payment): RedirectResponse
    {
        $updated = $payment->payment_type === PaymentType::DISBURSEMENT
            ? $this->paymentService->updateDisbursement($payment, $request->validated())
            : $this->paymentService->updateReceipt($payment, $request->validated());

        return redirect()
            ->route('accounting.payments.show', $updated)
            ->with('success', 'Pembayaran berhasil diperbarui.');
    }

    public function post(Payment $payment): RedirectResponse
    {
        $this->paymentService->post($payment);

        return back()->with('success', 'Pembayaran berhasil diposting dan jurnal telah dibuat.');
    }

    public function void(PaymentVoidRequest $request, Payment $payment): RedirectResponse
    {
        $this->paymentService->void($payment, (string) $request->input('reason'));

        return back()->with('success', 'Pembayaran berhasil dibatalkan.');
    }

    private function requestedId(Request $request, string $key): ?int
    {
        $id = $request->query($key);

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => $this->activeCustomers(),
            'suppliers' => $this->activeSuppliers(),
            'cashBankAccounts' => $this->paymentService->cashBankAccounts()
                ->map(fn (ChartOfAccount $account): array => $this->accountProps($account))
                ->values()
                ->all(),
            'methods' => $this->methodOptions(),
        ];
    }

    /**
     * @return array{selectedCustomerId: int|null, outstandingInvoices: list<array<string, mixed>>}
     */
    private function outstandingProps(?int $customerId): array
    {
        $customer = $customerId === null
            ? null
            : Customer::query()->whereKey($customerId)->where('is_active', true)->first();

        if ($customer === null) {
            return ['selectedCustomerId' => null, 'outstandingInvoices' => []];
        }

        return [
            'selectedCustomerId' => $customer->id,
            'outstandingInvoices' => array_map(fn (array $invoice): array => [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'invoiceDate' => $invoice['invoice_date'],
                'dueDate' => $invoice['due_date'],
                'total' => $invoice['total'],
                'paidAmount' => $invoice['paid_amount'],
                'outstanding' => $invoice['outstanding'],
            ], $this->paymentService->outstandingInvoices($customer)),
        ];
    }

    /**
     * @return array{selectedSupplierId: int|null, outstandingPurchaseInvoices: list<array<string, mixed>>}
     */
    private function outstandingPurchaseProps(?int $supplierId): array
    {
        $supplier = $supplierId === null
            ? null
            : Supplier::query()->whereKey($supplierId)->where('is_active', true)->first();

        if ($supplier === null) {
            return ['selectedSupplierId' => null, 'outstandingPurchaseInvoices' => []];
        }

        return [
            'selectedSupplierId' => $supplier->id,
            'outstandingPurchaseInvoices' => array_map(fn (array $invoice): array => [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'supplierInvoiceNumber' => $invoice['supplier_invoice_number'],
                'invoiceDate' => $invoice['invoice_date'],
                'dueDate' => $invoice['due_date'],
                'total' => $invoice['total'],
                'paidAmount' => $invoice['paid_amount'],
                'outstanding' => $invoice['outstanding'],
            ], $this->paymentService->outstandingPurchaseInvoices($supplier)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerProps(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'paymentType' => $payment->payment_type->value,
            'customerId' => $payment->customer_id,
            'supplierId' => $payment->supplier_id,
            'paymentDate' => $payment->payment_date->toDateString(),
            'method' => $payment->method->value,
            'accountId' => $payment->account_id,
            'amount' => $payment->amount,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}
     */
    private function accountProps(ChartOfAccount $account): array
    {
        return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name];
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function activeCustomers(): array
    {
        return Customer::query()
            ->select(['id', 'code', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function activeSuppliers(): array
    {
        return Supplier::query()
            ->select(['id', 'code', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function methodOptions(): array
    {
        return array_map(
            fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
            PaymentMethod::cases()
        );
    }
}
