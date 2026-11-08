<?php

namespace App\Domain\Ops;

use App\Domain\Settings\ShopSetting;
use App\Models\Admin;

/**
 * Admin Jump palette open-chord: staff → shop → default (ctrl+f / meta+f).
 */
final class JumpShortcut
{
    public const DEFAULT = 'ctrl+f';

    public const SHOP_KEY = 'admin.jump.shortcut';

    /** @var list<string> */
    private const RESERVED = [
        'ctrl+l', 'meta+l',
        'ctrl+r', 'meta+r',
        'ctrl+w', 'meta+w',
        'ctrl+t', 'meta+t',
        'ctrl+n', 'meta+n',
    ];

    public static function default(): string
    {
        return self::DEFAULT;
    }

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        $raw = str_replace([' ', '_'], ['', '+'], $raw);
        $parts = array_values(array_filter(explode('+', $raw), fn ($p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        $mods = [];
        $key = null;
        foreach ($parts as $part) {
            if (in_array($part, ['ctrl', 'control', 'ctl'], true)) {
                $mods['ctrl'] = true;
                continue;
            }
            if (in_array($part, ['meta', 'cmd', 'command', 'super'], true)) {
                $mods['meta'] = true;
                continue;
            }
            if (in_array($part, ['alt', 'option'], true)) {
                $mods['alt'] = true;
                continue;
            }
            if ($part === 'shift') {
                $mods['shift'] = true;
                continue;
            }
            if ($key !== null) {
                return null;
            }
            if (preg_match('/^[a-z0-9]$/', $part) || preg_match('/^f([1-9]|1[0-2])$/', $part)) {
                $key = $part;
                continue;
            }

            return null;
        }

        if ($key === null || $mods === []) {
            return null;
        }

        $order = ['ctrl', 'meta', 'alt', 'shift'];
        $out = [];
        foreach ($order as $mod) {
            if (! empty($mods[$mod])) {
                $out[] = $mod;
            }
        }
        $out[] = $key;

        return implode('+', $out);
    }

    public static function isValid(?string $raw): bool
    {
        $chord = self::normalize($raw);
        if ($chord === null) {
            return false;
        }

        return ! in_array($chord, self::RESERVED, true);
    }

    /**
     * @return array{shortcut: string, source: 'staff'|'shop'|'default', label: string}
     */
    public static function resolve(?Admin $admin): array
    {
        $staff = self::normalize($admin?->jump_shortcut);
        if ($staff !== null && self::isValid($staff)) {
            return [
                'shortcut' => $staff,
                'source' => 'staff',
                'label' => self::label($staff),
            ];
        }

        $shop = self::normalize((string) ShopSetting::get(self::SHOP_KEY, ''));
        if ($shop !== null && self::isValid($shop)) {
            return [
                'shortcut' => $shop,
                'source' => 'shop',
                'label' => self::label($shop),
            ];
        }

        return [
            'shortcut' => self::DEFAULT,
            'source' => 'default',
            'label' => self::label(self::DEFAULT),
        ];
    }

    public static function label(string $chord): string
    {
        $normalized = self::normalize($chord) ?? self::DEFAULT;
        $map = [
            'ctrl' => 'Ctrl',
            'meta' => 'Window',
            'alt' => 'Alt',
            'shift' => 'Shift',
        ];
        $parts = explode('+', $normalized);

        return implode('+', array_map(function (string $part) use ($map) {
            if (isset($map[$part])) {
                return $map[$part];
            }

            return strtoupper($part);
        }, $parts));
    }

    public static function shopChord(): ?string
    {
        return self::normalize((string) ShopSetting::get(self::SHOP_KEY, ''));
    }

    public static function putShop(?string $raw): void
    {
        $chord = self::normalize($raw);
        if ($chord === null) {
            ShopSetting::put(self::SHOP_KEY, '');

            return;
        }
        if (! self::isValid($chord)) {
            throw new \InvalidArgumentException('That shortcut is reserved by the browser. Pick another key.');
        }
        ShopSetting::put(self::SHOP_KEY, $chord);
    }
}
