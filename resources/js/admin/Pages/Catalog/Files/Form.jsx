import { router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Field,
    FormErrors,
    Icon,
    Input,
    RequiredMark,
    LocalizedField,
    PageHeader,
    Textarea,
    Tooltip,
    cx,
    ui,
    t,
} from '../../../ui';

const TRANSLATABLE_FIELDS = ['name', 'description'];

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }

    return fallback || null;
}

function isImageFile(file, existing) {
    if (file instanceof File) {
        return file.type.startsWith('image/');
    }

    return !!existing?.is_image;
}

function fileExtension(name) {
    const part = String(name || '').split('.').pop();
    return part ? part.toUpperCase() : '—';
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

function buildTranslations(file, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (file?.translations || []).forEach((row) => {
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
    if (!file?.translations?.length && file) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: file.name || '',
            description: file.description || '',
        };
    }

    return out;
}

const DEFAULT_UPLOAD_LIMITS = {
    max_kb: 10240,
    max_bytes: 10240 * 1024,
    max_label: '10 MB',
};

export default function Form({ file, uploadLimits = DEFAULT_UPLOAD_LIMITS }) {
    const isEdit = Boolean(file?.id);
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(file, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const fileInputRef = useRef(null);

    const form = useForm({
        name: defaultTranslation.name || '',
        description: defaultTranslation.description || '',
        translations: initialTranslations,
        file: null,
    });

    const fallbackLanguage = languages.find((row) => row.code === defaultLanguage) || null;
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

    const setTranslationField = (field, value) => {
        const current = translationFor(selectedLanguage);
        const nextRow = {
            ...current,
            language_code: selectedLanguage,
            [field]: value,
        };
        const next = {
            ...form.data,
            translations: {
                ...form.data.translations,
                [selectedLanguage]: nextRow,
            },
        };
        if (selectedLanguage === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
            next[field] = value;
        }
        form.setData(next);
        if (field === 'name' && String(value || '').trim()) {
            form.clearErrors(`translations.${selectedLanguage}.name`);
            if (selectedLanguage === defaultLanguage) {
                form.clearErrors('name');
            }
        }
    };

    const tooLargeMessage = t(
        'admin.files.too_large',
        'The file is too large. Maximum size is {max}.',
        { max: uploadLimits.max_label },
    );

    const submit = (e) => {
        e.preventDefault();
        form.clearErrors();

        const translations = Object.fromEntries(
            Object.entries(form.data.translations || {}).map(([languageCode, row]) => [
                languageCode,
                {
                    ...emptyTranslation(languageCode),
                    ...row,
                    language_code: languageCode,
                },
            ]),
        );
        const baseTranslation = translations[defaultLanguage] || emptyTranslation(defaultLanguage);
        const resolvedName = String(baseTranslation.name || form.data.name || '').trim();
        const nextErrors = {};

        if (!resolvedName) {
            nextErrors[`translations.${defaultLanguage}.name`] = t('admin.files.name_required', 'File name is required.');
        }

        const hasFile = form.data.file instanceof File;
        if (!isEdit && !hasFile) {
            nextErrors.file = t('admin.files.file_required', 'Please select a file.');
        } else if (hasFile && form.data.file.size > uploadLimits.max_bytes) {
            nextErrors.file = tooLargeMessage;
        }

        if (Object.keys(nextErrors).length > 0) {
            Object.entries(nextErrors).forEach(([key, message]) => form.setError(key, message));
            return;
        }

        form.transform((data) => {
            const payload = {
                ...data,
                name: resolvedName,
                description: baseTranslation.description || data.description || '',
                translations: {
                    ...translations,
                    [defaultLanguage]: {
                        ...(translations[defaultLanguage] || emptyTranslation(defaultLanguage)),
                        name: resolvedName,
                    },
                },
                ...(isEdit && hasFile ? { _method: 'put' } : {}),
            };
            if (data.file instanceof File) {
                payload.file = data.file;
            } else {
                delete payload.file;
            }

            return payload;
        });

        if (isEdit) {
            if (hasFile) {
                form.post(`/admin/files/${file.id}`, { forceFormData: true });
            } else {
                form.put(`/admin/files/${file.id}`);
            }
            return;
        }

        form.post('/admin/files', { forceFormData: true });
    };

    const pickedFile = form.data.file instanceof File ? form.data.file : null;
    const imagePreview = isImageFile(pickedFile, file)
        ? previewUrl(pickedFile, isEdit ? file?.preview_url : null)
        : null;
    const previewLabel = pickedFile?.name || (isEdit ? file?.original_filename : '');
    const previewExtension = fileExtension(previewLabel);

    const clearFile = () => {
        form.setData('file', null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const onFileChange = (event) => {
        const selected = event.target.files?.[0] || null;
        if (selected && selected.size > uploadLimits.max_bytes) {
            form.setError('file', tooLargeMessage);
            event.target.value = '';
            form.setData('file', null);
            return;
        }
        if (selected) {
            form.clearErrors('file');
        }
        form.setData('file', selected);
    };

    return (
        <Layout>
            <PageHeader
                title={isEdit ? t('admin.files.edit', 'Edit file') : t('admin.files.add', 'Add new file')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/files', label: t('admin.files.title', 'Files') },
                    { label: isEdit ? file.name : t('admin.files.add', 'Add new file') },
                ]}
                actions={
                    isEdit ? (
                        <Tooltip content={t('admin.common.delete', 'Delete')} placement="bottom">
                            <button
                                type="button"
                                className={cx(ui.rowActionsBtn, 'text-red-600 hover:bg-red-50 hover:text-red-700')}
                                aria-label={t('admin.common.delete', 'Delete')}
                                onClick={() => {
                                    if (window.confirm(t('admin.files.delete_confirm', 'Delete this file? Product links will be removed.'))) {
                                        router.delete(`/admin/files/${file.id}`);
                                    }
                                }}
                            >
                                <Icon name="trash" className={ui.rowActionsIcon} />
                            </button>
                        </Tooltip>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout} encType="multipart/form-data">
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.files.general', 'General')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.files.name', 'Name')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('name')}
                                hasValue={hasValueForField('name')}
                                fallbackValue={fallbackForField('name')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('name')}
                                    value={valueForField('name')}
                                    onChange={(e) => setTranslationField('name', e.target.value)}
                                    placeholder={t('admin.files.name_placeholder', 'e.g. Product manual')}
                                />
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.files.description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('description')}
                                hasValue={hasValueForField('description')}
                                fallbackValue={fallbackForField('description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Textarea
                                    rows={4}
                                    value={valueForField('description')}
                                    onChange={(e) => setTranslationField('description', e.target.value)}
                                    placeholder={t('admin.files.description_placeholder', 'Short note about this file')}
                                />
                            </LocalizedField>
                        </div>
                    </section>
                </div>

                <div className={`${ui.formSide} min-w-0 max-w-full`}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>
                                {t('admin.files.file', 'File')}
                                {!isEdit ? <> <RequiredMark /></> : null}
                            </h2>
                        </div>
                        <Field className="min-w-0">
                            <div className="min-w-0 space-y-3">
                                <div className="flex justify-center">
                                    <div className="inline-flex items-center gap-2">
                                        <div className="relative inline-block">
                                        <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-100">
                                            {imagePreview ? (
                                                <img
                                                    src={imagePreview}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex flex-col items-center justify-center gap-1 px-2 text-center">
                                                    <Icon name="file" className="h-6 w-6 text-neutral-400" />
                                                    {previewExtension !== '—' ? (
                                                        <span className="text-[10px] font-semibold uppercase text-neutral-500">{previewExtension}</span>
                                                    ) : null}
                                                </div>
                                            )}
                                        </div>
                                        {pickedFile ? (
                                            <button
                                                type="button"
                                                className="absolute -right-2 -top-2 inline-flex h-6 w-6 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-600 shadow-sm hover:bg-neutral-50 hover:text-red-600"
                                                aria-label={t('admin.files.remove_selected_file', 'Remove selected file')}
                                                onClick={clearFile}
                                            >
                                                <Icon name="x" className="h-3.5 w-3.5" />
                                            </button>
                                        ) : null}
                                        </div>
                                        {isEdit && file?.download_url && !pickedFile ? (
                                            <Tooltip content={t('admin.files.download', 'Download file')} placement="top" portal>
                                                <a
                                                    href={file.download_url}
                                                    className={cx(
                                                        ui.rowActionsBtn,
                                                        'inline-flex h-8 w-8 shrink-0 items-center justify-center text-neutral-600 hover:bg-neutral-100 hover:text-violet-700',
                                                    )}
                                                    aria-label={t('admin.files.download', 'Download file')}
                                                    download
                                                >
                                                    <Icon name="download" className="h-4 w-4" />
                                                </a>
                                            </Tooltip>
                                        ) : null}
                                    </div>
                                </div>
                                {previewLabel ? (
                                    <p className={`${ui.muted} text-center text-sm break-all`}>{previewLabel}</p>
                                ) : null}

                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    className="sr-only"
                                    tabIndex={-1}
                                    aria-hidden="true"
                                    onChange={onFileChange}
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="w-full"
                                    onClick={() => fileInputRef.current?.click()}
                                >
                                    {pickedFile || (isEdit && file?.original_filename)
                                        ? t('admin.files.replace_file', 'Replace file')
                                        : t('admin.files.choose_file', 'Choose file')}
                                </Button>

                                {isEdit && !pickedFile && file?.file_size_label ? (
                                    <p className={`${ui.muted} text-center`}>
                                        {t('admin.files.current_size', 'Current size')}: {file.file_size_label}
                                    </p>
                                ) : null}
                                {form.errors.file ? (
                                    <p className={`${ui.fieldError} text-center`} role="alert">
                                        {form.errors.file}
                                    </p>
                                ) : null}
                                <p className={`${ui.fieldHint} text-center`}>
                                    {isEdit
                                        ? t('admin.files.replace_hint', 'Leave empty to keep the current file.')
                                        : t(
                                            'admin.files.upload_hint',
                                            'PDF, DOC, DOCX, XLS, XLSX, TXT, ZIP up to {max}.',
                                            { max: uploadLimits.max_label },
                                        )}
                                </p>
                            </div>
                        </Field>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {isEdit ? t('admin.common.save_changes', 'Save changes') : t('admin.files.upload', 'Upload file')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/files">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
