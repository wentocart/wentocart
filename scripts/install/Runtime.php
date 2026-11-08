<?php

namespace Wento\Install;

/**
 * Stack-agnostic CLI installer. Works with system PHP, XAMPP, WAMP, Laragon, MAMP, or Docker.
 */
final class Runtime
{
    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        $this->reexecIfNeeded($argv);

        $args = array_values(array_slice($argv, 1));
        $command = $this->command($args);

        return match ($command) {
            'help', '--help', '-h' => $this->help(),
            'doctor', '--doctor' => $this->doctor(failHard: false),
            'wizard', '--wizard' => $this->wizard(),
            default => $this->install($args),
        };
    }

    /**
     * @param  list<string>  $argv
     */
    public function reexecIfNeeded(array $argv): void
    {
        $php = new FindPhp($this->root);
        $best = $php->best();
        $currentOk = version_compare(PHP_VERSION, FindPhp::NEED, '>=');
        foreach (FindPhp::EXTENSIONS as $ext) {
            $currentOk = $currentOk && extension_loaded($ext);
        }

        if ($currentOk) {
            return;
        }

        if ($best === null) {
            fwrite(STDERR, $this->phpMissingMessage($php));

            exit(1);
        }

        $current = realpath(PHP_BINARY) ?: PHP_BINARY;
        $target = realpath($best['bin']) ?: $best['bin'];
        if (strcasecmp($current, $target) === 0) {
            fwrite(STDERR, $this->phpMissingMessage($php));

            exit(1);
        }

        fwrite(STDOUT, 'Using PHP '.$best['version'].' at '.$best['bin'].PHP_EOL);
        $cmd = escapeshellarg($best['bin']).' '.implode(' ', array_map('escapeshellarg', $argv));
        passthru($cmd, $code);

        exit($code);
    }

    /**
     * @param  list<string>  $args
     */
    private function command(array $args): string
    {
        foreach ($args as $arg) {
            if (in_array($arg, ['doctor', '--doctor', 'wizard', '--wizard', 'help', '--help', '-h', '--cli'], true)) {
                return $arg === '--cli' ? 'install' : $arg;
            }
        }

        return 'install';
    }

    public function help(): int
    {
        $name = $this->productName();
        fwrite(STDOUT, <<<TXT
{$name} command-line install

Minimum versions: PHP 8.3+, MySQL/MariaDB 10.4+ (or SQLite), Node 20+ only if public/build is missing.
XAMPP, WAMP, Laragon, MAMP, system packages, and Docker are all fine — we pick PHP 8.3+ automatically.

  ./INSTALL.sh doctor          Check this machine
  ./INSTALL.sh --cli           Install from the terminal
  ./INSTALL.sh --wizard        Browser wizard at http://127.0.0.1:8099/

  php scripts/install/cli.php --no-interaction --demo \\
    --db-database=wentocart --prefix=WBL \\
    --admin-email=you@store.test --admin-password=secret

  docker compose up --build

Optional: create a file named php.path in this folder and put the full path to php (or php.exe) on the first line.
SQLite (no MySQL): add --db-connection=sqlite --db-database=database/database.sqlite

TXT);

        return 0;
    }

    public function doctor(bool $failHard = false): int
    {
        $php = new FindPhp($this->root);
        $rows = $php->inspectAll();
        $best = FindPhp::pickBest($rows);

        fwrite(STDOUT, $this->productName().' doctor'.PHP_EOL);
        fwrite(STDOUT, str_repeat('-', 60).PHP_EOL);

        if ($rows === []) {
            fwrite(STDOUT, "PHP        not found".PHP_EOL);
        }
        foreach ($rows as $row) {
            $mark = $row['ok'] ? 'OK' : 'skip';
            $extra = '';
            if (! $row['ok']) {
                $why = [];
                if ($row['version'] !== 'unknown' && version_compare($row['version'], FindPhp::NEED, '<')) {
                    $why[] = 'need '.FindPhp::NEED.'+';
                }
                if ($row['missing'] !== []) {
                    $why[] = 'missing '.implode(', ', $row['missing']);
                }
                if (isset($row['error'])) {
                    $why[] = $row['error'];
                }
                $extra = ' ('.implode('; ', $why ?: ['not a CLI with pdo_mysql']).')';
            }
            fwrite(STDOUT, sprintf("PHP %-6s %-4s %s%s\n", $row['version'], $mark, $row['bin'], $extra));
        }

        if ($best !== null) {
            fwrite(STDOUT, 'Selected   '.$best['bin'].' ('.$best['version'].')'.PHP_EOL);
        }

        $mysql = $this->mysqlReachable();
        fwrite(STDOUT, 'MySQL      '.($mysql ? 'port 3306 is open on 127.0.0.1' : 'not listening on 127.0.0.1:3306 (start MySQL/MariaDB, XAMPP, WAMP, or Docker)') . PHP_EOL);

        $assets = is_file($this->root.'/public/build/manifest.json');
        $node = $this->nodeVersion();
        if ($assets) {
            fwrite(STDOUT, 'Node       not required (public/build is present)'.PHP_EOL);
        } else {
            $nodeOk = $node !== null && version_compare($node, '20.0.0', '>=');
            fwrite(STDOUT, 'Node       '.($nodeOk ? 'OK v'.$node : ($node ? 'v'.$node.' (need 20+)' : 'not found — needed to build admin/storefront')).PHP_EOL);
        }

        $composer = $this->composerCommand();
        fwrite(STDOUT, 'Composer   '.($composer !== null ? 'OK' : 'will download composer.phar during install').PHP_EOL);

        $writable = is_writable($this->root.'/storage') && is_writable($this->root.'/bootstrap/cache');
        fwrite(STDOUT, 'Folders    '.($writable ? 'OK' : 'storage/ and bootstrap/cache must be writable').PHP_EOL);

        $phpOk = $best !== null;
        $nodeNeed = $assets || ($node !== null && version_compare($node, '20.0.0', '>='));
        $ok = $phpOk && $writable && $nodeNeed;

        fwrite(STDOUT, str_repeat('-', 60).PHP_EOL);
        fwrite(STDOUT, $ok ? "Ready to install.\n" : "Fix the lines above, then run doctor again.\n");

        if ($failHard && ! $ok) {
            return 1;
        }

        return $ok ? 0 : 1;
    }

    /**
     * @param  list<string>  $args
     */
    public function install(array $args): int
    {
        $lock = $this->root.'/storage/app/installed.lock';
        if (is_file($lock)) {
            fwrite(STDOUT, "Already installed. Start the shop:\n  php artisan serve\nor keep using Apache/nginx/IIS pointed at this folder.\n");

            return 0;
        }

        if ($this->doctor(failHard: true) !== 0) {
            return 1;
        }

        $php = (new FindPhp($this->root))->best();
        $phpBin = $php['bin'] ?? PHP_BINARY;

        if ($this->ensureComposer($phpBin) !== 0) {
            return 1;
        }

        fwrite(STDOUT, "Installing Composer packages…\n");
        if ($this->runCommand($this->composerInstall($phpBin)) !== 0) {
            fwrite(STDERR, "composer install failed.\n");

            return 1;
        }

        if (! is_file($this->root.'/.env') && is_file($this->root.'/.env.example')) {
            copy($this->root.'/.env.example', $this->root.'/.env');
        }

        if (! is_file($this->root.'/public/build/manifest.json')) {
            fwrite(STDOUT, "Building admin and storefront (npm)…\n");
            $npm = $this->which('npm');
            if ($npm === null) {
                fwrite(STDERR, "Node/npm 20+ is required for a source checkout. Install Node from https://nodejs.org then retry.\n");

                return 1;
            }
            if ($this->runCommand(escapeshellarg($npm).' install --ignore-scripts') !== 0) {
                return 1;
            }
            if ($this->runCommand(escapeshellarg($npm).' run build') !== 0) {
                return 1;
            }
        }

        $artisan = array_values(array_filter($args, fn (string $arg) => ! in_array($arg, ['--cli', 'cli', 'install'], true)));
        $cmd = escapeshellarg($phpBin).' '.escapeshellarg($this->root.'/artisan').' wento:install '.implode(' ', array_map('escapeshellarg', $artisan));
        fwrite(STDOUT, "Installing shop…\n");

        return $this->runCommand($cmd);
    }

    public function wizard(): int
    {
        $php = (new FindPhp($this->root))->best();
        if ($php === null) {
            fwrite(STDERR, $this->phpMissingMessage(new FindPhp($this->root)));

            return 1;
        }

        $url = 'http://127.0.0.1:8099/';
        fwrite(STDOUT, 'Using '.$php['bin'].' ('.$php['version'].')'.PHP_EOL);
        fwrite(STDOUT, 'Opening '.$url."  Keep this window open.\n");
        $this->openBrowser($url);
        $server = $this->root.DIRECTORY_SEPARATOR.'server.php';
        passthru(
            escapeshellarg($php['bin']).' -S 127.0.0.1:8099 -t '.escapeshellarg($this->root).' '.escapeshellarg($server),
            $code
        );

        return $code;
    }

    private function ensureComposer(string $phpBin): int
    {
        if ($this->composerCommand() !== null) {
            return 0;
        }

        fwrite(STDOUT, "Composer not on PATH — downloading composer.phar…\n");
        $installer = @file_get_contents('https://getcomposer.org/installer');
        if ($installer === false) {
            fwrite(STDERR, "Could not download Composer. Install Composer from https://getcomposer.org and retry.\n");

            return 1;
        }

        $setup = $this->root.'/composer-setup.php';
        file_put_contents($setup, $installer);
        $code = $this->runCommand(escapeshellarg($phpBin).' '.escapeshellarg($setup).' --install-dir='.escapeshellarg($this->root).' --filename=composer.phar');
        @unlink($setup);

        return $code;
    }

    private function composerInstall(string $phpBin): string
    {
        $phar = $this->root.'/composer.phar';
        if (is_file($phar)) {
            return escapeshellarg($phpBin).' '.escapeshellarg($phar).' install --no-interaction --prefer-dist';
        }

        return 'composer install --no-interaction --prefer-dist';
    }

    private function composerCommand(): ?string
    {
        if (is_file($this->root.'/composer.phar')) {
            return $this->root.'/composer.phar';
        }

        return $this->which('composer');
    }

    private function nodeVersion(): ?string
    {
        $node = $this->which('node');
        if ($node === null) {
            return null;
        }
        exec(escapeshellarg($node).' -v', $out, $code);
        if ($code !== 0 || ! isset($out[0])) {
            return null;
        }

        return ltrim(trim($out[0]), 'v');
    }

    private function mysqlReachable(): bool
    {
        $fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 1.0);

        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    private function which(string $name): ?string
    {
        $cmd = stripos(PHP_OS, 'WIN') === 0 ? 'where '.escapeshellarg($name).' 2>NUL' : 'command -v '.escapeshellarg($name).' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code !== 0 || ! isset($out[0]) || trim($out[0]) === '') {
            return null;
        }

        return trim($out[0]);
    }

    private function runCommand(string $cmd): int
    {
        $previous = getcwd();
        chdir($this->root);
        passthru($cmd, $code);
        if (is_string($previous) && $previous !== '') {
            chdir($previous);
        }

        return $code;
    }

    private function openBrowser(string $url): void
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            exec('start "" '.escapeshellarg($url).' 2>NUL');

            return;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            exec('open '.escapeshellarg($url).' >/dev/null 2>&1 &');

            return;
        }
        exec('xdg-open '.escapeshellarg($url).' >/dev/null 2>&1 &');
    }

    private function phpMissingMessage(FindPhp $php): string
    {
        $found = [];
        foreach ($php->inspectAll() as $row) {
            $found[] = $row['bin'].' ('.$row['version'].')';
        }
        $extra = $found !== [] ? "Found but not usable:\n  ".implode("\n  ", $found)."\n\n" : '';

        return "PHP ".FindPhp::NEED."+ with pdo_mysql was not found.\n".
            $extra.
            "Any of these is fine:\n".
            "  • XAMPP / WAMP / Laragon / MAMP with PHP 8.3+\n".
            "  • System PHP (Ubuntu: sudo apt install php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-gd php8.3-zip php8.3-sqlite3)\n".
            "  • Docker: docker compose up --build\n".
            "  • Portable PHP: create php.path and put the full path on the first line\n".
            "Set WENTO_PHP=/full/path/to/php if you already know it.\n";
    }

    private function productName(): string
    {
        return 'WentoCart';
    }
}
