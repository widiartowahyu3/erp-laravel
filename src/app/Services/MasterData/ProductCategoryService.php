<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

final class ProductCategoryService
{
    /**
     * @param  array{code: string, name: string, parent_id?: int|null}  $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data): ProductCategory
    {
        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $this->assertNoCyclesAndMaxDepth($parentId, null);

        return DB::transaction(function () use ($data, $parentId): ProductCategory {
            return ProductCategory::create([
                'parent_id' => $parentId,
                'code' => $data['code'],
                'name' => $data['name'],
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, parent_id?: int|null}  $data
     *
     * @throws BusinessRuleException
     */
    public function update(ProductCategory $category, array $data): ProductCategory
    {
        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $this->assertNoCyclesAndMaxDepth($parentId, (int) $category->id);

        return DB::transaction(function () use ($category, $data, $parentId): ProductCategory {
            // @audit-bypass: Audited via RecordsAudit model event
            $category->update([
                'parent_id' => $parentId,
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            return $category;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function delete(ProductCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            ReferenceGuard::assertCanDelete($category, 'Kategori Produk');
            // @audit-bypass: Audited via RecordsAudit model event
            $category->delete();
        });
    }

    /**
     * Enforce hierarchy constraints: prevent self-parenting, cycles, and depth > 5.
     *
     * @throws BusinessRuleException
     */
    public function assertNoCyclesAndMaxDepth(?int $parentId, ?int $categoryId = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($categoryId !== null && $parentId === $categoryId) {
            throw new BusinessRuleException('Kategori tidak boleh menjadi induk untuk dirinya sendiri.', 422);
        }

        $visited = $categoryId !== null ? [$categoryId => true] : [];
        $depth = 1;
        $currentId = $parentId;

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                throw new BusinessRuleException('Struktur hierarki kategori mengalami perulangan siklus (cycle).', 422);
            }
            $visited[$currentId] = true;
            $depth++;

            if ($depth > 5) {
                throw new BusinessRuleException('Kedalaman kategori maksimal 5 level hierarki.', 422);
            }

            /** @var int|null $currentId */
            $currentId = ProductCategory::where('id', $currentId)->value('parent_id');
        }
    }
}
