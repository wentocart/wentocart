import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    ListSettings,
    RowActions,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    ui,
    t,
} from '../../ui';

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function CategoryEditor({ category, onCancel }) {
    const form = useForm({
        name: category?.name || '',
        slug: category?.slug || '',
        description: category?.description || '',
        is_enabled: category?.is_enabled ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            slug: data.slug || slugify(data.name),
            is_enabled: !!data.is_enabled,
        }));

        if (category) {
            form.put(`/admin/cms/categories/${category.id}`, {
                preserveScroll: true,
                onSuccess: () => onCancel?.(),
            });
        } else {
            form.post('/admin/cms/categories', {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    form.setData({ name: '', slug: '', description: '', is_enabled: true });
                    onCancel?.();
                },
            });
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            <FormErrors errors={form.errors} />
            <Field label={t('admin.cms.name', 'Name *')} error={form.errors.name}>
                <Input
                    wide
                    invalid={!!form.errors.name}
                    value={form.data.name}
                    onChange={(e) => {
                        const name = e.target.value;
                        if (!category && !form.data.slug) {
                            form.setData({ name, slug: slugify(name) });
                        } else {
                            form.setData('name', name);
                        }
                    }}
                    placeholder={t('admin.cms.e_g_legal', 'e.g. Legal')}
                />
            </Field>
            <Field label={t('admin.cms.slug', 'Slug *')} error={form.errors.slug}>
                <Input
                    wide
                    invalid={!!form.errors.slug}
                    value={form.data.slug}
                    onChange={(e) => form.setData('slug', slugify(e.target.value))}
                    placeholder={t('admin.cms.legal', 'legal')}
                />
            </Field>
            <Field label={t('admin.cms.description', 'Description')} error={form.errors.description} className="sm:col-span-2">
                <Textarea
                    rows={2}
                    value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)}
                    placeholder={t('admin.cms.optional_short_note_for_admins', 'Optional short note for admins')}
                />
            </Field>
            <div className={`${ui.row} sm:col-span-2`}>
                <Checkbox
                    label={t('admin.cms.enabled', 'Enabled')}
                    checked={!!form.data.is_enabled}
                    onChange={(e) => form.setData('is_enabled', e.target.checked)}
                />
                <div className={`${ui.toolbar} ml-auto`}>
                    {onCancel ? (
                        <Button type="button" variant="secondary" onClick={onCancel}>
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                    ) : null}
                    <Button type="submit" icon="check" busy={form.processing}>
                        {category ? t('admin.cms.save_category', 'Save category') : t('admin.cms.add_category', 'Add category')}
                    </Button>
                </div>
            </div>
        </form>
    );
}

function DeleteCategory({ category, others, onCancel }) {
    const form = useForm({ move_to_category_id: others[0] ? String(others[0].id) : '' });
    const hasOthers = others.length > 0;
    const hasPages = (category.pages_count || 0) > 0;

    const submit = (e) => {
        e.preventDefault();
        if (hasPages && !hasOthers) {
            return;
        }
        form.delete(`/admin/cms/categories/${category.id}`, {
            preserveScroll: true,
            onSuccess: () => onCancel?.(),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            <FormErrors errors={form.errors} />
            <p className={ui.muted}>
                {hasPages
                    ? `“${category.name}” has ${category.pages_count} page(s).`
                    : `Delete “${category.name}”? This category has no pages.`}
            </p>
            {hasPages && !hasOthers ? (
                <p className="text-sm text-red-600">
                    {t('admin.cms.there_is_no_other_category_to_move_pages_into_de', 'There is no other category to move pages into. Delete or reassign those pages first, then try again.')}
                </p>
            ) : null}
            {hasPages && hasOthers ? (
                <Field label={t('admin.cms.move_pages_to', 'Move pages to *')} error={form.errors.move_to_category_id}>
                    <Select
                        wide
                        value={form.data.move_to_category_id}
                        onChange={(e) => form.setData('move_to_category_id', e.target.value)}
                    >
                        {others.map((cat) => (
                            <option key={cat.id} value={String(cat.id)}>{cat.name}</option>
                        ))}
                    </Select>
                </Field>
            ) : null}
            <div className={`${ui.toolbar} justify-end`}>
                <Button type="button" variant="secondary" onClick={onCancel}>
                    {t('admin.common.cancel', 'Cancel')}
                </Button>
                <Button
                    type="submit"
                    variant="danger"
                    busy={form.processing}
                    disabled={hasPages && !hasOthers}
                >
                    {hasPages ? t('admin.cms.move_pages_delete', 'Move pages & delete') : t('admin.cms.delete_category', 'Delete category')}
                </Button>
            </div>
        </form>
    );
}

/**
 * Page Categories card — sits below CMS Pages on the list screen.
 */
export default function CategoriesPanel({ categories = [] }) {
    const [mode, setMode] = useState(null);
    const editing = mode?.type === 'edit' ? categories.find((c) => c.id === mode.id) : null;
    const deleting = mode?.type === 'delete' ? categories.find((c) => c.id === mode.id) : null;
    const others = deleting ? categories.filter((c) => c.id !== deleting.id) : [];

    return (
        <section className={ui.listPanel}>
            <div className={ui.panelIntro}>
                <div className={ui.panelIntroMain}>
                    <span className={ui.panelIntroIcon}>
                        <Icon name="folder" />
                    </span>
                    <div>
                        <h2 className={ui.panelIntroTitle}>{t('admin.cms.page_categories', 'Page Categories')}</h2>
                        <p className={ui.panelIntroHint}>{t('admin.cms.organize_your_pages_using_categories', 'Organize your pages using categories.')}</p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ListSettings
                        listUrl="/admin/cms"
                        importUrl="/admin/cms/categories/import"
                        importSampleUrl="/admin/cms/categories/import/sample"
                        exportUrl="/admin/cms/categories/export"
                    />
                    {mode === null ? (
                        <Button type="button" icon="plus" onClick={() => setMode('create')}>
                            {t('admin.cms.add_category', 'Add Category')}
                        </Button>
                    ) : null}
                </div>
            </div>

            {mode === 'create' ? (
                <div className="border-b border-neutral-100 bg-neutral-50/80 p-4">
                    <CategoryEditor onCancel={() => setMode(null)} />
                </div>
            ) : null}

            {editing ? (
                <div className="border-b border-neutral-100 bg-neutral-50/80 p-4">
                    <CategoryEditor category={editing} onCancel={() => setMode(null)} />
                </div>
            ) : null}

            {deleting ? (
                <div className="border-b border-amber-100 bg-amber-50/60 p-4">
                    <DeleteCategory category={deleting} others={others} onCancel={() => setMode(null)} />
                </div>
            ) : null}

            <div className={ui.tableWrap}>
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.cms.category_name', 'Category Name')}</Th>
                            <Th>{t('admin.cms.slug_2', 'Slug')}</Th>
                            <Th>{t('admin.cms.pages', 'Pages')}</Th>
                            <Th>{t('admin.cms.status', 'Status')}</Th>
                            <Th actions>{t('admin.cms.action', 'Action')}</Th>
                        </tr>
                    </THead>
                    <tbody>
                        {categories.length === 0 ? (
                            <tr>
                                <Td colSpan={5}>
                                    <p className={ui.empty}>{t('admin.cms.no_categories_yet_add_one_to_organize_cms_pages', 'No categories yet. Add one to organize CMS pages.')}</p>
                                </Td>
                            </tr>
                        ) : categories.map((cat) => (
                            <Tr key={cat.id}>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <span className={ui.cellStrong}>{cat.name}</span>
                                        {cat.description ? <span className={ui.cellSub}>{cat.description}</span> : null}
                                    </div>
                                </Td>
                                <Td>
                                    <span className={ui.cellSub}>{cat.slug}</span>
                                </Td>
                                <Td>{cat.pages_count ?? 0}</Td>
                                <Td>
                                    <Badge tone={cat.is_enabled ? 'success' : 'neutral'} dot>
                                        {cat.is_enabled ? t('admin.common.active', 'Active') : t('admin.common.inactive', 'Inactive')}
                                    </Badge>
                                </Td>
                                <Td actions>
                                    <RowActions
                                        canEdit
                                        canDelete={false}
                                        onEdit={() => setMode({ type: 'edit', id: cat.id })}
                                        items={[
                                            {
                                                key: 'toggle',
                                                label: cat.is_enabled ? t('admin.cms.deactivate', 'Deactivate') : t('admin.cms.activate', 'Activate'),
                                                icon: 'check-circle',
                                                onClick: () => {
                                                    router.put(`/admin/cms/categories/${cat.id}`, {
                                                        name: cat.name,
                                                        slug: cat.slug,
                                                        description: cat.description || '',
                                                        is_enabled: !cat.is_enabled,
                                                    }, { preserveScroll: true });
                                                },
                                            },
                                            {
                                                key: 'delete',
                                                label: t('admin.common.delete', 'Delete'),
                                                icon: 'trash',
                                                danger: true,
                                                onClick: () => setMode({ type: 'delete', id: cat.id }),
                                            },
                                        ]}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </div>
            <div className={ui.listFooter}>
                <span>
                    Showing 1 to {categories.length} of {categories.length} results
                </span>
            </div>
        </section>
    );
}
