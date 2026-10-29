<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DemoMasterDataSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

test('demo master data seeder runs idempotently and populates all 8 tables', function (): void {
    $seeder = new DemoMasterDataSeeder;

    // Run first time
    $seeder->run();

    // Verify all 8 tables have records
    expect(DB::table('units')->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('payment_terms')->count())->toBeGreaterThanOrEqual(5)
        ->and(DB::table('tax_rates')->count())->toBeGreaterThanOrEqual(4)
        ->and(DB::table('warehouses')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('product_categories')->count())->toBeGreaterThanOrEqual(7)
        ->and(DB::table('products')->count())->toBeGreaterThanOrEqual(10)
        ->and(DB::table('suppliers')->count())->toBeGreaterThanOrEqual(4)
        ->and(DB::table('customers')->count())->toBeGreaterThanOrEqual(4);

    $countProducts1 = DB::table('products')->count();

    // Run second time (must not fail or duplicate rows)
    $seeder->run();

    $countProducts2 = DB::table('products')->count();
    expect($countProducts2)->toBe($countProducts1);
});

test('demo master data seeder aborts with runtime exception in production environment', function (): void {
    App::detectEnvironment(fn () => 'production');

    $seeder = new DemoMasterDataSeeder;

    expect(fn () => $seeder->run())
        ->toThrow(RuntimeException::class, 'DemoMasterDataSeeder tidak boleh dijalankan di lingkungan production!');
});
