<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class WarehouseService
{
    /**
     * @param  array{code: string, name: string, address?: string|null, is_active?: bool}  $data
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            return Warehouse::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, address?: string|null, is_active?: bool}  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            $warehouse->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse): void {
            ReferenceGuard::assertCanDelete($warehouse, 'Gudang');
            $warehouse->delete();
        });
    }
}
