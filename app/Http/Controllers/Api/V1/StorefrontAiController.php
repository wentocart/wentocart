<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Models\AiCustomerChat;
use App\Domain\Ai\StorefrontAssistant;
use App\Domain\Ai\StorefrontAssistantSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class StorefrontAiController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $cfg = StorefrontAssistantSettings::forStorefront();
        $customer = $request->user('customer');
        $contact = StorefrontAssistant::contactFromCustomer($customer);

        return response()->json([
            'assistant' => $cfg,
            'contact' => $contact,
            'logged_in' => $customer !== null,
        ]);
    }

    public function terms(): JsonResponse
    {
        if (! StorefrontAssistantSettings::forStorefront()['enabled']) {
            return response()->json(['message' => 'Shop AI is disabled.'], 404);
        }

        return response()->json([
            'terms' => StorefrontAssistantSettings::termsPageContent(),
        ]);
    }

    public function chats(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $token = $this->visitorToken($request);
        $customer = $request->user('customer');

        $q = AiCustomerChat::query()->orderByDesc('updated_at')->limit(50);
        if ($customer) {
            $q->where(function ($inner) use ($customer, $token) {
                $inner->where('customer_id', $customer->id)
                    ->orWhere('visitor_token', $token);
            });
        } else {
            $q->where('visitor_token', $token);
        }

        $rows = $q->get(['id', 'title', 'name', 'email', 'updated_at', 'created_at'])
            ->map(fn (AiCustomerChat $chat) => [
                'id' => $chat->id,
                'title' => $chat->title,
                'name' => $chat->name,
                'email' => $chat->email,
                'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
                'created_at' => optional($chat->created_at)?->toDateTimeString(),
            ]);

        return response()->json(['chats' => $rows, 'visitor_token' => $token]);
    }

    public function storeChat(Request $request, StorefrontAssistant $assistant): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'message' => ['required', 'string', 'max:2000'],
            'accepted_terms' => ['accepted'],
            'visitor_token' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
        ]);

        $token = $this->visitorToken($request, $data['visitor_token'] ?? null);
        $customer = $request->user('customer');
        $title = trim((string) $data['title']);
        if ($title === '') {
            $title = mb_substr(trim($data['message']), 0, 80) ?: 'Chat';
        }

        $hello = StorefrontAssistant::helloMessage();
        $userMsg = [
            'role' => 'user',
            'kind' => 'text',
            'text' => trim($data['message']),
        ];

        $chat = AiCustomerChat::query()->create([
            'customer_id' => $customer?->id,
            'visitor_token' => $token,
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'title' => $title,
            'accepted_terms' => true,
            'terms_accepted_at' => now(),
            'messages_json' => [$hello, $userMsg],
            'context_json' => [],
        ]);

        $aiMsg = $this->runAsk($assistant, $chat, trim($data['message']), $customer?->id);
        $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
        $messages[] = $aiMsg;
        $chat->messages_json = $messages;
        $chat->save();

        return response()->json([
            'chat' => StorefrontAssistant::chatPayload($chat->fresh()),
            'visitor_token' => $token,
        ]);
    }

    public function showChat(Request $request, AiCustomerChat $chat): JsonResponse
    {
        $this->assertEnabled();
        $this->assertChatAccess($request, $chat);

        return response()->json([
            'chat' => StorefrontAssistant::chatPayload($chat),
            'visitor_token' => $chat->visitor_token,
        ]);
    }

    public function ask(Request $request, StorefrontAssistant $assistant): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate([
            'chat_id' => ['required', 'integer', 'exists:ai_customer_chats,id'],
            'question' => ['required', 'string', 'max:2000'],
            'visitor_token' => ['nullable', 'string', 'max:64'],
        ]);

        $chat = AiCustomerChat::query()->findOrFail($data['chat_id']);
        $this->assertChatAccess($request, $chat, $data['visitor_token'] ?? null);

        $question = trim($data['question']);
        $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
        $messages[] = [
            'role' => 'user',
            'kind' => 'text',
            'text' => $question,
        ];

        $aiMsg = $this->runAsk($assistant, $chat, $question, $request->user('customer')?->id ?? $chat->customer_id);
        $messages[] = $aiMsg;
        $chat->messages_json = $messages;
        $chat->touch();
        $chat->save();

        return response()->json([
            'result' => $aiMsg,
            'chat' => StorefrontAssistant::chatPayload($chat->fresh()),
        ]);
    }

    public function destroyChat(Request $request, AiCustomerChat $chat): JsonResponse
    {
        $this->assertEnabled();
        $this->assertChatAccess($request, $chat);
        $chat->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function runAsk(StorefrontAssistant $assistant, AiCustomerChat $chat, string $question, ?int $customerId): array
    {
        $messages = is_array($chat->messages_json) ? $chat->messages_json : [];
        $history = [];
        foreach (array_slice($messages, -16) as $msg) {
            if (! is_array($msg) || empty($msg['text'])) {
                continue;
            }
            $history[] = [
                'role' => (($msg['role'] ?? '') === 'ai') ? 'assistant' : 'user',
                'content' => (string) $msg['text'],
            ];
        }

        try {
            $result = $assistant->ask([
                'question' => $question,
                'history' => $history,
                'customer_id' => $customerId,
            ]);
        } catch (Throwable $e) {
            report($e);
            $result = [
                'kind' => 'text',
                'text' => StorefrontAssistantSettings::fallbackMessage(),
            ];
        }

        return [
            'role' => 'ai',
            'kind' => $result['kind'] ?? 'text',
            'text' => (string) ($result['text'] ?? ''),
            'columns' => $result['columns'] ?? null,
            'table' => $result['table'] ?? null,
        ];
    }

    protected function assertEnabled(): void
    {
        if (! AiSettings::enabled() || ! StorefrontAssistantSettings::enabled() || AiSettings::activeConnection() === null) {
            abort(403, 'Shop AI is not enabled.');
        }
    }

    protected function visitorToken(Request $request, ?string $incoming = null): string
    {
        $token = trim((string) ($incoming ?: $request->header('X-Ai-Visitor') ?: $request->input('visitor_token')));
        if ($token !== '' && preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $token)) {
            return $token;
        }

        return (string) Str::lower(Str::ulid());
    }

    protected function assertChatAccess(Request $request, AiCustomerChat $chat, ?string $visitorToken = null): void
    {
        $customer = $request->user('customer');
        if ($customer && (int) $chat->customer_id === (int) $customer->id) {
            return;
        }

        $token = $this->visitorToken($request, $visitorToken);
        if (hash_equals((string) $chat->visitor_token, $token)) {
            return;
        }

        abort(403, 'This chat is not available.');
    }
}
