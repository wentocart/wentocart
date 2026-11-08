<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Marketing\Support\NewsletterCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterSubscriberController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->string('q')->toString());
        $email = trim($request->string('email')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $date = trim($request->string('date')->toString());
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'email', 'created_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $subscribers = NewsletterSubscriber::query()
            ->when($q !== '', fn ($query) => $query->where('email', 'like', "%{$q}%"))
            ->when($email !== '', fn ($query) => $query->where('email', 'like', "%{$email}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($date !== '', fn ($query) => $query->whereDate('created_at', $date))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderByDesc('id'))
            ->adminPaginate()
            ->through(fn (NewsletterSubscriber $row) => [
                'id' => $row->id,
                'email' => $row->email,
                'created_at' => optional($row->created_at)->toIso8601String(),
                'actions' => ['delete' => true],
            ]);

        return Inertia::render('Marketing/Newsletter/Index', [
            'subscribers' => $subscribers,
            'filters' => [
                'q' => $q,
                'email' => $email,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'date' => $date,
                'sort' => $sort,
                'dir' => $dir,
            ],
            'count' => NewsletterSubscriber::query()->count(),
            'campaigns' => \App\Domain\Marketing\Models\NewsletterCampaign::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'subject', 'status', 'sent_count', 'sent_at']),
        ]);
    }

    public function export(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        return $this->csvDownload('newsletter-subscribers.csv', NewsletterCsvSchema::exportCsv($ids));
    }

    public function importSample()
    {
        return $this->csvDownload('newsletter-subscribers-sample.csv', NewsletterCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, NewsletterCsvSchema::import(...), 'subscribers');
    }

    public function destroy(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->delete();

        return back()->with('success', 'Subscriber removed.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        NewsletterSubscriber::query()->whereIn('id', $ids)->delete();

        return back()->with('success', 'Subscribers removed.');
    }

    public function sendCampaign(\Illuminate\Http\Request $request, \App\Domain\Settings\ShopMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'html_body' => ['required', 'string', 'max:50000'],
        ]);
        $campaign = \App\Domain\Marketing\Models\NewsletterCampaign::query()->create([
            'subject' => $data['subject'],
            'html_body' => $data['html_body'],
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $sent = 0;
        \App\Domain\Marketing\Models\NewsletterSubscriber::query()
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($mailer, $data, &$sent) {
                foreach ($rows as $row) {
                    if ($mailer->sendHtml((string) $row->email, $data['subject'], $data['html_body'], 'newsletter')) {
                        $sent++;
                    }
                }
            });
        $campaign->update(['sent_count' => $sent]);

        return back()->with('success', 'Campaign sent to '.$sent.' subscriber'.($sent === 1 ? '' : 's').'.');
    }
}
