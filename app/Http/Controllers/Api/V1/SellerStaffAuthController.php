<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerStaffToken;
use App\Domain\Marketplace\Models\SellerUser;
use App\Domain\Marketplace\SellerStaffTokenSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 */
class SellerStaffAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        SellerStaffTokenSchema::ensure();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:120'],
        ]);
        $user = SellerUser::query()
            ->with(['seller:id,shop_name,shop_slug,status', 'role'])
            ->where('email', $data['email'])
            ->first();
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            return response()->json(['message' => 'Invalid credentials.', 'error' => 'auth'], 401);
        }
        if (! $user->is_enabled) {
            return response()->json(['message' => 'Account is suspended.', 'error' => 'suspended'], 403);
        }
        if (! $user->seller || $user->seller->status !== 'approved') {
            return response()->json(['message' => 'Seller shop is not available.', 'error' => 'forbidden'], 403);
        }

        $plain = Str::random(48);
        SellerStaffToken::query()->create([
            'seller_user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'name' => 'staff-session',
            'expires_at' => now()->addDays(7),
        ]);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $plain,
            'tokenType' => 'Bearer',
            'expiresAt' => now()->addDays(7)->toIso8601String(),
            'user' => $this->presentUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json(['data' => $this->presentUser($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        SellerStaffTokenSchema::ensure();
        $plain = $this->bearer($request);
        if ($plain !== '') {
            SellerStaffToken::query()->where('token_hash', hash('sha256', $plain))->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @return SellerUser|JsonResponse
     */
    private function resolveUser(Request $request): SellerUser|JsonResponse
    {
        SellerStaffTokenSchema::ensure();
        $plain = $this->bearer($request);
        if ($plain === '') {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $row = SellerStaffToken::query()
            ->with(['user.seller:id,shop_name,shop_slug,status', 'user.role'])
            ->where('token_hash', hash('sha256', $plain))
            ->first();
        if (! $row || ! $row->isValid() || ! $row->user || ! $row->user->is_enabled) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $row->forceFill(['last_used_at' => now()])->save();

        return $row->user;
    }

    private function bearer(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return trim((string) $request->input('token', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUser(SellerUser $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'sellerId' => (int) $user->seller_id,
            'shopName' => (string) ($user->seller?->shop_name ?? ''),
            'shopSlug' => (string) ($user->seller?->shop_slug ?? ''),
            'roleId' => (int) $user->role_id,
            'roleName' => (string) ($user->role?->name ?? ''),
            'permissionType' => (string) ($user->role?->permission_type ?? 'custom'),
            'permissions' => $user->role?->permission_type === 'all'
                ? ['*']
                : ($user->role?->permissions ?? []),
            'isOwner' => false,
        ];
    }
}
