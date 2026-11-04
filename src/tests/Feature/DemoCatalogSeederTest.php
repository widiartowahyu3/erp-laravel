<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @return list<string>
 */
function demoCatalogSkus(): array
{
    $skus = [];
    foreach (DemoCatalogSeeder::catalog() as $group) {
        foreach ($group['items'] as $item) {
            $skus[] = $item[0];
        }
    }

    return $skus;
}

test('demo catalog seeder inserts every catalog row with transaction-ready products', function (): void {
    (new DemoCatalogSeeder)->run();

    $skus = demoCatalogSkus();

    expect(count($skus))->toBe(count(array_unique($skus)))
        ->and(count($skus))->toBeGreaterThanOrEqual(100);

    $products = DB::table('products')->whereIn('sku', $skus)->get();
    expect($products)->toHaveCount(count($skus));

    foreach ($products as $product) {
        expect((bool) $product->track_stock)->toBeTrue()
            ->and((bool) $product->is_active)->toBeTrue()
            ->and((float) $product->selling_price)->toBeGreaterThan(0);
    }

    expect(DB::table('suppliers')->whereIn('code', array_column(DemoCatalogSeeder::suppliers(), 0))->count())
        ->toBe(count(DemoCatalogSeeder::suppliers()))
        ->and(DB::table('customers')->whereIn('code', array_column(DemoCatalogSeeder::customers(), 0))->count())
        ->toBe(count(DemoCatalogSeeder::customers()))
        ->and(DB::table('warehouses')->whereIn('code', array_column(DemoCatalogSeeder::warehouses(), 'code'))->count())
        ->toBe(count(DemoCatalogSeeder::warehouses()));
});

test('demo catalog categories all resolve to a child of a parent category', function (): void {
    (new DemoCatalogSeeder)->run();

    foreach (DemoCatalogSeeder::categories() as $parentCode => $parent) {
        $parentId = DB::table('product_categories')->where('code', $parentCode)->value('id');

        foreach (array_keys($parent['children']) as $childCode) {
            expect(DB::table('product_categories')->where('code', $childCode)->value('parent_id'))->toBe($parentId);
        }
    }
});

test('demo catalog barcodes are unique valid EAN-13 codes in the fictional range', function (): void {
    $barcodes = array_map(DemoCatalogSeeder::barcodeFor(...), demoCatalogSkus());

    expect(count($barcodes))->toBe(count(array_unique($barcodes)));

    foreach ($barcodes as $barcode) {
        expect($barcode)->toMatch('/^299\d{10}$/');

        $sum = 0;
        foreach (str_split($barcode) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        expect($sum % 10)->toBe(0);
    }
});

test('demo catalog seeder is idempotent and keeps manual edits on re-run', function (): void {
    $seeder = new DemoCatalogSeeder;
    $seeder->run();

    $counts = fn (): array => [
        DB::table('products')->count(),
        DB::table('suppliers')->count(),
        DB::table('customers')->count(),
        DB::table('product_categories')->count(),
    ];
    $before = $counts();

    DB::table('products')->where('sku', 'MIE-001')->update(['selling_price' => '999.00']);

    $seeder->run();

    expect($counts())->toBe($before)
        ->and((string) DB::table('products')->where('sku', 'MIE-001')->value('selling_price'))->toBe('999.00');
});

test('demo catalog seeder aborts with runtime exception in production environment', function (): void {
    App::detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoCatalogSeeder)->run())
        ->toThrow(RuntimeException::class, 'DemoCatalogSeeder tidak boleh dijalankan di lingkungan production!');
});
