<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Health\AiHealthService;
use App\Domain\Health\FixService;
use App\Domain\Health\HealthOverview;
use App\Domain\Health\HealthSettings;
use App\Domain\Health\Models\HealthAiProposal;
use App\Domain\Health\Models\HealthFinding;
use App\Domain\Health\Models\HealthFixJob;
use App\Domain\Health\Models\HealthScan;
use App\Domain\Health\RuleCatalog;
use App\Domain\Health\ScanEngine;
use App\Domain\Localization\Models\Language;
use App\Domain\Seo\SeoFixLive;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class HealthController extends Controller
{
    public function index(HealthOverview $overview): Response
    {
        return Inertia::render('Health/Overview', $overview->payload());
    }

    public function issues(Request $request, HealthOverview $overview): Response
    {
        $severity = trim((string) $request->query('severity', ''));
        $category = trim((string) $request->query('category', ''));
        $status = trim((string) $request->query('status', 'open'));
        $q = trim((string) $request->query('q', ''));

        $query = HealthFinding::query()->latest('id');
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if (in_array($severity, ['critical', 'warning', 'opportunity', 'passed'], true)) {
            $query->where('severity', $severity);
        }
        if (in_array($category, RuleCatalog::CATEGORIES, true)) {
            $query->where('category', $category);
        }
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', '%'.$q.'%')
                    ->orWhere('rule_code', 'like', '%'.$q.'%');
            });
        }

        $findings = $query->adminPaginate()->through(
            fn (HealthFinding $f) => $overview->presentFinding($f)
        );

        $counts = [
            'all' => HealthFinding::query()->where('status', 'open')->count(),
            'critical' => HealthFinding::query()->where('status', 'open')->where('severity', 'critical')->count(),
            'warning' => HealthFinding::query()->where('status', 'open')->where('severity', 'warning')->count(),
            'opportunity' => HealthFinding::query()->where('status', 'open')->where('severity', 'opportunity')->count(),
            'passed' => HealthFinding::query()->where('status', 'passed')->count(),
        ];

        return Inertia::render('Health/Issues', [
            'findings' => $findings,
            'counts' => $counts,
            'filters' => compact('severity', 'category', 'status', 'q'),
            'categories' => RuleCatalog::CATEGORIES,
        ]);
    }

    public function issueShow(HealthFinding $finding, HealthOverview $overview): Response
    {
        return Inertia::render('Health/IssueShow', [
            'finding' => $overview->presentFinding($finding),
            'rule' => RuleCatalog::get($finding->rule_code),
        ]);
    }

    public function seo(HealthOverview $overview): Response
    {
        $payload = $overview->payload();
        $seo = collect($payload['categories'] ?? [])->firstWhere('id', 'seo');

        return Inertia::render('Health/Seo', [
            'overview' => $payload,
            'seo' => $seo,
            'issues' => HealthFinding::query()->where('status', 'open')->where('category', 'seo')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->limit(20)->get()
                ->map(fn ($f) => $overview->presentFinding($f)),
        ]);
    }

    public function content(HealthOverview $overview): Response
    {
        return $this->categoryPage('Health/Content', 'content', $overview);
    }

    public function images(HealthOverview $overview): Response
    {
        $payload = $overview->payload();
        $images = collect($payload['categories'] ?? [])->firstWhere('id', 'images');
        $oversized = HealthFinding::query()->where('rule_code', 'images.oversized')->where('status', 'open')->first();
        $missingAlt = HealthFinding::query()->where('rule_code', 'images.missing_alt')->where('status', 'open')->first();
        $unused = HealthFinding::query()->where('rule_code', 'images.unused')->where('status', 'open')->first();

        return Inertia::render('Health/Images', [
            'overview' => $payload,
            'category' => $images,
            'stats' => [
                'oversized' => (int) ($oversized?->affected_count ?? 0),
                'missing_alt' => (int) ($missingAlt?->affected_count ?? 0),
                'unused' => (int) ($unused?->affected_count ?? 0),
            ],
            'issues' => HealthFinding::query()->where('status', 'open')->where('category', 'images')
                ->limit(30)->get()->map(fn ($f) => $overview->presentFinding($f)),
        ]);
    }

    public function performance(HealthOverview $overview): Response
    {
        return $this->categoryPage('Health/Performance', 'performance', $overview);
    }

    public function technical(HealthOverview $overview): Response
    {
        return $this->categoryPage('Health/Technical', 'technical', $overview);
    }

    public function cleanup(HealthOverview $overview): Response
    {
        return $this->categoryPage('Health/Cleanup', 'cleanup', $overview);
    }

    public function fixCenter(FixService $fixes): Response
    {
        return Inertia::render('Health/FixCenter', [
            'groups' => $fixes->fixCenterGroups(),
        ]);
    }

    public function aiWorkspace(): Response
    {
        $proposals = HealthAiProposal::query()->latest('id')->adminPaginate()->through(fn (HealthAiProposal $p) => [
            'id' => $p->id,
            'resource_type' => $p->resource_type,
            'resource_id' => $p->resource_id,
            'task_type' => $p->task_type,
            'status' => $p->status,
            'current' => $p->current_content,
            'suggested' => $p->edited_output ?: $p->generated_output,
            'context' => $p->input_context,
            'created_at' => optional($p->created_at)?->toDateTimeString(),
        ]);

        return Inertia::render('Health/AiWorkspace', ['proposals' => $proposals]);
    }

    public function pageInspector(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $product = null;
        if ($q !== '' && ctype_digit($q)) {
            $product = Product::query()->with('translations')->find((int) $q);
        } elseif ($q !== '') {
            $product = Product::query()->where('sku', $q)->with('translations')->first();
        }
        $locale = Language::defaultCode();
        $page = null;
        if ($product) {
            $tr = $product->translation($locale);
            $checks = [
                'meta_title' => trim((string) ($tr?->meta_title ?? '')) !== '',
                'meta_description' => trim((string) ($tr?->meta_description ?? '')) !== '',
                'canonical' => trim((string) ($tr?->canonical_url ?? '')) !== '',
                'cover' => trim((string) ($product->cover_path ?? '')) !== '',
                'description' => mb_strlen(strip_tags((string) ($tr?->description ?? ''))) >= 80,
            ];
            $pass = count(array_filter($checks));
            $page = [
                'type' => 'product',
                'id' => $product->id,
                'label' => (string) ($tr?->name ?: $product->sku),
                'sku' => $product->sku,
                'score' => (int) round(100 * $pass / max(1, count($checks))),
                'checks' => $checks,
                'href' => '/admin/products/'.$product->id.'/edit',
            ];
        }

        return Inertia::render('Health/PageInspector', [
            'q' => $q,
            'page' => $page,
        ]);
    }

    public function history(): Response
    {
        $scans = HealthScan::query()->latest('id')->adminPaginate()->through(fn (HealthScan $s) => [
            'id' => $s->id,
            'type' => $s->type,
            'status' => $s->status,
            'overall_score' => $s->overall_score,
            'critical_count' => $s->critical_count,
            'warning_count' => $s->warning_count,
            'opportunity_count' => $s->opportunity_count,
            'passed_count' => $s->passed_count,
            'started_at' => optional($s->started_at)?->toDateTimeString(),
            'completed_at' => optional($s->completed_at)?->toDateTimeString(),
            'comparison' => $s->comparison,
            'error' => $s->error,
        ]);

        $fixes = HealthFixJob::query()->latest('id')->limit(40)->get()->map(fn (HealthFixJob $j) => [
            'id' => $j->id,
            'rule_code' => $j->rule_code,
            'fix_type' => $j->fix_type,
            'status' => $j->status,
            'created_at' => optional($j->created_at)?->toDateTimeString(),
            'error' => $j->error,
        ]);

        return Inertia::render('Health/History', [
            'scans' => $scans,
            'fixes' => $fixes,
            'trend' => HealthScan::query()->where('status', 'completed')->latest('id')->limit(12)->get()
                ->sortBy('id')->values()->map(fn ($s) => [
                    'date' => optional($s->completed_at)?->toDateString(),
                    'score' => (int) $s->overall_score,
                ]),
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('Health/Settings', [
            'settings' => HealthSettings::all(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable'],
            'scan_schedule' => ['nullable', 'string', 'max:40'],
            'notify_critical' => ['nullable'],
            'notify_score_drop' => ['nullable'],
            'ai_enabled' => ['nullable'],
            'ai_require_approval' => ['nullable'],
            'ai_tone' => ['nullable', 'string', 'max:40'],
            'images_auto_webp' => ['nullable'],
            'images_keep_original' => ['nullable'],
            'images_max_edge' => ['nullable', 'integer', 'min:256', 'max:8192'],
            'retention_days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);
        HealthSettings::save($data);

        return back()->with('success', 'Store Health settings saved.');
    }

    public function scan(Request $request, ScanEngine $engine): RedirectResponse|JsonResponse
    {
        $type = (string) $request->input('type', 'full');
        $inline = app()->runningUnitTests() || $request->boolean('inline');
        $scan = $engine->start($type, $inline);
        if ($request->wantsJson() || $request->boolean('live')) {
            return response()->json(['scan' => $scan->fresh()]);
        }

        return redirect()->route('admin.health.scan.show', $scan);
    }

    public function scanShow(HealthScan $scan): Response
    {
        return Inertia::render('Health/ScanProgress', [
            'scan' => [
                'id' => $scan->id,
                'uuid' => $scan->uuid,
                'type' => $scan->type,
                'status' => $scan->status,
                'current_step' => $scan->current_step,
                'pages_total' => $scan->pages_total,
                'pages_checked' => $scan->pages_checked,
                'products_checked' => $scan->products_checked,
                'images_checked' => $scan->images_checked,
                'assets_checked' => $scan->assets_checked,
                'overall_score' => $scan->overall_score,
                'critical_count' => $scan->critical_count,
                'warning_count' => $scan->warning_count,
                'opportunity_count' => $scan->opportunity_count,
                'passed_count' => $scan->passed_count,
                'category_scores' => $scan->category_scores,
                'comparison' => $scan->comparison,
                'error' => $scan->error,
                'completed_at' => optional($scan->completed_at)?->toDateTimeString(),
            ],
        ]);
    }

    public function scanStatus(ScanEngine $engine): JsonResponse
    {
        return response()->json(['live' => $engine->live()]);
    }

    public function fix(HealthFinding $finding, FixService $fixes): RedirectResponse
    {
        try {
            $fixes->autoFix($finding);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Fix applied. Re-run a scan to verify.');
    }

    public function ignore(Request $request, HealthFinding $finding, FixService $fixes): RedirectResponse
    {
        $mode = (string) $request->input('mode', 'permanent');
        $fixes->ignore($finding, $mode);

        return back()->with('success', 'Issue updated.');
    }

    public function aiGenerate(HealthFinding $finding, AiHealthService $ai): RedirectResponse
    {
        try {
            $ai->generateForFinding($finding);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.health.ai')->with('success', 'AI suggestions generated. Review and approve before applying.');
    }

    public function aiApprove(HealthAiProposal $proposal, AiHealthService $ai): RedirectResponse
    {
        $ai->approve($proposal);

        return back()->with('success', 'Suggestion approved.');
    }

    public function aiApply(HealthAiProposal $proposal, AiHealthService $ai): RedirectResponse
    {
        try {
            $ai->applyProposal($proposal);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'AI suggestion applied.');
    }

    public function status(SeoFixLive $live): JsonResponse
    {
        return $live->status();
    }

    protected function categoryPage(string $component, string $category, HealthOverview $overview): Response
    {
        $payload = $overview->payload();
        $cat = collect($payload['categories'] ?? [])->firstWhere('id', $category);

        return Inertia::render($component, [
            'overview' => $payload,
            'category' => $cat,
            'issues' => HealthFinding::query()->where('status', 'open')->where('category', $category)
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->limit(40)->get()
                ->map(fn ($f) => $overview->presentFinding($f)),
        ]);
    }
}
