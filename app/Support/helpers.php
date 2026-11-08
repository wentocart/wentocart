<?php

use App\Support\Hooks\HookManager;

if (! function_exists('wento_hook')) {
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    function wento_hook(string $name, array $payload = []): array
    {
        return HookManager::fire($name, $payload);
    }
}

if (! function_exists('wento_action')) {
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    function wento_action(string $name, string $when, array $payload = []): array
    {
        return HookManager::fire('action.'.$name.'.'.$when, $payload);
    }
}

if (! function_exists('wento_db_prefix_stem')) {
    /**
     * Letters, numbers, and internal underscores. Trailing underscores are not part of the stem.
     */
    function wento_db_prefix_stem(?string $raw = null): string
    {
        $p = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', $raw ?? (string) env('DB_PREFIX', '')));

        return rtrim($p, '_');
    }
}

if (! function_exists('wento_db_prefix_valid')) {
    /**
     * Start with a letter; then letters, numbers, and underscores (tiger_, tia_2_, Lo_as_as_).
     * At least two letters/digits. Max 32 characters after trimming trailing underscores.
     */
    function wento_db_prefix_valid(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
            return false;
        }
        $stem = wento_db_prefix_stem($raw);
        if ($stem === '' || strlen($stem) > 32) {
            return false;
        }
        $alnum = (string) preg_replace('/_/', '', $stem);

        return strlen($alnum) >= 2 && strlen($alnum) <= 32;
    }
}

if (! function_exists('wento_db_prefix')) {
    /**
     * Normalize a merchant-chosen table prefix to PREFIX_ (e.g. WBL → WBL_, Lo_as_as_ → LO_AS_AS_).
     * Empty means no prefix (local tests).
     */
    function wento_db_prefix(?string $raw = null): string
    {
        $p = wento_db_prefix_stem($raw);

        return $p === '' ? '' : $p.'_';
    }
}

if (! function_exists('wento_plain_text')) {
    /**
     * Street / contact text: strip HTML, keep punctuation, numbers, and line breaks.
     */
    function wento_plain_text(string $value, int $max = 2000): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = str_replace("\0", '', $value);
        $value = trim((string) preg_replace("/\r\n|\r/", "\n", $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}

if (! function_exists('wento_compose_store_address')) {
    /**
     * Join optional worldwide address fields. Falls back to store_address.
     *
     * @param  array<string, mixed>  $input
     */
    function wento_compose_store_address(array $input): string
    {
        $parts = [];
        foreach (['store_line1', 'store_line2', 'store_city', 'store_region', 'store_postal'] as $key) {
            $value = wento_plain_text((string) ($input[$key] ?? ''), 255);
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $code = strtoupper(trim((string) ($input['store_country'] ?? '')));
        if ($code !== '') {
            $found = \App\Domain\Localization\WorldCountries::find($code);
            $parts[] = (string) ($found['name'] ?? $code);
        }
        if ($parts !== []) {
            return wento_plain_text(implode("\n", $parts), 2000);
        }

        return wento_plain_text((string) ($input['store_address'] ?? ''), 2000);
    }
}

if (! function_exists('wento_setting')) {
    function wento_setting(string $key, mixed $default = null): mixed
    {
        return \App\Domain\Settings\Models\Setting::getValue($key, $default);
    }
}

if (! function_exists('wento_admin_toast')) {
    /**
     * Attach a list-level growl flag to a redirect (optional; MarkAdminListToast covers named list routes).
     *
     * @template T of \Illuminate\Http\RedirectResponse
     * @param  T  $redirect
     * @return T
     */
    function wento_admin_toast(\Illuminate\Http\RedirectResponse $redirect): \Illuminate\Http\RedirectResponse
    {
        return $redirect->with('toast', true);
    }
}
