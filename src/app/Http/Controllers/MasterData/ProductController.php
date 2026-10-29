<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ProductStoreRequest;
use App\Http\Requests\MasterData\ProductUpdateRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Services\MasterData\ProductService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(Product::with(['category', 'baseUnit']))
            ->searchable(['sku', 'name', 'barcode'])
            ->sortable(['id', 'sku', 'name', 'selling_price', 'min_stock', 'track_stock', 'is_active', 'created_at'])
            ->filterable([
                'category_id' => 'category_id',
                'is_active' => 'is_active',
            ])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('MasterData/Products/Index', [
            'items' => Paginated::from($paginator, null, $request),
            'categories' => ProductCategory::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Products/Create', [
            'categories' => ProductCategory::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
            'units' => Unit::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(ProductStoreRequest $request): RedirectResponse
    {
        $this->productService->create($request->validated());

        return redirect()
            ->route('master.products.index')
            ->with('success', 'Produk berhasil ditambahkan.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('MasterData/Products/Edit', [
            'item' => $product,
            'categories' => ProductCategory::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
            'units' => Unit::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(ProductUpdateRequest $request, Product $product): RedirectResponse
    {
        $this->productService->update($product, $request->validated());

        return redirect()
            ->route('master.products.index')
            ->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        try {
            $this->productService->delete($product);

            return back()->with('success', 'Produk berhasil dihapus.');
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
