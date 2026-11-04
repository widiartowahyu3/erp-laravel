<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Audit\AuditRetentionService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

function insertAuditRow(CarbonInterface $createdAt, string $event = 'retention.test'): int
{
    return DB::table('audit_logs')->insertGetId([
        'event' => $event,
        'auditable_type' => 'retention_test',
        'auditable_id' => 1,
        'created_at' => $createdAt,
    ]);
}

function assertDeleteRejected(int $id): void
{
    try {
        DB::table('audit_logs')->where('id', $id)->delete();
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('audit_logs is append-only');
        expect(DB::table('audit_logs')->where('id', $id)->exists())->toBeTrue();

        return;
    }

    test()->fail("DELETE on audit row {$id} should have been rejected by the trigger.");
}

test('the delete trigger allows rows past the retention period and rejects newer ones', function (): void {
    $expired = insertAuditRow(now()->subDays(7)->subMinute());
    $recent = insertAuditRow(now()->subDays(7)->addMinute());
    $today = insertAuditRow(now());

    expect(DB::table('audit_logs')->where('id', $expired)->delete())->toBe(1);

    assertDeleteRejected($recent);
    assertDeleteRejected($today);
});

test('the update trigger still rejects every row, so a row cannot be aged into deletion', function (): void {
    $expired = insertAuditRow(now()->subDays(30));

    expect(fn () => DB::table('audit_logs')->where('id', $expired)->update(['event' => 'tampered']))
        ->toThrow(QueryException::class, 'audit_logs is append-only');

    $recent = insertAuditRow(now());

    expect(fn () => DB::table('audit_logs')->where('id', $recent)->update(['created_at' => now()->subDays(30)]))
        ->toThrow(QueryException::class, 'audit_logs is append-only');
});

test('moving the session clock forward cannot delete rows inside the retention period', function (): void {
    $recent = insertAuditRow(now()->subDay());

    DB::statement('SET timestamp = UNIX_TIMESTAMP() + 2592000');

    try {
        assertDeleteRejected($recent);
    } finally {
        DB::statement('SET timestamp = DEFAULT');
    }
});

test('erp:audit:prune removes only expired rows and records what it removed', function (): void {
    $expiredOne = insertAuditRow(now()->subDays(8));
    $expiredTwo = insertAuditRow(now()->subDays(40), 'journal.created');
    $recent = insertAuditRow(now()->subDays(6));

    $exitCode = Artisan::call('erp:audit:prune');

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('2 baris');
    expect(DB::table('audit_logs')->whereIn('id', [$expiredOne, $expiredTwo])->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('id', $recent)->exists())->toBeTrue();

    $record = DB::table('audit_logs')->where('event', 'audit_log.pruned')->sole();
    $values = json_decode((string) $record->new_values, true, 512, JSON_THROW_ON_ERROR);

    expect($values)->toBe(['deleted' => 2, 'retention_days' => 7]);
    expect($record->user_id)->toBeNull();
});

test('erp:audit:prune works through more rows than one batch', function (): void {
    $createdAt = now()->subDays(10)->toDateTimeString();
    $rows = array_fill(0, 1001, [
        'event' => 'retention.batch',
        'auditable_type' => 'retention_test',
        'auditable_id' => 1,
        'created_at' => $createdAt,
    ]);

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('audit_logs')->insert($chunk);
    }

    expect(app(AuditRetentionService::class)->prune())->toBe(1001);
    expect(DB::table('audit_logs')->where('event', 'retention.batch')->exists())->toBeFalse();

    $deleted = DB::table('audit_logs')
        ->where('event', 'audit_log.pruned')
        ->orderBy('id')
        ->pluck('new_values')
        ->map(fn (string $values): int => json_decode($values, true, 512, JSON_THROW_ON_ERROR)['deleted'])
        ->all();

    expect($deleted)->toBe([1000, 1]);
});

test('erp:audit:prune writes nothing when no row has expired', function (): void {
    $recent = insertAuditRow(now());

    expect(Artisan::call('erp:audit:prune'))->toBe(0);
    expect(Artisan::output())->toContain('0 baris');
    expect(DB::table('audit_logs')->where('id', $recent)->exists())->toBeTrue();
    expect(DB::table('audit_logs')->where('event', 'audit_log.pruned')->exists())->toBeFalse();
});

test('a failed audit record rolls the deletion back', function (): void {
    $expired = insertAuditRow(now()->subDays(8));

    $missingUser = new User;
    $missingUser->id = 99999999;
    Auth::setUser($missingUser);

    expect(fn () => app(AuditRetentionService::class)->prune())->toThrow(QueryException::class);

    expect(DB::table('audit_logs')->where('id', $expired)->exists())->toBeTrue();
    expect(DB::table('audit_logs')->where('event', 'audit_log.pruned')->exists())->toBeFalse();
});

test('the prune command is scheduled every day at 01:00', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toContain('0 1 * * *')
        ->toContain('erp:audit:prune');
});
