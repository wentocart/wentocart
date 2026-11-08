import { useEffect } from 'react';
import Category from './Category.jsx';
import { applyThinShellSeo } from '../lib/seoHead.js';

export default function Catalog({ slug = 'catalog' }) {
    const title = slug === 'new-arrivals' ? 'New products' : 'Catalog';
    useEffect(() => { applyThinShellSeo(title); }, [title]);

    return <Category slug={slug} />;
}
