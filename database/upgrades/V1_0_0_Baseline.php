<?php

namespace Database\Upgrades;

use App\Domain\Platform\Upgrade\UpgradePatch;

/**
 * Baseline marker for shops stamped at 1.0.0. No schema changes.
 */
final class V1_0_0_Baseline implements UpgradePatch
{
    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Baseline 1.0.0 (no schema changes).';
    }

    public function up(): void
    {
        // Intentionally empty — fresh installs already match the schema dump.
    }
}
