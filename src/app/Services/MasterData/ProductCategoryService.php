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
     * Enforce hierarchy constraints: prevent self-parenting, cycles, and total depth > 5.
     * Total depth = parentChainDepth + subtreeHeight <= 5.
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

        $parentDepth = $this->getParentChainDepth($parentId, $categoryId);
        $subtreeHeight = $categoryId !== null ? $this->getSubtreeHeight($categoryId) : 1;

        if ($parentDepth + $subtreeHeight > 5) {
            throw new BusinessRuleException('Kedalaman kategori maksimal 5 level hierarki.', 422);
        }
    }

    /**
     * Calculate depth of parent chain up to root (number of ancestor nodes inclusive of parentId).
     *
     * @throws BusinessRuleException
     */
    public function getParentChainDepth(int $parentId, ?int $categoryId = null): int
    {
        $visited = $categoryId !== null ? [$categoryId => true] : [];
        $depth = 0;
        /** @var int|null $currentId */
        $currentId = $parentId;

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                throw new BusinessRuleException('Struktur hierarki kategori mengalami perulangan siklus (cycle).', 422);
            }
            $visited[$currentId] = true;
            $depth++;

            /** @var int|null $currentId */
            $currentId = ProductCategory::where('id', $currentId)->value('parent_id');
        }

        return $depth;
    }

    /**
     * Calculate the maximum height of the subtree rooted at categoryId.
     * A leaf node has height 1.
     *
     * @param  array<int, bool>  $visited
     *
     * @throws BusinessRuleException
     */
    public function getSubtreeHeight(int $categoryId, array &$visited = []): int
    {
        if (isset($visited[$categoryId])) {
            throw new BusinessRuleException('Struktur hierarki kategori mengalami perulangan siklus (cycle).', 422);
        }
        $visited[$categoryId] = true;

        /** @var list<int> $childrenIds */
        $childrenIds = ProductCategory::where('parent_id', $categoryId)->pluck('id')->all();
        if (empty($childrenIds)) {
            return 1;
        }

        $maxChildHeight = 0;
        foreach ($childrenIds as $childId) {
            $childHeight = $this->getSubtreeHeight($childId, $visited);
            if ($childHeight > $maxChildHeight) {
                $maxChildHeight = $childHeight;
            }
        }

        return 1 + $maxChildHeight;
    }
}
