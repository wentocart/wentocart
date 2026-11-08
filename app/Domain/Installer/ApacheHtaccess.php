<?php

namespace App\Domain\Installer;

/**
 * Apache per-folder installs need RewriteBase or /admin/login 404s at the document root.
 */
final class ApacheHtaccess
{
    public static function baseFromUrl(string $appUrl): string
    {
        $appUrl = trim($appUrl);
        // Schemeless values like "shop.example/shop" must not become RewriteBase /shop.example/...
        if ($appUrl !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $appUrl)) {
            $appUrl = 'http://'.$appUrl;
        }

        $path = parse_url($appUrl, PHP_URL_PATH);
        $path = is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '';
        if ($path === '') {
            return '/';
        }

        return '/'.$path.'/';
    }

    /**
     * A path-less APP_URL must not point Apache at the XAMPP dashboard.
     * Keep the folder this copy actually lives in.
     */
    public static function resolvedBase(string $root, string $appUrl): string
    {
        $base = self::baseFromUrl($appUrl);
        if ($base !== '/') {
            return $base;
        }

        $fromDocumentRoot = self::baseFromDocumentRoot($root);
        if ($fromDocumentRoot !== null && $fromDocumentRoot !== '/') {
            return $fromDocumentRoot;
        }

        // The install worker is CLI, so DOCUMENT_ROOT is empty. A copied
        // RewriteBase must not win over the folder this copy actually occupies
        // under htdocs, including a shop that is the web root itself.
        $fromStack = self::baseFromWebStack($root);
        if ($fromStack !== null) {
            return $fromStack;
        }

        $existing = self::readRewriteBase($root);

        return ($existing !== null && $existing !== '/') ? $existing : '/';
    }

    public static function baseFromDocumentRoot(string $root): ?string
    {
        $doc = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (! is_string($doc) || $doc === '') {
            return null;
        }

        $rootPath = str_replace('\\', '/', realpath($root) ?: $root);
        $docPath = rtrim(str_replace('\\', '/', realpath($doc) ?: $doc), '/');
        if ($docPath === '' || ! str_starts_with(strtolower($rootPath), strtolower($docPath).'/')) {
            return null;
        }

        $relative = trim(substr($rootPath, strlen($docPath)), '/');

        return $relative === '' ? '/' : '/'.$relative.'/';
    }

    /**
     * Folder under htdocs / www / public_html when Apache's httpd.conf sits next to that directory.
     * Covers Windows XAMPP (apache/conf) and Linux XAMPP (etc/httpd.conf).
     */
    public static function baseFromWebStack(string $root): ?string
    {
        $shop = str_replace('\\', '/', realpath($root) ?: $root);
        $current = $shop;
        for ($guard = 0; $guard < 8; $guard++) {
            $parent = dirname($current);
            if ($parent === $current || $parent === '' || $parent === '.' || $parent === '/') {
                break;
            }
            $name = strtolower(basename($current));
            if (in_array($name, ['htdocs', 'www', 'public_html'], true) && self::stackHasHttpd($parent)) {
                $doc = rtrim(str_replace('\\', '/', $current), '/');
                $shopKey = strtolower($shop);
                $docKey = strtolower($doc);
                if ($shopKey !== $docKey && ! str_starts_with($shopKey, $docKey.'/')) {
                    return null;
                }
                $relative = trim(substr($shop, strlen($doc)), '/');

                return $relative === '' ? '/' : '/'.$relative.'/';
            }
            $current = $parent;
        }

        return null;
    }

    private static function stackHasHttpd(string $stackRoot): bool
    {
        $candidates = [
            $stackRoot.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'httpd.conf',
            $stackRoot.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'httpd.conf',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return true;
            }
        }
        foreach (glob($stackRoot.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'httpd.conf') ?: [] as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    public static function readRewriteBase(string $root): ?string
    {
        $file = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($file) || ! preg_match('/^\s*RewriteBase\s+(\S+)\s*$/mi', (string) file_get_contents($file), $match)) {
            return null;
        }

        return $match[1];
    }

    public static function write(string $root, string $appUrl): void
    {
        $base = self::resolvedBase($root, $appUrl);
        $rootFile = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'.htaccess';
        $publicFile = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess';
        $storageFile = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'.htaccess';
        $publicBase = $base === '/' ? '/' : rtrim($base, '/').'/public/';

        $existing = is_file($rootFile) ? (string) file_get_contents($rootFile) : '';
        $bridge = self::extractPhpCgiBridge($existing);
        file_put_contents($rootFile, $bridge.self::rootContents($base));
        file_put_contents($storageFile, self::storageContents());

        if (is_file($publicFile)) {
            $publicBody = self::withRewriteBase((string) file_get_contents($publicFile), $publicBase);
            file_put_contents($publicFile, self::appendStaticCache($publicBody));
        }
    }

    /**
     * Keep Apache RewriteBase aligned with APP_URL after git checkout/pull.
     * Folder installs break when the committed template (RewriteBase /) is restored.
     *
     * @return bool True when files were rewritten.
     */
    public static function ensure(string $root, string $appUrl): bool
    {
        $base = self::resolvedBase($root, $appUrl);
        $rootFile = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'.htaccess';
        $publicFile = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess';
        $fallback = $base === '/' ? '/index.php' : rtrim($base, '/').'/index.php';
        $publicBase = $base === '/' ? '/' : rtrim($base, '/').'/public/';

        $rootOk = is_file($rootFile)
            && preg_match('/^\s*RewriteBase\s+'.preg_quote($base, '/').'\s*$/mi', (string) file_get_contents($rootFile)) === 1
            && str_contains((string) file_get_contents($rootFile), 'FallbackResource '.$fallback);

        $publicOk = ! is_file($publicFile)
            || preg_match('/^\s*RewriteBase\s+'.preg_quote($publicBase, '/').'\s*$/mi', (string) file_get_contents($publicFile)) === 1;

        $changed = false;
        if (! $rootOk || ! $publicOk) {
            self::write($root, $appUrl);
            $changed = true;
        }

        // Pick up deny-rule improvements on shops installed before they shipped.
        $rootBody = is_file($rootFile) ? (string) file_get_contents($rootFile) : '';
        if ($rootBody !== '' && ! str_contains($rootBody, 'plugin-packs')) {
            self::write($root, $appUrl);
            $changed = true;
        }
        if (is_file($publicFile)) {
            $publicBody = (string) file_get_contents($publicFile);
            if (! str_contains($publicBody, 'WENTO-DENY-SENSITIVE')) {
                file_put_contents($publicFile, self::injectPublicDeny($publicBody));
                $changed = true;
            }
        }

        if (is_file($rootFile) && ! str_contains((string) file_get_contents($rootFile), 'WENTO-STATIC-CACHE')) {
            file_put_contents($rootFile, self::appendStaticCache((string) file_get_contents($rootFile)));
            $changed = true;
        }
        if (is_file($publicFile) && ! str_contains((string) file_get_contents($publicFile), 'WENTO-STATIC-CACHE')) {
            file_put_contents($publicFile, self::appendStaticCache((string) file_get_contents($publicFile)));
            $changed = true;
        }

        return $changed;
    }

    /**
     * Keep a local PHP CGI bridge (e.g. XAMPP PHP 7 + system PHP 8.3) when rewriting .htaccess.
     * Without this, the installer overwrites .htaccess and Apache falls back to an old mod_php,
     * so setup/api.php dies and the UI looks stuck at 0 tables while the CLI worker continues.
     */
    public static function extractPhpCgiBridge(string $htaccess): string
    {
        if (! str_contains($htaccess, 'application/x-httpd-php83') && ! str_contains($htaccess, 'php83.cgi')) {
            return '';
        }

        if (! preg_match(
            '/((?:#.*\R)*)'
            .'(?:<IfModule\s+mod_php7\.c>[\s\S]*?<\/IfModule>\s*)?'
            .'(?:Options\s+\+ExecCGI\s*)?'
            .'(?:AddHandler\s+cgi-script[^\n]*\n)?'
            .'(?:RemoveHandler\s+\.php\s*)?'
            .'(?:RemoveType\s+\.php\s*)?'
            .'(?:AddType\s+application\/x-httpd-php83[^\n]*\n)?'
            .'Action\s+application\/x-httpd-php83[^\n]*\n/i',
            $htaccess,
            $m
        )) {
            return '';
        }

        $block = trim($m[0]);

        return $block === '' ? '' : $block."\n\n";
    }

    /**
     * Rules used the moment the zip finishes unpacking, before the wizard knows
     * the shop URL. RewriteBase / and FallbackResource /index.php send a removed
     * helper such as _extract.php to the server document root. PHP then prints
     * a plain "File not found." Sensitive paths stay forbidden.
     */
    public static function preInstallContents(): string
    {
        foreach ([
            __DIR__.DIRECTORY_SEPARATOR.'preinstall.htaccess',
            dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'package'.DIRECTORY_SEPARATOR.'preinstall.htaccess',
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $body = file_get_contents($path);
            if (is_string($body) && $body !== '') {
                return str_replace("\r\n", "\n", $body);
            }
        }

        throw new \RuntimeException('Missing preinstall.htaccess.');
    }

    public static function rootContents(string $base): string
    {
        $fallback = $base === '/' ? '/index.php' : rtrim($base, '/').'/index.php';

        return <<<HTACCESS
<IfModule mod_autoindex.c>
    Options -Indexes
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase {$base}
    # Never publish secrets or application source. The wizard lives in setup/ and stays reachable.
    RewriteRule (^|/)\.env - [F,L]
    RewriteRule (^|/)\.git - [F,L]
    RewriteRule \.sql$ - [F,L]
    RewriteRule \.map$ - [F,L]
    RewriteRule ^(database|vendor|node_modules|app|bootstrap|config|routes|resources|scripts|package|extensions|tests|docs|storefront|docker|plugin-packs)(/|$) - [F,L]
    RewriteRule ^(artisan|composer\.(json|lock)|phpunit\.xml)$ - [F,L]
    RewriteRule ^storage/(logs|framework)(/|$) - [F,L]
    # Admin must hit Laravel. Do not let Apache look for /admin/login as a file.
    RewriteRule ^admin(/.*)?$ index.php [L]
    RewriteRule ^(build|themes|plugins|hot|images|i18n)(/.*)?$ public/$1$2 [L]
    RewriteRule ^storage/(.*)$ public/storage/$1 [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
<IfModule mod_dir.c>
    DirectoryIndex index.php index.html
    FallbackResource {$fallback}
</IfModule>

HTACCESS
            .self::staticCacheBlock();
    }

    /**
     * Hashed Vite files stay in the browser for a year. Other images and styles refresh within a day.
     * Apache skips a block when that module is not loaded.
     */
    public static function staticCacheBlock(): string
    {
        return <<<'HTACCESS'
# WENTO-STATIC-CACHE
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|mjs|svg|png|jpe?g|gif|webp|ico|woff2)$">
        Header set Cache-Control "public, max-age=86400"
    </FilesMatch>
    <FilesMatch "-[A-Za-z0-9]{8}\.(css|js|mjs|woff2)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/css application/javascript application/json image/svg+xml text/html
</IfModule>

HTACCESS;
    }

    public static function appendStaticCache(string $htaccess): string
    {
        if (str_contains($htaccess, 'WENTO-STATIC-CACHE')) {
            return $htaccess;
        }

        return rtrim($htaccess)."\n\n".self::staticCacheBlock();
    }

    /**
     * Deny block injected into public/.htaccess (and shipped in the committed
     * template). Published theme dist/ folders must stay executable-free:
     * no sourcemaps, dotfiles, or server-side scripts, while index.php and
     * the hashed runtime assets keep serving.
     */
    public static function publicDenyBlock(): string
    {
        return <<<'HTACCESS'
# WENTO-DENY-SENSITIVE
# Published theme/plugin folders must never leak sourcemaps, dotfiles, or scripts.
RewriteRule ^\.well-known/ - [L]
RewriteRule (^|/)\. - [F,L]
RewriteRule \.map$ - [F,L]
RewriteCond %{REQUEST_URI} !/index\.php$
RewriteRule \.(php|phtml|phar|cgi|pl|py|sh|sql|env)$ - [F,L]

HTACCESS;
    }

    public static function injectPublicDeny(string $htaccess): string
    {
        if (str_contains($htaccess, 'WENTO-DENY-SENSITIVE')) {
            return $htaccess;
        }

        $block = self::publicDenyBlock();
        if (preg_match('/^\s*RewriteBase\s+\S+.*$/mi', $htaccess)) {
            return preg_replace('/^\s*RewriteBase\s+\S+.*$/mi', "$0\n".$block, $htaccess, 1) ?? ($htaccess."\n".$block);
        }

        return preg_replace(
            '/(RewriteEngine\s+On)/i',
            "$1\n".$block,
            $htaccess,
            1
        ) ?? ($htaccess."\n".$block);
    }

    public static function storageContents(): string
    {
        return <<<'HTACCESS'
# Folder Apache installs map /storage/* onto this directory (same name as
# the public disk URL). Deny logs/cache; send media to storage/app/public.

<IfModule mod_autoindex.c>
    Options -Indexes
</IfModule>

<IfModule mod_authz_core.c>
    Require all granted
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^app/public/(.*)$ - [L]
    RewriteRule ^(logs|framework|app)(/|$) - [F,L]
    RewriteRule ^(.*)$ app/public/$1 [L]
</IfModule>

HTACCESS;
    }

    public static function withRewriteBase(string $htaccess, string $base): string
    {
        $line = 'RewriteBase '.$base;
        if (preg_match('/^\s*RewriteBase\s+\S+/mi', $htaccess)) {
            return preg_replace('/^\s*RewriteBase\s+\S+/mi', $line, $htaccess, 1) ?? $htaccess;
        }

        return preg_replace(
            '/(RewriteEngine\s+On)/i',
            "$1\n    ".$line,
            $htaccess,
            1
        ) ?? ($htaccess."\n".$line."\n");
    }
}
