<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Exceptions\BusinessRuleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleService
{
    private const int MAX_SLUG_LENGTH = 50;

    private const int MIN_SLUG_LENGTH = 3;

    public function __construct(
        private readonly AccessGuard $guard = new AccessGuard
    ) {}

    /**
     * @param  array{display_name: string, description?: string|null, permissions?: list<string>}  $data
     *
     * @throws BusinessRuleException
     */
    public function create(User $actor, array $data): Role
    {
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        $this->guard->assertPermissionsConsistent($permissions);
        $this->guard->assertCanChangePermissions($actor, $permissions, []);

        return DB::transaction(function () use ($actor, $data, $permissions): Role {
            $role = $this->insertRole($actor, trim($data['display_name']), $this->cleanDescription($data['description'] ?? null), $permissions);

            AuditLogger::record(
                event: 'role.created',
                auditableType: Role::class,
                auditableId: (int) $role->id,
                new: [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'permissions' => $permissions,
                ],
                strict: true
            );

            return $role;
        });
    }

    /**
     * @param  array{display_name: string, description?: string|null, permissions?: list<string>}  $data
     *
     * @throws BusinessRuleException
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->guard->assertRoleEditable($role);

        $permissions = array_values(array_unique($data['permissions'] ?? []));
        $this->guard->assertPermissionsConsistent($permissions);

        return DB::transaction(function () use ($actor, $role, $data, $permissions): Role {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->guard->assertRoleEditable($locked);

            $current = $locked->permissions()->pluck('name')->all();
            $added = array_values(array_diff($permissions, $current));
            $removed = array_values(array_diff($current, $permissions));

            $this->guard->assertCanChangePermissions($actor, $added, $removed);

            $old = ['display_name' => $locked->display_name, 'description' => $locked->description];
            $new = ['display_name' => trim($data['display_name']), 'description' => $this->cleanDescription($data['description'] ?? null)];

            $locked->forceFill($new + ['updated_by' => $actor->id])->save();

            if ($old !== $new) {
                AuditLogger::record(
                    event: 'role.updated',
                    auditableType: Role::class,
                    auditableId: (int) $locked->id,
                    old: $old,
                    new: $new,
                    strict: true
                );
            }

            if ($added !== [] || $removed !== []) {
                $locked->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id')->all());

                AuditLogger::record(
                    event: 'role.permissions_changed',
                    auditableType: Role::class,
                    auditableId: (int) $locked->id,
                    new: ['added' => $added, 'removed' => $removed],
                    strict: true
                );
            }

            return $locked;
        });
    }

    /**
     * Copy a role's permissions into a new editable custom role.
     *
     * @throws BusinessRuleException
     */
    public function duplicate(User $actor, Role $source): Role
    {
        $permissions = $source->permissions()->pluck('name')->all();

        $this->guard->assertCanChangePermissions($actor, $permissions, []);

        return DB::transaction(function () use ($actor, $source, $permissions): Role {
            $role = $this->insertRole($actor, mb_substr('Salinan '.$source->display_name, 0, 100), $source->description, $permissions);

            AuditLogger::record(
                event: 'role.created',
                auditableType: Role::class,
                auditableId: (int) $role->id,
                new: [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'duplicated_from' => $source->name,
                    'permissions' => $permissions,
                ],
                strict: true
            );

            return $role;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function delete(User $actor, Role $role): void
    {
        DB::transaction(function () use ($actor, $role): void {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            $this->guard->assertRoleDeletable($locked);

            $permissions = $locked->permissions()->pluck('name')->all();
            $this->guard->assertCanChangePermissions($actor, [], $permissions);

            AuditLogger::record(
                event: 'role.deleted',
                auditableType: Role::class,
                auditableId: (int) $locked->id,
                old: [
                    'name' => $locked->name,
                    'display_name' => $locked->display_name,
                    'permissions' => $permissions,
                ],
                strict: true
            );

            // @audit-bypass: recorded explicitly above because Role has no model audit events
            $locked->delete();
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function insertRole(User $actor, string $displayName, ?string $description, array $permissions): Role
    {
        $role = new Role;
        $role->forceFill([
            'name' => $this->uniqueSlug($displayName),
            'display_name' => $displayName,
            'description' => $description,
            'is_system' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id')->all());

        return $role;
    }

    private function cleanDescription(?string $description): ?string
    {
        $clean = trim((string) $description);

        return $clean !== '' ? $clean : null;
    }

    /**
     * Build a slug matching ^[a-z][a-z0-9_]{2,49}$, suffixed with a number when taken.
     */
    private function uniqueSlug(string $displayName): string
    {
        $base = Str::slug($displayName, '_');

        if (preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'peran_'.$base;
        }

        $base = rtrim(mb_substr($base, 0, self::MAX_SLUG_LENGTH), '_');

        if (strlen($base) < self::MIN_SLUG_LENGTH) {
            $base = 'peran_'.$base;
        }

        $slug = $base;
        $suffix = 2;

        while (Role::query()->where('name', $slug)->exists()) {
            $tail = "_{$suffix}";
            $slug = rtrim(mb_substr($base, 0, self::MAX_SLUG_LENGTH - strlen($tail)), '_').$tail;
            $suffix++;
        }

        return $slug;
    }
}
