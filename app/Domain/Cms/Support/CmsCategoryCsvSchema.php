<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Settings\AdminCsv;
use Illuminate\Support\Str;

final class CmsCategoryCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['name', 'slug', 'description', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'name' => 'Policies',
                'slug' => 'policies',
                'description' => 'Legal and shipping pages.',
                'is_enabled' => '1',
                'position' => '10',
            ],
            [
                'name' => 'Guides',
                'slug' => 'guides',
                'description' => 'How-to articles.',
                'is_enabled' => '1',
                'position' => '20',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = CmsCategory::query()->orderBy('position')->orderBy('name')->get()->map(fn (CmsCategory $row) => [
            'name' => $row->name,
            'slug' => $row->slug,
            'description' => $row->description,
            'is_enabled' => $row->is_enabled ? '1' : '0',
            'position' => $row->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'CMS category', $delimiter, self::required());
        $count = 0;
        $position = (int) (CmsCategory::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $slug = Str::slug(AdminCsv::get($row, 'slug') ?: $name);
            if ($slug === '') {
                continue;
            }
            $payload = [
                'name' => $name,
                'description' => AdminCsv::nullable(AdminCsv::get($row, 'description')),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 1) ?? ($position + 1),
            ];
            $category = CmsCategory::query()->where('slug', $slug)->first();
            if ($category) {
                $category->update($payload);
            } else {
                $position = (int) $payload['position'];
                CmsCategory::query()->create(array_merge($payload, ['slug' => $slug]));
            }
            $count++;
        }

        return $count;
    }
}
