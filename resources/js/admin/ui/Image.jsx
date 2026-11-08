import { useEffect, useState } from 'react';
import { ui, cx } from './classes';
import { Field, Input } from './Field';
import { t } from './i18n';
import { catalogImageUrl } from '../lib/catalogMedia';

export function Thumb({ src, alt = '', className, size = 'sm' }) {
    const fallback = catalogImageUrl(null, size);
    const [url, setUrl] = useState(() => catalogImageUrl(src, size));

    useEffect(() => {
        setUrl(catalogImageUrl(src, size));
    }, [src, size]);

    return (
        <img
            src={url}
            alt={alt}
            className={cx(ui.thumb, className)}
            onError={() => setUrl(fallback)}
        />
    );
}

export function ImageField({ label = t('admin.common.image', 'Image'), preview, error, onChange, ...props }) {
    return (
        <Field label={label} error={error}>
            <div className="space-y-2">
                <Thumb src={preview} />
                <Input type="file" accept="image/*" onChange={onChange} {...props} />
            </div>
        </Field>
    );
}
