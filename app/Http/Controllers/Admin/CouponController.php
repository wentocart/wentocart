<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Promo\Models\Coupon;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Coupons', ['coupons' => Coupon::query()->latest()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_cart' => ['nullable', 'numeric'],
            'max_uses' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
        ]);
        Coupon::query()->create($data + ['is_enabled' => true]);

        return back()->with('success', 'Coupon created.');
    }
}
