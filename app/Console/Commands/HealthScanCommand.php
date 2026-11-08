<?php

namespace App\Console\Commands;

use App\Domain\Health\ScanEngine;
use Illuminate\Console\Command;
use Throwable;

class HealthScanCommand extends Command
{
    protected $signature = 'wento:health-scan {scan : Health scan id}';

    protected $description = 'Run a Store Health scan in the background';

    public function handle(ScanEngine $engine): int
    {
        $id = (int) $this->argument('scan');
        try {
            $engine->execute($id);
            $this->info('Health scan '.$id.' completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
