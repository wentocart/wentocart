<?php

namespace App\Domain\Security;

use App\Domain\Security\Models\InboundWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class WebhookIdempotency
{
    /**
     * Process an inbound webhook with idempotency and replay protection.
     *
     * @param  callable(): array<string, mixed>  $handler
     * @return array{response: array<string, mixed>, status: int}
     */
    public static function run(string $driver, string $rawBody, array $headers, callable $handler): array
    {
        if (! Schema::hasTable('inbound_webhook_events')) {
            $result = $handler();
            return [
                'response' => $result,
                'status' => ($result['ok'] ?? false) ? 200 : 400,
            ];
        }

        $payloadHash = hash('sha256', $rawBody);
        $eventId = self::extractEventId($driver, $rawBody);

        self::ensureUniqueKeys();

        // Fast-path replay check. The authoritative guard is the unique key
        // claimed on insert below, so concurrent deliveries cannot both run.
        /** @var InboundWebhookEvent|null $existing */
        $existing = self::findExisting($driver, $eventId, $payloadHash);

        if ($existing) {
            $replay = self::replayResponse($existing);
            if ($replay !== null) {
                return $replay;
            }
            // A previous attempt failed: drop the marker so a genuine retry can claim it.
            $existing->delete();
        }

        try {
            $event = InboundWebhookEvent::create([
                'driver' => $driver,
                'event_id' => $eventId,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'http_status' => 200,
            ]);
        } catch (QueryException $e) {
            if (! self::isDuplicateKey($e)) {
                throw $e;
            }
            // Lost the race: the winner owns this event — report its state.
            $winner = self::findExisting($driver, $eventId, $payloadHash);
            if ($winner) {
                $replay = self::replayResponse($winner);
                if ($replay !== null) {
                    return $replay;
                }
            }

            return [
                'response' => [
                    'ok' => true,
                    'message' => 'Event currently processing.',
                    'idempotent' => true,
                ],
                'status' => 200,
            ];
        }

        try {
            $result = $handler();
            $status = ($result['ok'] ?? false) ? 200 : 400;

            $event->update([
                'status' => 'completed',
                'http_status' => $status,
                'response_payload' => $result,
            ]);

            return [
                'response' => $result,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            $event->update([
                'status' => 'failed',
                'http_status' => 500,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{response: array<string, mixed>, status: int}|null
     */
    protected static function replayResponse(InboundWebhookEvent $existing): ?array
    {
        if ($existing->status === 'completed') {
            $cached = (array) $existing->response_payload;
            $cached['idempotent_replay'] = true;

            return [
                'response' => $cached,
                'status' => $existing->http_status ?: 200,
            ];
        }

        if ($existing->status === 'processing') {
            return [
                'response' => [
                    'ok' => true,
                    'message' => 'Event currently processing.',
                    'idempotent' => true,
                ],
                'status' => 200,
            ];
        }

        return null;
    }

    protected static function findExisting(string $driver, ?string $eventId, string $payloadHash): ?InboundWebhookEvent
    {
        $query = InboundWebhookEvent::query()->where('driver', $driver);
        if ($eventId !== null && $eventId !== '') {
            $query->where('event_id', $eventId);
        } else {
            $query->where('payload_hash', $payloadHash);
        }

        /** @var InboundWebhookEvent|null $existing */
        $existing = $query->first();

        return $existing;
    }

    protected static function isDuplicateKey(QueryException $e): bool
    {
        if ((string) $e->getCode() === '23000') {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique constraint failed');
    }

    /**
     * Best-effort unique claims for shops created before the constraint shipped.
     */
    protected static function ensureUniqueKeys(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        foreach ([['driver', 'payload_hash'], ['driver', 'event_id']] as $columns) {
            try {
                if (! Schema::hasIndex('inbound_webhook_events', $columns)) {
                    Schema::table('inbound_webhook_events', function (Blueprint $table) use ($columns): void {
                        $table->unique($columns);
                    });
                }
            } catch (\Throwable) {
                // Leave the legacy behaviour; the insert guard applies wherever the key exists.
            }
        }
    }

    protected static function extractEventId(string $driver, string $rawBody): ?string
    {
        $json = json_decode($rawBody, true);
        if (! is_array($json)) {
            return null;
        }

        // Stripe event structure
        if (isset($json['id']) && is_string($json['id'])) {
            return $json['id'];
        }

        // Alternative event id fields
        if (isset($json['event_id']) && is_string($json['event_id'])) {
            return $json['event_id'];
        }

        return null;
    }
}
