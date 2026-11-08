import { useLocation } from 'react-router-dom';
import { skeletonForPath } from './Feedback.jsx';

/**
 * Path-aware placeholder while a lazy route chunk downloads.
 * Matches the finished page chrome so refresh does not flash an old layout.
 */
export function RouteFallback() {
    const { pathname } = useLocation();
    return skeletonForPath(pathname);
}
