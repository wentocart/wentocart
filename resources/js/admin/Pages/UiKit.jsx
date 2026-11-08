import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout from './Layout';
import {
    Alert,
    Badge,
    Button,
    BulkBar,
    BulkDelete,
    Card,
    Checkbox,
    ColorField,
    ColumnFilter,
    Field,
    FormCard,
    FormErrors,
    Icon,
    iconNames,
    ImageField,
    InlineForm,
    KpiCards,
    Input,
    List,
    ListItem,
    Loader,
    PageHeader,
    Pagination,
    RowActions,
    Select,
    SortHeader,
    StatusToggle,
    Table,
    THead,
    Td,
    Textarea,
    Th,
    Tr,
    FeatureTile,
    SettingsCount,
    SettingsSectionHead,
    t,
    ui,
} from '../ui';
import Editor from '../ui/Editor';

function Section({ title, hint, children }) {
    return (
        <section className="mb-8">
            <h2 className={ui.sectionTitle}>{title}</h2>
            {hint ? <p className={`${ui.muted} mb-3`}>{hint}</p> : null}
            {children}
        </section>
    );
}

function Snippet({ children }) {
    return <Card className="overflow-auto text-xs text-slate-600 whitespace-pre-wrap mb-4">{children}</Card>;
}

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.ui_kit', 'UI kit') },
];

export default function UiKit({ previewUrl }) {
    const [busy, setBusy] = useState(false);
    const [tileOn, setTileOn] = useState(true);
    const [tone, setTone] = useState('#7C3AED');
    const [sort, setSort] = useState({ column: 'name', dir: 'asc' });
    const [demoPage, setDemoPage] = useState(1);
    const [demoPerPage, setDemoPerPage] = useState(10);
    const [localPreview, setLocalPreview] = useState(previewUrl || '');
    const form = useForm({ title: '', body: '' });
    const upload = useForm({ image: null });

    const triggerBusy = () => {
        setBusy(true);
        window.setTimeout(() => setBusy(false), 1200);
    };

    const flash = (type) => router.post('/admin/ui-kit/flash', { type });

    return (
        <Layout>
            <PageHeader
                title={t('admin.nav.ui_kit', 'UI kit')}
                crumbs={crumbs}
                crumbsFirst
                className="mb-2"
            />
            <p className={`${ui.muted} mb-6`}>
                {t('admin.ui_kit.intro', 'Copy these callers. Do not paste Tailwind, Font Awesome, or a new CSS file. Catalog / orders / customers import from resources/js/admin/ui the same way.')}
            </p>
            <p className={`${ui.muted} mb-6`}>
                {t('admin.ui_kit.extensions_intro', 'Zip extension authors cannot import this React kit. Return page documents instead (kpis, list, button, fields). Live examples and PHP snippets: section 11 below, plus docs/extensions/UI.md.')}
            </p>

            <Section title="1. Icons" hint="Only <Icon name=… />. Add a path in ui/Icon.jsx if a new name is required — never a second icon library.">
                <Snippet>{`import { Icon } from '../../ui';\n<Icon name="plus" />`}</Snippet>
                <div className="flex flex-wrap gap-2">
                    {iconNames.map((name) => (
                        <Card key={name} className={ui.row}>
                            <Icon name={name} />
                            <span className="text-sm">{name}</span>
                        </Card>
                    ))}
                </div>
            </Section>

            <Section title="2. Loader" hint="Page wait = <Loader />. Button wait = busy on Button (same spinner).">
                <Snippet>{`<Loader label="Loading products…" />\n<Button icon="check" busy={form.processing}>Save</Button>`}</Snippet>
                <Card className="space-y-3">
                    <Loader label="Loading products…" />
                    <div className={ui.row}>
                        <Button icon="check" busy={busy} type="button" onClick={triggerBusy}>
                            Save
                        </Button>
                        <span className={ui.muted}>Click to see the in-button spinner.</span>
                    </div>
                </Card>
            </Section>

            <Section title="3. Buttons" hint={t('admin.ui_kit.buttons_hint', 'Variants already live in ui/Button.jsx. Pass icon and busy — do not restyle. Extension zips declare type button / link in a page document; core draws this same Button.')}>
                <Snippet>{`<Button icon="plus">Add</Button>\n<Button variant="secondary" icon="download">Export</Button>\n<Button variant="danger" icon="trash">Delete</Button>`}</Snippet>
                <div className="flex flex-wrap gap-2">
                    <Button icon="plus">Add</Button>
                    <Button variant="secondary" icon="download">
                        Export
                    </Button>
                    <Button variant="compact" icon="plus">
                        New product
                    </Button>
                    <Button variant="sm" icon="check">
                        Save
                    </Button>
                    <Button variant="link" icon="pencil">
                        Edit
                    </Button>
                    <Button variant="danger" icon="trash">
                        Delete
                    </Button>
                </div>
            </Section>

            <Section
                title="4. Success / fail / warning / info"
                hint="List actions (toggle/bulk/import/destroy) set session toast=true → growl. Form store/update keep inline Flash banners."
            >
                <Snippet>{`// Form save — inline banner after redirect\nreturn redirect()->route('admin.products.index')->with('success', 'Product updated.');\n\n// List action — MarkAdminListToast adds toast=true automatically\nreturn back()->with('success', 'Product enabled.');`}</Snippet>
                <div className="flex flex-wrap gap-2 mb-4">
                    <Button icon="check-circle" type="button" onClick={() => flash('success')}>
                        Success flash
                    </Button>
                    <Button variant="danger" icon="alert-circle" type="button" onClick={() => flash('error')}>
                        Failure flash
                    </Button>
                    <Button variant="secondary" icon="alert-triangle" type="button" onClick={() => flash('warning')}>
                        Warning flash
                    </Button>
                    <Button variant="secondary" icon="info" type="button" onClick={() => flash('info')}>
                        Info flash
                    </Button>
                </div>
                <p className={`${ui.muted} mb-3`}>Static copies of the same Alert (what Flash draws):</p>
                <Alert type="ok">Saved. Type ok / success.</Alert>
                <Alert type="error">Save failed. Type error / fail.</Alert>
                <Alert type="warning">Needs attention. Type warning.</Alert>
                <Alert type="info">FYI. Type info.</Alert>
            </Section>

            <Section
                title="5. Validation errors"
                hint="Submit empty to see FormErrors + field error. This is Inertia 422 — not a custom alert()."
            >
                <Snippet>{`<FormErrors errors={form.errors} />\n<Field label="Title" error={form.errors.title}>\n  <Input wide invalid={!!form.errors.title} value={form.data.title} onChange={…} />\n</Field>`}</Snippet>
                <FormCard
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/ui-kit/attempt');
                    }}
                >
                    <FormErrors className="col-span-2" errors={form.errors} />
                    <Field label="Title" error={form.errors.title} className="col-span-2">
                        <Input
                            wide
                            invalid={!!form.errors.title}
                            placeholder="Leave empty and save"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                        />
                    </Field>
                    <Field label="Body (editor)" error={form.errors.body} className="col-span-2">
                        <Editor value={form.data.body} onChange={(html) => form.setData('body', html)} />
                    </Field>
                    <Button variant="block" className="col-span-2" icon="check" busy={form.processing}>
                        Save dummy form
                    </Button>
                </FormCard>
            </Section>

            <Section title="6. Text editor" hint="TipTap rich editor (toolbar UI + image upload). One shared &lt;Editor /&gt; for products &amp; CMS — not TinyMCE.">
                <Snippet>{`import Editor from '../../ui/Editor';\n<Editor value={form.data.body} onChange={(html) => form.setData('body', html)} />`}</Snippet>
                <p className={ui.muted}>The editor in section 5 is the live example. Fill Title + Body and Save to get a success flash.</p>
            </Section>

            <Section title="7. Image" hint="Use ImageField + Thumb. Store on the public disk. Do not invent a dropzone CSS file.">
                <Snippet>{`import { ImageField } from '../../ui';\n<ImageField\n  preview={url}\n  error={form.errors.image}\n  onChange={(e) => form.setData('image', e.target.files[0])}\n/>`}</Snippet>
                <Card
                    as="form"
                    className="max-w-lg space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post('/admin/ui-kit/upload', { forceFormData: true });
                    }}
                >
                    <FormErrors errors={upload.errors} />
                    <ImageField
                        preview={localPreview}
                        error={upload.errors.image}
                        onChange={(e) => {
                            const file = e.target.files[0];
                            upload.setData('image', file);
                            if (file) {
                                setLocalPreview(URL.createObjectURL(file));
                            }
                        }}
                    />
                    <Button icon="upload" busy={upload.processing}>
                        Upload image
                    </Button>
                </Card>
            </Section>

            <Section title="8. Fields" hint="Input / Select / Textarea / Checkbox / InlineForm — all from ui/Field and ui/Card.">
                <Snippet>{`<InlineForm onSubmit={…}>\n  <Input placeholder="Name" />\n  <Select>…</Select>\n  <Button icon="plus">Add</Button>\n</InlineForm>`}</Snippet>
                <InlineForm
                    onSubmit={(e) => {
                        e.preventDefault();
                    }}
                >
                    <Input placeholder="Name" />
                    <Select defaultValue="">
                        <option value="">Choose</option>
                        <option>One</option>
                    </Select>
                    <Input narrow type="number" defaultValue="10" />
                    <Button icon="plus">Add</Button>
                </InlineForm>
                <Card className="space-y-3 max-w-lg">
                    <Field label="Notes">
                        <Textarea rows="3" placeholder="Plain textarea when you do not need rich text" />
                    </Field>
                    <Checkbox label="Enabled" defaultChecked />
                    <Field label="Color">
                        <ColorField value={tone} onChange={setTone} />
                    </Field>
                    <Badge tone={tone}>Preview badge</Badge>
                </Card>
            </Section>

            <Section title="9. Table and list" hint="SortHeader + ColumnFilter + StatusToggle + RowActions (⋯) + BulkBar. See /admin/customers and /admin/payments.">
                <Snippet>{`<SortHeader column="name" label="Name" sort={sort} dir={dir} onSort={onSort} />\n<ColumnFilter name="name" placeholder="Name" value={q.name} onApply={applyColumn} />\n<RowActions editHref={…} onDelete={…} />`}</Snippet>
                <div className="flex flex-wrap gap-3 mb-4 items-center">
                    <div className="w-40">
                        <ColumnFilter name="name" placeholder="Name" value="" onApply={() => {}} />
                    </div>
                    <div className="w-36">
                        <ColumnFilter
                            name="status"
                            type="select"
                            placeholder="Status"
                            value=""
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                            onApply={() => {}}
                        />
                    </div>
                    <StatusToggle active onToggle={() => {}} />
                    <RowActions editHref="/admin/ui-kit" onDelete={() => {}} />
                    <ColorField value={tone} onChange={setTone} />
                </div>
                <BulkBar count={2} onClear={() => {}}>
                    <BulkDelete ids={[1, 2]} onDelete={() => {}} />
                </BulkBar>
                <Table className="mt-3">
                    <THead>
                        <tr>
                            <SortHeader
                                column="name"
                                label="Name"
                                sort={sort.column}
                                dir={sort.dir}
                                onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))}
                            />
                            <Th>Status</Th>
                            <Th actions>Action</Th>
                        </tr>
                        <tr>
                            <Th>
                                <ColumnFilter name="name" placeholder="Name" value="" onApply={() => {}} />
                            </Th>
                            <Th>
                                <ColumnFilter
                                    name="status"
                                    type="select"
                                    placeholder="Status"
                                    value=""
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        { value: 'inactive', label: 'Inactive' },
                                    ]}
                                    onApply={() => {}}
                                />
                            </Th>
                            <Th actions />
                        </tr>
                    </THead>
                    <tbody>
                        <Tr>
                            <Td>Sample row</Td>
                            <Td>
                                <StatusToggle active onToggle={() => {}} />
                            </Td>
                            <Td actions>
                                <RowActions editHref="/admin/ui-kit" onDelete={() => {}} />
                            </Td>
                        </Tr>
                    </tbody>
                </Table>
                <div className={`${ui.listFooter} mt-3`}>
                    <Pagination
                        inline
                        paginator={{
                            current_page: demoPage,
                            last_page: 9,
                            from: (demoPage - 1) * demoPerPage + 1,
                            to: demoPage * demoPerPage,
                            total: 9 * demoPerPage,
                            per_page: demoPerPage,
                        }}
                        onPageChange={setDemoPage}
                        onPerPageChange={(n) => {
                            setDemoPerPage(n);
                            setDemoPage(1);
                        }}
                    />
                </div>
                <Snippet>{`<ListFooter pagination={rows} query={query} />\n// or local lists:\n<Pagination inline paginator={…} onPageChange={setPage} onPerPageChange={setPerPage} />`}</Snippet>
                <List className="mt-4">
                    <ListItem>List item — same card chrome as categories / brands.</ListItem>
                    <ListItem className="flex justify-between">
                        Second item
                        <Button variant="link" icon="check" type="button">
                            Approve
                        </Button>
                    </ListItem>
                </List>
            </Section>

            <Section
                title={t('admin.ui_kit.kpis_title', '10. List KPIs')}
                hint={t('admin.ui_kit.kpis_hint', 'Exactly four pastel cards. Core lists use KpiCards. Extension lists return a kpis array on the page document — do not invent a second KPI grid.')}
            >
                <Snippet>{`import { KpiCards, statusCountKpis } from '../../ui';\n<KpiCards items={statusCountKpis(counts)} />\n\n// Zip / page document (no React import):\n'kpis' => [\n  ['label' => 'Enquiries', 'value' => '128', 'trend' => 4.2],\n  ['label' => 'Open', 'value' => '12', 'trend' => -1.0],\n  ['label' => 'Closed', 'value' => '116', 'trend' => 2.5],\n  ['label' => 'Reply rate', 'value' => '91%', 'trend' => 0.4],\n]`}</Snippet>
                <KpiCards
                    items={[
                        { key: 'total', label: t('admin.common.total', 'Total'), value: '128', trend: 4.2 },
                        { key: 'active', label: t('admin.common.active', 'Active'), value: '12', trend: -1 },
                        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), value: '116', trend: 2.5 },
                        { key: 'rate', label: t('admin.common.active_rate', 'Active rate'), value: '9.4%', trend: 0.4 },
                    ]}
                />
            </Section>

            <Section
                title={t('admin.ui_kit.extensions_title', '11. Extensions (zip authors)')}
                hint={t('admin.ui_kit.extensions_hint', 'You never ship admin React. Core hosts your PHP page document. Copy the arrays, not the JSX.')}
            >
                <Snippet>{`return $this->page([\n  'title' => 'Enquiries',\n  'kpis' => [\n    ['label' => 'Total', 'value' => (string) $total, 'trend' => 0],\n    ['label' => 'Open', 'value' => (string) $open, 'trend' => 0],\n    ['label' => 'Closed', 'value' => (string) $closed, 'trend' => 0],\n    ['label' => 'Reply rate', 'value' => $rate.'%', 'trend' => 0],\n  ],\n  'list' => [ /* columns, rows, bulk => catalog */ ],\n]);\n\n// Admin CTA — same Button as this page\n['type' => 'button', 'label' => 'Open list', 'href' => '/admin/modules/acme/help/list', 'icon' => 'arrow-right']\n\n// Storefront next to Add to cart (looks like Classic buy button)\n$this->slots()->on('storefront.product.actions', function () {\n  return [[\n    'type' => 'button',\n    'label' => 'Ask a question',\n    'href' => '/m/acme/help/ask',\n    'icon' => 'help',\n    // omit variant (primary) = Add to cart look\n    // 'variant' => 'secondary' = Compare / Wishlist text link\n  ]];\n});`}</Snippet>
                <p className={ui.muted}>
                    {t('admin.ui_kit.extensions_docs', 'Full cookbook: docs/extensions/UI.md. Field types, icons, uploads, and lists: docs/extensions/CODING-STANDARD.md. Slots: docs/extensions/STOREFRONT.md.')}
                </p>
            </Section>

            <Section
                title={t('admin.ui_kit.settings_title', '12. Configure settings')}
                hint={t('admin.ui_kit.settings_hint', 'Configure pages use this tile. A switch or select saves immediately. A text field saves shortly after you stop typing. There is no Save button.')}
            >
                <Snippet>{`import { Card, FeatureTile, SettingsCount, SettingsSectionHead } from '../../ui';\n\n<Card>\n  <SettingsSectionHead\n    icon="settings"\n    title="Orders"\n    extra={<SettingsCount>1 setting</SettingsCount>}\n  />\n  <FeatureTile\n    icon="check"\n    title="Allow cancellation"\n    value={on}\n    onChange={setOn}\n  />\n</Card>`}</Snippet>
                <Card>
                    <SettingsSectionHead
                        icon="settings"
                        title={t('admin.ui_kit.settings_example', 'Example setting')}
                        hint={t('admin.ui_kit.settings_example_hint', 'This switch only changes this page. Configure pages save the same control to the shop.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 1 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="check"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.ui_kit.settings_toggle', 'Allow this example')}
                            hint={t('admin.ui_kit.settings_toggle_hint', 'On a Configure page this would save as soon as you flip it.')}
                            value={tileOn}
                            onChange={setTileOn}
                        />
                    </div>
                </Card>
            </Section>
        </Layout>
    );
}
