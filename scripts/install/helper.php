<?php

namespace Wento\Install;

/**
 * Choose a PHP command-line binary that meets the installer version and extensions.
 * A php.path file in the shop folder is checked first, then the system PATH, then common install locations.
 */
final class FindPhp
{
    public const NEED = '8.3.0';

    /** @var list<string> */
    public const EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'gd', 'zip'];

    public function __construct(private readonly string $root) {}

    /**
     * @return array{bin: string, version: string, extensions: list<string>, missing: list<string>, ok: bool}|null
     */
    public function best(): ?array
    {
        return self::pickBest($this->inspectAll());
    }

    /**
     * @param  list<array{bin: string, version: string, extensions: list<string>, missing: list<string>, ok: bool}>  $rows
     * @return array{bin: string, version: string, extensions: list<string>, missing: list<string>, ok: bool}|null
     */
    public static function pickBest(array $rows): ?array
    {
        $ok = array_values(array_filter($rows, fn (array $row) => $row['ok']));
        if ($ok === []) {
            return null;
        }

        usort($ok, function (array $a, array $b) {
            $cmp = version_compare($b['version'], $a['version']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strlen($a['bin']) <=> strlen($b['bin']);
        });

        return $ok[0];
    }

    /**
     * @return list<array{bin: string, version: string, extensions: list<string>, missing: list<string>, ok: bool, error?: string}>
     */
    public function inspectAll(): array
    {
        $seen = [];
        $rows = [];
        foreach ($this->candidateBins() as $bin) {
            $real = realpath($bin) ?: $bin;
            $key = strtolower($real);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $info = $this->inspect($bin);
            if ($info !== null) {
                $rows[] = $info;
            }
        }

        return $rows;
    }

    /**
     * @return array{bin: string, version: string, extensions: list<string>, missing: list<string>, ok: bool, error?: string}|null
     */
    public function inspect(string $bin): ?array
    {
        if (! is_file($bin) && ! is_executable($bin)) {
            return null;
        }

        $extList = implode(',', self::EXTENSIONS);
        $code = 'echo json_encode([PHP_VERSION, PHP_SAPI, array_values(array_filter(explode(",", "'.$extList.'"), "extension_loaded"))]);';
        $cmd = escapeshellarg($bin).' -r '.escapeshellarg($code).' 2>/dev/null';
        if (stripos(PHP_OS, 'WIN') === 0) {
            $cmd = escapeshellarg($bin).' -r '.escapeshellarg($code).' 2>NUL';
        }
        $out = [];
        exec($cmd, $out, $status);
        $raw = trim(implode("\n", $out));
        $decoded = json_decode($raw, true);
        if ($status !== 0 || ! is_array($decoded) || count($decoded) < 3) {
            return [
                'bin' => $bin,
                'version' => 'unknown',
                'extensions' => [],
                'missing' => self::EXTENSIONS,
                'ok' => false,
                'error' => 'Could not read version',
            ];
        }

        $version = (string) $decoded[0];
        $sapi = (string) $decoded[1];
        $present = array_values(array_map('strval', (array) $decoded[2]));
        $missing = array_values(array_diff(self::EXTENSIONS, $present));
        $ok = version_compare($version, self::NEED, '>=')
            && $missing === []
            && ! in_array($sapi, ['cgi', 'cgi-fcgi', 'fpm-fcgi'], true);

        return [
            'bin' => $bin,
            'version' => $version,
            'extensions' => $present,
            'missing' => $missing,
            'ok' => $ok,
        ];
    }

    /**
     * @return list<string>
     */
    public function candidateBins(): array
    {
        $bins = [];
        $forced = getenv('WENTO_PHP') ?: '';
        if (is_string($forced) && $forced !== '' && (is_file($forced) || is_executable($forced))) {
            $bins[] = $forced;
        }

        foreach ($this->phpPathFileLines() as $line) {
            $bins[] = $line;
        }

        $bins[] = PHP_BINARY;
        foreach ($this->pathPhpNames() as $name) {
            $found = $this->which($name);
            if ($found !== null) {
                $bins[] = $found;
            }
        }

        foreach ($this->commonLocations() as $path) {
            if (is_file($path)) {
                $bins[] = $path;
            }
        }

        return $bins;
    }

    /**
     * @return list<string>
     */
    private function phpPathFileLines(): array
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'php.path';
        if (! is_file($file)) {
            return [];
        }

        $lines = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = trim($line, "\"'");
            if (is_file($line) || is_executable($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function pathPhpNames(): array
    {
        return ['php', 'php8.5', 'php8.4', 'php8.3', 'php83', 'php84'];
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

    /**
     * @return list<string>
     */
    private function commonLocations(): array
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        $paths = [
            '/usr/bin/php8.5',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            '/opt/homebrew/opt/php@8.5/bin/php',
            '/opt/homebrew/opt/php@8.4/bin/php',
            '/opt/homebrew/opt/php@8.3/bin/php',
            '/usr/local/opt/php@8.3/bin/php',
            '/opt/lampp/bin/php',
            '/Applications/XAMPP/bin/php',
            '/Applications/XAMPP/xamppfiles/bin/php',
        ];

        if ($home !== '') {
            $paths[] = $home.'/.phpenv/shims/php';
            $paths[] = $home.'/.config/herd-lite/bin/php';
            $paths[] = $home.'/.config/herd/bin/php';
            $paths[] = $home.'/Library/Application Support/Herd/bin/php';
        }

        foreach (glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [] as $mamp) {
            $paths[] = $mamp;
        }
        foreach (glob('/opt/homebrew/opt/php@8.*/bin/php') ?: [] as $brew) {
            $paths[] = $brew;
        }

        if (stripos(PHP_OS, 'WIN') === 0) {
            foreach ($this->windowsLocations() as $win) {
                $paths[] = $win;
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function windowsLocations(): array
    {
        $paths = [];
        $env = [
            getenv('ProgramFiles').'\\php\\php.exe',
            getenv('ProgramFiles(x86)').'\\php\\php.exe',
            getenv('LOCALAPPDATA').'\\Programs\\php\\php.exe',
            getenv('LOCALAPPDATA').'\\Programs\\PHP\\php.exe',
            getenv('ChocolateyInstall').'\\bin\\php.exe',
        ];
        foreach ($env as $p) {
            if (is_string($p) && $p !== '' && is_file($p)) {
                $paths[] = $p;
            }
        }

        foreach (range('C', 'Z') as $letter) {
            $d = $letter.':\\';
            if (! is_dir($d)) {
                continue;
            }
            $fixed = [
                $d.'php\\php.exe',
                $d.'PHP\\php.exe',
                $d.'xampp\\php\\php.exe',
                $d.'XAMPP\\php\\php.exe',
                $d.'xampp\\php\\php8.3\\php.exe',
                $d.'laragon\\bin\\php\\php.exe',
            ];
            foreach ($fixed as $p) {
                if (is_file($p)) {
                    $paths[] = $p;
                }
            }
            foreach (['wamp64\\bin\\php', 'wamp\\bin\\php', 'laragon\\bin\\php'] as $rel) {
                foreach (glob($d.$rel.'\\php*\\php.exe') ?: [] as $ver) {
                    $paths[] = $ver;
                }
            }
        }

        return $paths;
    }
}

/**
 * Saved port is kept even when it is already in use.
 * With no preference, return the first closed port from 8099 through 8199.
 */
function listenPort(?int $prefer): ?int
{
    if ($prefer !== null && $prefer >= 1 && $prefer <= 65535) {
        return $prefer;
    }

    for ($port = 8099; $port <= 8199; $port++) {
        $errno = 0;
        $errstr = '';
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if (is_resource($probe)) {
            fclose($probe);
            continue;
        }

        return $port;
    }

    return null;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    $root = __DIR__;
    $parent = dirname($root);
    if (basename($root) === 'install' && basename($parent) === 'scripts') {
        $root = dirname($parent);
    }

    $command = $argv[1] ?? '';
    if ($command === 'php') {
        $finder = new FindPhp($root);
        if (in_array('--json', $argv, true)) {
            echo json_encode([
                'need' => FindPhp::NEED,
                'selected' => $finder->best(),
                'candidates' => $finder->inspectAll(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
            exit($finder->best() ? 0 : 1);
        }
        $best = $finder->best();
        if ($best === null) {
            fwrite(STDERR, 'No PHP '.FindPhp::NEED."+ command-line binary with the required extensions was found.\n");
            exit(1);
        }
        echo $best['bin'], PHP_EOL;
        exit(0);
    }

    if ($command === 'port') {
        $prefer = isset($argv[2]) ? (int) $argv[2] : 0;
        $port = listenPort($prefer >= 1 ? $prefer : null);
        if ($port === null) {
            fwrite(STDERR, "No free port between 8099 and 8199.\n");
            exit(1);
        }
        echo (string) $port;
        exit(0);
    }

    fwrite(STDERR, "Usage: php helper.php php [--json]\n       php helper.php port [port]\n");
    exit(1);
}
