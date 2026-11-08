<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\SystemCustomerGroups;
use App\Domain\Customer\Support\CustomerGroupCsvSchema;
use App\Http\Controllers\Controller;
use App\Support\HexColor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Domain\Security\CsvSafe;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerGroupController extends Controller
{
    public function index(Request $request): Response
    {
        SystemCustomerGroups::ensure();
        $filters = $this->groupFilters($request);

        $query = CustomerGroup::query()->withCount('customers');

        if ($filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        if ($filters['code'] !== '') {
            $query->where('code', 'like', '%'.$filters['code'].'%');
        }

        if ($filters['discount'] !== '') {
            $query->where('discount_percent', 'like', '%'.$filters['discount'].'%');
        }
        if ($filters['discount_min'] !== '') {
            $query->where('discount_percent', '>=', (float) $filters['discount_min']);
        }
        if ($filters['discount_max'] !== '') {
            $query->where('discount_percent', '<=', (float) $filters['discount_max']);
        }

        if ($filters['customers'] !== '') {
            $query->having('customers_count', '>=', (int) $filters['customers']);
        }
        if ($filters['customers_min'] !== '') {
            $query->having('customers_count', '>=', (int) $filters['customers_min']);
        }
        if ($filters['customers_max'] !== '') {
            $query->having('customers_count', '<=', (int) $filters['customers_max']);
        }

        if ($filters['status'] === 'active') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_enabled', false);
        }

        $groups = $query
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (CustomerGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'color' => $group->color,
                'discount_percent' => $group->discount_percent,
                'tax_exempt' => $group->tax_exempt,
                'description' => $group->description,
                'is_enabled' => $group->is_enabled,
                'is_system' => $group->is_system,
                'customers_count' => $group->customers_count,
            ]);

        $counts = [
            'all' => CustomerGroup::query()->count(),
            'active' => CustomerGroup::query()->where('is_enabled', true)->count(),
            'inactive' => CustomerGroup::query()->where('is_enabled', false)->count(),
        ];

        $panel = null;
        if ($request->boolean('create')) {
            $panel = ['mode' => 'create'];
        } elseif ($editId = $request->integer('edit')) {
            $editing = CustomerGroup::query()->find($editId);
            if ($editing) {
                $panel = [
                    'mode' => 'edit',
                    'group' => [
                        'id' => $editing->id,
                        'name' => $editing->name,
                        'code' => $editing->code,
                        'color' => $editing->color,
                        'discount_percent' => $editing->discount_percent,
                        'tax_exempt' => $editing->tax_exempt,
                        'hide_prices' => $editing->hide_prices,
                        'description' => $editing->description,
                        'is_enabled' => $editing->is_enabled,
                    ],
                ];
            }
        }

        return Inertia::render('Customers/Groups', [
            'groups' => $groups,
            'filters' => $filters,
            'counts' => $counts,
            'panel' => $panel,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->groupFilters($request);
        $query = CustomerGroup::query()->withCount('customers');

        if ($filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if ($filters['code'] !== '') {
            $query->where('code', 'like', '%'.$filters['code'].'%');
        }
        if ($filters['discount'] !== '') {
            $query->where('discount_percent', 'like', '%'.$filters['discount'].'%');
        }
        if ($filters['discount_min'] !== '') {
            $query->where('discount_percent', '>=', (float) $filters['discount_min']);
        }
        if ($filters['discount_max'] !== '') {
            $query->where('discount_percent', '<=', (float) $filters['discount_max']);
        }
        if ($filters['customers'] !== '') {
            $query->having('customers_count', '>=', (int) $filters['customers']);
        }
        if ($filters['customers_min'] !== '') {
            $query->having('customers_count', '>=', (int) $filters['customers_min']);
        }
        if ($filters['customers_max'] !== '') {
            $query->having('customers_count', '<=', (int) $filters['customers_max']);
        }
        if ($filters['status'] === 'active') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_enabled', false);
        }

        $csv = fopen('php://temp', 'r+');
        CsvSafe::put($csv, ['id', 'name', 'code', 'color', 'discount_percent', 'tax_exempt', 'description', 'is_enabled', 'customers_count']);
        $query->orderBy('position')->orderBy('name')->limit(10000)->each(function (CustomerGroup $group) use ($csv) {
            CsvSafe::put($csv, [
                $group->id,
                $group->name,
                $group->code,
                $group->color,
                $group->discount_percent,
                $group->tax_exempt ? '1' : '0',
                $group->description,
                $group->is_enabled ? '1' : '0',
                $group->customers_count,
            ]);
        });
        rewind($csv);
        $body = stream_get_contents($csv) ?: '';

        return response()->streamDownload(fn () => print ($body), 'customer-groups.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function importSample()
    {
        return $this->csvDownload('customer-groups-sample.csv', CustomerGroupCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        $count = CustomerGroupCsvSchema::import((string) $request->file('file')->getContent());

        return back()->with('success', "Imported {$count} customer groups.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:64'],
            'color' => HexColor::rules(),
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_exempt' => ['nullable', 'boolean'],
            'hide_prices' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $code = Str::slug((string) ($data['code'] ?: $data['name']));
        $request->merge(['code' => $code]);
        $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('customer_groups', 'code')],
        ]);

        CustomerGroup::query()->create([
            'name' => $data['name'],
            'code' => $code,
            'color' => HexColor::parse($data['color']),
            'discount_percent' => $data['discount_percent'] ?? 0,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'hide_prices' => $request->boolean('hide_prices'),
            'description' => $data['description'] ?? null,
            'is_enabled' => $request->boolean('is_enabled', true),
            'position' => (int) CustomerGroup::query()->max('position') + 1,
        ]);

        return $this->redirectToGroupsIndex($request, 'Customer group created.');
    }

    public function update(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => HexColor::rules(),
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_exempt' => ['nullable', 'boolean'],
            'hide_prices' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $customerGroup->update([
            'name' => $data['name'],
            'color' => HexColor::parse($data['color']),
            'discount_percent' => $data['discount_percent'] ?? 0,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'hide_prices' => $request->boolean('hide_prices'),
            'description' => $data['description'] ?? null,
            'is_enabled' => $request->boolean('is_enabled', true),
        ]);

        return $this->redirectToGroupsIndex($request, 'Customer group updated.');
    }

    public function toggle(CustomerGroup $customerGroup): RedirectResponse
    {
        if ($customerGroup->is_system) {
            return back()->with('error', 'Visitor, Guest, and Customer stay on in every shop.');
        }

        $customerGroup->update(['is_enabled' => ! $customerGroup->is_enabled]);

        return back()->with('success', $customerGroup->is_enabled ? 'Customer group enabled.' : 'Customer group disabled.');
    }

    public function destroy(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        if ($customerGroup->is_system) {
            return back()->with('error', 'Visitor, Guest, and Customer are shared by every shop and cannot be deleted.');
        }

        if (Customer::query()->where('customer_group', $customerGroup->code)->exists()) {
            return back()->with('error', 'This group is assigned to customers and cannot be deleted.');
        }

        $customerGroup->delete();

        return $this->redirectToGroupsIndex($request, 'Customer group deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $blocked = 0;
        foreach (CustomerGroup::query()->whereIn('id', $data['ids'])->get() as $group) {
            if ($group->is_system || Customer::query()->where('customer_group', $group->code)->exists()) {
                $blocked++;
                continue;
            }
            $group->delete();
            $deleted++;
        }

        if ($deleted && $blocked) {
            return back()->with('warning', "{$deleted} group(s) deleted. {$blocked} could not be deleted because they are assigned to customers.");
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected groups could be deleted. Reassign customers first.');
        }

        return back()->with('success', $deleted === 1 ? 'Customer group deleted.' : "{$deleted} customer groups deleted.");
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = CustomerGroup::query()
            ->whereIn('id', $data['ids'])
            ->when(! $data['enabled'], fn ($query) => $query->where('is_system', false))
            ->update(['is_enabled' => (bool) $data['enabled']]);

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Customer group enabled.' : "{$updated} customer groups enabled.")
            : ($updated === 1 ? 'Customer group disabled.' : "{$updated} customer groups disabled."));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (CustomerGroup::query()->whereIn('id', $data['ids'])->orderBy('id')->get() as $group) {
            if ($group->is_system) {
                continue;
            }
            $this->duplicateGroup($group);
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No customer groups were duplicated.');
        }

        return back()->with('success', $copied === 1 ? 'Customer group duplicated.' : "{$copied} customer groups duplicated.");
    }

    public function duplicate(CustomerGroup $customerGroup): RedirectResponse
    {
        if ($customerGroup->is_system) {
            return back()->with('error', 'Visitor, Guest, and Customer cannot be duplicated.');
        }

        $copy = $this->duplicateGroup($customerGroup);

        return redirect()
            ->route('admin.customer-groups.index', ['edit' => $copy->id])
            ->with('success', 'Customer group duplicated.');
    }

    protected function duplicateGroup(CustomerGroup $group): CustomerGroup
    {
        $copy = $group->replicate();
        $copy->name = trim($group->name).' (copy)';
        $copy->code = $this->uniqueGroupCode($group->code.'-copy');
        $copy->is_system = false;
        $copy->is_enabled = false;
        $copy->position = (int) CustomerGroup::query()->max('position') + 1;
        $copy->save();

        return $copy;
    }

    protected function uniqueGroupCode(string $base): string
    {
        $code = Str::slug($base) ?: 'group';
        $candidate = $code;
        $n = 2;
        while (CustomerGroup::query()->where('code', $candidate)->exists()) {
            $suffix = '-'.$n;
            $candidate = Str::limit($code, 64 - strlen($suffix), '').$suffix;
            $n++;
        }

        return $candidate;
    }

    /** @return array{name: string, code: string, discount: string, discount_min: string, discount_max: string, customers: string, customers_min: string, customers_max: string, status: string} */
    protected function groupFilters(Request $request): array
    {
        return [
            'name' => trim((string) $request->query('name', '')),
            'code' => trim((string) $request->query('code', '')),
            'discount' => trim((string) $request->query('discount', '')),
            'discount_min' => trim((string) $request->query('discount_min', '')),
            'discount_max' => trim((string) $request->query('discount_max', '')),
            'customers' => trim((string) $request->query('customers', '')),
            'customers_min' => trim((string) $request->query('customers_min', '')),
            'customers_max' => trim((string) $request->query('customers_max', '')),
            'status' => (string) $request->query('status', '') ?: 'all',
        ];
    }

    protected function redirectToGroupsIndex(Request $request, string $message): RedirectResponse
    {
        $query = [];
        if ($request->headers->has('referer')) {
            $referer = parse_url((string) $request->headers->get('referer'));
            parse_str($referer['query'] ?? '', $refererQuery);
            unset($refererQuery['edit'], $refererQuery['create']);
            $query = array_intersect_key($refererQuery, array_flip([
                'name', 'code', 'discount', 'discount_min', 'discount_max',
                'customers', 'customers_min', 'customers_max', 'status',
            ]));
        }
        $query = array_filter($query, fn ($value) => $value !== '' && $value !== 'all');

        return redirect()->route('admin.customer-groups.index', $query)->with('success', $message);
    }
}
