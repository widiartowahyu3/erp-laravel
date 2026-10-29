<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    /**
     * @param  array{sku: string, name: string, category_id: int|string, base_unit_id: int|string, barcode?: string|null, description?: string|null, track_stock?: bool, selling_price: string|numeric, min_stock: string|numeric, is_active?: bool}  $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            return Product::create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => (int) $data['category_id'],
                'base_unit_id' => (int) $data['base_unit_id'],
                'barcode' => isset($data['barcode']) && $data['barcode'] !== '' ? $data['barcode'] : null,
                'description' => $data['description'] ?? null,
                'track_stock' => $data['track_stock'] ?? true,
                'selling_price' => (string) $data['selling_price'],
                'min_stock' => (string) $data['min_stock'],
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array{sku: string, name: string, category_id: int|string, base_unit_id: int|string, barcode?: string|null, description?: string|null, track_stock?: bool, selling_price: string|numeric, min_stock: string|numeric, is_active?: bool}  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            // @audit-bypass: Audited via RecordsAudit model event
            $product->update([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => (int) $data['category_id'],
                'base_unit_id' => (int) $data['base_unit_id'],
                'barcode' => isset($data['barcode']) && $data['barcode'] !== '' ? $data['barcode'] : null,
                'description' => $data['description'] ?? null,
                'track_stock' => $data['track_stock'] ?? true,
                'selling_price' => (string) $data['selling_price'],
                'min_stock' => (string) $data['min_stock'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            ReferenceGuard::assertCanDelete($product, 'Produk');
            // @audit-bypass: Audited via RecordsAudit model event
            $product->delete();
        });
    }
}
