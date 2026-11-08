<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\MarketplaceAuditLog;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 19 — append-only marketplace audit trail.
 */
class MarketplaceAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        string $entityType,
        int $entityId,
        string $actorType,
        ?int $actorId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $reference = null,
        ?int $sellerId = null,
        ?int $customerId = null,
        array $context = [],
    ): ?MarketplaceAuditLog {
        try {
            if ($reference !== null) {
                $existing = MarketplaceAuditLog::query()
                    ->where('action', $action)
                    ->where('reference', $reference)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $storeId = app(StoreContext::class)->id();

            return MarketplaceAuditLog::query()->create([
                'store_id' => $storeId > 0 ? $storeId : null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'seller_id' => $sellerId,
                'customer_id' => $customerId,
                'before_state' => $this->sanitize($before),
                'after_state' => $this->sanitize($after),
                'reason' => $reason,
                'reference' => $reference,
                'context' => $this->sanitize($context),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('marketplace.audit.failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    protected function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $blocked = [
            'password',
            'password_confirmation',
            'token',
            'remember_token',
            'api_token',
            'secret',
            'csrf',
            'session',
        ];

        $clean = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $blocked, true) || str_contains($lower, 'password') || str_contains($lower, 'token')) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }
}
