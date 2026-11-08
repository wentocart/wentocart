<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;
use Illuminate\Support\Str;

final class CmsBlockCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'title', 'location', 'content', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['title'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'code' => 'csv-home-note',
                'title' => 'CSV home note',
                'location' => 'home',
                'content' => '<p>Imported from the sample CSV. Replace this copy.</p>',
                'is_enabled' => '1',
                'position' => '10',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $locale = Language::defaultCode();
        $rows = CmsBlock::query()
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(10000)
            ->get()
            ->map(function (CmsBlock $block) use ($locale) {
                $t = $block->translation($locale);

                return [
                    'code' => $block->code,
                    'title' => $t?->title ?: $block->title,
                    'location' => $block->location,
                    'content' => $t?->content ?: $block->content,
                    'is_enabled' => $block->is_enabled ? '1' : '0',
                    'position' => $block->position,
                ];
            })
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'CMS block', $delimiter, self::required());
        $locale = Language::defaultCode();
        $count = 0;
        $position = (int) (CmsBlock::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $title = AdminCsv::get($row, 'title');
            if ($title === '') {
                continue;
            }
            $code = Str::slug(AdminCsv::get($row, 'code') ?: $title, '_');
            $location = AdminCsv::get($row, 'location') ?: 'home';
            if (! isset(CmsBlock::LOCATIONS[$location])) {
                $location = 'home';
            }
            $payload = [
                'title' => $title,
                'location' => $location,
                'content' => AdminCsv::get($row, 'content'),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 1) ?? ($position + 1),
            ];
            $block = CmsBlock::query()->where('code', $code)->first();
            if ($block) {
                $block->update($payload);
            } else {
                $position = (int) $payload['position'];
                $block = CmsBlock::query()->create(array_merge($payload, ['code' => $code]));
            }
            $block->translations()->updateOrCreate(
                ['language_code' => $locale],
                [
                    'title' => $title,
                    'content' => AdminCsv::nullable(AdminCsv::get($row, 'content')),
                ]
            );
            $count++;
        }

        return $count;
    }
}
