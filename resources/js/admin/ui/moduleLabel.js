import { t } from './i18n';

/** Resolve extension/theme page-document copy through admin i18n when a key is present. */
export function moduleLabel(row, fallback = '') {
    if (!row || typeof row !== 'object') {
        return fallback;
    }
    const key = row.label_key || row.title_key || row.hint_key || row.text_key || row.empty_key || row.delete_confirm_key;
    const english = row.label ?? row.title ?? row.hint ?? row.text ?? row.empty ?? row.delete_confirm ?? fallback;
    if (key && typeof key === 'string') {
        return t(key, english || fallback || key);
    }

    return english || fallback;
}

export function moduleTitle(document, fallback = '') {
    if (!document || typeof document !== 'object') {
        return fallback;
    }
    if (document.title_key) {
        return t(document.title_key, document.title || fallback);
    }

    return document.title || fallback;
}

export function moduleHint(document) {
    if (!document || typeof document !== 'object' || !document.hint) {
        return '';
    }
    if (document.hint_key) {
        return t(document.hint_key, document.hint);
    }

    return document.hint;
}

/** Resolve label + hint (+ options) on a hosted field for display. */
export function resolveFieldCopy(field) {
    if (!field || typeof field !== 'object') {
        return field;
    }
    const next = {
        ...field,
        label: moduleLabel(field, field.label || ''),
        hint: field.hint_key
            ? t(field.hint_key, field.hint || '')
            : (field.hint || ''),
    };
    if (Array.isArray(field.options)) {
        next.options = field.options.map((option) => ({
            ...option,
            label: moduleLabel(option, option.label || option.value || ''),
        }));
    }
    if (field.link && typeof field.link === 'object' && field.link.text_key) {
        next.link = {
            ...field.link,
            text: t(field.link.text_key, field.link.text || ''),
        };
    }

    return next;
}
