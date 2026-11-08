<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\UniqueSlug;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());

        $base = Profile::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $profiles = Profile::query()
            ->with('permissions')
            ->withCount(['admins', 'teams'])
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (Profile $profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'slug' => $profile->slug,
                'description' => $profile->description,
                'is_enabled' => $profile->is_enabled,
                'admins_count' => $profile->admins_count,
                'teams_count' => $profile->teams_count,
                'pages_count' => count(PageCatalog::expand($profile->permissions->pluck('slug')->all())),
                'actions' => [
                    'edit' => true,
                    'delete' => $profile->admins_count === 0 && $profile->teams_count === 0,
                ],
            ]);

        return Inertia::render('Staff/Profiles/Index', [
            'profiles' => $profiles,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Profiles/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $profile = Profile::query()->create([
            'name' => $data['name'],
            'slug' => UniqueSlug::make($data['name'], Profile::class),
            'description' => $data['description'],
            'is_enabled' => $data['is_enabled'],
        ]);
        $profile->permissions()->sync($this->permissionIds($data['permissions']));

        return redirect()->route('admin.staff.profiles.index')->with('success', 'Profile created.');
    }

    public function edit(Profile $profile): Response
    {
        $profile->load('permissions');
        $granted = PageCatalog::expand($profile->permissions->pluck('slug')->all());

        return Inertia::render('Staff/Profiles/Form', [
            ...$this->formProps(),
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'description' => $profile->description ?? '',
                'is_enabled' => $profile->is_enabled,
                'permissions' => $granted,
                'admins_count' => $profile->admins()->count(),
                'teams_count' => $profile->teams()->count(),
            ],
        ]);
    }

    public function update(Request $request, Profile $profile): RedirectResponse
    {
        $data = $this->validated($request);
        $profile->update([
            'name' => $data['name'],
            'description' => $data['description'],
            'is_enabled' => $data['is_enabled'],
        ]);
        $profile->permissions()->sync($this->permissionIds($data['permissions']));

        return redirect()->route('admin.staff.profiles.index')->with('success', 'Profile updated.');
    }

    public function destroy(Profile $profile): RedirectResponse
    {
        if ($profile->admins()->exists()) {
            throw ValidationException::withMessages([
                'profile' => 'Reassign employees to another profile before deleting this one.',
            ]);
        }

        if ($profile->teams()->exists()) {
            throw ValidationException::withMessages([
                'profile' => 'Remove this profile from teams before deleting it.',
            ]);
        }

        $profile->delete();

        return redirect()->route('admin.staff.profiles.index')->with('success', 'Profile deleted.');
    }

    public function toggle(Profile $profile): RedirectResponse
    {
        $profile->update(['is_enabled' => ! $profile->is_enabled]);

        return back()->with('success', $profile->is_enabled ? 'Profile enabled.' : 'Profile disabled.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:profiles,id'],
        ]);

        Profile::query()
            ->whereIn('id', $data['ids'])
            ->whereDoesntHave('admins')
            ->whereDoesntHave('teams')
            ->delete();

        return back()->with('success', 'Selected profiles deleted.');
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
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['required', 'boolean'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['description'] = ($data['description'] ?? '') ?: null;

        $allowed = array_values(array_unique(array_merge(PageCatalog::slugs(), PageCatalog::familySlugs())));
        $data['permissions'] = array_values(array_intersect($data['permissions'], $allowed));

        if ($data['permissions'] === []) {
            throw ValidationException::withMessages([
                'permissions' => 'Choose at least one admin page this profile can open.',
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
