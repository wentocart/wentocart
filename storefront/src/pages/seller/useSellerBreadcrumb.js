import { useEffect } from 'react';
import { useOutletContext } from 'react-router-dom';

/** Override the last seller breadcrumb label (e.g. product name on edit form). */
export function useSellerBreadcrumb(label) {
    const { setSellerBreadcrumb } = useOutletContext();

    useEffect(() => {
        if (typeof setSellerBreadcrumb !== 'function') {
            return undefined;
        }
        setSellerBreadcrumb(label || null);
        return () => setSellerBreadcrumb(null);
    }, [label, setSellerBreadcrumb]);
}
