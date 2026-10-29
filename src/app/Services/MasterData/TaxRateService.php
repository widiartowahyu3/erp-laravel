<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

final class TaxRateService
{
    /**
     * @param  array{code: string, name: string, rate: string|numeric, is_active?: bool}  $data
     */
    public function create(array $data): TaxRate
    {
        return DB::transaction(function () use ($data): TaxRate {
            return TaxRate::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'rate' => (string) $data['rate'],
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, rate: string|numeric, is_active?: bool}  $data
     */
    public function update(TaxRate $taxRate, array $data): TaxRate
    {
        return DB::transaction(function () use ($taxRate, $data): TaxRate {
            $taxRate->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'rate' => (string) $data['rate'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $taxRate;
        });
    }

    public function delete(TaxRate $taxRate): void
    {
        DB::transaction(function () use ($taxRate): void {
            ReferenceGuard::assertCanDelete($taxRate, 'Tarif Pajak');
            $taxRate->delete();
        });
    }
}
