import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    PageHeader,
    Select,
    Toggle,
    ui,
} from '../../../ui';

const defaultFrequencies = [
    { value: 'hour', label: 'Hour' },
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
    { value: 'year', label: 'Year' },
];

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
    };
}

function buildTranslations(plan, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (plan?.translations || []).forEach((row) => {
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
    if (!plan?.translations?.length && plan) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: plan.name || '',
        };
    }

    return out;
}

export default function Form({ plan, frequencies = defaultFrequencies, return_to: returnTo = null }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(plan, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const backHref = returnTo || '/admin/subscription-plans';

    const form = useForm({
        name: defaultTranslation.name || '',
        translations: initialTranslations,
        is_enabled: plan?.is_enabled ?? true,
        trial_enabled: plan?.trial_enabled ?? false,
        trial_duration: plan?.trial_duration ?? 1,
        trial_cycle: plan?.trial_cycle ?? 1,
        trial_frequency: plan?.trial_frequency || 'month',
        duration: plan?.duration ?? 0,
        cycle: plan?.cycle ?? 1,
        frequency: plan?.frequency || 'month',
    });

    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
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
        if (selectedLanguage === defaultLanguage) {
            next[field] = value;
        }
        form.setData(next);
    };

    const submit = (e) => {
        e.preventDefault();
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
        if (!String(baseTranslation.name || '').trim()) {
            form.setError(`translations.${defaultLanguage}.name`, 'Plan name is required.');
            return;
        }
        if (Number(form.data.cycle) < 1) {
            form.setError('cycle', 'Billing cycle must be greater than 0.');
            return;
        }
        if (form.data.trial_enabled) {
            if (Number(form.data.trial_duration) < 1) {
                form.setError('trial_duration', 'Trial duration must be at least 1 when trial is enabled.');
                return;
            }
            if (Number(form.data.trial_cycle) < 1) {
                form.setError('trial_cycle', 'Trial cycle must be at least 1 when trial is enabled.');
                return;
            }
        }
        form.transform((data) => ({
            ...data,
            name: baseTranslation.name,
            translations,
        }));
        if (plan) {
            form.put(`/admin/subscription-plans/${plan.id}`);
        } else {
            form.post('/admin/subscription-plans');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={plan ? 'Edit subscription plan' : 'Add subscription plan'}
                crumbs={[
                    { href: '/admin', label: 'Dashboard' },
                    { href: '/admin/subscription-plans', label: 'Subscription plans' },
                    { label: plan ? 'Edit' : 'Add new' },
                ]}
                actions={
                    plan ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                const assigned = Number(plan.products_count) || 0;
                                if (assigned > 0) {
                                    window.alert(`This subscription plan is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please remove the plan from these products before deleting it.`);
                                    return;
                                }
                                if (window.confirm(`Delete “${plan.name}”?`)) {
                                    router.delete(`/admin/subscription-plans/${plan.id}`);
                                }
                            }}
                        >
                            Delete
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>General</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label="Plan name"
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('name')}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('name')}
                                    value={valueForField('name')}
                                    onChange={(e) => setTranslationField('name', e.target.value)}
                                    placeholder="e.g. Monthly"
                                />
                            </LocalizedField>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div>
                                <h2 className={ui.sectionTitle}>Trial</h2>
                                <p className={ui.muted}>Optional free period before billing starts.</p>
                            </div>
                            <Toggle
                                label="Enable trial"
                                value={!!form.data.trial_enabled}
                                onChange={(next) => form.setData('trial_enabled', next)}
                            />
                        </div>
                        {form.data.trial_enabled ? (
                            <div className={ui.fieldGrid3}>
                                <Field label="Trial duration" required error={form.errors.trial_duration} hint="How many trial periods the customer receives.">
                                    <Input
                                        wide
                                        type="number"
                                        min="0"
                                        step="1"
                                        invalid={!!form.errors.trial_duration}
                                        value={form.data.trial_duration}
                                        onChange={(e) => form.setData('trial_duration', e.target.value)}
                                    />
                                </Field>
                                <Field label="Trial cycle" required error={form.errors.trial_cycle}>
                                    <Input
                                        wide
                                        type="number"
                                        min="0"
                                        step="1"
                                        invalid={!!form.errors.trial_cycle}
                                        value={form.data.trial_cycle}
                                        onChange={(e) => form.setData('trial_cycle', e.target.value)}
                                    />
                                </Field>
                                <Field label="Trial frequency" error={form.errors.trial_frequency}>
                                    <Select
                                        wide
                                        value={form.data.trial_frequency}
                                        onChange={(e) => form.setData('trial_frequency', e.target.value)}
                                    >
                                        {frequencies.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                        ) : (
                            <p className={ui.muted}>Turn this on to offer a trial before the first payment.</p>
                        )}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div>
                                <h2 className={ui.sectionTitle}>Subscription</h2>
                                <p className={ui.muted}>Billing rules reused by any product that assigns this plan.</p>
                            </div>
                        </div>
                        <div className={ui.fieldGrid3}>
                            <Field
                                label="Duration"
                                required
                                error={form.errors.duration}
                                hint="0 means the subscription continues until cancelled. Greater than 0 ends after that many billing cycles."
                            >
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="1"
                                    invalid={!!form.errors.duration}
                                    value={form.data.duration}
                                    onChange={(e) => form.setData('duration', e.target.value)}
                                />
                            </Field>
                            <Field label="Cycle" required error={form.errors.cycle} hint="Billed every this many frequency units.">
                                <Input
                                    wide
                                    type="number"
                                    min="1"
                                    step="1"
                                    required
                                    invalid={!!form.errors.cycle}
                                    value={form.data.cycle}
                                    onChange={(e) => form.setData('cycle', e.target.value)}
                                />
                            </Field>
                            <Field label="Frequency" required error={form.errors.frequency}>
                                <Select
                                    wide
                                    value={form.data.frequency}
                                    onChange={(e) => form.setData('frequency', e.target.value)}
                                >
                                    {frequencies.map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>Status</h2>
                        </div>
                        <Checkbox
                            label="Enabled"
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>Disabled plans cannot be newly assigned to products. Existing assignments are kept.</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {plan ? 'Save changes' : 'Create subscription plan'}
                            </Button>
                            <Button type="button" variant="secondary" href={backHref}>
                                Cancel
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
