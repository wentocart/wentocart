/**
 * Renders API-sanitized rich HTML (HtmlSanitizer on the server).
 * Never pass unsanitized user HTML here.
 */
export default function SafeHtml({ html, as: Tag = 'div', className = '', ...rest }) {
    const markup = typeof html === 'string' ? html.trim() : '';
    if (!markup) {
        return null;
    }

    return (
        <Tag
            className={className || undefined}
            dangerouslySetInnerHTML={{ __html: markup }}
            {...rest}
        />
    );
}
