<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoMasterDataSeeder extends Seeder
{
    /**
     * Run the demo database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoMasterDataSeeder tidak boleh dijalankan di lingkungan production!');
        }

        $now = now();

        // 1. Units
        $units = [
            ['code' => 'PCS', 'name' => 'Pieces', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BOX', 'name' => 'Box / Kotak', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CTN', 'name' => 'Karton (Carton)', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'KG', 'name' => 'Kilogram', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LTR', 'name' => 'Liter', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DZN', 'name' => 'Lusin (Dozen)', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PACK', 'name' => 'Paket (Pack)', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ROLL', 'name' => 'Gulungan (Roll)', 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('units')->upsert($units, ['code'], ['name', 'updated_at']);
        $unitMap = DB::table('units')->pluck('id', 'code')->all();

        // 2. Payment Terms
        $terms = [
            ['code' => 'COD', 'name' => 'Bayar di Tempat (Cash on Delivery)', 'days' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET7', 'name' => 'Tempo 7 Hari', 'days' => 7, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET14', 'name' => 'Tempo 14 Hari', 'days' => 14, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET30', 'name' => 'Tempo 30 Hari', 'days' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET60', 'name' => 'Tempo 60 Hari', 'days' => 60, 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('payment_terms')->upsert($terms, ['code'], ['name', 'days', 'updated_at']);
        $termMap = DB::table('payment_terms')->pluck('id', 'code')->all();

        // 3. Tax Rates
        $taxRates = [
            ['code' => 'NONPAJAK', 'name' => 'Non-Pajak (0%)', 'rate' => '0.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PPN11', 'name' => 'PPN 11%', 'rate' => '11.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PPN12', 'name' => 'PPN 12%', 'rate' => '12.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PPH23', 'name' => 'PPh 23 Jasa (2%)', 'rate' => '2.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('tax_rates')->upsert($taxRates, ['code'], ['name', 'rate', 'is_active', 'updated_at']);

        // 4. Warehouses
        $warehouses = [
            ['code' => 'GD-UTAMA', 'name' => 'Gudang Distribusi Pusat', 'address' => 'Jl. Industri Raya No. 45, Kawasan Industri Jababeka, Cikarang', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GD-TRANSIT', 'name' => 'Gudang Transit Logistik', 'address' => 'Jl. Pelabuhan Tanjung Priok Blok B7, Jakarta Utara', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GD-RETUR', 'name' => 'Gudang Karantina & Retur', 'address' => 'Jl. Daan Mogot Km 19 No. 12, Tangerang', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('warehouses')->upsert($warehouses, ['code'], ['name', 'address', 'is_active', 'updated_at']);

        // 5. Product Categories (Hierarchy up to 2-3 levels)
        $parentCategories = [
            ['code' => 'FNB', 'name' => 'Makanan & Minuman', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ELK', 'name' => 'Elektronik & Perangkat', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ATK', 'name' => 'Alat Tulis & Kantor', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('product_categories')->upsert($parentCategories, ['code'], ['name', 'parent_id', 'updated_at']);
        $catMap = DB::table('product_categories')->pluck('id', 'code')->all();

        $subCategories = [
            ['code' => 'FNB-BEV', 'name' => 'Minuman Kemasan', 'parent_id' => $catMap['FNB'], 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FNB-SNK', 'name' => 'Makanan Ringan & Biskuit', 'parent_id' => $catMap['FNB'], 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ELK-ACC', 'name' => 'Aksesoris Komputer', 'parent_id' => $catMap['ELK'], 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ATK-PPR', 'name' => 'Kertas & Pembukuan', 'parent_id' => $catMap['ATK'], 'created_at' => $now, 'updated_at' => $now],
        ];
        DB::table('product_categories')->upsert($subCategories, ['code'], ['name', 'parent_id', 'updated_at']);

        // Re-read category map with subcategories
        $catMap = DB::table('product_categories')->pluck('id', 'code')->all();

        // 6. Products
        $products = [
            [
                'sku' => 'PRD-BEV-001',
                'name' => 'Kopi Arabika Toraja 250g',
                'category_id' => $catMap['FNB-BEV'],
                'base_unit_id' => $unitMap['PACK'],
                'barcode' => '8991001000011',
                'description' => 'Biji kopi sangrai arabika kualitas ekspor kemasan aluminium foil.',
                'track_stock' => true,
                'selling_price' => '65000.00',
                'min_stock' => '20.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-BEV-002',
                'name' => 'Teh Melati Celup Kotak 25s',
                'category_id' => $catMap['FNB-BEV'],
                'base_unit_id' => $unitMap['BOX'],
                'barcode' => '8991001000028',
                'description' => 'Teh wangi melati alami isi 25 kantung celup.',
                'track_stock' => true,
                'selling_price' => '12500.00',
                'min_stock' => '50.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-SNK-001',
                'name' => 'Biskuit Gandum Cokelat 300g',
                'category_id' => $catMap['FNB-SNK'],
                'base_unit_id' => $unitMap['BOX'],
                'barcode' => '8991002000010',
                'description' => 'Biskuit renyah kaya serat dengan krim cokelat pekat.',
                'track_stock' => true,
                'selling_price' => '18000.00',
                'min_stock' => '30.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-SNK-002',
                'name' => 'Keripik Singkong Balado 200g',
                'category_id' => $catMap['FNB-SNK'],
                'base_unit_id' => $unitMap['PACK'],
                'barcode' => '8991002000027',
                'description' => 'Keripik singkong renyah bumbu balado khas Minang.',
                'track_stock' => true,
                'selling_price' => '15000.00',
                'min_stock' => '40.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-ACC-001',
                'name' => 'Kabel Data USB-C Fast Charging 1m',
                'category_id' => $catMap['ELK-ACC'],
                'base_unit_id' => $unitMap['PCS'],
                'barcode' => '8992001000019',
                'description' => 'Kabel data braided nilon daya dukung pengisian hingga 65W.',
                'track_stock' => true,
                'selling_price' => '45000.00',
                'min_stock' => '15.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-ACC-002',
                'name' => 'Mouse Nirkabel Silent Click 2.4GHz',
                'category_id' => $catMap['ELK-ACC'],
                'base_unit_id' => $unitMap['PCS'],
                'barcode' => '8992001000026',
                'description' => 'Mouse ergonomis nirkabel tanpa suara klik dengan receiver nano.',
                'track_stock' => true,
                'selling_price' => '95000.00',
                'min_stock' => '10.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-PPR-001',
                'name' => 'Kertas Fotokopi HVS A4 80gsm (1 Rim)',
                'category_id' => $catMap['ATK-PPR'],
                'base_unit_id' => $unitMap['PACK'],
                'barcode' => '8993001000017',
                'description' => 'Kertas serbaguna cetak dokumen isi 500 lembar per rim.',
                'track_stock' => true,
                'selling_price' => '52000.00',
                'min_stock' => '25.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-PPR-002',
                'name' => 'Buku Kas Folio Bergaris 100 Hal',
                'category_id' => $catMap['ATK-PPR'],
                'base_unit_id' => $unitMap['PCS'],
                'barcode' => '8993001000024',
                'description' => 'Buku hard cover catatan akuntansi dan arus kas masuk-keluar.',
                'track_stock' => true,
                'selling_price' => '28000.00',
                'min_stock' => '10.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-BEV-003',
                'name' => 'Sirup Markisa Asli 650ml',
                'category_id' => $catMap['FNB-BEV'],
                'base_unit_id' => $unitMap['PCS'],
                'barcode' => '8991001000035',
                'description' => 'Konsentrat buah markisa asli dari dataran tinggi Brastagi.',
                'track_stock' => true,
                'selling_price' => '42000.00',
                'min_stock' => '12.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sku' => 'PRD-ACC-003',
                'name' => 'Adaptor Charger PD 30W Dual Port',
                'category_id' => $catMap['ELK-ACC'],
                'base_unit_id' => $unitMap['PCS'],
                'barcode' => '8992001000033',
                'description' => 'Kepala pengisi daya cepat USB-C + USB-A bersertifikasi SNI.',
                'track_stock' => true,
                'selling_price' => '115000.00',
                'min_stock' => '8.0000',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('products')->upsert(
            $products,
            ['sku'],
            ['name', 'category_id', 'base_unit_id', 'barcode', 'description', 'track_stock', 'selling_price', 'min_stock', 'is_active', 'updated_at']
        );

        // 7. Suppliers
        $suppliers = [
            [
                'code' => 'SUP-AGRO',
                'name' => 'PT Agro Pangan Lestari',
                'contact_name' => 'Bambang Sutejo',
                'phone' => '021-8971234',
                'email' => 'sales@agropangan.co.id',
                'address' => 'Kawasan Agro Industri No. 8, Sukabumi, Jawa Barat',
                'tax_id' => '01.234.567.8-401.000',
                'payment_term_id' => $termMap['NET30'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'SUP-TECH',
                'name' => 'CV Megah Teknologi Mandiri',
                'contact_name' => 'David Pratama',
                'phone' => '021-6289901',
                'email' => 'order@megahtekno.com',
                'address' => 'Mangga Dua Mall Lt. 4 Blok A No. 15, Jakarta Pusat',
                'tax_id' => '02.456.789.0-031.000',
                'payment_term_id' => $termMap['NET14'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'SUP-PPR',
                'name' => 'PT Kertas Nusantara Abadi',
                'contact_name' => 'Lestari Handayani',
                'phone' => '031-7890123',
                'email' => 'distribution@kertasnusantara.co.id',
                'address' => 'Jl. Rungkut Industri III No. 22, Surabaya',
                'tax_id' => '01.987.654.3-601.000',
                'payment_term_id' => $termMap['NET30'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'SUP-BEV',
                'name' => 'UD Makmur Beverage Supply',
                'contact_name' => 'Hengky Wijaya',
                'phone' => '0811-987-654',
                'email' => 'supply@makmurbeverage.id',
                'address' => 'Jl. Pahlawan Revolusi No. 90, Klender, Jakarta Timur',
                'tax_id' => '03.111.222.3-005.000',
                'payment_term_id' => $termMap['COD'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('suppliers')->upsert(
            $suppliers,
            ['code'],
            ['name', 'contact_name', 'phone', 'email', 'address', 'tax_id', 'payment_term_id', 'is_active', 'updated_at']
        );

        // 8. Customers
        $customers = [
            [
                'code' => 'CUST-MART01',
                'name' => 'Supermarket Berkah Sejahtera',
                'contact_name' => 'Hj. Aminah Mansyur',
                'phone' => '021-7788990',
                'email' => 'procurement@berkahmart.co.id',
                'address' => 'Jl. Margonda Raya No. 120, Beji, Depok',
                'tax_id' => '01.555.666.7-412.000',
                'credit_limit' => '50000000.00',
                'payment_term_id' => $termMap['NET30'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-TOKO02',
                'name' => 'Toko Kelontong Subur Rejeki',
                'contact_name' => 'Pak Joko Widodo',
                'phone' => '0813-2233-4455',
                'email' => 'suburrejeki.toko@gmail.com',
                'address' => 'Pasar Induk Kramat Jati Blok C No. 45, Jakarta Timur',
                'tax_id' => null,
                'credit_limit' => '10000000.00',
                'payment_term_id' => $termMap['NET14'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-CORP03',
                'name' => 'PT Mitra Sarana Digital',
                'contact_name' => 'Siska Tamara',
                'phone' => '021-5290112',
                'email' => 'finance@mitrasarana.com',
                'address' => 'Menara BTPN Lt. 18, Mega Kuningan, Jakarta Selatan',
                'tax_id' => '02.888.999.0-018.000',
                'credit_limit' => '75000000.00',
                'payment_term_id' => $termMap['NET30'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-RESTO04',
                'name' => 'Restoran Nusantara Rasa',
                'contact_name' => 'Chef Arifin',
                'phone' => '021-3141592',
                'email' => 'dapur@nusantararasa.id',
                'address' => 'Jl. Sabang No. 34, Menteng, Jakarta Pusat',
                'tax_id' => '01.333.444.5-071.000',
                'credit_limit' => '25000000.00',
                'payment_term_id' => $termMap['NET7'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('customers')->upsert(
            $customers,
            ['code'],
            ['name', 'contact_name', 'phone', 'email', 'address', 'tax_id', 'credit_limit', 'payment_term_id', 'is_active', 'updated_at']
        );
    }
}
