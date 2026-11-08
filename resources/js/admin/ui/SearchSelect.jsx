import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { FieldError } from './Alert';
import { Label } from './Field';
import { ui, cx } from './classes';
import { t } from './i18n';

/**
 * Typeahead multi-select for admin forms.
 * - Local mode: pass `options` (filtered as you type).
 * - Remote mode: pass `searchUrl` (JSON `{ results: [{ id, name }] }`, limited server-side).
 */
export default function SearchSelect({
    label,
    value,
    onChange,
    options = [],
    selectedOptions = [],
    searchUrl,
    placeholder = t('admin.common.type_to_search', 'Type to search…'),
    error,
    hint,
    allowFreeText = false,
    multiple = true,
    required,
}) {
    const inputId = useId();
    const rootRef = useRef(null);
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [remote, setRemote] = useState([]);
    const [known, setKnown] = useState(() => {
        const map = new Map();
        [...options, ...selectedOptions].forEach((opt) => {
            if (opt?.id != null) {
                map.set(String(opt.id), { id: opt.id, name: opt.name || String(opt.id) });
            }
        });
        return map;
    });

    useEffect(() => {
        setKnown((prev) => {
            const next = new Map(prev);
            [...options, ...selectedOptions].forEach((opt) => {
                if (opt?.id != null) {
                    next.set(String(opt.id), { id: opt.id, name: opt.name || String(opt.id) });
                }
            });
            return next;
        });
    }, [options, selectedOptions]);

    useEffect(() => {
        const onDoc = (e) => {
            if (!rootRef.current?.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    useEffect(() => {
        if (!searchUrl || !open) {
            return undefined;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = `${searchUrl}${searchUrl.includes('?') ? '&' : '?'}q=${encodeURIComponent(query.trim())}`;
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await res.json().catch(() => ({ results: [] }));
                const results = res.ok ? (payload.results || []) : [];
                setRemote(results);
                setKnown((prev) => {
                    const next = new Map(prev);
                    results.forEach((opt) => next.set(String(opt.id), { id: opt.id, name: opt.name }));
                    return next;
                });
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setRemote([]);
                }
            } finally {
                setLoading(false);
            }
        }, query.trim() ? 250 : 0);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [searchUrl, open, query]);

    const ids = useMemo(() => {
        if (multiple) {
            return Array.isArray(value) ? value : [];
        }
        if (Array.isArray(value)) {
            return value.slice(0, 1);
        }
        if (value === '' || value == null) {
            return [];
        }
        return [value];
    }, [multiple, value]);

    const selected = useMemo(
        () => ids.map((id) => known.get(String(id)) || { id, name: `#${id}` }),
        [ids, known],
    );

    const suggestions = useMemo(() => {
        const selectedSet = new Set(ids.map(String));
        const source = searchUrl ? remote : options;
        const q = query.trim().toLowerCase();
        return source
            .filter((opt) => !selectedSet.has(String(opt.id)))
            .filter((opt) => !q || String(opt.name).toLowerCase().includes(q) || String(opt.id).includes(q))
            .slice(0, 25);
    }, [searchUrl, remote, options, ids, query]);

    const emit = (nextIds, picked = null) => {
        if (multiple) {
            onChange?.(nextIds);
            return;
        }
        const id = nextIds[0] ?? '';
        onChange?.(id, picked || (id !== '' ? known.get(String(id)) || { id, name: String(id) } : null));
    };

    const add = (opt) => {
        const id = allowFreeText && typeof opt === 'string' ? opt : opt.id;
        const name = allowFreeText && typeof opt === 'string' ? opt : opt.name;
        if (id == null || id === '') {
            return;
        }
        const picked = { id, name: name || String(id) };
        const nextIds = multiple ? [...new Set([...ids, id])] : [id];
        setKnown((prev) => {
            const next = new Map(prev);
            next.set(String(id), picked);
            return next;
        });
        emit(nextIds, picked);
        setQuery('');
        setOpen(multiple);
    };

    const remove = (id) => {
        emit(ids.filter((item) => String(item) !== String(id)));
    };

    const onKeyDown = (e) => {
        if (e.key === 'Backspace' && !query && selected.length) {
            remove(selected[selected.length - 1].id);
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (suggestions[0]) {
                add(suggestions[0]);
            } else if (allowFreeText && query.trim()) {
                add(query.trim());
            }
        }
        if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div>
            {label ? (
                <Label htmlFor={inputId} required={required}>
                    {label}
                </Label>
            ) : null}
            <div className={cx(ui.searchSelect, label ? 'mt-1.5' : undefined)} ref={rootRef}>
                <div
                    className={ui.searchSelectBox}
                    onClick={() => {
                        setOpen(true);
                        document.getElementById(inputId)?.focus();
                    }}
                >
                    {selected.length ? (
                        <div className={ui.searchSelectChips}>
                            {selected.map((item) => (
                                <span key={String(item.id)} className={ui.searchSelectChip}>
                                    {item.name}
                                    <button
                                        type="button"
                                        className={ui.searchSelectChipRemove}
                                        aria-label={`Remove ${item.name}`}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            remove(item.id);
                                        }}
                                    >
                                        ×
                                    </button>
                                </span>
                            ))}
                        </div>
                    ) : null}
                    <input
                        id={inputId}
                        className={ui.searchSelectInput}
                        value={query}
                        placeholder={selected.length ? (multiple ? t('admin.common.add_more', 'Add more…') : t('admin.common.change', 'Change…')) : placeholder}
                        autoComplete="off"
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKeyDown}
                    />
                </div>

                {open ? (
                    <div className={ui.searchSelectMenu} role="listbox">
                        {loading ? <div className={ui.searchSelectEmpty}>{t('admin.common.searching', 'Searching…')}</div> : null}
                        {!loading && suggestions.length === 0 ? (
                            <div className={ui.searchSelectEmpty}>
                                {query.trim()
                                    ? allowFreeText
                                        ? `${t('admin.common.press_enter_to_add', 'Press Enter to add')} “${query.trim()}”`
                                        : t('admin.common.no_matches', 'No matches')
                                    : searchUrl
                                      ? t('admin.common.type_to_search', 'Type to search')
                                      : t('admin.common.no_options', 'No options')}
                            </div>
                        ) : null}
                        {!loading
                            ? suggestions.map((opt) => (
                                  <button
                                      key={String(opt.id)}
                                      type="button"
                                      role="option"
                                      className={cx(ui.searchSelectOption)}
                                      onMouseDown={(e) => {
                                          e.preventDefault();
                                          e.stopPropagation();
                                          add(opt);
                                      }}
                                  >
                                      {opt.name}
                                  </button>
                              ))
                            : null}
                    </div>
                ) : null}
            </div>
            {hint ? <p className={`${ui.metaK} mt-1`}>{hint}</p> : null}
            <FieldError>{error}</FieldError>
        </div>
    );
}
