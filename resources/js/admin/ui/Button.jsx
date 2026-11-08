import { Link } from '@inertiajs/react';
import { ui, cx } from './classes';
import Icon from './Icon';
import Loader from './Loader';

const variants = {
    primary: ui.btnPrimary,
    block: ui.btnPrimaryBlock,
    compact: ui.btnPrimaryCompact,
    sm: ui.btnPrimarySm,
    secondary: ui.btnSecondary,
    outline: ui.btnOutline,
    ghost: ui.btnGhost,
    link: ui.btnLink,
    danger: ui.btnDanger,
    warning: ui.btnWarning,
    success: ui.btnSuccess,
    neutral: ui.btnNeutral,
    dangerSoft: ui.btnDangerSoft,
    login: ui.btnLogin,
};

export default function Button({ variant = 'primary', className, type, children, href, native, icon, busy, disabled, ...props }) {
    const classes = cx(ui.btnBase, variants[variant] || variants.primary, className);
    const content = (
        <>
            {busy ? <Loader inline /> : icon ? <Icon name={icon} /> : null}
            {children}
        </>
    );

    if (href) {
        if (native) {
            return (
                <a href={href} className={classes} {...props}>
                    {content}
                </a>
            );
        }

        return (
            <Link href={href} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button type={type} className={classes} disabled={busy || disabled} aria-busy={busy || undefined} {...props}>
            {content}
        </button>
    );
}
