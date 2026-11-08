<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shop newsletter / POST /api/v1/store/newsletters.
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'email' => ['nullable', 'email', 'max:190'],
                'customerEmail' => ['nullable', 'email', 'max:190'],
            ]);
        } catch (ValidationException $e) {
            $flat = collect($e->errors())->flatten()->implode(' ');

            return response()->json([
                'message' => $flat !== '' ? $flat : 'Validation failed.',
                'success' => false,
                'ok' => false,
            ], 400);
        }

        $email = strtolower(trim((string) ($data['customerEmail'] ?? $data['email'] ?? '')));
        if ($email === '') {
            return response()->json([
                'message' => 'The customer email field is required.',
                'success' => false,
                'ok' => false,
            ], 400);
        }

        $existing = NewsletterSubscriber::query()->where('email', $email)->first();
        if ($existing) {
            return response()->json([
                'message' => 'The customer email has already been taken.',
                'success' => false,
                'ok' => false,
            ], 400);
        }

        NewsletterSubscriber::query()->create([
            'email' => $email,
            'ip' => mb_substr((string) $request->ip(), 0, 45),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $customer = Auth::guard('customer')->user();
        if ($customer instanceof Customer) {
            $customer->newsletter = true;
            $customer->save();
        }

        return response()->json([
            'ok' => true,
            'success' => true,
            'message' => 'You are subscribed.',
        ]);
    }

    /**
     * DELETE /shop/newsletters or POST /shop/unsubscribe-newsletters
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) (
            $request->input('customerEmail')
            ?? $request->input('email')
            ?? ''
        )));
        if ($email === '') {
            $customer = Auth::guard('customer')->user();
            $email = $customer instanceof Customer ? strtolower(trim((string) $customer->email)) : '';
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'A valid email is required.',
                'success' => false,
                'ok' => false,
            ], 400);
        }

        $row = NewsletterSubscriber::query()->where('email', $email)->first();
        if (! $row) {
            return response()->json([
                'ok' => true,
                'success' => true,
                'message' => 'Email is not subscribed.',
            ]);
        }
        if ($row->is_subscribed === false) {
            return response()->json([
                'ok' => true,
                'success' => true,
                'message' => 'Already unsubscribed.',
            ]);
        }
        $row->forceFill(['is_subscribed' => false])->save();

        $customer = Auth::guard('customer')->user();
        if ($customer instanceof Customer && strtolower((string) $customer->email) === $email) {
            $customer->newsletter = false;
            $customer->save();
        }

        return response()->json([
            'ok' => true,
            'success' => true,
            'message' => 'You are unsubscribed.',
        ]);
    }
}
