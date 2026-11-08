import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Alert,
    Button,
    Card,
    FeatureTile,
    Field,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Table,
    THead,
    Th,
    Tr,
    SettingsSectionHead,
    Td,
    ui,
    t,
    cx,
} from '../../ui';
import { StorefrontClubTabs } from './clubTabs';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.images', 'Images') },
];

const typeMeta = {
    cart: { icon: 'bag', label: t('admin.images.type_cart', 'Cart') },
    home: { icon: 'home', label: t('admin.images.type_home', 'Home') },
    medium: { icon: 'photo', label: t('admin.images.type_medium', 'Medium') },
    large: { icon: 'photo', label: t('admin.images.type_large', 'Large') },
    category: { icon: 'layers', label: t('admin.images.type_category', 'Category') },
};

const tips = [
    t('admin.images.tip_square', 'Use square images for consistent display.'),
    t('admin.images.tip_small', 'Keep file sizes small for faster loading.'),
    t('admin.images.tip_webp', 'Use WebP format for better performance.'),
    t('admin.images.tip_product', 'Recommended product image size: 800x800px.'),
];

function typeLabel(id) {
    return typeMeta[id]?.label || id.charAt(0).toUpperCase() + id.slice(1);
}

function typeIcon(id) {
    return typeMeta[id]?.icon || 'photo';
}

export default function Images({ types, webp, gd }) {
    const form = useForm({
        webp: !!webp,
        types: (types || []).map((row) => ({
            id: row.id,
            max_width: row.max_width,
            max_height: row.max_height,
        })),
    });

    const setType = (index, key, value) => {
        const next = form.data.types.map((row, i) => (i === index ? { ...row, [key]: value } : row));
        form.setData('types', next);
    };

    useSaveOnChange(form, '/admin/images', {
        textKeys: ['types'],
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.images.title', 'Images')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.images.subtitle', 'Manage uploaded image sizes and thumbnail generation settings for your store.')}
            />
            <StorefrontClubTabs current="images" />

            <div className={ui.formLayoutSplit}>
                <div className="min-w-0 space-y-4">
                    <Card
                        as="form"
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                        }}
                    >
                        <SettingsSectionHead
                            icon="photo"
                            title={t('admin.images.sizes_title', 'Image sizes')}
                            hint={t('admin.images.sizes_hint', 'Set the maximum dimensions for images used throughout your store.')}
                        />

                        {!gd ? (
                            <Alert type="warning">
                                {t('admin.images.gd_missing', 'PHP GD is not available, so thumbnails cannot be generated on this server.')}
                            </Alert>
                        ) : null}

                        <FormErrors errors={form.errors} />

                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start">
                            <div className="min-w-0 flex-1">
                                <FeatureTile
                                    icon="photo"
                                    tone="bg-violet-100 text-violet-600"
                                    title={t('admin.images.webp', 'Generate WebP')}
                                    hint={t('admin.images.webp_hint', 'Falls back to JPEG when WebP is not supported.')}
                                    value={form.data.webp}
                                    onChange={(on) => form.setData('webp', on)}
                                />
                            </div>
                            <Alert type="info" className="lg:max-w-sm lg:shrink-0">
                                {t('admin.images.webp_note', 'WebP images are smaller and faster, improving your store performance.')}
                            </Alert>
                        </div>

                        <div className="overflow-x-auto rounded-xl border border-neutral-200">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('admin.images.col_type', 'Image type')}</Th>
                                        <Th>{t('admin.images.col_width', 'Max width (px)')}</Th>
                                        <Th>{t('admin.images.col_height', 'Max height (px)')}</Th>
                                    </Tr>
                                </THead>
                                <tbody>
                                    {form.data.types.map((row, index) => (
                                        <Tr key={row.id}>
                                            <Td>
                                                <div className="flex items-center gap-2.5">
                                                    <span className={ui.cellIcon}>
                                                        <Icon name={typeIcon(row.id)} className="h-4 w-4" />
                                                    </span>
                                                    <span className="font-medium text-neutral-800">{typeLabel(row.id)}</span>
                                                </div>
                                            </Td>
                                            <Td>
                                                <Field error={form.errors[`types.${index}.max_width`]}>
                                                    <Input
                                                        type="number"
                                                        min="16"
                                                        value={row.max_width}
                                                        onChange={(e) => setType(index, 'max_width', Number(e.target.value))}
                                                    />
                                                </Field>
                                            </Td>
                                            <Td>
                                                <Field error={form.errors[`types.${index}.max_height`]}>
                                                    <Input
                                                        type="number"
                                                        min="16"
                                                        value={row.max_height}
                                                        onChange={(e) => setType(index, 'max_height', Number(e.target.value))}
                                                    />
                                                </Field>
                                            </Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </Card>

                    <Card className="space-y-4">
                        <SettingsSectionHead
                            icon="refresh"
                            tone={ui.statIconInfo}
                            title={t('admin.images.regenerate', 'Regenerate thumbnails')}
                            hint={t('admin.images.regenerate_hint', 'Re-writes sized files for every product image. Use missing only to skip images that already have every type.')}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" icon="refresh" onClick={() => router.post('/admin/images/regenerate', { missing_only: 0 })}>
                                {t('admin.images.regenerate_all', 'Regenerate all')}
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => router.post('/admin/images/regenerate', { missing_only: 1 })}>
                                {t('admin.images.regenerate_missing', 'Missing only')}
                            </Button>
                        </div>
                    </Card>
                </div>

                <aside className={cx(ui.formSide, 'space-y-4')}>
                    <Card className="space-y-2 border-violet-100 bg-violet-50/40">
                        <div className="flex items-start gap-3">
                            <span className={ui.sectionHeadIcon}>
                                <Icon name="info" className="h-5 w-5" />
                            </span>
                            <div>
                                <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                    {t('admin.images.about_title', 'About image settings')}
                                </h3>
                                <p className={cx(ui.muted, 'mt-1')}>
                                    {t('admin.images.about_body', 'These settings control the maximum size for uploaded images. The system creates resized copies for different pages. Cover-required-before-publish lives under Product Settings.')}
                                </p>
                            </div>
                        </div>
                    </Card>
                    <Card className="space-y-3">
                        <Button type="button" variant="secondary" href="/admin/product-settings" className="w-full justify-center">
                            {t('admin.images.open_product_settings', 'Open Product Settings')}
                        </Button>
                    </Card>

                    <Card className="space-y-3 border-sky-100 bg-sky-50/40">
                        <div className="flex items-start gap-3">
                            <span className={cx(ui.sectionHeadIcon, 'bg-sky-100 text-sky-700')}>
                                <Icon name="lightbulb" className="h-5 w-5" />
                            </span>
                            <div>
                                <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-sky-900')}>
                                    {t('admin.images.tips_title', 'Tips for better images')}
                                </h3>
                            </div>
                        </div>
                        <ul className="space-y-2">
                            {tips.map((tip) => (
                                <li key={tip} className="flex items-start gap-2 text-sm text-neutral-700">
                                    <span className={ui.tipCheck}>
                                        <Icon name="check" className="h-3 w-3" />
                                    </span>
                                    <span>{tip}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card className="space-y-3 border-emerald-100 bg-emerald-50/40">
                        <div className="flex items-start gap-3">
                            <span className={ui.sectionHeadIconSuccess}>
                                <Icon name="star" className="h-5 w-5" />
                            </span>
                            <div>
                                <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-emerald-900')}>
                                    {t('admin.images.recommended_title', 'Recommended sizes')}
                                </h3>
                            </div>
                        </div>
                        <ul className="space-y-2 text-sm text-neutral-700">
                            {(types || []).map((row) => (
                                <li key={row.id} className="flex items-center justify-between gap-3">
                                    <span>{typeLabel(row.id)}</span>
                                    <span className="font-medium tabular-nums text-neutral-900">
                                        {row.max_width}x{row.max_height}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </aside>
            </div>
        </Layout>
    );
}
