import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { t } from '../i18n.js';
import { Icon } from './Icons.jsx';

/**
 * Typeahead select for Seller Portal forms (local options only).
 * Single mode: dropdown trigger + searchable option list (admin SearchSelect parity).
 * Multiple mode: chip input + searchable suggestions.
 */
export default function SellerSearchSelect({
    label,
    value,
    onChange,
    options = [],
    selectedOptions = [],
    placeholder = t('storefront.seller.select_option', 'Select…'),
    searchPlaceholder = t('storefront.seller.type_to_search', 'Type to search…'),
    error,
    hint,
    multiple = false,
    required = false,
}) {
    const inputId = useId();
    const searchRef = useRef(null);
    const rootRef = useRef(null);
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
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
        const onDoc = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
                setQuery('');
            }
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    useEffect(() => {
        if (open && !multiple) {
            window.requestAnimationFrame(() => searchRef.current?.focus());
        }
    }, [open, multiple]);

    const ids = useMemo(() => {
        if (multiple) {
            return (Array.isArray(value) ? value : []).map(String);
        }
        if (value === '' || value == null) {
            return [];
        }
        return [String(value)];
    }, [multiple, value]);

    const selected = useMemo(
        () => ids.map((id) => known.get(id) || { id, name: `#${id}` }),
        [ids, known],
    );

    const suggestions = useMemo(() => {
        const q = query.trim().toLowerCase();
        const selectedSet = multiple ? new Set(ids) : new Set();
        return options
            .filter((opt) => !selectedSet.has(String(opt.id)))
            .filter((opt) => !q || String(opt.name || '').toLowerCase().includes(q))
            .slice(0, 50);
    }, [options, ids, query, multiple]);

    const emit = (nextIds) => {
        if (multiple) {
            onChange?.(nextIds.map(Number));
            return;
        }
        onChange?.(nextIds[0] ?? '');
    };

    const add = (opt) => {
        const nextIds = multiple ? [...new Set([...ids, String(opt.id)])] : [String(opt.id)];
        setKnown((prev) => {
            const next = new Map(prev);
            next.set(String(opt.id), { id: opt.id, name: opt.name || String(opt.id) });
            return next;
        });
        emit(nextIds);
        setQuery('');
        setOpen(multiple);
    };

    const remove = (id) => {
        emit(ids.filter((item) => item !== String(id)));
    };

    const toggleOpen = () => {
        setOpen((current) => {
            const next = !current;
            if (!next) {
                setQuery('');
            }
            return next;
        });
    };

    const onSearchKeyDown = (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
            setQuery('');
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (suggestions[0]) {
                add(suggestions[0]);
            }
        }
    };

    const menu = (
        <div className="mv-search-select-menu" role="listbox">
            <div className="mv-search-select-search-wrap">
                <Icon name="search" className="mv-search-select-search-icon" />
                <input
                    ref={searchRef}
                    id={inputId}
                    className="mv-search-select-search"
                    value={query}
                    placeholder={searchPlaceholder}
                    autoComplete="off"
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={onSearchKeyDown}
                    aria-invalid={error ? true : undefined}
                />
            </div>
            {suggestions.length === 0 ? (
                <p className="mv-search-select-empty">
                    {query.trim()
                        ? t('storefront.seller.no_matches', 'No matches')
                        : t('storefront.seller.no_options', 'No options')}
                </p>
            ) : suggestions.map((opt) => (
                <button
                    key={opt.id}
                    type="button"
                    className={`mv-search-select-option${ids[0] === String(opt.id) ? ' is-selected' : ''}`}
                    role="option"
                    aria-selected={ids[0] === String(opt.id)}
                    onMouseDown={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        add(opt);
                    }}
                >
                    {opt.name}
                </button>
            ))}
        </div>
    );

    return (
        <div className="shop-field" ref={rootRef}>
            {label ? (
                <span className="shop-field-label">
                    {label}
                    {required ? <span className="text-red-600" aria-hidden="true"> *</span> : null}
                </span>
            ) : null}

            {multiple ? (
                <div className={`mv-search-select${error ? ' is-invalid' : ''}`}>
                    <div
                        className="mv-search-select-box"
                        onClick={() => {
                            setOpen(true);
                            document.getElementById(inputId)?.focus();
                        }}
                    >
                        {selected.length ? (
                            <div className="mv-search-select-tags">
                                {selected.map((item) => (
                                    <span key={String(item.id)} className="mv-search-select-tag">
                                        {item.name}
                                        <button
                                            type="button"
                                            aria-label={t('storefront.seller.remove', 'Remove')}
                                            onClick={(event) => {
                                                event.stopPropagation();
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
                            className="mv-search-select-input"
                            value={query}
                            placeholder={selected.length ? t('storefront.seller.add_more', 'Add more…') : placeholder}
                            onChange={(e) => {
                                setQuery(e.target.value);
                                setOpen(true);
                            }}
                            onFocus={() => setOpen(true)}
                            aria-invalid={error ? true : undefined}
                        />
                    </div>
                    {open ? menu : null}
                </div>
            ) : (
                <div className={`mv-search-select mv-search-select--single${error ? ' is-invalid' : ''}${open ? ' is-open' : ''}`}>
                    <button
                        type="button"
                        className="mv-search-select-trigger shop-input"
                        aria-haspopup="listbox"
                        aria-expanded={open}
                        onClick={toggleOpen}
                    >
                        <span className={`mv-search-select-trigger-label${selected[0] ? '' : ' is-placeholder'}`}>
                            {selected[0]?.name || placeholder}
                        </span>
                        <Icon name="chevron-down" className={`mv-search-select-chevron${open ? ' is-open' : ''}`} />
                    </button>
                    {open ? menu : null}
                </div>
            )}

            {error ? <em className="shop-field-error" role="alert">{error}</em> : hint ? <em className="shop-field-hint">{hint}</em> : null}
        </div>
    );
}
