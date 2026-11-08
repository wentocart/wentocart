<?php

namespace App\Console\Commands;

use App\Domain\Health\HealthSettings;
use App\Domain\Health\Models\HealthFinding;
use App\Domain\Health\Models\HealthScan;
use App\Domain\Health\ScanEngine;
use Illuminate\Console\Command;
use Throwable;

class HealthScheduleCommand extends Command
{
    protected $signature = 'wento:health-schedule';

    protected $description = 'Run a Store Health scan when the schedule says it is due';

    public function handle(ScanEngine $engine): int
    {
        $settings = HealthSettings::all();
        if (! ($settings['enabled'] ?? true)) {
            $this->info('Store Health is disabled.');

            return self::SUCCESS;
        }

        $schedule = (string) ($settings['scan_schedule'] ?? 'weekly');
        if ($schedule === 'manual') {
            $this->info('Scan schedule is manual.');

            return self::SUCCESS;
        }

        $latest = HealthScan::query()->where('status', 'completed')->latest('id')->first();
        $due = match ($schedule) {
            'daily' => ! $latest || ! $latest->completed_at || $latest->completed_at->lt(now()->subDay()),
            'weekly' => ! $latest || ! $latest->completed_at || $latest->completed_at->lt(now()->subWeek()),
            'monthly' => ! $latest || ! $latest->completed_at || $latest->completed_at->lt(now()->subMonth()),
            default => false,
        };

        if (! $due) {
            $this->info('Scan is not due yet.');
        } else {
            try {
                $scan = $engine->start('full', true);
                $this->info('Health scan '.$scan->id.' finished with score '.($scan->overall_score ?? 'n/a').'.');
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $days = max(7, (int) ($settings['retention_days'] ?? 90));
        $cutoff = now()->subDays($days);
        $oldIds = HealthScan::query()->where('created_at', '<', $cutoff)->pluck('id');
        if ($oldIds->isNotEmpty()) {
            HealthFinding::query()->whereIn('scan_id', $oldIds)->update(['scan_id' => null]);
            HealthScan::query()->whereIn('id', $oldIds)->delete();
            $this->info('Pruned '.$oldIds->count().' old scan(s).');
        }

        return self::SUCCESS;
    }
}
