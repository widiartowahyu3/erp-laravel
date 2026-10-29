<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\SupplierStoreRequest;
use App\Http\Requests\MasterData\SupplierUpdateRequest;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Services\MasterData\SupplierService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $supplierService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(Supplier::with('paymentTerm'))
            ->searchable(['code', 'name', 'contact_name', 'phone', 'email', 'tax_id'])
            ->sortable(['id', 'code', 'name', 'is_active', 'created_at'])
            ->filterable([
                'is_active' => 'is_active',
            ])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/Suppliers/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Suppliers/Create', [
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'name', 'code', 'days'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(SupplierStoreRequest $request): RedirectResponse
    {
        $this->supplierService->create($request->validated());

        return redirect()
            ->route('master.suppliers.index')
            ->with('success', 'Pemasok berhasil ditambahkan.');
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('MasterData/Suppliers/Edit', [
            'item' => $supplier,
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'name', 'code', 'days'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(SupplierUpdateRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->supplierService->update($supplier, $request->validated());

        return redirect()
            ->route('master.suppliers.index')
            ->with('success', 'Pemasok berhasil diperbarui.');
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse|RedirectResponse
    {
        try {
            $this->supplierService->delete($supplier);

            return back()->with('success', 'Pemasok berhasil dihapus.');
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
