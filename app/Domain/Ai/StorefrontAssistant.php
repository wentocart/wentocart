<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Models\AiCustomerChat;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Customer-facing shopping assistant (store topics only).
 */
class StorefrontAssistant
{
    /**
     * @param  array{question: string, history?: list<array{role: string, content: string}>, customer_id?: int|null}  $input
     * @return array{kind: string, text: string, table?: list<array<string, mixed>>, columns?: list<string>}
     */
    public function ask(array $input): array
    {
        if (! AiSettings::enabled() || ! StorefrontAssistantSettings::enabled()) {
            throw new RuntimeException('Shop AI is not enabled.');
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            throw new InvalidArgumentException('Enter a question.');
        }

        $provider = $this->provider();
        $timeout = StorefrontAssistantSettings::timeout();
        $history = is_array($input['history'] ?? null) ? array_slice($input['history'], -8) : [];
        $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : null;

        if (! $this->isOnTopic($provider, $question, $history, $timeout)) {
            return [
                'kind' => 'text',
                'text' => StorefrontAssistantSettings::offTopicMessage(),
            ];
        }

        // Expand short follow-ups ("list them", "show me") using prior chat.
        $resolved = $this->resolveFollowUp($provider, $question, $history, $timeout);

        try {
            $sqlList = $this->generateSelects($provider, $resolved, $history, $customerId, $timeout);
            $rows = [];
            foreach ($sqlList as $sql) {
                if ($this->touchesPrivateCustomerData($sql)) {
                    if ($customerId) {
                        $rows = array_merge($rows, $this->customerScopedOrderRows($customerId));
                    }
                    continue;
                }
                try {
                    $chunk = DB::select($sql);
                    $rows = array_merge($rows, array_map(fn ($r) => (array) $r, $chunk));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            if ($rows === []) {
                $rows = $this->productSearchFallback($resolved.' '.$question);
            }

            if ($rows !== []) {
                $rows = array_slice($rows, 0, 20);
                $keys = array_keys($rows[0]);
                $summary = $this->summarize($provider, $resolved, $rows, $timeout);

                return [
                    'kind' => 'table',
                    'text' => $summary,
                    'columns' => $keys,
                    'table' => $rows,
                ];
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Last chance: keyword catalog search before a vague knowledge answer.
        $fallbackRows = $this->productSearchFallback($resolved.' '.$question);
        if ($fallbackRows !== []) {
            $keys = array_keys($fallbackRows[0]);
            $summary = $this->summarize($provider, $resolved, $fallbackRows, $timeout);

            return [
                'kind' => 'table',
                'text' => $summary,
                'columns' => $keys,
                'table' => $fallbackRows,
            ];
        }

        // Knowledge answer without SQL rows (policies, how shipping works, etc.)
        try {
            $text = $this->complete(
                $provider,
                $this->shopKnowledgePrompt($resolved, $history, $customerId),
                'You are a helpful store shopping assistant. Plain text only. Stay on shop topics. Use recent chat for follow-ups.',
                $timeout
            );
            if ($text !== '') {
                return ['kind' => 'text', 'text' => $text];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'kind' => 'text',
            'text' => StorefrontAssistantSettings::fallbackMessage(),
        ];
    }

    protected function provider(): AiProvider
    {
        $active = AiSettings::activeConnection();
        if ($active === null) {
            throw new RuntimeException('No active AI connection.');
        }

        return AiProviderFactory::make($active);
    }

    protected function complete(AiProvider $provider, string $prompt, string $system, int $timeout): string
    {
        return trim($provider->complete($prompt, [
            'system' => $system,
            'max_tokens' => 1200,
            'temperature' => 0.3,
        ]));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function isOnTopic(AiProvider $provider, string $question, array $history, int $timeout): bool
    {
        // Fast path for obvious shop keywords.
        if ($this->looksLikeShopTopic($question)) {
            return true;
        }

        // Short follow-ups inherit topic from recent shop chat ("list them", "show me", "yes").
        if ($this->isLikelyFollowUp($question) && $this->historyHasShopTopic($history)) {
            return true;
        }

        $hist = $this->formatHistory($history);
        $raw = strtolower(trim($this->complete(
            $provider,
            "Is this shopper message about THIS online shop (products, cart, orders, shipping, payment, store policies),\n"
            ."OR a follow-up to a recent shop conversation (e.g. list them / show more / which ones)?\n"
            ."Reply ONLY yes or no.\n"
            ."Recent chat:\n{$hist}\n"
            ."Current message: {$question}",
            'Reply with yes or no only.',
            $timeout
        )));

        return str_starts_with($raw, 'yes');
    }

    protected function looksLikeShopTopic(string $text): bool
    {
        return (bool) preg_match(
            '/\b(product|products|order|orders|cart|checkout|shipping|delivery|payment|pay|refund|return|stock|sku|price|brand|category|coupon|discount|track|wishlist|account|store|shop|shirt|shirts|item|items|catalog|buy|purchase)\b/i',
            $text
        );
    }

    protected function isLikelyFollowUp(string $question): bool
    {
        $q = trim($question);
        if ($q === '') {
            return false;
        }

        // Short / pronoun / list-request follow-ups.
        if (mb_strlen($q) <= 80 && preg_match(
            '/\b(list|show|those|them|that|these|it|more|details|which|any|all|here|above|same|yes|no|ok)\b/i',
            $q
        )) {
            return true;
        }

        return mb_strlen($q) <= 40 && $this->historyWouldClarify($q);
    }

    protected function historyWouldClarify(string $question): bool
    {
        // Very short messages almost always need prior context.
        return str_word_count($question) <= 6;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function historyHasShopTopic(array $history): bool
    {
        foreach (array_reverse($history) as $row) {
            $content = (string) ($row['content'] ?? '');
            if ($content !== '' && $this->looksLikeShopTopic($content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn "list down here" into an explicit shop question using recent history.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function resolveFollowUp(AiProvider $provider, string $question, array $history, int $timeout): string
    {
        if (! $this->isLikelyFollowUp($question) || $history === []) {
            return $question;
        }

        $hist = $this->formatHistory($history);
        try {
            $resolved = trim($this->complete(
                $provider,
                "Rewrite the shopper's follow-up as ONE clear standalone question about this shop,\n"
                ."using recent chat for context. Keep it about products/orders/shipping/payment if that was the topic.\n"
                ."Return ONLY the rewritten question, no quotes.\n"
                ."Recent chat:\n{$hist}\n"
                ."Follow-up: {$question}",
                'Output one rewritten question only.',
                $timeout
            ));

            return $resolved !== '' ? $resolved : $question;
        } catch (Throwable) {
            return $question;
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function formatHistory(array $history): string
    {
        $hist = '';
        foreach ($history as $row) {
            $hist .= ($row['role'] ?? 'user').': '.($row['content'] ?? '')."\n";
        }

        return $hist !== '' ? $hist : '(none)';
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<string>
     */
    protected function generateSelects(
        AiProvider $provider,
        string $question,
        array $history,
        ?int $customerId,
        int $timeout
    ): array {
        $schema = StorefrontSchemaCatalog::promptText();
        $cust = $customerId
            ? "Logged-in customer_id = {$customerId}. Order queries MUST include WHERE customer_id = {$customerId}."
            : 'Guest visitor — do NOT query orders table.';

        $hist = $this->formatHistory($history);

        $prompt = <<<TXT
You are a MySQL SELECT compiler for a storefront shopping assistant.
STRICT:
- Output ONLY raw SQL SELECT. No markdown.
- Never INSERT/UPDATE/DELETE.
- Use only tables/columns from this schema.
- NEVER use SELECT * — list needed columns only.
- Prefer language_code = 'en' for translations.
- Only enabled catalog products (is_enabled = 1) when listing products.
- {$cust}
- Follow-ups like "list them" / "show me" use recent chat to decide what to query (e.g. shirts → search product name/description).
- Search products with LIKE '%term%' on product_translations.name / short_description when the shopper asks for a type of item.
- If the question cannot be answered with SQL, reply exactly: NOT_ALLOWED

COLUMN CHOICE:
- Products → id, name, sku, price, stock_status (join product_translations)
- Shipping → name, code, type, price
- Payment → name, code
- Orders (logged-in only) → number, status, grand_total, created_at

{$schema}
Recent chat:
{$hist}
User question: {$question}
TXT;

        $raw = $this->complete(
            $provider,
            $prompt,
            'Output only MySQL SELECT statements or NOT_ALLOWED.',
            $timeout
        );

        if (strcasecmp(trim($raw), 'NOT_ALLOWED') === 0) {
            return [];
        }

        return SqlGuard::sanitizeSelects($raw, 20, StorefrontSchemaCatalog::tableNames());
    }

    protected function touchesPrivateCustomerData(string $sql): bool
    {
        return (bool) preg_match('/\b(?:FROM|JOIN)\s+`?(?:\w+_)?(?:orders|order_items|customers|customer_addresses|wishlists)\b/i', $sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function customerScopedOrderRows(int $customerId): array
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'number', 'status', 'grand_total', 'currency_code', 'created_at', 'payment_status'])
            ->map(fn (Order $order) => $order->toArray())
            ->values()
            ->all();
    }

    /**
     * Deterministic catalog search when the model SQL misses (e.g. "shirt").
     *
     * @return list<array<string, mixed>>
     */
    protected function productSearchFallback(string $text): array
    {
        $terms = $this->extractSearchTerms($text);
        if ($terms === []) {
            return [];
        }

        $prefix = '';
        try {
            $prefix = (string) DB::connection()->getTablePrefix();
        } catch (Throwable) {
            $prefix = '';
        }
        $products = $prefix.'products';
        $translations = $prefix.'product_translations';

        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            try {
                $rows = DB::select(
                    "SELECT p.id, pt.name, p.sku, p.price, p.stock_status
                     FROM {$products} p
                     INNER JOIN {$translations} pt ON pt.product_id = p.id AND pt.language_code = ?
                     WHERE p.is_enabled = 1
                       AND (pt.name LIKE ? OR COALESCE(pt.short_description, '') LIKE ? OR p.sku LIKE ?)
                     ORDER BY pt.name ASC
                     LIMIT 20",
                    ['en', $like, $like, $like]
                );
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($rows !== []) {
                return array_map(static fn ($row) => (array) $row, $rows);
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected function extractSearchTerms(string $text): array
    {
        $text = strtolower($text);
        if (! preg_match_all('/\b([a-z0-9][a-z0-9\-]{2,})\b/i', $text, $matches)) {
            return [];
        }

        $stop = [
            'have', 'has', 'product', 'products', 'like', 'what', 'your', 'this', 'that', 'with', 'from',
            'does', 'did', 'you', 'the', 'and', 'for', 'are', 'any', 'can', 'show', 'list', 'down', 'here',
            'them', 'those', 'these', 'please', 'about', 'shop', 'store', 'available', 'want', 'need',
            'find', 'looking', 'there', 'some', 'item', 'items', 'kind', 'type', 'types', 'which', 'where',
            'how', 'many', 'much', 'help', 'hello', 'thanks', 'thank', 'just', 'also', 'more', 'only',
            'could', 'would', 'should', 'tell', 'give', 'get', 'got', 'our', 'out', 'all', 'one',
        ];

        $terms = [];
        foreach ($matches[1] as $word) {
            $w = strtolower($word);
            if (in_array($w, $stop, true)) {
                continue;
            }
            $terms[] = $w;
        }

        $terms = array_values(array_unique($terms));

        // Prefer the most specific trailing terms ("shirt", "headphones").
        return array_slice($terms, -4);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function shopKnowledgePrompt(string $question, array $history, ?int $customerId): string
    {
        $hist = '';
        foreach ($history as $row) {
            $hist .= ($row['role'] ?? 'user').': '.($row['content'] ?? '')."\n";
        }
        $who = $customerId ? "Customer #{$customerId} is logged in." : 'Guest visitor.';

        return <<<TXT
Answer briefly for a shopper on this ecommerce store.
{$who}
Only discuss products, cart, orders, shipping, payment, returns, and store help.
Treat short follow-ups as continuing the recent chat topic.
If you do not have real catalog data in this conversation, say you could not find matching products — do not invent product names.
If unrelated, say you can only help with this shop.
Do not invent order details you were not given.
Recent chat:
{$hist}
Question: {$question}
TXT;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function summarize(AiProvider $provider, string $question, array $rows, int $timeout): string
    {
        try {
            $text = $this->complete(
                $provider,
                "Answer the shopper in 1-2 short friendly sentences using only this data.\n"
                ."Question: {$question}\nData JSON:\n".json_encode(array_slice($rows, 0, 15)),
                'Plain text only.',
                $timeout
            );

            return $text !== '' ? $text : 'Here is what I found in the shop.';
        } catch (Throwable) {
            return 'Here is what I found in the shop.';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function chatPayload(AiCustomerChat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'name' => $chat->name,
            'email' => $chat->email,
            'customer_id' => $chat->customer_id,
            'messages' => is_array($chat->messages_json) ? $chat->messages_json : [],
            'updated_at' => optional($chat->updated_at)?->toDateTimeString(),
            'created_at' => optional($chat->created_at)?->toDateTimeString(),
        ];
    }

    public static function helloMessage(): array
    {
        return [
            'role' => 'ai',
            'kind' => 'text',
            'text' => 'Hello! How can I help you with our shop today?',
        ];
    }

    /**
     * Prefill contact fields for a logged-in customer.
     *
     * @return array{name: string, email: string}
     */
    public static function contactFromCustomer(?Customer $customer): array
    {
        if (! $customer) {
            return ['name' => '', 'email' => ''];
        }

        $name = trim((string) ($customer->name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))));

        return [
            'name' => $name,
            'email' => (string) ($customer->email ?? ''),
        ];
    }
}
