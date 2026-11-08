<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\SupportService;
use App\Domain\Settings\CommunityFeatures;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shop contact / POST /api/v1/store/contact-us.
 */
class ContactController extends Controller
{
    public function store(Request $request, SupportService $support): JsonResponse
    {
        if (! CommunityFeatures::contactForm()) {
            abort(404);
        }

        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'message' => ['required', 'string', 'max:20000'],
                'order_number' => ['nullable', 'string', 'max:64'],
                'contact' => ['nullable', 'string', 'max:50'],
                'phone' => ['nullable', 'string', 'max:50'],
            ]);
        } catch (ValidationException $e) {
            $flat = collect($e->errors())->flatten()->implode(' ');

            return response()->json([
                'message' => $flat !== '' ? $flat : 'Validation failed.',
                'success' => false,
                'ok' => false,
            ], 400);
        }

        $phone = trim((string) ($data['contact'] ?? $data['phone'] ?? ''));
        $message = (string) $data['message'];
        if ($phone !== '') {
            $message = "Phone: {$phone}\n\n".$message;
        }

        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            $customer = null;
        }

        try {
            $support->openFromContact(
                $data['name'],
                $data['email'],
                $message,
                $data['order_number'] ?? null,
                $customer,
            );
            $ok = true;
            $msg = 'Message sent.';
        } catch (\Throwable) {
            $ok = false;
            $msg = 'Unable to send your inquiry at this time. Please try again later';
        }

        return response()->json([
            'ok' => $ok,
            'success' => $ok,
            'message' => $msg,
        ], 201);
    }
}
