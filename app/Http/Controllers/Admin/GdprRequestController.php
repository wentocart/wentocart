<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Services\GdprService;
use App\Domain\Settings\GdprSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GdprRequestController extends Controller
{
    public function index(Request $request): Response
    {
        GdprSchema::ensure();
        $status = (string) $request->query('status', 'all');
        $type = (string) $request->query('type', '');
        $q = trim((string) $request->query('q', ''));

        $query = GdprRequest::query()->with('customer:id,name,email')->latest('id');
        if (in_array($status, GdprSettings::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($type, GdprSettings::TYPES, true)) {
            $query->where('type', $type);
        }
        if ($request->filled('id')) {
            $query->where('id', (int) $request->query('id'));
        }
        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%'.trim((string) $request->query('reference')).'%');
        }
        if ($request->filled('customer')) {
            $needle = '%'.trim((string) $request->query('customer')).'%';
            $query->whereHas('customer', function ($customers) use ($needle) {
                $customers->where('name', 'like', $needle)->orWhere('email', 'like', $needle);
            });
        }
        if ($request->filled('message')) {
            $query->where('message', 'like', '%'.trim((string) $request->query('message')).'%');
        }
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('message', 'like', '%'.$q.'%')
                    ->orWhere('status', 'like', '%'.$q.'%')
                    ->orWhere('type', 'like', '%'.$q.'%')
                    ->orWhere('reference', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
                $inner->orWhereHas('customer', function ($customers) use ($q) {
                    $customers->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%');
                });
            });
        }
        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('created_from'));
        }
        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('created_to'));
        }

        return Inertia::render('Customers/GdprRequests', [
            'requests' => $query->paginate(20)->withQueryString()->through(fn (GdprRequest $row) => $this->present($row)),
            'filters' => [
                'q' => $q,
                'status' => $status,
                'type' => $type,
                'id' => (string) $request->query('id', ''),
                'reference' => (string) $request->query('reference', ''),
                'customer' => (string) $request->query('customer', ''),
                'message' => (string) $request->query('message', ''),
                'created_from' => (string) $request->query('created_from', ''),
                'created_to' => (string) $request->query('created_to', ''),
            ],
        ]);
    }

    public function edit(GdprRequest $gdprRequest): Response
    {
        $gdprRequest->load('customer:id,name,email');

        return Inertia::render('Customers/GdprRequestEdit', [
            'request' => $this->present($gdprRequest),
        ]);
    }

    public function update(Request $request, GdprRequest $gdprRequest, GdprService $gdpr): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(GdprSettings::STATUSES)],
        ]);
        $gdpr->updateStatus($gdprRequest, $data['status']);

        return redirect()->route('admin.gdpr-requests.index')->with('success', 'GDPR request updated.');
    }

    public function destroy(GdprRequest $gdprRequest): RedirectResponse
    {
        $gdprRequest->delete();

        return redirect()->route('admin.gdpr-requests.index')->with('success', 'GDPR request deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        GdprSchema::ensure();
        $rows = GdprRequest::query()->with('customer:id,name,email')->latest('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Customer Name', 'Status', 'Type', 'Requested change', 'Message', 'Created At']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->reference,
                    $row->customer?->name,
                    ucfirst((string) $row->status),
                    $row->typeLabel(),
                    $row->changeSummary(),
                    $row->message,
                    optional($row->created_at)?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'gdpr-data-requests.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GdprRequest $row): array
    {
        return [
            'id' => $row->id,
            'reference' => $row->reference,
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer?->name,
            'customer_email' => $row->customer?->email,
            'type' => $row->type,
            'type_label' => $row->typeLabel(),
            'status' => $row->status,
            'message' => $row->message,
            'summary' => $row->changeSummary(),
            'changes' => $row->changeRows(),
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
