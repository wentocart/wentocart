<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\GdprCookieConsent;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Services\GdprService;
use App\Domain\Settings\GdprSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GdprController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! GdprSettings::enabled()) {
            abort(404);
        }
        GdprSchema::ensure();
        $customer = $request->user('customer');
        $rows = GdprRequest::query()->where('customer_id', $customer->id)->latest('id')->get();
        $cookies = GdprCookieConsent::query()->where('customer_id', $customer->id)->first();

        return response()->json([
            'requests' => $rows->map(fn (GdprRequest $row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'type' => $row->type,
                'type_label' => $row->type === 'delete' ? 'Delete' : 'Update',
                'status' => $row->status,
                'message' => $row->message,
                'summary' => $row->changeSummary(),
                'changes' => $row->changeRows(),
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'can_revoke' => $row->status === 'pending',
            ])->values(),
            'cookies' => $cookies?->choices(),
        ]);
    }

    public function store(Request $request, GdprService $gdpr): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(GdprSettings::TYPES)],
            'message' => ['required', 'string', 'max:500'],
        ]);
        $row = $gdpr->create(
            $request->user('customer'),
            $data['type'],
            trim((string) ($data['message'] ?? '')),
        );

        return response()->json(['id' => $row->id, 'reference' => $row->reference, 'status' => $row->status], 201);
    }

    public function revoke(Request $request, GdprRequest $gdprRequest, GdprService $gdpr): JsonResponse
    {
        $gdpr->revoke($request->user('customer'), $gdprRequest);

        return response()->json(['ok' => true, 'status' => 'deleted']);
    }

    public function html(Request $request, GdprService $gdpr): Response
    {
        $html = $gdpr->exportHtml($request->user('customer'));

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function pdf(Request $request, GdprService $gdpr): Response
    {
        $pdf = $gdpr->exportPdf($request->user('customer'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="gdpr-data.pdf"',
        ]);
    }

    public function saveCookies(Request $request, GdprService $gdpr): JsonResponse
    {
        $data = $request->validate([
            'basic' => ['required', 'boolean'],
            'experience' => ['required', 'boolean'],
            'measurements' => ['required', 'boolean'],
            'targeting' => ['required', 'boolean'],
        ]);
        $saved = $gdpr->saveCookies($request->user('customer'), $data);

        return response()->json(['cookies' => $saved->choices()]);
    }
}
