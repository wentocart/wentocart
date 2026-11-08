<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceFulfilmentCarriers;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMarketplaceFulfilmentCarriersRestController extends Controller
{
    public function show(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $all = MarketplaceFulfilmentCarriers::all();

        return response()->json(['data' => $this->present($all)]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $data = $request->validate([
            'self_ship_title' => ['nullable', 'string', 'max:120'],
            'selfShipTitle' => ['nullable', 'string', 'max:120'],
            'carriers' => ['nullable', 'array'],
            'carriers.*.code' => ['required_with:carriers', 'string', 'max:64'],
            'carriers.*.title' => ['nullable', 'string', 'max:120'],
            'carriers.*.is_enabled' => ['nullable', 'boolean'],
            'carriers.*.isEnabled' => ['nullable', 'boolean'],
            'carriers.*.config' => ['nullable', 'array'],
        ]);
        $saved = MarketplaceFulfilmentCarriers::save($data);

        return response()->json([
            'message' => 'Fulfilment carriers saved.',
            'data' => $this->present($saved),
        ]);
    }

    /**
     * @param  array{self_ship_title: string, carriers: list<array<string, mixed>>}  $all
     * @return array<string, mixed>
     */
    private function present(array $all): array
    {
        return [
            'selfShipTitle' => (string) ($all['self_ship_title'] ?? ''),
            'selfShipAlwaysEnabled' => true,
            'carriers' => array_map(fn (array $c) => [
                'code' => (string) ($c['code'] ?? ''),
                'title' => (string) ($c['title'] ?? ''),
                'isEnabled' => (bool) ($c['is_enabled'] ?? false),
                'config' => $c['config'] ?? [],
            ], $all['carriers'] ?? []),
        ];
    }

    private function guard(): ?JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
