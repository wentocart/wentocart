<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\MarketplaceAuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 19 — admin marketplace audit trail (read-only).
 */
class MarketplaceAuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'action' => trim((string) $request->query('action', '')),
            'entity_type' => trim((string) $request->query('entity_type', '')),
            'seller_id' => (int) $request->query('seller_id', 0),
        ];

        $query = MarketplaceAuditLog::query()->orderByDesc('id');
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('action', 'like', '%'.$needle.'%')
                    ->orWhere('reference', 'like', '%'.$needle.'%')
                    ->orWhere('reason', 'like', '%'.$needle.'%');
            });
        }
        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }
        if ($filters['entity_type'] !== '') {
            $query->where('entity_type', $filters['entity_type']);
        }
        if ($filters['seller_id'] > 0) {
            $query->where('seller_id', $filters['seller_id']);
        }

        $actions = MarketplaceAuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        $entityTypes = MarketplaceAuditLog::query()
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->all();

        return Inertia::render('Multivendor/Audits', [
            'filters' => $filters,
            'actions' => $actions,
            'entity_types' => $entityTypes,
            'rows' => $query->adminPaginate()->through(fn (MarketplaceAuditLog $row) => [
                'id' => (int) $row->id,
                'action' => (string) $row->action,
                'entity_type' => (string) $row->entity_type,
                'entity_id' => (int) $row->entity_id,
                'actor_type' => (string) $row->actor_type,
                'actor_id' => $row->actor_id ? (int) $row->actor_id : null,
                'seller_id' => $row->seller_id ? (int) $row->seller_id : null,
                'reason' => $row->reason,
                'reference' => $row->reference,
                'created_at' => $row->created_at?->toDateTimeString(),
            ]),
        ]);
    }
}
