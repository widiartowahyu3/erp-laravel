<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * In-memory cache of permission names for this user instance.
     *
     * @var array<string, bool>|null
     */
    protected ?array $permissionsCache = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'bool',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The roles that belong to the user.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withPivot('created_at');
    }

    /**
     * Check if user has the given permission.
     */
    public function hasPermission(string $permissionName): bool
    {
        if ($this->permissionsCache === null) {
            $this->loadPermissionsCache();
        }

        return isset($this->permissionsCache[$permissionName]);
    }

    /**
     * Get all permission names for this user.
     *
     * @return array<int, string>
     */
    public function getPermissionNames(): array
    {
        if ($this->permissionsCache === null) {
            $this->loadPermissionsCache();
        }

        return array_keys($this->permissionsCache ?? []);
    }

    /**
     * Load all distinct permissions from assigned roles in a single query.
     */
    protected function loadPermissionsCache(): void
    {
        if (! $this->exists) {
            $this->permissionsCache = [];

            return;
        }

        /** @var array<int, string> $permissionNames */
        $permissionNames = DB::table('permissions')
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $this->id)
            ->distinct()
            ->pluck('permissions.name')
            ->all();

        $this->permissionsCache = array_fill_keys($permissionNames, true);
    }
}
