<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\StoreContext;
use App\Domain\Store\StoreService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShopGroupController extends Controller
{
    public function __construct(protected StoreService $shops) {}

    public function store(Request $request): RedirectResponse
    {
        $this->requireEnabled();
        try {
            $this->shops->createGroup($this->validated($request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Shop group created.');
    }

    public function update(Request $request, StoreGroup $shopGroup): RedirectResponse
    {
        $this->requireEnabled();
        try {
            $this->shops->updateGroup($shopGroup, $this->validated($request, true));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Shop group saved.');
    }

    public function destroy(Request $request, StoreGroup $shopGroup): RedirectResponse
    {
        $this->requireEnabled();
        $moveTo = $request->filled('move_to_group_id') ? $request->integer('move_to_group_id') : null;
        try {
            $this->shops->deleteGroup($shopGroup, $moveTo);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw ValidationException::withMessages(['group' => $e->getMessage()]);
        }

        return back()->with('success', 'Shop group deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'share_customers' => ['sometimes', 'boolean'],
            'share_orders' => ['sometimes', 'boolean'],
            'share_stock' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    protected function requireEnabled(): void
    {
        if (! app(StoreContext::class)->enabled()) {
            abort(404);
        }
    }
}
