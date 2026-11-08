<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\AbilityRegistry;
use App\Domain\Ops\Models\Automation;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(): Response
    {
        $rows = Automation::query()->withCount('runs')->latest('id')->get()->map(fn (Automation $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'is_enabled' => $row->is_enabled,
            'event' => $row->event,
            'ability' => $row->ability,
            'runs_count' => $row->runs_count,
            'updated_at' => optional($row->updated_at)?->toDateTimeString(),
        ]);

        return Inertia::render('Ops/Automations/Index', [
            'automations' => $rows,
            'halted' => ShopSetting::bool('ops.automations_halted', false),
        ]);
    }

    public function create(AbilityRegistry $abilities): Response
    {
        return Inertia::render('Ops/Automations/Form', [
            'automation' => null,
            'abilities' => $abilities->catalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Automation::query()->create($data);

        return redirect()->route('admin.automations.index')->with('success', 'Automation saved.');
    }

    public function edit(Automation $automation, AbilityRegistry $abilities): Response
    {
        $automation->load(['runs' => fn ($q) => $q->latest('id')->limit(20)]);

        return Inertia::render('Ops/Automations/Form', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'is_enabled' => $automation->is_enabled,
                'event' => $automation->event,
                'condition_path' => $automation->condition_path,
                'condition_equals' => $automation->condition_equals,
                'ability' => $automation->ability,
                'action_input' => $automation->action_input ?: new \stdClass,
                'runs' => $automation->runs->map(fn ($run) => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'message' => $run->message,
                    'operation_id' => $run->operation_id,
                    'created_at' => optional($run->created_at)?->toDateTimeString(),
                ])->values()->all(),
            ],
            'abilities' => $abilities->catalog(),
        ]);
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $automation->update($this->validated($request));

        return back()->with('success', 'Automation updated.');
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        $automation->delete();

        return redirect()->route('admin.automations.index')->with('success', 'Automation deleted.');
    }

    public function toggle(Automation $automation): RedirectResponse
    {
        $automation->update(['is_enabled' => ! $automation->is_enabled]);

        return back()->with('success', $automation->is_enabled ? 'Automation on.' : 'Automation off.');
    }

    public function halt(Request $request): RedirectResponse
    {
        $halt = $request->boolean('halted');
        ShopSetting::put('ops.automations_halted', $halt);

        return back()->with('success', $halt ? 'All automations halted.' : 'Automations can run again.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'event' => ['required', 'string', 'max:120'],
            'condition_path' => ['nullable', 'string', 'max:120'],
            'condition_equals' => ['nullable', 'string', 'max:255'],
            'ability' => ['required', 'string', 'max:120'],
            'action_input' => ['nullable'],
        ]);
        app(AbilityRegistry::class)->get($data['ability']);
        $input = $data['action_input'] ?? [];
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : [];
        }

        return [
            'name' => $data['name'],
            'is_enabled' => $request->boolean('is_enabled', true),
            'event' => $data['event'],
            'condition_path' => $data['condition_path'] ?? null,
            'condition_equals' => $data['condition_equals'] ?? null,
            'ability' => $data['ability'],
            'action_input' => is_array($input) ? $input : [],
        ];
    }
}
