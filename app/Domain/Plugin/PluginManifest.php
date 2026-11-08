<?php

namespace App\Domain\Plugin;

use App\Support\WebBase;
use InvalidArgumentException;

class PluginManifest
{
    public const CATEGORIES = [
        'payment', 'shipping', 'tax', 'analytics', 'marketing',
        'auth', 'storage', 'admin', 'business', 'other',
    ];

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $hooks
     * @param  array<string, string>  $requires  extension id => version constraint
     * @param  list<string>  $conflicts
     * @param  list<string>  $setupSteps
     */
    public function __construct(
        public readonly string $vendor,
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly string $type,
        public readonly array $capabilities,
        public readonly array $hooks,
        public readonly string $path,
        public readonly bool $bundled,
        public readonly ?string $preview,
        public readonly int $manifestVersion = 1,
        public readonly ?string $category = null,
        public readonly ?string $minAppVersion = null,
        public readonly ?string $maxAppVersion = null,
        public readonly array $requires = [],
        public readonly array $conflicts = [],
        public readonly array $setupSteps = [],
        public readonly array $outboundHosts = [],
        /** @var array<string, list<string>> */
        public readonly array $browserCsp = [],
        public readonly ?string $signature = null,
    ) {}

    public function id(): string
    {
        return $this->vendor.'/'.$this->name;
    }

    /**
     * Public URL for a file under this extension (install-folder aware: LAMPP/XAMPP/subdir).
     */
    public function publicAssetUrl(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        return WebBase::url('/plugins/'.$this->vendor.'/'.$this->name.'/'.$relative);
    }

    /** Catalog / card logo URL, or null when app.json has no preview. */
    public function previewPublicUrl(): ?string
    {
        if (! is_string($this->preview) || $this->preview === '') {
            return null;
        }

        return $this->publicAssetUrl($this->preview);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $path, bool $bundled = false): self
    {
        $vendor = strtolower(trim((string) ($raw['vendor'] ?? '')));
        $name = strtolower(trim((string) ($raw['name'] ?? '')));
        if (! ExtensionSlug::isValid($vendor) || ! ExtensionSlug::isValid($name)) {
            throw new InvalidArgumentException('app.json vendor and name must be lowercase letter/hyphen slugs (no digits).');
        }

        $type = strtolower(trim((string) ($raw['type'] ?? 'safe')));
        if (! in_array($type, ['safe', 'certified'], true)) {
            throw new InvalidArgumentException('app.json type must be safe or certified.');
        }

        $capabilities = [];
        foreach ((array) ($raw['capabilities'] ?? []) as $item) {
            if (! is_string($item) || $item === '' || ! Capabilities::known($item)) {
                throw new InvalidArgumentException('Unknown capability in app.json.');
            }
            if ($type === 'safe' && Capabilities::isCertified($item)) {
                throw new InvalidArgumentException('Safe apps cannot declare '.$item.'. Use type certified after review.');
            }
            $capabilities[] = $item;
        }

        $hooks = [];
        foreach ((array) ($raw['hooks'] ?? []) as $hook) {
            if (! is_string($hook) || $hook === '') {
                continue;
            }
            if (! HookCatalog::allows($hook)) {
                throw new InvalidArgumentException('App cannot listen to unpublished hook '.$hook.'.');
            }
            $hooks[] = $hook;
        }

        $manifestVersion = (int) ($raw['manifest_version'] ?? 1);
        if ($manifestVersion < 1) {
            throw new InvalidArgumentException('app.json manifest_version must be >= 1.');
        }

        $category = null;
        if (isset($raw['category']) && is_string($raw['category']) && $raw['category'] !== '') {
            $category = strtolower(trim($raw['category']));
            if (! in_array($category, self::CATEGORIES, true)) {
                throw new InvalidArgumentException('Unknown category in app.json.');
            }
        }

        $minAppVersion = self::optionalVersion($raw['min_app_version'] ?? $raw['minAppVersion'] ?? null, 'min_app_version');
        $maxAppVersion = self::optionalVersion($raw['max_app_version'] ?? $raw['maxAppVersion'] ?? null, 'max_app_version');
        if (isset($raw['compatible_with']) && is_string($raw['compatible_with']) && $raw['compatible_with'] !== '' && $maxAppVersion === null) {
            $maxAppVersion = self::optionalVersion($raw['compatible_with'], 'compatible_with');
        }

        $requires = [];
        foreach ((array) ($raw['requires'] ?? []) as $id => $constraint) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('app.json requires keys must be extension ids (vendor/name).');
            }
            $id = strtolower(trim($id));
            if (! ExtensionSlug::isValidId($id)) {
                throw new InvalidArgumentException('Invalid requires id '.$id.'.');
            }
            if (! is_string($constraint) || trim($constraint) === '') {
                throw new InvalidArgumentException('app.json requires values must be version constraints.');
            }
            $requires[$id] = trim($constraint);
        }

        $conflicts = [];
        foreach ((array) ($raw['conflicts'] ?? []) as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $id = strtolower(trim($id));
            if (! ExtensionSlug::isValidId($id)) {
                throw new InvalidArgumentException('Invalid conflicts id '.$id.'.');
            }
            $conflicts[] = $id;
        }

        $setupSteps = [];
        foreach ((array) ($raw['setup_steps'] ?? $raw['setupSteps'] ?? []) as $step) {
            if (is_string($step) && trim($step) !== '') {
                $setupSteps[] = trim($step);
            }
        }

        $outboundHosts = [];
        foreach ((array) ($raw['outbound_hosts'] ?? $raw['outboundHosts'] ?? []) as $host) {
            if (! is_string($host) || trim($host) === '') {
                continue;
            }
            $host = strtolower(trim($host));
            $host = preg_replace('#^https?://#', '', $host) ?? $host;
            $host = rtrim($host, '/');
            if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
                throw new InvalidArgumentException('Invalid outbound_hosts entry '.$host.'.');
            }
            $outboundHosts[] = $host;
        }

        $browserCsp = self::parseBrowserCsp($raw['csp'] ?? $raw['browser_csp'] ?? null);

        return new self(
            vendor: $vendor,
            name: $name,
            displayName: trim((string) ($raw['display_name'] ?? $raw['displayName'] ?? $name)) ?: $name,
            version: trim((string) ($raw['version'] ?? '1.0.0')) ?: '1.0.0',
            author: trim((string) ($raw['author'] ?? '')) ?: 'Unknown',
            description: trim((string) ($raw['description'] ?? '')),
            type: $type,
            capabilities: array_values(array_unique($capabilities)),
            hooks: array_values(array_unique($hooks)),
            path: $path,
            bundled: $bundled,
            preview: isset($raw['preview']) && is_string($raw['preview']) ? $raw['preview'] : null,
            manifestVersion: $manifestVersion,
            category: $category,
            minAppVersion: $minAppVersion,
            maxAppVersion: $maxAppVersion,
            requires: $requires,
            conflicts: array_values(array_unique($conflicts)),
            setupSteps: $setupSteps,
            outboundHosts: array_values(array_unique($outboundHosts)),
            browserCsp: $browserCsp,
            signature: isset($raw['signature']) && is_string($raw['signature']) ? trim($raw['signature']) : null,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function parseBrowserCsp(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $directive => $sources) {
            if (! is_string($directive) || ! in_array($directive, \App\Domain\Security\CspPolicyRegistry::ALLOWED_DIRECTIVES, true)) {
                continue;
            }
            $clean = [];
            foreach ((array) $sources as $source) {
                if (! is_string($source)) {
                    continue;
                }
                $source = trim($source);
                if (\App\Domain\Security\CspPolicyRegistry::isAllowedSource($source)) {
                    $clean[] = $source;
                }
            }
            if ($clean !== []) {
                $out[$directive] = array_values(array_unique($clean));
            }
        }

        return $out;
    }

    protected static function optionalVersion(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('app.json '.$field.' must be a version string.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+(\.\d+){0,3}(-[a-zA-Z0-9.-]+)?$/', $value)) {
            throw new InvalidArgumentException('app.json '.$field.' is not a valid version.');
        }

        return $value;
    }

    public static function studly(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    public function className(): string
    {
        return 'Modules\\'.self::studly($this->vendor).'\\'.self::studly($this->name).'\\Plugin';
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'vendor' => $this->vendor,
            'name' => $this->name,
            'display_name' => $this->displayName,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'type' => $this->type,
            'capabilities' => $this->capabilities,
            'hooks' => $this->hooks,
            'bundled' => $this->bundled,
            'preview' => $this->preview,
            'manifest_version' => $this->manifestVersion,
            'category' => $this->category,
            'min_app_version' => $this->minAppVersion,
            'max_app_version' => $this->maxAppVersion,
            'requires' => $this->requires,
            'conflicts' => $this->conflicts,
            'setup_steps' => $this->setupSteps,
            'outbound_hosts' => $this->outboundHosts,
            'signed' => $this->signature !== null && $this->signature !== '',
        ];
    }
}
