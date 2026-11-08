<?php

namespace App\Console\Commands;

use App\Domain\Api\Graphql\GraphqlAdminApiAudits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 */
class PruneAdminApiAuditsCommand extends Command
{
    protected $signature = 'wento:prune-admin-api-audits {--days= : Retention days override}';

    protected $description = 'Delete Admin API audit history older than retention (Integration → History prune).';

    public function handle(): int
    {
        if (! Schema::hasTable('admin_api_audits')) {
            $this->warn('Table admin_api_audits is missing — run installer/schema bootstrap first.');

            return self::SUCCESS;
        }
        $daysOpt = $this->option('days');
        $days = $daysOpt !== null && $daysOpt !== '' ? (int) $daysOpt : null;
        $result = GraphqlAdminApiAudits::prune($days, false);
        $this->info(sprintf(
            'Pruned %d Admin API audit row(s) older than %d day(s).',
            (int) ($result['deleted'] ?? 0),
            (int) ($result['days'] ?? 90)
        ));

        return self::SUCCESS;
    }
}
