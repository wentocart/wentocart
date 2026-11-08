<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\FlagReasonSchema;
use App\Domain\Marketplace\Models\MarketplaceFlagReason;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 */
class AdminMarketplaceFlagReasonsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return $this->list($request, false);
    }

    /** Storefront catalog of enabled flag reasons (no admin auth). */
    public function publicIndex(Request $request): JsonResponse
    {
        return $this->list($request, true);
    }

    private function list(Request $request, bool $publicOnly): JsonResponse
    {
        FlagReasonSchema::ensure();
        $q = MarketplaceFlagReason::query()->orderBy('position')->orderBy('id');
        if ($publicOnly) {
            $q->where('is_enabled', true);
        } elseif ($request->query('enabled') !== null && $request->query('enabled') !== '') {
            $q->where('is_enabled', filter_var($request->query('enabled'), FILTER_VALIDATE_BOOLEAN));
        }
        $applies = trim((string) ($request->query('applies_to') ?? $request->query('appliesTo') ?? ''));
        if (in_array($applies, ['seller', 'product', 'both'], true)) {
            $q->where(function ($inner) use ($applies) {
                $inner->where('applies_to', $applies)->orWhere('applies_to', 'both');
            });
        }
        $rows = $q->get()->map(fn (MarketplaceFlagReason $r) => $this->row($r))->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        FlagReasonSchema::ensure();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:64', 'unique:marketplace_flag_reasons,code'],
            'applies_to' => ['required', Rule::in(['seller', 'product', 'both'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($data['label']) ?: 'reason-'.Str::lower(Str::random(6));
        }
        $row = MarketplaceFlagReason::query()->create([
            'label' => $data['label'],
            'code' => $code,
            'applies_to' => $data['applies_to'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'position' => (int) ($data['position'] ?? 0),
        ]);

        return response()->json(['message' => 'Flag reason created.', 'data' => $this->row($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        FlagReasonSchema::ensure();
        $row = MarketplaceFlagReason::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Flag reason not found.', 'error' => 'not_found'], 404);
        }
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('marketplace_flag_reasons', 'code')->ignore($row->id)],
            'applies_to' => ['required', Rule::in(['seller', 'product', 'both'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $row->fill([
            'label' => $data['label'],
            'code' => trim((string) ($data['code'] ?? $row->code)) ?: $row->code,
            'applies_to' => $data['applies_to'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
        ])->save();

        return response()->json(['message' => 'Flag reason updated.', 'data' => $this->row($row->fresh() ?? $row)]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        FlagReasonSchema::ensure();
        $row = MarketplaceFlagReason::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Flag reason not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Flag reason deleted.', 'data' => ['id' => $id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MarketplaceFlagReason $row): array
    {
        return [
            'id' => (int) $row->id,
            'label' => (string) $row->label,
            'code' => (string) $row->code,
            'appliesTo' => (string) $row->applies_to,
            'isEnabled' => $row->is_enabled ? 1 : 0,
            'position' => (int) $row->position,
        ];
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
