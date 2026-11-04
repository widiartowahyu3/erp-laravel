<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Sales\DeliveryOrderService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
});

/**
 * Remove every journal so documents look as if they were posted before the accounting phase.
 */
function forgetAllJournals(): void
{
    DB::table('journal_lines')->delete();
    DB::table('journals')->update(['reversal_of_id' => null]);
    DB::table('journals')->delete();
}

/**
 * @return array{gr: int, do: int, sa: int}
 */
function postOneOfEachDocument(): array
{
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $gr = (new GoodsReceiptService(new StockService))->post(
        AccountingFixtures::draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '950.0000']], $warehouseId)
    );
    $do = (new DeliveryOrderService(new StockService))->post(
        AccountingFixtures::draftDelivery($productId, $warehouseId, '3.0000', '2000.0000')
    );

    $saService = new StockAdjustmentService(new StockService);
    $sa = $saService->post($saService->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '5.0000']],
    ]));

    return ['gr' => $gr->id, 'do' => $do->id, 'sa' => $sa->id];
}

test('dry run reports counts and writes nothing', function (): void {
    postOneOfEachDocument();
    forgetAllJournals();
    $auditBefore = DB::table('audit_logs')->count();

    $this->artisan('erp:journals:backfill')
        ->expectsOutputToContain('DRY-RUN')
        ->expectsOutputToContain('Penerimaan barang: 1')
        ->expectsOutputToContain('Surat jalan: 1')
        ->expectsOutputToContain('Penyesuaian stok: 1')
        ->assertSuccessful();

    expect(Journal::count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe($auditBefore);
});

test('execute recreates journals identical to those posted live, dated by the document', function (): void {
    $ids = postOneOfEachDocument();

    $snapshot = fn (): array => Journal::query()->with('lines')->orderBy('source_type')->get()->map(fn (Journal $j): array => [
        $j->source_type, $j->source_id, $j->journal_date->toDateString(), $j->status->value, $j->total_debit,
        $j->lines->map(fn ($l): array => [$l->line_no, $l->account_id, $l->debit, $l->credit])->all(),
    ])->all();

    $live = $snapshot();
    expect($live)->toHaveCount(3);

    forgetAllJournals();

    $this->artisan('erp:journals:backfill', ['--execute' => true])
        ->expectsOutputToContain('Penerimaan barang: 1')
        ->assertSuccessful();

    expect($snapshot())->toBe($live)
        ->and(Journal::where('source_type', 'goods_receipt')->where('source_id', $ids['gr'])->value('posted_by'))->not->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'journal.backfill')->exists())->toBeTrue();
});

test('running execute twice does not duplicate journals', function (): void {
    postOneOfEachDocument();
    forgetAllJournals();

    $this->artisan('erp:journals:backfill', ['--execute' => true])->assertSuccessful();
    $this->artisan('erp:journals:backfill', ['--execute' => true])
        ->expectsOutputToContain('Penerimaan barang: 0')
        ->assertSuccessful();

    expect(Journal::count())->toBe(3);
});

test('documents that already have a journal, drafts and voided documents are skipped', function (): void {
    $service = new GoodsReceiptService(new StockService);
    $product = TestDataFactory::createProduct();

    $journaled = $service->post(AccountingFixtures::draftReceipt([['product' => $product, 'quantity' => '1.0000', 'cost' => '10.0000']]));
    AccountingFixtures::draftReceipt([['product' => $product, 'quantity' => '1.0000', 'cost' => '10.0000']]);
    $voided = $service->post(AccountingFixtures::draftReceipt([['product' => $product, 'quantity' => '1.0000', 'cost' => '10.0000']]));
    $service->void($voided, 'Batal');
    DB::table('journal_lines')->whereIn('journal_id', Journal::where('source_id', $voided->id)->pluck('id'))->delete();
    Journal::where('source_id', $voided->id)->where('source_type', 'goods_receipt')->whereNotNull('reversal_of_id')->delete();
    Journal::where('source_id', $voided->id)->where('source_type', 'goods_receipt')->delete();

    $this->artisan('erp:journals:backfill', ['--execute' => true])
        ->expectsOutputToContain('Penerimaan barang: 0')
        ->assertSuccessful();

    expect(Journal::where('source_type', 'goods_receipt')->count())->toBe(1)
        ->and(Journal::where('source_type', 'goods_receipt')->value('source_id'))->toBe($journaled->id);
});
