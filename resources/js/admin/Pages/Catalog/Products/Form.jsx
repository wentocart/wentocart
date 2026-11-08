import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    AiAssist,
    PageHeader,
    RowActions,
    Select,
    StatusTabs,
    Textarea,
    Table,
    THead,
    Th,
    Tr,
    Td,
    SearchSelect,
    TagInput,
    Toggle,
    ChoiceCards,
    withRequiredMark,
    Label,
    RateTiersField,
    Tooltip,
    useGrowl,
    ui,
    cx,
    t,
} from '../../../ui';
import Editor from '../../../ui/Editor';
import { catalogImageUrl } from '../../../lib/catalogMedia';
import PRODUCT_TYPE_OPTIONS, { tabsForProduct, typeConfig, typeSupportsCapability, normalizeProductType } from './productTypes';
import CustomizationTab, { customizationFieldsFromProduct, customizationFieldsPayload } from './CustomizationTab';
import ProductSubscriptionTab, {
    assignedPlansPayload,
    PRODUCT_REQUIRES_ACTIVE_PLAN,
    productRequiresActivePlan,
    hasActiveAssignedPlan,
} from './ProductSubscriptionTab';
import ComponentItemsTab, { composedPrice, derivedQty, itemsSubtotal } from './ComponentItemsTab';
import CombinationsTab from './CombinationsTab';
import GiftCardTab, { defaultGiftCardConfig, giftCardPayload } from './GiftCardTab';
import ServiceTab, { defaultServiceConfig, servicePayload } from './ServiceTab';
import RentalTab, { defaultRentalConfig, rentalPayload } from './RentalTab';
import PreorderTab, { defaultPreorderConfig, preorderPayload } from './PreorderTab';
import { tabsForFormErrors } from './productFormErrors';
import { SUBSCRIPTION_PREORDER_CONFLICT } from './productModeConflict';

function qtyInputValue(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? String(number) : '';
}

function Section({ title, hint, children, required }) {
    return (
        <section className={ui.sectionCard}>
            {title ? (
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{withRequiredMark(title, required)}</h2>
                        {hint ? <p className={ui.muted}>{hint}</p> : null}
                    </div>
                </div>
            ) : null}
            {children}
        </section>
    );
}

function ProductQrCard({ url, saved = false }) {
    const [src, setSrc] = useState('');

    useEffect(() => {
        if (!url) {
            setSrc('');
            return undefined;
        }
        let cancelled = false;
        import('qrcode').then((mod) => {
            const QRCode = mod.default?.toDataURL ? mod.default : mod;
            return QRCode.toDataURL(url, { margin: 1, width: 192, errorCorrectionLevel: 'M' });
        }).then((data) => {
            if (!cancelled) {
                setSrc(data);
            }
        }).catch(() => {
            if (!cancelled) {
                setSrc('');
            }
        });

        return () => {
            cancelled = true;
        };
    }, [url]);

    return (
        <div className="flex flex-wrap items-center gap-4 rounded-xl border border-neutral-200 bg-neutral-50 px-5 py-4">
            {src ? (
                <img src={src} alt="QR code for this product" width={160} height={160} className="h-40 w-40 rounded-lg bg-white p-2" />
            ) : (
                <div className="flex h-40 w-40 items-center justify-center rounded-lg bg-white px-3 text-center text-sm text-neutral-500">
                    {saved ? 'No page address yet' : 'Save the product first'}
                </div>
            )}
            <div className="min-w-0 flex-1">
                <div className={ui.label}>Product QR code</div>
                <p className={cx(ui.muted, 'mt-1')}>Customers scan this to open the product page.</p>
                {url ? <p className={cx(ui.muted, 'mt-2 break-all')}>{url}</p> : null}
                {src ? (
                    <div className="mt-3">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                const link = document.createElement('a');
                                link.href = src;
                                link.download = 'product-qr.png';
                                link.click();
                            }}
                        >
                            Download
                        </Button>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function RelatedProductPicker({ selected, excludeId, onChange }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const rootRef = useRef(null);
    const selectedIds = selected.map((item) => item.id);

    useEffect(() => {
        const onDoc = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setResults([]);
            }
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    useEffect(() => {
        const q = query.trim();
        if (q.length < 1) {
            setResults([]);
            return undefined;
        }
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q });
            if (excludeId) {
                params.set('exclude', String(excludeId));
            }
            fetch(`/admin/products/search?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => (res.ok ? res.json() : []))
                .then((rows) => setResults(Array.isArray(rows) ? rows.filter((row) => !selectedIds.includes(row.id)) : []))
                .catch(() => setResults([]));
        }, 250);
        return () => clearTimeout(timer);
    }, [query, excludeId, selectedIds.join(',')]);

    return (
        <Field label="Related Products">
            <div ref={rootRef}>
                <div className={cx(ui.filterSearch, 'w-full min-w-0')}>
                    <Icon name="search" className={ui.filterSearchIcon} />
                    <input
                        className={ui.filterSearchInput}
                        type="text"
                        value={query}
                        placeholder="Search products"
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
                {results.length > 0 ? (
                    <div className="mt-1 max-h-52 overflow-y-auto rounded-xl border border-neutral-200 bg-white py-1 shadow-sm">
                        {results.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                className="block w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50"
                                onClick={() => {
                                    onChange([...selected, row]);
                                    setQuery('');
                                    setResults([]);
                                }}
                            >
                                {row.name} ({row.sku})
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            <p className={cx(ui.muted, 'mt-1')}>Type to search products. You can add multiple products.</p>
            {selected.length > 0 ? (
                <div className="mt-2 flex flex-wrap gap-2">
                    {selected.map((item) => (
                        <span key={item.id} className="inline-flex items-center gap-1 rounded-lg border border-neutral-200 bg-white px-2 py-1 text-sm">
                            {item.name || item.sku}
                            <Button
                                type="button"
                                variant="ghost"
                                aria-label={`Remove ${item.name || item.sku}`}
                                onClick={() => onChange(selected.filter((row) => row.id !== item.id))}
                            >
                                ×
                            </Button>
                        </span>
                    ))}
                </div>
            ) : null}
        </Field>
    );
}

function savedGalleryItem(img) {
    return {
        key: `saved:${img.id}`,
        id: img.id,
        path: img.path,
        url: catalogImageUrl(img.url || img.path, 'md'),
        file: null,
    };
}

function CatalogImg({ src, alt, className }) {
    const fallback = catalogImageUrl(null, 'md');
    const [url, setUrl] = useState(() => catalogImageUrl(src, 'md'));

    useEffect(() => {
        setUrl(catalogImageUrl(src, 'md'));
    }, [src]);

    return (
        <img
            src={url}
            alt={alt}
            draggable={false}
            className={className}
            onError={() => setUrl(fallback)}
        />
    );
}

function pendingGalleryItem(file) {
    const url = URL.createObjectURL(file);
    return { key: `pending:${url}`, id: null, path: null, url, file };
}

function moveCoverFirst(items, key) {
    const cover = items.find((item) => item.key === key);
    if (!cover) {
        return items;
    }
    return [cover, ...items.filter((item) => item.key !== key)];
}

function initialGallery(product) {
    const imgs = [...(product?.images || [])];
    const items = imgs.map(savedGalleryItem);
    const cover = imgs.find((img) => img.path === product?.cover_path);
    return cover ? moveCoverFirst(items, `saved:${cover.id}`) : items;
}

function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}

function CoverThumb({ src, alt, isCover, sortable, isOver, onSetCover, onClearCover, onRemove, onDragStart, onDragOver, onDrop, onDragEnd }) {
    return (
        <div
            className={cx('min-w-0', sortable && 'cursor-grab', isOver && 'outline outline-2 outline-violet-700 outline-offset-2 rounded-xl')}
            draggable={!!sortable}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDrop={onDrop}
            onDragEnd={onDragEnd}
        >
            <div className="group relative aspect-square overflow-hidden rounded-xl bg-neutral-100">
                <CatalogImg src={src} alt={alt} className="h-full w-full object-contain" />
                {onRemove ? (
                    <div className="absolute top-1.5 right-1.5 z-[2]">
                        <Tooltip content={t('admin.products.remove_image', 'Remove image')} portal placement="bottom">
                            <button
                                type="button"
                                className="inline-flex cursor-pointer text-red-600 drop-shadow-sm hover:text-red-700"
                                aria-label={t('admin.products.remove_image', 'Remove image')}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onRemove();
                                }}
                            >
                                <Icon name="trash" />
                            </button>
                        </Tooltip>
                    </div>
                ) : null}
                <label
                    className={cx(
                        'absolute inset-x-0 bottom-0 z-[1] m-0 flex cursor-pointer items-center gap-1.5 border-t border-neutral-200 bg-white px-2 py-1',
                        isCover ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100',
                    )}
                    onClick={(e) => e.stopPropagation()}
                >
                    <input
                        type="checkbox"
                        className={cx(ui.check, 'cursor-pointer')}
                        checked={isCover}
                        onChange={(e) => {
                            if (e.target.checked) {
                                onSetCover();
                            } else {
                                onClearCover?.();
                            }
                        }}
                    />
                    <span className={cx(ui.cellSub, 'm-0', isCover && 'text-violet-700')}>{isCover ? 'Cover' : 'Set as cover'}</span>
                </label>
            </div>
        </div>
    );
}

function ImageGallery({ items, coverKey, alt, onSetCover, onClearCover, onRemove, onReorder }) {
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);
    const coverLocked = Boolean(coverKey);

    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {items.map((item, index) => {
                const isCover = coverKey === item.key;
                const sortable = coverLocked ? index > 0 : items.length > 1;
                const allowDrop = !(coverLocked && index === 0);
                return (
                    <CoverThumb
                        key={item.key}
                        src={item.url}
                        alt={alt}
                        isCover={isCover}
                        sortable={sortable}
                        isOver={allowDrop && overIndex === index && fromIndex !== null && fromIndex !== index}
                        onSetCover={() => onSetCover(item)}
                        onClearCover={onClearCover}
                        onRemove={() => onRemove(item)}
                        onDragStart={(e) => {
                            if (!sortable || e.target.closest('input, label, button')) {
                                e.preventDefault();
                                return;
                            }
                            e.dataTransfer.effectAllowed = 'move';
                            e.dataTransfer.setData('text/plain', item.key);
                            setFromIndex(index);
                        }}
                        onDragOver={(e) => {
                            if (!allowDrop) {
                                return;
                            }
                            e.preventDefault();
                            e.dataTransfer.dropEffect = 'move';
                            setOverIndex(index);
                        }}
                        onDrop={(e) => {
                            e.preventDefault();
                            const origin = fromIndex;
                            setFromIndex(null);
                            setOverIndex(null);
                            if (origin == null || origin === index || (coverLocked && (origin === 0 || index === 0))) {
                                return;
                            }
                            onReorder(origin, index);
                        }}
                        onDragEnd={() => {
                            setFromIndex(null);
                            setOverIndex(null);
                        }}
                    />
                );
            })}
        </div>
    );
}

function ImageDropzone({ onFiles }) {
    const [dragging, setDragging] = useState(false);
    const [inputKey, setInputKey] = useState(0);

    const takeFiles = (list) => {
        onFiles(list);
        setInputKey((key) => key + 1);
    };

    return (
        <label
            className={cx(
                'grid cursor-pointer place-items-center gap-2 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-3 py-4 text-center',
                dragging && 'border-violet-700 bg-violet-50',
            )}
            onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                takeFiles(e.dataTransfer.files);
            }}
        >
            <input
                key={inputKey}
                type="file"
                accept="image/*"
                multiple
                className="hidden"
                onChange={(e) => takeFiles(e.target.files)}
            />
            <Icon name="upload" />
            <p className="m-0 text-sm font-medium text-neutral-600">
                <span className="text-violet-700">Choose Files</span>
                {' '}or drag and drop
            </p>
        </label>
    );
}

function categoryLabel(category) {
    return category?.translations?.[0]?.name || 'Untitled';
}

function buildCategoryTree(categories) {
    const nodes = (categories || []).map((category) => ({ ...category, children: [] }));
    const byId = new Map(nodes.map((node) => [Number(node.id), node]));
    const roots = [];

    nodes.forEach((node) => {
        const parentId = node.parent_id ? Number(node.parent_id) : null;
        if (parentId && byId.has(parentId)) {
            byId.get(parentId).children.push(node);
        } else {
            roots.push(node);
        }
    });

    const sortNodes = (list) => {
        list.sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || Number(a.id) - Number(b.id));
        list.forEach((node) => sortNodes(node.children));
    };
    sortNodes(roots);

    return roots;
}

function filterCategoryTree(nodes, needle, selectedSet) {
    return (nodes || []).reduce((acc, node) => {
        const children = filterCategoryTree(node.children || [], needle, selectedSet);
        const name = categoryLabel(node).toLowerCase();
        const slug = String(node.translations?.[0]?.slug || '').toLowerCase();
        const allowed = node.is_enabled !== false || selectedSet.has(Number(node.id));
        const matches = !needle || name.includes(needle) || slug.includes(needle);
        if (allowed && (matches || children.length > 0)) {
            acc.push({ ...node, children });
        }
        return acc;
    }, []);
}

function collectExpandableCategoryIds(nodes, acc = []) {
    (nodes || []).forEach((node) => {
        if ((node.children || []).length > 0) {
            acc.push(Number(node.id));
            collectExpandableCategoryIds(node.children, acc);
        }
    });
    return acc;
}

function collectOpenIdsForCategorySearch(nodes, needle, ancestors = [], acc = new Set()) {
    if (!needle) {
        return acc;
    }
    (nodes || []).forEach((node) => {
        const childHits = new Set();
        collectOpenIdsForCategorySearch(node.children || [], needle, [...ancestors, Number(node.id)], childHits);
        childHits.forEach((id) => acc.add(id));
        const name = categoryLabel(node).toLowerCase();
        const slug = String(node.translations?.[0]?.slug || '').toLowerCase();
        const selfMatch = name.includes(needle) || slug.includes(needle);
        if (selfMatch || childHits.size > 0) {
            ancestors.forEach((id) => acc.add(id));
            if ((node.children || []).length > 0) {
                acc.add(Number(node.id));
            }
        }
    });
    return acc;
}

function CategoryTreeNode({
    node,
    depth,
    openIds,
    onToggle,
    selectedSet,
    defaultCategoryId,
    onToggleId,
    onSetDefault,
}) {
    const id = Number(node.id);
    const hasChildren = (node.children || []).length > 0;
    const open = openIds.has(id);
    const checked = selectedSet.has(id);
    const isDefault = checked && Number(defaultCategoryId) === id;
    const disabled = node.is_enabled === false;

    return (
        <>
            <div className={ui.treePickerRow} style={{ paddingLeft: `${8 + depth * 16}px` }}>
                {hasChildren ? (
                    <button
                        type="button"
                        className={ui.treePickerToggle}
                        aria-expanded={open}
                        aria-label={open ? 'Collapse' : 'Expand'}
                        onClick={() => onToggle(id)}
                    >
                        <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                    </button>
                ) : (
                    <span className={ui.treePickerToggleSpacer} aria-hidden="true" />
                )}
                <label className={cx(ui.checkboxRow, 'min-w-0 flex-1')}>
                    <input
                        type="checkbox"
                        className={ui.check}
                        checked={checked}
                        onChange={(e) => onToggleId(id, e.target.checked)}
                    />
                    <span className={ui.treePickerLabel} title={categoryLabel(node)}>
                        {categoryLabel(node)}
                        {disabled ? ' (Disabled)' : ''}
                    </span>
                </label>
                {isDefault ? (
                    <Badge tone="primary">Default</Badge>
                ) : checked ? (
                    <button
                        type="button"
                        className={ui.treePickerHint}
                        onClick={() => onSetDefault(id)}
                    >
                        Set default
                    </button>
                ) : hasChildren ? (
                    <span className={ui.treePickerHint}>{node.children.length}</span>
                ) : null}
            </div>
            {hasChildren && open
                ? node.children.map((child) => (
                    <CategoryTreeNode
                        key={child.id}
                        node={child}
                        depth={depth + 1}
                        openIds={openIds}
                        onToggle={onToggle}
                        selectedSet={selectedSet}
                        defaultCategoryId={defaultCategoryId}
                        onToggleId={onToggleId}
                        onSetDefault={onSetDefault}
                    />
                ))
                : null}
        </>
    );
}

function CategoryPicker({
    categories,
    selectedIds,
    onChange,
    defaultCategoryId,
    onDefaultChange,
    error,
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const selected = selectedIds.map(Number);
    const selectedKey = selected.join(',');
    const selectedSet = useMemo(() => new Set(selected), [selectedKey]);
    const tree = useMemo(() => buildCategoryTree(categories), [categories]);
    const visible = useMemo(
        () => filterCategoryTree(tree, needle, selectedSet),
        [tree, needle, selectedSet],
    );
    const [openIds, setOpenIds] = useState(() => new Set());
    const [userToggled, setUserToggled] = useState(false);

    useEffect(() => {
        if (needle) {
            setOpenIds(collectOpenIdsForCategorySearch(visible, needle));
            setUserToggled(false);
            return;
        }
        if (!userToggled) {
            setOpenIds(new Set());
        }
    }, [needle, visible, userToggled]);

    const selectedCategories = selected
        .map((id) => categories.find((category) => Number(category.id) === id))
        .filter(Boolean);

    const toggleOpen = (id) => {
        setUserToggled(true);
        setOpenIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const toggleId = (id, checked) => {
        onChange(checked ? [...selected, id] : selected.filter((value) => value !== id));
    };

    const setDefault = (id) => {
        if (!selectedSet.has(Number(id))) {
            return;
        }
        onDefaultChange?.(id);
    };

    return (
        <div className="space-y-3">
            <div className={ui.filterSearch}>
                <Icon name="search" className={ui.filterSearchIcon} />
                <input
                    className={ui.filterSearchInput}
                    type="text"
                    value={query}
                    placeholder="Search categories"
                    onChange={(e) => setQuery(e.target.value)}
                />
            </div>

            {selectedCategories.length > 0 ? (
                <div className={ui.searchSelectChips}>
                    {selectedCategories.map((category) => {
                        const id = Number(category.id);
                        const isDefault = Number(defaultCategoryId) === id;
                        return (
                            <span key={id} className={ui.searchSelectChip}>
                                {categoryLabel(category)}
                                {isDefault ? ' · Default' : ''}
                                <button
                                    type="button"
                                    className={ui.searchSelectChipRemove}
                                    aria-label={`Remove ${categoryLabel(category)}`}
                                    onClick={() => toggleId(id, false)}
                                >
                                    ×
                                </button>
                            </span>
                        );
                    })}
                </div>
            ) : (
                <p className={ui.metaK}>Select at least one category. Mark one as default for the product URL.</p>
            )}

            <div className="flex flex-wrap items-center gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        setUserToggled(true);
                        setOpenIds(new Set(collectExpandableCategoryIds(visible)));
                    }}
                >
                    Expand all
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        setUserToggled(true);
                        setOpenIds(new Set());
                    }}
                >
                    Collapse all
                </Button>
            </div>

            <div className={ui.treePicker}>
                {visible.map((node) => (
                    <CategoryTreeNode
                        key={node.id}
                        node={node}
                        depth={0}
                        openIds={openIds}
                        onToggle={toggleOpen}
                        selectedSet={selectedSet}
                        defaultCategoryId={defaultCategoryId}
                        onToggleId={toggleId}
                        onSetDefault={setDefault}
                    />
                ))}
                {categories.length === 0 ? (
                    <div className={cx(ui.muted, 'px-3 py-4')}>No categories yet.</div>
                ) : null}
                {categories.length > 0 && visible.length === 0 ? (
                    <div className={cx(ui.muted, 'px-3 py-4')}>No matching categories.</div>
                ) : null}
            </div>
            {error ? <span className={ui.fieldError}>{error}</span> : null}
        </div>
    );
}

function FeaturePicker({ features, selected, onChange }) {
    const [featureId, setFeatureId] = useState('');
    const [valueId, setValueId] = useState('');
    const feature = features.find((item) => String(item.id) === String(featureId));
    const values = feature?.values || [];

    const add = () => {
        if (!featureId || !valueId) {
            return;
        }
        const next = selected.filter((row) => Number(row.feature_id) !== Number(featureId));
        onChange([...next, { feature_id: Number(featureId), feature_value_id: Number(valueId) }]);
        setValueId('');
    };

    const rows = selected.map((row) => {
        const f = features.find((item) => Number(item.id) === Number(row.feature_id));
        const v = f?.values?.find((item) => Number(item.id) === Number(row.feature_value_id));

        return {
            ...row,
            featureName: f?.translations?.[0]?.name || 'Feature',
            valueName: v?.translations?.[0]?.value || '—',
        };
    });

    return (
        <div>
            <div className={cx(ui.row, 'flex-wrap mb-2')}>
                <Select
                    value={featureId}
                    onChange={(e) => {
                        setFeatureId(e.target.value);
                        setValueId('');
                    }}
                >
                    <option value="">Choose a feature</option>
                    {features.map((item) => (
                        <option key={item.id} value={item.id}>{item.translations?.[0]?.name}</option>
                    ))}
                </Select>
                <Select
                    value={valueId}
                    className="flex-1"
                    disabled={!featureId}
                    onChange={(e) => setValueId(e.target.value)}
                >
                    <option value="">Choose a value or provide a customized one</option>
                    {values.map((item) => (
                        <option key={item.id} value={item.id}>{item.translations?.[0]?.value}</option>
                    ))}
                </Select>
                <Button type="button" icon="plus" disabled={!featureId || !valueId} onClick={add}>
                    Add a feature
                </Button>
            </div>
            <Button variant="link" href="/admin/features" className="mb-3">
                Manage features
            </Button>
            {rows.length > 0 ? (
                <div className={ui.tableWrap}>
                    <Table>
                        <THead>
                            <tr>
                                <Th>Feature</Th>
                                <Th>Feature value</Th>
                                <Th></Th>
                            </tr>
                        </THead>
                        <tbody>
                            {rows.map((row) => (
                                <Tr key={`${row.feature_id}-${row.feature_value_id}`}>
                                    <Td>{row.featureName}</Td>
                                    <Td>{row.valueName}</Td>
                                    <Td actions>
                                        <button
                                            type="button"
                                            className={cx(ui.btnDanger, 'cursor-pointer')}
                                            title="Remove"
                                            aria-label="Remove"
                                            onClick={() => onChange(selected.filter((item) => !(Number(item.feature_id) === Number(row.feature_id) && Number(item.feature_value_id) === Number(row.feature_value_id))))}
                                        >
                                            <Icon name="trash" />
                                        </button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            ) : null}
        </div>
    );
}

function attachmentSizeLabel(item) {
    if (item?.file_size_label) {
        return item.file_size_label;
    }
    const bytes = Number(item?.file_size || 0);
    if (!bytes) {
        return '—';
    }
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatAttachmentDate(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Product file library panel.
 * @param {'document'|'paid'} purpose — Media tab uses document (public); Digital Files uses paid.
 */
function ProductFiles({
    purpose = 'document',
    library,
    loading,
    attachedFiles,
    selectedIds,
    onAttach,
    onDetach,
    query,
    onQuery,
    pendingAttachments,
    onAdd,
    onRemovePending,
}) {
    const isPaid = purpose === 'paid';
    const needle = query.trim();
    const searchReady = needle.length >= 3;
    const selected = new Set((selectedIds || []).map(Number));
    const searchRows = searchReady
        ? (library || []).filter((item) => !selected.has(Number(item.id)))
        : [];
    const hasAttached = (attachedFiles || []).length > 0 || (pendingAttachments || []).length > 0;
    const title = isPaid ? 'Digital files' : 'Product attachments';
    const subtitle = isPaid
        ? 'Private files customers receive after purchasing this virtual product. They are never shown on the public product page.'
        : 'Public documents linked to this product (spec sheets, manuals). Removing a file here only detaches it — the file stays in the library.';
    const emptyLabel = isPaid
        ? 'No digital files yet. Upload a new file or attach one from the library below.'
        : 'No files attached yet. Upload a new file or search the library below.';

    return (
        <div className="space-y-4">
            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{title}</h2>
                        <p className={cx(ui.muted, 'mt-1')}>{subtitle}</p>
                    </div>
                    <div className={ui.row}>
                        <Button type="button" variant="secondary" icon="plus" onClick={onAdd}>
                            Add new file
                        </Button>
                    </div>
                </div>
                {!hasAttached ? (
                    <p className={ui.muted}>{emptyLabel}</p>
                ) : (
                    <div className={ui.tableWrap}>
                        <Table>
                            <THead>
                                <tr>
                                    <Th>Title</Th>
                                    <Th>File name</Th>
                                    <Th>Type</Th>
                                    <Th>Size</Th>
                                    {isPaid ? <Th>Uploaded</Th> : null}
                                    <Th></Th>
                                </tr>
                            </THead>
                            <tbody>
                                {(attachedFiles || []).map((item) => {
                                    const id = Number(item.id);
                                    const adminDownload = item.admin_download_url || (id ? `/admin/files/${id}/download` : null);

                                    return (
                                        <Tr key={item.id}>
                                            <Td>{item.name}</Td>
                                            <Td>{item.original_filename}</Td>
                                            <Td>{item.file_type_label || item.mime_type || '—'}</Td>
                                            <Td>{attachmentSizeLabel(item)}</Td>
                                            {isPaid ? <Td>{formatAttachmentDate(item.created_at)}</Td> : null}
                                            <Td actions>
                                                <div className={ui.row}>
                                                    {isPaid && adminDownload ? (
                                                        <a
                                                            className={ui.btnGhost}
                                                            href={adminDownload}
                                                            title="Download"
                                                            aria-label={`Download ${item.name}`}
                                                        >
                                                            <Icon name="download" />
                                                        </a>
                                                    ) : null}
                                                    <button
                                                        type="button"
                                                        className={cx(ui.btnDanger, 'cursor-pointer')}
                                                        title="Remove"
                                                        aria-label={`Remove ${item.name}`}
                                                        onClick={() => onDetach(id)}
                                                    >
                                                        <Icon name="trash" />
                                                    </button>
                                                </div>
                                            </Td>
                                        </Tr>
                                    );
                                })}
                                {(pendingAttachments || []).map((item) => (
                                    <Tr key={item.pendingKey}>
                                        <Td>{item.label}</Td>
                                        <Td>{item.file?.name || item.original_name || '—'}</Td>
                                        <Td>{item.mime_type || 'Pending upload'}</Td>
                                        <Td>—</Td>
                                        {isPaid ? <Td>—</Td> : null}
                                        <Td actions>
                                            <button
                                                type="button"
                                                className={cx(ui.btnDanger, 'cursor-pointer')}
                                                title="Remove"
                                                aria-label={`Remove ${item.label}`}
                                                onClick={() => onRemovePending(item)}
                                            >
                                                <Icon name="trash" />
                                            </button>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </section>

            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>Add from library</h2>
                        <p className={cx(ui.muted, 'mt-1')}>
                            {isPaid
                                ? 'Attach a library file as a digital download for this virtual product. It stays private until the customer buys the product.'
                                : 'Attach files from the library. They are saved as public documents on this product.'}
                        </p>
                    </div>
                </div>
                <div className={cx(ui.filterSearch, 'mb-3')}>
                    <Icon name="search" className={ui.filterSearchIcon} />
                    <input
                        className={ui.filterSearchInput}
                        type="search"
                        value={query}
                        placeholder="Search files"
                        onChange={(e) => onQuery(e.target.value)}
                    />
                </div>
                {!searchReady ? (
                    <p className={ui.muted}>
                        {needle.length > 0
                            ? 'Type at least 3 characters to search the file library.'
                            : 'Type at least 3 characters to find files in the library.'}
                    </p>
                ) : null}
                {searchReady && loading ? <p className={ui.muted}>Searching files…</p> : null}
                {searchReady && !loading ? (
                    <div className={ui.tableWrap}>
                        <Table>
                            <THead>
                                <tr>
                                    <Th />
                                    <Th>Title</Th>
                                    <Th>File name</Th>
                                    <Th>Type</Th>
                                    <Th>Size</Th>
                                </tr>
                            </THead>
                            <tbody>
                                {searchRows.length === 0 ? (
                                    <tr>
                                        <Td className={ui.empty} colSpan={5}>
                                            {(library || []).length
                                                ? 'No other files match this search.'
                                                : 'No files match this search.'}
                                        </Td>
                                    </tr>
                                ) : null}
                                {searchRows.map((item) => {
                                    const id = Number(item.id);

                                    return (
                                        <Tr key={item.id}>
                                            <Td>
                                                <Checkbox
                                                    checked={false}
                                                    onChange={() => onAttach(item)}
                                                    aria-label={`Attach ${item.name}`}
                                                />
                                            </Td>
                                            <Td>{item.name}</Td>
                                            <Td>{item.original_filename}</Td>
                                            <Td>{item.mime_type || '—'}</Td>
                                            <Td>{attachmentSizeLabel(item)}</Td>
                                        </Tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </div>
                ) : null}
            </section>
        </div>
    );
}

function AttachmentModal({ productId, form, purpose = 'document', onQueue, onUploaded, onClose }) {
    const hasFile = Boolean(form.data.file);
    const hasLabel = Boolean(String(form.data.label || '').trim());
    const [uploadBusy, setUploadBusy] = useState(false);
    const isPaid = purpose === 'paid';

    const submitExistingProduct = async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const body = new FormData();
        body.append('name', String(form.data.label).trim());
        body.append('description', form.data.description || '');
        body.append('file', form.data.file);

        setUploadBusy(true);
        form.clearErrors();

        try {
            const response = await fetch('/admin/files', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body,
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const errors = payload.errors || {};
                Object.entries(errors).forEach(([key, messages]) => {
                    form.setError(key, Array.isArray(messages) ? messages[0] : String(messages));
                });
                if (!Object.keys(errors).length && payload.message) {
                    form.setError('file', payload.message);
                }
                return;
            }

            onUploaded?.(payload.file);
            form.reset();
            onClose();
        } finally {
            setUploadBusy(false);
        }
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <form
                className={`${ui.modal} max-w-lg`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="attachment-title"
                onClick={(e) => e.stopPropagation()}
                onSubmit={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!hasFile || !hasLabel) {
                        return;
                    }
                    if (productId) {
                        submitExistingProduct();
                        return;
                    }
                    onQueue({
                        label: String(form.data.label).trim(),
                        description: form.data.description || '',
                        file: form.data.file,
                        pendingKey: `pending-${Date.now()}-${Math.random().toString(36).slice(2)}`,
                    });
                    form.reset();
                    onClose();
                }}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="attachment-title" className={ui.modalTitle}>
                            {isPaid ? 'Add digital file' : 'Add new file'}
                        </h2>
                        <p className={ui.modalSub}>PDF, images, zip, and documents up to 10 MB.</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label="Close" onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    <FormErrors errors={form.errors} />
                    <p className={ui.muted}>
                        {isPaid
                            ? (productId
                                ? 'The file is stored privately in the library and linked as a digital download. Click Save product to keep the link.'
                                : 'The file is kept on this form as a digital download until you click Save product.')
                            : (productId
                                ? 'The file is added to the catalog library and selected for this product. Click Save product to keep the link.'
                                : 'The file is kept on this form until you click Save product.')}
                    </p>
                    <Field
                        label="File name"
                        required
                        error={form.errors.label}
                    >
                        <Input
                            wide
                            required
                            invalid={!!form.errors.label}
                            value={form.data.label}
                            onChange={(e) => form.setData('label', e.target.value)}
                        />
                    </Field>
                    <Field label="Description" error={form.errors.description}>
                        <Input
                            wide
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>
                    <div>
                        <span className={ui.label}>{withRequiredMark('File', true)}</span>
                        <div className={cx(ui.row, 'mt-1')}>
                            <Input
                                wide
                                readOnly
                                invalid={!!form.errors.file}
                                value={form.data.file?.name || ''}
                                placeholder="Choose file"
                            />
                            <label className={cx(ui.btnBase, ui.btnSecondary, 'cursor-pointer shrink-0')}>
                                Browse
                                <input
                                    type="file"
                                    className="hidden"
                                    required
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.zip,.txt,.csv,.doc,.docx"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] || null;
                                        form.setData({
                                            ...form.data,
                                            file,
                                            label: form.data.label || (file ? file.name.replace(/\.[^.]+$/, '') : ''),
                                        });
                                    }}
                                />
                            </label>
                        </div>
                        {form.errors.file ? <p className={ui.fieldError}>{form.errors.file}</p> : null}
                    </div>
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" icon="check" busy={form.processing || uploadBusy} disabled={!hasFile || !hasLabel || uploadBusy}>
                        Save
                    </Button>
                </div>
            </form>
        </div>
    );
}

const ROBOTS_OPTIONS = [
    { value: 'index,follow', label: 'Index, Follow' },
    { value: 'index,nofollow', label: 'Index, NoFollow' },
    { value: 'noindex,follow', label: 'NoIndex, Follow' },
    { value: 'noindex,nofollow', label: 'NoIndex, NoFollow' },
];

function seoPreviewUrl(canonical, shopUrl, slug) {
    if (canonical) {
        try {
            const parsed = new URL(canonical);
            const parts = [parsed.host, ...parsed.pathname.split('/').filter(Boolean)];

            return parts.join(' › ');
        } catch {
            return canonical;
        }
    }

    const host = String(shopUrl || '').replace(/^https?:\/\//, '').replace(/\/$/, '') || 'example.com';

    return [host, slug || 'product'].join(' › ');
}

const LENGTH_CLASS_OPTIONS = [
    { value: 'cm', label: 'Centimeter' },
    { value: 'inch', label: 'Inch' },
    { value: 'mm', label: 'Millimeter' },
];

const WEIGHT_CLASS_OPTIONS = [
    { value: 'kg', label: 'Kilogram' },
    { value: 'g', label: 'Gram' },
    { value: 'lb', label: 'Pound' },
];

const SALE_UNIT_OPTIONS = [
    { value: 'piece', label: 'Piece (count)', hint: 'Phones, shirts, boxed goods. Quantity is 1, 2, 3…' },
    { value: 'weight', label: 'Weight', hint: 'Rice, fruit, meat. Price and stock are per kg or gram.' },
    { value: 'length', label: 'Length', hint: 'Fabric, cable, timber. Price and stock are per metre.' },
    { value: 'area', label: 'Area', hint: 'Tiles or carpet. Price and stock are per m².' },
    { value: 'volume', label: 'Volume', hint: 'Paint, milk, oil. Price and stock are per litre.' },
];

const SALE_UNIT_CODES = {
    piece: [{ value: 'pc', label: 'Piece' }],
    weight: [
        { value: 'kg', label: 'Kilogram (kg)' },
        { value: 'g', label: 'Gram (g)' },
        { value: 'lb', label: 'Pound (lb)' },
        { value: 'oz', label: 'Ounce (oz)' },
    ],
    length: [
        { value: 'm', label: 'Metre (m)' },
        { value: 'cm', label: 'Centimetre (cm)' },
        { value: 'mm', label: 'Millimetre (mm)' },
        { value: 'ft', label: 'Foot (ft)' },
        { value: 'in', label: 'Inch (in)' },
        { value: 'yd', label: 'Yard (yd)' },
    ],
    area: [
        { value: 'm2', label: 'Square metre (m²)' },
        { value: 'ft2', label: 'Square foot (ft²)' },
    ],
    volume: [
        { value: 'l', label: 'Litre (L)' },
        { value: 'ml', label: 'Millilitre (ml)' },
        { value: 'gal', label: 'Gallon (gal)' },
    ],
};

function defaultSaleUnitCode(unit, weightUnit) {
    if (unit === 'weight') {
        return weightUnit || 'kg';
    }
    return (SALE_UNIT_CODES[unit] || SALE_UNIT_CODES.piece)[0].value;
}

const CONDITION_OPTIONS = [
    { value: 'new', label: 'New' },
    { value: 'new_open_box', label: 'New open box' },
    { value: 'new_minor_defect', label: 'New minor defect' },
    { value: 'used', label: 'Used' },
    { value: 'refurbished', label: 'Refurbished' },
    { value: 'damaged', label: 'Damaged' },
];

function dateInput(value) {
    if (!value) {
        return '';
    }

    return String(value).slice(0, 10);
}

function roundMoney(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

function sellingFromForm(data, saleEnabled = true) {
    const regular = roundMoney(data.price);
    if (!saleEnabled) {
        return regular;
    }
    if (data.sale_price === '' || data.sale_price === null || data.sale_price === undefined) {
        return regular;
    }

    const now = new Date();
    if (data.sale_starts_at) {
        const start = new Date(`${data.sale_starts_at}T00:00:00`);
        if (now < start) {
            return regular;
        }
    }
    if (data.sale_ends_at) {
        const end = new Date(`${data.sale_ends_at}T23:59:59`);
        if (now > end) {
            return regular;
        }
    }

    if (data.sale_price_type === 'percent') {
        const percent = Math.max(0, Math.min(100, Number(data.sale_price) || 0));
        return Math.max(0, roundMoney(regular * (1 - percent / 100)));
    }

    return Math.max(0, Math.min(regular, roundMoney(data.sale_price)));
}

/** Mirrors TaxEngine::applyLayers for live admin tax previews. */
function applyTaxLayers(amount, layers, inclusive = false) {
    let tax = 0;
    let running = amount;
    const list = Array.isArray(layers) ? layers : [];
    for (const layer of list) {
        const rate = Number(layer?.rate) || 0;
        if (rate <= 0 || amount <= 0) {
            continue;
        }
        const base = layer?.is_compound ? running : amount;
        const line = inclusive
            ? roundMoney(base * rate / (100 + rate))
            : roundMoney(base * (rate / 100));
        tax += line;
        running += line;
    }

    return roundMoney(tax);
}

function taxPricePreview(catalogAmount, layers, taxInclusiveInput) {
    const amount = Math.max(0, roundMoney(catalogAmount));
    const tax = applyTaxLayers(amount, layers, !!taxInclusiveInput);
    if (taxInclusiveInput) {
        return {
            excl: Math.max(0, roundMoney(amount - tax)),
            incl: amount,
            tax,
        };
    }

    return {
        excl: amount,
        incl: roundMoney(amount + tax),
        tax,
    };
}

function formatCatalogMoney(amount, currencySymbol, decimals = 2) {
    const value = Number(amount) || 0;
    const places = Number.isFinite(Number(decimals)) ? Math.min(4, Math.max(0, Number(decimals))) : 2;

    return `${currencySymbol}${value.toFixed(places)}`;
}

/** Label for catalog price inputs from Product Settings → Pricing → tax_inclusive_input. */
function taxInputModeLabel(taxInclusiveInput) {
    return taxInclusiveInput ? 'Tax inclusive' : 'Tax exclusive';
}

const STOCK_STATUS_OPTIONS = [
    { value: 'in_stock', label: 'In Stock' },
    { value: 'out_of_stock', label: 'Out of Stock' },
    { value: 'on_backorder', label: 'On Backorder' },
];

function visibilityOptions() {
    return [
        { value: 'both', label: t('admin.products.visibility_both', 'Everywhere'), hint: t('admin.products.visibility_both_hint', 'Customers can access the product by browsing the catalog, using the search bar, or the link.') },
        { value: 'catalog', label: t('admin.products.visibility_catalog', 'Catalog only'), hint: t('admin.products.visibility_catalog_hint', 'Customers can access the product by browsing the catalog or the link.') },
        { value: 'search', label: t('admin.products.visibility_search', 'Search only'), hint: t('admin.products.visibility_search_hint', 'Customers can access the product by using the search bar or the link.') },
        { value: 'hidden', label: t('admin.products.visibility_hidden', 'Nowhere'), hint: t('admin.products.visibility_hidden_hint', 'The product is not visible. Customers can only access it with the link.') },
    ];
}

function ProductVisibilityFields({ form }) {
    const options = visibilityOptions();
    const selected = options.find((opt) => opt.value === form.data.visibility) || options[0];

    return (
        <Section title={t('admin.products.visibility', 'Visibility')} hint={t('admin.products.visibility_hint', 'Where do you want your product to appear?')}>
            <fieldset>
                <div className="space-y-2">
                    {options.map((opt) => (
                        <label key={opt.value} className={cx(ui.checkboxRow, 'cursor-pointer')}>
                            <input
                                type="radio"
                                name="visibility"
                                checked={form.data.visibility === opt.value}
                                onChange={() => form.setData('visibility', opt.value)}
                            />
                            {opt.label}
                        </label>
                    ))}
                </div>
            </fieldset>
            <p className={cx(ui.muted, 'mt-3')}>{selected.hint}</p>
        </Section>
    );
}

function ProductOfferFields({ form, catalogSettings = null, statusBusy = false, onToggleStatus }) {
    const shopHidesPrice = !!(catalogSettings?.catalog_mode && catalogSettings?.show_price === false);

    return (
        <div className="grid gap-3 md:grid-cols-3">
            <div className="rounded-xl border border-neutral-200 bg-neutral-50 px-5 py-4">
                <div className={ui.label}>Status</div>
                <p className={cx(ui.muted, 'mt-1')}>Active/Deactive the product</p>
                <div className="mt-3">
                    <Toggle
                        value={!!form.data.is_enabled}
                        disabled={statusBusy}
                        ariaLabel="Status"
                        onChange={() => onToggleStatus()}
                    />
                </div>
            </div>
            <div className="rounded-xl border border-neutral-200 bg-neutral-50 px-5 py-4">
                <div className={ui.label}>Available for order</div>
                <p className={cx(ui.muted, 'mt-1')}>Customers can add this product to the cart.</p>
                <div className="mt-3">
                    <Toggle
                        value={!!form.data.available_for_order}
                        error={form.errors.available_for_order}
                        onChange={(next) => form.setData('available_for_order', next)}
                    />
                </div>
            </div>
            <div className="rounded-xl border border-neutral-200 bg-neutral-50 px-5 py-4">
                <div className={ui.label}>Show price</div>
                <p className={cx(ui.muted, 'mt-1')}>
                    {shopHidesPrice
                        ? 'Hidden by Configure → Product Settings (Catalog Mode).'
                        : 'Show the selling price on the storefront.'}
                </p>
                <div className="mt-3">
                    <Toggle
                        value={shopHidesPrice ? false : !!form.data.show_price}
                        disabled={shopHidesPrice}
                        error={form.errors.show_price}
                        onChange={(next) => form.setData('show_price', next)}
                    />
                </div>
            </div>
        </div>
    );
}

const MOVEMENT_LABELS = {
    added: 'Stock Added',
    removed: 'Stock Removed',
    order: 'Order',
    cancellation: 'Cancellation',
    return: 'Return',
    refund: 'Refund',
    adjustment: 'Manual Adjustment',
    transfer: 'Transfer',
};

function qtyNumber(value) {
    if (value === '' || value === '-' || value === '.' || value === null || value === undefined) {
        return 0;
    }
    const n = Number(value);
    return Number.isFinite(n) ? Math.max(0, n) : 0;
}

function parseQtyField(value) {
    if (value === '' || value === '-' || value === '.') {
        return value;
    }
    const n = Number(value);
    if (value === null || value === undefined || Number.isNaN(n)) {
        return '';
    }

    return Math.max(0, n);
}

function applyTotalsToWarehouseRows(rows, stockQty, reservedQty) {
    if (!rows.length) {
        return rows;
    }
    let target = rows.findIndex((row) => row.is_default);
    if (target < 0) {
        target = 0;
    }
    const othersQty = rows.reduce((sum, row, index) => (index === target ? sum : sum + qtyNumber(row.qty)), 0);
    const othersReserved = rows.reduce((sum, row, index) => (index === target ? sum : sum + qtyNumber(row.reserved_qty)), 0);

    return rows.map((row, index) => {
        if (index !== target) {
            return {
                ...row,
                qty: qtyNumber(row.qty),
                reserved_qty: qtyNumber(row.reserved_qty),
            };
        }

        return {
            ...row,
            qty: Math.max(0, qtyNumber(stockQty) - othersQty),
            reserved_qty: Math.max(0, qtyNumber(reservedQty) - othersReserved),
        };
    });
}

function warehouseRows(warehouses, product) {
    const stocks = product?.warehouse_stocks || [];

    return (warehouses || []).map((wh) => {
        const row = stocks.find((item) => Number(item.warehouse_id) === Number(wh.id));

        return {
            warehouse_id: wh.id,
            name: wh.name,
            is_default: !!wh.is_default,
            qty: row ? Number(row.qty) : (wh.is_default ? qtyNumber(product?.stock_qty) : 0),
            reserved_qty: row ? Number(row.reserved_qty) : (wh.is_default ? qtyNumber(product?.reserved_qty) : 0),
        };
    });
}

function inventoryHint(available, threshold, critical) {
    if (available <= 0) {
        return { tone: 'danger', label: 'Out of Stock' };
    }
    if (available <= Number(critical || 0)) {
        return { tone: 'danger', label: 'Critical Stock' };
    }
    if (available <= Number(threshold || 0)) {
        return { tone: 'warning', label: 'Low Stock' };
    }

    return { tone: 'success', label: 'In Stock' };
}

function compositeTypeDefaults(type) {
    return typeConfig(type).defaults || {};
}

function componentsPayload(items) {
    return items.map((item) => ({
        id: item.id,
        quantity: Math.max(1, Number(item.quantity) || 1),
        min_quantity: item.min_quantity === '' || item.min_quantity === null || item.min_quantity === undefined
            ? null
            : Number(item.min_quantity),
        max_quantity: item.max_quantity === '' || item.max_quantity === null || item.max_quantity === undefined
            ? null
            : Number(item.max_quantity),
        qty_editable: !!item.qty_editable,
        is_required: item.is_required !== false,
        default_selected: !!item.default_selected,
        option_group: item.option_group || null,
    }));
}

const GROUP_PRICING_MODES = [
    { value: 'sum', label: 'Sum of child prices', hint: 'Total from selected child products.' },
    { value: 'sum_discount', label: 'Child prices + group discount', hint: 'Apply a fixed or percent discount to the subtotal.' },
    { value: 'fixed', label: 'Fixed group price', hint: 'Use the regular price field as the group price.' },
];

const BUNDLE_PRICING_MODES = [
    { value: 'fixed', label: 'Fixed bundle price', hint: 'Use the regular price field as the bundle price.' },
    { value: 'sum', label: 'Sum of component prices', hint: 'Total from all configured components.' },
    { value: 'sum_adjustment', label: 'Component total + adjustment', hint: 'Add or subtract from the component subtotal.' },
    { value: 'sum_discount', label: 'Discounted component total', hint: 'Apply a fixed or percent discount to the subtotal.' },
];

const TRANSLATABLE_FIELDS = [
    'name',
    'slug',
    'short_description',
    'description',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'focus_keyword',
    'canonical_url',
    'robots',
];

function keywordsToList(value) {
    if (Array.isArray(value)) {
        return value.map((row) => String(row).trim()).filter(Boolean);
    }

    return String(value || '')
        .split(',')
        .map((row) => row.trim())
        .filter(Boolean);
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        short_description: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
        focus_keyword: '',
        canonical_url: '',
        robots: 'index,follow',
        subscription_message: '',
    };
}

function buildTranslations(rows, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (rows || []).forEach((row) => {
        if (!row?.language_code) {
            return;
        }
        out[row.language_code] = {
            ...emptyTranslation(row.language_code),
            ...row,
            language_code: row.language_code,
        };
    });
    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyTranslation(defaultLanguage);
    }

    return out;
}

export default function Form({ product, categories, brands, suppliers = [], selectedSuppliers = [], taxClasses = [], pricingSettings = null, features, productType, relatedProducts = [], componentItems = [], combinationAttributeCatalog = [], combinationAxes = [], combinationVariants = [], giftCardConfig = null, serviceConfig = null, rentalConfig = null, rentalInventorySummary = null, preorderConfig = null, preorderEnabledDefault = false, warehouses = [], inventoryHistory = [], shippingMethods = [], currencySymbol = '$', shopUrl = '', productUrl = '', assignedSubscriptions = [], availableSubscriptionPlans = [], multivendor = null, catalogSettings = null, contentSettings = null, productDefaults = null, timeline = [] }) {
    const isMultivendor = !!multivendor;
    const productsIndexPath = isMultivendor ? multivendor.indexPath : '/admin/products';
    const multivendorDashboardPath = '/admin/multivendor/dashboard';
    const [reviewNote, setReviewNote] = useState('');
    const { i18n, shop } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(product?.translations || [], languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const images = product?.images || [];
    const cover = images.find((img) => img.path === product?.cover_path) || images[0];
    const [tab, setTab] = useState(() => {
        if (typeof window === 'undefined') {
            return 'general';
        }
        const requested = new URLSearchParams(window.location.search).get('tab');
        return requested || 'general';
    });
    const formRef = useRef(null);
    const growl = useGrowl();
    const [saveAttempt, setSaveAttempt] = useState(0);
    const [statusBusy, setStatusBusy] = useState(false);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const shortMax = Number(contentSettings?.short_description_max) || 400;
    const descMax = Number(contentSettings?.description_max) || 5000;
    const metaTitleMax = Number(contentSettings?.meta_title_max) || 70;
    const metaDescMax = Number(contentSettings?.meta_description_max) || 160;
    const shortDescriptionLabel = t('admin.products.short_description_max', 'Short description (max {max})').replace('{max}', String(shortMax));
    const descriptionLabel = t('admin.products.description_max', 'Description (max {max})').replace('{max}', String(descMax));
    const skuMode = productDefaults?.sku_mode || 'auto';
    const requireSku = skuMode === 'manual' || !!productDefaults?.require_sku;
    const barcodeMode = productDefaults?.barcode_mode || 'optional';
    const requireBarcodeCodes = barcodeMode === 'required';
    const defaultLowStock = Number(productDefaults?.default_low_stock_alert ?? 5);
    const defaultCritical = Number(productDefaults?.default_critical_stock ?? 3);
    const defaultVisibility = productDefaults?.default_visibility || 'both';
    const defaultTaxClassId = productDefaults?.default_tax_class_id || '';
    const defaultAvailable = productDefaults?.default_available_for_order !== false;
    const defaultShowPrice = productDefaults?.default_show_price !== false;
    const defaultManageStock = productDefaults?.default_manage_stock !== false;
    const defaultBackorder = ['allow', 'deny', 'hide'].includes(productDefaults?.out_of_stock_behavior)
        ? productDefaults.out_of_stock_behavior
        : (['allow', 'deny', 'hide'].includes(productDefaults?.default_backorder_status)
            ? productDefaults.default_backorder_status
            : (productDefaults?.default_allow_backorder ? 'allow' : 'deny'));
    const defaultNotifyLow = productDefaults?.default_notify_low_stock !== false;
    const defaultNotifyOos = productDefaults?.default_notify_out_of_stock !== false;
    const defaultIsNew = !product && Number(productDefaults?.new_product_days ?? 0) > 0;
    const defaultMinQty = Number(productDefaults?.default_min_qty ?? 1);
    const defaultQtyStep = Number(productDefaults?.default_qty_step ?? 1);
    const defaultMaxQty = productDefaults?.default_max_qty ?? '';
    const defaultEnabled = product ? !!product.is_enabled : (productDefaults?.default_status !== 'draft');
    const weightUnit = productDefaults?.weight_unit || 'kg';
    const dimensionUnit = productDefaults?.dimension_unit || 'cm';
    const [slugLocked, setSlugLocked] = useState(() => Object.fromEntries(
        Object.entries(initialTranslations).map(([code, row]) => [code, !!row.slug]),
    ));
    const [relatedItems, setRelatedItems] = useState(relatedProducts);
    const [compositionItems, setCompositionItems] = useState(componentItems);
    const [combinationAxisItems, setCombinationAxisItems] = useState(combinationAxes);
    const [combinationVariantItems, setCombinationVariantItems] = useState(combinationVariants);
    const [deletedCombinationVariantIds, setDeletedCombinationVariantIds] = useState([]);
    const [gallery, setGallery] = useState(() => initialGallery(product));
    const [pendingCoverUrl, setPendingCoverUrl] = useState(null);
    const [uploadingCombinationImage, setUploadingCombinationImage] = useState(false);
    const [fileModalOpen, setFileModalOpen] = useState(false);
    const [fileModalPurpose, setFileModalPurpose] = useState('document');
    const [fileQuery, setFileQuery] = useState('');
    const [pendingAttachments, setPendingAttachments] = useState([]);
    const [libraryFiles, setLibraryFiles] = useState([]);
    const [libraryLoading, setLibraryLoading] = useState(false);
    const [selectedAttachmentIds, setSelectedAttachmentIds] = useState(() => (product?.attachments || []).map((row) => Number(row.id)));
    const [attachedFileMap, setAttachedFileMap] = useState(() => {
        const map = {};
        (product?.attachments || []).forEach((row) => {
            map[Number(row.id)] = row;
        });
        return map;
    });
    const [attachmentRoles, setAttachmentRoles] = useState(() => {
        const map = {};
        const virtualDefault = String(product?.type || productType || '') === 'virtual';
        (product?.attachments || []).forEach((row) => {
            const stored = row.pivot?.role || row.role;
            map[Number(row.id)] = stored === 'paid' || stored === 'document'
                ? stored
                : (virtualDefault ? 'paid' : 'document');
        });
        return map;
    });
    const [customizationEnabled, setCustomizationEnabled] = useState(!!product?.customization_enabled);
    const [customizationFields, setCustomizationFields] = useState(() => customizationFieldsFromProduct(product));
    const [subscriptionEnabled, setSubscriptionEnabled] = useState(
        !!product?.subscription_enabled || (!product && productType === 'subscription'),
    );
    const [purchaseOption, setPurchaseOption] = useState(
        product?.purchase_option || (!product && productType === 'subscription' ? 'subscription' : 'one_time'),
    );
    const [assignedPlans, setAssignedPlans] = useState(() => (assignedSubscriptions || []).map((row) => ({
        key: `saved-${row.subscription_plan_id}`,
        ...row,
    })));
    const [giftCardSettings, setGiftCardSettings] = useState(() => {
        if (!giftCardConfig) {
            return defaultGiftCardConfig();
        }
        const { amounts: _amounts, ...rest } = giftCardConfig;

        return { ...defaultGiftCardConfig(), ...rest };
    });
    const [giftCardAmounts, setGiftCardAmounts] = useState(() => (giftCardConfig?.amounts || []).map((row, index) => ({
        key: row.id ? `saved-${row.id}` : `amount-${index}`,
        ...row,
        selling_price: row.selling_price ?? '',
        position: row.position ?? index,
    })));
    const [serviceSettings, setServiceSettings] = useState(() => {
        if (!serviceConfig) return defaultServiceConfig();
        const { ...rest } = serviceConfig;
        return { ...defaultServiceConfig(), ...rest };
    });
    const [rentalSettings, setRentalSettings] = useState(() => {
        if (!rentalConfig) return defaultRentalConfig();
        const { pricing_tiers: _tiers, assets: _assets, ...rest } = rentalConfig;
        return { ...defaultRentalConfig(), ...rest };
    });
    const [rentalTiers, setRentalTiers] = useState(() => (rentalConfig?.pricing_tiers || []).map((row, index) => ({
        key: row.id ? `saved-${row.id}` : `tier-${index}`,
        ...row,
        position: row.position ?? index,
    })));
    const [rentalAssets, setRentalAssets] = useState(() => (rentalConfig?.assets || []).map((row, index) => ({
        key: row.id ? `saved-${row.id}` : `asset-${index}`,
        ...row,
        position: row.position ?? index,
    })));
    const normalizedInitialType = normalizeProductType(product?.type || productType || 'simple');
    const [preorderEnabled, setPreorderEnabled] = useState(() => {
        if (product?.preorder_enabled || preorderConfig?.preorder_enabled) {
            return true;
        }
        if (!product && (productType === 'preorder' || preorderEnabledDefault)) {
            return true;
        }
        return false;
    });
    const [preorderSettings, setPreorderSettings] = useState(() => {
        if (!preorderConfig) {
            return defaultPreorderConfig();
        }
        const { orders_remaining: _remaining, preorder_enabled: _enabled, ...rest } = preorderConfig;
        return { ...defaultPreorderConfig(), ...rest };
    });
    const savedAttachments = product?.attachments || [];
    const attachmentForm = useForm({
        label: '',
        description: '',
        file: null,
    });

    const form = useForm({
        type: normalizedInitialType,
        sku: product?.sku || '',
        name: defaultTranslation.name || '',
        slug: defaultTranslation.slug || '',
        price: product?.price || 0,
        sale_price: product?.sale_price ?? '',
        sale_price_type: product?.sale_price_type || 'fixed',
        sale_starts_at: dateInput(product?.sale_starts_at),
        sale_ends_at: dateInput(product?.sale_ends_at),
        compare_price: product?.compare_price ?? '',
        cost_price: product?.cost_price ?? '',
        stock_qty: product ? qtyNumber(product.stock_qty) : '',
        reserved_qty: product ? qtyNumber(product.reserved_qty) : '',
        stock_status: product?.stock_status || 'in_stock',
        low_stock_threshold: product?.low_stock_threshold ?? defaultLowStock,
        low_stock_alert: product?.low_stock_alert ?? defaultLowStock,
        critical_stock: product?.critical_stock ?? defaultCritical,
        backorder_status: product
            ? (product.backorder_status === 'allow_notify'
                ? 'allow'
                : (['allow', 'deny', 'hide'].includes(product.backorder_status)
                    ? product.backorder_status
                    : (product.allow_backorder ? 'allow' : defaultBackorder)))
            : defaultBackorder,
        notify_low_stock: product?.notify_low_stock ?? defaultNotifyLow,
        notify_out_of_stock: product?.notify_out_of_stock ?? defaultNotifyOos,
        warehouse_stocks: warehouseRows(warehouses, product),
        description: defaultTranslation.description || '',
        short_description: defaultTranslation.short_description || '',
        meta_title: defaultTranslation.meta_title || '',
        meta_description: defaultTranslation.meta_description || '',
        meta_keywords: defaultTranslation.meta_keywords || '',
        focus_keyword: defaultTranslation.focus_keyword || '',
        canonical_url: defaultTranslation.canonical_url || '',
        robots: defaultTranslation.robots || 'index,follow',
        translations: initialTranslations,
        visibility: product?.visibility || defaultVisibility,
        available_for_order: product?.available_for_order ?? defaultAvailable,
        show_price: product?.show_price ?? defaultShowPrice,
        is_enabled: product?.is_enabled ?? defaultEnabled,
        is_new: product?.is_new || defaultIsNew,
        is_on_sale: product?.is_on_sale || false,
        is_best_seller: product?.is_best_seller || false,
        brand_id: product?.brand_id || '',
        supplier_id: product?.supplier_id || '',
        supplier_ids: (selectedSuppliers.length ? selectedSuppliers : (product?.suppliers || [])).map((row) => row.id),
        tax_class_id: product?.tax_class_id || defaultTaxClassId || '',
        default_category_id: product?.default_category_id || '',
        category_ids: product?.categories?.map((c) => c.id) || [],
        weight: product?.weight || '',
        weight_class: product?.weight_class || weightUnit,
        length: product?.length || '',
        width: product?.width || '',
        height: product?.height || '',
        length_class: product?.length_class || dimensionUnit,
        processing_time: product?.processing_time || '',
        processing_time_unit: product?.processing_time_unit || 'days',
        delivery_from: product?.delivery_from || '',
        delivery_to: product?.delivery_to || '',
        additional_shipping_cost: product?.additional_shipping_cost ?? '0.000000',
        available_all_carriers: product?.available_all_carriers ?? true,
        shipping_method_ids: (product?.shipping_methods || []).map((method) => Number(method.id)),
        barcode: product?.barcode || '',
        isbn: product?.isbn || '',
        upc: product?.upc || '',
        mpn: product?.mpn || '',
        gtin: product?.gtin || '',
        video_url: product?.video_url || '',
        display_condition: product?.display_condition || false,
        condition: product?.condition || '',
        min_qty: qtyInputValue(product?.min_qty),
        max_qty: qtyInputValue(product?.max_qty),
        qty_step: qtyInputValue(product?.qty_step),
        sale_unit: product?.sale_unit || 'piece',
        sale_unit_code: product?.sale_unit_code || 'pc',
        qty_prices: (product?.qty_prices || []).length
            ? product.qty_prices.map((row) => ({
                min: row.min_qty ?? row.min ?? '',
                max: row.max_qty ?? row.max ?? '',
                price: row.price ?? '',
            }))
            : [{ min: '', max: '', price: '' }],
        allow_backorder: product?.allow_backorder || false,
        manage_stock: product?.manage_stock ?? (product ? typeConfig(product?.type || productType || 'simple').defaultManageStock : defaultManageStock),
        related_ids: relatedProducts.map((item) => item.id),
        cover_image_id: (product?.images || []).find((img) => img.path === product?.cover_path)?.id || '',
        upsell_ids: [],
        crosssell_ids: [],
        feature_value_ids: product?.feature_values?.map((v) => ({
            feature_id: v.feature_id || v.pivot?.feature_id,
            feature_value_id: v.id,
        })) || [],
        selection_mode: product?.selection_mode || compositeTypeDefaults(product?.type || productType || 'simple').selection_mode || 'all',
        bundle_type: product?.bundle_type || compositeTypeDefaults(product?.type || productType || 'simple').bundle_type || 'fixed',
        pricing_mode: product?.pricing_mode || compositeTypeDefaults(product?.type || productType || 'simple').pricing_mode || 'sum',
        pricing_discount_type: product?.pricing_discount_type || 'fixed',
        pricing_discount_value: product?.pricing_discount_value ?? '',
        inventory_mode: product?.inventory_mode || compositeTypeDefaults(product?.type || productType || 'simple').inventory_mode || 'derived',
        shipping_mode: product?.shipping_mode || compositeTypeDefaults(product?.type || productType || 'simple').shipping_mode || 'derived',
        seller_id: multivendor?.sellerId ? String(multivendor.sellerId) : '',
    });

    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const hasValueForField = (field) => String(valueForField(field) || '').trim() !== '';
    const fallbackForField = (field) => {
        if (selectedLanguage === defaultLanguage) {
            return '';
        }
        return translationFor(defaultLanguage)[field] || '';
    };
    const errorForField = (field) => (
        form.errors[`translations.${selectedLanguage}.${field}`]
        || (selectedLanguage === defaultLanguage ? form.errors[field] : undefined)
    );

    useEffect(() => {
        const q = fileQuery.trim();
        if (q.length < 3) {
            setLibraryFiles([]);
            setLibraryLoading(false);
            return undefined;
        }

        let cancelled = false;
        setLibraryLoading(true);
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q });
            if (product?.id) {
                params.set('product_id', String(product.id));
            }
            fetch(`/admin/files/search?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((response) => response.json())
                .then((data) => {
                    if (cancelled) {
                        return;
                    }
                    setLibraryFiles(Array.isArray(data.files) ? data.files : []);
                })
                .catch(() => {
                    if (!cancelled) {
                        setLibraryFiles([]);
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setLibraryLoading(false);
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [fileQuery, product?.id]);

    const attachedFiles = selectedAttachmentIds
        .map((id) => attachedFileMap[Number(id)])
        .filter(Boolean);
    const isVirtualProduct = String(form.data.type || productType) === 'virtual';
    const attachmentRoleOf = (id) => {
        const stored = attachmentRoles[Number(id)];
        if (stored === 'paid' || stored === 'document') {
            return stored;
        }

        return isVirtualProduct ? 'paid' : 'document';
    };
    const documentAttachedFiles = attachedFiles.filter((item) => attachmentRoleOf(item.id) === 'document');
    const paidAttachedFiles = attachedFiles.filter((item) => attachmentRoleOf(item.id) === 'paid');
    const documentPendingAttachments = pendingAttachments.filter((item) => item.role !== 'paid');
    const paidPendingAttachments = pendingAttachments.filter((item) => item.role === 'paid');
    const documentSelectedIds = selectedAttachmentIds.filter((id) => attachmentRoleOf(id) === 'document');
    const paidSelectedIds = selectedAttachmentIds.filter((id) => attachmentRoleOf(id) === 'paid');

    const attachLibraryFile = (file, role) => {
        const id = Number(file?.id);
        if (!id) {
            return;
        }
        const nextRole = role === 'paid' ? 'paid' : 'document';
        setSelectedAttachmentIds((current) => (
            current.includes(id) ? current : [...current, id]
        ));
        setAttachedFileMap((current) => ({ ...current, [id]: file }));
        setAttachmentRoles((current) => ({ ...current, [id]: nextRole }));
    };

    const detachLibraryFile = (id) => {
        const attachmentId = Number(id);
        setSelectedAttachmentIds((current) => current.filter((row) => row !== attachmentId));
        setAttachedFileMap((current) => {
            const next = { ...current };
            delete next[attachmentId];
            return next;
        });
        setAttachmentRoles((current) => {
            const next = { ...current };
            delete next[attachmentId];
            return next;
        });
    };

    const openFileModal = (purpose) => {
        attachmentForm.reset();
        setFileModalPurpose(purpose === 'paid' ? 'paid' : 'document');
        setFileModalOpen(true);
    };

    const setTranslationField = (languageCode, field, value) => {
        const current = translationFor(languageCode);
        const nextTranslations = {
            ...form.data.translations,
            [languageCode]: {
                ...current,
                language_code: languageCode,
                [field]: value,
            },
        };
        const next = { ...form.data, translations: nextTranslations };

        if (languageCode === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
            next[field] = value;
        }

        form.setData(next);
    };

    const setLocalizedName = (value) => {
        setTranslationField(selectedLanguage, 'name', value);
    };

    const title = form.data.name.trim() || (product ? t('admin.products.edit', 'Edit product') : t('admin.products.new', 'New product'));
    const productTypeKey = normalizeProductType(form.data.type);
    const typeLabel = PRODUCT_TYPE_OPTIONS.find((opt) => opt.value === productTypeKey)?.label || productTypeKey;
    const typeSettings = typeConfig(productTypeKey);
    const tabs = tabsForProduct(productTypeKey);
    useEffect(() => {
        const available = tabsForProduct(productTypeKey);
        if (!available.some((row) => row.id === tab)) {
            setTab(available[0]?.id || 'general');
        }
    }, [productTypeKey, tab]);
    useEffect(() => {
        if (isVirtualProduct) {
            return;
        }
        // Digital Files exist only on virtual products — demote roles when type changes away.
        setAttachmentRoles((current) => {
            const next = {};
            Object.keys(current).forEach((id) => {
                next[id] = 'document';
            });
            return next;
        });
        setPendingAttachments((current) => current.map((row) => ({ ...row, role: 'document' })));
    }, [isVirtualProduct]);
    const supportsPreorder = typeSupportsCapability(productTypeKey, 'preorder');
    const supplierOptions = [...suppliers];
    selectedSuppliers.forEach((row) => {
        if (!supplierOptions.some((item) => Number(item.id) === Number(row.id))) {
            supplierOptions.push(row);
        }
    });

    const setSupplierIds = (ids) => {
        const nextIds = ids.map(Number);
        let nextDefault = Number(form.data.supplier_id) || 0;
        if (!nextIds.includes(nextDefault)) {
            nextDefault = nextIds[0] || '';
        }
        form.setData({
            ...form.data,
            supplier_ids: nextIds,
            supplier_id: nextDefault || '',
        });
    };
    const usesCatalogLayout = typeSettings.usesCatalogLayout;
    const isComposite = ['group', 'bundle'].includes(form.data.type);
    const isCombination = form.data.type === 'combination';
    const isGiftCard = form.data.type === 'gift_card';
    const isService = form.data.type === 'service';
    const isRental = form.data.type === 'rental';
    const allowsSaleUnit = ['simple', 'combination'].includes(form.data.type);
    const saleUnit = form.data.sale_unit || 'piece';
    const saleUnitCodes = SALE_UNIT_CODES[saleUnit] || SALE_UNIT_CODES.piece;
    const saleUnitLabel = (saleUnitCodes.find((opt) => opt.value === form.data.sale_unit_code) || saleUnitCodes[0])?.label || 'unit';
    const qtyInputStep = saleUnit === 'piece' ? 1 : 0.001;
    const derivedInventory = isComposite && (form.data.inventory_mode || 'derived') === 'derived';
    const derivedShipping = isComposite && (form.data.shipping_mode || 'derived') === 'derived';
    const componentSubtotal = isComposite ? itemsSubtotal(compositionItems) : 0;
    const computedCompositePrice = isComposite
        ? composedPrice(
            form.data.pricing_mode,
            componentSubtotal,
            form.data.pricing_discount_type,
            form.data.pricing_discount_value,
            form.data.price,
        )
        : 0;
    const computedDerivedQty = isComposite
        ? derivedQty(compositionItems, { type: form.data.type, selectionMode: form.data.selection_mode })
        : 0;
    const pricingModeOptions = form.data.type === 'bundle' ? BUNDLE_PRICING_MODES : GROUP_PRICING_MODES;

    useEffect(() => {
        setCompositionItems(componentItems);
    }, [product?.id]);

    useEffect(() => {
        setCombinationAxisItems(combinationAxes);
    }, [product?.id]);

    useEffect(() => {
        setCombinationVariantItems(combinationVariants);
    }, [product?.id]);

    useEffect(() => {
        if (!isComposite) {
            return;
        }
        const defaults = compositeTypeDefaults(form.data.type);
        const patch = {};
        if (form.data.type === 'group' && !form.data.selection_mode) {
            patch.selection_mode = defaults.selection_mode || 'all';
        }
        if (form.data.type === 'bundle' && !form.data.bundle_type) {
            patch.bundle_type = defaults.bundle_type || 'fixed';
        }
        if (!form.data.pricing_mode) {
            patch.pricing_mode = defaults.pricing_mode || (form.data.type === 'bundle' ? 'fixed' : 'sum');
        }
        if (!form.data.inventory_mode) {
            patch.inventory_mode = defaults.inventory_mode || 'derived';
        }
        if (!form.data.shipping_mode) {
            patch.shipping_mode = defaults.shipping_mode || 'derived';
        }
        if (Object.keys(patch).length) {
            form.setData({ ...form.data, ...patch });
        }
    }, [form.data.type]);

    useEffect(() => {
        if (!supportsPreorder) {
            if (preorderEnabled) {
                setPreorderEnabled(false);
            }
            if (tab === 'preorder') {
                setTab('general');
            }
        }
    }, [supportsPreorder, tab]);

    const salePricingEnabled = pricingSettings?.sale_enabled !== false;
    const compareAtEnabled = pricingSettings?.compare_at_enabled !== false;
    const taxInclusiveInput = !!pricingSettings?.tax_inclusive_input;
    const priceDecimals = Number.isFinite(Number(pricingSettings?.decimal_precision))
        ? Number(pricingSettings.decimal_precision)
        : 2;
    const sellingPrice = isComposite && form.data.pricing_mode !== 'fixed'
        ? computedCompositePrice
        : sellingFromForm(form.data, salePricingEnabled);
    const selectedTaxClass = (taxClasses || []).find((row) => String(row.id) === String(form.data.tax_class_id || ''));
    const taxLayers = selectedTaxClass?.tax_layers || [];
    const taxPreview = taxPricePreview(sellingPrice, taxLayers, taxInclusiveInput);
    const costPrice = roundMoney(form.data.cost_price);
    const profitAmount = roundMoney(sellingPrice - costPrice);
    const profitMargin = sellingPrice > 0 ? roundMoney((profitAmount / sellingPrice) * 100) : 0;
    const availableStock = derivedInventory
        ? computedDerivedQty
        : Math.max(0, Number(form.data.stock_qty || 0) - Number(form.data.reserved_qty || 0));
    const stockHint = inventoryHint(availableStock, form.data.low_stock_threshold, form.data.critical_stock);

    const setWarehouseStock = (warehouseId, patch) => {
        const parsed = { ...patch };
        if (Object.prototype.hasOwnProperty.call(patch, 'qty')) {
            parsed.qty = parseQtyField(patch.qty);
        }
        if (Object.prototype.hasOwnProperty.call(patch, 'reserved_qty')) {
            parsed.reserved_qty = parseQtyField(patch.reserved_qty);
        }
        const rows = form.data.warehouse_stocks.map((row) => (
            Number(row.warehouse_id) === Number(warehouseId) ? { ...row, ...parsed } : row
        ));
        form.setData({
            ...form.data,
            warehouse_stocks: rows,
            stock_qty: rows.reduce((sum, row) => sum + qtyNumber(row.qty), 0),
            reserved_qty: rows.reduce((sum, row) => sum + qtyNumber(row.reserved_qty), 0),
        });
    };

    const setCurrentStock = (value) => {
        const parsed = parseQtyField(value);
        if (parsed === '' || parsed === '-' || parsed === '.') {
            form.setData({ ...form.data, stock_qty: parsed });
            return;
        }
        form.setData({
            ...form.data,
            stock_qty: parsed,
            warehouse_stocks: applyTotalsToWarehouseRows(form.data.warehouse_stocks, parsed, form.data.reserved_qty),
        });
    };

    const setReservedStock = (value) => {
        const parsed = parseQtyField(value);
        if (parsed === '' || parsed === '-' || parsed === '.') {
            form.setData({ ...form.data, reserved_qty: parsed });
            return;
        }
        form.setData({
            ...form.data,
            reserved_qty: parsed,
            warehouse_stocks: applyTotalsToWarehouseRows(form.data.warehouse_stocks, form.data.stock_qty, parsed),
        });
    };
    const counts = {
        variants: combinationVariantItems.length,
        media: documentAttachedFiles.length + documentPendingAttachments.length,
        downloads: paidAttachedFiles.length + paidPendingAttachments.length,
        customizable: customizationFields.length,
        subscription: assignedPlans.length,
        products: compositionItems.length,
        bundle: compositionItems.length,
        gift_card_amounts: giftCardAmounts.filter((row) => row.is_enabled !== false).length,
        rental_tiers: rentalTiers.length,
    };
    const errorTabs = useMemo(
        () => tabsForFormErrors(form.errors, tabs.map((item) => item.id)),
        [form.errors, tabs],
    );
    const errorTabSet = useMemo(() => new Set(errorTabs), [errorTabs]);
    const tabItems = tabs.map((item) => ({
        key: item.id,
        label: item.label,
        count: item.countKey ? counts[item.countKey] : undefined,
        error: errorTabSet.has(item.id),
    }));

    const submit = (e) => {
        e.preventDefault();
        setSaveAttempt((n) => n + 1);
        form.clearErrors();
        const errors = {};
        const productName = String(form.data.translations?.[defaultLanguage]?.name || '').trim();
        if (!productName) {
            errors[`translations.${defaultLanguage}.name`] = 'Product name is required.';
        }
        const selectedCategoryIds = (form.data.category_ids || []).map(Number).filter((id) => id > 0);
        if (selectedCategoryIds.length === 0) {
            errors.category_ids = 'Select at least one category.';
        } else {
            const defaultCategoryId = Number(form.data.default_category_id || 0);
            if (!defaultCategoryId || !selectedCategoryIds.includes(defaultCategoryId)) {
                errors.default_category_id = 'Choose a default category from the selected categories.';
            }
        }
        if (isMultivendor && !form.data.seller_id) {
            errors.seller_id = 'Choose a seller.';
        }
        if (form.data.price !== '' && form.data.price != null && Number(form.data.price) < 0) {
            errors.price = 'Price cannot be negative.';
        }
        if (requireSku && !String(form.data.sku || '').trim()) {
            errors.sku = 'SKU is required.';
        }
        if (requireBarcodeCodes && !String(form.data.upc || '').trim()) {
            errors.upc = 'UPC is required.';
        }
        if (requireBarcodeCodes && !String(form.data.gtin || '').trim()) {
            errors.gtin = 'GTIN is required.';
        }
        if (productDefaults?.require_cover_to_publish && !gallery.some((item) => item.file || item.id)) {
            errors.cover_path = 'A cover image is required before publishing this product.';
        }
        if (productDefaults?.require_brand_to_publish && !form.data.brand_id) {
            errors.brand_id = 'A brand is required before publishing this product.';
        }
        if (isComposite && compositionItems.length === 0) {
            errors.components = 'Add at least one product to this pack.';
        }
        if (!product && productTypeKey === 'combination') {
            if (!combinationAxisItems.length && !combinationVariantItems.length) {
                errors.combination_variants = 'Add at least one combination before saving.';
            }
        }
        if (productRequiresActivePlan(subscriptionEnabled, purchaseOption) && !hasActiveAssignedPlan(assignedPlans)) {
            errors.subscription_plans = PRODUCT_REQUIRES_ACTIVE_PLAN;
        }
        if (subscriptionEnabled && preorderEnabled) {
            errors.subscription_enabled = SUBSCRIPTION_PREORDER_CONFLICT;
            errors.preorder_enabled = SUBSCRIPTION_PREORDER_CONFLICT;
            growl?.push({ type: 'error', message: SUBSCRIPTION_PREORDER_CONFLICT });
        }
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            const firstErrorTab = tabsForFormErrors(errors, tabs.map((item) => item.id))[0];
            if (firstErrorTab && firstErrorTab !== tab) {
                setTab(firstErrorTab);
            }
            return;
        }
        form.transform((data) => {
            const translations = Object.fromEntries(
                Object.entries(data.translations || {}).map(([languageCode, row]) => [
                    languageCode,
                    {
                        ...emptyTranslation(languageCode),
                        ...row,
                        language_code: languageCode,
                    },
                ]),
            );
            const baseTranslation = translations[defaultLanguage] || emptyTranslation(defaultLanguage);
            const payload = {
                ...data,
                name: baseTranslation.name,
                slug: baseTranslation.slug,
                short_description: baseTranslation.short_description,
                description: baseTranslation.description,
                meta_title: baseTranslation.meta_title,
                meta_description: baseTranslation.meta_description,
                meta_keywords: baseTranslation.meta_keywords,
                focus_keyword: baseTranslation.focus_keyword,
                canonical_url: baseTranslation.canonical_url,
                robots: baseTranslation.robots,
                translations,
                related_ids: relatedItems.map((item) => item.id),
                sale_price: data.sale_price === '' ? null : data.sale_price,
                sale_starts_at: data.sale_starts_at || null,
                sale_ends_at: data.sale_ends_at || null,
                compare_price: data.compare_price === '' ? null : data.compare_price,
                cost_price: data.cost_price === '' ? null : data.cost_price,
                manage_stock: data.manage_stock ? 1 : 0,
                stock_qty: qtyNumber(data.stock_qty),
                reserved_qty: qtyNumber(data.reserved_qty),
                warehouse_stocks: applyTotalsToWarehouseRows(
                    data.warehouse_stocks || [],
                    data.stock_qty,
                    data.reserved_qty,
                ),
            };
            if (!product && gallery.some((item) => item.file)) {
                const files = gallery.filter((item) => item.file);
                payload.images = files.map((item) => item.file);
                const coverIndex = files.findIndex((item) => item.url === pendingCoverUrl);
                if (coverIndex >= 0) {
                    payload.cover_index = coverIndex;
                }
            }
            if (product) {
                const virtualProduct = String(form.data.type || productType) === 'virtual';
                payload.attachment_ids = selectedAttachmentIds;
                payload.attachment_roles = Object.fromEntries(
                    selectedAttachmentIds.map((id) => [
                        id,
                        virtualProduct ? attachmentRoleOf(id) : 'document',
                    ]),
                );
            }
            if (!product && pendingAttachments.length) {
                const virtualProduct = String(form.data.type) === 'virtual';
                payload.attachment_files = pendingAttachments.map((item) => item.file);
                payload.attachment_labels = pendingAttachments.map((item) => item.label);
                payload.attachment_descriptions = pendingAttachments.map((item) => item.description);
                payload.attachment_roles = pendingAttachments.map((item) => (
                    virtualProduct && item.role === 'paid' ? 'paid' : 'document'
                ));
            }
            payload.customization_enabled = customizationEnabled;
            payload.customization_fields = customizationFieldsPayload(customizationFields);
            payload.subscription_enabled = subscriptionEnabled;
            payload.purchase_option = purchaseOption;
            payload.subscription_plans = assignedPlansPayload(assignedPlans);
            if (product) {
                payload.image_order = gallery.filter((item) => item.id).map((item) => item.id);
            }
            if (!payload.cover_image_id) {
                delete payload.cover_image_id;
            }
            if (['group', 'bundle'].includes(data.type)) {
                payload.components = componentsPayload(compositionItems);
                payload.pricing_discount_value = data.pricing_discount_value === '' ? null : data.pricing_discount_value;
                if ((data.inventory_mode || 'derived') === 'derived') {
                    payload.manage_stock = 0;
                }
            }
            if (data.type === 'combination') {
                payload.combination_axes = combinationAxisItems.map((axis) => ({
                    attribute_id: axis.attribute_id,
                    value_ids: axis.value_ids || [],
                }));
                payload.combination_variants = combinationVariantItems.map((variant) => ({
                    id: variant.id || null,
                    attribute_value_ids: variant.attribute_value_ids || [],
                    sku: variant.sku || '',
                    barcode: variant.barcode || '',
                    price: variant.price === '' ? null : variant.price,
                    sale_price: variant.sale_price === '' ? null : variant.sale_price,
                    sale_price_type: variant.sale_price_type || 'fixed',
                    cost_price: variant.cost_price === '' ? null : variant.cost_price,
                    stock_qty: variant.stock_qty ?? 0,
                    manage_stock: variant.manage_stock !== false,
                    stock_status: variant.stock_status || 'in_stock',
                    allow_backorder: !!variant.allow_backorder,
                    weight: variant.weight === '' ? null : variant.weight,
                    length: variant.length === '' ? null : variant.length,
                    width: variant.width === '' ? null : variant.width,
                    height: variant.height === '' ? null : variant.height,
                    cover_image_id: variant.cover_image_id ? Number(variant.cover_image_id) : null,
                    cover_path: variant.cover_path || null,
                    is_enabled: variant.is_enabled !== false,
                    is_default: !!variant.is_default,
                    position: variant.position ?? 0,
                }));
                payload.manage_stock = 0;
                payload.deleted_combination_variant_ids = deletedCombinationVariantIds;
            }
            if (data.type === 'gift_card') {
                Object.assign(payload, giftCardPayload(giftCardSettings, giftCardAmounts));
                payload.manage_stock = 0;
            }
            if (data.type === 'service') {
                Object.assign(payload, servicePayload(serviceSettings));
                payload.manage_stock = 0;
            }
            if (data.type === 'rental') {
                Object.assign(payload, rentalPayload(rentalSettings, rentalTiers, rentalAssets));
            }
            if (supportsPreorder) {
                Object.assign(payload, preorderPayload(preorderEnabled, preorderSettings));
            }
            if (isMultivendor) {
                payload.seller_id = data.seller_id;
            }
            return payload;
        });
        const needsUpload = !product && (gallery.some((item) => item.file) || pendingAttachments.length > 0);
        const options = {
            ...(needsUpload ? { forceFormData: true } : {}),
            preserveScroll: true,
            preserveState: Boolean(product),
            onError: (errors) => {
                if (!errors || Object.keys(errors).length === 0) {
                    growl?.push({ type: 'error', message: 'Could not save the product.' });
                }
            },
        };
        if (isMultivendor) {
            if (multivendor.saveMethod === 'put') {
                form.put(multivendor.savePath, options);
            } else {
                form.post(multivendor.savePath, options);
            }
            return;
        }
        if (product) {
            form.put(`/admin/products/${product.id}`, options);
        } else {
            form.post('/admin/products', options);
        }
    };

    const setRelated = (items) => {
        setRelatedItems(items);
        form.setData('related_ids', items.map((item) => item.id));
    };

    const setCategories = (ids) => {
        form.setData({
            ...form.data,
            category_ids: ids,
            default_category_id: ids.includes(Number(form.data.default_category_id)) ? form.data.default_category_id : (ids[0] || ''),
        });
    };

    const addPendingImages = (fileList) => {
        const files = Array.from(fileList || []);
        if (!files.length) {
            return;
        }
        if (product) {
            files.forEach((file, index) => {
                const data = new FormData();
                data.append('image', file);
                data.append('cover', gallery.length || index ? '0' : '1');
                router.post(`/admin/products/${product.id}/images`, data, { forceFormData: true });
            });
            return;
        }
        setGallery((current) => [...current, ...files.map(pendingGalleryItem)]);
    };

    const selectedSavedCoverId = form.data.cover_image_id
        ? Number(form.data.cover_image_id)
        : null;

    const coverKey = pendingCoverUrl
        ? gallery.find((item) => item.url === pendingCoverUrl)?.key || null
        : (selectedSavedCoverId ? `saved:${selectedSavedCoverId}` : null);

    const chooseCover = (item) => {
        setGallery((current) => moveCoverFirst(current, item.key));
        if (item.id) {
            form.setData('cover_image_id', item.id);
            setPendingCoverUrl(null);
        } else {
            form.setData('cover_image_id', '');
            setPendingCoverUrl(item.url);
        }
    };

    const clearCover = () => {
        form.setData('cover_image_id', '');
        setPendingCoverUrl(null);
    };

    const removeGalleryItem = (item) => {
        const cleanupLocal = () => {
            if (item.file && item.url) {
                URL.revokeObjectURL(item.url);
            }
            if (pendingCoverUrl === item.url || (item.id && selectedSavedCoverId === Number(item.id))) {
                clearCover();
            }
            if (item.id) {
                setCombinationVariantItems((current) => current.map((variant) => (
                    Number(variant.cover_image_id) === Number(item.id)
                        ? { ...variant, cover_image_id: null, cover_path: '', cover_url: null }
                        : variant
                )));
            }
            setGallery((current) => current.filter((row) => row.key !== item.key));
        };

        if (item.id && product?.id) {
            router.delete(`/admin/products/${product.id}/images/${item.id}`, {
                preserveScroll: true,
                onSuccess: cleanupLocal,
            });
            return;
        }

        cleanupLocal();
    };

    const reorderGallery = (from, to) => {
        setGallery((current) => moveItem(current, from, to));
    };

    const savedImageKey = (product?.images || []).map((img) => img.id).join(',');
    useEffect(() => {
        const incoming = product?.images || [];
        if (!incoming.length) {
            return;
        }
        setGallery((current) => {
            const have = new Set(current.map((item) => item.id).filter(Boolean));
            const extra = incoming.filter((img) => !have.has(img.id)).map(savedGalleryItem);
            return extra.length ? [...current, ...extra] : current;
        });
    }, [savedImageKey]);

    const setChannels = (store, app) => {
        if (store && app) {
            form.setData('visibility', 'both');
        } else if (store) {
            form.setData('visibility', 'catalog');
        } else if (app) {
            form.setData('visibility', 'search');
        } else {
            form.setData('visibility', 'hidden');
        }
    };

    const onlineStore = form.data.visibility === 'catalog' || form.data.visibility === 'both';
    const mobileApp = form.data.visibility === 'search' || form.data.visibility === 'both';

    const galleryBlock = gallery.length ? (
        <ImageGallery
            items={gallery}
            coverKey={coverKey}
            alt={form.data.name}
            onSetCover={chooseCover}
            onClearCover={clearCover}
            onRemove={removeGalleryItem}
            onReorder={reorderGallery}
        />
    ) : null;

    const multivendorSidebar = isMultivendor ? (
        <>
            <Section title="Seller" required>
                <Field label="Seller" error={form.errors.seller_id}>
                    <Select
                        wide
                        value={String(form.data.seller_id || '')}
                        onChange={(e) => form.setData('seller_id', e.target.value)}
                        disabled={!!multivendor.listingId}
                    >
                        <option value="">Choose a seller</option>
                        {(multivendor.sellers || []).map((seller) => (
                            <option key={seller.id} value={seller.id}>{seller.label}</option>
                        ))}
                    </Select>
                </Field>
            </Section>
            {multivendor.reviewPath ? (
                <Section title="Moderation">
                    <Field label="Rejection reason">
                        <Textarea rows={3} value={reviewNote} onChange={(e) => setReviewNote(e.target.value)} />
                    </Field>
                    <div className="mt-4 flex flex-wrap gap-3">
                        <Button
                            type="button"
                            onClick={() => router.post(multivendor.reviewPath, { action: 'approve' }, { preserveScroll: true })}
                        >
                            Accept
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => router.post(multivendor.reviewPath, { action: 'reject', note: reviewNote }, { preserveScroll: true })}
                        >
                            Reject
                        </Button>
                    </div>
                </Section>
            ) : null}
            {multivendor.approvalStatus && !multivendor.reviewPath ? (
                <Section title="Moderation">
                    <Field label="Status">
                        <Input wide value={multivendor.approvalStatus} disabled />
                    </Field>
                    {multivendor.approvalNote ? (
                        <Field label="Note">
                            <Textarea rows={3} value={multivendor.approvalNote} disabled />
                        </Field>
                    ) : null}
                </Section>
            ) : null}
        </>
    ) : null;

    const selectTab = (next) => {
        const hasError = errorTabSet.has(next);
        setTab(next);
        const scroller = formRef.current?.closest('main');
        if (!hasError && scroller) {
            scroller.scrollTop = 0;
        }
        if (!hasError) {
            return;
        }
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                const root = formRef.current;
                if (!root) {
                    return;
                }
                const target = root.querySelector('[aria-invalid="true"], [role="alert"]');
                target?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        });
    };

    const toggleProductStatus = () => {
        if (statusBusy) {
            return;
        }
        if (!product?.id) {
            form.setData('is_enabled', !form.data.is_enabled);
            return;
        }
        setStatusBusy(true);
        router.post(`/admin/products/${product.id}/toggle`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('is_enabled', !form.data.is_enabled);
            },
            onFinish: () => setStatusBusy(false),
        });
    };

    return (
        <Layout mainClassName={ui.mainFlushTop}>
            <form ref={formRef} onSubmit={submit} className={ui.formPageShell}>
                <div className={ui.formPageStickyHead}>
                    <PageHeader
                        crumbsFirst
                        title={title}
                        meta={(
                            <>
                                <Badge tone="neutral">{typeLabel}</Badge>
                                {isMultivendor && multivendor.shopName ? (
                                    <Badge tone="neutral">{multivendor.shopName}</Badge>
                                ) : null}
                                {isMultivendor && multivendor.approvalStatus ? (
                                    <Badge tone={multivendor.approvalStatus === 'approved' ? 'success' : 'warning'}>
                                        {multivendor.approvalStatus}
                                    </Badge>
                                ) : null}
                            </>
                        )}
                        crumbs={isMultivendor ? [
                            { href: '/admin', label: 'Dashboard' },
                            { href: multivendorDashboardPath, label: 'Multivendor Management' },
                            { href: productsIndexPath, label: 'Products' },
                            { label: title },
                        ] : [
                            { href: '/admin', label: 'Dashboard' },
                            { href: productsIndexPath, label: 'Products' },
                            { label: title },
                        ]}
                        actions={(
                            <>
                                <Button type="submit" icon="check" busy={form.processing}>
                                    {product ? t('admin.products.save_changes', 'Save changes') : t('admin.products.save', 'Save product')}
                                </Button>
                                {product ? (
                                <RowActions
                                    canView={false}
                                    canEdit={false}
                                    canDelete
                                    confirmMessage={`Delete “${title}”?`}
                                    onDelete={() => {
                                        if (isMultivendor && multivendor.deletePath) {
                                            router.delete(multivendor.deletePath, { preserveScroll: true });
                                            return;
                                        }
                                        router.delete(`/admin/products/${product.id}`);
                                    }}
                                    items={isMultivendor ? [] : [
                                        {
                                            key: 'new',
                                            label: 'New Product',
                                            icon: 'plus',
                                            onClick: () => router.get('/admin/products/create', {
                                                type: form.data.type || productTypeKey || 'simple',
                                            }),
                                        },
                                        {
                                            key: 'duplicate',
                                            label: 'Duplicate',
                                            icon: 'copy',
                                            onClick: () => router.post(`/admin/products/${product.id}/duplicate`),
                                        },
                                        form.data.slug
                                            ? {
                                                key: 'preview',
                                                label: 'Preview',
                                                icon: 'eye',
                                                href: `/${form.data.slug}`,
                                                native: true,
                                                target: '_blank',
                                            }
                                            : null,
                                    ]}
                                />
                                ) : null}
                            </>
                        )}
                    />

                    <FormErrors className="mb-2" errors={form.errors} variant="growl" replayKey={saveAttempt} />
                    <StatusTabs
                        tabs={tabItems}
                        value={tab}
                        onChange={selectTab}
                        className={ui.formEditTabs}
                    />
                </div>

                <div className={ui.formPageBody}>
                {tab === 'general' && usesCatalogLayout && (
                    <div className={ui.formLayoutWide}>
                        <div className={ui.formMain}>
                            <Section>
                                <div className="space-y-3">
                                    <LocalizedField
                                        label={t('admin.products.product_name', 'Product name')}
                                        required
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('name')}
                                        hasValue={hasValueForField('name')}
                                        fallbackValue={fallbackForField('name')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                        ai={(
                                            <AiAssist
                                                value={valueForField('name')}
                                                field="product.name"
                                                locale={selectedLanguage}
                                                context={{
                                                    product_name: valueForField('name'),
                                                    sku: form.data.sku,
                                                    shop_name: shop?.name,
                                                }}
                                                onApply={(text) => setLocalizedName(text)}
                                            />
                                        )}
                                    >
                                        {() => (
                                            <Input
                                                wide
                                                invalid={!!errorForField('name')}
                                                value={valueForField('name')}
                                                onChange={(e) => setLocalizedName(e.target.value)}
                                            />
                                        )}
                                    </LocalizedField>
                                    <LocalizedField
                                        label={shortDescriptionLabel}
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('short_description')}
                                        hasValue={hasValueForField('short_description')}
                                        fallbackValue={fallbackForField('short_description')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    >
                                        {() => (
                                            <Editor
                                                value={valueForField('short_description')}
                                                onChange={(html) => setTranslationField(selectedLanguage, 'short_description', html)}
                                                maxLength={shortMax}
                                                aiField="product.short_description"
                                                aiLocale={selectedLanguage}
                                                aiContext={{
                                                    product_name: valueForField('name'),
                                                    sku: form.data.sku,
                                                }}
                                            />
                                        )}
                                    </LocalizedField>
                                    <LocalizedField
                                        label={descriptionLabel}
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('description')}
                                        hasValue={hasValueForField('description')}
                                        fallbackValue={fallbackForField('description')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    >
                                        {() => (
                                            <Editor
                                                value={valueForField('description')}
                                                onChange={(html) => setTranslationField(selectedLanguage, 'description', html)}
                                                maxLength={descMax}
                                                aiField="product.description"
                                                aiLocale={selectedLanguage}
                                                aiContext={{
                                                    product_name: valueForField('name'),
                                                    sku: form.data.sku,
                                                }}
                                            />
                                        )}
                                    </LocalizedField>
                                    {productDefaults?.product_video !== false ? (
                                        <Field
                                            label={t('admin.products.video_url', 'Video URL')}
                                            hint={t('admin.products.video_url_hint', 'YouTube or Vimeo. It plays under the photos on the product page.')}
                                            error={form.errors.video_url}
                                        >
                                            <Input
                                                wide
                                                type="url"
                                                placeholder="https://www.youtube.com/watch?v="
                                                invalid={!!form.errors.video_url}
                                                value={form.data.video_url}
                                                onChange={(e) => form.setData('video_url', e.target.value)}
                                            />
                                        </Field>
                                    ) : null}
                                    <Field
                                        label={t('admin.products.brand', 'Brand')}
                                        required={!!productDefaults?.require_brand_to_publish}
                                        error={form.errors.brand_id}
                                    >
                                        <Select wide value={form.data.brand_id} onChange={(e) => form.setData('brand_id', e.target.value)}>
                                            <option value="">{t('admin.products.select_brand', 'Select brand')}</option>
                                            {brands.map((b) => <option key={b.id} value={b.id}>{b.translations?.[0]?.name}{b.is_enabled === false ? ' (Disabled)' : ''}</option>)}
                                        </Select>
                                    </Field>
                                    <RelatedProductPicker selected={relatedItems} excludeId={product?.id} onChange={setRelated} />
                                    <ProductOfferFields
                                        form={form}
                                        catalogSettings={catalogSettings}
                                        statusBusy={statusBusy}
                                        onToggleStatus={toggleProductStatus}
                                    />
                                    {barcodeMode !== 'off' ? (
                                        <div className="mt-4">
                                            <ProductQrCard url={product?.id ? productUrl : ''} saved={!!product?.id} />
                                        </div>
                                    ) : null}
                                </div>
                            </Section>
                        </div>
                        <div className={ui.formSideUnderStickyHead}>
                            {multivendorSidebar}
                            <Section title="Images" required={!!productDefaults?.require_cover_to_publish}>
                                <ImageDropzone onFiles={addPendingImages} />
                                <p className={cx(ui.muted, 'mt-2 mb-3')}>Upload one or more product images. On create they are saved with the product.</p>
                                {form.errors.cover_path ? <p className="mb-3 text-xs text-rose-600">{form.errors.cover_path}</p> : null}
                                {galleryBlock}
                            </Section>
                            <Section title="Categories" required>
                                <CategoryPicker
                                    categories={categories}
                                    selectedIds={form.data.category_ids}
                                    onChange={setCategories}
                                    defaultCategoryId={form.data.default_category_id}
                                    onDefaultChange={(id) => form.setData('default_category_id', id)}
                                    error={form.errors.category_ids || form.errors.default_category_id}
                                />
                            </Section>
                            <ProductVisibilityFields form={form} />
                        </div>
                    </div>
                )}

                {tab === 'general' && !usesCatalogLayout && (
                    <div className={ui.formLayout}>
                        <div className={ui.formMain}>
                            <Section title="Basic Information">
                                <div className={ui.fieldGrid}>
                                    <LocalizedField
                                        label={t('admin.products.product_name', 'Product name')}
                                        required
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('name')}
                                        hasValue={hasValueForField('name')}
                                        fallbackValue={fallbackForField('name')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                        ai={(
                                            <AiAssist
                                                value={valueForField('name')}
                                                field="product.name"
                                                locale={selectedLanguage}
                                                context={{ product_name: valueForField('name'), sku: form.data.sku, shop_name: shop?.name }}
                                                onApply={(text) => setLocalizedName(text)}
                                            />
                                        )}
                                    >
                                        {() => (
                                            <Input
                                                wide
                                                invalid={!!errorForField('name')}
                                                value={valueForField('name')}
                                                onChange={(e) => setLocalizedName(e.target.value)}
                                            />
                                        )}
                                    </LocalizedField>
                                    <Field label="SKU" required={requireSku} error={form.errors.sku} hint={skuMode === 'auto' && !requireSku ? 'Leave blank to auto-generate.' : undefined}>
                                        <Input wide invalid={!!form.errors.sku} value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} />
                                    </Field>
                                </div>
                                <div className="mt-3">
                                    <LocalizedField
                                        label={shortDescriptionLabel}
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('short_description')}
                                        hasValue={hasValueForField('short_description')}
                                        fallbackValue={fallbackForField('short_description')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    >
                                        {() => (
                                            <Editor
                                                value={valueForField('short_description')}
                                                onChange={(html) => setTranslationField(selectedLanguage, 'short_description', html)}
                                                maxLength={shortMax}
                                                aiField="product.short_description"
                                                aiLocale={selectedLanguage}
                                                aiContext={{ product_name: valueForField('name'), sku: form.data.sku }}
                                            />
                                        )}
                                    </LocalizedField>
                                    <LocalizedField
                                        label={descriptionLabel}
                                        languages={languages}
                                        currentLanguage={selectedLanguage}
                                        onLanguageChange={setSelectedLanguage}
                                        error={errorForField('description')}
                                        hasValue={hasValueForField('description')}
                                        fallbackValue={fallbackForField('description')}
                                        fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    >
                                        {() => (
                                            <Editor
                                                value={valueForField('description')}
                                                onChange={(html) => setTranslationField(selectedLanguage, 'description', html)}
                                                maxLength={descMax}
                                                aiField="product.description"
                                                aiLocale={selectedLanguage}
                                                aiContext={{ product_name: valueForField('name'), sku: form.data.sku }}
                                            />
                                        )}
                                    </LocalizedField>
                                </div>
                                <div className="mt-4">
                                    <ProductOfferFields
                                        form={form}
                                        catalogSettings={catalogSettings}
                                        statusBusy={statusBusy}
                                        onToggleStatus={toggleProductStatus}
                                    />
                                    {barcodeMode !== 'off' ? (
                                        <div className="mt-4">
                                            <ProductQrCard url={product?.id ? productUrl : ''} saved={!!product?.id} />
                                        </div>
                                    ) : null}
                                </div>
                            </Section>
                        </div>
                        <div className={ui.formSideUnderStickyHead}>
                            {multivendorSidebar}
                            <Section title="Product Images">
                                {cover ? (
                                    <CatalogImg src={cover.url || cover.path} alt={cover.alt || form.data.name} className="h-40 w-full rounded-lg bg-neutral-100 object-contain" />
                                ) : (
                                    <p className={ui.muted}>
                                        {product ? 'No images yet. Use the Media tab to upload.' : 'Save the product, then upload images from Media.'}
                                    </p>
                                )}
                            </Section>
                            <Section title="Status">
                                <div className="space-y-3">
                                    <Field label="Stock Status">
                                        <Select wide value={form.data.stock_status} onChange={(e) => form.setData('stock_status', e.target.value)}>
                                            {STOCK_STATUS_OPTIONS.map((opt) => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            </Section>
                            {timeline.length > 0 ? (
                                <Section title="Change history">
                                    <ul className="space-y-2">
                                        {timeline.map((row, index) => (
                                            <li key={`${row.at}-${index}`} className="text-sm">
                                                <span className={ui.muted}>{row.source}</span>
                                                <div>{row.href ? <a href={row.href}>{row.title}</a> : row.title}</div>
                                            </li>
                                        ))}
                                    </ul>
                                </Section>
                            ) : null}
                            <Section title="Publishing">
                                <p className={cx(ui.label, 'mb-2')}>Sales Channels</p>
                                <Checkbox label="Online Store" checked={onlineStore} onChange={(e) => setChannels(e.target.checked, mobileApp)} />
                                <div className="mt-2">
                                    <Checkbox label="Mobile App" checked={mobileApp} onChange={(e) => setChannels(onlineStore, e.target.checked)} />
                                </div>
                            </Section>
                            <ProductVisibilityFields form={form} />
                        </div>
                    </div>
                )}

                {tab === 'pricing' && (
                    <div className="space-y-4">
                        {isComposite ? (
                            <Section title="Pricing mode">
                                <ChoiceCards
                                    name="pricing-mode"
                                    options={pricingModeOptions}
                                    value={form.data.pricing_mode}
                                    onChange={(value) => form.setData('pricing_mode', value)}
                                />
                                {form.data.pricing_mode === 'fixed' ? (
                                    <div className={cx(ui.fieldGrid, 'mt-4')}>
                                        <Field label={form.data.type === 'bundle' ? 'Bundle price' : 'Fixed group price'} required error={form.errors.price}>
                                            <Input
                                                wide
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                invalid={!!form.errors.price}
                                                value={form.data.price}
                                                onChange={(e) => form.setData('price', e.target.value)}
                                            />
                                            <span className={cx(ui.muted, 'mt-1 block')}>{taxInputModeLabel(taxInclusiveInput)}</span>
                                        </Field>
                                    </div>
                                ) : null}
                                {form.data.pricing_mode === 'sum_discount' ? (
                                    <div className={cx(ui.fieldGrid, 'mt-4')}>
                                        <Field label="Discount type" error={form.errors.pricing_discount_type}>
                                            <Select wide value={form.data.pricing_discount_type} onChange={(e) => form.setData('pricing_discount_type', e.target.value)}>
                                                <option value="fixed">Fixed amount</option>
                                                <option value="percent">Percentage</option>
                                            </Select>
                                        </Field>
                                        <Field label="Discount value" error={form.errors.pricing_discount_value}>
                                            <Input
                                                wide
                                                type="number"
                                                min="0"
                                                max={form.data.pricing_discount_type === 'percent' ? '100' : undefined}
                                                step="0.01"
                                                value={form.data.pricing_discount_value}
                                                onChange={(e) => form.setData('pricing_discount_value', e.target.value)}
                                            />
                                        </Field>
                                    </div>
                                ) : null}
                                {form.data.pricing_mode === 'sum_adjustment' ? (
                                    <div className={cx(ui.fieldGrid, 'mt-4')}>
                                        <Field label="Adjustment amount" error={form.errors.pricing_discount_value} hint="Use a negative value to reduce the component subtotal.">
                                            <Input
                                                wide
                                                type="number"
                                                step="0.01"
                                                value={form.data.pricing_discount_value}
                                                onChange={(e) => form.setData('pricing_discount_value', e.target.value)}
                                            />
                                        </Field>
                                    </div>
                                ) : null}
                                {form.data.pricing_mode !== 'fixed' && compositionItems.length > 0 ? (
                                    <div className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm">
                                        <p className={ui.label}>Price preview</p>
                                        {compositionItems.map((item) => (
                                            <div key={item.id} className="flex justify-between gap-3 py-1">
                                                <span>{item.name}</span>
                                                <span>
                                                    {currencySymbol}{(Number(item.selling_price ?? item.price) || 0).toFixed(2)} × {item.quantity || 1}
                                                </span>
                                            </div>
                                        ))}
                                        <div className="mt-2 flex justify-between border-t border-neutral-200 pt-2 font-medium">
                                            <span>Final price</span>
                                            <span>{currencySymbol}{computedCompositePrice.toFixed(2)}</span>
                                        </div>
                                    </div>
                                ) : null}
                            </Section>
                        ) : null}
                        <Section title={isComposite ? 'Catalog pricing' : 'Pricing'}>
                        <div className={ui.fieldGrid}>
                            <Field label="Regular Price" required error={form.errors.price}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    invalid={!!form.errors.price}
                                    value={form.data.price}
                                    onChange={(e) => form.setData('price', e.target.value)}
                                    readOnly={isComposite && form.data.pricing_mode !== 'fixed'}
                                />
                                <span className={cx(ui.muted, 'mt-1 block')}>
                                    {isComposite && form.data.pricing_mode !== 'fixed'
                                        ? 'Computed from child products on the storefront.'
                                        : saleUnit === 'piece'
                                            ? 'Actual / base product price'
                                            : `Price per ${saleUnitLabel.toLowerCase()}`}
                                </span>
                                {!(isComposite && form.data.pricing_mode !== 'fixed') ? (
                                    <span className={cx(ui.muted, 'mt-1 block')}>{taxInputModeLabel(taxInclusiveInput)}</span>
                                ) : null}
                            </Field>
                            <Field label="Sale Price Type" error={form.errors.sale_price_type}>
                                <Select
                                    wide
                                    value={form.data.sale_price_type}
                                    onChange={(e) => form.setData('sale_price_type', e.target.value)}
                                    disabled={!salePricingEnabled}
                                >
                                    <option value="fixed">Fixed amount</option>
                                    <option value="percent">Percentage</option>
                                </Select>
                                {!salePricingEnabled ? (
                                    <span className={cx(ui.muted, 'mt-1 block')}>
                                        Sale pricing is off in Product Settings → Pricing.
                                    </span>
                                ) : null}
                            </Field>
                            <Field label="Sale Price" error={form.errors.sale_price}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    max={form.data.sale_price_type === 'percent' ? '100' : undefined}
                                    step={form.data.sale_price_type === 'percent' ? '0.01' : '0.01'}
                                    value={form.data.sale_price}
                                    onChange={(e) => form.setData('sale_price', e.target.value)}
                                    disabled={!salePricingEnabled}
                                />
                                <span className={cx(ui.muted, 'mt-1 block')}>
                                    {form.data.sale_price_type === 'percent' ? 'Percent off the regular price' : 'Discounted selling price'}
                                </span>
                                {form.data.sale_price_type !== 'percent' ? (
                                    <span className={cx(ui.muted, 'mt-1 block')}>{taxInputModeLabel(taxInclusiveInput)}</span>
                                ) : null}
                            </Field>
                            <Field label="Sale Start Date" error={form.errors.sale_starts_at}>
                                <Input
                                    wide
                                    type="date"
                                    value={form.data.sale_starts_at}
                                    onChange={(e) => form.setData('sale_starts_at', e.target.value)}
                                    disabled={!salePricingEnabled}
                                />
                            </Field>
                            <Field label="Sale End Date" error={form.errors.sale_ends_at}>
                                <Input
                                    wide
                                    type="date"
                                    value={form.data.sale_ends_at}
                                    onChange={(e) => form.setData('sale_ends_at', e.target.value)}
                                    disabled={!salePricingEnabled}
                                />
                            </Field>
                            {compareAtEnabled ? (
                                <Field label="Compare-at / MSRP" error={form.errors.compare_price}>
                                    <Input
                                        wide
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={form.data.compare_price}
                                        onChange={(e) => form.setData('compare_price', e.target.value)}
                                    />
                                    <span className={cx(ui.muted, 'mt-1 block')}>
                                        Original price shown with strikethrough next to the selling price. Leave blank to use the regular price when a sale is live.
                                    </span>
                                    <span className={cx(ui.muted, 'mt-1 block')}>{taxInputModeLabel(taxInclusiveInput)}</span>
                                </Field>
                            ) : (
                                <Field label="Compare-at / MSRP">
                                    <Input wide readOnly value="" placeholder="Disabled" />
                                    <span className={cx(ui.muted, 'mt-1 block')}>
                                        Compare-at is off in Product Settings → Pricing.
                                    </span>
                                </Field>
                            )}
                            <Field label="Cost Price" error={form.errors.cost_price}>
                                <Input wide type="number" min="0" step="0.01" value={form.data.cost_price} onChange={(e) => form.setData('cost_price', e.target.value)} />
                                <span className={cx(ui.muted, 'mt-1 block')}>Internal purchase / manufacturing cost</span>
                            </Field>
                            <Field label="Profit Amount">
                                <Input wide readOnly value={profitAmount.toFixed(2)} />
                                <span className={cx(ui.muted, 'mt-1 block')}>Selling price minus cost</span>
                            </Field>
                            <Field label="Profit Margin">
                                <Input wide readOnly value={`${profitMargin.toFixed(2)}%`} />
                                <span className={cx(ui.muted, 'mt-1 block')}>Automatically calculated from selling price and cost</span>
                            </Field>
                            <Field label="Tax class" error={form.errors.tax_class_id}>
                                <Select wide value={form.data.tax_class_id} onChange={(e) => form.setData('tax_class_id', e.target.value)}>
                                    <option value="">—</option>
                                    {taxClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                                </Select>
                            </Field>
                        </div>
                        <div className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm">
                            <p className={ui.label}>Tax price preview</p>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {taxInclusiveInput
                                    ? 'Catalog prices include tax (Product Settings → Pricing).'
                                    : 'Catalog prices exclude tax (Product Settings → Pricing).'}
                                {' '}
                                Rates use the shop country tax rules for the selected tax class.
                            </p>
                            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                <div className="flex justify-between gap-3 sm:block">
                                    <span className={ui.muted}>Excl. tax</span>
                                    <span className="font-medium">{formatCatalogMoney(taxPreview.excl, currencySymbol, priceDecimals)}</span>
                                </div>
                                <div className="flex justify-between gap-3 sm:block">
                                    <span className={ui.muted}>Incl. tax</span>
                                    <span className="font-medium">{formatCatalogMoney(taxPreview.incl, currencySymbol, priceDecimals)}</span>
                                </div>
                                <div className="flex justify-between gap-3 sm:block">
                                    <span className={ui.muted}>Tax</span>
                                    <span className="font-medium">{formatCatalogMoney(taxPreview.tax, currencySymbol, priceDecimals)}</span>
                                </div>
                            </div>
                            {!form.data.tax_class_id ? (
                                <p className={cx(ui.muted, 'mt-2')}>
                                    Select a tax class to estimate tax. Without one, excl. and incl. stay the same.
                                </p>
                            ) : taxLayers.length === 0 ? (
                                <p className={cx(ui.muted, 'mt-2')}>
                                    No matching tax rate for the shop country on this class.
                                </p>
                            ) : null}
                        </div>
                        </Section>
                    </div>
                )}

                {tab === 'inventory' && (
                    <div className="space-y-4">
                        {allowsSaleUnit ? (
                            <Section title="Sold by">
                                <ChoiceCards
                                    name="sale-unit"
                                    options={SALE_UNIT_OPTIONS}
                                    value={saleUnit}
                                    onChange={(value) => {
                                        const next = { ...form.data, sale_unit: value };
                                        const codes = SALE_UNIT_CODES[value] || SALE_UNIT_CODES.piece;
                                        if (!codes.some((opt) => opt.value === next.sale_unit_code)) {
                                            next.sale_unit_code = defaultSaleUnitCode(value, weightUnit);
                                        }
                                        if (value !== 'piece' && form.data.sale_unit === 'piece' && Number(next.qty_step) === 1) {
                                            next.qty_step = 0.1;
                                        }
                                        if (value !== 'piece' && form.data.sale_unit === 'piece' && Number(next.min_qty) === 1) {
                                            next.min_qty = 0.1;
                                        }
                                        if (value === 'piece' && form.data.sale_unit !== 'piece') {
                                            next.qty_step = Math.max(1, Math.round(Number(next.qty_step) || 1));
                                            next.min_qty = Math.max(1, Math.round(Number(next.min_qty) || 1));
                                            next.sale_unit_code = 'pc';
                                        }
                                        form.setData(next);
                                    }}
                                />
                                {saleUnit !== 'piece' ? (
                                    <div className={cx(ui.fieldGrid, 'mt-4')}>
                                        <Field label="Unit" error={form.errors.sale_unit_code}>
                                            <Select wide value={form.data.sale_unit_code} onChange={(e) => form.setData('sale_unit_code', e.target.value)}>
                                                {saleUnitCodes.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                ))}
                                            </Select>
                                        </Field>
                                    </div>
                                ) : null}
                                <p className={cx(ui.muted, 'mt-3')}>
                                    Customers enter quantity in this unit. Stock is stored in the same unit. Combinations (colour, cut) still work.
                                </p>
                            </Section>
                        ) : null}
                        {isComposite ? (
                            <Section title="Inventory mode">
                                <ChoiceCards
                                    name="inventory-mode"
                                    options={[
                                        { value: 'derived', label: 'Derived from child products', hint: 'Availability is calculated from component stock and quantities.' },
                                        { value: 'manual', label: 'Manual inventory', hint: 'Track stock on this parent product directly.' },
                                    ]}
                                    value={form.data.inventory_mode || 'derived'}
                                    onChange={(value) => {
                                        form.setData({
                                            ...form.data,
                                            inventory_mode: value,
                                            manage_stock: value === 'manual',
                                        });
                                    }}
                                />
                                {derivedInventory ? (
                                    <div className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
                                        <p className={ui.label}>Derived availability</p>
                                        <p className="text-2xl font-semibold text-neutral-900">{computedDerivedQty}</p>
                                        <p className={cx(ui.muted, 'mt-1')}>Based on required/default child stock and configured quantities.</p>
                                    </div>
                                ) : null}
                            </Section>
                        ) : null}
                        <Section title="Stock Status">
                            <div className={ui.fieldGrid}>
                                <Field label="Stock Status" error={form.errors.stock_status}>
                                    <Select wide value={form.data.stock_status} onChange={(e) => form.setData('stock_status', e.target.value)}>
                                        {STOCK_STATUS_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <div>
                                    <p className={cx(ui.label, 'mb-2')}>Computed status</p>
                                    <Badge tone={stockHint.tone}>{stockHint.label}</Badge>
                                    <p className={cx(ui.muted, 'mt-2')}>
                                        Available {availableStock} · threshold {form.data.low_stock_threshold || 0}
                                        {availableStock > 0 && availableStock <= Number(form.data.low_stock_threshold || 0) ? ' → Low Stock' : ''}
                                    </p>
                                </div>
                            </div>
                        </Section>

                        <Section title="Stock Management">
                            <Checkbox
                                label="Track inventory for this product"
                                checked={!!form.data.manage_stock}
                                disabled={derivedInventory}
                                onChange={(e) => form.setData('manage_stock', e.target.checked)}
                            />
                            {derivedInventory ? (
                                <p className={cx(ui.muted, 'mt-2')}>Parent stock is derived from child products and is not decremented separately.</p>
                            ) : null}
                            {form.data.manage_stock ? (
                                <div className="mt-4 space-y-4">
                                    <div className={ui.fieldGrid}>
                                        <Field label="Stock Quantity" error={form.errors.stock_qty}>
                                            <Input wide type="number" min="0" step={qtyInputStep} value={form.data.stock_qty} onChange={(e) => setCurrentStock(e.target.value)} />
                                        </Field>
                                        <Field label="Low Stock Threshold" error={form.errors.low_stock_threshold}>
                                            <Input wide type="number" min="0" value={form.data.low_stock_threshold} onChange={(e) => form.setData('low_stock_threshold', e.target.value)} />
                                        </Field>
                                    </div>
                                    <fieldset className="mt-3">
                                        <legend className={ui.label}>Out-of-stock behavior</legend>
                                        <div className={ui.choiceRow}>
                                            {[
                                                { value: 'allow', label: 'Allow' },
                                                { value: 'deny', label: 'Do not allow' },
                                                { value: 'hide', label: 'Hide products' },
                                            ].map((opt) => (
                                                <label key={opt.value} className={cx(ui.checkboxRow, 'cursor-pointer')}>
                                                    <input
                                                        type="radio"
                                                        name="backorder_status"
                                                        checked={form.data.backorder_status === opt.value}
                                                        onChange={() => form.setData('backorder_status', opt.value)}
                                                    />
                                                    {opt.label}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                </div>
                            ) : (
                                <p className={cx(ui.muted, 'mt-2')}>Inventory is not tracked for this product.</p>
                            )}
                        </Section>

                        {form.data.manage_stock ? (
                            <Section title="Quantity">
                                <div className={ui.fieldGrid3}>
                                    <Field label="Current Stock" error={form.errors.stock_qty}>
                                        <Input wide type="number" min="0" step={qtyInputStep} value={form.data.stock_qty} onChange={(e) => setCurrentStock(e.target.value)} />
                                    </Field>
                                    <Field label="Reserved Stock" error={form.errors.reserved_qty}>
                                        <Input wide type="number" min="0" step={qtyInputStep} value={form.data.reserved_qty} onChange={(e) => setReservedStock(e.target.value)} />
                                    </Field>
                                    <Field label="Available Stock">
                                        <Input wide readOnly value={availableStock} />
                                        <span className={cx(ui.muted, 'mt-1 block')}>Current Stock − Reserved Stock</span>
                                    </Field>
                                </div>
                            </Section>
                        ) : null}

                        <Section title="Purchase limits">
                            <div className={ui.fieldGrid3}>
                                <Field label={saleUnit === 'piece' ? 'Min qty' : `Min ${saleUnitLabel.toLowerCase()}`} error={form.errors.min_qty}>
                                    <Input wide type="number" min="0" step={qtyInputStep} placeholder={String(defaultMinQty)} value={form.data.min_qty} onChange={(e) => form.setData('min_qty', e.target.value)} />
                                </Field>
                                <Field label={saleUnit === 'piece' ? 'Max qty' : `Max ${saleUnitLabel.toLowerCase()}`} error={form.errors.max_qty}>
                                    <Input wide type="number" min="0" step={qtyInputStep} placeholder={defaultMaxQty === '' || defaultMaxQty == null ? '' : String(defaultMaxQty)} value={form.data.max_qty} onChange={(e) => form.setData('max_qty', e.target.value)} />
                                </Field>
                                <Field label={saleUnit === 'piece' ? 'Qty step' : `${saleUnitLabel} increment`} error={form.errors.qty_step}>
                                    <Input wide type="number" min={qtyInputStep} step={qtyInputStep} placeholder={String(defaultQtyStep)} value={form.data.qty_step} onChange={(e) => form.setData('qty_step', e.target.value)} />
                                </Field>
                            </div>
                            <p className={cx(ui.muted, 'mt-2')}>
                                {`Leave empty to follow Product Settings (min ${defaultMinQty}, step ${defaultQtyStep}${defaultMaxQty ? `, max ${defaultMaxQty}` : ', no maximum'}). A number here is for this product only.`}
                            </p>
                            <div className="mt-4">
                                <p className={cx(ui.muted, 'mb-2')}>Quantity breaks (unit price from this qty). Leave empty if unused.</p>
                                <RateTiersField
                                    value={form.data.qty_prices}
                                    onChange={(rows) => form.setData('qty_prices', rows)}
                                    minLabel="Qty from"
                                    maxLabel="Qty to"
                                    priceLabel="Unit price"
                                    addLabel="Add qty price"
                                />
                            </div>
                        </Section>

                        {form.data.manage_stock ? (
                            <Section title="Stock Alerts">
                                <div className={ui.fieldGrid}>
                                    <Field label="Low Stock Alert" error={form.errors.low_stock_alert}>
                                        <Input wide type="number" min="0" value={form.data.low_stock_alert} onChange={(e) => form.setData('low_stock_alert', e.target.value)} />
                                    </Field>
                                    <Field label="Critical Stock" error={form.errors.critical_stock}>
                                        <Input wide type="number" min="0" value={form.data.critical_stock} onChange={(e) => form.setData('critical_stock', e.target.value)} />
                                    </Field>
                                </div>
                                <div className={cx(ui.choiceRow, 'mt-3')}>
                                    <Checkbox label="Notify when low stock" checked={!!form.data.notify_low_stock} onChange={(e) => form.setData('notify_low_stock', e.target.checked)} />
                                    <Checkbox label="Notify when out of stock" checked={!!form.data.notify_out_of_stock} onChange={(e) => form.setData('notify_out_of_stock', e.target.checked)} />
                                </div>
                            </Section>
                        ) : null}

                        {form.data.manage_stock ? (
                            <Section title="Warehouse / Location Inventory">
                                <p className={cx(ui.muted, 'mb-3')}>Stock is stored per warehouse.</p>
                                <div className="mb-3">
                                    <Button type="button" variant="secondary" href="/admin/warehouses">Manage warehouses</Button>
                                </div>
                                <div className={ui.tableWrap}>
                                    <Table>
                                        <THead>
                                            <tr>
                                                <Th>Warehouse</Th>
                                                <Th>Stock</Th>
                                                <Th>Reserved</Th>
                                                <Th>Available</Th>
                                            </tr>
                                        </THead>
                                        <tbody>
                                            {form.data.warehouse_stocks.length === 0 ? (
                                                <tr>
                                                    <Td className={ui.empty} colSpan={4}>No warehouses yet.</Td>
                                                </tr>
                                            ) : form.data.warehouse_stocks.map((row) => (
                                                <Tr key={row.warehouse_id}>
                                                    <Td>{row.name}{row.is_default ? ' (default)' : ''}</Td>
                                                    <Td>
                                                        <Input
                                                            compact
                                                            type="number"
                                                            min="0"
                                                            step={qtyInputStep}
                                                            value={row.qty}
                                                            onChange={(e) => setWarehouseStock(row.warehouse_id, { qty: e.target.value })}
                                                        />
                                                    </Td>
                                                    <Td>
                                                        <Input
                                                            compact
                                                            type="number"
                                                            min="0"
                                                            value={row.reserved_qty}
                                                            onChange={(e) => setWarehouseStock(row.warehouse_id, { reserved_qty: e.target.value })}
                                                        />
                                                    </Td>
                                                    <Td>{Math.max(0, Number(row.qty || 0) - Number(row.reserved_qty || 0))}</Td>
                                                </Tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </div>
                            </Section>
                        ) : null}

                        <Section title="Inventory History">
                            {inventoryHistory.length === 0 ? (
                                <p className={ui.muted}>{product ? 'No inventory history yet.' : 'History appears after the product is saved.'}</p>
                            ) : (
                                <div className={ui.tableWrap}>
                                    <Table>
                                        <THead>
                                            <tr>
                                                <Th>Date</Th>
                                                <Th>Action</Th>
                                                <Th>Qty</Th>
                                                <Th>Reference</Th>
                                            </tr>
                                        </THead>
                                        <tbody>
                                            {inventoryHistory.map((row) => (
                                                <Tr key={row.id}>
                                                    <Td>{row.date}</Td>
                                                    <Td>{MOVEMENT_LABELS[row.action] || row.action}</Td>
                                                    <Td>{row.qty > 0 ? `+${row.qty}` : row.qty}</Td>
                                                    <Td>{row.reference || '—'}</Td>
                                                </Tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </div>
                            )}
                        </Section>
                    </div>
                )}

                {tab === 'shipping' && (
                    <div className="space-y-4">
                        {isComposite ? (
                            <Section title="Shipping calculation">
                                <ChoiceCards
                                    name="shipping-mode"
                                    options={[
                                        { value: 'derived', label: 'Calculate from child products', hint: 'Use child product weights and shipping settings.' },
                                        { value: 'custom', label: 'Use custom shipping settings', hint: 'Enter package details on this product.' },
                                    ]}
                                    value={form.data.shipping_mode || 'derived'}
                                    onChange={(value) => form.setData('shipping_mode', value)}
                                />
                                {derivedShipping ? (
                                    <p className={cx(ui.muted, 'mt-3')}>Package dimensions below are hidden while shipping is derived from child products.</p>
                                ) : null}
                            </Section>
                        ) : null}
                        {!derivedShipping ? (
                            <>
                        <Section title="Package Details">
                            <div className={ui.fieldGrid4}>
                                <Field label="Length" error={form.errors.length}>
                                    <Input wide type="number" min="0" step="0.001" value={form.data.length} onChange={(e) => form.setData('length', e.target.value)} />
                                </Field>
                                <Field label="Width" error={form.errors.width}>
                                    <Input wide type="number" min="0" step="0.001" value={form.data.width} onChange={(e) => form.setData('width', e.target.value)} />
                                </Field>
                                <Field label="Height" error={form.errors.height}>
                                    <Input wide type="number" min="0" step="0.001" value={form.data.height} onChange={(e) => form.setData('height', e.target.value)} />
                                </Field>
                                <Field label="Length class" error={form.errors.length_class}>
                                    <Select wide value={form.data.length_class} onChange={(e) => form.setData('length_class', e.target.value)}>
                                        {LENGTH_CLASS_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                            <div className={cx(ui.fieldGrid4, 'mt-3')}>
                                <Field label={saleUnit === 'weight' ? 'Extra packaging (per ordered unit)' : (saleUnit === 'piece' ? 'Package Weight' : 'Shipping weight per unit')} error={form.errors.weight}>
                                    <Input wide type="number" min="0" step="0.001" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} />
                                </Field>
                                <Field label="Weight class" error={form.errors.weight_class}>
                                    <Select wide value={form.data.weight_class} onChange={(e) => form.setData('weight_class', e.target.value)}>
                                        {WEIGHT_CLASS_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                        </Section>

                        <Section title="Delivery Settings">
                            <div className={ui.fieldGrid}>
                                <Field label="Processing Time" error={form.errors.processing_time}>
                                    <div className={ui.row}>
                                        <Input grow type="number" min="0" value={form.data.processing_time} onChange={(e) => form.setData('processing_time', e.target.value)} />
                                        <Select value={form.data.processing_time_unit} onChange={(e) => form.setData('processing_time_unit', e.target.value)}>
                                            <option value="days">Days</option>
                                            <option value="weeks">Weeks</option>
                                        </Select>
                                    </div>
                                </Field>
                            </div>
                            <div className={cx(ui.fieldGrid, 'mt-3')}>
                                <Field label="Estimated Delivery From" error={form.errors.delivery_from}>
                                    <Input wide type="number" min="0" value={form.data.delivery_from} onChange={(e) => form.setData('delivery_from', e.target.value)} />
                                </Field>
                                <Field label="Estimated Delivery To" error={form.errors.delivery_to}>
                                    <Input wide type="number" min="0" value={form.data.delivery_to} onChange={(e) => form.setData('delivery_to', e.target.value)} />
                                </Field>
                            </div>
                        </Section>

                        <Section title="Shipping Options">
                            <div className={ui.fieldGrid4}>
                                <Field label="Additional shipping costs" error={form.errors.additional_shipping_cost}>
                                    <div className={ui.inputGroup}>
                                        <span className={ui.inputPrefix}>{currencySymbol}</span>
                                        <Input
                                            unstyled
                                            className={ui.inputBare}
                                            type="number"
                                            min="0"
                                            step="0.000001"
                                            value={form.data.additional_shipping_cost}
                                            onChange={(e) => form.setData('additional_shipping_cost', e.target.value)}
                                        />
                                    </div>
                                </Field>
                            </div>
                            <div className="mt-4">
                                <p className={cx(ui.label, 'mb-2')}>Available shippings</p>
                                <Checkbox
                                    label="All carriers"
                                    checked={!!form.data.available_all_carriers}
                                    onChange={(e) => form.setData('available_all_carriers', e.target.checked)}
                                />
                                <div className="mt-3 space-y-2">
                                    {shippingMethods.length === 0 ? (
                                        <p className={ui.muted}>No shipping methods yet.</p>
                                    ) : shippingMethods.map((method) => {
                                        const id = Number(method.id);
                                        const checked = form.data.available_all_carriers || form.data.shipping_method_ids.includes(id);

                                        return (
                                            <Checkbox
                                                key={method.id}
                                                label={method.name}
                                                checked={checked}
                                                disabled={!!form.data.available_all_carriers}
                                                onChange={(e) => {
                                                    const ids = form.data.shipping_method_ids;
                                                    form.setData(
                                                        'shipping_method_ids',
                                                        e.target.checked ? [...ids, id] : ids.filter((value) => value !== id),
                                                    );
                                                }}
                                            />
                                        );
                                    })}
                                </div>
                            </div>
                        </Section>
                            </>
                        ) : null}
                    </div>
                )}

                {tab === 'products' && form.data.type === 'group' && (
                    <div className="space-y-4">
                        <Section title="Selection mode">
                            <ChoiceCards
                                name="selection-mode"
                                options={[
                                    { value: 'all', label: 'All products', hint: 'Every configured child product is included.' },
                                    { value: 'customer', label: 'Customer can select', hint: 'Customers choose products according to default/optional rules.' },
                                ]}
                                value={form.data.selection_mode || 'all'}
                                onChange={(value) => form.setData('selection_mode', value)}
                            />
                        </Section>
                        <ComponentItemsTab
                            variant="group"
                            items={compositionItems}
                            onChange={setCompositionItems}
                            excludeId={product?.id}
                            errors={form.errors}
                            currencySymbol={currencySymbol}
                        />
                    </div>
                )}

                {tab === 'bundle' && form.data.type === 'bundle' && (
                    <div className="space-y-4">
                        <Section title="Bundle type">
                            <ChoiceCards
                                name="bundle-type"
                                options={[
                                    { value: 'fixed', label: 'Fixed bundle', hint: 'Customer receives the predefined components.' },
                                    { value: 'configurable', label: 'Configurable bundle', hint: 'Use option groups to support future customer choices.' },
                                ]}
                                value={form.data.bundle_type || 'fixed'}
                                onChange={(value) => form.setData('bundle_type', value)}
                            />
                        </Section>
                        <ComponentItemsTab
                            variant="bundle"
                            items={compositionItems}
                            onChange={setCompositionItems}
                            excludeId={product?.id}
                            errors={form.errors}
                            currencySymbol={currencySymbol}
                            bundleType={form.data.bundle_type || 'fixed'}
                        />
                    </div>
                )}

                {tab === 'seo' && (
                    <div className="space-y-4">
                        <Section title="SEO">
                            <div className="space-y-3">
                                <LocalizedField
                                    label="SEO Title"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('meta_title')}
                                    hasValue={hasValueForField('meta_title')}
                                    fallbackValue={fallbackForField('meta_title')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    ai={(
                                        <AiAssist
                                            value={valueForField('meta_title')}
                                            field="product.meta_title"
                                            locale={selectedLanguage}
                                            context={{ product_name: valueForField('name') }}
                                            onApply={(text) => setTranslationField(selectedLanguage, 'meta_title', text)}
                                        />
                                    )}
                                >
                                    {() => (
                                        <>
                                            <Input wide maxLength={metaTitleMax} value={valueForField('meta_title')} onChange={(e) => setTranslationField(selectedLanguage, 'meta_title', e.target.value)} />
                                            <p className={cx(ui.muted, 'text-right mt-1')}>{(valueForField('meta_title') || '').length} / {metaTitleMax} characters</p>
                                        </>
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="Meta Description"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('meta_description')}
                                    hasValue={hasValueForField('meta_description')}
                                    fallbackValue={fallbackForField('meta_description')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                    ai={(
                                        <AiAssist
                                            value={valueForField('meta_description')}
                                            field="product.meta_description"
                                            locale={selectedLanguage}
                                            context={{ product_name: valueForField('name') }}
                                            onApply={(text) => setTranslationField(selectedLanguage, 'meta_description', text)}
                                        />
                                    )}
                                >
                                    {() => (
                                        <>
                                            <Textarea rows="3" maxLength={metaDescMax} value={valueForField('meta_description')} onChange={(e) => setTranslationField(selectedLanguage, 'meta_description', e.target.value)} />
                                            <p className={cx(ui.muted, 'text-right mt-1')}>{(valueForField('meta_description') || '').length} / {metaDescMax} characters</p>
                                        </>
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="Meta Keywords"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('meta_keywords')}
                                    hasValue={hasValueForField('meta_keywords')}
                                    fallbackValue={fallbackForField('meta_keywords')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                >
                                    {() => (
                                        <TagInput
                                            value={keywordsToList(valueForField('meta_keywords'))}
                                            onChange={(tags) => setTranslationField(selectedLanguage, 'meta_keywords', tags.join(', '))}
                                            placeholder="Type keyword and press Enter"
                                        />
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="Focus Keyword"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('focus_keyword')}
                                    hasValue={hasValueForField('focus_keyword')}
                                    fallbackValue={fallbackForField('focus_keyword')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                >
                                    {() => (
                                        <Input wide value={valueForField('focus_keyword')} onChange={(e) => setTranslationField(selectedLanguage, 'focus_keyword', e.target.value)} />
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="URL Slug"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('slug')}
                                    hasValue={hasValueForField('slug')}
                                    fallbackValue={fallbackForField('slug')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                >
                                    {() => (
                                        <Input
                                            wide
                                            value={valueForField('slug')}
                                            onChange={(e) => {
                                                const languageCode = selectedLanguage;
                                                setSlugLocked((current) => ({ ...current, [languageCode]: true }));
                                                setTranslationField(languageCode, 'slug', slugify(e.target.value));
                                            }}
                                        />
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="Canonical URL"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('canonical_url')}
                                    hasValue={hasValueForField('canonical_url')}
                                    fallbackValue={fallbackForField('canonical_url')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                >
                                    {() => (
                                        <Input wide type="url" value={valueForField('canonical_url')} onChange={(e) => setTranslationField(selectedLanguage, 'canonical_url', e.target.value)} />
                                    )}
                                </LocalizedField>
                                <LocalizedField
                                    label="Search Engine Visibility"
                                    languages={languages}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={setSelectedLanguage}
                                    error={errorForField('robots')}
                                    hasValue={hasValueForField('robots')}
                                    fallbackValue={fallbackForField('robots')}
                                    fallbackLanguage={languages.find((row) => row.code === defaultLanguage)}
                                >
                                    {() => (
                                        <Select wide value={valueForField('robots') || 'index,follow'} onChange={(e) => setTranslationField(selectedLanguage, 'robots', e.target.value)}>
                                        {ROBOTS_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                    )}
                                </LocalizedField>
                            </div>
                        </Section>

                        <Section title="Search Preview">
                            <div className={ui.seoPreview}>
                                <div className={ui.seoPreviewTitle}>{form.data.meta_title || form.data.name || 'Product title'}</div>
                                <div className={ui.seoPreviewUrl}>{seoPreviewUrl(form.data.canonical_url, shopUrl, form.data.slug)}</div>
                                <div className={ui.seoPreviewDesc}>
                                    {form.data.meta_description || form.data.short_description || 'Meta description will appear here.'}
                                </div>
                            </div>
                        </Section>
                    </div>
                )}

                {tab === 'gift_card' && isGiftCard ? (
                    <GiftCardTab
                        productId={product?.id || null}
                        config={giftCardSettings}
                        amounts={giftCardAmounts}
                        onConfigChange={setGiftCardSettings}
                        onAmountsChange={setGiftCardAmounts}
                        errors={form.errors}
                        currencySymbol={currencySymbol}
                        categories={categories}
                    />
                ) : null}

                {tab === 'service' && isService ? (
                    <ServiceTab
                        config={serviceSettings}
                        onChange={setServiceSettings}
                        errors={form.errors}
                    />
                ) : null}

                {tab === 'rental' && isRental ? (
                    <RentalTab
                        config={rentalSettings}
                        tiers={rentalTiers}
                        assets={rentalAssets}
                        inventorySummary={rentalInventorySummary}
                        onConfigChange={setRentalSettings}
                        onTiersChange={setRentalTiers}
                        onAssetsChange={setRentalAssets}
                        errors={form.errors}
                        currencySymbol={currencySymbol}
                    />
                ) : null}

                {tab === 'preorder' && supportsPreorder ? (
                    <PreorderTab
                        enabled={preorderEnabled}
                        onEnabled={(next) => {
                            if (next && subscriptionEnabled) {
                                growl?.push({ type: 'error', message: SUBSCRIPTION_PREORDER_CONFLICT });
                                return;
                            }
                            setPreorderEnabled(next);
                        }}
                        config={preorderSettings}
                        onChange={setPreorderSettings}
                        errors={form.errors}
                        currencySymbol={currencySymbol}
                        productPrice={sellingPrice}
                        blockedBySubscription={!!subscriptionEnabled && !preorderEnabled}
                        conflictActive={!!subscriptionEnabled && !!preorderEnabled}
                        conflictMessage={SUBSCRIPTION_PREORDER_CONFLICT}
                    />
                ) : null}

                {tab === 'combinations' && isCombination ? (
                    <CombinationsTab
                        catalog={combinationAttributeCatalog}
                        axes={combinationAxisItems}
                        variants={combinationVariantItems}
                        productImages={gallery.filter((item) => item.id && (item.path || item.url)).map((item) => ({
                            id: item.id,
                            path: item.path || '',
                            url: catalogImageUrl(item.url || item.path, 'md'),
                        }))}
                        uploadingImage={uploadingCombinationImage}
                        onUploadImage={product ? ((file, helpers = {}) => {
                            const knownIds = new Set(
                                gallery.filter((item) => item.id).map((item) => Number(item.id)),
                            );
                            const data = new FormData();
                            data.append('image', file);
                            data.append('cover', '0');
                            setUploadingCombinationImage(true);
                            router.post(`/admin/products/${product.id}/images`, data, {
                                forceFormData: true,
                                preserveScroll: true,
                                preserveState: true,
                                onSuccess: (page) => {
                                    const incoming = page.props.product?.images || [];
                                    const added = incoming.filter((img) => !knownIds.has(Number(img.id)));
                                    const newest = added[added.length - 1] || null;
                                    if (newest) {
                                        helpers.onUploaded?.({
                                            id: newest.id,
                                            path: newest.path,
                                            url: catalogImageUrl(newest.url || newest.path, 'md'),
                                        });
                                    }
                                },
                                onFinish: () => setUploadingCombinationImage(false),
                            });
                        }) : undefined}
                        baseProduct={{
                            price: form.data.price,
                            sale_price: form.data.sale_price,
                            sale_price_type: form.data.sale_price_type,
                            cost_price: form.data.cost_price,
                            weight: form.data.weight,
                            weight_class: form.data.weight_class,
                            length: form.data.length,
                            width: form.data.width,
                            height: form.data.height,
                        }}
                        onAxesChange={setCombinationAxisItems}
                        onVariantsChange={setCombinationVariantItems}
                        onDeletedVariantIdsChange={setDeletedCombinationVariantIds}
                        errors={form.errors}
                        currencySymbol={currencySymbol}
                    />
                ) : null}

                {tab === 'variants' && !isCombination && !isGiftCard && !isService && !isRental ? (
                    <Section title="Variants">
                        <p className={ui.muted}>This product type does not use combination variants.</p>
                    </Section>
                ) : null}

                {tab === 'details' && (
                    <div className="space-y-4">
                        <Section title="Details">
                            <div className={ui.fieldGrid}>
                                <Field label="SKU" required={requireSku} error={form.errors.sku} hint={skuMode === 'auto' && !requireSku ? 'Leave blank to auto-generate.' : undefined}>
                                    <Input wide invalid={!!form.errors.sku} value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} />
                                </Field>
                                <Field label="ISBN" error={form.errors.isbn}>
                                    <Input wide invalid={!!form.errors.isbn} value={form.data.isbn} onChange={(e) => form.setData('isbn', e.target.value)} />
                                </Field>
                                {barcodeMode !== 'off' ? (
                                    <Field label="UPC" required={requireBarcodeCodes} error={form.errors.upc} hint="Retail number for warehouse scanners and product feeds.">
                                        <Input wide invalid={!!form.errors.upc} value={form.data.upc} onChange={(e) => form.setData('upc', e.target.value)} />
                                    </Field>
                                ) : null}
                                <Field label="MPN" error={form.errors.mpn}>
                                    <Input wide invalid={!!form.errors.mpn} value={form.data.mpn} onChange={(e) => form.setData('mpn', e.target.value)} />
                                </Field>
                                {barcodeMode !== 'off' ? (
                                    <Field label="GTIN" required={requireBarcodeCodes} error={form.errors.gtin} hint="Retail number. The QR code is what a customer scans.">
                                        <Input wide invalid={!!form.errors.gtin} value={form.data.gtin} onChange={(e) => form.setData('gtin', e.target.value)} />
                                    </Field>
                                ) : null}
                            </div>
                        </Section>
                        <Section title="Suppliers">
                            <SearchSelect
                                label="Assigned suppliers"
                                options={supplierOptions}
                                selectedOptions={selectedSuppliers}
                                value={form.data.supplier_ids}
                                onChange={setSupplierIds}
                                placeholder="Search suppliers by name…"
                                hint="Pick one or more vendors for this product. Assign products from here — not from the supplier page."
                                error={form.errors.supplier_ids || form.errors.supplier_id}
                            />
                            {form.data.supplier_ids.length > 1 ? (
                                <div className="mt-3">
                                    <Field label="Default supplier" error={form.errors.supplier_id}>
                                        <Select
                                            wide
                                            value={form.data.supplier_id || ''}
                                            onChange={(e) => form.setData('supplier_id', e.target.value ? Number(e.target.value) : '')}
                                        >
                                            <option value="">Select default</option>
                                            {form.data.supplier_ids.map((id) => {
                                                const supplier = supplierOptions.find((row) => Number(row.id) === Number(id));
                                                return (
                                                    <option key={id} value={id}>
                                                        {supplier?.name || `Supplier #${id}`}
                                                        {supplier?.is_enabled === false ? ' (Disabled)' : ''}
                                                    </option>
                                                );
                                            })}
                                        </Select>
                                    </Field>
                                </div>
                            ) : null}
                        </Section>
                        <Section title="Features">
                            <FeaturePicker
                                features={features}
                                selected={form.data.feature_value_ids}
                                onChange={(rows) => form.setData('feature_value_ids', rows)}
                            />
                        </Section>
                        <Section title="Condition">
                            <Toggle
                                label="Display condition on product page"
                                value={!!form.data.display_condition}
                                error={form.errors.display_condition}
                                onChange={(next) => form.setData('display_condition', next)}
                            />
                            {form.data.display_condition ? (
                                <div className={cx(ui.fieldGrid, 'mt-3')}>
                                    <Field label="Condition" error={form.errors.condition}>
                                        <Select wide value={form.data.condition} onChange={(e) => form.setData('condition', e.target.value)}>
                                            <option value="">Select condition</option>
                                            {CONDITION_OPTIONS.map((opt) => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            ) : null}
                        </Section>
                    </div>
                )}

                {tab === 'attributes' && (
                    <Section title={t('admin.products.tab.attributes', 'Attributes')}>
                        <div className={ui.fieldGrid}>
                            <Field
                                label={t('admin.products.brand', 'Brand')}
                                required={!!productDefaults?.require_brand_to_publish}
                                error={form.errors.brand_id}
                            >
                                <Select wide value={form.data.brand_id} onChange={(e) => form.setData('brand_id', e.target.value)}>
                                    <option value="">—</option>
                                    {brands.map((b) => <option key={b.id} value={b.id}>{b.translations?.[0]?.name}{b.is_enabled === false ? ' (Disabled)' : ''}</option>)}
                                </Select>
                            </Field>
                            <Field label="Default category" required error={form.errors.default_category_id}>
                                <Select wide value={form.data.default_category_id} onChange={(e) => form.setData('default_category_id', e.target.value)}>
                                    <option value="">—</option>
                                    {form.data.category_ids.map((id) => {
                                        const category = categories.find((row) => Number(row.id) === Number(id));
                                        return (
                                            <option key={id} value={id}>
                                                {categoryLabel(category)}
                                            </option>
                                        );
                                    })}
                                </Select>
                            </Field>
                        </div>
                        <div className="mt-3">
                            <Label required className="mb-1.5">Categories</Label>
                            <CategoryPicker
                                categories={categories}
                                selectedIds={form.data.category_ids}
                                onChange={setCategories}
                                defaultCategoryId={form.data.default_category_id}
                                onDefaultChange={(id) => form.setData('default_category_id', id)}
                                error={form.errors.category_ids || form.errors.default_category_id}
                            />
                        </div>
                    </Section>
                )}

                {tab === 'media' && (
                    <div className="space-y-4">
                        {productDefaults?.product_video !== false ? (
                            <Section
                                title={t('admin.products.product_video', 'Product video')}
                                hint={t('admin.products.product_video_hint', 'Paste a YouTube or Vimeo link. The same field is on the General tab. It plays under the photos on the storefront product page.')}
                            >
                                <Field
                                    label={t('admin.products.video_url', 'Video URL')}
                                    error={form.errors.video_url}
                                >
                                    <Input
                                        wide
                                        invalid={!!form.errors.video_url}
                                        value={form.data.video_url}
                                        onChange={(e) => form.setData('video_url', e.target.value)}
                                        placeholder="https://www.youtube.com/watch?v=…"
                                    />
                                </Field>
                            </Section>
                        ) : null}
                        <ProductFiles
                            purpose="document"
                            library={libraryFiles}
                            loading={libraryLoading}
                            attachedFiles={documentAttachedFiles}
                            selectedIds={documentSelectedIds}
                            onAttach={(file) => attachLibraryFile(file, 'document')}
                            onDetach={detachLibraryFile}
                            query={fileQuery}
                            onQuery={setFileQuery}
                            pendingAttachments={documentPendingAttachments}
                            onAdd={() => openFileModal('document')}
                            onRemovePending={(item) => {
                                setPendingAttachments((current) => current.filter((row) => row.pendingKey !== item.pendingKey));
                            }}
                        />
                    </div>
                )}

                {tab === 'downloads' && isVirtualProduct && (
                    <ProductFiles
                        purpose="paid"
                        library={libraryFiles}
                        loading={libraryLoading}
                        attachedFiles={paidAttachedFiles}
                        selectedIds={paidSelectedIds}
                        onAttach={(file) => attachLibraryFile(file, 'paid')}
                        onDetach={detachLibraryFile}
                        query={fileQuery}
                        onQuery={setFileQuery}
                        pendingAttachments={paidPendingAttachments}
                        onAdd={() => openFileModal('paid')}
                        onRemovePending={(item) => {
                            setPendingAttachments((current) => current.filter((row) => row.pendingKey !== item.pendingKey));
                        }}
                    />
                )}

                {tab === 'customizable' && (
                    <CustomizationTab
                        enabled={customizationEnabled}
                        fields={customizationFields}
                        onEnabled={setCustomizationEnabled}
                        onChange={setCustomizationFields}
                        currencySymbol={currencySymbol}
                        errors={form.errors}
                    />
                )}

                {tab === 'subscription' && (
                    <ProductSubscriptionTab
                        enabled={subscriptionEnabled}
                        purchaseOption={purchaseOption}
                        rows={assignedPlans}
                        availablePlans={availableSubscriptionPlans}
                        regularPrice={sellingPrice}
                        currencySymbol={currencySymbol}
                        errors={form.errors}
                        productId={product?.id || null}
                        languages={languages}
                        selectedLanguage={selectedLanguage}
                        onLanguageChange={setSelectedLanguage}
                        message={valueForField('subscription_message')}
                        onMessage={(value) => setTranslationField(selectedLanguage, 'subscription_message', value)}
                        messageError={errorForField('subscription_message')}
                        onEnabled={(next) => {
                            if (next && preorderEnabled) {
                                growl?.push({ type: 'error', message: SUBSCRIPTION_PREORDER_CONFLICT });
                                return;
                            }
                            setSubscriptionEnabled(next);
                        }}
                        onPurchaseOption={setPurchaseOption}
                        onChange={setAssignedPlans}
                        blockedByPreorder={!!preorderEnabled && !subscriptionEnabled}
                        conflictActive={!!subscriptionEnabled && !!preorderEnabled}
                        conflictMessage={SUBSCRIPTION_PREORDER_CONFLICT}
                    />
                )}

                {tab === 'history' && (
                    <Section title="History">
                        <p className={ui.muted}>No history yet.</p>
                    </Section>
                )}
                </div>
            </form>
            {fileModalOpen ? (
                <AttachmentModal
                    productId={product?.id}
                    form={attachmentForm}
                    purpose={fileModalPurpose}
                    onUploaded={(file) => {
                        if (!file?.id) {
                            return;
                        }
                        attachLibraryFile(file, fileModalPurpose === 'paid' ? 'paid' : 'document');
                    }}
                    onQueue={(item) => {
                        const role = fileModalPurpose === 'paid' && isVirtualProduct ? 'paid' : 'document';
                        setPendingAttachments((current) => [
                            ...current,
                            {
                                pendingKey: `pending:${item.file.name}:${item.file.size}:${Date.now()}`,
                                label: item.label,
                                description: item.description,
                                original_name: item.file.name,
                                mime_type: item.file.type || '',
                                file: item.file,
                                role,
                            },
                        ]);
                    }}
                    onClose={() => setFileModalOpen(false)}
                />
            ) : null}
        </Layout>
    );
}
