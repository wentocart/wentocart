import { useEffect, useState } from 'react';
import { defaultImageUrl, mediaUrlOrDefault } from '../lib/format.js';

export default function ProductImage({ product, alt, className = '', priority = false, fit = 'contain', size = 'md', width, height }) {
    const fallback = defaultImageUrl(size);
    const label = alt || product?.name || '';
    const [src, setSrc] = useState(() => mediaUrlOrDefault(product?.cover_url || product?.cover, size));
    const objectFit = fit === 'cover' ? 'object-cover' : 'object-contain';
    const dims = {
        sm: { width: 160, height: 160 },
        md: { width: 320, height: 320 },
        lg: { width: 640, height: 640 },
    }[size] || { width: 320, height: 320 };
    const imgWidth = width ?? dims.width;
    const imgHeight = height ?? dims.height;

    useEffect(() => {
        setSrc(mediaUrlOrDefault(product?.cover_url || product?.cover, size));
    }, [product?.cover_url, product?.cover, size]);

    return (
        <img
            src={src}
            alt={label}
            width={imgWidth}
            height={imgHeight}
            className={`h-full w-full ${objectFit} ${className}`}
            loading={priority ? 'eager' : 'lazy'}
            decoding="async"
            fetchPriority={priority ? 'high' : 'auto'}
            onError={() => setSrc(fallback)}
        />
    );
}
