import { ui, cx } from './classes';

export function Card({ children, className, padded = true, as: Tag = 'div', ...props }) {
    return (
        <Tag className={cx(padded ? ui.cardPad : ui.card, className)} {...props}>
            {children}
        </Tag>
    );
}

export function FormCard({ children, className, onSubmit }) {
    return (
        <form className={cx(ui.cardForm, className)} onSubmit={onSubmit}>
            {children}
        </form>
    );
}

export function List({ children, className }) {
    return <ul className={cx(ui.list, className)}>{children}</ul>;
}

export function ListItem({ children, className }) {
    return <li className={cx(ui.listItem, className)}>{children}</li>;
}

export function InlineForm({ children, className, onSubmit }) {
    return (
        <form className={cx(ui.inlineForm, className)} onSubmit={onSubmit}>
            {children}
        </form>
    );
}
