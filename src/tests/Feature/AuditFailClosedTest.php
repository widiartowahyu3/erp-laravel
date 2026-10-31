<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('audit logger record in strict mode rethrows exception on failure while non-strict returns false', function (): void {
    $nonExistentUserId = 99999999;

    // 1. Non-strict mode: catches error, logs it, and returns false without throwing
    $nonStrictResult = AuditLogger::record(
        event: 'unit.test_non_strict',
        auditableType: 'unit_test',
        auditableId: 1,
        userId: $nonExistentUserId,
        strict: false
    );
    expect($nonStrictResult)->toBeFalse();

    // 2. Strict mode: must rethrow the QueryException caused by foreign key failure
    expect(fn () => AuditLogger::record(
        event: 'unit.test_strict',
        auditableType: 'unit_test',
        auditableId: 1,
        userId: $nonExistentUserId,
        strict: true
    ))->toThrow(QueryException::class);

    // 3. Positive control: valid user ID returns true
    $validUserId = TestDataFactory::createUser();
    $positiveResult = AuditLogger::record(
        event: 'unit.test_success',
        auditableType: 'unit_test',
        auditableId: 1,
        userId: $validUserId,
        strict: true
    );
    expect($positiveResult)->toBeTrue();
});

test('model data changes fail closed inside transaction when audit recording fails', function (): void {
    $validUserId = TestDataFactory::createUser();
    $nonExistentUserId = 99999999;
    $catId = TestDataFactory::createCategory();
    $unitId = TestDataFactory::createUnit();

    $skuFailed = 'SKU-FAIL-'.bin2hex(random_bytes(4));

    // Integration failure test:
    // When Auth user ID does not exist in users table (FK violation on audit_logs.user_id),
    // product creation must fail and the transaction must rollback completely.
    $fakeUser = new User;
    $fakeUser->id = $nonExistentUserId;
    Auth::setUser($fakeUser);

    $exceptionThrown = false;
    try {
        DB::transaction(function () use ($skuFailed, $catId, $unitId, $validUserId) {
            $product = new Product;
            $product->forceFill([
                'sku' => $skuFailed,
                'name' => 'Should Rollback Product',
                'category_id' => $catId,
                'base_unit_id' => $unitId,
                'track_stock' => true,
                'selling_price' => '10000.0000',
                'min_stock' => '0.0000',
                'is_active' => true,
                'created_by' => $validUserId,
                'updated_by' => $validUserId,
            ]);
            $product->save();
        });
    } catch (\Throwable $e) {
        $exceptionThrown = true;
    }

    expect($exceptionThrown)->toBeTrue('Product creation must throw exception when audit logging fails');

    // Assert that product was rolled back and does not exist in DB
    $productInDb = DB::table('products')->where('sku', $skuFailed)->first();
    expect($productInDb)->toBeNull('Product must NOT exist in database after audit failure rollback');

    // Positive control: with valid Auth user, product and audit log are successfully created
    $validUser = User::find($validUserId);
    Auth::setUser($validUser);

    $skuSuccess = 'SKU-OK-'.bin2hex(random_bytes(4));
    DB::transaction(function () use ($skuSuccess, $catId, $unitId, $validUserId) {
        $product = new Product;
        $product->forceFill([
            'sku' => $skuSuccess,
            'name' => 'Should Succeed Product',
            'category_id' => $catId,
            'base_unit_id' => $unitId,
            'track_stock' => true,
            'selling_price' => '10000.0000',
            'min_stock' => '0.0000',
            'is_active' => true,
            'created_by' => $validUserId,
            'updated_by' => $validUserId,
        ]);
        $product->save();
    });

    $successProduct = DB::table('products')->where('sku', $skuSuccess)->first();
    expect($successProduct)->not->toBeNull('Product must exist in database when audit succeeds');

    $auditLog = DB::table('audit_logs')
        ->where('auditable_type', Product::class)
        ->where('auditable_id', $successProduct->id)
        ->where('event', 'product.created')
        ->first();
    expect($auditLog)->not->toBeNull('Audit log must be created for product');
});
