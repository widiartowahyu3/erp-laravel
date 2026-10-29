<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\WarehouseStoreRequest;
use App\Http\Requests\MasterData\WarehouseUpdateRequest;
use App\Models\Warehouse;
use App\Services\MasterData\WarehouseService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WarehouseController extends Controller
{
    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(Warehouse::query())
            ->searchable(['code', 'name', 'address'])
            ->sortable(['id', 'code', 'name', 'is_active', 'created_at'])
            ->filterable([
                'is_active' => 'is_active',
            ])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/Warehouses/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Warehouses/Create');
    }

    public function store(WarehouseStoreRequest $request): RedirectResponse
    {
        $this->warehouseService->create($request->validated());

        return redirect()
            ->route('master.warehouses.index')
            ->with('success', 'Gudang berhasil ditambahkan.');
    }

    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('MasterData/Warehouses/Edit', [
            'item' => $warehouse,
        ]);
    }

    public function update(WarehouseUpdateRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->warehouseService->update($warehouse, $request->validated());

        return redirect()
            ->route('master.warehouses.index')
            ->with('success', 'Gudang berhasil diperbarui.');
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse|RedirectResponse
    {
        try {
            $this->warehouseService->delete($warehouse);

            return back()->with('success', 'Gudang berhasil dihapus.');
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
