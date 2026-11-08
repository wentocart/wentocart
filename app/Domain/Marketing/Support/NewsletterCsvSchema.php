<?php

namespace App\Domain\Marketing\Support;

use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\AdminCsv;

final class NewsletterCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['email', 'created_at'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['email'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            ['email' => 'newsletter.one@example.com', 'created_at' => ''],
            ['email' => 'newsletter.two@example.com', 'created_at' => ''],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(?array $ids = null, string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = NewsletterSubscriber::query()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->limit(50000)
            ->get(['email', 'created_at'])
            ->map(fn (NewsletterSubscriber $row) => [
                'email' => $row->email,
                'created_at' => optional($row->created_at)?->toDateTimeString(),
            ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Newsletter subscriber', $delimiter, self::required());
        $count = 0;
        foreach ($parsed['rows'] as $row) {
            $email = strtolower(AdminCsv::get($row, 'email'));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            NewsletterSubscriber::query()->firstOrCreate(['email' => $email]);
            $count++;
        }

        return $count;
    }
}
