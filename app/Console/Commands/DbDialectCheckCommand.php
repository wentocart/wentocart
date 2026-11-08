<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Reports MySQL-only SQL patterns in app code that would break on PostgreSQL.
 */
class DbDialectCheckCommand extends Command
{
    protected $signature = 'wento:db-dialect-check {--fail : Exit 1 when hits remain}';

    protected $description = 'Scan app/ for MySQL-only SQL that blocks PostgreSQL parity.';

    public function handle(): int
    {
        $patterns = [
            'DB::raw(.*like binary' => '/DB::raw\([^)]*like binary/i',
            'whereRaw LIKE BINARY' => '/whereRaw\([^)]*LIKE BINARY/i',
            'SHOW TABLES' => '/SHOW\s+(FULL\s+)?TABLES/i',
            'SHOW COLUMNS' => '/SHOW\s+COLUMNS/i',
            'INFORMATION_SCHEMA' => '/INFORMATION_SCHEMA/i',
            'GROUP_CONCAT' => '/GROUP_CONCAT\s*\(/i',
            'FIND_IN_SET' => '/FIND_IN_SET\s*\(/i',
            'FORCE INDEX' => '/FORCE\s+INDEX/i',
            '`backtick identifiers`' => '/DB::(?:select|statement|unprepared)\([^)]*`[a-z]/i',
        ];

        $hits = [];
        $files = File::allFiles(app_path());
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            // Installer MySQL schema path is expected to stay MySQL-specific.
            if (str_contains($path, '/Installer/SchemaBootstrap.php')) {
                continue;
            }
            if (str_contains($path, '/Installer/PostgresSchemaTranslator.php')) {
                continue;
            }
            if (str_contains($path, '/Commands/DbDialectCheckCommand.php')) {
                continue;
            }
            $contents = File::get($path);
            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $contents)) {
                    $hits[] = [str_replace(base_path().'/', '', $path), $label];
                }
            }
        }

        $driver = DB::connection()->getDriverName();
        $this->line('Current driver: '.$driver);
        $this->line('Hits: '.count($hits));
        foreach (array_slice($hits, 0, 40) as $hit) {
            $this->line('  '.$hit[0].' — '.$hit[1]);
        }
        if (count($hits) > 40) {
            $this->line('  … and '.(count($hits) - 40).' more');
        }

        if ($this->option('fail') && $hits !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
