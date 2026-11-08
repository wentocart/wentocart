<?php

namespace App\Console\Commands;

use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\Support\ModuleBuses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ExtensionsTickCommand extends Command
{
    protected $signature = 'wento:extensions-tick';

    protected $description = 'Boot enabled extensions and run schedule.tick callbacks (abandoned cart mail, digests, …).';

    public function handle(): int
    {
        try {
            if (Schema::hasTable('plugins')) {
                PluginKernel::reset();
                app(PluginKernel::class)->boot();
            }
            $ran = ModuleBuses::runScheduleTicks();
            $this->info("Extension schedule tick finished ({$ran} callback(s)).");
        } catch (Throwable $e) {
            $this->error('Extension schedule tick failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
