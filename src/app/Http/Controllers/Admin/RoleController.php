<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\RespondsToDeleteConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AccessGuard;
use App\Services\Administration\PermissionCatalog;
use App\Services\Administration\RoleService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController extends Controller
{
    use RespondsToDeleteConflict;

    public function __construct(
        private readonly RoleService $roles,
        private readonly AccessGuard $guard,
        private readonly PermissionCatalog $catalog
    ) {}

    public function index(Request $request): Response
    {
        $isSuperAdmin = $this->guard->isSuperAdmin($this->actor($request));

        $paginator = ListQuery::for(Role::query()->withCount(['users', 'permissions']))
            ->searchable(['display_name', 'name'])
            ->sortable(['display_name', 'is_system'])
            ->filterable(['is_system' => 'is_system'])
            ->defaultSort('display_name', 'asc')
            ->paginate($request, 25);

        return Inertia::render('Admin/Roles/Index', [
            'items' => Paginated::from($paginator, fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'isSystem' => (bool) $role->is_system,
                'usersCount' => $this->visibleUsersCount($role, $isSuperAdmin),
                'permissionsCount' => (int) $role->getAttribute('permissions_count'),
            ], $request),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Roles/Create', $this->matrixProps($this->actor($request)));
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($this->actor($request), $request->validated());

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Peran berhasil ditambahkan.');
    }

    public function show(Request $request, Role $role): Response
    {
        $actor = $this->actor($request);
        $role->loadCount('users');

        return Inertia::render('Admin/Roles/Show', [
            'item' => $this->present($role),
            'selectedPermissions' => $role->permissions()->orderBy('permissions.id')->pluck('name')->all(),
            'usersCount' => $this->visibleUsersCount($role, $this->guard->isSuperAdmin($actor)),
            ...$this->matrixProps($actor),
        ]);
    }

    public function edit(Request $request, Role $role): Response|RedirectResponse
    {
        if ($role->is_system) {
            return redirect()
                ->route('admin.roles.show', $role)
                ->with('error', 'Peran bawaan tidak dapat diubah. Gunakan Duplikat untuk membuat salinannya.');
        }

        return Inertia::render('Admin/Roles/Edit', [
            'item' => $this->present($role),
            'selectedPermissions' => $role->permissions()->orderBy('permissions.id')->pluck('name')->all(),
            ...$this->matrixProps($this->actor($request)),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($this->actor($request), $role, $request->validated());

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Peran berhasil diperbarui.');
    }

    public function duplicate(Request $request, Role $role): RedirectResponse
    {
        $copy = $this->roles->duplicate($this->actor($request), $role);

        return redirect()
            ->route('admin.roles.edit', $copy)
            ->with('success', 'Salinan peran berhasil dibuat. Sesuaikan nama dan izinnya.');
    }

    public function destroy(Request $request, Role $role): JsonResponse|RedirectResponse
    {
        try {
            $this->roles->delete($this->actor($request), $role);

            return redirect()
                ->route('admin.roles.index')
                ->with('success', 'Peran berhasil dihapus.');
        } catch (BusinessRuleException $e) {
            return $this->respondToDeleteConflict($request, $e);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /**
     * Who holds the super admin role is visible to super admins only.
     */
    private function visibleUsersCount(Role $role, bool $isSuperAdmin): ?int
    {
        if ($role->name === AccessGuard::SUPER_ADMIN_ROLE && ! $isSuperAdmin) {
            return null;
        }

        return (int) $role->getAttribute('users_count');
    }

    /**
     * @return array{id: int, name: string, display_name: string, description: string|null, isSystem: bool}
     */
    private function present(Role $role): array
    {
        return [
            'id' => (int) $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'isSystem' => (bool) $role->is_system,
        ];
    }

    /**
     * @return array{permissionMatrix: list<array<string, mixed>>, grantablePermissions: list<string>}
     */
    private function matrixProps(User $actor): array
    {
        return [
            'permissionMatrix' => $this->catalog->matrix(),
            'grantablePermissions' => $this->guard->grantablePermissions($actor),
        ];
    }
}
