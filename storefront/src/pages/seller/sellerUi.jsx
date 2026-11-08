import { Link } from 'react-router-dom';
import { Icon } from '../../components/Icons.jsx';
import { t } from '../../i18n.js';

const ICON_SM = 'h-3.5 w-3.5 shrink-0';
const ICON_MD = 'h-4 w-4 shrink-0';

export function AddBtnLabel({ children }) {
    return (
        <>
            <Icon name="plus" className={ICON_MD} aria-hidden="true" />
            {children}
        </>
    );
}

export function SaveBtnLabel({ children }) {
    return (
        <>
            <Icon name="check" className={ICON_MD} aria-hidden="true" />
            {children}
        </>
    );
}

export function SellerEditLink({ to, children, className = 'mv-link', ...props }) {
    return (
        <Link to={to} className={className} {...props}>
            <Icon name="pencil" className={ICON_SM} aria-hidden="true" />
            {children ?? t('storefront.seller.edit', 'Edit')}
        </Link>
    );
}

export function SellerEditButton({ children, className = 'mv-link', ...props }) {
    return (
        <button type="button" className={className} {...props}>
            <Icon name="pencil" className={ICON_SM} aria-hidden="true" />
            {children ?? t('storefront.seller.edit', 'Edit')}
        </button>
    );
}

export function SellerDeleteButton({ children, className = 'mv-danger', ...props }) {
    return (
        <button type="button" className={className} {...props}>
            <Icon name="trash" className={ICON_SM} aria-hidden="true" />
            {children ?? t('storefront.seller.delete', 'Delete')}
        </button>
    );
}
