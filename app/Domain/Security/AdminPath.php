<?php

namespace App\Domain\Security;

use App\Support\WebBase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AdminPath
{
    public static function segment(): string
    {
        $segment = (string) config('wento.admin_prefix', 'admin');

        return self::isWellFormed($segment) ? $segment : 'admin';
    }

    public static function write(string $segment): string
    {
        $segment = self::normalize($segment);
        $path = self::filePath();
        $contents = "<?php\n\n/**\n * Public admin address. Only this segment changes.\n * /admin/login becomes /{segment}/login.\n * Recover a forgotten address by editing segment here.\n */\nreturn [\n    'segment' => ".var_export($segment, true).",\n];\n";
        File::ensureDirectoryExists(dirname($path));
        if (File::put($path, $contents) === false) {
            throw ValidationException::withMessages([
                'segment' => 'The admin address could not be saved. Check that config/ is writable.',
            ]);
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        $cached = base_path('bootstrap/cache/config.php');
        if (is_file($cached)) {
            @unlink($cached);
        }
        config([
            'wento.admin_prefix' => $segment,
            'admin-path.segment' => $segment,
        ]);

        return $segment;
    }

    public static function filePath(): string
    {
        $override = config('wento.admin_path_file');

        return is_string($override) && $override !== ''
            ? $override
            : config_path('admin-path.php');
    }

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [
            'api', 'install', 'installed', 'up', 'storage', 'build', 'setup', 'sanctum',
            'sitemap', 'feeds', 'themes', 'plugins', 'clone', 'download', 'pay',
            'public', 'vendor', 'login', 'account', 'cart', 'checkout', 'register',
            'password', 'index.php', 'favicon.ico', 'robots.txt',
        ];
    }

    public static function normalize(string $segment): string
    {
        $segment = trim($segment);
        if (! self::isWellFormed($segment) || in_array(strtolower($segment), self::reserved(), true)) {
            throw ValidationException::withMessages([
                'segment' => 'Use 3–40 letters, numbers, and hyphens. Start with a letter. Names already used by the shop are not available.',
            ]);
        }

        return $segment;
    }

    public static function rewriteInbound(Request $request): Request
    {
        $segment = self::segment();
        if ($segment === 'admin') {
            return $request;
        }

        $path = trim($request->path(), '/');
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            abort(404);
        }
        if ($path === $segment) {
            return self::retarget($request, 'admin');
        }
        if (str_starts_with($path, $segment.'/')) {
            return self::retarget($request, 'admin'.substr($path, strlen($segment)));
        }

        return $request;
    }

    public static function rewriteOutbound(Response $response): Response
    {
        if (self::segment() === 'admin') {
            return $response;
        }

        foreach (['Location', 'X-Inertia-Location'] as $header) {
            $location = $response->headers->get($header);
            if (is_string($location) && $location !== '') {
                $response->headers->set($header, self::toPublicReference($location));
            }
        }

        if ($response->headers->has('X-Inertia')) {
            $body = $response->getContent();
            if (is_string($body) && $body !== '') {
                $data = json_decode($body, true);
                if (is_array($data) && isset($data['url']) && is_string($data['url'])) {
                    $data['url'] = self::toPublicReference($data['url']);
                    $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return $response;
    }

    public static function toPublicReference(string $url): string
    {
        if (self::segment() === 'admin' || $url === '' || ! str_contains($url, '/admin')) {
            return $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['path'])) {
                return $url;
            }
            $path = self::swapPrefix($parts['path']);
            if ($path === $parts['path']) {
                return $url;
            }
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

            return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').$port.$path.$query.$fragment;
        }

        $fragment = '';
        $query = '';
        $path = $url;
        if (str_contains($path, '#')) {
            [$path, $fragment] = explode('#', $path, 2);
            $fragment = '#'.$fragment;
        }
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
            $query = '?'.$query;
        }

        return self::swapPrefix($path).$query.$fragment;
    }

    public static function publicAbsolute(string $suffix): string
    {
        $suffix = '/'.ltrim($suffix, '/');

        // url() already includes the shop folder (/shop). Do not prefix it again.
        return url('/'.self::segment().$suffix);
    }

    private static function swapPrefix(string $path): string
    {
        $segment = self::segment();
        $base = WebBase::path();
        $from = $base.'/admin';
        if ($path === $from || str_starts_with($path, $from.'/')) {
            return $base.'/'.$segment.substr($path, strlen($from));
        }

        return $path;
    }

    private static function retarget(Request $request, string $internal): Request
    {
        $internal = '/'.ltrim($internal, '/');
        $query = $request->getQueryString();
        $base = rtrim((string) $request->getBaseUrl(), '/');
        $uri = $base.$internal.($query !== null && $query !== '' ? '?'.$query : '');
        $request->server->set('REQUEST_URI', $uri);
        $reflection = new \ReflectionClass($request);
        foreach (['pathInfo', 'requestUri', 'baseUrl', 'basePath'] as $name) {
            if (! $reflection->hasProperty($name)) {
                continue;
            }
            $property = $reflection->getProperty($name);
            $property->setValue($request, null);
        }

        return $request;
    }

    private static function isWellFormed(string $segment): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9-]{2,39}$/', $segment) === 1;
    }
}
