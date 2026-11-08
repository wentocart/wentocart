import { useEffect, useState } from 'react';
import { t } from './i18n';
import { ui } from './classes';
import Button from './Button';
import { Select } from './Field';

const MODIFIERS = [
    { value: 'ctrl', label: 'Ctrl' },
    { value: 'alt', label: 'Alt' },
    { value: 'meta', label: 'Window' },
    { value: 'shift', label: 'Shift' },
];

const KEYS = [
    ...'abcdefghijklmnopqrstuvwxyz'.split(''),
    ...'123456789'.split(''),
];

const RESERVED = new Set([
    'ctrl+l', 'meta+l',
    'ctrl+r', 'meta+r',
    'ctrl+w', 'meta+w',
    'ctrl+t', 'meta+t',
    'ctrl+n', 'meta+n',
]);

export function chordLabel(chord) {
    if (!chord) {
        return '';
    }
    return String(chord)
        .split('+')
        .map((part) => {
            if (part === 'ctrl') {
                return 'Ctrl';
            }
            if (part === 'meta') {
                return 'Window';
            }
            if (part === 'alt') {
                return 'Alt';
            }
            if (part === 'shift') {
                return 'Shift';
            }
            return part.toUpperCase();
        })
        .join('+');
}

export function splitChord(value, fallback = 'ctrl+f') {
    const raw = String(value || fallback || 'ctrl+f').toLowerCase();
    const parts = raw.split('+').filter(Boolean);
    const keyPart = parts[parts.length - 1] || 'f';
    const modPart = parts.find((part) => MODIFIERS.some((row) => row.value === part)) || 'ctrl';
    const key = KEYS.includes(keyPart) ? keyPart : 'f';

    return { modifier: modPart, key };
}

export function isReservedChord(chord) {
    return RESERVED.has(String(chord || '').toLowerCase());
}

/**
 * Pick a modifier and a key. Calls onChange("ctrl+k") when either dropdown changes.
 */
export default function JumpShortcutCapture({
    value,
    fallback = 'ctrl+f',
    onChange,
    onClear,
    effectiveLabel,
    effectiveSource,
    hint,
    clearLabel,
}) {
    const parsed = splitChord(value, fallback);
    const [modifier, setModifier] = useState(parsed.modifier);
    const [key, setKey] = useState(parsed.key);

    useEffect(() => {
        const next = splitChord(value, fallback);
        setModifier(next.modifier);
        setKey(next.key);
    }, [value, fallback]);

    const chord = `${modifier}+${key}`;
    const reserved = isReservedChord(chord);
    const sourceHint = effectiveSource === 'staff'
        ? t('admin.jump.source_staff', 'Your profile')
        : effectiveSource === 'shop'
            ? t('admin.jump.source_shop', 'Shop default')
            : t('admin.jump.source_default', 'Built-in default');

    const choose = (nextModifier, nextKey) => {
        setModifier(nextModifier);
        setKey(nextKey);
        onChange?.(`${nextModifier}+${nextKey}`);
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={modifier}
                    aria-label={t('admin.search_shortcut.modifier', 'Modifier')}
                    onChange={(e) => choose(e.target.value, key)}
                >
                    {MODIFIERS.map((row) => (
                        <option key={row.value} value={row.value}>{row.label}</option>
                    ))}
                </Select>
                <span className="text-sm text-neutral-400" aria-hidden>+</span>
                <Select
                    value={key}
                    aria-label={t('admin.search_shortcut.key', 'Key')}
                    onChange={(e) => choose(modifier, e.target.value)}
                >
                    {KEYS.map((row) => (
                        <option key={row} value={row}>{row.toUpperCase()}</option>
                    ))}
                </Select>
                <kbd className="rounded-md border border-neutral-200 bg-neutral-50 px-2 py-1 text-xs font-semibold text-neutral-700">
                    {chordLabel(chord)}
                </kbd>
            </div>
            {hint ? <p className={ui.muted}>{hint}</p> : null}
            {reserved ? (
                <p className="text-sm text-red-600">
                    {t('admin.search_shortcut.reserved', 'That combination is reserved by the browser. Pick another key.')}
                </p>
            ) : null}
            <div className="flex flex-wrap items-center gap-2">
                {onClear ? (
                    <Button type="button" variant="ghost" onClick={onClear}>
                        {clearLabel || t('admin.jump.use_shop_default', 'Use shop default')}
                    </Button>
                ) : null}
                {effectiveLabel ? (
                    <span className={ui.muted}>
                        {t('admin.jump.effective', 'Effective')}: {effectiveLabel} ({sourceHint})
                    </span>
                ) : null}
            </div>
        </div>
    );
}
