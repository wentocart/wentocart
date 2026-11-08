<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Ops\Models\WebhookDelivery;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Security\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WebhookDispatcher
{
    /** @var list<int> */
    protected array $backoff = [0, 60, 300, 1800, 7200, 43200];

    public function handle(CommerceEvent $event): void
    {
        if (! WebhookFlags::isEnabled((string) $event->name)) {
            return;
        }
        $endpoints = WebhookEndpoint::query()
            ->where('is_enabled', true)
            ->whereNull('disabled_at')
            ->get();
        foreach ($endpoints as $endpoint) {
            if (! $endpoint->matches((string) $event->name)) {
                continue;
            }
            $this->queue($endpoint, (string) $event->name, is_array($event->payload) ? $event->payload : [], $event->id);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(WebhookEndpoint $endpoint, string $event, array $payload, ?int $commerceEventId = null): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'webhook_endpoint_id' => $endpoint->id,
            'commerce_event_id' => $commerceEventId,
            'event' => $event,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $this->dispatchAttempt($delivery);

        return $delivery;
    }

    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->queue($endpoint, 'ops.ping', ['ok' => true, 'endpoint_id' => $endpoint->id]);
    }

    public function deliver(WebhookDelivery $delivery): bool
    {
        $delivery->refresh();
        if (! in_array($delivery->status, ['pending', 'failed'], true)) {
            return $delivery->status === 'sent';
        }
        if ($delivery->available_at && $delivery->available_at->isFuture()) {
            return false;
        }

        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->is_enabled || $endpoint->disabled_at) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Endpoint is disabled.']);

            return false;
        }

        $secret = $endpoint->plainSecret();
        if (! $secret) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Endpoint secret is missing.']);

            return false;
        }

        $body = json_encode([
            'id' => $delivery->uuid,
            'event' => $delivery->event,
            'created_at' => now()->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body) || $body === '') {
            $delivery->update(['status' => 'failed', 'last_error' => 'Could not encode payload.']);

            return false;
        }

        $timestamp = time();
        $header = WebhookSigner::header($secret, $body, $timestamp);
        $attempts = (int) $delivery->attempts + 1;
        $delivery->update(['status' => 'sending', 'attempts' => $attempts]);

        try {
            $response = Http::timeout(8)
                ->withOptions(SafeUrl::outboundOptions((string) $endpoint->url))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'WentoCart-Webhook/1',
                    WebhookSigner::HEADER => $header,
                    'X-Wento-Event' => $delivery->event,
                    'X-Wento-Delivery' => $delivery->uuid,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $ok = $response->successful();
            $snippet = Str::limit(trim((string) $response->body()), 8000, '');
            $delivery->update([
                'status' => $ok ? 'sent' : 'failed',
                'sent_at' => $ok ? now() : null,
                'response_code' => $response->status(),
                'response_body' => $snippet !== '' ? $snippet : null,
                'last_error' => $ok ? null : ('HTTP '.$response->status()),
                'available_at' => $ok ? $delivery->available_at : $this->nextAttemptAt($attempts),
            ]);
            $endpoint->update([
                'last_status' => $ok ? 'sent' : 'failed',
                'last_error' => $ok ? null : ('HTTP '.$response->status()),
                'last_sent_at' => now(),
                'consecutive_failures' => $ok ? 0 : ((int) $endpoint->consecutive_failures + 1),
            ]);
            if (! $ok) {
                $this->maybeDisable($endpoint->fresh());
                $this->retryLater($delivery->fresh());
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('webhook.failed', ['id' => $endpoint->id, 'error' => $e->getMessage()]);
            $delivery->update([
                'status' => 'failed',
                'last_error' => Str::limit($e->getMessage(), 255, ''),
                'available_at' => $this->nextAttemptAt($attempts),
            ]);
            $endpoint->update([
                'last_status' => 'failed',
                'last_error' => Str::limit($e->getMessage(), 255, ''),
                'last_sent_at' => now(),
                'consecutive_failures' => (int) $endpoint->consecutive_failures + 1,
            ]);
            $this->maybeDisable($endpoint->fresh());
            $this->retryLater($delivery->fresh());

            return false;
        }
    }

    public function tick(int $limit = 40): int
    {
        $rows = WebhookDelivery::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $ran = 0;
        foreach ($rows as $row) {
            if ((int) $row->attempts >= count($this->backoff)) {
                continue;
            }
            $this->deliver($row);
            $ran++;
        }

        return $ran;
    }

    protected function dispatchAttempt(WebhookDelivery $delivery): void
    {
        if (app()->runningUnitTests()) {
            $this->deliver($delivery);

            return;
        }
        $id = (int) $delivery->id;
        dispatch(function () use ($id) {
            $row = WebhookDelivery::query()->find($id);
            if ($row) {
                app(WebhookDispatcher::class)->deliver($row);
            }
        })->afterResponse();
    }

    protected function retryLater(?WebhookDelivery $delivery): void
    {
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }
        if ((int) $delivery->attempts >= count($this->backoff)) {
            return;
        }
        $delivery->update(['status' => 'pending']);
    }

    protected function nextAttemptAt(int $attempts): \Illuminate\Support\Carbon
    {
        $wait = $this->backoff[min($attempts, count($this->backoff) - 1)] ?? 43200;

        return now()->addSeconds($wait);
    }

    protected function maybeDisable(?WebhookEndpoint $endpoint): void
    {
        if (! $endpoint) {
            return;
        }
        if ((int) $endpoint->consecutive_failures < WebhookEndpoint::FAIL_DISABLE_AFTER) {
            return;
        }
        $endpoint->update([
            'is_enabled' => false,
            'disabled_at' => now(),
            'last_error' => 'Disabled after repeated delivery failures.',
        ]);
        CommerceTopics::flushListeners();
    }
}
