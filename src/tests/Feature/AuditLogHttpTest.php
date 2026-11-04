<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->viewer = userWithPermissions(['audit_logs.view']);
    DB::table('audit_logs')->insert([
        ['user_id' => $this->viewer->id, 'event' => 'uji.pertama', 'auditable_type' => 'App\\Models\\Role', 'auditable_id' => 1, 'old_values' => json_encode(['a' => 1]), 'new_values' => json_encode(['b' => '<script>x</script>']), 'created_at' => '2026-01-10 10:00:00'],
        ['user_id' => null, 'event' => 'uji.kedua', 'auditable_type' => 'auth', 'auditable_id' => 0, 'old_values' => null, 'new_values' => null, 'created_at' => '2026-01-20 10:00:00'],
    ]);
});

test('the audit log requires its permission', function (): void {
    $this->actingAs(userWithPermissions(['products.view']))->get('/admin/audit-logs')->assertStatus(403);
});

test('there is no route to change or export audit logs', function (string $method): void {
    $this->actingAs($this->viewer)->{$method}('/admin/audit-logs/1')->assertStatus(404);
})->with(['put', 'delete', 'post']);

test('newest entries come first, with decoded values and the actor name', function (): void {
    $this->actingAs($this->viewer)->get('/admin/audit-logs?filter[event]=uji.')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/AuditLogs/Index')
        ->has('items.data', 2)
        ->where('items.data.0.event', 'uji.kedua')
        ->where('items.data.0.user', null)
        ->where('items.data.1.event', 'uji.pertama')
        ->where('items.data.1.user.id', $this->viewer->id)
        ->where('items.data.1.old_values.a', 1)
        ->where('items.data.1.new_values.b', '<script>x</script>')
        ->has('users')
    );
});

test('filters by date range, user, event and object type', function (string $query, string $expected): void {
    $query = str_replace('{user}', (string) $this->viewer->id, $query);

    $this->actingAs($this->viewer)->get("/admin/audit-logs?filter[event]=uji.&{$query}")->assertInertia(fn (Assert $page) => $page
        ->has('items.data', 1)
        ->where('items.data.0.event', $expected)
    );
})->with([
    'date from' => ['filter[date_from]=2026-01-15', 'uji.kedua'],
    'date to includes the whole day' => ['filter[date_to]=2026-01-10', 'uji.pertama'],
    'user' => ['filter[user_id]={user}', 'uji.pertama'],
    'object type' => ['filter[auditable_type]=auth', 'uji.kedua'],
]);

test('malformed date filters are ignored instead of failing', function (): void {
    $this->actingAs($this->viewer)->get('/admin/audit-logs?filter[event]=uji.&filter[date_from]=bukan-tanggal')->assertInertia(fn (Assert $page) => $page->has('items.data', 2));
});

test('page size is capped at 100', function (): void {
    $this->actingAs($this->viewer)->get('/admin/audit-logs?per_page=5000')->assertInertia(fn (Assert $page) => $page->where('items.meta.per_page', 100));
});

test('index does not run a query per entry', function (): void {
    $this->actingAs($this->viewer);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/audit-logs')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $withFew = $count();

    foreach (User::factory()->count(5)->create() as $user) {
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'event' => 'uji.banyak', 'auditable_type' => 'auth', 'auditable_id' => 0, 'created_at' => now()]);
    }

    expect($count())->toBe($withFew);
});
