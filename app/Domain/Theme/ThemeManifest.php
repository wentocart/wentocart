<?php

namespace App\Domain\Theme;

use InvalidArgumentException;

class ThemeManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $version,
        public readonly string $author,
        public readonly ?string $parent,
        public readonly string $engine,
        public readonly string $description,
        public readonly ?string $preview,
        public readonly array $layouts,
        public readonly array $slots,
        public readonly array $settings,
        /** @var array<string, mixed> */
        public readonly array $settingsSchema,
        public readonly array $css,
        public readonly array $js,
        /** live = storefront reads merged settings; admin_only = admin saves only (static storefront). */
        public readonly string $storefrontSettingsApply,
        public readonly string $path,
        public readonly bool $bundled,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $path, bool $bundled = false): self
    {
        $name = strtolower(trim((string) ($raw['name'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $name)) {
            throw new InvalidArgumentException('theme.json name must be a lowercase slug.');
        }

        $engine = strtolower(trim((string) ($raw['engine'] ?? '')));
        $parent = self::nullableString($raw['parent'] ?? null);
        if ($engine === '') {
            $engine = is_dir($path.DIRECTORY_SEPARATOR.'dist') ? 'spa-dist' : 'react-vite';
        }
        if (! in_array($engine, ['react-vite', 'spa-dist'], true)) {
            throw new InvalidArgumentException('theme.json engine must be react-vite or spa-dist.');
        }

        $assets = is_array($raw['assets'] ?? null) ? $raw['assets'] : [];

        return new self(
            name: $name,
            displayName: trim((string) ($raw['display_name'] ?? $raw['displayName'] ?? $name)) ?: $name,
            version: trim((string) ($raw['version'] ?? '1.0.0')) ?: '1.0.0',
            author: trim((string) ($raw['author'] ?? '')) ?: 'Unknown',
            parent: $parent,
            engine: $engine,
            description: trim((string) ($raw['description'] ?? '')),
            preview: self::nullableString($raw['preview'] ?? null),
            layouts: array_values(array_filter((array) ($raw['layouts'] ?? []), fn ($v) => is_string($v) && $v !== '')),
            slots: array_values(array_filter((array) ($raw['slots'] ?? []), fn ($v) => is_string($v) && $v !== '')),
            settings: is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            settingsSchema: is_array($raw['settings_schema'] ?? null) ? $raw['settings_schema'] : [],
            css: self::assetList($assets['css'] ?? []),
            js: self::assetList($assets['js'] ?? []),
            storefrontSettingsApply: self::storefrontSettingsApply($raw['storefront_settings_apply'] ?? null),
            path: $path,
            bundled: $bundled,
        );
    }

    public function appliesSettingsOnStorefront(): bool
    {
        return $this->storefrontSettingsApply !== 'admin_only';
    }

    public function isChild(): bool
    {
        return $this->parent !== null && $this->parent !== '';
    }

    public function isFullOverride(): bool
    {
        return ! $this->isChild() && $this->engine === 'spa-dist';
    }

    public function hasSettingsSchema(): bool
    {
        return $this->settingsSchema !== [] && is_array($this->settingsSchema['groups'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'version' => $this->version,
            'author' => $this->author,
            'parent' => $this->parent,
            'engine' => $this->engine,
            'description' => $this->description,
            'preview' => $this->preview,
            'layouts' => $this->layouts,
            'slots' => $this->slots,
            'settings' => $this->settings,
            'settings_schema' => $this->settingsSchema,
            'css' => $this->css,
            'js' => $this->js,
            'storefront_settings_apply' => $this->storefrontSettingsApply,
            'bundled' => $this->bundled,
            'path' => $this->path,
        ];
    }

    protected static function storefrontSettingsApply(mixed $value): string
    {
        $mode = is_string($value) ? strtolower(trim($value)) : 'live';

        return in_array($mode, ['live', 'admin_only'], true) ? $mode : 'live';
    }

    protected static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    protected static function assetList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (! is_string($item) || $item === '') {
                continue;
            }
            $item = str_replace('\\', '/', $item);
            if (str_contains($item, '..') || str_starts_with($item, '/')) {
                continue;
            }
            $out[] = ltrim($item, './');
        }

        return array_values(array_unique($out));
    }
}
