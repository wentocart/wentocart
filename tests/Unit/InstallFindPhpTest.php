<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wento\Install\FindPhp;

require_once dirname(__DIR__, 2).'/scripts/install/helper.php';

class InstallFindPhpTest extends TestCase
{
    public function test_pick_best_skips_php_81_and_keeps_83(): void
    {
        $best = FindPhp::pickBest([
            [
                'bin' => '/opt/lampp/bin/php',
                'version' => '8.1.25',
                'extensions' => FindPhp::EXTENSIONS,
                'missing' => [],
                'ok' => false,
            ],
            [
                'bin' => '/usr/bin/php8.3',
                'version' => '8.3.6',
                'extensions' => FindPhp::EXTENSIONS,
                'missing' => [],
                'ok' => true,
            ],
            [
                'bin' => '/usr/bin/php8.4',
                'version' => '8.4.1',
                'extensions' => FindPhp::EXTENSIONS,
                'missing' => [],
                'ok' => true,
            ],
        ]);

        $this->assertNotNull($best);
        $this->assertSame('/usr/bin/php8.4', $best['bin']);
    }

    public function test_pick_best_returns_null_when_nothing_meets_the_floor(): void
    {
        $this->assertNull(FindPhp::pickBest([
            [
                'bin' => 'C:\\xampp\\php\\php.exe',
                'version' => '8.1.10',
                'extensions' => [],
                'missing' => ['pdo_mysql'],
                'ok' => false,
            ],
        ]));
    }
}
