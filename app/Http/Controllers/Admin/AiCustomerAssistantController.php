<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ai\Models\AiCustomerChat;
use App\Domain\Ai\StorefrontAssistant;
use App\Domain\Ai\StorefrontAssistantSettings;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Security\CsvSafe;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiCustomerAssistantController extends Controller
{
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'timeout' => ['required', 'integer', 'min:10', 'max:120'],
            'fallback_message' => ['nullable', 'string', 'max:500'],
            'off_topic_message' => ['nullable', 'string', 'max:500'],
            'icon_type' => ['required', 'in:text,icon'],
            'icon_text' => ['nullable', 'string', 'max:40'],
            'terms_cms_page_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['terms_cms_page_id'])) {
            if (! CmsPage::query()->whereKey((int) $data['terms_cms_page_id'])->exists()) {
                return back()->withErrors(['terms_cms_page_id' => 'Selected CMS page was not found.']);
            }
        } else {
            $data['terms_cms_page_id'] = null;
        }

        StorefrontAssistantSettings::save($data);

        return back()->with('success', 'Customer AI assistant settings saved.');
    }

    public function index(Request $request): Response
    {
        $query = AiCustomerChat::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%')
                    ->orWhere('title', 'like', '%'.$q.'%');
            });
        }

        if ($request->filled('id_min')) {
            $query->where('id', '>=', (int) $request->query('id_min'));
        }
        if ($request->filled('id_max')) {
            $query->where('id', '<=', (int) $request->query('id_max'));
        }
        if ($title = trim((string) $request->query('title', ''))) {
            $query->where('title', 'like', '%'.$title.'%');
        }
        if ($customer = trim((string) $request->query('customer', ''))) {
            $query->where(function ($inner) use ($customer) {
                $inner->where('name', 'like', '%'.$customer.'%')
                    ->orWhere('email', 'like', '%'.$customer.'%');
            });
        }
        if ($date = trim((string) $request->query('date', ''))) {
            $query->whereDate('updated_at', $date);
        }

        $sort = (string) $request->query('sort', 'updated_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'title', 'name', 'email', 'updated_at', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'updated_at';
        }
        $query->orderBy($sort, $dir);

        $pageSize = min(100, max(10, (int) $request->query('page_size', 20)));
        $paginated = $query->paginate($pageSize)->withQueryString();

        $chats = $paginated->through(fn (AiCustomerChat $chat) => [
            'id' => $chat->id,
            'title' => $chat->title,
            'name' => $chat->name,
            'email' => $chat->email,
            'customer_id' => $chat->customer_id,
            'message_count' => is_array($chat->messages_json) ? count($chat->messages_json) : 0,
            'accepted_terms' => (bool) $chat->accepted_terms,
            'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
            'created_at' => optional($chat->created_at)?->toDateTimeString(),
            'preview' => $this->preview($chat),
        ]);

        return Inertia::render('Settings/AiCustomerChats', [
            'chats' => $chats,
            'filters' => $request->only(['q', 'id_min', 'id_max', 'title', 'customer', 'date', 'sort', 'dir', 'page_size']),
            'count' => $chats->total(),
        ]);
    }

    public function bulkDestroyCustomerChats(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:ai_customer_chats,id'],
        ]);

        AiCustomerChat::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', count($data['ids']).' customer chat(s) deleted.');
    }

    public function exportCustomerChats(Request $request): StreamedResponse
    {
        $query = AiCustomerChat::query();
        if ($ids = $request->query('ids')) {
            $idList = array_filter(array_map('intval', explode(',', (string) $ids)));
            if ($idList !== []) {
                $query->whereIn('id', $idList);
            }
        }
        $chats = $query->orderByDesc('id')->get();

        return response()->streamDownload(function () use ($chats) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, ['ID', 'Name', 'Email', 'Customer ID', 'Title', 'Messages Count', 'Updated At', 'Created At']);
            foreach ($chats as $chat) {
                $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
                CsvSafe::put($out, [
                    $chat->id,
                    $chat->name ?: '',
                    $chat->email ?: '',
                    $chat->customer_id ?: '',
                    $chat->title,
                    count($messages),
                    optional($chat->updated_at)?->toDateTimeString() ?: '',
                    optional($chat->created_at)?->toDateTimeString() ?: '',
                ]);
            }
            fclose($out);
        }, 'customer-chats-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function show(AiCustomerChat $chat): Response
    {
        return Inertia::render('Settings/AiCustomerChatShow', [
            'chat' => StorefrontAssistant::chatPayload($chat) + [
                'accepted_terms' => (bool) $chat->accepted_terms,
                'terms_accepted_at' => optional($chat->terms_accepted_at)?->toDateTimeString(),
            ],
        ]);
    }

    public function destroy(AiCustomerChat $chat): RedirectResponse
    {
        $chat->delete();

        return redirect('/admin/ai/customer-chats')->with('success', 'Customer chat deleted.');
    }

    /**
     * Published CMS pages for the terms picker.
     *
     * @return list<array{id: int, title: string, slug: string}>
     */
    public static function cmsPageOptions(): array
    {
        return CmsPage::query()
            ->with('translations')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (CmsPage $page) {
                $tr = $page->translation();

                return [
                    'id' => $page->id,
                    'title' => $tr?->title ?: ('Page #'.$page->id),
                    'slug' => $tr?->slug ?: '',
                    'is_published' => $page->status === CmsPage::STATUS_PUBLISHED && $page->is_enabled,
                ];
            })
            ->values()
            ->all();
    }

    protected function preview(AiCustomerChat $chat): string
    {
        $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
        $user = collect($messages)->firstWhere('role', 'user');

        return mb_substr((string) ($user['text'] ?? $chat->title), 0, 120);
    }
}
