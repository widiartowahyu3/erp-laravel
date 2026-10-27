<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Models\TestUnit;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Clean up units table before each test
    DB::table('units')->delete();
});

test('HasAuditUsers fills created_by and updated_by from authenticated user when omitted', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $unit = TestUnit::create([
        'code' => 'PCS',
        'name' => 'Pieces',
    ]);

    expect($unit->created_by)->toBe($user->id)
        ->and($unit->updated_by)->toBe($user->id);
});

test('HasAuditUsers does not overwrite explicitly supplied created_by and updated_by', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);

    $unit = TestUnit::create([
        'code' => 'BOX',
        'name' => 'Box',
        'created_by' => $userB->id,
        'updated_by' => $userB->id,
    ]);

    expect($unit->created_by)->toBe($userB->id)
        ->and($unit->updated_by)->toBe($userB->id);
});

test('HasAuditUsers updates updated_by on update while preserving created_by', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);
    $unit = TestUnit::create([
        'code' => 'KG',
        'name' => 'Kilogram',
    ]);

    $this->actingAs($userB);
    $unit->update([
        'name' => 'Kilo Gram',
    ]);

    $unit->refresh();
    expect($unit->created_by)->toBe($userA->id)
        ->and($unit->updated_by)->toBe($userB->id);
});

test('RecordsAudit records created, updated, deleted, and restored events with exact diffs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // 1. Create
    $unit = TestUnit::create([
        'code' => 'MTR',
        'name' => 'Meter',
    ]);

    $createAudit = DB::table('audit_logs')
        ->where('auditable_type', $unit->getMorphClass())
        ->where('auditable_id', $unit->id)
        ->where('event', 'unit.created')
        ->first();

    expect($createAudit)->not->toBeNull()
        ->and($createAudit->old_values)->toBeNull();

    /** @var array<string, mixed> $createPayload */
    $createPayload = json_decode((string) $createAudit->new_values, true);
    expect($createPayload['code'])->toBe('MTR')
        ->and($createPayload['name'])->toBe('Meter')
        ->and(array_key_exists('updated_at', $createPayload))->toBeFalse();

    // 2. Update (change only 'name', 'code' remains unchanged)
    $unit->update([
        'name' => 'Meter Panjang',
    ]);

    $updateAudit = DB::table('audit_logs')
        ->where('auditable_type', $unit->getMorphClass())
        ->where('auditable_id', $unit->id)
        ->where('event', 'unit.updated')
        ->first();

    expect($updateAudit)->not->toBeNull();

    /** @var array<string, mixed> $oldDiff */
    $oldDiff = json_decode((string) $updateAudit->old_values, true);
    /** @var array<string, mixed> $newDiff */
    $newDiff = json_decode((string) $updateAudit->new_values, true);

    // Only changed attribute is present in old and new diff
    expect($oldDiff)->toHaveKey('name', 'Meter')
        ->and($newDiff)->toHaveKey('name', 'Meter Panjang')
        ->and(array_key_exists('code', $oldDiff))->toBeFalse()
        ->and(array_key_exists('code', $newDiff))->toBeFalse()
        ->and(array_key_exists('updated_at', $oldDiff))->toBeFalse()
        ->and(array_key_exists('updated_at', $newDiff))->toBeFalse();

    // 3. Delete (soft delete)
    $unit->delete();

    $deleteAudit = DB::table('audit_logs')
        ->where('auditable_type', $unit->getMorphClass())
        ->where('auditable_id', $unit->id)
        ->where('event', 'unit.deleted')
        ->first();

    expect($deleteAudit)->not->toBeNull()
        ->and($deleteAudit->new_values)->toBeNull();

    /** @var array<string, mixed> $deletePayload */
    $deletePayload = json_decode((string) $deleteAudit->old_values, true);
    expect($deletePayload['code'])->toBe('MTR')
        ->and(array_key_exists('updated_at', $deletePayload))->toBeFalse();

    // 4. Restore
    $unit->restore();

    $restoreAudit = DB::table('audit_logs')
        ->where('auditable_type', $unit->getMorphClass())
        ->where('auditable_id', $unit->id)
        ->where('event', 'unit.restored')
        ->first();

    expect($restoreAudit)->not->toBeNull()
        ->and($restoreAudit->old_values)->toBeNull();

    /** @var array<string, mixed> $restorePayload */
    $restorePayload = json_decode((string) $restoreAudit->new_values, true);
    expect($restorePayload['code'])->toBe('MTR')
        ->and(array_key_exists('updated_at', $restorePayload))->toBeFalse();
});

test('RecordsAudit never records sensitive keys or unchanged attributes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $unit = TestUnit::create([
        'code' => 'SENS',
        'name' => 'Sensitive Test',
    ]);

    // Update with sensitive field in raw array and normal update
    $unit->update([
        'name' => 'Sensitive Renamed',
    ]);

    $lastAudit = DB::table('audit_logs')
        ->where('auditable_type', $unit->getMorphClass())
        ->where('auditable_id', $unit->id)
        ->where('event', 'unit.updated')
        ->first();

    expect($lastAudit)->not->toBeNull();
    $old = (string) $lastAudit->old_values;
    $new = (string) $lastAudit->new_values;

    expect($old)->not->toContain('password')
        ->and($old)->not->toContain('secret')
        ->and($old)->not->toContain('token')
        ->and($old)->not->toContain('updated_at')
        ->and($new)->not->toContain('password')
        ->and($new)->not->toContain('secret')
        ->and($new)->not->toContain('token')
        ->and($new)->not->toContain('updated_at');
});

test('failed update leaves no residual pending changes and subsequent update records accurate diff', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // 1. Create two units
    $unitA = TestUnit::create(['code' => 'U_A', 'name' => 'Original A']);
    TestUnit::create(['code' => 'U_B', 'name' => 'Unit B']);

    // 2. Attempt an update on unitA that fails database unique constraint
    $unitA->code = 'U_B'; // Duplicate code!
    $unitA->name = 'Failed Attempt Name';

    $failed = false;
    try {
        $unitA->save();
    } catch (QueryException $e) {
        $failed = true;
    }
    expect($failed)->toBeTrue();

    // 3. Reset unitA code to a valid new value and different name, then save successfully
    $unitA->code = 'U_A_NEW';
    $unitA->name = 'Successfully Renamed A';
    $unitA->save();

    // 4. Retrieve the updated audit log
    $updateAudit = DB::table('audit_logs')
        ->where('auditable_type', $unitA->getMorphClass())
        ->where('auditable_id', $unitA->id)
        ->where('event', 'unit.updated')
        ->latest('id')
        ->first();

    expect($updateAudit)->not->toBeNull();

    /** @var array<string, mixed> $oldDiff */
    $oldDiff = json_decode((string) $updateAudit->old_values, true);
    /** @var array<string, mixed> $newDiff */
    $newDiff = json_decode((string) $updateAudit->new_values, true);

    // Old values must reflect the clean original values, new values reflect the successful update
    expect($oldDiff)->toBe([
        'code' => 'U_A',
        'name' => 'Original A',
    ])->and($newDiff)->toBe([
        'code' => 'U_A_NEW',
        'name' => 'Successfully Renamed A',
    ]);

    // Ensure 'Failed Attempt Name' never leaked into the audit trail
    expect((string) $updateAudit->old_values)->not->toContain('Failed Attempt Name')
        ->and((string) $updateAudit->new_values)->not->toContain('Failed Attempt Name');
});
