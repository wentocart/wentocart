<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\Company;
use App\Domain\Customer\Support\CompanyCsvSchema;
use App\Domain\Customer\Services\CompanyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request, CompanyService $companies): Response
    {
        $sort = (string) $request->query('sort', 'name');
        if (! in_array($sort, ['id', 'name', 'email', 'users', 'credit_limit', 'net_days', 'status'], true)) {
            $sort = 'name';
        }
        $filters = [
            'name' => trim((string) $request->query('name', '')),
            'email' => trim((string) $request->query('email', '')),
            'users_min' => trim((string) $request->query('users_min', '')),
            'users_max' => trim((string) $request->query('users_max', '')),
            'credit_min' => trim((string) $request->query('credit_min', '')),
            'credit_max' => trim((string) $request->query('credit_max', '')),
            'status' => (string) $request->query('status', 'all'),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc',
        ];

        $query = Company::query()->withCount('users');
        if ($filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if ($filters['email'] !== '') {
            $query->where('email', 'like', '%'.$filters['email'].'%');
        }
        if ($filters['users_min'] !== '' && is_numeric($filters['users_min'])) {
            $query->has('users', '>=', (int) $filters['users_min']);
        }
        if ($filters['users_max'] !== '' && is_numeric($filters['users_max'])) {
            $query->has('users', '<=', (int) $filters['users_max']);
        }
        if ($filters['credit_min'] !== '' && is_numeric($filters['credit_min'])) {
            $query->where('credit_limit', '>=', (float) $filters['credit_min']);
        }
        if ($filters['credit_max'] !== '' && is_numeric($filters['credit_max'])) {
            $query->where('credit_limit', '<=', (float) $filters['credit_max']);
        }
        if ($filters['status'] === 'active') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_enabled', false);
        }

        $sortColumn = match ($filters['sort']) {
            'users' => 'users_count',
            'status' => 'is_enabled',
            default => $filters['sort'],
        };

        $rows = $query
            ->orderBy($sortColumn, $filters['dir'])
            ->orderBy('id', $filters['dir'])
            ->adminPaginate()
            ->through(fn (Company $company) => array_merge($companies->present($company), [
                'users_count' => (int) $company->users_count,
            ]));

        $panel = null;
        if ($request->boolean('create')) {
            $panel = ['mode' => 'create'];
        } elseif ($editId = $request->integer('edit')) {
            $editing = Company::query()->find($editId);
            if ($editing) {
                $panel = ['mode' => 'edit', 'company' => $companies->present($editing)];
            }
        }

        return Inertia::render('Customers/Companies', [
            'companies' => $rows,
            'filters' => $filters,
            'counts' => [
                'all' => Company::query()->count(),
                'active' => Company::query()->where('is_enabled', true)->count(),
                'inactive' => Company::query()->where('is_enabled', false)->count(),
            ],
            'panel' => $panel,
        ]);
    }

    public function export()
    {
        return $this->csvDownload('companies.csv', CompanyCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('companies-sample.csv', CompanyCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CompanyCsvSchema::import(...), 'companies');
    }

    public function store(Request $request, CompanyService $companies): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'net_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $company = $companies->create($data);
        if (! empty($data['customer_id'])) {
            $companies->attach($company, (int) $data['customer_id'], 'manager');
        }

        return back()->with('success', 'Company saved.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'net_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $company->update($data);

        return back()->with('success', 'Company updated.');
    }

    public function attach(Request $request, Company $company, CompanyService $companies): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'role' => ['nullable', 'in:buyer,manager'],
        ]);
        $companies->attach($company, (int) $data['customer_id'], $data['role'] ?? 'buyer');

        return back()->with('success', 'Customer added to the company.');
    }
}
