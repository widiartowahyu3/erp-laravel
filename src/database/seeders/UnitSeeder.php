<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $units = [
            ['code' => 'PCS', 'name' => 'Pcs', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BOX', 'name' => 'Box', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'KG', 'name' => 'Kilogram', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LTR', 'name' => 'Liter', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MTR', 'name' => 'Meter', 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('units')->upsert($units, ['code'], ['name', 'updated_at']);
    }
}
