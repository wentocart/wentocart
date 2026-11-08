<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\Sharing;
use App\Domain\Store\StoreContext;
use App\Domain\Store\StoreService;
use App\Domain\Theme\ThemeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShopController extends Controller
{
    public function __construct(protected StoreService $shops) {}

    public function switchContext(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'context' => ['nullable', Rule::in(['all', 'group', 'shop'])],
            'group_id' => ['nullable', 'integer'],
            'store_id' => ['nullable'],
            'id_shop' => ['nullable'],
        ]);
        $kind = (string) ($data['context'] ?? '');
        $raw = (string) ($data['store_id'] ?? $data['id_shop'] ?? '');
        if ($kind === '') {
            $kind = ($raw === 'all' || $raw === 'view_all') ? 'all' : 'shop';
        }
        if ($kind === 'all') {
            $request->session()->put('admin.context', 'all');
            $request->session()->put('admin.store_id', 'all');

            return back();
        }
        if ($kind === 'group') {
            $group = StoreGroup::query()->where('is_enabled', true)->findOrFail((int) ($data['group_id'] ?? 0));
            $shop = $group->stores()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->first();
            if (! $shop) {
                throw ValidationException::withMessages(['group_id' => 'This group has no shop yet.']);
            }
            $request->session()->put('admin.context', 'group:'.$group->id);
            $request->session()->put('admin.store_id', $shop->id);
            $request->session()->put('admin.store_id_concrete', $shop->id);

            return back();
        }
        $store = Store::query()->where('is_enabled', true)->with('urls')->findOrFail((int) $raw);
        if ($store->primaryUrl() === null) {
            throw ValidationException::withMessages(['store_id' => 'Add a URL before selecting this shop.']);
        }
        $request->session()->put('admin.context', 'shop:'.$store->id);
        $request->session()->put('admin.store_id', $store->id);
        $request->session()->put('admin.store_id_concrete', $store->id);

        return back();
    }

    public function index(Request $request): Response
    {
        $this->requireEnabled();
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $groupId = $request->integer('group');

        $base = Store::query()->with(['urls', 'group']);
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $stores = Store::query()
            ->with(['urls', 'group', 'policies'])
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($groupId > 0, fn ($query) => $query->where('store_group_id', $groupId))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (Store $store) => $this->presentList($store));

        return Inertia::render('Shops/Index', [
            'stores' => $stores,
            'counts' => $counts,
            'groups' => StoreGroup::query()->withCount('stores')->orderBy('name')->get()
                ->map(function (StoreGroup $group) {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'code' => $group->code,
                        'description' => $group->description,
                        'shops' => $group->stores_count,
                        'share_customers' => $group->share_customers,
                        'share_orders' => $group->share_orders,
                        'share_stock' => $group->share_stock,
                        'color' => $group->color ?: '',
                        'is_enabled' => $group->is_enabled,
                        'can_delete' => $group->canBeDeleted(),
                    ] + $this->shops->groupShareLocks($group);
                })->values()->all(),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'group' => $groupId > 0 ? (string) $groupId : 'all',
            ],
            'default_shop_id' => Store::query()->where('is_default', true)->value('id'),
            'default_shops' => Store::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->requireEnabled();

        return Inertia::render('Shops/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireEnabled();
        $data = $this->validated($request);

        $store = $this->shops->create($data);

        return redirect()->route('admin.shops.edit', $store)->with('success', 'Shop created.');
    }

    public function edit(Store $shop): Response
    {
        $this->requireEnabled();
        $shop->load(['urls', 'policies', 'group']);

        return Inertia::render('Shops/Form', $this->formProps($shop));
    }

    public function update(Request $request, Store $shop): RedirectResponse
    {
        $this->requireEnabled();
        $data = $this->validated($request, $shop);
        $this->shops->update($shop, $data);

        return back()->with('success', 'Shop saved.');
    }

    public function toggle(Store $shop): RedirectResponse
    {
        $this->requireEnabled();
        try {
            $this->shops->toggle($shop);
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['is_enabled' => $e->getMessage()]);
        }

        return back()->with('success', $shop->is_enabled ? 'Shop enabled.' : 'Shop disabled.');
    }

    public function destroy(Store $shop): RedirectResponse
    {
        $this->requireEnabled();
        try {
            $this->shops->delete($shop);
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['shop' => $e->getMessage()]);
        }

        return redirect()->route('admin.shops.index')->with('success', 'Shop deleted.');
    }

    public function setDefault(Request $request): RedirectResponse
    {
        $this->requireEnabled();
        $data = $request->validate([
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')],
        ]);
        $shop = Store::query()->findOrFail((int) $data['store_id']);

        return $this->makeDefault($shop);
    }

    public function makeDefault(Store $shop): RedirectResponse
    {
        $this->requireEnabled();
        try {
            $this->shops->makeDefault($shop);
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['shop' => $e->getMessage()]);
        }

        return back()->with('success', $shop->name.' is now the default shop.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formProps(?Store $store = null): array
    {
        $store?->loadMissing(['urls', 'policies', 'group']);
        $sources = Store::query()->with('group')->where('is_enabled', true)->orderBy('name')->get()
            ->map(fn (Store $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'group_id' => $row->store_group_id,
            ])
            ->values()->all();

        $policies = [];
        foreach (Sharing::families() as $family) {
            $row = $store?->policy($family);
            $policies[] = [
                'family' => $family,
                'label' => Sharing::familyLabel($family),
                'hint' => Sharing::familyHint($family),
                'mode' => $row?->mode ?? Sharing::MODE_OWN,
                'source_store_id' => $row?->source_store_id,
            ];
        }

        return [
            'record' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'email' => $store->email,
                'address' => $store->address,
                'theme' => $store->theme ?: 'default',
                'shop_url' => $store->primaryUrl()?->root() ?: '',
                'scheme' => $store->primaryUrl()?->scheme ?: 'http',
                'domain' => $store->primaryUrl()?->domain ?: '',
                'port' => $store->primaryUrl()?->port,
                'physical_uri' => $store->primaryUrl()?->physical_uri ?: '/',
                'virtual_uri' => trim((string) ($store->primaryUrl()?->virtual_uri ?? ''), '/'),
                'is_default' => $store->is_default,
                'is_enabled' => $store->is_enabled,
                'can_delete' => $store->canBeDeleted(),
                'color' => $store->color ?: '',
                'group_id' => $store->store_group_id,
            ] : null,
            'url_defaults' => ShopUrl::attributesFromRequest(request()),
            'policies' => $policies,
            'sources' => $sources,
            'groups' => StoreGroup::query()->orderBy('name')->get(['id', 'name']),
            'presets' => Sharing::presetOptions(),
            'import_options' => Sharing::importOptions(),
            'modes' => Sharing::modeOptions(),
            'default_copy_from_id' => app(StoreContext::class)->defaultStore()?->id,
            'install_physical' => ShopUrl::installPhysicalUri(),
            'virtual_reserved' => ShopUrl::reservedVirtual(),
            'themes' => collect(app(ThemeRegistry::class)->all())->map(fn ($theme) => [
                'name' => $theme->name,
                'label' => $theme->displayName,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentList(Store $store): array
    {
        $url = $store->primaryUrl()?->root();

        return [
            'id' => $store->id,
            'name' => $store->name,
            'code' => $store->code,
            'url' => $url,
            'theme' => $store->theme,
            'is_default' => $store->is_default,
            'is_enabled' => $store->is_enabled,
            'can_delete' => $store->canBeDeleted(),
            'color' => $store->color ?: '',
            'group' => $store->group?->name,
            'actions' => [
                'edit' => true,
                'delete' => $store->canBeDeleted(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Store $store = null): array
    {
        $creating = $store === null;
        $request->merge([
            'virtual_uri' => ShopUrl::tidyVirtualSegment((string) $request->input('virtual_uri', '')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'scheme' => ['nullable', Rule::in(['http', 'https'])],
            'domain' => ['required_without:shop_url', 'nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'physical_uri' => ['nullable', 'string', 'max:255'],
            'virtual_uri' => ['nullable', 'string', 'max:'.ShopUrl::VIRTUAL_MAX, function (string $attribute, mixed $value, \Closure $fail): void {
                $message = ShopUrl::virtualUriMessage(is_string($value) ? $value : '');
                if ($message !== null) {
                    $fail($message);
                }
            }],
            'shop_url' => ['required_without:domain', 'nullable', 'url', 'max:255'],
            'theme' => ['required', 'string', Rule::in($this->themeCodes())],
            'is_enabled' => ['sometimes', 'boolean'],
            'preset' => ['nullable', Rule::in([Sharing::PRESET_SAME, Sharing::PRESET_EMPTY, Sharing::PRESET_CUSTOM])],
            'import_data' => ['sometimes', 'boolean'],
            'import' => ['nullable', 'array'],
            'import.*' => ['in:'.implode(',', Sharing::importKeys())],
            'copy_from_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'group_id' => ['nullable', 'integer', Rule::exists('store_groups', 'id')],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'policies' => ['nullable', 'array'],
            'policies.*.family' => ['required_with:policies', Rule::in(Sharing::families())],
            'policies.*.mode' => ['required_with:policies', Rule::in(Sharing::modes())],
            'policies.*.source_store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
        ]);

        if (! empty($data['domain'])) {
            $parts = ShopUrl::attributesFromParts($data);
        } else {
            $parts = ShopUrl::attributesFromUrl((string) $data['shop_url']);
        }
        $installPhysical = ShopUrl::installPhysicalUri($request);
        $sameHost = strtolower((string) $parts['domain']) === strtolower($request->getHost());
        if ($sameHost && $installPhysical !== '/' && ShopUrl::normalizePhysical((string) $parts['physical_uri']) !== $installPhysical) {
            $parts['physical_uri'] = $installPhysical;
        }
        if (($data['theme'] ?? '') === 'default') {
            $data['theme'] = 'classic';
        }
        $this->assertUniqueUrl($parts, $store);

        if ($creating) {
            $data['import_data'] = $request->boolean('import_data');
            $data['import'] = array_values((array) ($data['import'] ?? []));
            if ($data['import_data'] && empty($data['copy_from_id'])) {
                throw ValidationException::withMessages([
                    'copy_from_id' => 'Pick a shop to import from.',
                ]);
            }
        }

        return array_merge($data, $parts);
    }

    /**
     * @return list<string>
     */
    protected function themeCodes(): array
    {
        $codes = array_map(
            fn ($theme) => $theme->name,
            app(ThemeRegistry::class)->all(),
        );
        $codes[] = ThemeRegistry::DEFAULT_ALIAS;

        return array_values(array_unique($codes));
    }

    /**
     * @param  array{domain: string, physical_uri: string, virtual_uri?: string, port?: ?int}  $attrs
     */
    protected function assertUniqueUrl(array $attrs, ?Store $store): void
    {
        $virtual = ShopUrl::normalizeVirtual((string) ($attrs['virtual_uri'] ?? ''));
        $virtualKey = ShopUrl::virtualKey($virtual);
        $exists = ShopUrl::query()
            ->where('domain', $attrs['domain'])
            ->where('physical_uri', ShopUrl::normalizePhysical((string) $attrs['physical_uri']))
            ->when($attrs['port'] ?? null, fn ($q) => $q->where('port', $attrs['port']), fn ($q) => $q->where(function ($inner) {
                $inner->whereNull('port')->orWhereIn('port', [80, 443]);
            }))
            ->when($store, fn ($q) => $q->where('store_id', '!=', $store->id))
            ->get()
            ->contains(fn (ShopUrl $row) => ShopUrl::virtualKey((string) $row->virtual_uri) === $virtualKey);
        if ($exists) {
            throw ValidationException::withMessages([
                'virtual_uri' => 'That URL is already used by another shop.',
                'shop_url' => 'That URL is already used by another shop.',
            ]);
        }
    }

    protected function requireEnabled(): void
    {
        if (! app(StoreContext::class)->enabled()) {
            abort(404);
        }
    }
}
