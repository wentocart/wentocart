<?php

namespace App\Console\Commands;

use App\Domain\Plugin\StructureValidator;

class MakePluginCommand extends MakeAppCommand
{
    protected $signature = 'make:plugin {id : vendor/name, for example acme/hello-banner} {--title= : Display name shown in Design → Extensions}';

    protected $description = 'Scaffold a new extension under extensions/{vendor}/{name}.';
}
