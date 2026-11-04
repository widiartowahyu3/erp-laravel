<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoCatalogSeeder extends Seeder
{
    /**
     * Fictional in-store EAN-13 range, so demo barcodes never collide with real retail barcodes.
     */
    private const string BARCODE_PREFIX = '299';

    /**
     * Run the demo catalog seeds. Existing rows are left untouched so manual edits survive a re-run.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatalogSeeder tidak boleh dijalankan di lingkungan production!');
        }

        $now = now();
        $stamp = ['created_at' => $now, 'updated_at' => $now];

        DB::table('units')->insertOrIgnore(array_map(
            fn (array $unit): array => [...$unit, ...$stamp],
            self::units()
        ));
        $unitMap = DB::table('units')->pluck('id', 'code')->all();

        DB::table('payment_terms')->insertOrIgnore(array_map(
            fn (array $term): array => [...$term, ...$stamp],
            self::paymentTerms()
        ));
        $termMap = DB::table('payment_terms')->pluck('id', 'code')->all();

        DB::table('warehouses')->insertOrIgnore(array_map(
            fn (array $warehouse): array => [...$warehouse, 'is_active' => true, ...$stamp],
            self::warehouses()
        ));

        foreach (self::categories() as $parentCode => $parent) {
            DB::table('product_categories')->insertOrIgnore([
                ['code' => $parentCode, 'name' => $parent['name'], 'parent_id' => null, ...$stamp],
            ]);
            $parentId = DB::table('product_categories')->where('code', $parentCode)->value('id');

            DB::table('product_categories')->insertOrIgnore(array_map(
                fn (string $code, string $name): array => ['code' => $code, 'name' => $name, 'parent_id' => $parentId, ...$stamp],
                array_keys($parent['children']),
                array_values($parent['children'])
            ));
        }
        $categoryMap = DB::table('product_categories')->pluck('id', 'code')->all();

        foreach (self::catalog() as $categoryCode => $group) {
            DB::table('products')->insertOrIgnore(array_map(
                fn (array $item): array => [
                    'sku' => $item[0],
                    'name' => $item[1],
                    'category_id' => $categoryMap[$categoryCode],
                    'base_unit_id' => $unitMap[$item[2]],
                    'barcode' => self::barcodeFor($item[0]),
                    'description' => $group['description'],
                    'track_stock' => true,
                    'selling_price' => $item[3],
                    'min_stock' => $item[4],
                    'is_active' => true,
                    ...$stamp,
                ],
                $group['items']
            ));
        }

        DB::table('suppliers')->insertOrIgnore(array_map(
            fn (array $supplier): array => [
                'code' => $supplier[0],
                'name' => $supplier[1],
                'contact_name' => $supplier[2],
                'phone' => $supplier[3],
                'email' => $supplier[4],
                'address' => $supplier[5],
                'tax_id' => $supplier[6],
                'payment_term_id' => $termMap[$supplier[7]],
                'is_active' => true,
                ...$stamp,
            ],
            self::suppliers()
        ));

        DB::table('customers')->insertOrIgnore(array_map(
            fn (array $customer): array => [
                'code' => $customer[0],
                'name' => $customer[1],
                'contact_name' => $customer[2],
                'phone' => $customer[3],
                'email' => $customer[4],
                'address' => $customer[5],
                'tax_id' => $customer[6],
                'credit_limit' => $customer[7],
                'payment_term_id' => $termMap[$customer[8]],
                'is_active' => true,
                ...$stamp,
            ],
            self::customers()
        ));
    }

    /**
     * Build a valid EAN-13 barcode derived only from the SKU, so it stays stable when the catalog is reordered.
     */
    public static function barcodeFor(string $sku): string
    {
        $body = self::BARCODE_PREFIX.str_pad((string) (crc32($sku) % 1000000000), 9, '0', STR_PAD_LEFT);

        $sum = 0;
        foreach (str_split($body) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $body.(string) ((10 - $sum % 10) % 10);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function units(): array
    {
        return [
            ['code' => 'PCS', 'name' => 'Pcs'],
            ['code' => 'BOX', 'name' => 'Box'],
            ['code' => 'CTN', 'name' => 'Karton'],
            ['code' => 'DZN', 'name' => 'Lusin'],
            ['code' => 'KG', 'name' => 'Kilogram'],
            ['code' => 'MTR', 'name' => 'Meter'],
            ['code' => 'PACK', 'name' => 'Pak'],
            ['code' => 'ROLL', 'name' => 'Gulungan'],
            ['code' => 'BTL', 'name' => 'Botol'],
            ['code' => 'KLG', 'name' => 'Kaleng'],
            ['code' => 'SAK', 'name' => 'Sak'],
            ['code' => 'RIM', 'name' => 'Rim'],
            ['code' => 'TUB', 'name' => 'Tube'],
            ['code' => 'GLN', 'name' => 'Galon'],
        ];
    }

    /**
     * @return list<array{code: string, name: string, days: int}>
     */
    public static function paymentTerms(): array
    {
        return [
            ['code' => 'COD', 'name' => 'Bayar di Tempat (COD)', 'days' => 0],
            ['code' => 'NET7', 'name' => 'Tempo 7 Hari', 'days' => 7],
            ['code' => 'NET14', 'name' => 'Tempo 14 Hari', 'days' => 14],
            ['code' => 'NET30', 'name' => 'Tempo 30 Hari', 'days' => 30],
            ['code' => 'NET60', 'name' => 'Tempo 60 Hari', 'days' => 60],
        ];
    }

    /**
     * @return list<array{code: string, name: string, address: string}>
     */
    public static function warehouses(): array
    {
        return [
            ['code' => 'GD-JKT', 'name' => 'Gudang Jakarta Timur', 'address' => 'Jl. Raya Bekasi Km 22, Cakung, Jakarta Timur'],
            ['code' => 'GD-BDG', 'name' => 'Gudang Bandung', 'address' => 'Jl. Soekarno-Hatta No. 410, Bandung'],
            ['code' => 'GD-SMG', 'name' => 'Gudang Semarang', 'address' => 'Kawasan Industri Terboyo Blok C-12, Semarang'],
            ['code' => 'GD-SBY', 'name' => 'Gudang Surabaya', 'address' => 'Jl. Margomulyo No. 44, Tandes, Surabaya'],
            ['code' => 'GD-MDN', 'name' => 'Gudang Medan', 'address' => 'Jl. Pulau Sumbawa KIM II, Medan'],
            ['code' => 'GD-MKS', 'name' => 'Gudang Makassar', 'address' => 'Kawasan Industri Makassar Blok K-7, Makassar'],
        ];
    }

    /**
     * @return array<string, array{name: string, children: array<string, string>}>
     */
    public static function categories(): array
    {
        return [
            'SMB' => ['name' => 'Sembako', 'children' => [
                'SMB-BRS' => 'Beras',
                'SMB-MYK' => 'Minyak Goreng',
                'SMB-GLA' => 'Gula & Garam',
                'SMB-TPG' => 'Tepung',
                'SMB-TLR' => 'Telur & Margarin',
            ]],
            'MKI' => ['name' => 'Makanan Instan & Bumbu', 'children' => [
                'MKI-MIE' => 'Mi Instan',
                'MKI-KLG' => 'Makanan Kaleng',
                'MKI-BMB' => 'Bumbu & Saus',
            ]],
            'MIN' => ['name' => 'Minuman', 'children' => [
                'MIN-AIR' => 'Air Minum Kemasan',
                'MIN-TKP' => 'Teh & Kopi',
                'MIN-RTD' => 'Minuman Siap Saji & Sirup',
                'MIN-SUS' => 'Susu',
            ]],
            'CML' => ['name' => 'Camilan', 'children' => [
                'CML-BIS' => 'Biskuit & Wafer',
                'CML-KRP' => 'Keripik & Kacang',
                'CML-PRM' => 'Permen & Cokelat',
            ]],
            'PRT' => ['name' => 'Kebutuhan Rumah Tangga', 'children' => [
                'PRT-CUC' => 'Deterjen & Pencuci',
                'PRT-BRS' => 'Pembersih & Anti Serangga',
                'PRT-TIS' => 'Tisu & Kantong Plastik',
            ]],
            'PRD' => ['name' => 'Perawatan Diri', 'children' => [
                'PRD-SBN' => 'Sabun & Losion',
                'PRD-SMP' => 'Sampo',
                'PRD-GIG' => 'Perawatan Gigi',
                'PRD-KOS' => 'Kosmetik & Kewanitaan',
            ]],
            'KES' => ['name' => 'Kesehatan', 'children' => [
                'KES-OBT' => 'Obat Bebas',
                'KES-HRB' => 'Herbal & Minyak Angin',
            ]],
            'BAY' => ['name' => 'Ibu & Bayi', 'children' => [
                'BAY-POP' => 'Popok',
                'BAY-MKN' => 'Susu & Makanan Bayi',
            ]],
            'ATK' => ['name' => 'Alat Tulis & Kantor', 'children' => [
                'ATK-TLS' => 'Alat Tulis',
                'ATK-PPR' => 'Kertas & Pembukuan',
            ]],
            'ELK' => ['name' => 'Elektronik & Perangkat', 'children' => [
                'ELK-LMP' => 'Lampu',
                'ELK-BAT' => 'Baterai',
                'ELK-LIS' => 'Perlengkapan Listrik',
            ]],
            'BGN' => ['name' => 'Bahan Bangunan', 'children' => [
                'BGN-SMN' => 'Semen',
                'BGN-CAT' => 'Cat',
                'BGN-PIP' => 'Pipa & Perekat',
                'BGN-LGM' => 'Paku & Besi',
            ]],
        ];
    }

    /**
     * Items are [sku, name, unit code, selling price, minimum stock]. Prices are rough demo estimates, not market quotes.
     *
     * @return array<string, array{description: string, items: list<array{string, string, string, string, string}>}>
     */
    public static function catalog(): array
    {
        return [
            'SMB-BRS' => ['description' => 'Beras kemasan untuk ritel dan grosir.', 'items' => [
                ['BRS-001', 'Beras Sania Premium 5 kg', 'SAK', '78000', '40'],
                ['BRS-002', 'Beras Fortune Premium 5 kg', 'SAK', '77000', '40'],
                ['BRS-003', 'Beras Topi Koki Setra Ramos 5 kg', 'SAK', '79000', '30'],
                ['BRS-004', 'Beras Rojolele Delanggu 5 kg', 'SAK', '85000', '20'],
                ['BRS-005', 'Beras Pandan Wangi Cianjur 5 kg', 'SAK', '88000', '20'],
                ['BRS-006', 'Beras Medium Curah 25 kg', 'SAK', '340000', '15'],
                ['BRS-007', 'Beras Merah Organik 1 kg', 'PACK', '28000', '15'],
            ]],
            'SMB-MYK' => ['description' => 'Minyak goreng kemasan untuk rumah tangga dan usaha makanan.', 'items' => [
                ['MYK-001', 'Minyak Goreng Bimoli 2 L', 'PACK', '40000', '48'],
                ['MYK-002', 'Minyak Goreng Bimoli 1 L', 'PACK', '20500', '48'],
                ['MYK-003', 'Minyak Goreng Filma 2 L', 'PACK', '39000', '36'],
                ['MYK-004', 'Minyak Goreng Sania 2 L', 'PACK', '38000', '36'],
                ['MYK-005', 'Minyak Goreng Tropical 1 L', 'PACK', '20000', '36'],
                ['MYK-006', 'Minyak Goreng Sunco 2 L', 'PACK', '42000', '24'],
                ['MYK-007', 'MinyaKita 1 L', 'BTL', '15700', '60'],
            ]],
            'SMB-GLA' => ['description' => 'Gula dan garam konsumsi.', 'items' => [
                ['GLA-001', 'Gula Pasir Gulaku Premium 1 kg', 'PACK', '19500', '50'],
                ['GLA-002', 'Gula Pasir Rose Brand 1 kg', 'PACK', '18500', '50'],
                ['GLA-003', 'Gula Pasir Curah', 'KG', '17500', '100'],
                ['GLA-004', 'Gula Merah Aren 500 g', 'PACK', '18000', '20'],
                ['GLA-005', 'Garam Refina 250 g', 'PCS', '5000', '40'],
                ['GLA-006', 'Garam Dapur Cap Kapal 250 g', 'PCS', '3000', '60'],
            ]],
            'SMB-TPG' => ['description' => 'Tepung untuk masakan dan kue.', 'items' => [
                ['TPG-001', 'Tepung Terigu Segitiga Biru 1 kg', 'PACK', '14000', '48'],
                ['TPG-002', 'Tepung Terigu Cakra Kembar 1 kg', 'PACK', '14500', '30'],
                ['TPG-003', 'Tepung Terigu Kunci Biru 1 kg', 'PACK', '13500', '30'],
                ['TPG-004', 'Tepung Beras Rose Brand 500 g', 'PACK', '9500', '30'],
                ['TPG-005', 'Tepung Tapioka Rose Brand 500 g', 'PACK', '9000', '30'],
                ['TPG-006', 'Tepung Maizena Maizenaku 200 g', 'PCS', '8000', '24'],
            ]],
            'SMB-TLR' => ['description' => 'Telur dan lemak olahan untuk memasak.', 'items' => [
                ['TLR-001', 'Telur Ayam Ras', 'KG', '29000', '100'],
                ['TLR-002', 'Margarin Blue Band 200 g', 'PCS', '11000', '36'],
                ['TLR-003', 'Margarin Filma 200 g', 'PCS', '9500', '24'],
            ]],
            'MKI-MIE' => ['description' => 'Mi instan untuk ritel dan grosir.', 'items' => [
                ['MIE-001', 'Indomie Goreng 85 g (Dus isi 40)', 'CTN', '122000', '50'],
                ['MIE-002', 'Indomie Soto Mie 70 g (Dus isi 40)', 'CTN', '115000', '40'],
                ['MIE-003', 'Indomie Ayam Bawang 69 g (Dus isi 40)', 'CTN', '115000', '40'],
                ['MIE-004', 'Mie Sedaap Goreng 90 g (Dus isi 40)', 'CTN', '120000', '40'],
                ['MIE-005', 'Mie Sedaap Soto 75 g (Dus isi 40)', 'CTN', '113000', '30'],
                ['MIE-006', 'Sarimi Isi 2 Ayam Bawang 115 g (Dus isi 24)', 'CTN', '110000', '20'],
                ['MIE-007', 'Supermi Ayam Bawang 70 g (Dus isi 40)', 'CTN', '108000', '20'],
                ['MIE-008', 'Pop Mie Ayam Bawang 75 g (Dus isi 24)', 'CTN', '125000', '20'],
                ['MIE-009', 'Indomie Goreng 85 g', 'PCS', '3500', '200'],
            ]],
            'MKI-KLG' => ['description' => 'Makanan kaleng siap olah.', 'items' => [
                ['KLG-001', 'Sarden ABC Saus Tomat 155 g', 'KLG', '11000', '48'],
                ['KLG-002', 'Sarden Botan Saus Tomat 155 g', 'KLG', '10500', '48'],
                ['KLG-003', 'Sarden Maya Saus Cabai 155 g', 'KLG', '9500', '36'],
                ['KLG-004', 'Kornet Sapi Pronas 198 g', 'KLG', '27000', '24'],
                ['KLG-005', 'Kornet Sapi CIP 198 g', 'KLG', '25000', '24'],
            ]],
            'MKI-BMB' => ['description' => 'Bumbu dapur, kecap, dan saus.', 'items' => [
                ['BMB-001', 'Kecap Manis Bango 520 ml', 'PCS', '26000', '36'],
                ['BMB-002', 'Kecap Manis ABC 600 ml', 'BTL', '27000', '36'],
                ['BMB-003', 'Kecap Asin ABC 133 ml', 'BTL', '7000', '24'],
                ['BMB-004', 'Saus Sambal ABC 335 ml', 'BTL', '14000', '36'],
                ['BMB-005', 'Saus Sambal Indofood 340 ml', 'BTL', '16000', '30'],
                ['BMB-006', 'Saus Tomat ABC 335 ml', 'BTL', '12500', '24'],
                ['BMB-007', 'Kaldu Masako Ayam 230 g', 'PCS', '13000', '48'],
                ['BMB-008', 'Kaldu Royco Ayam 230 g', 'PCS', '12500', '48'],
                ['BMB-009', 'Penyedap Sasa 250 g', 'PCS', '14000', '36'],
                ['BMB-010', 'Penyedap Ajinomoto 250 g', 'PCS', '15000', '36'],
                ['BMB-011', 'Santan Kara 200 ml', 'PCS', '9000', '48'],
                ['BMB-012', 'Lada Bubuk Ladaku 15 g (Pak isi 12)', 'PACK', '16000', '20'],
            ]],
            'MIN-AIR' => ['description' => 'Air minum dalam kemasan.', 'items' => [
                ['AIR-001', 'Aqua 600 ml (Dus isi 24)', 'CTN', '55000', '60'],
                ['AIR-002', 'Aqua 1500 ml (Dus isi 12)', 'CTN', '62000', '40'],
                ['AIR-003', 'Aqua Galon 19 L', 'GLN', '21000', '50'],
                ['AIR-004', 'Le Minerale 600 ml (Dus isi 24)', 'CTN', '52000', '50'],
                ['AIR-005', 'Le Minerale 1500 ml (Dus isi 12)', 'CTN', '60000', '30'],
                ['AIR-006', 'Club 600 ml (Dus isi 24)', 'CTN', '42000', '30'],
                ['AIR-007', 'Ades 600 ml (Dus isi 24)', 'CTN', '48000', '30'],
                ['AIR-008', 'Cleo 550 ml (Dus isi 24)', 'CTN', '50000', '20'],
            ]],
            'MIN-TKP' => ['description' => 'Teh dan kopi kemasan.', 'items' => [
                ['TKP-001', 'Teh Celup Sariwangi Asli 25 s', 'BOX', '7500', '48'],
                ['TKP-002', 'Teh Celup Sosro 25 s', 'BOX', '7000', '36'],
                ['TKP-003', 'Teh Kotak Jasmine 300 ml (Dus isi 24)', 'CTN', '78000', '20'],
                ['TKP-004', 'Teh Pucuk Harum 350 ml (Dus isi 24)', 'CTN', '72000', '30'],
                ['TKP-005', 'Kopi Kapal Api Special 165 g', 'PCS', '15500', '36'],
                ['TKP-006', 'Kapal Api Special Mix 24 g (Renceng isi 10)', 'PACK', '15000', '50'],
                ['TKP-007', 'Good Day Cappuccino 25 g (Renceng isi 10)', 'PACK', '19000', '40'],
                ['TKP-008', 'Torabika Cappuccino 25 g (Renceng isi 10)', 'PACK', '18000', '40'],
                ['TKP-009', 'Kopi ABC Susu 31 g (Renceng isi 10)', 'PACK', '14000', '40'],
                ['TKP-010', 'Indocafe Coffeemix 20 g (Renceng isi 10)', 'PACK', '15000', '30'],
                ['TKP-011', 'Nescafe Classic 100 g', 'PCS', '33000', '15'],
            ]],
            'MIN-RTD' => ['description' => 'Minuman siap saji dan sirup.', 'items' => [
                ['RTD-001', 'Pocari Sweat 500 ml (Dus isi 24)', 'CTN', '165000', '15'],
                ['RTD-002', 'Mizone 500 ml (Dus isi 12)', 'CTN', '74000', '15'],
                ['RTD-003', 'Coca-Cola 390 ml (Dus isi 12)', 'CTN', '58000', '20'],
                ['RTD-004', 'Sprite 390 ml (Dus isi 12)', 'CTN', '58000', '15'],
                ['RTD-005', 'Fanta Stroberi 390 ml (Dus isi 12)', 'CTN', '58000', '15'],
                ['RTD-006', 'Larutan Penyegar Cap Kaki Tiga 320 ml', 'KLG', '7500', '48'],
                ['RTD-007', 'Nutrisari Jeruk Manis 14 g (Renceng isi 10)', 'PACK', '12000', '40'],
                ['RTD-008', 'Sirup Marjan Cocopandan 460 ml', 'BTL', '22000', '24'],
                ['RTD-009', 'Sirup ABC Squash Jeruk 460 ml', 'BTL', '20000', '20'],
            ]],
            'MIN-SUS' => ['description' => 'Susu cair, kental manis, dan bubuk.', 'items' => [
                ['SUS-001', 'Susu Kental Manis Indomilk 370 g', 'KLG', '13500', '48'],
                ['SUS-002', 'Susu Kental Manis Frisian Flag 370 g', 'KLG', '14000', '48'],
                ['SUS-003', 'Ultra Milk Full Cream 1 L', 'PCS', '19000', '36'],
                ['SUS-004', 'Ultra Milk Cokelat 250 ml (Dus isi 24)', 'CTN', '115000', '15'],
                ['SUS-005', 'Susu Beruang Bear Brand 189 ml', 'KLG', '11000', '48'],
                ['SUS-006', 'Dancow Fortigro Instant 800 g', 'PCS', '98000', '12'],
                ['SUS-007', 'Milo Activ-Go 300 g', 'PCS', '40000', '15'],
                ['SUS-008', 'Energen Cokelat 34 g (Renceng isi 10)', 'PACK', '19000', '30'],
            ]],
            'CML-BIS' => ['description' => 'Biskuit dan wafer kemasan.', 'items' => [
                ['BIS-001', 'Roma Kelapa 300 g', 'PCS', '11000', '36'],
                ['BIS-002', 'Roma Malkist Crackers 135 g', 'PCS', '9000', '36'],
                ['BIS-003', 'Biskuat Original 110 g', 'PCS', '9000', '30'],
                ['BIS-004', 'Oreo Vanila 133 g', 'PCS', '9500', '36'],
                ['BIS-005', 'Khong Guan Assorted 1,6 kg', 'KLG', '95000', '10'],
                ['BIS-006', 'Monde Butter Cookies 454 g', 'KLG', '85000', '10'],
                ['BIS-007', 'Nissin Wafer Cokelat 110 g', 'PCS', '10000', '24'],
                ['BIS-008', 'Tango Wafer Cokelat 130 g', 'PCS', '10500', '24'],
            ]],
            'CML-KRP' => ['description' => 'Keripik dan kacang kemasan.', 'items' => [
                ['KRP-001', 'Chitato Sapi Panggang 68 g', 'PCS', '11000', '36'],
                ['KRP-002', 'Japota Ayam Bakar 68 g', 'PCS', '11000', '24'],
                ['KRP-003', 'Qtela Singkong Balado 180 g', 'PCS', '15000', '24'],
                ['KRP-004', 'Taro Net Seaweed 65 g', 'PCS', '6000', '36'],
                ['KRP-005', 'Piattos Sapi Panggang 75 g', 'PCS', '10500', '24'],
                ['KRP-006', 'Kacang Garuda Kulit 250 g', 'PCS', '18000', '24'],
                ['KRP-007', 'Kacang Dua Kelinci Kulit 250 g', 'PCS', '15500', '24'],
            ]],
            'CML-PRM' => ['description' => 'Permen dan cokelat.', 'items' => [
                ['PRM-001', 'SilverQueen Cashew 62 g', 'PCS', '14000', '30'],
                ['PRM-002', 'Cadbury Dairy Milk 62 g', 'PCS', '14500', '24'],
                ['PRM-003', 'Beng-Beng 20 g (Pak isi 20)', 'PACK', '38000', '20'],
                ['PRM-004', 'Permen Kopiko 150 g', 'PCS', '14000', '24'],
                ['PRM-005', 'Permen Relaxa 125 g', 'PCS', '9500', '24'],
                ['PRM-006', 'Mentos Mint Roll (Dus isi 24)', 'CTN', '50000', '12'],
                ['PRM-007', 'Fruit Tella Roll (Dus isi 24)', 'CTN', '55000', '12'],
            ]],
            'PRT-CUC' => ['description' => 'Deterjen, pelembut, dan pencuci piring.', 'items' => [
                ['CUC-001', 'Rinso Anti Noda Bubuk 770 g', 'PCS', '26000', '36'],
                ['CUC-002', 'Attack Clean Max Bubuk 800 g', 'PCS', '24000', '36'],
                ['CUC-003', 'So Klin Softergent 770 g', 'PCS', '22000', '36'],
                ['CUC-004', 'Daia Bubuk 900 g', 'PCS', '22000', '30'],
                ['CUC-005', 'Molto Pewangi Refill 800 ml', 'PCS', '18000', '36'],
                ['CUC-006', 'Downy Sunrise Fresh Refill 900 ml', 'PCS', '32000', '20'],
                ['CUC-007', 'Sunlight Jeruk Nipis Refill 755 ml', 'PCS', '18000', '48'],
                ['CUC-008', 'Mama Lemon Refill 680 ml', 'PCS', '13000', '30'],
            ]],
            'PRT-BRS' => ['description' => 'Pembersih rumah dan pembasmi serangga.', 'items' => [
                ['PBR-001', 'Wipol Karbol Cemara 780 ml', 'BTL', '19000', '24'],
                ['PBR-002', 'Super Pell Pembersih Lantai 800 ml', 'PCS', '15000', '30'],
                ['PBR-003', 'So Klin Lantai 800 ml', 'PCS', '14000', '30'],
                ['PBR-004', 'Vixal Pembersih Porselen 780 ml', 'BTL', '18000', '20'],
                ['PBR-005', 'Harpic Pembersih Toilet 450 ml', 'BTL', '21000', '20'],
                ['PBR-006', 'Bayclin Pemutih 500 ml', 'BTL', '9000', '30'],
                ['PBR-007', 'Baygon Aerosol 600 ml', 'KLG', '39000', '20'],
                ['PBR-008', 'HIT Aerosol 600 ml', 'KLG', '36000', '20'],
                ['PBR-009', 'Stella Pengharum Ruangan Aerosol 400 ml', 'KLG', '25000', '15'],
            ]],
            'PRT-TIS' => ['description' => 'Tisu dan kantong plastik.', 'items' => [
                ['TIS-001', 'Tisu Paseo 250 Lembar', 'PACK', '14000', '36'],
                ['TIS-002', 'Tisu Tessa 250 Lembar', 'PACK', '13000', '30'],
                ['TIS-003', 'Tisu Nice 200 Lembar', 'PACK', '12000', '30'],
                ['TIS-004', 'Tisu Gulung Paseo (Pak isi 10)', 'PACK', '32000', '20'],
                ['TIS-005', 'Kantong Sampah Hitam 60 x 100 cm', 'PACK', '9000', '30'],
                ['TIS-006', 'Kantong Plastik HD 28 cm', 'PACK', '12000', '40'],
            ]],
            'PRD-SBN' => ['description' => 'Sabun mandi dan losion tubuh.', 'items' => [
                ['SBN-001', 'Sabun Lifebuoy Batang 110 g', 'PCS', '4500', '72'],
                ['SBN-002', 'Sabun Lux Batang 110 g', 'PCS', '4500', '48'],
                ['SBN-003', 'Sabun Giv Batang 110 g', 'PCS', '3500', '48'],
                ['SBN-004', 'Sabun Nuvo Batang 110 g', 'PCS', '3500', '36'],
                ['SBN-005', 'Lifebuoy Sabun Cair Refill 450 ml', 'PCS', '26000', '24'],
                ['SBN-006', 'Dettol Sabun Cair Refill 410 ml', 'PCS', '34000', '20'],
                ['SBN-007', 'Citra Hand & Body Lotion 230 ml', 'BTL', '29000', '20'],
                ['SBN-008', 'Marina Hand & Body Lotion 200 ml', 'BTL', '18000', '20'],
            ]],
            'PRD-SMP' => ['description' => 'Sampo perawatan rambut.', 'items' => [
                ['SMP-001', 'Sampo Pantene 170 ml', 'BTL', '30000', '24'],
                ['SMP-002', 'Sampo Sunsilk 170 ml', 'BTL', '26000', '24'],
                ['SMP-003', 'Sampo Clear Men 160 ml', 'BTL', '32000', '20'],
                ['SMP-004', 'Sampo Head & Shoulders 170 ml', 'BTL', '31000', '20'],
                ['SMP-005', 'Sampo Lifebuoy 170 ml', 'BTL', '24000', '20'],
                ['SMP-006', 'Sampo Rejoice 170 ml', 'BTL', '22000', '20'],
                ['SMP-007', 'Sampo Emeron 170 ml', 'BTL', '19000', '15'],
            ]],
            'PRD-GIG' => ['description' => 'Pasta gigi, sikat gigi, dan obat kumur.', 'items' => [
                ['GIG-001', 'Pepsodent Pencegah Gigi Berlubang 190 g', 'TUB', '17000', '48'],
                ['GIG-002', 'Close Up Fresh Green 160 g', 'TUB', '18000', '30'],
                ['GIG-003', 'Formula Silver 190 g', 'TUB', '15000', '30'],
                ['GIG-004', 'Ciptadent Fresh Mint 190 g', 'TUB', '11000', '30'],
                ['GIG-005', 'Sikat Gigi Formula Soft (Lusin)', 'DZN', '66000', '10'],
                ['GIG-006', 'Listerine Cool Mint 250 ml', 'BTL', '30000', '15'],
            ]],
            'PRD-KOS' => ['description' => 'Kosmetik harian dan produk kewanitaan.', 'items' => [
                ['KOS-001', 'Rexona Roll On 45 ml', 'BTL', '23000', '24'],
                ['KOS-002', 'Wardah Sun Care SPF 35 40 ml', 'TUB', '35000', '15'],
                ['KOS-003', 'Ponds White Beauty Facial Foam 100 g', 'TUB', '33000', '15'],
                ['KOS-004', 'Garnier Men Facial Foam 100 ml', 'TUB', '36000', '12'],
                ['KOS-005', 'Biore Body Foam Refill 450 ml', 'PCS', '28000', '20'],
                ['KOS-006', 'Softex Daun Sirih 29 cm isi 8', 'PACK', '12000', '36'],
                ['KOS-007', 'Laurier Super Maxi 22 cm isi 10', 'PACK', '12500', '36'],
            ]],
            'KES-OBT' => ['description' => 'Obat bebas tanpa resep.', 'items' => [
                ['OBT-001', 'Bodrex Tablet Strip isi 10', 'PACK', '4000', '50'],
                ['OBT-002', 'Panadol Merah Strip isi 10', 'PACK', '13000', '30'],
                ['OBT-003', 'Promag Tablet Strip isi 12', 'PACK', '9500', '40'],
                ['OBT-004', 'Betadine Antiseptik 30 ml', 'BTL', '26000', '20'],
                ['OBT-005', 'Hansaplast Plester Isi 10', 'PACK', '7000', '30'],
            ]],
            'KES-HRB' => ['description' => 'Herbal dan minyak angin.', 'items' => [
                ['HRB-001', 'Tolak Angin Cair 15 ml (Box isi 12)', 'BOX', '48000', '20'],
                ['HRB-002', 'Antangin JRG 15 ml (Box isi 12)', 'BOX', '46000', '20'],
                ['HRB-003', 'Minyak Kayu Putih Cap Lang 60 ml', 'BTL', '26000', '24'],
                ['HRB-004', 'Minyak Telon Cap Lang 60 ml', 'BTL', '27000', '24'],
                ['HRB-005', 'Balsem Geliga 20 g', 'PCS', '15000', '24'],
            ]],
            'BAY-POP' => ['description' => 'Popok bayi celana.', 'items' => [
                ['POP-001', 'MamyPoko Pants M 34', 'PACK', '72000', '20'],
                ['POP-002', 'MamyPoko Pants L 30', 'PACK', '72000', '20'],
                ['POP-003', 'Sweety Pants M 34', 'PACK', '68000', '20'],
                ['POP-004', 'Sweety Pants L 30', 'PACK', '68000', '20'],
            ]],
            'BAY-MKN' => ['description' => 'Susu pertumbuhan, makanan bayi, dan perawatan bayi.', 'items' => [
                ['MKN-001', 'SGM Eksplor 1+ Madu 900 g', 'PCS', '92000', '12'],
                ['MKN-002', 'Bebelac 3 Vanila 800 g', 'PCS', '165000', '10'],
                ['MKN-003', 'Bubur Promina Beras Merah 100 g', 'PCS', '14000', '24'],
                ['MKN-004', 'Zwitsal Baby Powder 300 g', 'PCS', '26000', '15'],
                ['MKN-005', 'Cussons Baby Shampoo 200 ml', 'BTL', '22000', '15'],
            ]],
            'ATK-TLS' => ['description' => 'Alat tulis harian untuk sekolah dan kantor.', 'items' => [
                ['TLS-001', 'Pulpen Standard AE7 (Lusin)', 'DZN', '20000', '20'],
                ['TLS-002', 'Pensil Faber-Castell 2B (Lusin)', 'DZN', '42000', '15'],
                ['TLS-003', 'Penghapus Joyko (Box isi 40)', 'BOX', '50000', '10'],
                ['TLS-004', 'Spidol Snowman Permanen (Lusin)', 'DZN', '84000', '10'],
                ['TLS-005', 'Lem Kenko Stick 8 g', 'PCS', '3500', '50'],
                ['TLS-006', 'Stapler Kenko HD-10', 'PCS', '19000', '15'],
                ['TLS-007', 'Isi Staples Kenko No. 10 (Box isi 20)', 'BOX', '30000', '10'],
                ['TLS-008', 'Buku Tulis SiDU 38 Lembar (Pak isi 10)', 'PACK', '34000', '30'],
            ]],
            'ATK-PPR' => ['description' => 'Kertas cetak dan perlengkapan pembukuan.', 'items' => [
                ['KTS-001', 'Kertas PaperOne A4 80 gsm', 'RIM', '56000', '30'],
                ['KTS-002', 'Kertas Sinar Dunia A4 70 gsm', 'RIM', '45000', '40'],
                ['KTS-003', 'Kertas Sinar Dunia F4 70 gsm', 'RIM', '52000', '30'],
                ['KTS-004', 'Amplop Paperline Coklat 90 (Box isi 100)', 'BOX', '35000', '10'],
                ['KTS-005', 'Kertas Struk Thermal 80 x 80 mm', 'ROLL', '9500', '50'],
                ['KTS-006', 'Buku Nota Kontan 2 Ply (Lusin)', 'DZN', '48000', '10'],
            ]],
            'ELK-LMP' => ['description' => 'Lampu LED untuk rumah dan toko.', 'items' => [
                ['LMP-001', 'Lampu LED Philips 10 W', 'PCS', '38000', '30'],
                ['LMP-002', 'Lampu LED Philips 14 W', 'PCS', '52000', '20'],
                ['LMP-003', 'Lampu LED Hannochs 9 W', 'PCS', '25000', '24'],
                ['LMP-004', 'Lampu LED Shinyoku 12 W', 'PCS', '22000', '24'],
                ['LMP-005', 'Lampu LED Osram 10 W', 'PCS', '33000', '20'],
            ]],
            'ELK-BAT' => ['description' => 'Baterai rumah tangga.', 'items' => [
                ['BAT-001', 'Baterai ABC Biru AA (Pak isi 4)', 'PACK', '10000', '40'],
                ['BAT-002', 'Baterai ABC Biru AAA (Pak isi 4)', 'PACK', '10000', '40'],
                ['BAT-003', 'Baterai Panasonic Merah AA (Pak isi 4)', 'PACK', '11000', '30'],
                ['BAT-004', 'Baterai Energizer Alkaline AA (Pak isi 2)', 'PACK', '24000', '20'],
                ['BAT-005', 'Baterai Eveready D (Pak isi 2)', 'PACK', '16000', '15'],
            ]],
            'ELK-LIS' => ['description' => 'Perlengkapan instalasi listrik.', 'items' => [
                ['LIS-001', 'Kabel NYM Eterna 2 x 1,5 mm', 'MTR', '8500', '100'],
                ['LIS-002', 'Kabel NYM Eterna 2 x 2,5 mm', 'MTR', '13000', '100'],
                ['LIS-003', 'Stop Kontak Uticon 3 Lubang', 'PCS', '36000', '20'],
                ['LIS-004', 'Saklar Broco Tunggal', 'PCS', '14000', '24'],
                ['LIS-005', 'Fitting Lampu Broco E27', 'PCS', '9000', '24'],
                ['LIS-006', 'Isolasi Listrik Nitto', 'ROLL', '7500', '30'],
            ]],
            'BGN-SMN' => ['description' => 'Semen untuk konstruksi.', 'items' => [
                ['SMN-001', 'Semen Tiga Roda 40 kg', 'SAK', '62000', '100'],
                ['SMN-002', 'Semen Gresik 40 kg', 'SAK', '60000', '100'],
                ['SMN-003', 'Semen Dynamix 40 kg', 'SAK', '61000', '80'],
                ['SMN-004', 'Semen Padang 50 kg', 'SAK', '72000', '60'],
                ['SMN-005', 'Semen Instan Mortar Utama 40 kg', 'SAK', '78000', '40'],
            ]],
            'BGN-CAT' => ['description' => 'Cat tembok dan cat kayu.', 'items' => [
                ['CAT-001', 'Cat Tembok Dulux Catylac Putih 5 kg', 'PCS', '125000', '15'],
                ['CAT-002', 'Cat Tembok Nippon Vinilex Putih 5 kg', 'PCS', '135000', '15'],
                ['CAT-003', 'Cat Tembok Avitex Putih 5 kg', 'PCS', '120000', '15'],
                ['CAT-004', 'Cat Kayu dan Besi Avian 1 kg', 'KLG', '72000', '12'],
            ]],
            'BGN-PIP' => ['description' => 'Pipa PVC dan bahan perekat.', 'items' => [
                ['PIP-001', 'Pipa PVC Rucika 1/2 inci 4 m', 'PCS', '28000', '30'],
                ['PIP-002', 'Pipa PVC Rucika 3/4 inci 4 m', 'PCS', '36000', '30'],
                ['PIP-003', 'Pipa PVC Wavin 1 inci 4 m', 'PCS', '52000', '20'],
                ['PIP-004', 'Lem Aibon 70 g', 'TUB', '14000', '24'],
                ['PIP-005', 'Lem PVC Rucika 100 g', 'TUB', '19000', '20'],
            ]],
            'BGN-LGM' => ['description' => 'Paku, kawat, dan besi.', 'items' => [
                ['LGM-001', 'Paku Kayu 2 Inci', 'KG', '18000', '50'],
                ['LGM-002', 'Paku Beton 2 Inci', 'KG', '32000', '20'],
                ['LGM-003', 'Kawat Bendrat', 'KG', '19000', '40'],
                ['LGM-004', 'Besi Beton Polos 10 mm 12 m', 'PCS', '72000', '30'],
            ]],
        ];
    }

    /**
     * Fictional distributors. Rows are [code, name, contact, phone, email, address, tax id, payment term code].
     *
     * @return list<array{string, string, string, string, string, string, string, string}>
     */
    public static function suppliers(): array
    {
        return [
            ['SUP-SMB01', 'PT Sumber Pangan Sentosa', 'Rudi Hartono', '021-5550101', 'sales@sumberpangan.test', 'Jl. Raya Narogong Km 12, Bekasi', '01.100.000.1-401.000', 'NET30'],
            ['SUP-SMB02', 'CV Tani Makmur Abadi', 'Sri Wahyuni', '0271-5550102', 'order@tanimakmur.test', 'Jl. Solo-Sragen Km 8, Karanganyar', '01.100.000.2-526.000', 'NET14'],
            ['SUP-FMCG1', 'PT Distribusi Boga Nusantara', 'Agus Salim', '021-5550103', 'sales@bogadistribusi.test', 'Jl. Daan Mogot Km 21, Tangerang', '01.100.000.3-402.000', 'NET30'],
            ['SUP-FMCG2', 'PT Citra Niaga Konsumen', 'Dewi Lestari', '031-5550104', 'order@citraniaga.test', 'Jl. Rungkut Industri VII No. 5, Surabaya', '01.100.000.4-609.000', 'NET30'],
            ['SUP-BEV01', 'PT Tirta Segar Distribusi', 'Hendra Gunawan', '022-5550105', 'sales@tirtasegar.test', 'Jl. Cibaligo No. 77, Cimahi', '01.100.000.5-421.000', 'NET14'],
            ['SUP-HOME1', 'PT Bersih Rumah Indonesia', 'Yuni Astuti', '021-5550106', 'sales@bersihrumah.test', 'Kawasan Industri Pulogadung Blok F-3, Jakarta Timur', '01.100.000.6-004.000', 'NET30'],
            ['SUP-CARE1', 'PT Sehat Sejahtera Farma', 'Rina Kurnia', '024-5550107', 'order@sehatfarma.test', 'Jl. Majapahit No. 210, Semarang', '01.100.000.7-503.000', 'NET60'],
            ['SUP-ATK01', 'CV Karya Kantor Mandiri', 'Tono Sugiarto', '021-5550108', 'sales@karyakantor.test', 'Jl. Asemka No. 33, Jakarta Barat', '01.100.000.8-032.000', 'NET14'],
            ['SUP-ELK01', 'PT Terang Listrik Perkasa', 'Budi Santoso', '021-5550109', 'sales@teranglistrik.test', 'Jl. Kenari Raya No. 18, Jakarta Pusat', '01.100.000.9-071.000', 'NET30'],
            ['SUP-BGN01', 'PT Kokoh Bangun Material', 'Slamet Riyadi', '031-5550110', 'order@kokohbangun.test', 'Jl. Raya Gresik Km 15, Gresik', '01.100.001.0-644.000', 'COD'],
        ];
    }

    /**
     * Fictional customers. Rows are [code, name, contact, phone, email, address, tax id, credit limit, payment term code].
     *
     * @return list<array{string, string, string, string, string, string, string|null, string, string}>
     */
    public static function customers(): array
    {
        return [
            ['CUS-TK001', 'Toko Sembako Barokah', 'Ibu Sumiati', '0812-5550-0201', 'tokobarokah@contoh.test', 'Pasar Minggu Blok A No. 12, Jakarta Selatan', null, '15000000', 'NET7'],
            ['CUS-TK002', 'Toko Sumber Rezeki', 'Pak Darto', '0812-5550-0202', 'sumberrezeki@contoh.test', 'Jl. Kaliurang Km 6, Sleman', null, '10000000', 'COD'],
            ['CUS-TK003', 'Toko Maju Jaya', 'Ko Ahong', '0812-5550-0203', 'majujaya@contoh.test', 'Pasar Baru Blok D No. 7, Bandung', '02.200.000.3-423.000', '25000000', 'NET14'],
            ['CUS-TK004', 'Warung Bu Tini', 'Ibu Tini', '0812-5550-0204', 'warungtini@contoh.test', 'Jl. Pemuda No. 88, Purwokerto', null, '3000000', 'COD'],
            ['CUS-GR001', 'Grosir Sentral Pangan', 'Pak Haris', '021-5550205', 'sentralpangan@contoh.test', 'Pasar Induk Kramat Jati Blok F, Jakarta Timur', '02.200.000.5-005.000', '150000000', 'NET30'],
            ['CUS-GR002', 'Grosir Murah Abadi', 'Ibu Linda', '031-5550206', 'murahabadi@contoh.test', 'Jl. Kembang Jepun No. 45, Surabaya', '02.200.000.6-613.000', '100000000', 'NET30'],
            ['CUS-GR003', 'UD Sinar Harapan', 'Pak Rahmat', '061-5550207', 'sinarharapan@contoh.test', 'Jl. Sutomo No. 120, Medan', '02.200.000.7-111.000', '75000000', 'NET14'],
            ['CUS-MM001', 'Minimarket Harian Kita', 'Sinta Maharani', '021-5550208', 'purchasing@harian-kita.test', 'Jl. Raya Bogor Km 30, Depok', '02.200.000.8-412.000', '60000000', 'NET30'],
            ['CUS-MM002', 'Swalayan Prima Keluarga', 'Dimas Prakoso', '024-5550209', 'purchasing@primakeluarga.test', 'Jl. Pandanaran No. 66, Semarang', '02.200.000.9-503.000', '80000000', 'NET30'],
            ['CUS-MM003', 'Swalayan Berkah Timur', 'Andi Baso', '0411-5550210', 'order@berkahtimur.test', 'Jl. Perintis Kemerdekaan Km 9, Makassar', '02.200.001.0-801.000', '50000000', 'NET30'],
            ['CUS-HR001', 'Hotel Cempaka Asri', 'Maya Sari', '0274-5550211', 'purchasing@cempakaasri.test', 'Jl. Malioboro No. 150, Yogyakarta', '02.200.001.1-541.000', '40000000', 'NET30'],
            ['CUS-HR002', 'Rumah Makan Padang Sederhana Rasa', 'Uda Rizal', '0751-5550212', 'sederhanarasa@contoh.test', 'Jl. Khatib Sulaiman No. 21, Padang', null, '20000000', 'NET7'],
            ['CUS-HR003', 'Katering Dapur Ibu', 'Ibu Ratna', '022-5550213', 'dapuribu@contoh.test', 'Jl. Setiabudi No. 200, Bandung', '02.200.001.3-428.000', '30000000', 'NET14'],
            ['CUS-KT001', 'PT Graha Karya Sentosa', 'Fitri Handayani', '021-5550214', 'ga@grahakarya.test', 'Jl. Sudirman Kav. 52, Jakarta Selatan', '02.200.001.4-012.000', '50000000', 'NET30'],
            ['CUS-KT002', 'Koperasi Karyawan Sejahtera', 'Pak Wawan', '021-5550215', 'koperasi@sejahtera.test', 'Kawasan Industri Jababeka Blok V, Cikarang', '02.200.001.5-414.000', '35000000', 'NET14'],
            ['CUS-BG001', 'Toko Bangunan Sumber Makmur', 'Pak Teguh', '0341-5550216', 'sumbermakmur@contoh.test', 'Jl. Raya Singosari No. 90, Malang', '02.200.001.6-623.000', '120000000', 'NET30'],
            ['CUS-BG002', 'Depo Material Jaya Abadi', 'Ko Hendri', '0778-5550217', 'jayaabadi@contoh.test', 'Jl. Ahmad Yani No. 12, Batam', '02.200.001.7-215.000', '90000000', 'NET30'],
            ['CUS-AP001', 'Apotek Sehat Sentosa', 'Apt. Nadia', '0361-5550218', 'apoteksehat@contoh.test', 'Jl. Teuku Umar No. 40, Denpasar', '02.200.001.8-901.000', '25000000', 'NET14'],
        ];
    }
}
