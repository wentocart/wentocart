<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Models\AiAssistantLog;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AdminAssistant
{
    /**
     * @param  array{question: string, context?: array<string, mixed>, history?: list<array{role: string, content: string}>}  $input
     * @return array<string, mixed>
     */
    public function ask(array $input, ?Admin $admin = null): array
    {
        if (! AiSettings::enabled() || ! AssistantSettings::enabled()) {
            throw new RuntimeException('AI Admin Assistant is not enabled. Configure it under Configure → AI.');
        }

        $provider = $this->provider();
        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            throw new InvalidArgumentException('Enter a question.');
        }

        $history = is_array($input['history'] ?? null) ? array_slice($input['history'], -8) : [];
        $session = is_array($input['context'] ?? null) ? $input['context'] : [];
        $timeout = AssistantSettings::timeout();
        $limit = AssistantSettings::resultLimit();
        $fallback = AssistantSettings::fallbackMessage();

        $chartRequest = $this->isChartRequest($question);
        $lane = $chartRequest ? 'db' : $this->classifyLane($provider, $question, $timeout);
        $topic = $this->classifyTopic($provider, $question, $timeout);

        // Follow-up like "show in pie chart" — reuse last result rows when present.
        if ($chartRequest && $this->sessionRows($session) !== []) {
            $rows = $this->sessionRows($session);
            $viz = $this->visualize($provider, $question, $rows, $limit, $timeout);
            $session['last_rows'] = array_slice($rows, 0, $limit);

            return $this->finish($admin, $topic, 'db', $question, $viz, $session);
        }

        if ($lane === 'general') {
            $text = $this->complete(
                $provider,
                "Answer briefly and helpfully. You are an ecommerce admin assistant. If unrelated to the shop, still answer politely.\nUser: {$question}",
                'You help shop admins. Return plain text only.',
                $timeout
            );

            return $this->finish($admin, $topic, $lane, $question, [
                'kind' => 'text',
                'text' => $text,
            ], $session);
        }

        if ($lane === 'admin' && ! $chartRequest) {
            $text = $this->complete(
                $provider,
                $this->adminHelpPrompt($question),
                'You explain how to use this Laravel commerce admin (WentoCart). Plain text, short steps, no SQL.',
                $timeout
            );

            return $this->finish($admin, $topic, $lane, $question, [
                'kind' => 'text',
                'text' => $text,
            ], $session);
        }

        // db lane
        $action = $chartRequest ? 'select' : $this->classifyAction($provider, $question, $timeout);

        if (in_array($action, ['insert', 'update', 'delete'], true)) {
            if (! AssistantSettings::writesEnabled()) {
                return $this->finish($admin, $topic, $lane, $question, [
                    'kind' => 'text',
                    'text' => 'Write actions are disabled. Enable “Allow create / update / delete” under Configure → AI.',
                ], $session);
            }

            $plan = $this->planMutation($provider, $question, $action, $session, $timeout);

            return $this->finish($admin, $topic, $lane, $question, [
                'kind' => 'confirm_mutation',
                'plan' => $plan,
                'text' => $this->mutationPreview($plan),
            ], $session);
        }

        // select
        try {
            try {
                $sqlList = $this->generateSelects($provider, $question, $session, $history, $limit, $timeout);
            } catch (Throwable $e) {
                report($e);
                $sqlList = $this->heuristicSelects($question, $limit);
                if ($sqlList === []) {
                    throw $e;
                }
            }

            $allRows = [];
            $executed = [];
            foreach ($sqlList as $sql) {
                try {
                    $executed[] = $sql;
                    $rows = DB::select($sql);
                    $rows = array_map(fn ($row) => (array) $row, $rows);
                    $allRows = array_merge($allRows, $rows);
                } catch (Throwable $e) {
                    report($e);
                    // Fall through to heuristic retry below.
                }
            }

            if ($allRows === []) {
                $retry = $this->heuristicSelects($question, $limit);
                if ($retry !== []) {
                    $executed = [];
                    $allRows = [];
                    foreach ($retry as $sql) {
                        $executed[] = $sql;
                        $rows = DB::select($sql);
                        $rows = array_map(fn ($row) => (array) $row, $rows);
                        $allRows = array_merge($allRows, $rows);
                    }
                }
            }

            if ($allRows === []) {
                return $this->finish($admin, $topic, $lane, $question, [
                    'kind' => 'text',
                    'text' => 'No matching rows found for that question.',
                    'sql' => $executed,
                ], $session);
            }

            $session = $this->enrichSession($session, $allRows);
            $allRows = $this->slimResultRows($question, $allRows);
            $session['last_rows'] = array_slice($allRows, 0, $limit);
            $viz = $this->visualize($provider, $question, $allRows, $limit, $timeout);

            return $this->finish($admin, $topic, $lane, $question, [
                ...$viz,
                'sql' => $executed,
                'rows' => array_slice($allRows, 0, $limit),
            ], $session);
        } catch (Throwable $e) {
            report($e);

            return $this->finish($admin, $topic, $lane, $question, [
                'kind' => 'text',
                'text' => $fallback,
            ], $session);
        }
    }

    /**
     * @param  array{action: string, entity: string, id?: int|null, fields?: array<string, mixed>}  $plan
     * @return array<string, mixed>
     */
    public function mutate(array $plan, ?Admin $admin = null): array
    {
        $result = EntityMutator::apply($plan);
        if (AssistantSettings::logging() && $admin) {
            AiAssistantLog::query()->create([
                'topic' => (string) ($plan['entity'] ?? 'mutation'),
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'context_type' => 'mutation',
                'chat_json' => [
                    ['from' => 'user', 'message' => 'Confirm '.$plan['action'].' '.$plan['entity'], 'time' => now()->toDateTimeString()],
                    ['from' => 'ai', 'message' => $result['message'], 'time' => now()->toDateTimeString()],
                ],
            ]);
        }

        return [
            'kind' => 'text',
            'text' => $result['message'],
            'link' => $result['link'] ?? null,
            'operation_id' => $result['operation_id'] ?? null,
            'session' => [],
        ];
    }

    protected function provider(): AiProvider
    {
        $active = AiSettings::activeConnection();
        if ($active === null) {
            throw new RuntimeException('No active AI connection. Add one under Configure → AI.');
        }

        return AiProviderFactory::make($active);
    }

    protected function complete(AiProvider $provider, string $prompt, string $system, int $timeout): string
    {
        return trim($provider->complete($prompt, [
            'system' => $system,
            'max_tokens' => 2000,
            'temperature' => 0.3,
            // providers use AiHttp timeout; keep prompt aware
        ]));
    }

    protected function classifyLane(AiProvider $provider, string $question, int $timeout): string
    {
        if ($this->isChartRequest($question)) {
            return 'db';
        }

        $raw = strtolower(trim($this->complete(
            $provider,
            "Classify into exactly one word: db, admin, or general.\n"
            ."db = shop data, reports, products, orders, customers, stock, revenue, charts, graphs, pie, bar, counts\n"
            ."admin = how to use admin settings / configure the store software\n"
            ."general = unrelated chitchat\n"
            ."create/update/delete of customers/products/orders = db\n"
            ."Short follow-ups like 'show as pie' or 'in a chart' about prior shop data = db\n"
            ."Question: {$question}",
            'Reply with exactly one word: db, admin, or general.',
            $timeout
        )));

        foreach (['db', 'admin', 'general'] as $lane) {
            if (str_contains($raw, $lane)) {
                return $lane;
            }
        }

        return 'db';
    }

    protected function isChartRequest(string $question): bool
    {
        return (bool) preg_match(
            '/\b(chart|charts|graph|graphs|pie|piechart|pie-chart|donut|doughnut|bar|bars|line|trend|timeline|visuali[sz]e|percentage|square|histogram)\b/i',
            $question
        );
    }

    /**
     * @param  array<string, mixed>  $session
     * @return list<array<string, mixed>>
     */
    protected function sessionRows(array $session): array
    {
        $rows = $session['last_rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    protected function classifyTopic(AiProvider $provider, string $question, int $timeout): string
    {
        $raw = strtolower(trim($this->complete(
            $provider,
            "Classify into one of: product, order, customer, general. Reply one word.\nQuestion: {$question}",
            'One word only.',
            $timeout
        )));

        foreach (['product', 'order', 'customer'] as $t) {
            if (str_contains($raw, $t)) {
                return $t;
            }
        }

        return 'general';
    }

    protected function classifyAction(AiProvider $provider, string $question, int $timeout): string
    {
        $raw = strtolower(trim($this->complete(
            $provider,
            "Classify into exactly one word: select, insert, update, delete, none.\nQuestion: {$question}",
            'One word only.',
            $timeout
        )));

        foreach (['insert', 'update', 'delete', 'select', 'none'] as $a) {
            if (str_contains($raw, $a)) {
                return $a === 'none' ? 'select' : $a;
            }
        }

        return 'select';
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  list<array{role: string, content: string}>  $history
     * @return list<string>
     */
    protected function generateSelects(
        AiProvider $provider,
        string $question,
        array $session,
        array $history,
        int $limit,
        int $timeout
    ): array {
        $schema = SchemaCatalog::promptText();
        $ctx = $session !== [] ? 'Session context JSON: '.json_encode($session)."\n" : '';
        $hist = '';
        foreach ($history as $row) {
            $hist .= ($row['role'] ?? 'user').': '.($row['content'] ?? '')."\n";
        }

        $prompt = <<<TXT
You are a MySQL SELECT compiler for WentoCart.
STRICT:
- Output ONLY raw SQL SELECT (one or more SELECT...;). No markdown.
- Never INSERT/UPDATE/DELETE/DROP/ALTER.
- Use only tables/columns from this schema.
- NEVER use SELECT * — always list only the columns the admin needs for the question.
- Prefer language_code = 'en' for translations when needed.
- Limit results sensibly (app will also enforce LIMIT {$limit}).
- For charts/trends use date grouping when useful.
- If modification was requested, reply exactly: NOT_ALLOWED

COLUMN CHOICE (important):
- Stock / quantity questions about products → select products.id, product_translations.name, products.sku, products.stock_qty, products.price, products.stock_status (join product_translations).
- Product list / catalog questions → id, name, sku, price, stock_qty, is_enabled (with translation name).
- Order lists → id, number, email, status, grand_total, created_at (or currency_code).
- Customer lists → id, name, email, phone, is_enabled, created_at.
- Do not return shipping dimensions, barcodes, flags, or internal mode columns unless the user asked for them.

{$schema}
{$ctx}
Recent chat:
{$hist}
User question: {$question}
TXT;

        $raw = $this->complete(
            $provider,
            $prompt,
            'Output only MySQL SELECT statements. No explanation.',
            $timeout
        );

        return SqlGuard::sanitizeSelects($raw, $limit);
    }

    /**
     * Safe templates when the model emits bad SQL (SELECT *, wrong columns, etc.).
     *
     * @return list<string>
     */
    protected function heuristicSelects(string $question, int $limit): array
    {
        $q = strtolower($question);
        $sql = null;

        if (preg_match('/\bproducts?\b.*\b(?:quantity|qty|stock)\b.*\b(?:less than|under|below|<|>)\s*(\d+)/i', $question, $m)
            || preg_match('/\b(?:quantity|qty|stock)\b.*\b(?:less than|under|below|<|>)\s*(\d+).*\bproducts?\b/i', $question, $m)
            || preg_match('/\bproducts?\b.*(?:quantity|qty|stock).*?(?:less than|under|below|<)\s*(\d+)/i', $question, $m)) {
            $n = (int) $m[1];
            $sql = "SELECT p.id, pt.name, p.sku, p.stock_qty, p.price, p.stock_status
FROM products p
LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language_code = 'en'
WHERE p.stock_qty < {$n}
ORDER BY p.stock_qty ASC";
        } elseif (preg_match('/\bproducts?\b.*\b(?:quantity|qty|stock)\b.*\b(?:greater than|over|above|>)\s*(\d+)/i', $question, $m)
            || preg_match('/\b(?:quantity|qty|stock)\b.*\b(?:greater than|over|above|>)\s*(\d+).*\bproducts?\b/i', $question, $m)) {
            $n = (int) $m[1];
            $sql = "SELECT p.id, pt.name, p.sku, p.stock_qty, p.price, p.stock_status
FROM products p
LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language_code = 'en'
WHERE p.stock_qty > {$n}
ORDER BY p.stock_qty DESC";
        } elseif (preg_match('/\bhow many products\b|\bproduct count\b|\bnumber of products\b/i', $question)) {
            $sql = 'SELECT COUNT(*) AS product_count FROM products';
        } elseif (preg_match('/\b(low stock|out of stock)\b/i', $question)) {
            $sql = "SELECT p.id, pt.name, p.sku, p.stock_qty, p.price, p.stock_status
FROM products p
LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language_code = 'en'
WHERE p.stock_qty <= p.low_stock_threshold OR p.stock_status = 'out_of_stock'
ORDER BY p.stock_qty ASC";
        }

        if ($sql === null) {
            return [];
        }

        try {
            return SqlGuard::sanitizeSelects($sql, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function visualize(AiProvider $provider, string $question, array $rows, int $limit, int $timeout): array
    {
        $sample = array_slice($rows, 0, $limit);
        if ($sample === []) {
            return [
                'kind' => 'text',
                'text' => AssistantSettings::fallbackMessage(),
            ];
        }

        $keys = array_keys($sample[0] ?? []);
        $numeric = [];
        $labels = [];
        foreach ($keys as $key) {
            $vals = array_column($sample, $key);
            $allNum = $vals !== [] && collect($vals)->every(fn ($v) => is_numeric($v));
            if ($allNum) {
                $numeric[] = $key;
            } else {
                $labels[] = $key;
            }
        }

        $wantsChart = $this->isChartRequest($question);
        $type = $this->chartTypeFromQuestion($question);

        // Multi-row / multi-metric charts
        if ($wantsChart && count($numeric) >= 1 && (count($sample) > 1 || $labels !== [] || count($numeric) >= 2)) {
            $labelKey = $labels[0] ?? null;
            $valueKey = $numeric[0];
            if ($labelKey === null && count($numeric) >= 2) {
                $labelKey = $numeric[0];
                $valueKey = $numeric[1];
            }
            if ($labelKey === null) {
                $labelKey = $keys[0];
            }

            return [
                'kind' => 'chart',
                'text' => 'Here is a '.$type.' chart for your question.',
                'chart' => [
                    'type' => $type,
                    'labels' => array_map(fn ($r) => (string) ($r[$labelKey] ?? ''), $sample),
                    'values' => array_map(fn ($r) => (float) ($r[$valueKey] ?? 0), $sample),
                    'label' => $valueKey,
                ],
                'columns' => $keys,
                'table' => $sample,
            ];
        }

        // Single metric (e.g. product_count: 20) still chartable as one slice/bar
        if ($wantsChart && count($numeric) >= 1) {
            $valueKey = $numeric[0];
            $label = $labels[0] ?? $valueKey;
            $value = (float) ($sample[0][$valueKey] ?? 0);

            return [
                'kind' => 'chart',
                'text' => 'Here is a '.$type.' chart for your question.',
                'chart' => [
                    'type' => $type,
                    'labels' => [(string) ($sample[0][$label] ?? $label)],
                    'values' => [$value],
                    'label' => $valueKey,
                ],
                'columns' => $keys,
                'table' => $sample,
            ];
        }

        if (count($sample) === 1 && count($keys) <= 6) {
            $bits = [];
            foreach ($sample[0] as $k => $v) {
                $bits[] = $k.': '.(is_scalar($v) ? (string) $v : json_encode($v));
            }

            return [
                'kind' => 'text',
                'text' => implode("\n", $bits),
                'columns' => $keys,
                'table' => $sample,
            ];
        }

        // Ask model for a one-line summary; table always included for CSV.
        $summary = '';
        try {
            $summary = $this->complete(
                $provider,
                "Answer the admin's question in one short factual sentence using only this data. "
                ."Do not invent trends, marketing copy, or fields that are not in the data.\n"
                ."Question: {$question}\nData JSON:\n".json_encode($sample),
                'Plain text only. One or two short sentences max.',
                $timeout
            );
        } catch (Throwable) {
            $summary = 'Results ('.count($sample).' rows).';
        }

        return [
            'kind' => 'table',
            'text' => $summary,
            'columns' => $keys,
            'table' => $sample,
        ];
    }

    /**
     * Drop noisy columns when the model returns a wide product/order dump.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function slimResultRows(string $question, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $keys = array_keys($rows[0]);
        if (count($keys) <= 8) {
            return $rows;
        }

        $q = strtolower($question);
        $prefer = [];
        if (preg_match('/\b(product|stock|quantity|qty|sku|catalog|inventory)\b/', $q)) {
            $prefer = ['id', 'name', 'sku', 'stock_qty', 'price', 'sale_price', 'stock_status', 'is_enabled'];
        } elseif (preg_match('/\b(order|revenue|sales)\b/', $q)) {
            $prefer = ['id', 'number', 'email', 'status', 'grand_total', 'currency_code', 'created_at', 'payment_status'];
        } elseif (preg_match('/\b(customer|buyer)\b/', $q)) {
            $prefer = ['id', 'name', 'email', 'phone', 'is_enabled', 'created_at'];
        } else {
            // Keep first identifier-ish columns + a few numeric/text fields.
            $prefer = array_slice($keys, 0, 8);
        }

        $keep = [];
        foreach ($prefer as $col) {
            foreach ($keys as $key) {
                if (strcasecmp((string) $key, $col) === 0) {
                    $keep[] = $key;
                    break;
                }
            }
        }
        // Always keep obvious name/sku/qty if present under alternate casing.
        foreach ($keys as $key) {
            $lk = strtolower((string) $key);
            if (in_array($lk, ['name', 'sku', 'stock_qty', 'quantity', 'qty', 'price', 'email', 'status'], true) && ! in_array($key, $keep, true)) {
                $keep[] = $key;
            }
        }

        if ($keep === []) {
            return $rows;
        }

        return array_map(static function (array $row) use ($keep) {
            $out = [];
            foreach ($keep as $col) {
                if (array_key_exists($col, $row)) {
                    $out[$col] = $row[$col];
                }
            }

            return $out;
        }, $rows);
    }

    protected function chartTypeFromQuestion(string $question): string
    {
        if (preg_match('/\bpie\b|\bpiechart\b|\bpie-chart\b|\bpercentage\b/i', $question)) {
            return 'pie';
        }
        if (preg_match('/\bdonut|doughnut\b/i', $question)) {
            return 'doughnut';
        }
        if (preg_match('/\bline|trend|timeline\b/i', $question)) {
            return 'line';
        }
        if (preg_match('/\bsquare\b|\bbar\b|\bhistogram\b/i', $question)) {
            return 'bar';
        }

        return 'bar';
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{action: string, entity: string, id?: int|null, fields: array<string, mixed>}
     */
    protected function planMutation(AiProvider $provider, string $question, string $action, array $session, int $timeout): array
    {
        $prompt = <<<TXT
Convert the admin request into ONE JSON object only (no markdown):
{"action":"{$action}","entity":"customer|product|order","id":null_or_number,"fields":{...}}

Rules:
- entity customer: fields may include name, first_name, last_name, email, phone, is_enabled, newsletter
- entity product: fields may include name, sku, price, stock_qty, short_description, description, is_enabled
- entity order: action must be update; fields must include status (pending|processing|shipped|delivered|completed|cancelled|refunded|on_hold)
- Use session context if id missing: {$this->json($session)}
- For create, id should be null.
Question: {$question}
TXT;

        $raw = $this->complete($provider, $prompt, 'Return only valid JSON.', $timeout);
        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw) ?? $raw);
        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['entity']) || empty($data['action'])) {
            throw new InvalidArgumentException('Could not understand the create/update/delete request. Please rephrase with clear fields.');
        }

        $action = EntityMutator::normalizeAction((string) ($data['action'] ?: $action));
        $entity = strtolower((string) $data['entity']);
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        // Prefer explicit name from question when model only returned email.
        if ($entity === 'customer' && empty($fields['name']) && empty($fields['first_name'])) {
            if (preg_match('/\b(?:customer|named?)\s+([A-Za-z][A-Za-z\-]+(?:\s+[A-Za-z][A-Za-z\-]+)?)/i', $question, $m)) {
                $fields['name'] = trim($m[1]);
            }
        }

        return [
            'action' => $action,
            'entity' => $entity,
            'id' => isset($data['id']) ? (int) $data['id'] : null,
            'fields' => $fields,
        ];
    }

    /**
     * @param  array{action: string, entity: string, id?: int|null, fields?: array<string, mixed>}  $plan
     */
    protected function mutationPreview(array $plan): string
    {
        $action = EntityMutator::normalizeAction((string) ($plan['action'] ?? ''));
        $entity = strtolower((string) ($plan['entity'] ?? ''));
        $id = $plan['id'] ?? null;
        $fields = is_array($plan['fields'] ?? null) ? $plan['fields'] : [];

        $entityLabel = match ($entity) {
            'customer' => 'customer',
            'product' => 'product',
            'order' => 'order',
            default => $entity,
        };

        $details = match ($entity) {
            'customer' => $this->customerPreviewDetails($fields),
            'product' => $this->productPreviewDetails($fields),
            'order' => $this->orderPreviewDetails($id, $fields),
            default => '',
        };

        $verb = match ($action) {
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            default => ucfirst($action),
        };

        $who = $id ? " {$entityLabel} #{$id}" : " a new {$entityLabel}";
        $line = "{$verb}{$who}";
        if ($details !== '') {
            $line .= ": {$details}";
        }

        return $line."\n\nClick Confirm to apply this change, or Cancel to discard it.";
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function customerPreviewDetails(array $fields): string
    {
        $bits = [];
        $name = trim((string) ($fields['name'] ?? trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? ''))));
        if ($name !== '') {
            $bits[] = $name;
        }
        if (! empty($fields['email'])) {
            $bits[] = (string) $fields['email'];
        }
        if (! empty($fields['phone'])) {
            $bits[] = 'phone '.(string) $fields['phone'];
        }

        return implode(' · ', $bits);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function productPreviewDetails(array $fields): string
    {
        $bits = [];
        if (! empty($fields['name'])) {
            $bits[] = (string) $fields['name'];
        }
        if (! empty($fields['sku'])) {
            $bits[] = 'SKU '.(string) $fields['sku'];
        }
        if (array_key_exists('price', $fields) && $fields['price'] !== '' && $fields['price'] !== null) {
            $bits[] = 'price '.$fields['price'];
        }
        if (array_key_exists('stock_qty', $fields) && $fields['stock_qty'] !== '' && $fields['stock_qty'] !== null) {
            $bits[] = 'stock '.$fields['stock_qty'];
        }

        return implode(' · ', $bits);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function orderPreviewDetails(?int $id, array $fields): string
    {
        $status = trim((string) ($fields['status'] ?? ''));

        return $status !== '' ? 'set status to '.$status : '';
    }

    protected function adminHelpPrompt(string $question): string
    {
        return <<<TXT
You help merchants use WentoCart admin (Laravel + Inertia React).
Common areas:
- Catalog → Products / Categories / Brands
- Orders → Orders / Order Journey
- Customers → Customers / Groups / Addresses
- Marketing → Discounts
- Design → Pages / Themes / Extensions
- Configure → General Configuration / Store / Products, URLs, SEO, AI, Email, Documents
Answer with short numbered steps. Question: {$question}
TXT;
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function enrichSession(array $session, array $rows): array
    {
        $row = $rows[0] ?? [];
        foreach (['product_id', 'id_product'] as $k) {
            if (! empty($row[$k])) {
                $session['product']['id'] = (int) $row[$k];
            }
        }
        foreach (['name', 'product_name', 'Name'] as $k) {
            if (! empty($row[$k]) && is_scalar($row[$k])) {
                $session['product']['name'] = (string) $row[$k];
            }
        }
        foreach (['order_id', 'id_order'] as $k) {
            if (! empty($row[$k])) {
                $session['order']['id'] = (int) $row[$k];
            }
        }
        foreach (['number', 'reference'] as $k) {
            if (! empty($row[$k]) && is_scalar($row[$k])) {
                $session['order']['number'] = (string) $row[$k];
            }
        }
        foreach (['customer_id', 'id_customer'] as $k) {
            if (! empty($row[$k])) {
                $session['customer']['id'] = (int) $row[$k];
            }
        }
        foreach (['email', 'customer_email'] as $k) {
            if (! empty($row[$k]) && is_scalar($row[$k])) {
                $session['customer']['email'] = (string) $row[$k];
            }
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    protected function finish(?Admin $admin, string $topic, string $lane, string $question, array $payload, array $session): array
    {
        if (AssistantSettings::logging() && $admin) {
            AiAssistantLog::query()->create([
                'topic' => $topic,
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'admin_email' => $admin->email,
                'context_type' => $lane,
                'chat_json' => [
                    [
                        'from' => 'user',
                        'sender' => $admin->name,
                        'message' => $question,
                        'time' => now()->toDateTimeString(),
                    ],
                    [
                        'from' => 'ai',
                        'message' => (string) ($payload['text'] ?? $payload['kind'] ?? ''),
                        'kind' => $payload['kind'] ?? 'text',
                        'time' => now()->toDateTimeString(),
                    ],
                ],
            ]);
        }

        return [
            ...$payload,
            'topic' => $topic,
            'lane' => $lane,
            'session' => $session,
        ];
    }

    protected function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
