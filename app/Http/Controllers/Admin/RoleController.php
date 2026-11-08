<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\UniqueSlug;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());

        $base = Role::query()->where('slug', '!=', 'super_admin');

        $counts = [
            'all' => (clone $base)->count(),
        ];

        $roles = Role::query()
            ->where('slug', '!=', 'super_admin')
            ->with('permissions')
            ->withCount(['admins', 'profiles'])
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => $role->isSystem(),
                'admins_count' => $role->admins_count,
                'profiles_count' => $role->profiles_count,
                'pages_count' => count(PageCatalog::expand($role->permissions->pluck('slug')->all())),
                'actions' => [
                    'edit' => true,
                    'delete' => ! $role->isSystem() && $role->admins_count === 0 && $role->profiles_count === 0,
                ],
            ]);

        return Inertia::render('Staff/Roles/Index', [
            'roles' => $roles,
            'counts' => $counts,
            'filters' => [
                'q' => $q,
                'name' => $name,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Roles/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => UniqueSlug::make($data['name'], Role::class),
        ]);
        $role->permissions()->sync($this->permissionIds($data['permissions']));

        return redirect()->route('admin.staff.roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role): Response
    {
        if ($role->isSuperAdminRole()) {
            abort(403, 'The super admin role cannot be edited. It always has every page.');
        }

        $role->load('permissions');
        $granted = PageCatalog::expand($role->permissions->pluck('slug')->all());

        return Inertia::render('Staff/Roles/Form', [
            ...$this->formProps(),
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => $role->isSystem(),
                'permissions' => $granted,
            ],
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->isSuperAdminRole()) {
            abort(403, 'The super admin role cannot be edited.');
        }

        $data = $this->validated($request);
        $role->update(['name' => $data['name']]);
        $role->permissions()->sync($this->permissionIds($data['permissions']));

        return redirect()->route('admin.staff.roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->isSystem()) {
            throw ValidationException::withMessages([
                'role' => 'Built-in roles cannot be deleted.',
            ]);
        }

        if ($role->admins()->exists() || $role->profiles()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Remove this role from profiles and employees before deleting it.',
            ]);
        }

        $role->delete();

        return redirect()->route('admin.staff.roles.index')->with('success', 'Role deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:roles,id'],
        ]);

        Role::query()
            ->whereIn('id', $data['ids'])
            ->whereNotIn('slug', ['super_admin', 'catalog_manager', 'order_manager', 'customer_manager'])
            ->whereDoesntHave('admins')
            ->whereDoesntHave('profiles')
            ->delete();

        return back()->with('success', 'Selected roles deleted.');
    }

    public function sync(Request $request, Role $role): RedirectResponse
    {
        if ($role->isSuperAdminRole()) {
            abort(403, 'The super admin role cannot be edited.');
        }

        $data = $request->validate(['permission_ids' => ['array']]);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return back()->with('success', 'Permissions saved.');
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'groups' => collect(PageCatalog::navGroups())->map(fn (array $group) => [
                'heading' => $group['heading'],
                'heading_key' => $group['heading_key'] ?? PageCatalog::headingKey($group['heading']),
                'pages' => collect($group['pages'])->map(fn (array $page) => [
                    'slug' => $page['slug'],
                    'label' => $page['label'],
                    'label_key' => $page['label_key'] ?? ('admin.pages.'.$page['slug']),
                    'href' => $page['href'],
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        $allowed = PageCatalog::slugs();
        $data['permissions'] = array_values(array_intersect($data['permissions'], $allowed));

        if ($data['permissions'] === []) {
            throw ValidationException::withMessages([
                'permissions' => 'Choose at least one page this role can open.',
            ]);
        }

        return $data;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    protected function permissionIds(array $slugs): array
    {
        return Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }
}
