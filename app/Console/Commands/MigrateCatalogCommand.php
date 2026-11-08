<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\PlatformMigrationService;
use Illuminate\Console\Command;

class MigrateCatalogCommand extends Command
{
    protected $signature = 'wento:migrate-catalog
                            {file : Path to CSV export file}
                            {--format= : Source CSV layout: store_csv, catalog_csv, universal}
                            {--dry-run : Validate and simulate migration without writing to database}
                            {--locale= : Target language code (defaults to shop default)}';

    protected $description = 'Import a catalog from a CSV export';

    public function handle(PlatformMigrationService $migrationService): int
    {
        $filePath = (string) $this->argument('file');
        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $format = $this->option('format');
        $dryRun = (bool) $this->option('dry-run');
        $locale = $this->option('locale');

        $this->info("Starting catalog migration from [{$filePath}]".($dryRun ? ' (DRY RUN)' : '').'...');

        try {
            $result = $migrationService->importFile($filePath, $format, $dryRun, $locale);

            $this->newLine();
            $this->info("=== Catalog Migration Summary ===");
            $this->line("Detected Format:     <fg=cyan>{$result['platform']}</>");
            $this->line("Total Rows Read:     <fg=cyan>{$result['total_rows']}</>");
            $this->line("Products Created:    <fg=green>{$result['products_created']}</>");
            $this->line("Products Updated:    <fg=yellow>{$result['products_updated']}</>");
            $this->line("Categories Created:  <fg=green>{$result['categories_created']}</>");

            if (! empty($result['errors'])) {
                $this->newLine();
                $this->warn("Warnings / Errors (".count($result['errors'])."):");
                foreach (array_slice($result['errors'], 0, 10) as $err) {
                    $this->line("  - {$err}");
                }
                if (count($result['errors']) > 10) {
                    $this->line("  ... and ".(count($result['errors']) - 10)." more.");
                }
            }

            $this->newLine();
            $this->info('Migration completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Migration failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
