<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Exceptions\BusinessRuleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Central rules against privilege escalation and accidental lockout.
 */
final class AccessGuard
{
    public const string SUPER_ADMIN_ROLE = 'super_admin';

    public function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('roles.name', self::SUPER_ADMIN_ROLE)->exists();
    }

    /**
     * Permission names the actor may hand out or take away.
     *
     * @return list<string>
     */
    public function grantablePermissions(User $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return Permission::query()->pluck('name')->all();
        }

        return array_values($actor->getPermissionNames());
    }

    /**
     * Every non-view permission on a resource needs that resource's view permission,
     * for resources that have one.
     *
     * @param  list<string>  $permissionNames
     *
     * @throws BusinessRuleException
     */
    public function assertPermissionsConsistent(array $permissionNames): void
    {
        $known = Permission::query()->pluck('name')->all();
        $unknown = array_diff($permissionNames, $known);

        if ($unknown !== []) {
            throw new BusinessRuleException('Izin tidak dikenal: '.implode(', ', $unknown).'.', 422, 'permissions');
        }

        foreach ($permissionNames as $name) {
            $resource = explode('.', $name)[0];
            $view = "{$resource}.view";

            if ($name !== $view && in_array($view, $known, true) && ! in_array($view, $permissionNames, true)) {
                throw new BusinessRuleException("Izin {$name} memerlukan izin {$view}.", 422, 'permissions');
            }
        }
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     *
     * @throws BusinessRuleException
     */
    public function assertCanChangePermissions(User $actor, array $added, array $removed): void
    {
        $outside = array_diff([...$added, ...$removed], $this->grantablePermissions($actor));

        if ($outside !== []) {
            throw new BusinessRuleException('Anda tidak dapat mengubah izin yang tidak Anda miliki: '.implode(', ', $outside).'.', 422, 'permissions');
        }
    }

    /**
     * @param  iterable<Role>  $roles
     *
     * @throws BusinessRuleException
     */
    public function assertCanAssignRoles(User $actor, iterable $roles): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        $own = $actor->getPermissionNames();

        foreach ($roles as $role) {
            if ($role->name === self::SUPER_ADMIN_ROLE) {
                throw new BusinessRuleException('Peran Super Administrator hanya dapat diatur oleh Super Administrator.', 422, 'roles');
            }

            $outside = array_diff($role->permissions()->pluck('name')->all(), $own);

            if ($outside !== []) {
                throw new BusinessRuleException("Anda tidak dapat memberikan peran {$role->display_name} karena memuat izin yang tidak Anda miliki.", 422, 'roles');
            }
        }
    }

    /**
     * A user who could reset the password of someone with more permissions could take over that account,
     * so the ceiling also applies to who may be managed at all.
     *
     * @throws BusinessRuleException
     */
    public function assertCanManageUser(User $actor, User $target): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if ($this->isSuperAdmin($target)) {
            throw new BusinessRuleException('Hanya Super Administrator yang dapat mengubah akun Super Administrator.', 403, 'form');
        }

        if (array_diff($target->getPermissionNames(), $actor->getPermissionNames()) !== []) {
            throw new BusinessRuleException('Anda tidak dapat mengubah akun yang memiliki izin melebihi izin Anda.', 403, 'form');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertNotSelf(User $actor, User $target, string $field): void
    {
        if ((int) $actor->id === (int) $target->id) {
            throw new BusinessRuleException('Tindakan ini tidak dapat dilakukan pada akun Anda sendiri.', 422, $field);
        }
    }

    /**
     * Locks the super admin role row so two concurrent changes cannot both pass this check.
     *
     * @throws BusinessRuleException
     */
    public function assertNotLastActiveSuperAdmin(User $target): void
    {
        if (! $this->isSuperAdmin($target) || ! $target->is_active) {
            return;
        }

        $role = Role::query()->where('name', self::SUPER_ADMIN_ROLE)->lockForUpdate()->firstOrFail();

        if (! $this->hasOtherActiveMember($role, $target)) {
            throw new BusinessRuleException('Tidak dapat mengubah Super Administrator aktif yang terakhir.', 422, 'form');
        }
    }

    /**
     * Read-only variant for screens; the write path relies on assertNotLastActiveSuperAdmin.
     */
    public function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $this->isSuperAdmin($target) || ! $target->is_active) {
            return false;
        }

        $role = Role::query()->where('name', self::SUPER_ADMIN_ROLE)->firstOrFail();

        return ! $this->hasOtherActiveMember($role, $target);
    }

    public function canManageUser(User $actor, User $target): bool
    {
        try {
            $this->assertCanManageUser($actor, $target);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    private function hasOtherActiveMember(Role $role, User $target): bool
    {
        return $role->users()
            ->where('users.is_active', true)
            ->whereKeyNot($target->id)
            ->exists();
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertRoleEditable(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleException('Peran bawaan tidak dapat diubah. Gunakan Duplikat untuk membuat salinannya.', 409, 'form');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertRoleDeletable(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleException('peran bawaan tidak dapat dihapus.', 409, 'form');
        }

        if ($role->users()->exists()) {
            throw new BusinessRuleException('peran masih dipakai oleh pengguna.', 422, 'form');
        }
    }
}
