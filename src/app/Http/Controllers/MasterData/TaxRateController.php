<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\TaxRateStoreRequest;
use App\Http\Requests\MasterData\TaxRateUpdateRequest;
use App\Models\TaxRate;
use App\Services\MasterData\TaxRateService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TaxRateController extends Controller
{
    public function __construct(
        private readonly TaxRateService $taxRateService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(TaxRate::query())
            ->searchable(['code', 'name'])
            ->sortable(['id', 'code', 'name', 'rate', 'is_active', 'created_at'])
            ->filterable([
                'is_active' => 'is_active',
            ])
            ->defaultSort('code', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/TaxRates/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/TaxRates/Create');
    }

    public function store(TaxRateStoreRequest $request): RedirectResponse
    {
        $this->taxRateService->create($request->validated());

        return redirect()
            ->route('master.tax-rates.index')
            ->with('success', 'Tarif pajak berhasil ditambahkan.');
    }

    public function edit(TaxRate $taxRate): Response
    {
        return Inertia::render('MasterData/TaxRates/Edit', [
            'item' => $taxRate,
        ]);
    }

    public function update(TaxRateUpdateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $this->taxRateService->update($taxRate, $request->validated());

        return redirect()
            ->route('master.tax-rates.index')
            ->with('success', 'Tarif pajak berhasil diperbarui.');
    }

    public function destroy(Request $request, TaxRate $taxRate): JsonResponse|RedirectResponse
    {
        try {
            $this->taxRateService->delete($taxRate);

            return back()->with('success', 'Tarif pajak berhasil dihapus.');
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
