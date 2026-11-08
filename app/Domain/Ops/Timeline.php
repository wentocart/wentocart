<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\OperationItem;

final class Timeline
{
    /**
     * @return list<array{at: string, source: string, title: string, href: string|null}>
     */
    public function for(string $entityType, int $entityId, int $limit = 20): array
    {
        $rows = OperationItem::query()
            ->with('operation')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (OperationItem $item) {
                $op = $item->operation;

                return [
                    'at' => optional($item->created_at)?->toIso8601String() ?: '',
                    'source' => (string) ($op?->source ?? 'admin'),
                    'title' => (string) ($op?->summary ?: ($item->action.' '.$item->label)),
                    'href' => $op ? '/admin/activity/'.$op->id : null,
                ];
            })
            ->all();

        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, $limit);
    }
}
