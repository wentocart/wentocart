import { Link } from 'react-router-dom';
import { prefetchHandlers } from '../lib/prefetchRoute.js';

function hrefOf(to) {
    if (typeof to === 'string') {
        return to;
    }
    if (to && typeof to === 'object' && typeof to.pathname === 'string') {
        return to.pathname + (to.search || '');
    }
    return '';
}

/** react-router Link that warms the lazy route chunk on hover/focus. */
export default function PrefetchLink({ to, onMouseEnter, onFocus, onTouchStart, ...props }) {
    const warm = prefetchHandlers(hrefOf(to));
    return (
        <Link
            to={to}
            {...props}
            onMouseEnter={(e) => {
                warm.onMouseEnter?.();
                onMouseEnter?.(e);
            }}
            onFocus={(e) => {
                warm.onFocus?.();
                onFocus?.(e);
            }}
            onTouchStart={(e) => {
                warm.onTouchStart?.();
                onTouchStart?.(e);
            }}
        />
    );
}
