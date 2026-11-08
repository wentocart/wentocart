import { useState } from 'react';
import { ui, cx } from './classes';
import { t } from './i18n';
import { FieldError } from './Alert';

/**
 * Type a word + Enter → chip. Backspace on empty input removes last chip.
 * value: string[] or comma-separated string (normalized to string[] in onChange as string[]).
 */
export default function TagInput({
    value = [],
    onChange,
    placeholder = t('admin.common.type_and_press_enter', 'Type and press Enter'),
    error,
    className,
}) {
    const [draft, setDraft] = useState('');
    const tags = Array.isArray(value)
        ? value.map((t) => String(t).trim()).filter(Boolean)
        : String(value || '')
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean);

    const commit = (raw) => {
        const next = String(raw || '')
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean);
        if (!next.length) {
            return;
        }
        const merged = [...tags];
        next.forEach((tag) => {
            if (!merged.some((t) => t.toLowerCase() === tag.toLowerCase())) {
                merged.push(tag);
            }
        });
        onChange?.(merged);
        setDraft('');
    };

    const removeAt = (index) => {
        onChange?.(tags.filter((_, i) => i !== index));
    };

    return (
        <div>
            <div
                className={cx(ui.tagInputBox, className)}
                onClick={(e) => e.currentTarget.querySelector('input')?.focus()}
            >
                {tags.map((tag, index) => (
                    <span key={`${tag}-${index}`} className={ui.tagChip}>
                        {tag}
                        <button
                            type="button"
                            className={ui.tagChipRemove}
                            aria-label={`${t('admin.common.remove', 'Remove')} ${tag}`}
                            onClick={(e) => {
                                e.stopPropagation();
                                removeAt(index);
                            }}
                        >
                            ×
                        </button>
                    </span>
                ))}
                <input
                    className={ui.tagInputField}
                    value={draft}
                    placeholder={tags.length ? '' : placeholder}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            commit(draft);
                        } else if (e.key === 'Backspace' && draft === '' && tags.length) {
                            e.preventDefault();
                            removeAt(tags.length - 1);
                        }
                    }}
                    onBlur={() => {
                        if (draft.trim()) {
                            commit(draft);
                        }
                    }}
                />
            </div>
            <FieldError>{error}</FieldError>
        </div>
    );
}
