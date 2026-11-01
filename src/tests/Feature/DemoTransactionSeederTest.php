<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Support\Decimal;
use Database\Seeders\DemoMasterDataSeeder;
use Database\Seeders\DemoTransactionSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

test('demo transaction seeder populates documents matching fixed scenario and maintains stock ledger consistency', function (): void {
    // 1. Pastikan master data tersedia
    (new DemoMasterDataSeeder)->run();

    $seeder = new DemoTransactionSeeder;

    // 2. Eksekusi pertama kali
    $seeder->run();

    // 3. Verifikasi jumlah Purchase Order per status
    $poTotal = PurchaseOrder::query()->count();
    $poDraft = PurchaseOrder::query()->where('status', 'draft')->count();
    $poPartiallyReceived = PurchaseOrder::query()->where('status', 'partially_received')->count();
    $poReceived = PurchaseOrder::query()->where('status', 'received')->count();
    $poVoid = PurchaseOrder::query()->where('status', 'void')->count();

    expect($poTotal)->toBe(4)
        ->and($poDraft)->toBe(1)
        ->and($poPartiallyReceived)->toBe(1)
        ->and($poReceived)->toBe(1)
        ->and($poVoid)->toBe(1);

    // 4. Verifikasi jumlah Goods Receipt (3 dokumen seluruhnya berstatus posted)
    $grTotal = GoodsReceipt::query()->count();
    $grPosted = GoodsReceipt::query()->where('status', 'posted')->count();

    expect($grTotal)->toBe(3)
        ->and($grPosted)->toBe(3);

    // 5. Verifikasi jumlah Stock Adjustment (2 posted, 1 draft)
    $saTotal = StockAdjustment::query()->count();
    $saPosted = StockAdjustment::query()->where('status', 'posted')->count();
    $saDraft = StockAdjustment::query()->where('status', 'draft')->count();

    expect($saTotal)->toBe(3)
        ->and($saPosted)->toBe(2)
        ->and($saDraft)->toBe(1);

    // 6. Verifikasi konsistensi Ledger: SUM(stock_movements.quantity) = stock_balances.quantity
    $balances = DB::table('stock_balances')->get();
    expect($balances->count())->toBeGreaterThan(0);

    foreach ($balances as $bal) {
        $movementSum = (string) DB::table('stock_movements')
            ->where('product_id', $bal->product_id)
            ->where('warehouse_id', $bal->warehouse_id)
            ->sum('quantity');

        $roundedMovementSum = Decimal::round($movementSum, Decimal::SCALE_QUANTITY);
        $roundedBalance = Decimal::round((string) $bal->quantity, Decimal::SCALE_QUANTITY);

        expect(Decimal::cmp($roundedMovementSum, $roundedBalance) === 0)->toBeTrue();
    }

    // 7. Verifikasi sebagian produk berada di bawah min_stock
    $lowStockProducts = Product::query()
        ->where('is_active', true)
        ->where('track_stock', true)
        ->where('min_stock', '>', 0)
        ->get()
        ->filter(function (Product $p): bool {
            $totalQty = (string) DB::table('stock_balances')
                ->where('product_id', $p->id)
                ->sum('quantity');

            return Decimal::cmp($totalQty, (string) $p->min_stock) < 0;
        });

    expect($lowStockProducts->count())->toBeGreaterThan(0);

    // 8. Idempotensi: Eksekusi kedua tidak menambah baris baru
    $movementsBefore = DB::table('stock_movements')->count();
    $balancesBefore = DB::table('stock_balances')->count();

    $seeder->run();

    expect(PurchaseOrder::query()->count())->toBe($poTotal)
        ->and(GoodsReceipt::query()->count())->toBe($grTotal)
        ->and(StockAdjustment::query()->count())->toBe($saTotal)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and(DB::table('stock_balances')->count())->toBe($balancesBefore);
});

test('demo transaction seeder aborts with runtime exception in production environment', function (): void {
    App::detectEnvironment(fn () => 'production');

    $seeder = new DemoTransactionSeeder;

    expect(fn () => $seeder->run())
        ->toThrow(RuntimeException::class, 'DemoTransactionSeeder tidak boleh dijalankan di lingkungan production!');
});
