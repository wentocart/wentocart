import { Icon, kitIcon } from './Icons.jsx';

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

export default function ExtensionIcon({ icon, className = 'h-4 w-4', fallback = '' }) {
    const parsed = spec(icon);
    if (!parsed) {
        return fallback ? <Icon name={kitIcon(fallback, 'grid')} className={className} /> : null;
    }
    if (parsed.kind === 'kit') {
        return <Icon name={kitIcon(parsed.value, fallback || 'grid')} className={className} />;
    }
    if (parsed.kind === 'emoji') {
        return <span className={`ext-icon ext-icon-emoji ${className}`} aria-hidden="true">{parsed.value}</span>;
    }
    if (parsed.kind === 'fa') {
        return <i className={`ext-icon ${parsed.value} ${className}`} aria-hidden="true" />;
    }
    if (parsed.kind === 'image') {
        return <img src={parsed.value} alt="" className={`ext-icon ${className}`} />;
    }
    if (parsed.kind === 'svg' && String(parsed.value).trim().toLowerCase().startsWith('<svg')) {
        return (
            <span
                className={`ext-icon ext-icon-svg ${className}`}
                aria-hidden="true"
                dangerouslySetInnerHTML={{ __html: parsed.value }}
            />
        );
    }

    return fallback ? <Icon name={kitIcon(fallback, 'grid')} className={className} /> : null;
}
