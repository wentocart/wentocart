<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\EmailLog;
use App\Support\AdminPagination;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class EmailLogIndex
{
    public function perPage(Request $request): int
    {
        return AdminPagination::perPage($request, 10);
    }

    public function range(Request $request): string
    {
        $range = trim($request->string('range')->toString());

        return in_array($range, ['7', '30', '90', 'all'], true) ? $range : '7';
    }

    /**
     * @return array{sent: int, queued: int, failed: int}
     */
    public function stats(Request $request): array
    {
        $base = $this->query($request, applyStatus: false);

        return [
            'sent' => (clone $base)->where('status', 'sent')->count(),
            'queued' => (clone $base)->where('status', 'queued')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
        ];
    }

    /**
     * @return list<string>
     */
    public function templates(): array
    {
        return EmailLog::query()
            ->whereNotNull('template_code')
            ->where('template_code', '!=', '')
            ->distinct()
            ->orderBy('template_code')
            ->pluck('template_code')
            ->values()
            ->all();
    }

    /**
     * @return array{q: string, template: string, status: string, range: string, per_page: int}
     */
    public function filters(Request $request): array
    {
        return [
            'q' => trim($request->string('q')->toString()),
            'template' => trim($request->string('template')->toString()),
            'status' => trim($request->string('status')->toString()),
            'range' => $this->range($request),
            'per_page' => $this->perPage($request),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<EmailLog>
     */
    public function query(Request $request, bool $applyStatus = true)
    {
        $q = trim($request->string('q')->toString());
        $template = trim($request->string('template')->toString());
        $status = trim($request->string('status')->toString());
        $range = $this->range($request);

        return EmailLog::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('to_email', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('template_code', 'like', $like);
                });
            })
            ->when($template !== '' && $template !== 'all', fn ($query) => $query->where('template_code', $template))
            ->when($applyStatus && $status !== '' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($range !== 'all', function ($query) use ($range) {
                $query->where('created_at', '>=', now()->subDays((int) $range)->startOfDay());
            });
    }

    public function paginator(Request $request): LengthAwarePaginator
    {
        return $this->query($request)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (EmailLog $row) => [
                'id' => $row->id,
                'to' => $row->to_email,
                'subject' => $row->subject,
                'template' => $row->template_code,
                'status' => $row->status,
                'error' => $row->error,
                'created_at' => optional($row->created_at)?->format('Y-m-d H:i:s'),
            ]);
    }
}
