<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\CustomerStoreRequest;
use App\Http\Requests\MasterData\CustomerUpdateRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Services\MasterData\CustomerService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(Customer::with('paymentTerm'))
            ->searchable(['code', 'name', 'contact_name', 'phone', 'email', 'tax_id'])
            ->sortable(['id', 'code', 'name', 'credit_limit', 'is_active', 'created_at'])
            ->filterable([
                'is_active' => 'is_active',
            ])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/Customers/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Customers/Create', [
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'name', 'code', 'days'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(CustomerStoreRequest $request): RedirectResponse
    {
        $this->customerService->create($request->validated());

        return redirect()
            ->route('master.customers.index')
            ->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('MasterData/Customers/Edit', [
            'item' => $customer,
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'name', 'code', 'days'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): RedirectResponse
    {
        $this->customerService->update($customer, $request->validated());

        return redirect()
            ->route('master.customers.index')
            ->with('success', 'Pelanggan berhasil diperbarui.');
    }

    public function destroy(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        try {
            $this->customerService->delete($customer);

            return back()->with('success', 'Pelanggan berhasil dihapus.');
        } catch (BusinessRuleException $e) {
            $message = 'Tidak dapat menghapus data: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['form' => [$message]],
                ], 409);
            }

            return back()->with('error', $message)->setStatusCode(409);
        }
    }
}
