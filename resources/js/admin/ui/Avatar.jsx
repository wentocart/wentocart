import { useEffect, useState } from 'react';
import { ui, cx } from './classes';

const colors = ['#6d28d9', '#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed'];

function initials(name) {
    const label = (name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

    return label || '?';
}

export default function Avatar({ name, src, className }) {
    const [failed, setFailed] = useState(false);
    const label = initials(name);
    const color = colors[(name || label || 'A').charCodeAt(0) % colors.length];
    const showImage = Boolean(src) && !failed;

    useEffect(() => {
        setFailed(false);
    }, [src]);

    return (
        <span
            className={cx(ui.avatar, 'overflow-hidden', className)}
            style={{ background: color }}
            title={name || undefined}
        >
            {showImage ? (
                <img
                    src={src}
                    alt=""
                    className="h-full w-full object-cover"
                    onError={() => setFailed(true)}
                />
            ) : (
                label
            )}
        </span>
    );
}
