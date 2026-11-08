import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Alert, Button, Card, Checkbox, ColorField, Field, FormErrors, Icon, Input, LocalizedField, PageHeader, Select, Table, THead, Th, Tr, Td, Textarea, Toggle, ui, t, cx,
} from '../../ui';
import { catalogImageUrl } from '../../lib/catalogMedia';
import { adminUrl } from '../../webPath';
import Editor from '../../ui/Editor';

const EMPTY_SLIDE = {
    eyebrow: '', title: '', body: '', content: '', image_url: '', cta_label: 'Shop Now', cta_url: '/catalog',
};
const HERO_PLACEHOLDER = '/themes/classic/assets/merchant/home/hero/placeholder.png';
const HERO_MEDIA = '/themes/classic/assets/merchant/home/hero';

/** Bundled defaults: layout 1 = 3 slides; layout 2 = 2×3; layout 3 = 3×3. */
function defaultHeroLayoutColumns() {
    const slide = (file, title = 'Shop the look') => ({
        ...EMPTY_SLIDE,
        title,
        content: `<h2>${title}</h2><p><a href="/catalog">Shop Now</a></p>`,
        image_url: `${HERO_MEDIA}/${file}`,
    });

    return {
        1: [{
            width_hint: 'full',
            slides: [
                slide('layout-1/slide-1.png', 'Insulated bottles for desk and travel'),
                slide('layout-1/slide-2.png', 'Smooth-writing pens in three colors'),
                slide('layout-1/slide-3.png', 'Discover premium fragrance today'),
            ],
        }],
        2: [
            {
                width_hint: 'main',
                slides: [
                    slide('layout-2/a-1.png', 'Insulated bottles for desk and travel'),
                    slide('layout-2/a-2.jpg', 'Smooth-writing pens in three colors'),
                    slide('layout-2/a-3.jpg', 'Bleu Élégance eau de parfum'),
                ],
            },
            {
                width_hint: 'side',
                slides: [
                    slide('layout-2/b-1.jpg', '70mai smart dash cam'),
                    slide('layout-2/b-2.jpg', 'Adjustable phone & tablet stands'),
                    slide('layout-2/b-3.jpg', 'Minimal quartz desk clocks'),
                ],
            },
        ],
        3: [
            {
                width_hint: 'main',
                slides: [
                    slide('layout-3/a-1.jpg', 'Mirror swim goggles'),
                    slide('layout-3/a-2.jpg', 'Spinner carry-on luggage'),
                    slide('layout-3/a-3.jpg', 'Ergonomic mesh office chair'),
                ],
            },
            {
                width_hint: 'side',
                slides: [
                    slide('layout-3/b-1.jpg', 'Pens in three colors'),
                    slide('layout-3/b-2.jpg', '70mai dash cam'),
                    slide('layout-3/b-3.jpg', 'Adjustable phone stands'),
                ],
            },
            {
                width_hint: 'side',
                slides: [
                    slide('layout-3/c-1.jpg', 'Speckled ceramic mug'),
                    slide('layout-3/c-2.jpg', 'Pro laptop lineup'),
                    slide('layout-3/c-3.jpg', 'Classic baseball caps'),
                ],
            },
        ],
    };
}

function syncLegacyHeroFields(columns) {
    const mainSlides = columns[0]?.slides || [];
    const sideSlides = columns.slice(1).flatMap((col) => col.slides || []);

    return {
        slides: mainSlides,
        sides: sideSlides.slice(0, 2).map((slide) => ({
            kicker: slide.eyebrow || '',
            title: slide.title || '',
            subtitle: slide.body || '',
            cta_label: slide.cta_label || 'Shop Now',
            cta_url: slide.cta_url || '/catalog',
            image_url: slide.image_url || '',
        })),
    };
}

const EMPTY_SIDE = { kicker: '', title: '', subtitle: '', cta_label: 'Shop Now', cta_url: '/catalog', image_url: '' };
const EMPTY_PROMO = {
    kicker: 'Discount Available', title: '', subtitle: '', cta: 'Shop Now', url: '/catalog', image_url: '', bg: '#EEF1F6',
};
const EMPTY_TRUST = { icon: 'truck', title: '', text: '' };
const EMPTY_QUOTE = { name: '', role: 'Verified buyer', rating: 5, quote: '', avatar_url: '' };
const EMPTY_LINK = { label: '', url: '/' };
const EMPTY_COLUMN = { title: 'New column', type: 'links', links: [] };

function homeKey(code) {
    if (code === 'categories') {
        return 'shop_categories';
    }
    return code;
}

function moduleValue(settings, code) {
    const home = settings?.home || {};
    const key = homeKey(code);
    if (code === 'hero') {
        const layout = [1, 2, 3].includes(Number(home.hero?.layout)) ? Number(home.hero.layout) : 1;
        const bundled = defaultHeroLayoutColumns();
        const layoutColumns = { ...bundled, ...(home.hero?.layout_columns || {}) };
        // Normalize numeric + string keys from PHP/JSON.
        [1, 2, 3].forEach((id) => {
            const tree = layoutColumns[id] || layoutColumns[String(id)];
            if (Array.isArray(tree) && tree.length) {
                layoutColumns[id] = tree;
            } else if (!layoutColumns[id]) {
                layoutColumns[id] = bundled[id];
            }
        });
        let columns = Array.isArray(home.hero?.columns) ? home.hero.columns : [];
        if (!columns.length) {
            columns = layoutColumns[layout] || bundled[layout];
        }
        layoutColumns[layout] = columns;
        const legacy = syncLegacyHeroFields(columns);
        return {
            ...(home.hero || {}),
            layout,
            layout_columns: layoutColumns,
            columns,
            slides: legacy.slides,
            sides: legacy.sides,
            autoplay_ms: home.hero?.autoplay_ms ?? 5500,
        };
    }
    if (['trust', 'promos', 'promos_mid', 'promos_bottom', 'testimonials'].includes(code)) {
        return Array.isArray(home[key]) ? home[key] : [];
    }
    return home[key] || {};
}

export default function ClassicModule({ code, label, hint, settings, options = {} }) {
    const key = homeKey(code);
    const form = useForm({
        home: {
            [key]: moduleValue(settings, code),
        },
    });
    const value = form.data.home[key];

    const setValue = (next) => {
        form.setData('home', { [key]: next });
    };

    const patch = (partial) => setValue({ ...value, ...partial });

    return (
        <Layout>
            <PageHeader
                title={code === 'hero' ? t('admin.theme_settings.hero_banner', 'Hero Banner') : label}
                description={code === 'hero' ? t('admin.theme_settings.hero_row_hint', 'Pick layout 1 / 2 / 3 and configure it.') : undefined}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/themes', label: t('admin.nav.themes', 'Themes') },
                    { href: '/admin/themes/settings', label: t('admin.theme_settings.title', 'Theme settings') },
                    { label: code === 'hero' ? t('admin.theme_settings.hero_banner', 'Hero Banner') : label },
                ]}
                actions={(
                    <Button href="/admin/themes/settings" variant="secondary">
                        {t('admin.theme_settings.back_layout', 'Back to layout')}
                    </Button>
                )}
            />
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(`/admin/themes/modules/${code}`);
                }}
            >
                {hint && code !== 'hero' ? <Alert>{hint}</Alert> : null}
                <FormErrors errors={form.errors} />

                {code === 'hero' ? <HeroFields value={value} setValue={setValue} /> : null}
                {code === 'trust' ? <ListFields items={value} setItems={setValue} empty={EMPTY_TRUST} max={8} render={TrustRow} addLabel={t('admin.theme_settings.add_item', 'Add item')} inlineRemove /> : null}
                {['promos', 'promos_mid', 'promos_bottom'].includes(code) ? (
                    <ListFields
                        items={value}
                        setItems={setValue}
                        empty={EMPTY_PROMO}
                        max={4}
                        render={({ item, onChange }) => (
                            <PromoRow item={item} onChange={onChange} withBg={code === 'promos'} />
                        )}
                        addLabel={t('admin.theme_settings.add_banner', 'Add banner')}
                    />
                ) : null}
                {code === 'testimonials' ? <ListFields items={value} setItems={setValue} empty={EMPTY_QUOTE} max={8} render={QuoteRow} addLabel={t('admin.theme_settings.add_review', 'Add review')} /> : null}
                {code === 'featured' ? <ProductListFields value={value} patch={patch} categories={options.categories} withCategory /> : null}
                {code === 'new_arrivals' || code === 'bestsellers' ? <ProductListFields value={value} patch={patch} /> : null}
                {code === 'categories' ? <IdPickerFields value={value} patch={patch} options={options.categories} idsKey="category_ids" withTitle withLimit maxLimit={20} /> : null}
                {code === 'category_shelves' ? (
                    <IdPickerFields
                        value={value}
                        patch={patch}
                        options={options.categories}
                        idsKey="category_ids"
                        pickerLayout="twoCol"
                        withShowViewAll
                        footer={(
                            <ThemeImageField
                                code={code}
                                label={t('admin.theme_settings.catalog_banner', 'Catalog banner')}
                                value={value.banner_image_url || ''}
                                onChange={(banner_image_url) => patch({ banner_image_url })}
                                removeLabel={t('admin.theme_settings.remove_banner', 'Remove banner')}
                                embedded
                            />
                        )}
                    />
                ) : null}
                {code === 'brands' ? <IdPickerFields value={value} patch={patch} options={options.brands} idsKey="brand_ids" withTitle withLimit maxLimit={16} /> : null}
                {code === 'banner' ? <BannerFields value={value} patch={patch} /> : null}
                {code === 'content' ? <ContentFields value={value} patch={patch} /> : null}
                {code === 'newsletter' ? <NewsletterFields value={value} patch={patch} /> : null}
                {code === 'header' ? <IdPickerFields value={value} patch={patch} options={options.categories} idsKey="category_ids" emptyHint={t('admin.theme_settings.all_categories', 'Leave empty to show every root category.')} /> : null}
                {code === 'footer' ? (
                    <FooterFields
                        value={value}
                        setValue={setValue}
                        settings={settings}
                        shop={options.shop || {}}
                        categories={options.categories || []}
                    />
                ) : null}

                <div className="flex flex-wrap gap-2 pb-8">
                    <Button type="submit" icon="check" busy={form.processing}>
                        {t('admin.theme_settings.save_module', 'Save module')}
                    </Button>
                </div>
            </form>
        </Layout>
    );
}

function ListFields({ items, setItems, empty, max, render: Row, addLabel, inlineRemove = false }) {
    const rows = Array.isArray(items) ? items : [];
    const setRow = (index, next) => setItems(rows.map((row, i) => (i === index ? next : row)));
    const removeRow = (index) => setItems(rows.filter((_, i) => i !== index));
    return (
        <Card className="space-y-4">
            {rows.map((item, index) => (
                <div key={index} className="space-y-2 rounded-lg border border-slate-100 p-3">
                    <Row
                        item={item}
                        index={index}
                        onChange={(next) => setRow(index, next)}
                        onRemove={inlineRemove ? () => removeRow(index) : undefined}
                    />
                    {inlineRemove ? null : (
                        <Button type="button" variant="ghost" onClick={() => removeRow(index)}>
                            {t('admin.common.remove', 'Remove')}
                        </Button>
                    )}
                </div>
            ))}
            {rows.length < max ? (
                <Button type="button" variant="secondary" onClick={() => setItems([...rows, { ...empty }])}>
                    {addLabel}
                </Button>
            ) : null}
        </Card>
    );
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function legacySlideHtml(slide, read) {
    const eyebrow = read(slide?.eyebrow);
    const title = read(slide?.title);
    const body = read(slide?.body);
    const label = read(slide?.cta_label);
    const url = String(slide?.cta_url || '').trim();
    const parts = [];
    if (eyebrow) {
        parts.push(`<p><strong>${escapeHtml(eyebrow)}</strong></p>`);
    }
    if (title) {
        parts.push(`<h2>${escapeHtml(title).replace(/\n/g, '<br>')}</h2>`);
    }
    if (body) {
        parts.push(`<p>${escapeHtml(body).replace(/\n/g, '<br>')}</p>`);
    }
    if (label) {
        const href = url || '/catalog';
        parts.push(`<p><a href="${escapeHtml(href)}">${escapeHtml(label)}</a></p>`);
    }

    return parts.join('');
}

function slideEditorHtml(slide, read) {
    const saved = read(slide?.content).trim();
    if (saved) {
        return saved;
    }

    return legacySlideHtml(slide, read);
}

function asLocalizedMap(value, defaultLanguage, fieldDefault = '') {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return { ...value };
    }
    if (typeof value === 'string' && value.trim() !== '') {
        return { [defaultLanguage]: value };
    }
    if (typeof fieldDefault === 'string' && fieldDefault.trim() !== '') {
        return { [defaultLanguage]: fieldDefault };
    }

    return { [defaultLanguage]: '' };
}

/** One language switch for a card. Shopper-facing text is stored as { en, fr, … }. */
function useContentLanguage() {
    const { i18n } = usePage().props;
    const languages = Array.isArray(i18n?.languages) && i18n.languages.length
        ? i18n.languages
        : [{ code: i18n?.default || 'en', name: i18n?.default_name || 'English' }];
    const defaultLanguage = languages.some((row) => row.code === i18n?.default)
        ? i18n.default
        : (languages[0]?.code || 'en');
    const [language, setLanguage] = useState(defaultLanguage);
    const activeLanguage = languages.some((row) => row.code === language) ? language : defaultLanguage;
    const read = (value) => {
        const map = asLocalizedMap(value, defaultLanguage);
        return typeof map[activeLanguage] === 'string' ? map[activeLanguage] : '';
    };
    const write = (value, text) => ({
        ...asLocalizedMap(value, defaultLanguage),
        [activeLanguage]: text,
    });

    return { languages, activeLanguage, setLanguage, read, write };
}

function LangField({ label, value, onChange, languages, language, setLanguage, rows = 0, placeholder = '' }) {
    return (
        <LocalizedField
            label={label}
            languages={languages}
            currentLanguage={language}
            onLanguageChange={setLanguage}
        >
            {rows > 0 ? (
                <Textarea rows={rows} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
            ) : (
                <Input wide value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
            )}
        </LocalizedField>
    );
}

function plainSlideText(html) {
    return String(html || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim();
}

function HeroFields({ value, setValue }) {
    const lang = useContentLanguage();
    const [activeCol, setActiveCol] = useState(0);
    const [activeSlide, setActiveSlide] = useState(0);
    const layout = [1, 2, 3].includes(Number(value.layout)) ? Number(value.layout) : 1;
    const columns = value.columns || [];
    const needed = layout;
    const bundled = defaultHeroLayoutColumns();

    const columnsForLayout = (nextLayout, presets) => {
        const saved = presets?.[nextLayout] || presets?.[String(nextLayout)];
        if (Array.isArray(saved) && saved.length) {
            return saved.slice(0, nextLayout).map((col, index) => ({
                ...col,
                width_hint: index === 0 ? (nextLayout === 1 ? 'full' : 'main') : 'side',
                slides: (col.slides?.length ? col.slides : [{ ...EMPTY_SLIDE, image_url: HERO_PLACEHOLDER }]).slice(0, 8),
            }));
        }

        return (bundled[nextLayout] || bundled[1]).map((col) => ({
            ...col,
            slides: (col.slides || []).map((slide) => ({ ...EMPTY_SLIDE, ...slide })),
        }));
    };

    const commitColumns = (nextColumns, nextLayout = layout, presets = null) => {
        const layoutColumns = { ...(presets || value.layout_columns || bundled) };
        layoutColumns[nextLayout] = nextColumns;
        const legacy = syncLegacyHeroFields(nextColumns);
        setValue({
            ...value,
            layout: nextLayout,
            layout_columns: layoutColumns,
            columns: nextColumns,
            ...legacy,
        });
    };

    const setLayout = (nextLayout) => {
        const layoutColumns = { ...(value.layout_columns || bundled) };
        // Persist the layout we are leaving so its uploads are never overwritten.
        layoutColumns[layout] = columns.length ? columns : columnsForLayout(layout, layoutColumns);
        const nextColumns = columnsForLayout(nextLayout, layoutColumns);
        layoutColumns[nextLayout] = nextColumns;
        commitColumns(nextColumns, nextLayout, layoutColumns);
    };

    const setColumnSlide = (colIndex, slideIndex, partial) => {
        const nextColumns = columns.map((col, i) => {
            if (i !== colIndex) {
                return col;
            }
            return {
                ...col,
                slides: (col.slides || []).map((row, j) => (j === slideIndex ? { ...row, ...partial } : row)),
            };
        });
        commitColumns(nextColumns);
    };

    const displayColumns = (columns.length ? columns : columnsForLayout(layout, value.layout_columns)).slice(0, needed);

    const removeColumnSlide = (colIndex, slideIndex) => {
        const nextColumns = displayColumns.map((col, i) => {
            if (i !== colIndex) {
                return col;
            }
            return { ...col, slides: (col.slides || []).filter((_, j) => j !== slideIndex) };
        });
        commitColumns(nextColumns);
    };

    const colIndex = displayColumns.length ? Math.min(activeCol, displayColumns.length - 1) : 0;
    const column = displayColumns[colIndex] || null;
    const slides = column?.slides || [];
    const slideIndex = slides.length ? Math.min(activeSlide, slides.length - 1) : 0;
    const slide = slides[slideIndex] || null;
    const imageHint = layout === 1
        ? t('admin.theme_settings.hero_banner1_image_hint', 'Recommended size: 1920×640 px. Upload a full-width image — it fills the whole banner and slide text sits on top (leave open space on the left for copy).')
        : layout === 2
            ? t('admin.theme_settings.hero_banner2_image_hint', 'Recommended size: 960×500 px (aspect 48:25 ≈ 1.92:1, nearly 2:1). Both columns use the same frame — leave open space on the left for text.')
            : layout === 3 && colIndex === 0
                ? t('admin.theme_settings.hero_main_image_hint', 'Recommended size: 1200×520 px (aspect 30:13 ≈ 2.3:1). Main banner — leave open space on the left for text.')
                : layout === 3
                    ? t('admin.theme_settings.hero_side_image_hint', 'Recommended size: 700×250 px (aspect 14:5 = 2.8:1). Both side banners use this same frame — leave open space on the left for text.')
                    : t('admin.theme_settings.hero_image_hint', 'Recommended size: 1200×520 px. Full-bleed art with room on the left for the headline overlay.');

    const selectColumn = (index) => {
        setActiveCol(index);
        setActiveSlide(0);
    };

    return (
        <div className="space-y-4">
            <Card padded={false} className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
                    <div className="text-sm font-semibold text-neutral-900">{t('admin.theme_settings.footer_preview', 'Storefront preview')}</div>
                    <Select
                        value={lang.activeLanguage}
                        onChange={(e) => lang.setLanguage(e.target.value)}
                        className={ui.localizedFieldSelect}
                        aria-label={t('admin.theme_settings.editing_language', 'Language')}
                    >
                        {lang.languages.map((row) => (
                            <option key={row.code} value={row.code}>{row.code.toUpperCase()}</option>
                        ))}
                    </Select>
                </div>
                <div className="bg-neutral-50 px-4 py-4 sm:px-6">
                    <HeroPreview
                        layout={layout}
                        columns={displayColumns}
                        read={lang.read}
                        activeCol={colIndex}
                        activeSlide={slideIndex}
                        onSelect={selectColumn}
                    />
                </div>
            </Card>

            <div className="grid gap-3 lg:grid-cols-[1fr_180px]">
                <div className="grid gap-2 sm:grid-cols-3">
                    {[
                        [1, t('admin.theme_settings.hero_layout_1', 'Full width')],
                        [2, t('admin.theme_settings.hero_layout_2', 'Two banners')],
                        [3, t('admin.theme_settings.hero_layout_3', 'Main and two sides')],
                    ].map(([id, label]) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => { setLayout(id); selectColumn(0); }}
                            className={cx(
                                'rounded-xl border px-3 py-3 text-left',
                                layout === id ? 'border-violet-600 bg-violet-50' : 'border-neutral-200 bg-white hover:border-violet-300',
                            )}
                        >
                            <HeroLayoutMark layout={id} />
                            <span className="mt-2 block text-sm font-semibold text-neutral-900">{id} · {label}</span>
                        </button>
                    ))}
                </div>
                <Field label={t('admin.theme_settings.autoplay', 'Autoplay (ms)')}>
                    <Input wide type="number" min="0" max="20000" value={value.autoplay_ms ?? 5500} onChange={(e) => setValue({ ...value, autoplay_ms: Number(e.target.value) })} />
                </Field>
            </div>

            {slide ? (
                <Card className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="text-sm font-semibold text-neutral-900">
                            {t('admin.theme_settings.hero_column', 'Column')} {colIndex + 1}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {slides.map((row, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    onClick={() => setActiveSlide(index)}
                                    className={cx(
                                        'rounded-lg border px-3 py-1.5 text-xs font-medium',
                                        index === slideIndex ? 'border-violet-600 bg-violet-50 text-violet-700' : 'border-neutral-200 bg-white text-neutral-600',
                                    )}
                                >
                                    {t('admin.theme_settings.slide_n', 'Slide')} {index + 1}
                                </button>
                            ))}
                            {slides.length < 8 ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon="plus"
                                    onClick={() => {
                                        const nextColumns = displayColumns.map((col, i) => (
                                            i === colIndex
                                                ? { ...col, slides: [...(col.slides || []), { ...EMPTY_SLIDE, image_url: HERO_PLACEHOLDER }] }
                                                : col
                                        ));
                                        commitColumns(nextColumns);
                                        setActiveSlide(slides.length);
                                    }}
                                >
                                    {t('admin.theme_settings.add_slide', 'Add slide')}
                                </Button>
                            ) : null}
                            {slides.length > 1 ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="text-red-600"
                                    onClick={() => {
                                        removeColumnSlide(colIndex, slideIndex);
                                        setActiveSlide(Math.max(0, slideIndex - 1));
                                    }}
                                >
                                    {t('admin.theme_settings.remove_slide', 'Remove slide')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                    <Field
                        label={t('admin.theme_settings.slide_content', 'Slide content')}
                        hint={t('admin.theme_settings.hero_content_hint', 'Formatted text shown on the banner. Put a link on its own line to turn it into the button.')}
                    >
                        <Editor
                            className="hero-slide-editor"
                            value={slideEditorHtml(slide, lang.read)}
                            onChange={(html) => setColumnSlide(colIndex, slideIndex, { content: lang.write(slide.content, html) })}
                        />
                    </Field>
                    <ThemeImageField
                        code="hero"
                        label={t('admin.theme_settings.image', 'Image')}
                        hint={imageHint}
                        value={slide.image_url || ''}
                        onChange={(image_url) => setColumnSlide(colIndex, slideIndex, { image_url })}
                        removeLabel={t('admin.theme_settings.remove_image', 'Remove image')}
                        embedded
                    />
                </Card>
            ) : null}
        </div>
    );
}

function HeroLayoutMark({ layout }) {
    const cell = 'rounded-md bg-violet-200';
    if (layout === 1) {
        return <span className={cx(cell, 'block h-8 w-full')} />;
    }
    if (layout === 2) {
        return (
            <span className="grid h-8 grid-cols-2 gap-1">
                <span className={cell} />
                <span className={cell} />
            </span>
        );
    }

    return (
        <span className="grid h-8 grid-cols-[1.6fr_1fr] grid-rows-2 gap-1">
            <span className={cx(cell, 'row-span-2')} />
            <span className={cell} />
            <span className={cell} />
        </span>
    );
}

function HeroPreview({ layout, columns, read, activeCol, activeSlide, onSelect }) {
    const grid = layout === 1
        ? 'grid-cols-1'
        : layout === 2
            ? 'grid-cols-2'
            : 'grid-cols-[1.6fr_1fr] grid-rows-2';

    return (
        <div
            className={cx('grid gap-2', grid)}
            style={layout === 1 ? { aspectRatio: '1920 / 640' } : layout === 2 ? { aspectRatio: '1920 / 500' } : { minHeight: 260, aspectRatio: '1920 / 520' }}
        >
            {columns.map((column, index) => {
                const slides = column.slides || [];
                const current = slides[index === activeCol ? Math.min(activeSlide, Math.max(slides.length - 1, 0)) : 0] || null;
                const text = plainSlideText(slideEditorHtml(current, read));
                const image = catalogImageUrl(current?.image_url || HERO_PLACEHOLDER);

                return (
                    <button
                        key={index}
                        type="button"
                        onClick={() => onSelect(index)}
                        className={cx(
                            'relative min-h-24 overflow-hidden rounded-xl bg-neutral-200 text-left',
                            layout === 3 && index === 0 ? 'row-span-2' : '',
                            index === activeCol ? 'ring-2 ring-violet-600' : 'hover:ring-2 hover:ring-violet-300',
                        )}
                    >
                        {image ? <img src={image} alt="" className="absolute inset-0 h-full w-full object-cover object-[72%_center]" /> : null}
                        <span className="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent" />
                        <span className="relative flex h-full flex-col justify-end p-3">
                            <span className="line-clamp-3 text-xs font-semibold text-white">{text || `${t('admin.theme_settings.hero_column', 'Column')} ${index + 1}`}</span>
                            {slides.length > 1 ? (
                                <span className="mt-2 flex gap-1">
                                    {slides.map((_, dot) => (
                                        <span key={dot} className={cx('h-1.5 w-1.5 rounded-full', dot === (index === activeCol ? activeSlide : 0) ? 'bg-white' : 'bg-white/50')} />
                                    ))}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function TrustRow({ item, onChange, onRemove }) {
    const { i18n } = usePage().props;
    const languages = Array.isArray(i18n?.languages) && i18n.languages.length
        ? i18n.languages
        : [{ code: i18n?.default || 'en', name: i18n?.default_name || 'English' }];
    const defaultLanguage = languages.some((row) => row.code === i18n?.default)
        ? i18n.default
        : (languages[0]?.code || 'en');
    const [language, setLanguage] = useState(defaultLanguage);
    const activeLanguage = languages.some((row) => row.code === language) ? language : defaultLanguage;
    const titleMap = asLocalizedMap(item.title, defaultLanguage);
    const textMap = asLocalizedMap(item.text, defaultLanguage);
    const titleValue = typeof titleMap[activeLanguage] === 'string' ? titleMap[activeLanguage] : '';
    const textValue = typeof textMap[activeLanguage] === 'string' ? textMap[activeLanguage] : '';

    return (
        <div className="grid items-end gap-2 sm:grid-cols-[10.5rem_minmax(0,1fr)_minmax(0,1.15fr)_auto]">
            <Field label={t('admin.theme_settings.trust_icon', 'Icon')}>
                <Select wide value={item.icon || 'truck'} onChange={(e) => onChange({ ...item, icon: e.target.value })}>
                    <option value="truck">{t('admin.theme_settings.trust_icon_truck', 'Truck')}</option>
                    <option value="return">{t('admin.theme_settings.trust_icon_return', 'Return')}</option>
                    <option value="shield">{t('admin.theme_settings.trust_icon_shield', 'Shield')}</option>
                    <option value="support">{t('admin.theme_settings.trust_icon_support', 'Support')}</option>
                    <option value="tag">{t('admin.theme_settings.trust_icon_tag', 'Tag')}</option>
                </Select>
            </Field>
            <LocalizedField
                label={t('admin.theme_settings.title_field', 'Title')}
                languages={languages}
                currentLanguage={activeLanguage}
                onLanguageChange={setLanguage}
            >
                <Input
                    wide
                    value={titleValue}
                    onChange={(e) => onChange({
                        ...item,
                        title: {
                            ...titleMap,
                            [activeLanguage]: e.target.value,
                        },
                    })}
                />
            </LocalizedField>
            <LocalizedField
                label={t('admin.theme_settings.trust_text', 'Text')}
                languages={languages}
                currentLanguage={activeLanguage}
                onLanguageChange={setLanguage}
            >
                <Input
                    wide
                    value={textValue}
                    onChange={(e) => onChange({
                        ...item,
                        text: {
                            ...textMap,
                            [activeLanguage]: e.target.value,
                        },
                    })}
                />
            </LocalizedField>
            <Button
                type="button"
                variant="ghost"
                icon="trash"
                className="mb-0.5 shrink-0 justify-self-end"
                aria-label={t('admin.theme_settings.remove_item', 'Remove item')}
                title={t('admin.theme_settings.remove_item', 'Remove item')}
                onClick={onRemove}
            />
        </div>
    );
}

function PromoRow({ item, onChange, withBg }) {
    const lang = useContentLanguage();
    const text = (key, label) => (
        <LangField
            label={label}
            value={lang.read(item[key])}
            onChange={(next) => onChange({ ...item, [key]: lang.write(item[key], next) })}
            languages={lang.languages}
            language={lang.activeLanguage}
            setLanguage={lang.setLanguage}
        />
    );

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {text('title', t('admin.theme_settings.title_field', 'Title'))}
            {text('kicker', t('admin.theme_settings.kicker', 'Kicker'))}
            {text('subtitle', t('admin.theme_settings.subtitle', 'Subtitle'))}
            {text('cta', t('admin.theme_settings.cta', 'Button label'))}
            <Field label={t('admin.theme_settings.url', 'URL')}>
                <Input wide value={item.url || ''} onChange={(e) => onChange({ ...item, url: e.target.value })} />
            </Field>
            <Field label={t('admin.theme_settings.image_url', 'Image URL')}>
                <Input wide value={item.image_url || ''} onChange={(e) => onChange({ ...item, image_url: e.target.value })} />
            </Field>
            {withBg ? (
                <Field label={t('admin.theme_settings.background', 'Background')}>
                    <ColorField
                        value={item.bg}
                        onChange={(bg) => onChange({ ...item, bg })}
                        placeholder="#EEF1F6"
                        fallback="#EEF1F6"
                    />
                </Field>
            ) : null}
        </div>
    );
}

function QuoteRow({ item, onChange }) {
    const lang = useContentLanguage();
    const text = (key, label, rows = 0) => (
        <LangField
            label={label}
            rows={rows}
            value={lang.read(item[key])}
            onChange={(next) => onChange({ ...item, [key]: lang.write(item[key], next) })}
            languages={lang.languages}
            language={lang.activeLanguage}
            setLanguage={lang.setLanguage}
        />
    );

    return (
        <>
            <div className="grid gap-2 sm:grid-cols-3">
                {text('name', t('admin.theme_settings.review_name', 'Name'))}
                {text('role', t('admin.theme_settings.review_role', 'Role'))}
                <Field label={t('admin.theme_settings.review_rating', 'Rating')}>
                    <Select wide value={String(item.rating || 5)} onChange={(e) => onChange({ ...item, rating: Number(e.target.value) })}>
                        {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>
                </Field>
            </div>
            {text('quote', t('admin.theme_settings.review_quote', 'Quote'), 2)}
            <ThemeImageField
                code="testimonials"
                label={t('admin.theme_settings.review_avatar', 'Profile photo')}
                hint={t('admin.theme_settings.review_avatar_hint', 'Optional. Storefront shows a default avatar when empty.')}
                value={item.avatar_url || ''}
                onChange={(avatar_url) => onChange({ ...item, avatar_url })}
                compact
            />
        </>
    );
}

function ProductListFields({ value, patch, categories = [], withCategory = false }) {
    const lang = useContentLanguage();

    return (
        <Card className="space-y-3">
            <LangField
                label={t('admin.theme_settings.title_field', 'Title')}
                value={lang.read(value.title)}
                onChange={(next) => patch({ title: lang.write(value.title, next) })}
                languages={lang.languages}
                language={lang.activeLanguage}
                setLanguage={lang.setLanguage}
            />
            <Field label={t('admin.theme_settings.limit', 'How many products')}>
                <Select wide value={String(value.limit || 12)} onChange={(e) => patch({ limit: Number(e.target.value) })}>
                    {[4, 6, 8, 10, 12, 16, 24].map((n) => <option key={n} value={n}>{n}</option>)}
                </Select>
            </Field>
            {withCategory ? (
                <Field label={t('admin.theme_settings.category', 'Category')} hint={t('admin.theme_settings.any_category', 'Leave empty to use all products.')}>
                    <Select wide value={String(value.category_id || '')} onChange={(e) => patch({ category_id: e.target.value ? Number(e.target.value) : null })}>
                        <option value="">{t('admin.common.any', 'Any')}</option>
                        {categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}
                    </Select>
                </Field>
            ) : null}
        </Card>
    );
}

function IdPickerFields({ value, patch, options = [], idsKey, withTitle = false, withLimit = false, withColumns = false, withShowViewAll = false, maxLimit = 16, limitLabel, emptyHint, pickerLayout = 'grid', footer = null }) {
    const lang = useContentLanguage();
    const selected = Array.isArray(value[idsKey]) ? value[idsKey] : [];
    const toggle = (id, on) => {
        const next = on ? [...selected, id] : selected.filter((n) => n !== id);
        patch({ [idsKey]: next });
    };
    const isSelected = (id) => selected.includes(id) || selected.includes(Number(id)) || selected.includes(String(id));
    let pickList;
    if (pickerLayout === 'twoCol') {
        const twoColHeaderCell = (
            <div className={ui.idPickerTwoColHeaderCell}>
                <span className={ui.idPickerTwoColHeaderSelect}>
                    {t('admin.theme_settings.pick_select', 'Select')}
                </span>
                <span className={ui.idPickerTwoColHeaderName}>
                    {t('admin.theme_settings.pick_category_name', 'Category Name')}
                </span>
                <span className={ui.idPickerTwoColHeaderCount}>
                    {t('admin.theme_settings.pick_product_count', 'Product Count')}
                </span>
            </div>
        );
        pickList = (
            <div className={ui.idPickerTwoColWrap} data-testid="id-picker-two-col">
                <div className={ui.idPickerTwoColHeader} data-testid="id-picker-two-col-header">
                    {twoColHeaderCell}
                    <div className="hidden sm:contents">{twoColHeaderCell}</div>
                </div>
                <div className={ui.idPickerTwoColBody}>
                    {options.length === 0 ? (
                        <p className="col-span-full px-1 py-2 text-sm text-neutral-500">
                            {t('admin.theme_settings.pick_empty', 'No categories available.')}
                        </p>
                    ) : options.map((opt) => {
                        const checked = isSelected(opt.id);
                        const productsCount = Number(opt.products_count);
                        return (
                            <div
                                key={opt.id}
                                className={`${ui.idPickerTwoColItem}${checked ? ` ${ui.idPickerTwoColItemSelected}` : ''}`}
                            >
                                <Checkbox
                                    className="min-w-0 flex-1"
                                    label={<span className={ui.idPickerTwoColName}>{opt.name}</span>}
                                    checked={checked}
                                    onChange={(e) => toggle(opt.id, e.target.checked)}
                                />
                                <span className={ui.idPickerTwoColCount} title={t('admin.theme_settings.pick_product_count', 'Product Count')}>
                                    {Number.isFinite(productsCount) ? productsCount : 0}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    } else if (pickerLayout === 'table') {
        pickList = (
            <div className={ui.idPickerTableWrap} data-testid="id-picker-table">
                <Table className="w-full min-w-0">
                    <THead>
                        <Tr className="!border-0 hover:!bg-transparent">
                            <Th check className={ui.idPickerThSticky}>
                                {t('admin.theme_settings.pick_select', 'Select')}
                            </Th>
                            <Th className={ui.idPickerThSticky}>
                                {t('admin.theme_settings.pick_category_name', 'Category Name')}
                            </Th>
                            <Th className={`${ui.idPickerThSticky} ${ui.idPickerCount}`}>
                                {t('admin.theme_settings.pick_product_count', 'Product Count')}
                            </Th>
                        </Tr>
                    </THead>
                    <tbody>
                        {options.length === 0 ? (
                            <Tr>
                                <Td colSpan={3} className="px-3 py-4 text-sm text-neutral-500">
                                    {t('admin.theme_settings.pick_empty', 'No categories available.')}
                                </Td>
                            </Tr>
                        ) : options.map((opt) => {
                            const checked = isSelected(opt.id);
                            const inputId = `id-picker-${idsKey}-${opt.id}`;
                            const productsCount = Number(opt.products_count);
                            return (
                                <Tr key={opt.id} selected={checked}>
                                    <Td check>
                                        <Checkbox
                                            id={inputId}
                                            checked={checked}
                                            onChange={(e) => toggle(opt.id, e.target.checked)}
                                            aria-label={t('admin.theme_settings.pick_select_item', 'Select {name}', { name: opt.name })}
                                        />
                                    </Td>
                                    <Td>
                                        <label htmlFor={inputId} className="block cursor-pointer text-sm text-neutral-800">
                                            {opt.name}
                                        </label>
                                    </Td>
                                    <Td className={ui.idPickerCount}>
                                        {Number.isFinite(productsCount) ? productsCount : 0}
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            </div>
        );
    } else {
        pickList = (
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((opt) => (
                    <Checkbox
                        key={opt.id}
                        label={opt.name}
                        checked={isSelected(opt.id)}
                        onChange={(e) => toggle(opt.id, e.target.checked)}
                    />
                ))}
            </div>
        );
    }
    return (
        <Card className="space-y-3">
            {withTitle ? (
                <LangField
                    label={t('admin.theme_settings.title_field', 'Title')}
                    value={lang.read(value.title)}
                    onChange={(next) => patch({ title: lang.write(value.title, next) })}
                    languages={lang.languages}
                    language={lang.activeLanguage}
                    setLanguage={lang.setLanguage}
                />
            ) : null}
            {withLimit ? (
                <Field label={limitLabel || t('admin.theme_settings.limit', 'How many')}>
                    <Select wide value={String(value.limit || 8)} onChange={(e) => patch({ limit: Number(e.target.value) })}>
                        {[3, 4, 6, 8, 10, 12, 16].filter((n) => n <= maxLimit).map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>
                </Field>
            ) : null}
            {withColumns ? (
                <Field label={t('admin.theme_settings.products_per_row', 'Products per row')} hint={t('admin.theme_settings.products_per_row_hint', 'Controls the desktop catalog layout.')}>
                    <Select wide value={String(value.columns || 4)} onChange={(e) => patch({ columns: Number(e.target.value) })}>
                        {[2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>
                </Field>
            ) : null}
            {withShowViewAll ? (
                <Toggle
                    label={t('admin.theme_settings.show_view_all', 'Show View All button')}
                    hint={t('admin.theme_settings.show_view_all_hint', 'When off, View All is hidden on every category products section. When on, it still only appears if the category has more products than the shelf shows.')}
                    value={value.show_view_all !== false}
                    onChange={(on) => patch({ show_view_all: on })}
                />
            ) : null}
            <Field label={t('admin.theme_settings.pick_items', 'Show these')} hint={emptyHint || t('admin.theme_settings.pick_hint', 'Leave empty to use the default list.')}>
                {pickList}
            </Field>
            {footer}
        </Card>
    );
}

function ThemeImageField({
    code, label, value, onChange, hint, compact = false, embedded = false,
    removeLabel = '',
}) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const [localPreview, setLocalPreview] = useState('');
    const [previewBroken, setPreviewBroken] = useState(false);
    const removeText = removeLabel || t('admin.theme_settings.remove_banner', 'Remove banner');

    useEffect(() => {
        setPreviewBroken(false);
    }, [value]);

    useEffect(() => () => {
        if (localPreview) {
            URL.revokeObjectURL(localPreview);
        }
    }, [localPreview]);

    const clearLocalPreview = () => {
        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return '';
        });
    };

    const previewSrc = localPreview || (value && !previewBroken ? catalogImageUrl(value) : '');
    const showPreviewRow = Boolean(previewSrc || value || localPreview);

    const upload = async (event) => {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return URL.createObjectURL(file);
        });
        setPreviewBroken(false);
        setUploading(true);
        setError('');
        try {
            const body = new FormData();
            body.append('image', file);
            const meta = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            const response = await fetch(adminUrl('/admin/themes/modules/' + code + '/media'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(meta ? { 'X-CSRF-TOKEN': meta } : {}),
                    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
                },
                credentials: 'same-origin',
                body,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                clearLocalPreview();
                setError(payload?.errors?.image?.[0] || payload?.message || t('admin.theme_settings.upload_failed', 'Image upload failed.'));
                return;
            }
            clearLocalPreview();
            onChange(payload.url || '');
        } catch (uploadError) {
            clearLocalPreview();
            setError(uploadError?.message || t('admin.theme_settings.upload_failed', 'Image upload failed.'));
        } finally {
            setUploading(false);
            event.target.value = '';
        }
    };

    const removeImage = () => {
        clearLocalPreview();
        setPreviewBroken(false);
        onChange('');
    };

    const field = (
        <Field
            label={label}
            hint={hint || t('admin.theme_settings.catalog_banner_hint', 'Upload the image shown to the left of the catalog products.')}
            error={error}
        >
            <div className="space-y-3">
                <Input type="file" accept="image/*" disabled={uploading} onChange={upload} />
                {uploading ? <span className={ui.muted}>{t('admin.theme_settings.uploading', 'Uploading…')}</span> : null}
                {showPreviewRow ? (
                    <div className="flex items-start gap-2">
                        {previewSrc ? (
                            <img
                                src={previewSrc}
                                alt=""
                                onError={() => {
                                    if (localPreview) {
                                        clearLocalPreview();
                                    } else {
                                        setPreviewBroken(true);
                                    }
                                }}
                                className={compact
                                    ? 'h-16 w-16 rounded-full border border-slate-200 bg-slate-50 object-cover'
                                    : 'h-48 w-full max-w-sm rounded-xl border border-slate-200 bg-slate-50 object-contain'}
                            />
                        ) : null}
                        <Button
                            type="button"
                            variant="ghost"
                            icon="trash"
                            aria-label={removeText}
                            title={removeText}
                            onClick={removeImage}
                        />
                    </div>
                ) : null}
            </div>
        </Field>
    );

    if (compact || embedded) {
        return field;
    }

    return (
        <Card className="space-y-3">
            {field}
        </Card>
    );
}

function BannerFields({ value, patch }) {
    const lang = useContentLanguage();
    const text = (key, label) => (
        <LangField
            label={label}
            value={lang.read(value[key])}
            onChange={(next) => patch({ [key]: lang.write(value[key], next) })}
            languages={lang.languages}
            language={lang.activeLanguage}
            setLanguage={lang.setLanguage}
        />
    );

    return (
        <Card className="space-y-3">
            {text('title', t('admin.theme_settings.title_field', 'Title'))}
            {text('kicker', t('admin.theme_settings.kicker', 'Kicker'))}
            {text('subtitle', t('admin.theme_settings.subtitle', 'Subtitle'))}
            {text('cta', t('admin.theme_settings.cta', 'Button label'))}
            <Field label={t('admin.theme_settings.url', 'URL')}>
                <Input wide value={value.url || ''} onChange={(e) => patch({ url: e.target.value })} />
            </Field>
            <Field label={t('admin.theme_settings.image_url', 'Image URL')}>
                <Input wide value={value.image_url || ''} onChange={(e) => patch({ image_url: e.target.value })} />
            </Field>
        </Card>
    );
}

function ContentFields({ value, patch }) {
    const lang = useContentLanguage();

    return (
        <Card className="space-y-3">
            <LangField
                label={t('admin.theme_settings.title_field', 'Title')}
                value={lang.read(value.title)}
                onChange={(next) => patch({ title: lang.write(value.title, next) })}
                languages={lang.languages}
                language={lang.activeLanguage}
                setLanguage={lang.setLanguage}
            />
            <LangField
                label={t('admin.theme_settings.html', 'HTML')}
                rows={10}
                value={lang.read(value.html)}
                onChange={(next) => patch({ html: lang.write(value.html, next) })}
                languages={lang.languages}
                language={lang.activeLanguage}
                setLanguage={lang.setLanguage}
            />
            <p className={ui.muted}>{t('admin.theme_settings.html_hint', 'Shown as the content banner on the home page.')}</p>
        </Card>
    );
}

function NewsletterFields({ value, patch }) {
    const lang = useContentLanguage();

    return (
        <Card className="space-y-3">
            <LangField
                label={t('admin.theme_settings.title_field', 'Title')}
                value={lang.read(value.title)}
                onChange={(next) => patch({ title: lang.write(value.title, next) })}
                languages={lang.languages}
                language={lang.activeLanguage}
                setLanguage={lang.setLanguage}
            />
            <LangField
                label={t('admin.theme_settings.body', 'Body')}
                rows={3}
                value={lang.read(value.body)}
                onChange={(next) => patch({ body: lang.write(value.body, next) })}
                languages={lang.languages}
                language={lang.activeLanguage}
                setLanguage={lang.setLanguage}
            />
            <p className={ui.muted}>
                {t('admin.theme_settings.newsletter_list_hint', 'Subscribers are stored in the shop. Open Marketing → Newsletter to see the list.')}
            </p>
            <Button href="/admin/newsletter" variant="secondary">
                {t('admin.nav.newsletter', 'Newsletter')}
            </Button>
        </Card>
    );
}

const FOOTER_COLUMN_TYPES = ['links', 'contact', 'categories'];

function footerTypeLabel(type) {
    if (type === 'contact') {
        return t('admin.theme_settings.type_contact', 'Contact');
    }
    if (type === 'categories') {
        return t('admin.theme_settings.type_categories', 'Categories');
    }

    return t('admin.theme_settings.type_links', 'Links');
}

function footerTypeHint(type) {
    if (type === 'contact') {
        return t('admin.theme_settings.footer_contact_short', 'Shop name, address, email, and phone from store settings.');
    }
    if (type === 'categories') {
        return t('admin.theme_settings.footer_categories_short', 'Pick categories. Each one already has its page URL.');
    }

    return t('admin.theme_settings.footer_links_short', 'Named pages in this column.');
}

function FooterFields({ value, setValue, settings = {}, shop = {}, categories = [] }) {
    const lang = useContentLanguage();
    const columns = Array.isArray(value.columns) ? value.columns : [];
    const [active, setActive] = useState(0);
    const index = columns.length ? Math.min(active, columns.length - 1) : 0;
    const column = columns[index] || null;
    const shopName = lang.read(settings.logo_text).trim() || shop.name || t('admin.theme_settings.shop', 'Shop');
    const logoUrl = catalogImageUrl(settings.logo_url || '/themes/classic/assets/merchant/ui/logo.png');
    const year = new Date().getFullYear();

    const setColumns = (next, focus = index) => {
        setValue({ ...value, columns: next });
        setActive(focus);
    };
    const setColumn = (next) => {
        setColumns(columns.map((col, i) => (i === index ? next : col)), index);
    };
    const setLinks = (links) => setColumn({ ...column, links });

    return (
        <div className="space-y-4">
            <Card padded={false} className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
                    <div className="text-sm font-semibold text-neutral-900">{t('admin.theme_settings.footer_preview', 'Storefront preview')}</div>
                    <Select
                        value={lang.activeLanguage}
                        onChange={(e) => lang.setLanguage(e.target.value)}
                        className={ui.localizedFieldSelect}
                        aria-label={t('admin.theme_settings.editing_language', 'Language')}
                    >
                        {lang.languages.map((row) => (
                            <option key={row.code} value={row.code}>{row.code.toUpperCase()}</option>
                        ))}
                    </Select>
                </div>
                <div className="bg-white px-4 py-5 sm:px-6">
                    {columns.length ? (
                        <div className="grid gap-6" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
                            {columns.map((col, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => setActive(i)}
                                    className={cx(
                                        'min-w-0 rounded-xl px-2 py-2 text-left',
                                        i === index ? 'ring-2 ring-violet-500' : 'hover:bg-neutral-50',
                                    )}
                                >
                                    <FooterPreviewColumn
                                        column={col}
                                        read={lang.read}
                                        shopName={shopName}
                                        logoUrl={logoUrl}
                                        shop={shop}
                                    />
                                </button>
                            ))}
                        </div>
                    ) : (
                        <p className={ui.muted}>{t('admin.theme_settings.footer_empty', 'Add the first column')}</p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2 border-t border-neutral-100 bg-neutral-50 px-4 py-3 text-xs text-neutral-500">
                    <span>© {year} {shopName}.</span>
                    <Input
                        value={lang.read(value.copyright)}
                        placeholder={t('admin.theme_settings.copyright_hint', 'Leave empty to use the shop name and year.')}
                        onChange={(e) => setValue({ ...value, copyright: lang.write(value.copyright, e.target.value) })}
                        className="h-9 min-w-[16rem] flex-1 bg-white"
                    />
                </div>
            </Card>

            <div className="flex flex-wrap gap-2">
                {columns.map((col, i) => {
                    const title = lang.read(col.title).trim() || t('admin.theme_settings.column_n', 'Column');

                    return (
                        <button
                            key={i}
                            type="button"
                            onClick={() => setActive(i)}
                            className={cx(
                                'rounded-xl border px-3 py-2 text-left',
                                i === index ? 'border-violet-600 bg-violet-50' : 'border-neutral-200 bg-white hover:border-violet-300',
                            )}
                        >
                            <span className="block text-sm font-semibold text-neutral-900">{title}</span>
                            <span className="block text-xs text-neutral-500">{footerTypeLabel(col.type)}</span>
                        </button>
                    );
                })}
                {columns.length < 8 ? (
                    <button
                        type="button"
                        onClick={() => setColumns([...columns, { ...EMPTY_COLUMN, title: lang.write('', ''), links: [] }], columns.length)}
                        className="inline-flex items-center gap-2 rounded-xl border border-dashed border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-600 hover:border-violet-400 hover:text-violet-700"
                    >
                        <Icon name="plus" className="h-4 w-4" />
                        {t('admin.theme_settings.add_column', 'Add column')}
                    </button>
                ) : null}
            </div>

            {column ? (
                <Card className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="text-sm font-semibold text-neutral-900">
                            {lang.read(column.title).trim() || t('admin.theme_settings.column_n', 'Column')}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="secondary" disabled={index === 0} onClick={() => {
                                const next = [...columns];
                                const [moved] = next.splice(index, 1);
                                next.splice(index - 1, 0, moved);
                                setColumns(next, index - 1);
                            }}
                            >
                                {t('admin.theme_settings.move_left', 'Move left')}
                            </Button>
                            <Button type="button" variant="secondary" disabled={index === columns.length - 1} onClick={() => {
                                const next = [...columns];
                                const [moved] = next.splice(index, 1);
                                next.splice(index + 1, 0, moved);
                                setColumns(next, index + 1);
                            }}
                            >
                                {t('admin.theme_settings.move_right', 'Move right')}
                            </Button>
                            <Button type="button" variant="ghost" className="text-red-600" onClick={() => {
                                const next = columns.filter((_, i) => i !== index);
                                setColumns(next, Math.max(0, index - 1));
                            }}
                            >
                                {t('admin.theme_settings.remove_column', 'Remove column')}
                            </Button>
                        </div>
                    </div>
                    <Field label={t('admin.theme_settings.column_title', 'Column title')}>
                        <Input
                            wide
                            value={lang.read(column.title)}
                            onChange={(e) => setColumn({ ...column, title: lang.write(column.title, e.target.value) })}
                        />
                    </Field>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {FOOTER_COLUMN_TYPES.map((id) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => setColumn({ ...column, type: id })}
                                className={cx(
                                    'rounded-xl border px-3 py-2.5 text-left',
                                    (column.type || 'links') === id
                                        ? 'border-violet-600 bg-violet-50'
                                        : 'border-neutral-200 bg-white hover:border-violet-300',
                                )}
                            >
                                <span className="block text-sm font-semibold text-neutral-900">{footerTypeLabel(id)}</span>
                                <span className="mt-0.5 block text-xs leading-5 text-neutral-500">{footerTypeHint(id)}</span>
                            </button>
                        ))}
                    </div>
                    {(column.type || 'links') === 'contact' ? null : column.type === 'categories' ? (
                        <FooterCategoryList
                            links={column.links || []}
                            categories={categories}
                            read={lang.read}
                            onAdd={(category) => {
                                const names = category.names && typeof category.names === 'object' && Object.keys(category.names).length
                                    ? { ...category.names }
                                    : lang.write('', category.name);
                                setLinks([...(column.links || []), { label: names, url: category.url || '/' }]);
                            }}
                            onRemove={(li) => setLinks((column.links || []).filter((_, i) => i !== li))}
                        />
                    ) : (
                        <div className="space-y-2">
                            {(column.links || []).map((link, li) => (
                                <div key={li} className="grid items-center gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                    <Input
                                        wide
                                        placeholder={t('admin.theme_settings.link_label', 'Link label')}
                                        value={lang.read(link.label)}
                                        onChange={(e) => {
                                            const links = (column.links || []).map((row, i) => (
                                                i === li ? { ...row, label: lang.write(link.label, e.target.value) } : row
                                            ));
                                            setLinks(links);
                                        }}
                                    />
                                    <Input
                                        wide
                                        placeholder="/url"
                                        value={link.url || ''}
                                        onChange={(e) => {
                                            const links = (column.links || []).map((row, i) => (
                                                i === li ? { ...row, url: e.target.value } : row
                                            ));
                                            setLinks(links);
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        aria-label={t('admin.common.remove', 'Remove')}
                                        onClick={() => setLinks((column.links || []).filter((_, i) => i !== li))}
                                    >
                                        <Icon name="trash" className="h-4 w-4" />
                                    </Button>
                                </div>
                            ))}
                            {(column.links || []).length === 0 ? (
                                <p className={ui.muted}>{t('admin.theme_settings.no_links', 'No links yet.')}</p>
                            ) : null}
                            {(column.links || []).length < 20 ? (
                                <Button type="button" variant="secondary" icon="plus" onClick={() => setLinks([...(column.links || []), { ...EMPTY_LINK }])}>
                                    {t('admin.theme_settings.add_link', 'Add link')}
                                </Button>
                            ) : null}
                        </div>
                    )}
                </Card>
            ) : (
                <Button type="button" variant="secondary" icon="plus" onClick={() => setColumns([{ ...EMPTY_COLUMN, title: lang.write('', ''), links: [] }], 0)}>
                    {t('admin.theme_settings.footer_empty', 'Add the first column')}
                </Button>
            )}
        </div>
    );
}

function categoryChoiceLabel(category, categories) {
    const parent = categories.find((row) => row.id === category.parent_id);
    if (parent?.name && parent.name !== category.name) {
        return `${parent.name} / ${category.name}`;
    }

    return category.name;
}

function FooterCategoryList({ links, categories, read, onAdd, onRemove }) {
    const used = new Set((links || []).map((link) => String(link.url || '')));
    const available = categories.filter((category) => category.url && !used.has(String(category.url)));

    return (
        <div className="space-y-2">
            {links.map((link, li) => (
                <div key={`${link.url}-${li}`} className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2">
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium text-neutral-900">{read(link.label) || link.url}</div>
                        <div className="truncate text-xs text-neutral-500">{link.url}</div>
                    </div>
                    <Button type="button" variant="ghost" aria-label={t('admin.common.remove', 'Remove')} onClick={() => onRemove(li)}>
                        <Icon name="trash" className="h-4 w-4" />
                    </Button>
                </div>
            ))}
            {links.length === 0 ? (
                <p className={ui.muted}>{t('admin.theme_settings.no_categories', 'No categories yet.')}</p>
            ) : null}
            {links.length < 20 && available.length ? (
                <Select
                    wide
                    value=""
                    onChange={(e) => {
                        const category = available.find((row) => String(row.id) === e.target.value);
                        if (category) {
                            onAdd(category);
                        }
                    }}
                >
                    <option value="">{t('admin.theme_settings.add_category', 'Add category')}</option>
                    {available.map((category) => (
                        <option key={category.id} value={category.id}>{categoryChoiceLabel(category, categories)}</option>
                    ))}
                </Select>
            ) : null}
        </div>
    );
}

function FooterPreviewColumn({ column, read, shopName, logoUrl, shop }) {
    const title = read(column.title).trim();
    if ((column.type || 'links') === 'contact') {
        const lines = [shop.address, shop.email, shop.phone, shop.business_number].filter((line) => String(line || '').trim() !== '');

        return (
            <div>
                <img src={logoUrl} alt="" className="mb-3 h-8 w-auto max-w-[140px] object-contain" />
                <div className="text-[11px] font-bold uppercase tracking-wider text-neutral-800">{title || shopName}</div>
                <div className="mt-2 space-y-1 text-xs leading-5 text-neutral-500">
                    {lines.length ? lines.map((line) => (
                        <div key={line} className="whitespace-pre-line">{line}</div>
                    )) : (
                        <div>{shopName}</div>
                    )}
                </div>
            </div>
        );
    }

    const labels = (column.links || [])
        .map((link) => read(link.label).trim())
        .filter(Boolean);

    return (
        <div>
            <div className="text-[11px] font-bold uppercase tracking-wider text-neutral-800">{title || footerTypeLabel(column.type)}</div>
            <div className="mt-2 space-y-1 text-xs leading-5 text-neutral-500">
                {labels.length ? labels.slice(0, 8).map((label, n) => <div key={`${label}-${n}`}>{label}</div>) : (
                    <div className="italic">
                        {column.type === 'categories'
                            ? t('admin.theme_settings.no_categories', 'No categories yet.')
                            : t('admin.theme_settings.no_links', 'No links yet.')}
                    </div>
                )}
            </div>
        </div>
    );
}

