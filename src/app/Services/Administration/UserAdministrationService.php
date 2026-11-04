<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Account operations shared by the console commands and the web administration pages.
 * Every change and its audit record share one transaction, so a failed audit undoes the change.
 */
final class UserAdministrationService
{
    /**
     * @param  list<int>  $roleIds
     *
     * @throws BusinessRuleException
     */
    public function create(string $name, string $email, string $password, array $roleIds): User
    {
        $normalizedEmail = self::normalizeEmail($email);

        $this->assertEmailAvailable($normalizedEmail);
        $this->assertPasswordValid($password, $normalizedEmail);

        return DB::transaction(function () use ($name, $normalizedEmail, $password, $roleIds): User {
            $user = new User;
            $user->forceFill([
                'name' => trim($name),
                'email' => $normalizedEmail,
                'password' => $password,
                'is_active' => true,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ])->save();

            $user->roles()->attach(array_fill_keys($roleIds, ['created_at' => now()]));

            AuditLogger::record(
                event: 'user.created',
                auditableType: User::class,
                auditableId: (int) $user->id,
                new: [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $normalizedEmail,
                    'role_ids' => $roleIds,
                ],
                strict: true
            );

            return $user;
        });
    }

    /**
     * Set a new password and revoke every active session of the user.
     *
     * @throws BusinessRuleException
     */
    public function resetPassword(User $user, string $password): void
    {
        $this->assertPasswordValid($password, (string) $user->email);

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'updated_by' => Auth::id() ?? $user->updated_by,
            ])->save();

            $this->revokeSessions($user);

            AuditLogger::record(
                event: 'password.reset',
                auditableType: User::class,
                auditableId: (int) $user->id,
                new: ['email' => (string) $user->email],
                strict: true
            );
        });
    }

    /**
     * Activate or deactivate an account. Deactivating revokes every active session.
     */
    public function setActive(User $user, bool $isActive): void
    {
        DB::transaction(function () use ($user, $isActive): void {
            $wasActive = (bool) $user->is_active;

            $user->forceFill([
                'is_active' => $isActive,
                'updated_by' => Auth::id() ?? $user->updated_by,
            ])->save();

            if (! $isActive) {
                $this->revokeSessions($user);
            }

            AuditLogger::record(
                event: $isActive ? 'user.activated' : 'user.deactivated',
                auditableType: User::class,
                auditableId: (int) $user->id,
                old: ['is_active' => $wasActive],
                new: ['is_active' => $isActive],
                strict: true
            );
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Emails stay reserved by soft deleted users because the database key covers them too.
     *
     * @throws BusinessRuleException
     */
    public function assertEmailAvailable(string $normalizedEmail, ?int $exceptUserId = null): void
    {
        $taken = User::withTrashed()
            ->where('email', $normalizedEmail)
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();

        if ($taken) {
            throw new BusinessRuleException('Email sudah dipakai, termasuk oleh akun yang sudah dihapus.', 422, 'email');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertPasswordValid(string $password, string $email): void
    {
        $violation = PasswordPolicy::validate($password, $email);

        if ($violation !== null) {
            throw new BusinessRuleException($violation, 422, 'password');
        }
    }

    private function revokeSessions(User $user): void
    {
        // @audit-bypass: sessions have no audit trail; the revoking action itself is audited by the caller
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
