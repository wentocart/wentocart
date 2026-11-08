import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Layout from '../Layout';
import EmailCanvas from '../../ui/EmailCanvas';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    PageHeader,
    Select,
    Toggle,
    ui,
    t,
    cx,
} from '../../ui';

function emptyDocLocale() {
    return { email_subject: '', body: '', design_json: '' };
}

export default function DocumentForm({ template, types = [], variables = [], languages = [], default_language: defaultLanguage = 'en' }) {
    const isNew = !template?.id;
    const canvasRef = useRef(null);
    const subjectRef = useRef(null);
    const langList = languages.length
        ? languages
        : [{ code: defaultLanguage, name: defaultLanguage.toUpperCase(), is_default: true }];
    const [device, setDevice] = useState('desktop');
    const [insertInto, setInsertInto] = useState('document');
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const [translations, setTranslations] = useState(() => {
        const seed = { ...(template?.translations || {}) };
        if (!seed[defaultLanguage]) {
            seed[defaultLanguage] = {
                email_subject: template?.email_subject || '',
                body: template?.body || '',
                design_json: template?.design_json || '',
            };
        }
        langList.forEach((lang) => {
            if (!seed[lang.code]) seed[lang.code] = emptyDocLocale();
        });
        return seed;
    });
    const current = translations[selectedLanguage] || emptyDocLocale();
    const form = useForm({
        name: template?.name || '',
        type: template?.type || 'custom',
        email_subject: current.email_subject || '',
        body: current.body || '',
        design_json: current.design_json || '',
        is_enabled: template?.is_enabled ?? true,
        translations,
    });

    const flushCanvasInto = (code, map) => {
        const value = canvasRef.current?.getValue?.();
        const next = { ...map };
        next[code] = {
            email_subject: form.data.email_subject || '',
            body: value?.html ?? form.data.body ?? '',
            design_json: value?.design ?? form.data.design_json ?? '',
        };
        return next;
    };

    const switchLanguage = (nextCode) => {
        if (nextCode === selectedLanguage) return;
        const nextMap = flushCanvasInto(selectedLanguage, translations);
        setTranslations(nextMap);
        const locale = nextMap[nextCode] || emptyDocLocale();
        form.setData({
            ...form.data,
            email_subject: locale.email_subject || '',
            body: locale.body || '',
            design_json: locale.design_json || '',
            translations: nextMap,
        });
        setSelectedLanguage(nextCode);
    };

    const insertVariable = (key) => {
        const token = `{{${key}}}`;
        if (insertInto === 'document') {
            canvasRef.current?.insertToken?.(key);
            return;
        }
        const el = subjectRef.current;
        const current = form.data.email_subject || '';
        if (el && typeof el.selectionStart === 'number') {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const next = `${current.slice(0, start)}${token}${current.slice(end)}`;
            form.setData('email_subject', next);
            requestAnimationFrame(() => {
                el.focus();
                const caret = start + token.length;
                el.setSelectionRange(caret, caret);
            });
            return;
        }
        form.setData('email_subject', `${current}${current ? ' ' : ''}${token}`);
    };

    const switchDevice = (next) => {
        setDevice(next);
        canvasRef.current?.setDevice?.(next);
    };

    const previewDocument = () => {
        const value = canvasRef.current?.getValue?.();
        const html = value?.html || form.data.body || '';
        const win = window.open('', '_blank');
        if (!win) {
            return;
        }
        win.document.write(html || '<p>No content yet.</p>');
        win.document.close();
    };

    const submit = (e) => {
        e.preventDefault();
        const nextMap = flushCanvasInto(selectedLanguage, translations);
        const fallback = nextMap[defaultLanguage] || emptyDocLocale();
        const payload = {
            name: form.data.name,
            type: form.data.type,
            email_subject: fallback.email_subject || form.data.email_subject,
            body: fallback.body || form.data.body,
            design_json: fallback.design_json || form.data.design_json || '',
            is_enabled: form.data.is_enabled,
            translations: nextMap,
        };
        form.transform(() => payload);
        if (isNew) {
            form.post('/admin/documents');
        } else {
            form.put(`/admin/documents/${template.id}`, { preserveScroll: true });
        }
    };

    return (
        <Layout>
            <PageHeader
                crumbsFirst
                title={isNew ? t('admin.documents.create_template', 'Create document template') : t('admin.documents.edit_template', 'Edit document template')}
                description={t('admin.documents.edit_lead', 'Lay out the PDF for this document. Variables fill in the order, shop, and product lines when it is generated.')}
                crumbs={[
                    { label: t('admin.nav.configure', 'Configure') },
                    { href: '/admin/documents', label: t('admin.documents.document_designs', 'Document designs') },
                    { label: isNew ? t('admin.common.add_new', 'Add new') : t('admin.common.edit', 'Edit') },
                ]}
                actions={(
                    <Button type="submit" form="document-template-form" icon="check" busy={form.processing}>
                        {t('admin.documents.save_template', 'Save template')}
                    </Button>
                )}
            />

            <form id="document-template-form" onSubmit={submit} className="space-y-4">
                <FormErrors errors={form.errors} />

                <section className={ui.sectionCard}>
                    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className={ui.sectionHeadIcon}>
                                <Icon name="file" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className={cx(ui.sectionTitle, 'mb-0 inline-flex flex-wrap items-center gap-2')}>
                                    {t('admin.documents.details', 'Document details')}
                                    {!isNew && template.is_in_use ? (
                                        <Badge tone="success">{t('admin.documents.in_use', 'In use')}</Badge>
                                    ) : null}
                                </h2>
                                <p className={cx(ui.muted, 'mt-0.5')}>
                                    {t('admin.documents.details_hint', 'Name the PDF and choose its type. Only the template marked In use is attached to that document. The email subject is used when the PDF is emailed.')}
                                </p>
                            </div>
                        </div>
                        <Toggle
                            label={t('admin.common.enabled', 'Enabled')}
                            hint={t('admin.documents.enabled_hint', 'A template must be enabled before it can be set as in use. The one marked In use stays on until you choose another.')}
                            value={!!form.data.is_enabled}
                            onChange={(value) => form.setData('is_enabled', value)}
                        />
                    </div>

                    <div className="grid gap-4">
                        <Field
                            label={t('admin.documents.name', 'Name')}
                            hint={t('admin.documents.name_hint', 'Shown in the document list. Customers see the PDF, not this name.')}
                            error={form.errors.name}
                            required
                        >
                            <Input
                                wide
                                invalid={!!form.errors.name}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('admin.documents.type', 'Type')}
                            error={form.errors.type}
                            required
                            hint={template?.is_system
                                ? t('admin.documents.system_type_locked', 'Built-in type cannot be changed. Duplicate to make a custom variant.')
                                : t('admin.documents.type_hint', 'Invoice, credit slip, and delivery slip replace those shop documents. Custom is an extra PDF.')}
                        >
                            <Select
                                wide
                                disabled={!!template?.is_system}
                                value={form.data.type}
                                onChange={(e) => form.setData('type', e.target.value)}
                            >
                                {types.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <LocalizedField
                            label={t('admin.documents.email_subject', 'Email subject')}
                            error={form.errors.email_subject}
                            required
                            hint={t('admin.documents.email_subject_hint', 'Subject line when a staff member emails this PDF. Switch language to translate each version.')}
                            languages={langList}
                            currentLanguage={selectedLanguage}
                            onLanguageChange={switchLanguage}
                        >
                            <Input
                                ref={subjectRef}
                                wide
                                invalid={!!form.errors.email_subject}
                                value={form.data.email_subject}
                                onChange={(e) => form.setData('email_subject', e.target.value)}
                            />
                        </LocalizedField>
                        <div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className={ui.label}>{t('admin.documents.variables', 'Variables')}</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant={insertInto === 'document' ? 'primary' : 'secondary'}
                                        onClick={() => setInsertInto('document')}
                                    >
                                        {t('admin.documents.insert_into_document', 'Insert into document')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={insertInto === 'subject' ? 'primary' : 'secondary'}
                                        onClick={() => setInsertInto('subject')}
                                    >
                                        {t('admin.documents.insert_into_subject', 'Insert into email subject')}
                                    </Button>
                                </div>
                            </div>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {insertInto === 'document'
                                    ? t('admin.documents.insert_document_hint', 'Click a variable to place it in the PDF. Select a text block first to add it there. {{items_rows}} prints the product table.')
                                    : t('admin.documents.insert_subject_hint', 'Click a variable to add it to the email subject. The PDF itself is not changed.')}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {variables.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        className={ui.tagChip}
                                        onClick={() => insertVariable(item.key)}
                                    >
                                        {`{{${item.key}}}`}
                                        <span className="font-normal text-neutral-500"> {item.label}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                <section className={ui.sectionCard}>
                    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className={ui.sectionHeadIcon}>
                                <Icon name="pencil" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                                    {t('admin.documents.layout', 'Document layout')}
                                </h2>
                                <p className={cx(ui.muted, 'mt-0.5')}>
                                    {t('admin.documents.layout_hint', 'Open Blocks to add a heading, paragraph, or table for the PDF. Product lines come from {{items_rows}}. Save template to keep the layout.')}
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {langList.length > 1 ? (
                                <LocalizedField
                                    label={t('admin.documents.content_language', 'Content language')}
                                    languages={langList}
                                    currentLanguage={selectedLanguage}
                                    onLanguageChange={switchLanguage}
                                    beside
                                >
                                    <span className={ui.muted}>
                                        {langList.find((row) => row.code === selectedLanguage)?.name || selectedLanguage}
                                    </span>
                                </LocalizedField>
                            ) : null}
                            <Button
                                type="button"
                                variant={device === 'desktop' ? 'primary' : 'secondary'}
                                icon="monitor"
                                onClick={() => switchDevice('desktop')}
                            >
                                {t('admin.documents.desktop', 'Page')}
                            </Button>
                            <Button
                                type="button"
                                variant={device === 'mobile' ? 'primary' : 'secondary'}
                                icon="smartphone"
                                onClick={() => switchDevice('mobile')}
                            >
                                {t('admin.documents.narrow', 'Narrow')}
                            </Button>
                            <Button type="button" variant="secondary" icon="eye" onClick={previewDocument}>
                                {t('admin.documents.preview', 'Preview document')}
                            </Button>
                        </div>
                    </div>
                    <EmailCanvas
                        key={`${template?.id || 'new'}-${selectedLanguage}`}
                        html={form.data.body || ''}
                        design={form.data.design_json}
                        hideDeviceToolbar
                        onReady={(api) => {
                            canvasRef.current = api;
                            api.setDevice?.(device);
                        }}
                    />
                    {form.errors.body ? <p className={ui.fieldError}>{form.errors.body}</p> : null}
                    <p className={cx(ui.muted, 'mt-4')}>
                        {t('admin.documents.save_to_keep', 'Click Save template to keep this PDF layout. Editing the canvas does not save on its own.')}
                    </p>
                </section>
            </form>
        </Layout>
    );
}
