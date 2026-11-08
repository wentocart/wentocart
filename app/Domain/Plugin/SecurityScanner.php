<?php

namespace App\Domain\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class SecurityScanner
{
    /** @var list<string> */
    protected array $allowed = [
        'json', 'php', 'css', 'js', 'mjs', 'txt', 'md', 'svg',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'woff', 'woff2',
    ];

    /** @var list<string> */
    protected array $forbiddenCalls = [
        'eval', 'assert', 'create_function', 'extract',
        'exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen', 'pcntl_exec',
        'dl', 'phpinfo', 'posix_kill', 'posix_getpwuid',
        'curl_exec', 'curl_multi_exec', 'file_get_contents', 'file_put_contents',
        'fopen', 'fwrite', 'unlink', 'rmdir', 'mkdir', 'chmod', 'chown', 'symlink',
        'move_uploaded_file', 'unserialize', 'highlight_file', 'show_source',
        'stream_socket_client', 'fsockopen', 'pfsockopen', 'socket_create', 'mail',
        'base_path', 'app_path', 'storage_path', 'public_path', 'config_path',
        'env', 'getenv', 'putenv', 'passthru',
        'mysqli_connect', 'mysql_query',
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
        'register_shutdown_function', 'register_tick_function',
        'ini_set', 'ini_alter', 'parse_ini_file',
        'proc_nice', 'proc_terminate', 'proc_get_status',
    ];

    /** @var list<string> */
    protected array $forbiddenSubstrings = [
        'Illuminate\\',
        'App\\Http\\',
        'App\\Models\\',
        'App\\Providers\\',
        'DB::',
        'Artisan::',
        'File::',
        'Storage::',
        '$_GET',
        '$_POST',
        '$_REQUEST',
        '$_COOKIE',
        '$_SERVER',
        '$_ENV',
        '$_FILES',
        '$_SESSION',
        'new PDO',
        'ReflectionClass',
        'ReflectionFunction',
        'ReflectionMethod',
        'ReflectionObject',
    ];

    public function assertSafeTree(string $root, PluginManifest $manifest): void
    {
        $phpFiles = 0;
        foreach (File::allFiles($root) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (str_contains($rel, '..')) {
                throw ValidationException::withMessages(['zip' => 'Unsafe path in the app zip.']);
            }
            $ext = strtolower($file->getExtension());
            if ($ext === '' || ! in_array($ext, $this->allowed, true)) {
                throw ValidationException::withMessages(['zip' => 'Remove .'.$ext.' files. Apps may only ship declared static types plus PHP under src/.']);
            }
            if (in_array($ext, ['js', 'mjs'], true)) {
                if ($manifest->type === 'safe' && ! str_starts_with($rel, 'assets/')) {
                    throw ValidationException::withMessages(['zip' => 'JavaScript is only allowed under assets/ on this app own pages.']);
                }
                if ($manifest->type === 'safe' && ! $manifest->can('admin.assets') && ! $manifest->can('storefront.pages')) {
                    throw ValidationException::withMessages(['zip' => 'Safe apps cannot ship JavaScript unless they declare admin.assets or storefront.pages.']);
                }
                $this->assertJs((string) File::get($file->getPathname()), $rel);
            }
            if ($ext === 'php') {
                $phpFiles++;
                if (! str_starts_with($rel, 'src/')) {
                    throw ValidationException::withMessages(['zip' => 'PHP is only allowed under src/.']);
                }
                $this->assertPhp((string) File::get($file->getPathname()), $rel, $manifest);
            }
        }
        if ($phpFiles === 0 || ! is_file($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Plugin.php')) {
            throw ValidationException::withMessages(['zip' => 'A Safe app must include src/Plugin.php.']);
        }
        $maxPhp = (int) config('wento.apps.max_safe_php_files', 16);
        if ($manifest->type === 'safe' && $phpFiles > $maxPhp) {
            throw ValidationException::withMessages(['zip' => 'Safe apps may ship at most '.$maxPhp.' PHP files.']);
        }
        $maxCertified = (int) config('wento.apps.max_certified_php_files', 48);
        if ($manifest->type === 'certified' && $phpFiles > $maxCertified) {
            throw ValidationException::withMessages(['zip' => 'Certified apps may ship at most '.$maxCertified.' PHP files.']);
        }
    }

    public function assertPhp(string $code, string $rel, PluginManifest $manifest): void
    {
        $certifiedPayment = $manifest->type === 'certified'
            && ($manifest->can(Capabilities::PAYMENT_DRIVER)
                || $manifest->can(Capabilities::SHIPPING_DRIVER)
                || $manifest->can(Capabilities::HTTP_OUTBOUND));

        foreach ($this->forbiddenSubstrings as $needle) {
            if (str_contains($code, $needle)) {
                throw ValidationException::withMessages(['zip' => $rel.' uses a blocked API. Use the Module Context.']);
            }
        }

        if ($certifiedPayment) {
            if (preg_match('/App\\\\(?!Domain\\\\(?:Plugin|Payment|Order|Shipping|Checkout|Localization|Settings)\\\\)/', $code)) {
                throw ValidationException::withMessages(['zip' => $rel.' cannot import core App classes outside Payment/Order/Shipping/Checkout/Localization/Settings/Plugin.']);
            }
        } elseif (preg_match('/App\\\\(?!Domain\\\\Plugin\\\\)/', $code)) {
            throw ValidationException::withMessages(['zip' => $rel.' cannot import core App classes.']);
        }

        $forbiddenCalls = $this->forbiddenCalls;
        if ($certifiedPayment) {
            // Outbound HTTP must go through PluginHttp (Illuminate Http under the hood).
            // Certified drivers may write plugin-scoped logs under storage/app/plugin-logs.
            $forbiddenCalls = array_values(array_filter(
                $forbiddenCalls,
                fn ($c) => ! in_array($c, ['file_get_contents', 'fopen', 'fwrite', 'mkdir', 'unlink', 'storage_path'], true)
            ));
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                if ($token === '`') {
                    throw ValidationException::withMessages(['zip' => $rel.' cannot use shell backticks.']);
                }
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                throw ValidationException::withMessages(['zip' => $rel.' cannot include or eval PHP.']);
            }
            if ($id === T_STRING && in_array(strtolower($text), $forbiddenCalls, true)) {
                throw ValidationException::withMessages(['zip' => $rel.' cannot call '.$text.'.']);
            }
            if ($id === T_VARIABLE) {
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (($tokens[$j] ?? null) === '(') {
                    throw ValidationException::withMessages(['zip' => $rel.' cannot use variable functions.']);
                }
            }
        }

        if ($rel === 'src/Plugin.php') {
            if (! str_contains($code, 'BasePlugin')) {
                throw ValidationException::withMessages(['zip' => 'src/Plugin.php must extend BasePlugin.']);
            }
            $ns = 'namespace '.implode('\\', array_slice(explode('\\', $manifest->className()), 0, -1)).';';
            if (! str_contains($code, $ns) || ! preg_match('/class\s+Plugin\b/', $code)) {
                throw ValidationException::withMessages(['zip' => 'src/Plugin.php must declare '.$manifest->className().'.']);
            }
        }
    }

    public function assertJs(string $code, string $rel): void
    {
        if (preg_match('/\beval\s*\(|new\s+Function\s*\(|document\.write\s*\(|innerHTML\s*=|javascript:/i', $code)) {
            throw ValidationException::withMessages(['zip' => $rel.' contains a blocked JavaScript pattern.']);
        }
    }
}
