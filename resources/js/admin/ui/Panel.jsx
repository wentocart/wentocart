import { createContext, useContext } from 'react';
import { ui, cx } from './classes';

const PanelContext = createContext({ tone: 'default' });

const TONES = ['default', 'primary', 'success', 'info', 'warning', 'danger'];

function resolveTone(tone, bsStyle) {
    const value = bsStyle || tone || 'default';
    return TONES.includes(value) ? value : 'default';
}

/**
 * Bootstrap-style panel (Heading / Title / Body / Footer).
 * API mirrors react-bootstrap v3 Panel; styles use admin tokens (not Bootstrap CSS).
 *
 * @example
 * <Panel tone="primary">
 *   <Panel.Heading><Panel.Title>Title</Panel.Title></Panel.Heading>
 *   <Panel.Body>Content</Panel.Body>
 *   <Panel.Footer>Actions</Panel.Footer>
 * </Panel>
 */
export default function Panel({
    tone = 'default',
    bsStyle,
    as: Tag = 'div',
    className,
    children,
    ...props
}) {
    const resolved = resolveTone(tone, bsStyle);

    return (
        <PanelContext.Provider value={{ tone: resolved }}>
            <Tag
                className={cx(ui.panel, ui[`panel_${resolved}`], className)}
                {...props}
            >
                {children}
            </Tag>
        </PanelContext.Provider>
    );
}

function Heading({ as: Tag = 'div', className, children, ...props }) {
    const { tone } = useContext(PanelContext);

    return (
        <Tag className={cx(ui.panelHeading, ui[`panelHeading_${tone}`], className)} {...props}>
            {children}
        </Tag>
    );
}

function Title({ as: Tag = 'h3', className, children, ...props }) {
    return (
        <Tag className={cx(ui.panelTitle, className)} {...props}>
            {children}
        </Tag>
    );
}

function Body({ as: Tag = 'div', className, children, ...props }) {
    return (
        <Tag className={cx(ui.panelBody, className)} {...props}>
            {children}
        </Tag>
    );
}

function Footer({ as: Tag = 'div', className, children, ...props }) {
    return (
        <Tag className={cx(ui.panelFooter, className)} {...props}>
            {children}
        </Tag>
    );
}

Panel.Heading = Heading;
Panel.Title = Title;
Panel.Body = Body;
Panel.Footer = Footer;
