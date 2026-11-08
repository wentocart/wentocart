<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\Models\SellerUser;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 */
class SellerTeamRestController extends Controller
{
    public function rolesIndex(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $rows = SellerRole::query()->where('seller_id', $seller->id)->orderBy('name')->get()
            ->map(fn (SellerRole $r) => $this->roleRow($r))->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function rolesStore(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_type' => ['required', Rule::in([SellerRole::TYPE_ALL, SellerRole::TYPE_CUSTOM])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:80'],
        ]);
        if ($data['permission_type'] === SellerRole::TYPE_CUSTOM && empty($data['permissions'])) {
            return response()->json(['message' => 'Custom roles need at least one permission.', 'error' => 'validation'], 422);
        }
        $role = SellerRole::query()->create([
            'seller_id' => $seller->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permission_type' => $data['permission_type'],
            'permissions' => $data['permission_type'] === SellerRole::TYPE_ALL ? null : array_values($data['permissions'] ?? []),
        ]);

        return response()->json(['message' => 'Role created.', 'data' => $this->roleRow($role)], 201);
    }

    public function rolesUpdate(Request $request, int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $role = SellerRole::query()->where('seller_id', $seller->id)->find($id);
        if (! $role) {
            return response()->json(['message' => 'Role not found.', 'error' => 'not_found'], 404);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_type' => ['required', Rule::in([SellerRole::TYPE_ALL, SellerRole::TYPE_CUSTOM])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:80'],
        ]);
        if ($data['permission_type'] === SellerRole::TYPE_CUSTOM && empty($data['permissions'])) {
            return response()->json(['message' => 'Custom roles need at least one permission.', 'error' => 'validation'], 422);
        }
        $role->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permission_type' => $data['permission_type'],
            'permissions' => $data['permission_type'] === SellerRole::TYPE_ALL ? null : array_values($data['permissions'] ?? []),
        ])->save();

        return response()->json(['message' => 'Role updated.', 'data' => $this->roleRow($role->fresh() ?? $role)]);
    }

    public function rolesDestroy(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $role = SellerRole::query()->where('seller_id', $seller->id)->find($id);
        if (! $role) {
            return response()->json(['message' => 'Role not found.', 'error' => 'not_found'], 404);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Role is in use by team members.', 'error' => 'validation'], 422);
        }
        if (SellerRole::query()->where('seller_id', $seller->id)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the only role.', 'error' => 'validation'], 422);
        }
        $role->delete();

        return response()->json(['message' => 'Role deleted.', 'data' => ['id' => $id]]);
    }

    public function usersIndex(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $rows = SellerUser::query()->with('role:id,name')->where('seller_id', $seller->id)->orderBy('name')->get()
            ->map(fn (SellerUser $u) => $this->userRow($u))->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function usersStore(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:6', 'max:120'],
            'role_id' => ['required', 'integer'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $role = SellerRole::query()->where('seller_id', $seller->id)->find((int) $data['role_id']);
        if (! $role) {
            return response()->json(['message' => 'Role not found for this shop.', 'error' => 'validation'], 422);
        }
        if (SellerUser::query()->where('seller_id', $seller->id)->where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email already used on this shop.', 'error' => 'validation'], 422);
        }
        if ($cap = $this->staffCapReached($seller)) {
            return $cap;
        }
        $user = SellerUser::query()->create([
            'seller_id' => $seller->id,
            'role_id' => $role->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);

        return response()->json(['message' => 'Team member created.', 'data' => $this->userRow($user->load('role:id,name'))], 201);
    }

    public function usersUpdate(Request $request, int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $user = SellerUser::query()->where('seller_id', $seller->id)->find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.', 'error' => 'not_found'], 404);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:6', 'max:120'],
            'role_id' => ['required', 'integer'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $role = SellerRole::query()->where('seller_id', $seller->id)->find((int) $data['role_id']);
        if (! $role) {
            return response()->json(['message' => 'Role not found for this shop.', 'error' => 'validation'], 422);
        }
        $dup = SellerUser::query()
            ->where('seller_id', $seller->id)
            ->where('email', $data['email'])
            ->where('id', '!=', $user->id)
            ->exists();
        if ($dup) {
            return response()->json(['message' => 'Email already used on this shop.', 'error' => 'validation'], 422);
        }
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role_id' => $role->id,
            'is_enabled' => (bool) ($data['is_enabled'] ?? $user->is_enabled),
        ];
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }
        $user->fill($payload)->save();

        return response()->json(['message' => 'Team member updated.', 'data' => $this->userRow($user->fresh(['role:id,name']) ?? $user)]);
    }

    public function usersDestroy(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerTeamSchema::ensure();
        $user = SellerUser::query()->where('seller_id', $seller->id)->find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.', 'error' => 'not_found'], 404);
        }
        $user->delete();

        return response()->json(['message' => 'Team member deleted.', 'data' => ['id' => $id]]);
    }

    private function staffCapReached(Seller $seller): ?JsonResponse
    {
        if (! MarketplaceSettings::subscriptionPlansEnabled()) {
            return null;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $sub = MarketplaceSellerSubscription::query()
            ->with('plan')
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();
        $max = $sub?->plan?->max_staff;
        if ($max === null) {
            return null;
        }
        $count = SellerUser::query()->where('seller_id', $seller->id)->count();
        if ($count >= (int) $max) {
            return response()->json([
                'message' => 'You have reached max limit of '.(int) $max.' Users.',
                'error' => 'staff_limit',
            ], 422);
        }

        return null;
    }

    /**
     * @return Seller|JsonResponse
     */
    private function owner(): Seller|JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $seller = Seller::query()->where('customer_id', $customer->id)->first();
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Seller shop not found.', 'error' => 'not_found'], 404);
        }

        return $seller;
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRow(SellerRole $role): array
    {
        return [
            'id' => (int) $role->id,
            'name' => (string) $role->name,
            'description' => (string) ($role->description ?? ''),
            'permissionType' => (string) $role->permission_type,
            'permissions' => $role->permissions ?? [],
            'usersCount' => (int) $role->users()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(SellerUser $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone' => (string) ($user->phone ?? ''),
            'roleId' => (int) $user->role_id,
            'roleName' => (string) ($user->role?->name ?? ''),
            'isEnabled' => $user->is_enabled ? 1 : 0,
            'lastLoginAt' => optional($user->last_login_at)?->toIso8601String(),
        ];
    }
}
