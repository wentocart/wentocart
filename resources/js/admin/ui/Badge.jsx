import { ui, cx } from './classes';
import { hexFromTone, TONE_HEX } from './ColorField';

const tones = {
    success: ui.badgeSuccess,
    warning: ui.badgeWarning,
    danger: ui.badgeDanger,
    info: ui.badgeInfo,
    primary: ui.badgePrimary,
    neutral: ui.badgeNeutral,
};

function isCustomHex(tone) {
    return typeof tone === 'string' && /^#[0-9A-Fa-f]{6}$/.test(tone.trim());
}

export default function Badge({ tone = 'neutral', children, dot = true, className, title }) {
    const named = tones[tone];
    if (!named && (isCustomHex(tone) || TONE_HEX[tone])) {
        const hex = hexFromTone(tone);
        return (
            <span
                className={cx(ui.badge, className)}
                title={title}
                style={{ background: `color-mix(in srgb, ${hex} 16%, white)`, color: hex }}
            >
                {dot ? <span className={cx(ui.badgeDot, 'bg-current')} /> : null}
                {children}
            </span>
        );
    }

    return (
        <span className={cx(ui.badge, named || tones.neutral, className)} title={title}>
            {dot ? <span className={cx(ui.badgeDot, 'bg-current')} /> : null}
            {children}
        </span>
    );
}
