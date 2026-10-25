<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('erp_app connection has DML privileges (SELECT, INSERT, UPDATE, DELETE)', function () {
    $email = 'dml_test_'.uniqid().'@example.com';

    // INSERT
    $inserted = DB::connection('mariadb')->table('users')->insert([
        'name' => 'DML Test',
        'email' => $email,
        'password' => 'secret_password_hash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($inserted)->toBeTrue();

    // SELECT
    $user = DB::connection('mariadb')->table('users')->where('email', $email)->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('DML Test');

    // UPDATE
    $updated = DB::connection('mariadb')->table('users')->where('email', $email)->update([
        'name' => 'DML Test Updated',
    ]);
    expect($updated)->toBe(1);

    // DELETE
    $deleted = DB::connection('mariadb')->table('users')->where('email', $email)->delete();
    expect($deleted)->toBe(1);

    $userAfterDelete = DB::connection('mariadb')->table('users')->where('email', $email)->first();
    expect($userAfterDelete)->toBeNull();
});

test('erp_app connection is strictly denied DDL privileges (CREATE, DROP)', function () {
    // Attempt CREATE TABLE
    $createFailedWithAccessDenied = false;
    try {
        DB::connection('mariadb')->statement('CREATE TABLE test_privilege_forbidden (id INT)');
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'command denied') || str_contains($msg, 'access denied') || str_contains($e->getMessage(), '42000')) {
            $createFailedWithAccessDenied = true;
        }
    }
    expect($createFailedWithAccessDenied)->toBeTrue();

    // Attempt DROP TABLE
    $dropFailedWithAccessDenied = false;
    try {
        DB::connection('mariadb')->statement('DROP TABLE users');
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'command denied') || str_contains($msg, 'access denied') || str_contains($e->getMessage(), '42000')) {
            $dropFailedWithAccessDenied = true;
        }
    }
    expect($dropFailedWithAccessDenied)->toBeTrue();
});

test('erp_migrator connection has DDL privileges (CREATE, DROP)', function () {
    if (empty(env('DB_MIGRATOR_PASSWORD'))) {
        $this->markTestSkipped('DB_MIGRATOR_PASSWORD is not set in environment.');
    }

    $tableName = 'test_privilege_migrator_'.uniqid();

    // Attempt CREATE TABLE
    DB::connection('mariadb_migrator')->statement("CREATE TABLE {$tableName} (id INT PRIMARY KEY, name VARCHAR(255))");
    expect(Schema::connection('mariadb_migrator')->hasTable($tableName))->toBeTrue();

    // Attempt DROP TABLE
    DB::connection('mariadb_migrator')->statement("DROP TABLE {$tableName}");
    expect(Schema::connection('mariadb_migrator')->hasTable($tableName))->toBeFalse();
});
