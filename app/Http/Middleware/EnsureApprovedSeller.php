<?php

namespace App\Http\Middleware;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerStaffToken;
use App\Domain\Marketplace\Models\SellerUser;
use App\Domain\Marketplace\SellerStaffTokenSchema;
use App\Domain\Marketplace\Services\SellerSubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedSeller
{
    public function handle(Request $request, Closure $next, string $permission = ''): Response
    {
        $customer = $request->user('customer');
        $seller = $customer?->seller()->first();
        $gate = app(SellerSubscriptionGate::class);

        // Portal operations require an ACTIVE (approved) seller. Pending, rejected,
        // and suspended (blocked) sellers keep normal customer account access.
        if ($seller instanceof Seller && $seller->canAccessPortal()) {
            if ($permission !== '') {
                $gate->assertPlanAllows($seller, $permission);
            }
            $request->attributes->set('seller', $seller);
            $request->attributes->set('marketplace.actor', 'owner');

            return $next($request);
        }
        SellerStaffTokenSchema::ensure();
        $header = (string) $request->header('Authorization', '');
        $plain = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        if ($plain !== '') {
            $token = SellerStaffToken::query()
                ->with(['user.role', 'user.seller'])
                ->where('token_hash', hash('sha256', $plain))
                ->first();
            $user = $token?->user;
            $staffSeller = $user?->seller;
            if ($token && $token->isValid()
                && $user instanceof SellerUser
                && $user->is_enabled
                && $staffSeller instanceof Seller
                && $staffSeller->canAccessPortal()) {
                if ($permission !== '' && $user->role && ! $user->role->allows($permission)) {
                    abort(403, 'Forbidden.');
                }
                if ($permission !== '') {
                    $gate->assertPlanAllows($staffSeller, $permission);
                }
                $token->forceFill(['last_used_at' => now()])->save();
                $request->attributes->set('seller', $staffSeller);
                $request->attributes->set('marketplace.actor', 'staff');
                $request->attributes->set('marketplace.staff', $user);

                return $next($request);
            }
        }

        abort(403, 'Seller access is not available.');
    }
}
