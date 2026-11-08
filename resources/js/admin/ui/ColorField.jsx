import { ui, cx } from './classes';
import { Input } from './Field';
import { t } from './i18n';

export const badgeTones = ['primary', 'info', 'warning', 'success', 'danger', 'neutral'];

export const TONE_HEX = {
    primary: '#7C3AED',
    info: '#0284C7',
    warning: '#D97706',
    success: '#16A34A',
    danger: '#DC2626',
    neutral: '#737373',
};

export function normalizeHex(value, fallback = '#7C3AED') {
    const raw = String(value || '').trim();
    if (!raw) {
        return fallback;
    }
    const named = TONE_HEX[raw.toLowerCase()];
    if (named) {
        return named;
    }
    if (/^#[0-9A-Fa-f]{6}$/.test(raw)) {
        return raw.toUpperCase();
    }
    if (/^#[0-9A-Fa-f]{3}$/.test(raw)) {
        return `#${raw[1]}${raw[1]}${raw[2]}${raw[2]}${raw[3]}${raw[3]}`.toUpperCase();
    }
    if (/^[0-9A-Fa-f]{6}$/.test(raw)) {
        return `#${raw.toUpperCase()}`;
    }

    return fallback;
}

export function hexFromTone(value, fallback = '#7C3AED') {
    return normalizeHex(value, fallback);
}

export default function ColorField({
    value,
    onChange,
    disabled = false,
    swatches = [],
    placeholder = '#7C3AED',
    fallback = '#7C3AED',
    ariaLabel,
    swatchClassName,
    inputClassName,
}) {
    const hex = normalizeHex(value, fallback);
    const picker = /^#[0-9A-F]{6}$/.test(hex) ? hex : fallback;

    return (
        <div className={ui.hexRow}>
            <input
                type="color"
                className={cx(ui.hexNative, swatchClassName)}
                disabled={disabled}
                value={picker}
                aria-label={ariaLabel || t('admin.common.pick_color', 'Pick color')}
                onChange={(e) => onChange?.(e.target.value.toUpperCase())}
            />
            <Input
                grow
                className={cx('min-w-0', inputClassName)}
                disabled={disabled}
                value={value || ''}
                placeholder={placeholder}
                onChange={(e) => onChange?.(e.target.value)}
            />
            {(swatches || []).map((swatch) => {
                const chip = typeof swatch === 'string' ? { name: swatch, hex: swatch } : swatch;
                const chipHex = normalizeHex(chip.hex, fallback);
                return (
                    <button
                        key={chip.name || chipHex}
                        type="button"
                        title={chip.name || chipHex}
                        disabled={disabled}
                        className={cx(ui.hexChip, hex === chipHex && 'ring-2 ring-violet-500')}
                        style={{ background: chipHex }}
                        onClick={() => onChange?.(chipHex)}
                    />
                );
            })}
        </div>
    );
}
