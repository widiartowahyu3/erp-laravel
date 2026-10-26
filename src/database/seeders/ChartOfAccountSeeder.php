<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $headers = [
            ['code' => '1000', 'name' => 'ASET', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '2000', 'name' => 'LIABILITAS', 'type' => 'liability', 'normal_balance' => 'credit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3000', 'name' => 'EKUITAS', 'type' => 'equity', 'normal_balance' => 'credit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4000', 'name' => 'PENDAPATAN', 'type' => 'revenue', 'normal_balance' => 'credit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '5000', 'name' => 'BEBAN POKOK', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '6000', 'name' => 'BEBAN', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => null, 'is_postable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('chart_of_accounts')->upsert($headers, ['code'], ['name', 'type', 'normal_balance', 'is_postable', 'is_active', 'updated_at']);

        // Fetch header IDs to map parents idempotently
        $headerIds = DB::table('chart_of_accounts')
            ->whereIn('code', ['1000', '2000', '3000', '4000', '5000', '6000'])
            ->pluck('id', 'code');

        $accounts = [
            // Under 1000 ASET
            ['code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $headerIds['1000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $headerIds['1000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1201', 'name' => 'Piutang Usaha', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $headerIds['1000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1301', 'name' => 'Persediaan Barang Dagang', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $headerIds['1000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1401', 'name' => 'PPN Masukan', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $headerIds['1000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Under 2000 LIABILITAS
            ['code' => '2101', 'name' => 'Utang Usaha', 'type' => 'liability', 'normal_balance' => 'credit', 'parent_id' => $headerIds['2000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '2201', 'name' => 'PPN Keluaran', 'type' => 'liability', 'normal_balance' => 'credit', 'parent_id' => $headerIds['2000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Under 3000 EKUITAS
            ['code' => '3101', 'name' => 'Modal Pemilik', 'type' => 'equity', 'normal_balance' => 'credit', 'parent_id' => $headerIds['3000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3201', 'name' => 'Laba Ditahan', 'type' => 'equity', 'normal_balance' => 'credit', 'parent_id' => $headerIds['3000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Under 4000 PENDAPATAN
            ['code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue', 'normal_balance' => 'credit', 'parent_id' => $headerIds['4000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4201', 'name' => 'Potongan Penjualan', 'type' => 'revenue', 'normal_balance' => 'debit', 'parent_id' => $headerIds['4000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Under 5000 BEBAN POKOK
            ['code' => '5101', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $headerIds['5000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '5201', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $headerIds['5000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Under 6000 BEBAN
            ['code' => '6101', 'name' => 'Beban Operasional', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $headerIds['6000'] ?? null, 'is_postable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('chart_of_accounts')->upsert($accounts, ['code'], ['name', 'type', 'normal_balance', 'parent_id', 'is_postable', 'is_active', 'updated_at']);
    }
}
