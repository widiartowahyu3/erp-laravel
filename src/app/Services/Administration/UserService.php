<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Web administration of users: applies the access rules on top of UserAdministrationService.
 */
final class UserService
{
    public function __construct(
        private readonly AccessGuard $guard = new AccessGuard,
        private readonly UserAdministrationService $administration = new UserAdministrationService
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, roles?: list<int>}  $data
     *
     * @throws BusinessRuleException
     */
    public function create(User $actor, array $data): User
    {
        $roles = $this->findRoles($data['roles'] ?? []);

        $this->guard->assertCanAssignRoles($actor, $roles);

        return $this->administration->create(
            $data['name'],
            $data['email'],
            $data['password'],
            $roles->modelKeys()
        );
    }

    /**
     * @param  array{name: string, email: string, roles?: list<int>}  $data
     *
     * @throws BusinessRuleException
     */
    public function update(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->guard->assertCanManageUser($actor, $locked);

            $this->saveProfile($actor, $locked, $data['name'], $data['email']);
            $this->syncRoles($actor, $locked, $data['roles'] ?? []);

            return $locked;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function setActive(User $actor, User $target, bool $isActive): void
    {
        DB::transaction(function () use ($actor, $target, $isActive): void {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->guard->assertCanManageUser($actor, $locked);

            if (! $isActive) {
                $this->guard->assertNotSelf($actor, $locked, 'form');
                $this->guard->assertNotLastActiveSuperAdmin($locked);
            }

            $this->administration->setActive($locked, $isActive);
        });
    }

    /**
     * Resetting your own password here would end your own session; the account page covers that case.
     *
     * @throws BusinessRuleException
     */
    public function resetPassword(User $actor, User $target, string $password): void
    {
        $this->guard->assertNotSelf($actor, $target, 'form');
        $this->guard->assertCanManageUser($actor, $target);

        $this->administration->resetPassword($target, $password);
    }

    /**
     * @throws BusinessRuleException
     */
    private function saveProfile(User $actor, User $user, string $name, string $email): void
    {
        $normalizedEmail = UserAdministrationService::normalizeEmail($email);
        $this->administration->assertEmailAvailable($normalizedEmail, (int) $user->id);

        $old = ['name' => $user->name, 'email' => $user->email];
        $new = ['name' => trim($name), 'email' => $normalizedEmail];

        if ($old === $new) {
            return;
        }

        $user->forceFill($new + ['updated_by' => $actor->id])->save();

        AuditLogger::record(
            event: 'user.updated',
            auditableType: User::class,
            auditableId: (int) $user->id,
            old: $old,
            new: $new,
            strict: true
        );
    }

    /**
     * @param  list<int>  $roleIds
     *
     * @throws BusinessRuleException
     */
    private function syncRoles(User $actor, User $user, array $roleIds): void
    {
        $target = $this->findRoles($roleIds);
        $current = $user->roles()->get();

        $added = $target->diff($current);
        $removed = $current->diff($target);

        if ($added->isEmpty() && $removed->isEmpty()) {
            return;
        }

        $this->guard->assertNotSelf($actor, $user, 'roles');
        $this->guard->assertCanAssignRoles($actor, $added->merge($removed));

        if ($removed->contains(fn (Role $role): bool => $role->name === AccessGuard::SUPER_ADMIN_ROLE)) {
            $this->guard->assertNotLastActiveSuperAdmin($user);
        }

        $user->roles()->syncWithPivotValues($target->modelKeys(), ['created_at' => now()]);

        AuditLogger::record(
            event: 'user.roles_changed',
            auditableType: User::class,
            auditableId: (int) $user->id,
            new: [
                'added' => $added->pluck('name')->values()->all(),
                'removed' => $removed->pluck('name')->values()->all(),
            ],
            strict: true
        );
    }

    /**
     * @param  list<int>  $roleIds
     * @return Collection<int, Role>
     *
     * @throws BusinessRuleException
     */
    private function findRoles(array $roleIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = Role::query()->whereIn('id', $ids)->get();

        if ($roles->count() !== count($ids)) {
            throw new BusinessRuleException('Peran tidak dikenal.', 422, 'roles');
        }

        return $roles;
    }
}
