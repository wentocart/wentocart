<?php

namespace App\Domain\Platform\Upgrade;

interface UpgradePatch
{
    /** Semver this patch brings the schema to (e.g. 1.0.1). */
    public function version(): string;

    public function description(): string;

    public function up(): void;
}
