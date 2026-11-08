import { useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Layout from '../Layout';
import EmailCanvas from '../../ui/EmailCanvas';
import {
    Button,
    FeatureTile,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    PageHeader,
    ui,
    t,
    cx,
} from '../../ui';

function variableLabel(key, fallback) {
    const labels = {
        shop_name: t('admin.email.var_shop_name', 'Shop name'),
        shop_url: t('admin.email.var_shop_url', 'Shop URL'),
        shop_email: t('admin.email.var_shop_email', 'Shop email'),
        customer_name: t('admin.email.var_customer_name', 'Customer name'),
        customer_email: t('admin.email.var_customer_email', 'Customer email'),
        order_number: t('admin.email.var_order_number', 'Order number'),
        grand_total: t('admin.email.var_order_total', 'Order total'),
        status: t('admin.email.var_order_status', 'Order status'),
        payment_status: t('admin.email.var_payment_status', 'Payment status'),
        reset_url: t('admin.email.var_password_reset_url', 'Password reset URL'),
    };

    return labels[key] || fallback || key;
}

function emptyEmailLocale() {
    return { subject: '', body: '', design_json: '' };
}

export default function EmailForm({ template, variables = [], languages = [], default_language: defaultLanguage = 'en' }) {
    const isNew = !template?.id;
    const canvasRef = useRef(null);
    const subjectRef = useRef(null);
    const page = usePage();
    const langList = languages.length
        ? languages
        : [{ code: defaultLanguage, name: defaultLanguage.toUpperCase(), is_default: true }];
    const [device, setDevice] = useState('desktop');
    const [insertInto, setInsertInto] = useState('email');
    const [testOpen, setTestOpen] = useState(false);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const [translations, setTranslations] = useState(() => {
        const seed = { ...(template?.translations || {}) };
        if (!seed[defaultLanguage]) {
            seed[defaultLanguage] = {
                subject: template?.subject || '',
                body: template?.body || '',
                design_json: template?.design_json || '',
            };
        }
        langList.forEach((lang) => {
            if (!seed[lang.code]) seed[lang.code] = emptyEmailLocale();
        });
        return seed;
    });
    const current = translations[selectedLanguage] || emptyEmailLocale();
    const form = useForm({
        name: template?.name || '',
        subject: current.subject || '',
        body: current.body || '',
        design_json: current.design_json || '',
        is_enabled: template?.is_enabled ?? true,
        translations,
    });
    const test = useForm({ to: page.props.auth?.admin?.email || '' });

    const flushCanvasInto = (code, map) => {
        const value = canvasRef.current?.getValue?.();
        const next = { ...map };
        next[code] = {
            subject: form.data.subject || '',
            body: value?.html ?? form.data.body ?? '',
            design_json: value?.design ?? form.data.design_json ?? '',
        };
        return next;
    };

    const switchLanguage = (nextCode) => {
        if (nextCode === selectedLanguage) return;
        const nextMap = flushCanvasInto(selectedLanguage, translations);
        setTranslations(nextMap);
        const locale = nextMap[nextCode] || emptyEmailLocale();
        form.setData({
            ...form.data,
            subject: locale.subject || '',
            body: locale.body || '',
            design_json: locale.design_json || '',
            translations: nextMap,
        });
        setSelectedLanguage(nextCode);
    };

    const insertVariable = (key) => {
        const token = `{{${key}}}`;
        if (insertInto === 'email') {
            canvasRef.current?.insertToken?.(key);
            return;
        }
        const el = subjectRef.current;
        const current = form.data.subject || '';
        if (el && typeof el.selectionStart === 'number') {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const next = `${current.slice(0, start)}${token}${current.slice(end)}`;
            form.setData('subject', next);
            requestAnimationFrame(() => {
                el.focus();
                const caret = start + token.length;
                el.setSelectionRange(caret, caret);
            });
            return;
        }
        form.setData('subject', `${current}${current ? ' ' : ''}${token}`);
    };

    const switchDevice = (next) => {
        setDevice(next);
        canvasRef.current?.setDevice?.(next);
    };

    const previewEmail = () => {
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
        const fallback = nextMap[defaultLanguage] || emptyEmailLocale();
        const payload = {
            name: form.data.name,
            subject: fallback.subject || form.data.subject,
            body: fallback.body || form.data.body,
            design_json: fallback.design_json || form.data.design_json || '',
            is_enabled: form.data.is_enabled,
            translations: nextMap,
        };
        form.transform(() => payload);
        if (isNew) {
            form.post('/admin/email/templates');
        } else {
            form.put(`/admin/email/templates/${template.id}`, { preserveScroll: true });
        }
    };

    return (
        <Layout>
            <PageHeader
                crumbsFirst
                title={isNew ? t('admin.email.create_template', 'Create template') : t('admin.email.edit_template', 'Edit email template')}
                description={t('admin.email.edit_lead', 'Customize the content and design of this email template. Use variables to personalize your messages.')}
                crumbs={[
                    { label: t('admin.nav.configure', 'Configure') },
                    { href: '/admin/email?tab=templates', label: t('admin.email.email', 'Email') },
                    { label: isNew ? t('admin.common.add_new', 'Add new') : t('admin.common.edit', 'Edit') },
                ]}
                actions={(
                    <Button type="submit" form="email-template-form" icon="check" busy={form.processing}>
                        {t('admin.email.save_template', 'Save template')}
                    </Button>
                )}
            />

            <form id="email-template-form" onSubmit={submit} className="space-y-4">
                <FormErrors errors={form.errors} />

                <section className={ui.sectionCard}>
                    <div className="mb-4 flex min-w-0 items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="mail" className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                                {t('admin.email.template_details', 'Template details')}
                            </h2>
                            <p className={cx(ui.muted, 'mt-0.5')}>
                                {t('admin.email.template_details_hint', 'Set the basic information and variables for this template.')}
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4">
                        <Field
                            label={t('admin.email.template_name', 'Template name')}
                            hint={t('admin.email.template_name_hint', 'A short name to identify this template.')}
                            error={form.errors.name}
                        >
                            <Input
                                wide
                                invalid={!!form.errors.name}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <LocalizedField
                            label={t('admin.email.subject', 'Subject')}
                            hint={t('admin.email.subject_line_hint', 'This will be shown in the email subject line. Switch language to translate each version.')}
                            error={form.errors.subject}
                            languages={langList}
                            currentLanguage={selectedLanguage}
                            onLanguageChange={switchLanguage}
                        >
                            <Input
                                ref={subjectRef}
                                wide
                                invalid={!!form.errors.subject}
                                value={form.data.subject}
                                onChange={(e) => form.setData('subject', e.target.value)}
                            />
                        </LocalizedField>

                        <div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className={ui.label}>{t('admin.email.available_variables', 'Available variables')}</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant={insertInto === 'email' ? 'primary' : 'secondary'}
                                        onClick={() => setInsertInto('email')}
                                    >
                                        {t('admin.email.insert_into_email', 'Insert into email')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={insertInto === 'subject' ? 'primary' : 'secondary'}
                                        onClick={() => setInsertInto('subject')}
                                    >
                                        {t('admin.email.insert_into_subject', 'Insert into subject')}
                                    </Button>
                                </div>
                            </div>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {insertInto === 'email'
                                    ? t('admin.email.insert_email_hint', 'Click a variable to drop it into the email. Select a text block first to add it there.')
                                    : t('admin.email.insert_subject_hint', 'Click a variable to add it to the subject line.')}
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
                                        <span className="font-normal text-neutral-500"> {variableLabel(item.key, item.label)}</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {!isNew ? (
                            <div className="mt-4">
                                <FeatureTile
                                    icon="check"
                                    tone="bg-emerald-100 text-emerald-600"
                                    title={t('admin.email.enabled', 'Enabled')}
                                    hint={t('admin.email.enabled_hint', 'This template is active and can be used.')}
                                    value={!!form.data.is_enabled}
                                    disabled={template?.code === 'forgot_password'}
                                    onChange={(value) => form.setData('is_enabled', value)}
                                />
                            </div>
                        ) : null}
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
                                    {t('admin.email.email_design', 'Email design')}
                                </h2>
                                <p className={cx(ui.muted, 'mt-0.5')}>
                                    {t('admin.email.email_design_hint', 'Open Blocks to add a title or paragraph, then click Save template. Inbox clients receive the saved HTML.')}
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {langList.length > 1 ? (
                                <LocalizedField
                                    label={t('admin.email.content_language', 'Content language')}
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
                                {t('admin.email.desktop', 'Desktop')}
                            </Button>
                            <Button
                                type="button"
                                variant={device === 'mobile' ? 'primary' : 'secondary'}
                                icon="smartphone"
                                onClick={() => switchDevice('mobile')}
                            >
                                {t('admin.email.mobile', 'Mobile')}
                            </Button>
                            <Button type="button" variant="secondary" icon="eye" onClick={previewEmail}>
                                {t('admin.email.preview_email', 'Preview email')}
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

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                icon="send"
                                onClick={() => setTestOpen((open) => !open)}
                            >
                                {t('admin.email.send_test', 'Send a test email')}
                            </Button>
                            {testOpen ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Input
                                        type="email"
                                        className="w-56"
                                        placeholder={t('admin.email.test_to', 'Send to')}
                                        value={test.data.to}
                                        onChange={(e) => test.setData('to', e.target.value)}
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        icon="send"
                                        busy={test.processing}
                                        onClick={() => {
                                            const value = canvasRef.current?.getValue?.();
                                            test.transform((data) => ({
                                                ...data,
                                                subject: form.data.subject,
                                                html: value?.html || form.data.body,
                                            }));
                                            test.post('/admin/email/smtp/test');
                                        }}
                                    >
                                        {t('admin.email.send', 'Send')}
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                        <p className={ui.muted}>
                            {t('admin.email.save_to_keep', 'Click Save template to keep this design. Editing the canvas does not save on its own.')}
                        </p>
                    </div>
                </section>
            </form>
        </Layout>
    );
}
