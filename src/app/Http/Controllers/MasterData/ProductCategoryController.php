<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ProductCategoryStoreRequest;
use App\Http\Requests\MasterData\ProductCategoryUpdateRequest;
use App\Models\ProductCategory;
use App\Services\MasterData\ProductCategoryService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductCategoryController extends Controller
{
    public function __construct(
        private readonly ProductCategoryService $categoryService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(ProductCategory::with('parent'))
            ->searchable(['code', 'name'])
            ->sortable(['id', 'code', 'name', 'created_at'])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/ProductCategories/Index', [
            'items' => Paginated::from($paginator, null, $request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/ProductCategories/Create', [
            'parentCategories' => ProductCategory::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(ProductCategoryStoreRequest $request): RedirectResponse
    {
        $this->categoryService->create($request->validated());

        return redirect()
            ->route('master.product-categories.index')
            ->with('success', 'Kategori produk berhasil ditambahkan.');
    }

    public function edit(ProductCategory $productCategory): Response
    {
        return Inertia::render('MasterData/ProductCategories/Edit', [
            'item' => $productCategory,
            'parentCategories' => ProductCategory::query()
                ->where('id', '!=', $productCategory->id)
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(ProductCategoryUpdateRequest $request, ProductCategory $productCategory): RedirectResponse
    {
        $this->categoryService->update($productCategory, $request->validated());

        return redirect()
            ->route('master.product-categories.index')
            ->with('success', 'Kategori produk berhasil diperbarui.');
    }

    public function destroy(Request $request, ProductCategory $productCategory): JsonResponse|RedirectResponse
    {
        try {
            $this->categoryService->delete($productCategory);

            return back()->with('success', 'Kategori produk berhasil dihapus.');
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
