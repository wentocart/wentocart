<?php

namespace App\Domain\Settings;

use Illuminate\Support\Facades\DB;

final class DocumentSequence
{
    public static function next(string $name, int $start = 1): int
    {
        $name = trim($name);
        $start = max(1, $start);

        return (int) DB::transaction(function () use ($name, $start) {
            $row = DB::table('document_sequences')->where('name', $name)->lockForUpdate()->first();
            if (! $row) {
                DB::table('document_sequences')->insert([
                    'name' => $name,
                    'next_number' => $start + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $start;
            }
            $n = max($start, (int) $row->next_number);
            DB::table('document_sequences')->where('name', $name)->update([
                'next_number' => $n + 1,
                'updated_at' => now(),
            ]);

            return $n;
        });
    }
}
