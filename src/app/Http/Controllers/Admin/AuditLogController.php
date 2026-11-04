<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only by design: there is no route to change, delete or export entries.
 */
final class AuditLogController extends Controller
{
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(AuditLog::query()->with('user:id,name'))
            ->filterable([
                'date_from' => function (Builder $builder, mixed $value): void {
                    if ($this->isDate($value)) {
                        $builder->where('created_at', '>=', "{$value} 00:00:00");
                    }
                },
                'date_to' => function (Builder $builder, mixed $value): void {
                    if ($this->isDate($value)) {
                        $builder->where('created_at', '<=', "{$value} 23:59:59");
                    }
                },
                'user_id' => function (Builder $builder, mixed $value): void {
                    $builder->where('user_id', (int) $value);
                },
                'event' => function (Builder $builder, mixed $value): void {
                    $builder->where('event', 'LIKE', ListQuery::escapeLike(mb_substr((string) $value, 0, 50)).'%');
                },
                'auditable_type' => function (Builder $builder, mixed $value): void {
                    $builder->where('auditable_type', 'LIKE', '%'.ListQuery::escapeLike(mb_substr((string) $value, 0, 100)).'%');
                },
            ])
            ->defaultSort('created_at', 'desc')
            ->paginate($request, 25);

        return Inertia::render('Admin/AuditLogs/Index', [
            'items' => Paginated::from($paginator, fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'user' => $log->user !== null ? ['id' => (int) $log->user->id, 'name' => $log->user->name] : null,
            ], $request),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ]);
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match(self::DATE_PATTERN, $value) === 1 && strtotime($value) !== false;
    }
}
