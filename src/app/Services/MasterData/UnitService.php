<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\Unit;
use Illuminate\Support\Facades\DB;

final class UnitService
{
    /**
     * @param  array{code: string, name: string}  $data
     */
    public function create(array $data): Unit
    {
        return DB::transaction(function () use ($data): Unit {
            return Unit::create([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string}  $data
     */
    public function update(Unit $unit, array $data): Unit
    {
        return DB::transaction(function () use ($unit, $data): Unit {
            $unit->update([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            return $unit;
        });
    }

    public function delete(Unit $unit): void
    {
        DB::transaction(function () use ($unit): void {
            ReferenceGuard::assertCanDelete($unit, 'Satuan Ukuran');
            $unit->delete();
        });
    }
}
