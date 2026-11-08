<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\CommerceTopics;
use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Ops\Models\WebhookDelivery;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Ops\Models\WebserviceKey;
use App\Domain\Ops\WebhookCatalog;
use App\Domain\Ops\WebserviceCatalog;
use App\Domain\Ops\WebhookDispatcher;
use App\Domain\Ops\WebhookFlags;
use App\Domain\Security\SafeUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class OpsToolsController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'webhooks');
        $tabs = ['webhooks', 'deliveries', 'keys', 'docs'];
        if (! in_array($tab, $tabs, true)) {
            $tab = 'webhooks';
        }

        return Inertia::render('Ops/Tools', [
            'tab' => $tab,
            'catalog' => [
                'events' => collect(WebhookCatalog::events())->map(fn (array $row) => [
                    ...$row,
                    'enabled' => WebhookFlags::isEnabled($row['id']),
                ])->all(),
                'scopes' => WebhookCatalog::scopes(),
            ],
            'webhooks' => WebhookEndpoint::query()->latest('id')->get()->map(fn (WebhookEndpoint $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'url' => $row->url,
                'events' => $row->subscribedEvents(),
                'secret_prefix' => $row->secret_prefix,
                'is_enabled' => $row->is_enabled,
                'last_status' => $row->last_status,
                'last_error' => $row->last_error,
                'consecutive_failures' => $row->consecutive_failures,
                'last_sent_at' => optional($row->last_sent_at)?->toDateTimeString(),
            ]),
            'deliveries' => WebhookDelivery::query()
                ->with('endpoint:id,name,url')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (WebhookDelivery $row) => [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'event' => $row->event,
                    'status' => $row->status,
                    'attempts' => $row->attempts,
                    'payload' => $row->payload,
                    'response_code' => $row->response_code,
                    'response_body' => $row->response_body,
                    'last_error' => $row->last_error,
                    'endpoint' => $row->endpoint?->name,
                    'url' => $row->endpoint?->url,
                    'created_at' => optional($row->created_at)?->toDateTimeString(),
                    'sent_at' => optional($row->sent_at)?->toDateTimeString(),
                    'available_at' => optional($row->available_at)?->toDateTimeString(),
                ]),
            'events' => CommerceEvent::query()->latest('id')->limit(40)->get()->map(fn (CommerceEvent $event) => [
                'id' => $event->id,
                'name' => $event->name,
                'operation_id' => $event->operation_id,
                'created_at' => optional($event->created_at)?->toDateTimeString(),
            ]),
            'keys' => WebserviceKey::query()->latest('id')->get()->map(fn (WebserviceKey $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'key_prefix' => $row->key_prefix,
                'scopes' => $row->scopes,
                'allowed_ips' => $row->allowed_ips,
                'is_enabled' => $row->is_enabled,
                'expires_at' => optional($row->expires_at)?->toDateTimeString(),
                'last_used_at' => optional($row->last_used_at)?->toDateTimeString(),
                'last_ip' => $row->last_ip,
            ]),
            'shopUrl' => rtrim((string) config('app.url'), '/'),
            'revealed' => [
                'webhook_secret' => $request->session()->get('ops.webhook_secret'),
                'api_key' => $request->session()->get('ops.api_key'),
            ],
        ]);
    }

    public function createKey(): Response
    {
        return Inertia::render('Ops/KeyCreate', [
            'scopes' => WebhookCatalog::scopes(),
        ]);
    }

    public function webservices(Request $request): Response
    {
        return Inertia::render('Ops/Webservices', [
            'catalog' => [
                'scopes' => WebhookCatalog::scopes(),
                'endpoints' => WebserviceCatalog::endpoints(),
            ],
            'probe' => $request->session()->get('ops.ws.probe'),
            'revealed' => [
                'api_key' => $request->session()->get('ops.api_key'),
            ],
        ]);
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:80'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        try {
            SafeUrl::assertPublicHttp((string) $data['url']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        $events = WebhookEndpoint::normalizeEvents($data['events']);
        if ($events === []) {
            throw ValidationException::withMessages(['events' => 'Choose at least one event.']);
        }
        $plain = WebhookEndpoint::generateSecret();
        $endpoint = new WebhookEndpoint;
        $endpoint->fill([
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => $events,
            'event_pattern' => in_array('*', $events, true) ? '*' : $events[0],
            'is_enabled' => $request->boolean('is_enabled', true),
        ]);
        $endpoint->setPlainSecret($plain);
        $endpoint->save();
        CommerceTopics::flushListeners();

        return back()->with('success', 'Webhook saved. Copy the signing secret now — it is shown only once.')
            ->with('ops.webhook_secret', $plain);
    }

    public function destroyWebhook(WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->delete();
        CommerceTopics::flushListeners();

        return back()->with('success', 'Webhook removed.');
    }

    public function rotateWebhook(WebhookEndpoint $webhook): RedirectResponse
    {
        $plain = WebhookEndpoint::generateSecret();
        $webhook->setPlainSecret($plain);
        $webhook->consecutive_failures = 0;
        $webhook->disabled_at = null;
        $webhook->is_enabled = true;
        $webhook->save();
        CommerceTopics::flushListeners();

        return back()->with('success', 'New signing secret created. Copy it now.')
            ->with('ops.webhook_secret', $plain);
    }

    public function toggleWebhook(WebhookEndpoint $webhook): RedirectResponse
    {
        $on = ! $webhook->is_enabled;
        $webhook->update([
            'is_enabled' => $on,
            'disabled_at' => $on ? null : now(),
            'consecutive_failures' => $on ? 0 : $webhook->consecutive_failures,
        ]);
        CommerceTopics::flushListeners();

        return back()->with('success', $on ? 'Webhook enabled.' : 'Webhook disabled.');
    }

    public function pingWebhook(WebhookEndpoint $webhook, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $delivery = $dispatcher->ping($webhook);
        $ok = $delivery->fresh()?->status === 'sent';

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Ping delivered.'
            : 'Ping failed: '.($delivery->fresh()?->last_error ?: $webhook->fresh()?->last_error ?: 'request failed'));
    }

    public function toggleEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(WebhookCatalog::ids())],
        ]);
        $id = $data['event'];
        $on = ! WebhookFlags::isEnabled($id);
        WebhookFlags::set($id, $on);

        return back()->with('success', $on ? 'Event enabled. Destinations can receive it.' : 'Event disabled. Destinations will not receive it.');
    }

    public function retryDelivery(WebhookDelivery $delivery, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $delivery->update([
            'status' => 'pending',
            'available_at' => now(),
        ]);
        $ok = $dispatcher->deliver($delivery->fresh());

        return back()->with($ok ? 'success' : 'error', $ok ? 'Delivery sent.' : 'Delivery failed. It will retry automatically.');
    }

    public function storeKey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(WebhookCatalog::scopeIds())],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $plain = WebserviceKey::generatePlain();
        WebserviceKey::issue([
            'name' => $data['name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'allowed_ips' => $this->parseIps($data['allowed_ips'] ?? ''),
            'is_enabled' => true,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $request->user('admin')?->id,
        ], $plain);

        return redirect()
            ->route('admin.ops-tools.index', ['tab' => 'keys'])
            ->with('success', 'API key created. Copy it now — it cannot be shown again.')
            ->with('ops.api_key', $plain);
    }

    public function destroyKey(WebserviceKey $key): RedirectResponse
    {
        $key->delete();

        return back()->with('success', 'API key revoked.');
    }

    public function toggleKey(WebserviceKey $key): RedirectResponse
    {
        $key->update(['is_enabled' => ! $key->is_enabled]);

        return back()->with('success', $key->is_enabled ? 'API key enabled.' : 'API key disabled.');
    }

    public function tryWebservice(Request $request): RedirectResponse
    {
        $catalog = collect(WebserviceCatalog::endpoints());
        $paths = $catalog
            ->filter(fn (array $row) => in_array('GET', $row['methods'] ?? [], true))
            ->map(fn (array $row) => $row['path'])
            ->unique()
            ->values()
            ->all();

        $data = $request->validate([
            'path' => ['required', 'string', Rule::in($paths)],
            'token' => ['required', 'string', 'max:190'],
            'path_id' => ['nullable', 'string', 'max:64'],
            'include' => ['nullable', 'string', 'max:500'],
            'query' => ['nullable', 'string', 'max:500'],
        ]);

        $template = (string) $data['path'];
        $pathId = trim((string) ($data['path_id'] ?? ''));
        if (str_contains($template, '{id}')) {
            if ($pathId === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $pathId)) {
                throw ValidationException::withMessages([
                    'path_id' => 'Enter the numeric ID from Catalog, Orders, or Customers (example: 12).',
                ]);
            }
            $path = str_replace('{id}', $pathId, $template);
        } else {
            $path = $template;
        }
        $query = [];
        $include = trim((string) ($data['include'] ?? ''));
        if ($include !== '') {
            $query['include'] = $include;
        }
        $extra = trim((string) ($data['query'] ?? ''));
        if ($extra !== '') {
            parse_str(ltrim($extra, '?'), $parsed);
            if (is_array($parsed)) {
                unset($parsed['ws_key'], $parsed['api_key'], $parsed['token']);
                $query = array_merge($query, $parsed);
            }
        }

        $displayUrl = rtrim((string) config('app.url'), '/').$path;
        if ($query !== []) {
            $displayUrl .= (str_contains($displayUrl, '?') ? '&' : '?').http_build_query($query);
        }

        $started = microtime(true);
        $probeRequest = Request::create($path, 'GET', $query, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.trim($data['token']),
        ]);
        $response = app(Kernel::class)->handle($probeRequest);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $raw = $response->getContent() ?: '';
        $decoded = json_decode($raw, true);

        return back()->with('ops.ws.probe', [
            'url' => $displayUrl,
            'status' => $response->getStatusCode(),
            'ok' => $response->isSuccessful(),
            'ms' => $ms,
            'body' => is_array($decoded) ? $decoded : Str::limit($raw, 4000, ''),
        ]);
    }

    protected function parseIps(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $ip) {
            $ip = trim($ip);
            if ($ip === '') {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw ValidationException::withMessages(['allowed_ips' => 'Use a list of IPv4 or IPv6 addresses.']);
            }
            $out[] = $ip;
        }

        return array_values(array_unique($out));
    }
}
