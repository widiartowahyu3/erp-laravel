<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserActiveRequest;
use App\Http\Requests\Admin\UserPasswordRequest;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AccessGuard;
use App\Services\Administration\UserService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly AccessGuard $guard
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $isSuperAdmin = $this->guard->isSuperAdmin($actor);

        $query = User::query()
            ->with('roles:id,name,display_name')
            ->when(! $isSuperAdmin, fn (Builder $builder) => $builder->whereDoesntHave(
                'roles',
                fn (Builder $roles) => $roles->where('roles.name', AccessGuard::SUPER_ADMIN_ROLE)
            ));

        $paginator = ListQuery::for($query)
            ->searchable(['name', 'email'])
            ->sortable(['name', 'email', 'is_active', 'last_login_at'])
            ->filterable([
                'is_active' => 'is_active',
                'role_id' => function (Builder $builder, mixed $value): void {
                    $builder->whereHas('roles', fn (Builder $roles) => $roles->where('roles.id', (int) $value));
                },
            ])
            ->defaultSort('name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('Admin/Users/Index', [
            'items' => Paginated::from($paginator, fn (User $user): array => $this->present($user), $request),
            'roles' => $this->roleFilterOptions($isSuperAdmin),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'assignableRoles' => $this->assignableRoles($this->actor($request)),
        ]);
    }

    public function store(UserStoreRequest $request): RedirectResponse
    {
        $user = $this->users->create($this->actor($request), $request->validated());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function show(Request $request, User $user): Response
    {
        $actor = $this->actor($request);
        $this->abortIfHidden($actor, $user);
        $user->load('roles:id,name,display_name');

        return Inertia::render('Admin/Users/Show', [
            'item' => $this->present($user),
            ...$this->accessState($actor, $user),
        ]);
    }

    public function edit(Request $request, User $user): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfHidden($actor, $user);

        if (! $this->guard->canManageUser($actor, $user)) {
            return redirect()
                ->route('admin.users.show', $user)
                ->with('error', 'Anda tidak dapat mengubah akun yang memiliki izin melebihi izin Anda.');
        }

        $user->load('roles:id,name,display_name');

        return Inertia::render('Admin/Users/Edit', [
            'item' => $this->present($user),
            'assignableRoles' => $this->assignableRoles($actor),
            ...$this->accessState($actor, $user),
        ]);
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfHidden($actor, $user);

        $this->users->update($actor, $user, $request->validated());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function resetPassword(UserPasswordRequest $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfHidden($actor, $user);

        $this->users->resetPassword($actor, $user, $request->string('password')->toString());

        return back()->with('success', 'Kata sandi berhasil direset dan seluruh sesi pengguna ini dicabut.');
    }

    public function setActive(UserActiveRequest $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfHidden($actor, $user);

        $isActive = $request->boolean('is_active');
        $this->users->setActive($actor, $user, $isActive);

        return back()->with('success', $isActive
            ? 'Pengguna berhasil diaktifkan.'
            : 'Pengguna berhasil dinonaktifkan dan seluruh sesinya dicabut.');
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /**
     * Holders of the super admin role are not visible to anyone else, so they answer as missing.
     */
    private function abortIfHidden(User $actor, User $target): void
    {
        abort_if(! $this->guard->isSuperAdmin($actor) && $this->guard->isSuperAdmin($target), 404);
    }

    /**
     * @return array{id: int, name: string, email: string, is_active: bool, last_login_at: string|null, roles: list<array{id: int, name: string, display_name: string}>}
     */
    private function present(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at !== null ? Carbon::parse($user->last_login_at)->format('Y-m-d H:i') : null,
            'roles' => $user->roles->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{isSelf: bool, isLastSuperAdmin: bool, canManage: bool}
     */
    private function accessState(User $actor, User $target): array
    {
        return [
            'isSelf' => (int) $actor->id === (int) $target->id,
            'isLastSuperAdmin' => $this->guard->isLastActiveSuperAdmin($target),
            'canManage' => $this->guard->canManageUser($actor, $target),
        ];
    }

    /**
     * @return list<array{id: int, display_name: string}>
     */
    private function roleFilterOptions(bool $isSuperAdmin): array
    {
        return Role::query()
            ->when(! $isSuperAdmin, fn (Builder $builder) => $builder->where('name', '!=', AccessGuard::SUPER_ADMIN_ROLE))
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->map(fn (Role $role): array => ['id' => (int) $role->id, 'display_name' => $role->display_name])
            ->values()
            ->all();
    }

    /**
     * Roles beyond the actor's permissions are listed but marked, so the form can explain why.
     *
     * @return list<array{id: int, name: string, display_name: string, description: string|null, assignable: bool}>
     */
    private function assignableRoles(User $actor): array
    {
        $isSuperAdmin = $this->guard->isSuperAdmin($actor);
        $own = $actor->getPermissionNames();

        return Role::query()
            ->with('permissions:id,name')
            ->when(! $isSuperAdmin, fn (Builder $builder) => $builder->where('name', '!=', AccessGuard::SUPER_ADMIN_ROLE))
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'assignable' => $isSuperAdmin || array_diff($role->permissions->pluck('name')->all(), $own) === [],
            ])
            ->values()
            ->all();
    }
}
