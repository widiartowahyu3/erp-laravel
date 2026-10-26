<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $rates = [
            [
                'code' => 'NOL',
                'name' => 'Tanpa Pajak',
                'rate' => 0.00,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('tax_rates')->upsert($rates, ['code'], ['name', 'rate', 'is_active', 'updated_at']);
    }
}
