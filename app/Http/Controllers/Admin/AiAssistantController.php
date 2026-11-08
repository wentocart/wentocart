<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ai\AdminAssistant;
use App\Domain\Ai\AssistantSettings;
use App\Domain\Ai\EntityMutator;
use App\Domain\Ai\Models\AiAssistantChat;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Domain\Security\CsvSafe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiAssistantController extends Controller
{
    public function chats(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        $rows = AiAssistantChat::query()
            ->where('admin_id', $admin->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at', 'created_at'])
            ->map(fn (AiAssistantChat $chat) => [
                'id' => $chat->id,
                'title' => $chat->title,
                'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
                'created_at' => optional($chat->created_at)?->toDateTimeString(),
            ]);

        return response()->json(['chats' => $rows]);
    }

    public function storeChat(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
        ]);

        $hello = [
            'role' => 'ai',
            'kind' => 'text',
            'text' => 'Hello! How can I assist you today?',
        ];

        $chat = AiAssistantChat::query()->create([
            'admin_id' => $admin->id,
            'title' => trim($data['title']),
            'context_json' => [],
            'messages_json' => [$hello],
        ]);

        return response()->json([
            'chat' => $this->chatPayload($chat),
        ]);
    }

    public function showChat(Request $request, AiAssistantChat $chat): JsonResponse
    {
        $this->assertChatOwner($request, $chat);

        return response()->json(['chat' => $this->chatPayload($chat)]);
    }

    public function destroyChat(Request $request, AiAssistantChat $chat): JsonResponse
    {
        $this->assertChatOwner($request, $chat);
        $chat->delete();

        return response()->json(['ok' => true]);
    }

    public function ask(Request $request, AdminAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'chat_id' => ['required', 'integer', 'exists:ai_assistant_chats,id'],
            'context' => ['nullable', 'array'],
            'history' => ['nullable', 'array'],
            'history.*.role' => ['nullable', 'string', 'max:20'],
            'history.*.content' => ['nullable', 'string', 'max:4000'],
        ]);

        $chat = AiAssistantChat::query()->findOrFail($data['chat_id']);
        $this->assertChatOwner($request, $chat);

        $context = is_array($chat->context_json) ? $chat->context_json : [];
        if (is_array($data['context'] ?? null) && $data['context'] !== []) {
            $context = array_merge($context, $data['context']);
        }

        $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
        $history = $data['history'] ?? $this->historyFromMessages($messages);

        try {
            $result = $assistant->ask([
                'question' => $data['question'],
                'context' => $context,
                'history' => $history,
            ], $request->user('admin'));

            $messages[] = ['role' => 'user', 'kind' => 'text', 'text' => $data['question']];
            $messages[] = [
                'role' => 'ai',
                'kind' => $result['kind'] ?? 'text',
                'text' => $result['text'] ?? '',
                'chart' => $result['chart'] ?? null,
                'table' => $result['table'] ?? ($result['rows'] ?? null),
                'columns' => $result['columns'] ?? null,
                'plan' => $result['plan'] ?? null,
                'link' => $result['link'] ?? null,
            ];
            if (count($messages) > 80) {
                $messages = array_slice($messages, -80);
            }

            $chat->context_json = is_array($result['session'] ?? null) ? $result['session'] : $context;
            $chat->messages_json = $messages;
            $chat->save();

            return response()->json([
                ...$result,
                'chat_id' => $chat->id,
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function mutate(Request $request, AdminAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['create', 'update', 'delete', 'insert', 'add', 'edit', 'remove', 'destroy'])],
            'entity' => ['required', 'string', Rule::in(['customer', 'product', 'order'])],
            'id' => ['nullable', 'integer'],
            'fields' => ['nullable', 'array'],
            'chat_id' => ['nullable', 'integer', 'exists:ai_assistant_chats,id'],
        ]);

        $data['action'] = EntityMutator::normalizeAction($data['action']);
        $chatId = $data['chat_id'] ?? null;
        unset($data['chat_id']);

        // Writing through the assistant must satisfy the same domain
        // permission as changing the record on its own page.
        $required = [
            'customer' => ['customers.customers', 'customers.manage'],
            'product' => ['catalog.products', 'catalog.manage'],
            'order' => ['orders.manage'],
        ][$data['entity']] ?? [];
        $admin = $request->user('admin');
        $allowed = false;
        foreach ($required as $slug) {
            if ($admin && $admin->hasPermission($slug)) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 403, 'You do not have permission to change this.');

        try {
            $result = $assistant->mutate($data, $request->user('admin'));

            if ($chatId) {
                $chat = AiAssistantChat::query()->find($chatId);
                if ($chat) {
                    $this->assertChatOwner($request, $chat);
                    $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
                    $messages[] = [
                        'role' => 'ai',
                        'kind' => 'text',
                        'text' => $result['text'] ?? 'Done.',
                        'link' => $result['link'] ?? null,
                    ];
                    $chat->messages_json = $messages;
                    $chat->save();
                    $result['messages'] = $messages;
                    $result['chat_id'] = $chat->id;
                }
            }

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'assistant_enabled' => ['required', 'boolean'],
            'timeout' => ['required', 'integer', 'min:10', 'max:120'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'logging' => ['required', 'boolean'],
            'writes_enabled' => ['required', 'boolean'],
            'accessible_tabs' => ['present', 'array'],
            'accessible_tabs.*' => ['string', Rule::in(AssistantSettings::TABS)],
            'fallback_message' => ['required', 'string', 'max:500'],
            'icon_type' => ['required', 'string', Rule::in(['text', 'icon'])],
            'icon_text' => ['required', 'string', 'max:40'],
        ]);

        AssistantSettings::save($data);

        return back()->with('success', 'AI Assistant settings saved.');
    }

    public function logs(Request $request): Response
    {
        $query = AiAssistantChat::query()->with('admin:id,name,email');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', '%'.$q.'%')
                    ->orWhereHas('admin', function ($a) use ($q) {
                        $a->where('name', 'like', '%'.$q.'%')
                            ->orWhere('email', 'like', '%'.$q.'%');
                    });
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
        if ($admin = trim((string) $request->query('admin', ''))) {
            $query->whereHas('admin', function ($a) use ($admin) {
                $a->where('name', 'like', '%'.$admin.'%')
                    ->orWhere('email', 'like', '%'.$admin.'%');
            });
        }
        if ($date = trim((string) $request->query('date', ''))) {
            $query->whereDate('updated_at', $date);
        }

        $sort = (string) $request->query('sort', 'updated_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'title', 'admin_id', 'created_at', 'updated_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'updated_at';
        }
        $query->orderBy($sort, $dir);

        $pageSize = min(100, max(10, (int) $request->query('page_size', 20)));
        $paginated = $query->paginate($pageSize)->withQueryString();

        $chats = $paginated->through(function (AiAssistantChat $chat) {
            $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
            $user = collect($messages)->firstWhere('role', 'user');

            return [
                'id' => $chat->id,
                'title' => $chat->title,
                'admin_id' => $chat->admin_id,
                'admin_name' => $chat->admin?->name ?: ('Admin #'.$chat->admin_id),
                'admin_email' => $chat->admin?->email ?: '',
                'message_count' => count($messages),
                'preview' => mb_substr((string) ($user['text'] ?? $chat->title), 0, 120),
                'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
                'created_at' => optional($chat->created_at)?->toDateTimeString(),
            ];
        });

        return Inertia::render('Settings/AiLogs', [
            'chats' => $chats,
            'filters' => $request->only(['q', 'id_min', 'id_max', 'title', 'admin', 'date', 'sort', 'dir', 'page_size']),
            'count' => $chats->total(),
        ]);
    }

    public function bulkDestroyLogs(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:ai_assistant_chats,id'],
        ]);

        AiAssistantChat::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', count($data['ids']).' chat session(s) deleted.');
    }

    public function exportLogs(Request $request): StreamedResponse
    {
        $query = AiAssistantChat::query()->with('admin:id,name,email');
        if ($ids = $request->query('ids')) {
            $idList = array_filter(array_map('intval', explode(',', (string) $ids)));
            if ($idList !== []) {
                $query->whereIn('id', $idList);
            }
        }
        $chats = $query->orderByDesc('id')->get();

        return response()->streamDownload(function () use ($chats) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, ['ID', 'Admin Name', 'Admin Email', 'Title', 'Messages Count', 'Updated At', 'Created At']);
            foreach ($chats as $chat) {
                $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
                CsvSafe::put($out, [
                    $chat->id,
                    $chat->admin?->name ?: '',
                    $chat->admin?->email ?: '',
                    $chat->title,
                    count($messages),
                    optional($chat->updated_at)?->toDateTimeString() ?: '',
                    optional($chat->created_at)?->toDateTimeString() ?: '',
                ]);
            }
            fclose($out);
        }, 'admin-chats-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function showLogChat(AiAssistantChat $chat): Response
    {
        $admin = Admin::query()->find($chat->admin_id);

        return Inertia::render('Settings/AiLogChatShow', [
            'chat' => $this->chatPayload($chat) + [
                'admin_name' => $admin?->name ?: ('Admin #'.$chat->admin_id),
                'admin_email' => $admin?->email ?: '',
                'created_at' => optional($chat->created_at)?->toDateTimeString(),
            ],
        ]);
    }

    public function destroyLogChat(AiAssistantChat $chat): RedirectResponse
    {
        $chat->delete();

        return redirect('/admin/ai/logs')->with('success', 'Chat session deleted.');
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', 'max:120'],
            'rows' => ['required', 'array'],
            'rows.*' => ['array'],
        ]);

        $columns = $data['columns'];
        $rows = $data['rows'];

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, $columns);
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $col) {
                    $val = $row[$col] ?? '';
                    $line[] = is_scalar($val) ? $val : json_encode($val);
                }
                CsvSafe::put($out, $line);
            }
            fclose($out);
        }, 'ai-assistant-export-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function assertChatOwner(Request $request, AiAssistantChat $chat): void
    {
        if ((int) $chat->admin_id !== (int) $request->user('admin')?->id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatPayload(AiAssistantChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'context' => is_array($chat->context_json) ? $chat->context_json : [],
            'messages' => is_array($chat->messages_json) ? $chat->messages_json : [],
            'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{role: string, content: string}>
     */
    protected function historyFromMessages(array $messages): array
    {
        $out = [];
        foreach (array_slice($messages, -16) as $msg) {
            if (! is_array($msg) || empty($msg['text'])) {
                continue;
            }
            $out[] = [
                'role' => (($msg['role'] ?? '') === 'ai') ? 'assistant' : 'user',
                'content' => (string) $msg['text'],
            ];
        }

        return $out;
    }
}
