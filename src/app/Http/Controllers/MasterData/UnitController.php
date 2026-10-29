<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\UnitStoreRequest;
use App\Http\Requests\MasterData\UnitUpdateRequest;
use App\Models\Unit;
use App\Services\MasterData\UnitService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UnitController extends Controller
{
    public function __construct(
        private readonly UnitService $unitService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(Unit::query())
            ->searchable(['code', 'name'])
            ->sortable(['id', 'code', 'name', 'created_at'])
            ->defaultSort('code', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/Units/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Units/Create');
    }

    public function store(UnitStoreRequest $request): RedirectResponse
    {
        $this->unitService->create($request->validated());

        return redirect()
            ->route('master.units.index')
            ->with('success', 'Satuan ukuran berhasil ditambahkan.');
    }

    public function edit(Unit $unit): Response
    {
        return Inertia::render('MasterData/Units/Edit', [
            'item' => $unit,
        ]);
    }

    public function update(UnitUpdateRequest $request, Unit $unit): RedirectResponse
    {
        $this->unitService->update($unit, $request->validated());

        return redirect()
            ->route('master.units.index')
            ->with('success', 'Satuan ukuran berhasil diperbarui.');
    }

    public function destroy(Request $request, Unit $unit): JsonResponse|RedirectResponse
    {
        try {
            $this->unitService->delete($unit);

            return back()->with('success', 'Satuan ukuran berhasil dihapus.');
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
