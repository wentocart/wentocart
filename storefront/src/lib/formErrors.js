/**
 * Parse Laravel / module JSON errors without clearing the caller's form state.
 */
export function parseApiErrors(error, fallback = 'Please fix the highlighted fields.') {
    const payload = error?.response?.data;
    const fields = {};
    const messages = [];

    const push = (text) => {
        const value = String(text || '').trim();
        if (value && !messages.includes(value)) {
            messages.push(value);
        }
    };

    if (payload?.errors && typeof payload.errors === 'object' && !Array.isArray(payload.errors)) {
        Object.entries(payload.errors).forEach(([key, value]) => {
            const list = Array.isArray(value) ? value : [value];
            const text = list.map((item) => String(item || '').trim()).filter(Boolean).join(' ');
            if (!text) {
                return;
            }
            const name = String(key).replace(/^values\./, '');
            if (name) {
                fields[name] = list[0] ? String(list[0]) : text;
            }
            push(text);
        });
    }

    if (Array.isArray(payload?.document?.errors)) {
        payload.document.errors.forEach(push);
    }
    if (payload?.document?.field_errors && typeof payload.document.field_errors === 'object') {
        Object.entries(payload.document.field_errors).forEach(([key, value]) => {
            const text = Array.isArray(value) ? value[0] : value;
            if (text) {
                fields[key] = String(text);
                push(text);
            }
        });
    }
    if (payload?.message) {
        push(payload.message);
    }

    return {
        message: messages[0] || fallback,
        messages,
        fields,
    };
}
