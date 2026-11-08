<?php

namespace App\Domain\Installer\Models;

use App\Domain\Security\AdminPath;
use App\Domain\Store\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ShopUrl extends Model
{
    public const VIRTUAL_MAX = 80;

    protected $fillable = ['store_id', 'scheme', 'domain', 'port', 'physical_uri', 'virtual_uri', 'is_primary'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'port' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function root(): string
    {
        $port = $this->port;
        $default = ($this->scheme === 'https' && $port === 443) || ($this->scheme === 'http' && $port === 80);
        $authority = $this->domain.($port && ! $default ? ':'.$port : '');
        $path = $this->physical_uri === '/' ? '' : rtrim((string) $this->physical_uri, '/');
        $virtual = $this->virtualSegment();
        if ($virtual !== '') {
            $path .= '/'.$virtual;
        }

        return $this->scheme.'://'.$authority.$path;
    }

    /** Install folder only. Admin, sessions, and /build stay here even when the storefront has a virtual path. */
    public function installRoot(): string
    {
        $port = $this->port;
        $default = ($this->scheme === 'https' && (int) $port === 443) || ($this->scheme === 'http' && ((int) $port === 80 || $port === null));
        $authority = $this->domain.($port && ! $default ? ':'.$port : '');
        $path = $this->physical_uri === '/' ? '' : rtrim((string) $this->physical_uri, '/');

        return $this->scheme.'://'.$authority.$path;
    }

    public function virtualSegment(): string
    {
        return trim(self::normalizeVirtual((string) ($this->virtual_uri ?? '')), '/');
    }

    /**
     * @return array{scheme: string, domain: string, port: ?int, physical_uri: string, virtual_uri: string, is_primary: bool}
     */
    public static function attributesFromUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $path = $parts['path'] ?? '/';
        if ($path === '' || $path === '/') {
            $physical = '/';
        } else {
            $physical = rtrim($path, '/').'/';
        }

        return self::alignToInstall([
            'scheme' => $parts['scheme'] ?? 'http',
            'domain' => $parts['host'] ?? 'localhost',
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'physical_uri' => $physical,
            'virtual_uri' => '',
            'is_primary' => true,
        ]);
    }

    /**
     * A folder install lives at one Apache path (for example /shop/).
     * An extra segment such as /8 is a virtual shop path, not a new install folder.
     *
     * @param  array{scheme?: string, domain?: string, port?: ?int, physical_uri?: string, virtual_uri?: string, is_primary?: bool}  $attrs
     * @return array{scheme?: string, domain?: string, port?: ?int, physical_uri: string, virtual_uri: string, is_primary?: bool}
     */
    public static function alignToInstall(array $attrs, ?string $installPhysical = null): array
    {
        $installPhysical = self::normalizePhysical($installPhysical ?? self::installPhysicalUri());
        $physical = self::normalizePhysical((string) ($attrs['physical_uri'] ?? '/'));
        $virtual = trim(self::normalizeVirtual((string) ($attrs['virtual_uri'] ?? '')), '/');
        $install = trim($installPhysical, '/');
        $full = trim($physical, '/');

        if ($install !== '' && ($full === $install || str_starts_with($full, $install.'/'))) {
            $rest = $full === $install ? '' : trim(substr($full, strlen($install)), '/');
            if ($rest !== '' && $virtual === '') {
                $virtual = $rest;
            }
            $physical = $installPhysical;
        }

        $attrs['physical_uri'] = $physical;
        $attrs['virtual_uri'] = self::normalizeVirtual($virtual);

        return $attrs;
    }

    /**
     * @param  array{scheme?: string, domain: string, port?: int|string|null, physical_uri?: string, virtual_uri?: string}  $parts
     * @return array{scheme: string, domain: string, port: ?int, physical_uri: string, virtual_uri: string, is_primary: bool}
     */
    public static function attributesFromParts(array $parts): array
    {
        $port = $parts['port'] ?? null;

        return [
            'scheme' => $parts['scheme'] ?? 'http',
            'domain' => strtolower(trim((string) ($parts['domain'] ?? 'localhost'))),
            'port' => ($port === '' || $port === null) ? null : (int) $port,
            'physical_uri' => self::normalizePhysical((string) ($parts['physical_uri'] ?? '/')),
            'virtual_uri' => self::normalizeVirtual((string) ($parts['virtual_uri'] ?? '')),
            'is_primary' => true,
        ];
    }

    public static function normalizePhysical(string $uri): string
    {
        $uri = trim(str_replace('\\', '/', $uri));
        if ($uri === '' || $uri === '/') {
            return '/';
        }

        return '/'.trim($uri, '/').'/';
    }

    public static function normalizeVirtual(string $uri): string
    {
        $uri = self::tidyVirtualSegment($uri);
        if ($uri === '') {
            return '';
        }

        return $uri.'/';
    }

    /**
     * One public path segment. Capitals stay as typed so /SHOP and /shop are the same shop,
     * and the saved spelling is the address visitors see.
     */
    public static function tidyVirtualSegment(string $uri): string
    {
        $uri = trim(str_replace('\\', '/', $uri));
        $uri = trim($uri, '/');

        return self::formC($uri);
    }

    public static function virtualKey(string $uri): string
    {
        return mb_strtolower(self::tidyVirtualSegment($uri), 'UTF-8');
    }

    public static function virtualUriMessage(string $uri): ?string
    {
        $segment = self::tidyVirtualSegment($uri);
        if ($segment === '') {
            return null;
        }
        if (mb_strlen($segment) > self::VIRTUAL_MAX) {
            return 'Use at most '.self::VIRTUAL_MAX.' characters.';
        }
        if (! preg_match('/^[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*$/u', $segment)) {
            return 'Use letters and numbers, with a hyphen or underscore between words. Capitals are fine. Example: Shop-2, tienda, or 商店.';
        }
        if (in_array(self::virtualKey($segment), self::reservedVirtual(), true)) {
            return 'That path is already used by the shop (admin, cart, api, …).';
        }

        return null;
    }

    /**
     * Remainder after a virtual prefix, or null when the candidate is a different path.
     * Comparison ignores case. An empty string means the candidate is the virtual path itself.
     */
    public static function virtualPathRest(string $candidate, string $virtual): ?string
    {
        $candidate = trim(str_replace('\\', '/', $candidate), '/');
        $virtual = trim(str_replace('\\', '/', $virtual), '/');
        if ($candidate === '' || $virtual === '') {
            return null;
        }

        $cParts = explode('/', $candidate);
        $vParts = explode('/', $virtual);
        if (count($cParts) < count($vParts)) {
            return null;
        }
        foreach ($vParts as $i => $part) {
            if (self::virtualKey($cParts[$i]) !== self::virtualKey($part)) {
                return null;
            }
        }

        return implode('/', array_slice($cParts, count($vParts)));
    }

    public static function applyCanonicalVirtualCase(string $candidate, string $virtual): string
    {
        $cParts = explode('/', trim($candidate, '/'));
        $vParts = explode('/', trim($virtual, '/'));
        foreach ($vParts as $i => $part) {
            if (array_key_exists($i, $cParts)) {
                $cParts[$i] = $part;
            }
        }

        return implode('/', $cParts);
    }

    /**
     * GET/HEAD redirect to the saved spelling when the visitor used different capitals.
     */
    public static function canonicalVirtualRedirect(Request $request): ?string
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        if ($request->is('admin') || $request->is('admin/*') || $request->is('install') || $request->is('install/*')) {
            return null;
        }
        if (! config('wento.installed')) {
            return null;
        }

        try {
            $matched = self::matchFromRequest($request);
        } catch (\Throwable) {
            return null;
        }
        if (! $matched) {
            return null;
        }

        $virtual = $matched->virtualSegment();
        if ($virtual === '') {
            return null;
        }

        $physical = self::normalizePhysical((string) (self::attributesFromRequest($request)['physical_uri'] ?? '/'));
        $full = trim(rawurldecode((string) (parse_url($request->getRequestUri(), PHP_URL_PATH) ?? '')), '/');
        $best = null;
        foreach (self::requestPathCandidates($request, $physical) as $candidate) {
            $candidate = trim($candidate, '/');
            if ($candidate === '' || self::virtualPathRest($candidate, $virtual) === null) {
                continue;
            }
            $haystack = '/'.$full;
            $suffix = '/'.$candidate;
            if ($haystack !== $suffix && ! str_ends_with($haystack, $suffix)) {
                continue;
            }
            if ($best === null || strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }
        if ($best === null) {
            return null;
        }

        $rewritten = self::applyCanonicalVirtualCase($best, $virtual);
        if ($rewritten === $best) {
            return null;
        }

        $haystack = '/'.$full;
        $suffix = '/'.$best;
        $prefix = str_ends_with($haystack, $suffix)
            ? substr($haystack, 0, -strlen($suffix))
            : '';
        $path = $prefix.'/'.$rewritten;
        $query = $request->getQueryString();

        return $path.($query !== null && $query !== '' ? '?'.$query : '');
    }

    private static function formC(string $value): string
    {
        if ($value === '' || ! class_exists(\Normalizer::class)) {
            return $value;
        }

        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $value;
    }

    /**
     * Folder this copy is served from. Prefers the live request, then APP_URL.
     * A second shop on the same copy must keep this path and add a virtual URI.
     */
    public static function installPhysicalUri(?Request $request = null): string
    {
        $request ??= request();
        $fromRequest = self::normalizePhysical((string) (self::attributesFromRequest($request)['physical_uri'] ?? '/'));
        if ($fromRequest !== '/') {
            return $fromRequest;
        }

        $appPath = parse_url((string) config('app.url'), PHP_URL_PATH);

        return is_string($appPath) && $appPath !== '' && $appPath !== '/'
            ? self::normalizePhysical($appPath)
            : '/';
    }

    public static function attributesFromRequest(Request $request): array
    {
        $base = $request->getBasePath();
        $physical = $base === '' ? '/' : rtrim($base, '/').'/';
        $port = $request->getPort();
        $scheme = $request->getScheme();
        $omit = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return [
            'scheme' => $scheme,
            'domain' => $request->getHost(),
            'port' => $omit ? null : $port,
            'physical_uri' => $physical,
            'virtual_uri' => '',
            'is_primary' => true,
        ];
    }

    public static function matchFromRequest(Request $request): ?self
    {
        $attrs = self::attributesFromRequest($request);
        $host = strtolower((string) $attrs['domain']);
        $domains = array_values(array_unique(array_filter([
            $host,
            str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host,
        ])));

        $query = self::query()->whereIn('domain', $domains);
        if ($attrs['port']) {
            $query->where('port', $attrs['port']);
        } else {
            $query->where(function ($q) {
                $q->whereNull('port')->orWhereIn('port', [80, 443]);
            });
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $physical = $attrs['physical_uri'];
        $candidates = self::requestPathCandidates($request, $physical);
        $best = null;
        $bestLen = -1;
        foreach ($rows as $row) {
            $virtual = trim(self::normalizeVirtual((string) ($row->virtual_uri ?? '')), '/');
            if ($virtual === '') {
                continue;
            }
            $rowPhysical = self::normalizePhysical((string) $row->physical_uri);
            // Older saves stored "/" on a folder install, so /shop/web-shop-2 never matched and 404ed.
            $physicalOk = $rowPhysical === $physical || $rowPhysical === '/';
            if (! $physicalOk) {
                continue;
            }
            foreach ($candidates as $pathInfo) {
                if (self::virtualPathRest($pathInfo, $virtual) === null) {
                    continue;
                }
                $len = mb_strlen($virtual);
                if ($len > $bestLen) {
                    $best = $row;
                    $bestLen = $len;
                }
            }
        }
        if ($best) {
            return $best;
        }

        return $rows->first(function (self $row) use ($physical) {
            return self::normalizePhysical((string) $row->physical_uri) === $physical
                && trim(self::normalizeVirtual((string) ($row->virtual_uri ?? '')), '/') === '';
        });
    }

    /**
     * Path tails that may contain a virtual shop segment.
     * Covers both a correct base path and a request that still includes the install folder.
     *
     * @return list<string>
     */
    public static function requestPathCandidates(Request $request, string $requestPhysical): array
    {
        $pathInfo = trim($request->getPathInfo(), '/');
        $candidates = [$pathInfo];
        foreach ([$requestPhysical, self::installPhysicalUri($request)] as $base) {
            $base = trim(self::normalizePhysical($base), '/');
            if ($base === '') {
                continue;
            }
            if ($pathInfo === $base) {
                $candidates[] = '';
            } elseif (str_starts_with($pathInfo, $base.'/')) {
                $candidates[] = trim(substr($pathInfo, strlen($base)), '/');
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    public static function reservedVirtual(): array
    {
        return array_values(array_unique(array_merge(AdminPath::reserved(), [
            'admin', 'login', 'account', 'cart', 'checkout',
        ])));
    }

    public static function rewriteVirtualInbound(Request $request): Request
    {
        if ($request->is('admin') || $request->is('admin/*') || $request->is('install') || $request->is('install/*')) {
            return $request;
        }
        if (! config('wento.installed')) {
            return $request;
        }
        try {
            $matched = self::matchFromRequest($request);
        } catch (\Throwable) {
            return $request;
        }
        if (! $matched) {
            return $request;
        }

        $request->attributes->set('wento.shop_url', $matched);
        $virtual = $matched->virtualSegment();
        if ($virtual === '') {
            return $request;
        }

        $physical = self::normalizePhysical((string) (self::attributesFromRequest($request)['physical_uri'] ?? '/'));
        $rest = null;
        foreach (self::requestPathCandidates($request, $physical) as $candidate) {
            $piece = self::virtualPathRest($candidate, $virtual);
            if ($piece === null) {
                continue;
            }
            $rest = $piece;
            break;
        }
        if ($rest === null) {
            return $request;
        }
        $internal = '/'.ltrim((string) $rest, '/');
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
        app()->instance('request', $request);

        return $request;
    }

    public static function syncFromRequest(Request $request): self
    {
        $attrs = self::attributesFromRequest($request);
        $existing = self::query()->where('is_primary', true)->where(function ($q) {
            $q->whereNull('store_id')->orWhereHas('store', fn ($s) => $s->where('is_default', true));
        })->first();

        if ($existing) {
            unset($attrs['virtual_uri']);
            $existing->fill($attrs);
            $existing->is_primary = true;
            $existing->save();

            return $existing;
        }

        return self::query()->updateOrCreate(['is_primary' => true], $attrs);
    }

    public static function sessionPathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return '/';
        }

        return rtrim($path, '/').'/';
    }

    public static function hostFromUrl(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? null;

        return $port ? $host.':'.$port : $host;
    }

    /**
     * @return array{scheme: string, host: string, port: int, path: string}|null
     */
    public static function servingFingerprint(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $host = 'loopback';
        } elseif (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '/' || $path === '') {
            $path = '';
        } else {
            $path = rtrim($path, '/');
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => $path,
        ];
    }

    public static function sameServingOrigin(string $a, string $b): bool
    {
        $left = self::servingFingerprint($a);
        $right = self::servingFingerprint($b);

        return $left !== null && $right !== null && $left === $right;
    }

    /**
     * @return list<string>
     */
    public static function sanctumDomainsFromUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $host = strtolower((string) ($parts['host'] ?? 'localhost'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $hosts = [$host];
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $hosts = ['localhost', '127.0.0.1'];
        }

        $domains = [
            'localhost',
            'localhost:8000',
            'localhost:8099',
            '127.0.0.1',
            '127.0.0.1:8000',
            '127.0.0.1:8099',
        ];
        foreach ($hosts as $name) {
            $domains[] = $name;
            if ($port) {
                $domains[] = $name.':'.$port;
            }
        }
        $domains[] = self::hostFromUrl($url);

        return array_values(array_unique(array_filter($domains)));
    }
}
