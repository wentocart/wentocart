<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Cms\CmsPageTemplates;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Language;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminCmsPages
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('cms.manage') && ! $admin->hasPermission('cms.pages'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $locale = trim((string) ($filters['locale'] ?? Language::defaultCode())) ?: Language::defaultCode();
        $q = CmsPage::query()->with('translations');

        $title = trim((string) ($filters['page_title'] ?? $filters['title'] ?? $filters['q'] ?? ''));
        $slug = trim((string) ($filters['slug'] ?? $filters['slug'] ?? ''));
        if ($title !== '' || $slug !== '') {
            $op = DbGrammar::likeOperator();
            $q->whereHas('translations', function ($inner) use ($title, $slug, $locale, $op) {
                $inner->where('language_code', $locale);
                if ($title !== '') {
                    $inner->where('title', $op, '%'.$title.'%');
                }
                if ($slug !== '') {
                    $inner->where('slug', $op, '%'.$slug.'%');
                }
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'created_at', 'updated_at', 'status'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (CmsPage $row) => self::map($row, $locale, false))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id, ?string $locale = null): ?array
    {
        self::assertManage();
        $locale = trim((string) ($locale ?: Language::defaultCode())) ?: Language::defaultCode();
        $row = CmsPage::query()->with('translations')->find($id);

        return $row ? self::map($row, $locale, true) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $locale = trim((string) ($input['locale'] ?? Language::defaultCode())) ?: Language::defaultCode();
        $block = self::localeBlock($input, $locale, true);
        $title = trim((string) ($block['page_title'] ?? $block['title'] ?? $input['page_title'] ?? $input['title'] ?? ''));
        $slug = trim((string) ($block['slug'] ?? $block['slug'] ?? $input['slug'] ?? $input['slug'] ?? ''));
        $body = (string) ($block['html_content'] ?? $block['body'] ?? $input['html_content'] ?? $input['body'] ?? '');
        if ($title === '') {
            throw new UserError('page_title is required.');
        }
        if ($slug === '') {
            $slug = Str::slug($title) ?: ('page-'.Str::lower(Str::random(6)));
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new UserError('slug must be lowercase letters, numbers, and hyphens.');
        }
        if (CmsPage::query()->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))->exists()) {
            throw new UserError('A CMS page with this slug already exists.');
        }
        $channels = collect($input['stores'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        foreach ($channels as $storeId) {
            if (! Store::query()->whereKey($storeId)->exists()) {
                throw new UserError('Unknown store id: '.$storeId);
            }
        }

        $page = DB::transaction(function () use ($input, $title, $slug, $body, $locale, $block) {
            $page = CmsPage::query()->create([
                'status' => CmsPage::STATUS_PUBLISHED,
                'is_enabled' => ! array_key_exists('status', $input) || (int) $input['status'] !== 0,
                'is_visible' => true,
                'template' => CmsPageTemplates::normalize('blank'),
                'robots' => 'index,follow',
            ]);
            $page->translations()->create([
                'language_code' => $locale,
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'meta_title' => (string) ($block['meta_title'] ?? $input['meta_title'] ?? ''),
                'meta_keywords' => (string) ($block['meta_keywords'] ?? $input['meta_keywords'] ?? ''),
                'meta_description' => (string) ($block['meta_description'] ?? $input['meta_description'] ?? ''),
            ]);

            return $page;
        });

        return self::map($page->fresh(['translations']) ?? $page, $locale, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $page = CmsPage::query()->with('translations')->find($id);
        if (! $page) {
            throw new UserError('CMS page not found.');
        }
        $locale = trim((string) ($input['locale'] ?? Language::defaultCode())) ?: Language::defaultCode();
        $block = self::localeBlock($input, $locale, false);
        $tr = $page->translation($locale);
        $oldSlug = (string) ($tr?->slug ?? '');

        $title = array_key_exists('page_title', $block) || array_key_exists('title', $block)
            ? trim((string) ($block['page_title'] ?? $block['title'] ?? ''))
            : (string) ($tr?->title ?? '');
        if ($title === '' && (array_key_exists('page_title', $input) || array_key_exists('title', $input))) {
            $title = trim((string) ($input['page_title'] ?? $input['title'] ?? ''));
        }
        if ($title === '') {
            $title = (string) ($tr?->title ?? '');
        }
        if ($title === '') {
            throw new UserError('page_title is required.');
        }

        $slug = array_key_exists('slug', $block) || array_key_exists('slug', $block)
            ? trim((string) ($block['slug'] ?? $block['slug'] ?? ''))
            : (array_key_exists('slug', $input) || array_key_exists('slug', $input)
                ? trim((string) ($input['slug'] ?? $input['slug'] ?? ''))
                : (string) ($tr?->slug ?? ''));
        if ($slug === '') {
            $slug = Str::slug($title) ?: ('page-'.$page->id);
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new UserError('slug must be lowercase letters, numbers, and hyphens.');
        }
        $exists = CmsPage::query()
            ->whereKeyNot($page->id)
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))
            ->exists();
        if ($exists) {
            throw new UserError('A CMS page with this slug already exists.');
        }

        $body = array_key_exists('html_content', $block) || array_key_exists('body', $block)
            ? (string) ($block['html_content'] ?? $block['body'] ?? '')
            : (array_key_exists('html_content', $input) || array_key_exists('body', $input)
                ? (string) ($input['html_content'] ?? $input['body'] ?? '')
                : (string) ($tr?->body ?? ''));

        if (array_key_exists('stores', $input) && is_array($input['stores'])) {
            $channels = collect($input['stores'])->map(fn ($cid) => (int) $cid)->filter()->unique()->values()->all();
            if ($channels === []) {
                throw new UserError('stores cannot be empty.');
            }
            foreach ($channels as $storeId) {
                if (! Store::query()->whereKey($storeId)->exists()) {
                    throw new UserError('Unknown store id: '.$storeId);
                }
            }
        }

        DB::transaction(function () use ($input, $page, $title, $slug, $body, $locale, $block, $tr, $oldSlug) {
            if (array_key_exists('status', $input) && $input['status'] !== null && ! is_string($input['status'])) {
                $page->is_enabled = (int) $input['status'] === 1;
            }
            $page->save();
            $page->translations()->updateOrCreate(
                ['language_code' => $locale],
                [
                    'title' => $title,
                    'slug' => $slug,
                    'body' => $body,
                    'meta_title' => (string) ($block['meta_title'] ?? $input['meta_title'] ?? $tr?->meta_title ?? ''),
                    'meta_keywords' => (string) ($block['meta_keywords'] ?? $input['meta_keywords'] ?? $tr?->meta_keywords ?? ''),
                    'meta_description' => (string) ($block['meta_description'] ?? $input['meta_description'] ?? $tr?->meta_description ?? ''),
                ]
            );
            if ($oldSlug !== '' && $oldSlug !== $slug) {
                SearchRedirect::query()->updateOrCreate(
                    ['query' => '/page/'.$oldSlug],
                    ['target_path' => '/page/'.$slug, 'is_enabled' => true]
                );
            }
        });

        return self::map($page->fresh(['translations']) ?? $page, $locale, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $page = CmsPage::query()->with('translations')->find($id);
        if (! $page) {
            throw new UserError('CMS page not found.');
        }
        $snapshot = self::map($page, Language::defaultCode(), true);
        $page->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one CMS page id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = CmsPage::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(CmsPage $page, string $locale, bool $detail = false): array
    {
        $tr = $page->translation($locale);
        $slug = (string) ($tr?->slug ?? '');
        $payload = [
            'id' => (int) $page->id,

            'locale' => $locale,
            'page_title' => $tr?->title,
            'pageTitle' => $tr?->title,
            'title' => $tr?->title,
            'slug' => $slug,
            'slug' => $slug,
            'slug' => $slug,
            'html_content' => $detail ? (string) ($tr?->body ?? '') : null,
            'htmlContent' => $detail ? (string) ($tr?->body ?? '') : null,
            'meta_title' => $detail ? (string) ($tr?->meta_title ?? '') : null,
            'meta_keywords' => $detail ? (string) ($tr?->meta_keywords ?? '') : null,
            'meta_description' => $detail ? (string) ($tr?->meta_description ?? '') : null,
            'status' => $page->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $page->is_enabled,
            'preview_url' => $slug !== '' ? '/page/'.$slug : null,
            'previewUrl' => $slug !== '' ? '/page/'.$slug : null,
            'stores' => [],
            'created_at' => optional($page->created_at)?->toIso8601String(),
            'updated_at' => optional($page->updated_at)?->toIso8601String(),
        ];
        if (! $detail) {
            unset($payload['html_content'], $payload['htmlContent'], $payload['meta_title'], $payload['meta_keywords'], $payload['meta_description']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function localeBlock(array $input, string $locale, bool $required): array
    {
        if (isset($input[$locale]) && is_array($input[$locale])) {
            return $input[$locale];
        }
        if ($required) {
            return $input;
        }

        return $input;
    }
}
