import Icon from './Icon';
import { ui } from './classes';

function spec(icon) {
    if (!icon) {
        return null;
    }
    if (typeof icon === 'string') {
        return { kind: 'kit', value: icon };
    }
    if (icon.kind && icon.value) {
        return icon;
    }

    return null;
}

export default function ExtensionIcon({ icon, className, fallback = '' }) {
    const parsed = spec(icon);
    const box = className || ui.extIcon;
    if (!parsed) {
        return fallback ? <Icon name={fallback} className={box} /> : null;
    }
    if (parsed.kind === 'kit') {
        return <Icon name={parsed.value} className={box} />;
    }
    if (parsed.kind === 'emoji') {
        return <span className={ui.extIconEmoji} aria-hidden="true">{parsed.value}</span>;
    }
    if (parsed.kind === 'fa') {
        return <i className={`${parsed.value} ${ui.extIconFa}`} aria-hidden="true" />;
    }
    if (parsed.kind === 'image') {
        return <img src={parsed.value} alt="" className={box} />;
    }
    if (parsed.kind === 'svg' && String(parsed.value).trim().toLowerCase().startsWith('<svg')) {
        return (
            <span
                className={ui.extIconSvg}
                aria-hidden="true"
                dangerouslySetInnerHTML={{ __html: parsed.value }}
            />
        );
    }

    return fallback ? <Icon name={fallback} className={box} /> : null;
}
