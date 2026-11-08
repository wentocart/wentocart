<?php

namespace App\Domain\Localization\Support;

use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;

final class LanguageCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'is_enabled', 'is_default', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['code', 'name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'code' => 'sv',
                'name' => 'Swedish',
                'is_enabled' => '1',
                'is_default' => '0',
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
        $rows = Language::query()->orderByDesc('is_default')->orderBy('position')->get()->map(fn (Language $row) => [
            'code' => $row->code,
            'name' => $row->name,
            'is_enabled' => $row->is_enabled ? '1' : '0',
            'is_default' => $row->is_default ? '1' : '0',
            'position' => $row->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Language', $delimiter, self::required());
        $count = 0;
        $position = (int) (Language::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $code = strtolower(AdminCsv::get($row, 'code'));
            $name = AdminCsv::get($row, 'name');
            if ($code === '' || $name === '') {
                continue;
            }
            $payload = [
                'name' => $name,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'is_default' => AdminCsv::bool(AdminCsv::get($row, 'is_default'), false),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 1) ?? ($position + 1),
            ];
            $language = Language::query()->updateOrCreate(['code' => $code], $payload);
            if ($language->is_default) {
                Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
            }
            $count++;
        }

        return $count;
    }
}
