<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Team;
use App\Domain\Auth\UniqueSlug;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\HexColor;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());

        $base = Team::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $teams = Team::query()
            ->with('defaultProfile:id,name')
            ->withCount('admins')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'color' => $team->color,
                'description' => $team->description,
                'is_enabled' => $team->is_enabled,
                'admins_count' => $team->admins_count,
                'default_profile' => $team->defaultProfile?->name,
                'actions' => [
                    'edit' => true,
                    'delete' => $team->admins_count === 0,
                ],
            ]);

        return Inertia::render('Staff/Teams/Index', [
            'teams' => $teams,
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
        return Inertia::render('Staff/Teams/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        Team::query()->create([
            ...$this->payload($data),
            'slug' => UniqueSlug::make($data['name'], Team::class),
        ]);

        return redirect()->route('admin.staff.teams.index')->with('success', 'Team created.');
    }

    public function edit(Team $team): Response
    {
        return Inertia::render('Staff/Teams/Form', [
            ...$this->formProps(),
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description ?? '',
                'color' => $team->color,
                'is_enabled' => $team->is_enabled,
                'default_profile_id' => $team->default_profile_id,
            ],
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $data = $this->validated($request, $team);
        $previousProfileId = $team->default_profile_id;
        $team->update($this->payload($data));

        if ($team->default_profile_id && $team->default_profile_id !== $previousProfileId) {
            Admin::query()
                ->where('team_id', $team->id)
                ->whereDoesntHave('roles', fn ($query) => $query->where('slug', 'super_admin'))
                ->update(['profile_id' => $team->default_profile_id]);
        }

        return redirect()->route('admin.staff.teams.index')->with('success', 'Team updated.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        if ($team->admins()->exists()) {
            throw ValidationException::withMessages([
                'team' => 'Move or reassign employees before deleting this team.',
            ]);
        }

        $team->delete();

        return redirect()->route('admin.staff.teams.index')->with('success', 'Team deleted.');
    }

    public function toggle(Team $team): RedirectResponse
    {
        $team->update(['is_enabled' => ! $team->is_enabled]);

        return back()->with('success', $team->is_enabled ? 'Team enabled.' : 'Team disabled.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:teams,id'],
        ]);

        Team::query()
            ->whereIn('id', $data['ids'])
            ->whereDoesntHave('admins')
            ->delete();

        return back()->with('success', 'Selected teams deleted.');
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'profiles' => Profile::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Team $existing): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => HexColor::rules(),
            'is_enabled' => ['required', 'boolean'],
            'default_profile_id' => ['required', 'integer', 'exists:profiles,id'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['description'] = ($data['description'] ?? '') ?: null;
        $data['default_profile_id'] = $data['default_profile_id'] ?? null;

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function payload(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'color' => HexColor::parse($data['color']),
            'is_enabled' => $data['is_enabled'],
            'default_profile_id' => $data['default_profile_id'],
        ];
    }
}
