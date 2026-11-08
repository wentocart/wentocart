<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\SupportTicket;
use App\Domain\Customer\SupportService;
use App\Domain\Customer\SupportSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function __construct(protected SupportService $support) {}

    public function index(Request $request): Response
    {
        $sort = (string) $request->query('sort', 'id');
        if (! in_array($sort, ['id', 'name', 'email', 'status', 'created_at'], true)) {
            $sort = 'id';
        }
        $filters = [
            'name' => trim((string) $request->query('name', '')),
            'email' => trim((string) $request->query('email', '')),
            'message' => trim((string) $request->query('message', '')),
            'status' => (string) $request->query('status', 'all'),
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $query = SupportTicket::query();
        if (in_array($filters['status'], [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_REPLIED, SupportTicket::STATUS_CLOSED], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['id_min'] !== '') {
            $query->where('id', '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where('id', '<=', (int) $filters['id_max']);
        }
        if ($filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if ($filters['email'] !== '') {
            $query->where('email', 'like', '%'.$filters['email'].'%');
        }
        if ($filters['message'] !== '') {
            $query->where('body', 'like', '%'.$filters['message'].'%');
        }

        $dir = $filters['dir'];
        $query->orderBy($filters['sort'], $dir)->orderBy('id', $dir);

        $counts = [
            'all' => SupportTicket::query()->count(),
            SupportTicket::STATUS_OPEN => SupportTicket::query()->where('status', SupportTicket::STATUS_OPEN)->count(),
            SupportTicket::STATUS_REPLIED => SupportTicket::query()->where('status', SupportTicket::STATUS_REPLIED)->count(),
            SupportTicket::STATUS_CLOSED => SupportTicket::query()->where('status', SupportTicket::STATUS_CLOSED)->count(),
        ];

        return Inertia::render('Customers/Support', [
            'filters' => $filters,
            'counts' => $counts,
            'settings' => SupportSettings::forAdmin(),
            'tickets' => $query->adminPaginate()->through(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'email' => $ticket->email,
                'order_number' => $ticket->order_number,
                'snippet' => $ticket->snippet(),
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $ticket->load(['replies.admin', 'customer']);

        return Inertia::render('Customers/SupportShow', [
            'ticket' => [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'email' => $ticket->email,
                'order_number' => $ticket->order_number,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toDateTimeString(),
                'customer_id' => $ticket->customer_id,
                'replies' => $ticket->replies->map(fn ($reply) => [
                    'id' => $reply->id,
                    'author' => $reply->author,
                    'staff_name' => $reply->admin?->name,
                    'body' => $reply->body,
                    'created_at' => $reply->created_at?->toDateTimeString(),
                ])->values()->all(),
            ],
            'settings' => SupportSettings::forAdmin(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $this->support->reply($ticket, trim($data['body']), $request->user('admin'));

        return back()->with('success', 'Reply sent.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:close,reopen,delete'],
        ]);
        if ($data['action'] === 'delete') {
            $ticket->replies()->delete();
            $ticket->delete();

            return redirect('/admin/support')->with('success', 'Message deleted.');
        }
        if ($data['action'] === 'close') {
            $this->support->close($ticket);

            return back()->with('success', 'Message closed.');
        }
        $this->support->reopen($ticket);

        return back()->with('success', 'Message reopened.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:close,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $tickets = SupportTicket::query()->whereIn('id', $data['ids'])->get();
        foreach ($tickets as $ticket) {
            if ($data['action'] === 'delete') {
                $ticket->replies()->delete();
                $ticket->delete();
            } else {
                $this->support->close($ticket);
            }
        }

        return back()->with('success', 'Updated selected messages.');
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notify_email' => ['nullable', 'boolean'],
            'reply_email' => ['nullable', 'boolean'],
            'notify_addresses' => ['nullable', 'string', 'max:2000'],
        ]);
        $raw = (string) ($data['notify_addresses'] ?? '');
        $parsed = SupportSettings::parseEmails($raw);
        if (trim($raw) !== '' && $parsed === []) {
            return back()->withErrors(['notify_addresses' => 'Enter valid email addresses.']);
        }
        SupportSettings::save($data);

        return back()->with('success', 'Support email settings saved.');
    }
}
