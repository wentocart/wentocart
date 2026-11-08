<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Team;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $email = trim($request->string('email')->toString());
        $teamId = $request->integer('team_id');
        $profileId = $request->integer('profile_id');

        $base = Admin::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
            'super' => (clone $base)->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))->count(),
        ];

        $employees = Admin::query()
            ->with(['team', 'profile', 'roles'])
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($status === 'super', fn ($query) => $query->whereHas('roles', fn ($inner) => $inner->where('slug', 'super_admin')))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('job_title', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($email !== '', fn ($query) => $query->where('email', 'like', "%{$email}%"))
            ->when($teamId > 0, fn ($query) => $query->where('team_id', $teamId))
            ->when($profileId > 0, fn ($query) => $query->where('profile_id', $profileId))
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (Admin $admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'job_title' => $admin->job_title,
                'is_enabled' => $admin->is_enabled,
                'is_super' => $admin->isSuperAdmin(),
                'team' => $admin->team ? [
                    'id' => $admin->team->id,
                    'name' => $admin->team->name,
                    'color' => $admin->team->color,
                ] : null,
                'profile' => $admin->profile ? [
                    'id' => $admin->profile->id,
                    'name' => $admin->profile->name,
                ] : null,
                'actions' => [
                    'edit' => true,
                    'delete' => ! $admin->isLastSuperAdmin() && $admin->id !== $request->user('admin')?->id,
                ],
            ]);

        return Inertia::render('Staff/Employees/Index', [
            'employees' => $employees,
            'counts' => $counts,
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'profiles' => Profile::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'email' => $email,
                'team_id' => $teamId ?: '',
                'profile_id' => $profileId ?: '',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Employees/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $isSuper = (bool) $data['is_super'];
        $profile = $this->resolveProfile($data, $isSuper);

        $admin = Admin::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_enabled' => $data['is_enabled'],
            'team_id' => $data['team_id'],
            'job_title' => $data['job_title'],
            'phone' => $data['phone'],
            'profile_id' => $profile?->id,
        ]);

        if ($isSuper) {
            $admin->grantSuperAdmin();
        } else {
            $admin->applyProfile($profile);
        }

        return redirect()->route('admin.staff.employees.index')->with('success', 'Employee created.');
    }

    public function edit(Admin $admin): Response
    {
        $admin->load(['roles', 'team', 'profile']);

        return Inertia::render('Staff/Employees/Form', [
            ...$this->formProps(),
            'employee' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'job_title' => $admin->job_title ?? '',
                'phone' => $admin->phone ?? '',
                'is_enabled' => $admin->is_enabled,
                'is_super' => $admin->isSuperAdmin(),
                'is_last_super' => $admin->isLastSuperAdmin(),
                'team_id' => $admin->team_id,
                'profile_id' => $admin->profile_id,
            ],
        ]);
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        $admin->load('roles');
        $data = $this->validated($request, $admin);
        $isSuper = (bool) $data['is_super'];

        if ($admin->isLastSuperAdmin() && (! $isSuper || ! $data['is_enabled'])) {
            throw ValidationException::withMessages([
                'is_super' => 'The store must keep at least one enabled super admin.',
            ]);
        }

        $profile = $this->resolveProfile($data, $isSuper);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'is_enabled' => $data['is_enabled'],
            'team_id' => $data['team_id'],
            'job_title' => $data['job_title'],
            'phone' => $data['phone'],
            'profile_id' => $profile?->id,
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
            $payload['remember_token'] = Str::random(60);
        }

        $admin->update($payload);
        if (! empty($data['password'])) {
            SessionRevoker::revokeOthers('admin', $admin->id);
        }
        $admin->unsetRelation('roles');
        $admin->load('roles');

        if ($isSuper) {
            $admin->grantSuperAdmin();
        } else {
            $admin->applyProfile($profile);
        }

        return redirect()->route('admin.staff.employees.index')->with('success', 'Employee updated.');
    }

    public function destroy(Request $request, Admin $admin): RedirectResponse
    {
        $admin->load('roles');

        if ($admin->id === $request->user('admin')?->id) {
            throw ValidationException::withMessages([
                'employee' => 'You cannot delete your own account.',
            ]);
        }

        if ($admin->isLastSuperAdmin()) {
            throw ValidationException::withMessages([
                'employee' => 'The store must keep at least one super admin.',
            ]);
        }

        $admin->delete();

        return redirect()->route('admin.staff.employees.index')->with('success', 'Employee deleted.');
    }

    public function toggle(Request $request, Admin $admin): RedirectResponse
    {
        $admin->load('roles');

        if ($admin->isLastSuperAdmin() && $admin->is_enabled) {
            throw ValidationException::withMessages([
                'employee' => 'The store must keep at least one enabled super admin.',
            ]);
        }

        $admin->update(['is_enabled' => ! $admin->is_enabled]);

        return back()->with('success', $admin->is_enabled ? 'Employee enabled.' : 'Employee disabled.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:admins,id'],
        ]);

        $me = $request->user('admin')?->id;
        $admins = Admin::query()->with('roles')->whereIn('id', $data['ids'])->get();

        foreach ($admins as $admin) {
            if ($admin->id === $me || $admin->isLastSuperAdmin()) {
                continue;
            }
            $admin->delete();
        }

        return back()->with('success', 'Selected employees deleted.');
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'teams' => Team::query()->where('is_enabled', true)->with('defaultProfile:id,name')->orderBy('name')->get(['id', 'name', 'default_profile_id']),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Admin $existing): array
    {
        $isSuper = $request->boolean('is_super');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($existing?->id)],
            'password' => CustomerPassword::rules(required: ! $existing, confirmed: false),
            'job_title' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'is_enabled' => ['required', 'boolean'],
            'is_super' => ['required', 'boolean'],
        ], CustomerPassword::messages());

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['is_super'] = $isSuper;
        $data['job_title'] = ($data['job_title'] ?? '') ?: null;
        $data['phone'] = ($data['phone'] ?? '') ?: null;
        $data['team_id'] = $data['team_id'] ?? null;

        if (! $isSuper && ! $data['team_id']) {
            throw ValidationException::withMessages([
                'team_id' => 'Choose a team. The team’s profile decides which admin pages this employee can open.',
            ]);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function resolveProfile(array $data, bool $isSuper): ?Profile
    {
        if ($isSuper) {
            return null;
        }

        $team = Team::query()->findOrFail($data['team_id']);

        if (! $team->default_profile_id) {
            throw ValidationException::withMessages([
                'team_id' => 'This team has no profile yet. Edit the team and choose a profile first.',
            ]);
        }

        $profile = Profile::query()->findOrFail($team->default_profile_id);

        if (! $profile->is_enabled) {
            throw ValidationException::withMessages([
                'team_id' => 'This team’s profile is disabled. Choose another team or enable the profile.',
            ]);
        }

        return $profile;
    }
}