<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Models\Currency;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Promo\Support\DiscountCsvSchema;
use App\Domain\Promo\Models\DiscountRuleCondition;
use App\Domain\Settings\CustomerSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DiscountRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $applyAs = trim($request->string('apply_as')->toString());
        $action = trim($request->string('action')->toString());
        $condition = trim($request->string('condition')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'priority';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['id', 'name', 'apply_as', 'action', 'priority', 'is_enabled', 'starts_at', 'expires_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'priority';
        }

        $base = DiscountRule::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
            'coupon' => (clone $base)->where('apply_as', 'coupon')->count(),
            'calendar' => (clone $base)->where(function ($query) {
                $query->whereNotNull('starts_at')->orWhereNotNull('expires_at');
            })->count(),
        ];

        $rules = DiscountRule::query()
            ->with('conditions')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($status === 'coupon', fn ($query) => $query->where('apply_as', 'coupon'))
            ->when($status === 'calendar', fn ($query) => $query->where(function ($inner) {
                $inner->whereNotNull('starts_at')->orWhereNotNull('expires_at');
            }))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('apply_as', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($applyAs !== '' && $applyAs !== 'all', fn ($query) => $query->where('apply_as', $applyAs))
            ->when($action !== '' && $action !== 'all', fn ($query) => $query->where('action', $action))
            ->when($condition !== '' && $condition !== 'all', function ($query) use ($condition) {
                if ($condition === 'catalog') {
                    $query->where(function ($inner) {
                        $inner->whereDoesntHave('conditions')
                            ->orWhereHas('conditions', fn ($c) => $c->where('type', 'catalog'));
                    });
                } else {
                    $query->whereHas('conditions', fn ($c) => $c->where('type', $condition));
                }
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id'))
            ->adminPaginate()
            ->through(fn (DiscountRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'code' => $rule->code,
                'apply_as' => $rule->apply_as,
                'apply_as_label' => $rule->applyAsLabel(),
                'action_summary' => $rule->actionSummary(),
                'condition_summary' => $rule->conditionSummary(),
                'priority' => $rule->priority,
                'is_enabled' => $rule->is_enabled,
                'starts_at' => $rule->starts_at?->toIso8601String(),
                'expires_at' => $rule->expires_at?->toIso8601String(),
                'actions' => [
                    'edit' => true,
                    'delete' => true,
                ],
            ]);

        return Inertia::render('Discounts/Index', [
            'rules' => $rules,
            'counts' => $counts,
            'applyAs' => DiscountRule::APPLY_AS,
            'actions' => DiscountRule::ACTIONS,
            'conditionTypes' => $this->conditionTypeMap(),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'apply_as' => $applyAs ?: 'all',
                'action' => $action ?: 'all',
                'condition' => $condition ?: 'all',
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        return $this->csvDownload('discounts.csv', DiscountCsvSchema::exportCsv($ids));
    }

    public function importSample()
    {
        return $this->csvDownload('discounts-sample.csv', DiscountCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, DiscountCsvSchema::import(...), 'discounts');
    }

    public function create(): Response
    {
        return Inertia::render('Discounts/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->persist(new DiscountRule, $data);

        return redirect()->route('admin.discounts.index')->with('success', 'Discount created.');
    }

    public function edit(DiscountRule $discount): Response
    {
        $discount->load('conditions');
        $conditions = $this->presentConditions($discount);

        return Inertia::render('Discounts/Form', [
            ...$this->formProps(),
            'discount' => [
                'id' => $discount->id,
                'name' => $discount->name,
                'apply_as' => $discount->apply_as,
                'code' => $discount->code ?? '',
                'action' => $discount->action,
                'value' => (float) $discount->value,
                'currency_code' => $discount->currency_code ?: null,
                'priority' => $discount->priority,
                'stop_further' => $discount->stop_further,
                'min_cart' => $discount->min_cart,
                'min_qty' => $discount->min_qty,
                'max_discount' => $discount->max_discount,
                'max_uses' => $discount->max_uses,
                'max_uses_per_customer' => $discount->max_uses_per_customer,
                'used_count' => $discount->used_count,
                'starts_at' => $discount->starts_at?->format('Y-m-d\\TH:i'),
                'expires_at' => $discount->expires_at?->format('Y-m-d\\TH:i'),
                'is_enabled' => $discount->is_enabled,
                'conditions' => $conditions,
            ],
            'selected' => $this->selectedOptions($conditions),
        ]);
    }

    public function update(Request $request, DiscountRule $discount): RedirectResponse
    {
        $data = $this->validated($request, $discount);
        $this->persist($discount, $data);

        return redirect()->route('admin.discounts.index')->with('success', 'Discount updated.');
    }

    public function destroy(DiscountRule $discount): RedirectResponse
    {
        $discount->delete();

        return redirect()->route('admin.discounts.index')->with('success', 'Discount deleted.');
    }

    public function toggle(DiscountRule $discount): RedirectResponse
    {
        $discount->update(['is_enabled' => ! $discount->is_enabled]);

        return back()->with('success', $discount->is_enabled ? 'Discount activated.' : 'Discount deactivated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:discount_rules,id'],
        ]);

        $deleted = DiscountRule::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1 ? 'Discount deleted.' : $deleted.' discounts deleted.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:discount_rules,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = DiscountRule::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No discounts were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Discount enabled.' : $updated.' discounts enabled.')
            : ($updated === 1 ? 'Discount disabled.' : $updated.' discounts disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:discount_rules,id'],
        ]);

        $copied = 0;
        foreach (DiscountRule::query()->with('conditions')->whereIn('id', $data['ids'])->get() as $rule) {
            $this->duplicateRule($rule);
            $copied++;
        }

        return back()->with('success', $copied === 1 ? 'Discount duplicated.' : $copied.' discounts duplicated.');
    }

    protected function duplicateRule(DiscountRule $rule): DiscountRule
    {
        $copy = $rule->replicate(['used_count']);
        $copy->name = $rule->name.' (copy)';
        $copy->used_count = 0;
        $copy->is_enabled = false;

        if ($rule->apply_as === 'coupon' && $rule->code) {
            $base = substr((string) $rule->code, 0, 50);
            $suffix = 1;
            do {
                $candidate = strtoupper($base.'-C'.$suffix);
                $exists = DiscountRule::query()->where('code', $candidate)->exists();
                $suffix++;
            } while ($exists && $suffix < 1000);
            $copy->code = $candidate;
        } else {
            $copy->code = null;
        }

        $copy->save();

        foreach ($rule->conditions as $condition) {
            $copy->conditions()->create([
                'type' => $condition->type,
                'value_ids' => $condition->value_ids,
            ]);
        }

        return $copy->refresh()->load('conditions');
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Product::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('sku', 'like', "%{$q}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => ($product->translations->first()?->name ?? $product->sku).' ('.$product->sku.')',
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Customer::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'email'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => trim($customer->name.' ('.$customer->email.')'),
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    public function searchCategories(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Category::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('position')
            ->limit(25)
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->translations->first()?->name ?? "Category #{$category->id}",
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    public function searchBrands(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Brand::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('position')
            ->limit(25)
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->translations->first()?->name ?? "Brand #{$brand->id}",
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    public function searchRegions(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = CountryRegion::query()
            ->with('country')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhereHas('country', fn ($country) => $country->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"));
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (CountryRegion $region) => [
                'id' => $region->id,
                'name' => $region->name.($region->country ? ' ('.$region->country->code.')' : ''),
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Customer group is a condition only while group-specific discounts are on.
     *
     * @return array<string, string>
     */
    protected function conditionTypeMap(): array
    {
        $types = DiscountRuleCondition::TYPES;
        if (! CustomerSettings::groupSpecificDiscounts()) {
            unset($types['customer_group']);
        }

        return $types;
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'applyAs' => collect(DiscountRule::APPLY_AS)
                ->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                    'hint' => match ($value) {
                        'catalog' => 'Changes the product price in the catalog and cart',
                        'cart' => 'Applied automatically at checkout when conditions match',
                        'coupon' => 'Customer enters a code at checkout',
                        default => '',
                    },
                ])
                ->values()
                ->all(),
            'actions' => collect(DiscountRule::ACTIONS)
                ->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                    'hint' => match ($value) {
                        'percent' => 'Take a percentage off the matching price',
                        'amount' => 'Take a fixed amount off',
                        'set_price' => 'Replace the catalog unit price (catalog discount rules only)',
                        'buy_x_get_y' => 'Cart/coupon: cheapest matching items are free (buy X get 1)',
                        default => '',
                    },
                ])
                ->values()
                ->all(),
            'conditionTypes' => collect($this->conditionTypeMap())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'options' => [
                'customer_groups' => CustomerGroup::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (CustomerGroup $group) => [
                        'id' => $group->id,
                        'name' => $group->name,
                    ])
                    ->values()
                    ->all(),
                'countries' => Country::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Country $country) => [
                        'id' => $country->id,
                        'name' => $country->name,
                    ])
                    ->values()
                    ->all(),
                'currencies' => Currency::query()
                    ->where('is_enabled', true)
                    ->orderBy('position')
                    ->get(['id', 'name', 'code', 'symbol'])
                    ->map(fn (Currency $currency) => [
                        'id' => $currency->id,
                        'name' => $currency->name.' ('.$currency->code.')',
                        'code' => $currency->code,
                        'symbol' => $currency->symbol ?: $currency->code,
                    ])
                    ->values()
                    ->all(),
            ],
            'selected' => [
                'products' => [],
                'categories' => [],
                'brands' => [],
                'customers' => [],
                'customer_groups' => [],
                'countries' => [],
                'regions' => [],
                'currencies' => [],
            ],
            'defaultCurrency' => Currency::query()
                ->where('is_enabled', true)
                ->orderByDesc('is_default')
                ->orderBy('position')
                ->first(['id', 'name', 'code', 'symbol']),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?DiscountRule $existing = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code', ''))) ?: null,
            'currency_code' => strtoupper(trim((string) $request->input('currency_code', ''))) ?: null,
            'min_cart' => $request->input('min_cart') === '' ? null : $request->input('min_cart'),
            'min_qty' => $request->input('min_qty') === '' ? null : $request->input('min_qty'),
            'max_discount' => $request->input('max_discount') === '' ? null : $request->input('max_discount'),
            'max_uses' => $request->input('max_uses') === '' ? null : $request->input('max_uses'),
            'max_uses_per_customer' => $request->input('max_uses_per_customer') === '' ? null : $request->input('max_uses_per_customer'),
            'starts_at' => $request->input('starts_at') === '' ? null : $request->input('starts_at'),
            'expires_at' => $request->input('expires_at') === '' ? null : $request->input('expires_at'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'apply_as' => ['required', Rule::in(array_keys(DiscountRule::APPLY_AS))],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('discount_rules', 'code')->ignore($existing?->id),
            ],
            'action' => ['required', Rule::in(array_keys(DiscountRule::ACTIONS))],
            'value' => ['required', 'numeric', 'min:0'],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where(fn ($query) => $query->where('is_enabled', true)),
            ],
            'priority' => ['nullable', 'integer', 'min:0'],
            'stop_further' => ['nullable', 'boolean'],
            'min_cart' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_enabled' => ['required', 'boolean'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.type' => ['required', Rule::in(array_keys(DiscountRuleCondition::TYPES))],
            'conditions.*.value_ids' => ['nullable', 'array'],
            'conditions.*.value_ids.*' => ['nullable'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['stop_further'] = (bool) ($data['stop_further'] ?? false);
        $data['priority'] = (int) ($data['priority'] ?? 100);
        $data['code'] = $data['code'] ?? null;
        $data['currency_code'] = strtoupper((string) $data['currency_code']);

        if ($data['apply_as'] === 'coupon' && ! $data['code']) {
            throw ValidationException::withMessages(['code' => 'A coupon code is required.']);
        }
        if ($data['apply_as'] !== 'coupon') {
            $data['code'] = null;
        }
        if ($data['action'] === 'set_price' && $data['apply_as'] !== 'catalog') {
            throw ValidationException::withMessages(['action' => 'Set price is only available for catalog discounts.']);
        }
        if ($data['action'] === 'buy_x_get_y' && $data['apply_as'] === 'catalog') {
            throw ValidationException::withMessages(['action' => 'Buy X get 1 is a cart or coupon action.']);
        }
        if ($data['action'] === 'percent' && (float) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Percent off cannot exceed 100.']);
        }

        $data['conditions'] = collect($data['conditions'] ?? [])
            ->map(function (array $row) {
                $ids = collect($row['value_ids'] ?? [])
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => is_numeric($id) ? (int) $id : (string) $id)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'type' => $row['type'],
                    'value_ids' => $row['type'] === 'catalog' ? [] : $ids,
                ];
            })
            ->unique(fn (array $row) => $row['type'].':'.implode(',', $row['value_ids']))
            ->when(
                ! CustomerSettings::groupSpecificDiscounts(),
                fn ($rows) => $rows->reject(fn (array $row) => $row['type'] === 'customer_group'),
            )
            ->values()
            ->all();

        foreach (['min_cart', 'min_qty', 'max_discount', 'max_uses', 'max_uses_per_customer', 'starts_at', 'expires_at'] as $field) {
            if (($data[$field] ?? null) === '' || ($data[$field] ?? null) === null) {
                $data[$field] = null;
            }
        }

        if ($data['starts_at']) {
            $data['starts_at'] = Carbon::parse($data['starts_at']);
        }
        if ($data['expires_at']) {
            $data['expires_at'] = Carbon::parse($data['expires_at']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function persist(DiscountRule $rule, array $data): DiscountRule
    {
        \App\Domain\Promo\Models\DiscountRedemption::ensureStorage();
        $rule->fill([
            'name' => $data['name'],
            'apply_as' => $data['apply_as'],
            'code' => $data['code'],
            'action' => $data['action'],
            'value' => $data['value'],
            'currency_code' => $data['currency_code'],
            'priority' => $data['priority'],
            'stop_further' => $data['stop_further'],
            'min_cart' => $data['min_cart'],
            'min_qty' => $data['min_qty'],
            'max_discount' => $data['max_discount'],
            'max_uses' => $data['max_uses'],
            'max_uses_per_customer' => $data['max_uses_per_customer'],
            'starts_at' => $data['starts_at'],
            'expires_at' => $data['expires_at'],
            'is_enabled' => $data['is_enabled'],
        ]);
        if (! $rule->exists) {
            $rule->used_count = 0;
        }
        $rule->save();

        $rule->conditions()->delete();
        foreach ($data['conditions'] as $condition) {
            $rule->conditions()->create($condition);
        }

        return $rule->refresh()->load('conditions');
    }

    /** @return list<array{type: string, value_ids: list<int|string>}> */
    protected function presentConditions(DiscountRule $rule): array
    {
        return $rule->conditions->map(fn (DiscountRuleCondition $condition) => [
            'type' => $condition->type,
            'value_ids' => $condition->values(),
        ])->values()->all();
    }

    /**
     * @param  list<array{type: string, value_ids: list<int|string>}>  $conditions
     * @return array<string, mixed>
     */
    protected function selectedOptions(array $conditions): array
    {
        $ids = fn (string $type) => collect($conditions)
            ->where('type', $type)
            ->flatMap(fn ($row) => $row['value_ids'])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'products' => Product::query()->with('translations')->whereIn('id', $ids('product'))->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => ($product->translations->first()?->name ?? $product->sku).' ('.$product->sku.')',
                ]),
            'categories' => Category::query()->with('translations')->whereIn('id', $ids('category'))->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->translations->first()?->name ?? "Category #{$category->id}",
                ]),
            'brands' => Brand::query()->with('translations')->whereIn('id', $ids('brand'))->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->translations->first()?->name ?? "Brand #{$brand->id}",
                ]),
            'customers' => Customer::query()->whereIn('id', $ids('customer'))->get(['id', 'name', 'email'])
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => trim($customer->name.' ('.$customer->email.')'),
                ]),
            'customer_groups' => CustomerGroup::query()->whereIn('id', $ids('customer_group'))->get(['id', 'name']),
            'countries' => Country::query()->whereIn('id', $ids('country'))->get(['id', 'name']),
            'regions' => CountryRegion::query()->with('country')->whereIn('id', $ids('region'))->get()
                ->map(fn (CountryRegion $region) => [
                    'id' => $region->id,
                    'name' => $region->name.($region->country ? ' ('.$region->country->code.')' : ''),
                ]),
            'currencies' => Currency::query()->whereIn('id', $ids('currency'))->get(['id', 'name', 'code', 'symbol'])
                ->map(fn (Currency $currency) => [
                    'id' => $currency->id,
                    'name' => $currency->name.' ('.$currency->code.')',
                    'code' => $currency->code,
                    'symbol' => $currency->symbol ?: $currency->code,
                ])
                ->values()
                ->all(),
        ];
    }
}
