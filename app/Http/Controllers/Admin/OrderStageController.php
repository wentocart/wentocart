<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Profile;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Order\OrderStageCatalog;
use App\Domain\Order\Support\OrderStageCsvSchema;
use App\Domain\Settings\Models\EmailTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\HexColor;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderStageController extends Controller
{
    public function index(Request $request): Response
    {
        OrderStageCatalog::seed();

        $status = $request->string('status')->toString() ?: 'all';
        $name = trim($request->string('name')->toString());
        $buyerLabel = trim($request->string('buyer_label')->toString());
        $requestedSort = $request->string('sort')->toString() ?: 'position';
        $sort = match ($requestedSort) {
            'id' => 'id',
            'name' => 'name',
            'buyer_label' => 'buyer_label',
            'is_enabled' => 'is_enabled',
            default => 'position',
        };
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';

        $base = OrderStage::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $stages = OrderStage::query()
            ->with('profiles:id,name')
            ->when($status === 'active', fn ($q) => $q->where('is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_enabled', false))
            ->when($name !== '', fn ($q) => $q->where(function ($inner) use ($name) {
                $inner->where('name', 'like', "%{$name}%")
                    ->orWhere('code', 'like', "%{$name}%");
            }))
            ->when($buyerLabel !== '', fn ($q) => $q->where('buyer_label', 'like', "%{$buyerLabel}%"))
            ->when($request->filled('id_min'), fn ($q) => $q->where('id', '>=', (int) $request->input('id_min')))
            ->when($request->filled('id_max'), fn ($q) => $q->where('id', '<=', (int) $request->input('id_max')))
            ->orderBy($sort, $dir)
            ->orderBy('position')
            ->adminPaginate()
            ->through(fn (OrderStage $stage) => $stage->toAdminArray());

        return Inertia::render('OrderStages/Index', [
            'stages' => $stages,
            'counts' => $counts,
            'can_manage' => (bool) $request->user('admin')?->isSuperAdmin(),
            'filters' => [
                'status' => $status,
                'name' => $name,
                'buyer_label' => $buyerLabel,
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $this->assertSuperAdmin();
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        OrderStage::query()
            ->whereIn('id', $validated['ids'])
            ->update(['is_enabled' => $validated['is_enabled']]);

        return back()->with('success', 'Selected order statuses updated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->assertSuperAdmin();
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $stages = OrderStage::query()->whereIn('id', $validated['ids'])->get();
        $deleted = 0;
        $skipped = 0;

        foreach ($stages as $stage) {
            if ($stage->is_system || Order::query()->where('status', $stage->code)->exists()) {
                $skipped++;
                continue;
            }
            $stage->delete();
            $deleted++;
        }

        if ($skipped > 0) {
            return back()->with('warning', "Deleted {$deleted} statuses. Skipped {$skipped} system or in-use statuses.");
        }

        return back()->with('success', "Deleted {$deleted} order statuses.");
    }

    public function export()
    {
        return $this->csvDownload('order-stages.csv', OrderStageCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('order-stages-sample.csv', OrderStageCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        $this->assertSuperAdmin();

        return $this->csvImport($request, OrderStageCsvSchema::import(...), 'order stages');
    }

    public function create(): Response
    {
        $this->assertSuperAdmin();

        return Inertia::render('OrderStages/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertSuperAdmin();
        $data = $this->validated($request);
        $code = $this->uniqueCode($data['name']);
        $meanings = OrderStage::attributesFromMeanings($data);

        $stage = OrderStage::query()->create(array_merge([
            'code' => $code,
            'name' => $data['name'],
            'buyer_label' => $data['buyer_label'],
            'buyer_note' => $data['buyer_note'] ?? null,
            'tone' => $data['tone'],
            'notify_buyer' => $data['notify_buyer'],
            'notify_staff' => false,
            'mail_template' => $data['notify_buyer'] ? ($data['mail_template'] ?: 'order_status') : null,
            'buyer_may_pay' => ($data['money_meaning'] ?? 'none') === 'none',
            'buyer_may_cancel' => ($data['money_meaning'] ?? 'none') === 'none',
            'next_stage_ids' => null,
            'sla_hours' => null,
            'is_system' => false,
            'is_enabled' => $data['is_enabled'],
            'position' => (int) (OrderStage::query()->max('position') ?? 0) + 10,
        ], $meanings));

        $stage->profiles()->sync($this->profileIds($data));

        return redirect()->route('admin.order-stages.index')->with('success', 'Order status created.');
    }

    public function edit(OrderStage $orderStage): Response
    {
        $this->assertSuperAdmin();
        $orderStage->load('profiles:id,name');

        return Inertia::render('OrderStages/Form', [
            ...$this->formProps(),
            'stage' => $orderStage->toAdminArray(),
        ]);
    }

    public function update(Request $request, OrderStage $orderStage): RedirectResponse
    {
        $this->assertSuperAdmin();
        $data = $this->validated($request, $orderStage);
        $meanings = OrderStage::attributesFromMeanings($data);

        $orderStage->update(array_merge([
            'name' => $data['name'],
            'buyer_label' => $data['buyer_label'],
            'buyer_note' => $data['buyer_note'] ?? null,
            'tone' => $data['tone'],
            'notify_buyer' => $data['notify_buyer'],
            'mail_template' => $data['notify_buyer'] ? ($data['mail_template'] ?: 'order_status') : null,
            'is_enabled' => $data['is_enabled'],
        ], $meanings));

        $orderStage->profiles()->sync($this->profileIds($data));

        return back()->with('success', 'Order status updated.');
    }

    public function destroy(OrderStage $orderStage): RedirectResponse
    {
        $this->assertSuperAdmin();
        if ($orderStage->is_system) {
            return back()->with('error', 'System statuses cannot be deleted. Disable them instead.');
        }

        if (Order::query()->where('status', $orderStage->code)->exists()) {
            return back()->with('error', 'This status is used by orders. Disable it instead of deleting.');
        }

        $orderStage->delete();

        return redirect()->route('admin.order-stages.index')->with('success', 'Order status deleted.');
    }

    public function toggle(OrderStage $orderStage): RedirectResponse
    {
        $this->assertSuperAdmin();
        $orderStage->update(['is_enabled' => ! $orderStage->is_enabled]);

        return back()->with('success', $orderStage->is_enabled ? 'Status enabled.' : 'Status disabled.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formProps(): array
    {
        $templates = EmailTemplate::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (EmailTemplate $t) => ['value' => $t->code, 'label' => $t->name])
            ->values()
            ->all();

        if ($templates === []) {
            $templates = [['value' => 'order_status', 'label' => 'Order status']];
        }

        return [
            'mailTemplates' => $templates,
            'profiles' => Profile::query()
                ->where('is_enabled', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description'])
                ->map(fn (Profile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'description' => $profile->description,
                ])
                ->values()
                ->all(),
            'tones' => ['primary', 'info', 'warning', 'success', 'danger', 'neutral'],
            'moneyMeanings' => [
                ['value' => 'none', 'label' => 'No money change'],
                ['value' => 'paid', 'label' => 'Mark as paid (unlock invoice)'],
                ['value' => 'cancelled', 'label' => 'Cancel order (restock)'],
                ['value' => 'refunded', 'label' => 'Refunded (credit slip)'],
            ],
            'fulfillmentMeanings' => [
                ['value' => 'none', 'label' => 'Not started'],
                ['value' => 'processing', 'label' => 'Processing / packing'],
                ['value' => 'shipped', 'label' => 'Shipped (delivery slip)'],
                ['value' => 'delivered', 'label' => 'Delivered'],
                ['value' => 'stopped', 'label' => 'Stopped'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?OrderStage $stage = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'buyer_label' => ['required', 'string', 'max:120'],
            'buyer_note' => ['nullable', 'string', 'max:255'],
            'tone' => HexColor::rules(),
            'money_meaning' => ['required', 'string', Rule::in(['none', 'paid', 'cancelled', 'refunded'])],
            'fulfillment_meaning' => ['required', 'string', Rule::in(['none', 'processing', 'shipped', 'delivered', 'stopped'])],
            'notify_buyer' => ['required', 'boolean'],
            'mail_template' => ['nullable', 'string', 'max:64'],
            'unlock_invoice' => ['required', 'boolean'],
            'unlock_credit_slip' => ['required', 'boolean'],
            'unlock_delivery_slip' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
            'profile_ids' => ['nullable', 'array'],
            'profile_ids.*' => ['integer', 'exists:profiles,id'],
        ]);
        $data['tone'] = HexColor::parse($data['tone'], HexColor::TONES['neutral']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    protected function profileIds(array $data): array
    {
        return array_values(array_unique(array_map('intval', $data['profile_ids'] ?? [])));
    }

    protected function assertSuperAdmin(): void
    {
        abort_unless(
            (bool) request()->user('admin')?->isSuperAdmin(),
            403,
            'Only a super admin can create or edit order statuses.'
        );
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'stage';
        $base = Str::limit($base, 50, '');
        $code = $base;
        $i = 2;
        while (OrderStage::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }
}
