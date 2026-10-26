<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $terms = [
            ['code' => 'CASH', 'name' => 'Tunai', 'days' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET7', 'name' => 'Net 7 Hari', 'days' => 7, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET14', 'name' => 'Net 14 Hari', 'days' => 14, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET30', 'name' => 'Net 30 Hari', 'days' => 30, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('payment_terms')->upsert($terms, ['code'], ['name', 'days', 'updated_at']);
    }
}
