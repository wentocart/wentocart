import { router, useForm, usePage } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Button, Field, FormErrors, PageHeader, Select, Table, THead, Th, Tr, Td, ui, t } from '../../ui';

export default function ImportExport({ packs = [], batches = [] }) {
    const { flash } = usePage().props;
    const form = useForm({ type: 'countries', file: null });
    const activeBatchId = flash?.import_batch_id;
    const activeBatch = batches.find((b) => b.id === activeBatchId) || batches.find((b) => b.status === 'pending');

    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/localization/import', { forceFormData: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.import_export.import_export', 'Import / Export')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.import_export.localization', 'Localization') },
                    { label: t('admin.import_export.import_export', 'Import / Export') },
                ]}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t('admin.import_export.export_csv_snapshots_or_stage_a_csv_for_review_b', 'Export CSV snapshots, or stage a CSV for review before applying. Max 500 rows per upload.')}
            </p>

            <section className={`${ui.sectionCard} mb-5`}>
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{t('admin.import_export.export', 'Export')}</h2>
                </div>
                <div className={ui.toolbar}>
                    <Button href="/admin/localization/export?type=countries" icon="download">{t('admin.import_export.export_countries_csv', 'Export countries CSV')}</Button>
                    <Button href="/admin/localization/export?type=currencies" variant="secondary" icon="download">{t('admin.import_export.export_currencies_csv', 'Export currencies CSV')}</Button>
                </div>
            </section>

            <section className={`${ui.sectionCard} mb-5`}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{t('admin.import_export.stage_csv_import', 'Stage CSV import')}</h2>
                        <p className={ui.muted}>{t('admin.import_export.upload_preview_apply_does_not_auto_write_until_y', 'Upload → preview → apply. Does not auto-write until you confirm.')}</p>
                    </div>
                </div>
                <form onSubmit={submit} className="grid gap-3 max-w-xl">
                    <FormErrors errors={form.errors} />
                    <Field label={t('admin.import_export.type', 'Type')}>
                        <Select wide value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="countries">{t('admin.import_export.countries', 'Countries')}</option>
                            <option value="currencies">{t('admin.import_export.currencies', 'Currencies')}</option>
                        </Select>
                    </Field>
                    <Field label={t('admin.import_export.csv_file', 'CSV file')}>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            className={ui.input}
                            onChange={(e) => form.setData('file', e.target.files?.[0] || null)}
                        />
                    </Field>
                    <Button icon="upload" busy={form.processing}>{t('admin.import_export.stage_import', 'Stage import')}</Button>
                </form>
            </section>

            {activeBatch && activeBatch.status === 'pending' ? (
                <section className={`${ui.sectionCard} mb-5`}>
                    <div className={ui.sectionHead}>
                        <div>
                            <h2 className={ui.sectionTitle}>Pending batch #{activeBatch.id}</h2>
                            <p className={ui.muted}>{activeBatch.filename} · {activeBatch.row_count} rows · {activeBatch.type}</p>
                        </div>
                        <Button
                            type="button"
                            onClick={() => router.post(`/admin/localization/import/${activeBatch.id}/apply`, {}, { preserveScroll: true })}
                        >
                            {t('admin.import_export.apply_import', 'Apply import')}
                        </Button>
                    </div>
                    <Table>
                        <THead>
                            <tr>
                                {Object.keys(activeBatch.preview?.[0] || { code: '', name: '' }).slice(0, 6).map((k) => (
                                    <Th key={k}>{k}</Th>
                                ))}
                            </tr>
                        </THead>
                        <tbody>
                            {(activeBatch.preview || []).slice(0, 15).map((row, i) => (
                                <Tr key={i}>
                                    {Object.keys(activeBatch.preview?.[0] || {}).slice(0, 6).map((k) => (
                                        <Td key={k}>{String(row[k] ?? '')}</Td>
                                    ))}
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    {(activeBatch.preview || []).length > 15 ? (
                        <p className={`${ui.muted} mt-2`}>Showing first 15 of {activeBatch.row_count} rows.</p>
                    ) : null}
                </section>
            ) : null}

            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{t('admin.import_export.recent_batches', 'Recent batches')}</h2>
                </div>
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.import_export.id', 'ID')}</Th>
                            <Th>{t('admin.import_export.type', 'Type')}</Th>
                            <Th>{t('admin.import_export.file', 'File')}</Th>
                            <Th>{t('admin.import_export.rows', 'Rows')}</Th>
                            <Th>{t('admin.import_export.status', 'Status')}</Th>
                            <Th>{t('admin.import_export.result', 'Result')}</Th>
                        </tr>
                    </THead>
                    <tbody>
                        {batches.map((b) => (
                            <Tr key={b.id}>
                                <Td>#{b.id}</Td>
                                <Td>{b.type}</Td>
                                <Td>{b.filename || '—'}</Td>
                                <Td>{b.row_count}</Td>
                                <Td><Badge tone={b.status === 'pending' ? 'warning' : 'success'}>{b.status}</Badge></Td>
                                <Td>
                                    {b.result
                                        ? `+${b.result.created || 0} / ~${b.result.updated || 0}`
                                        : '—'}
                                </Td>
                            </Tr>
                        ))}
                        {batches.length === 0 ? (
                            <Tr><Td colSpan={6}><span className={ui.muted}>{t('admin.import_export.no_imports_yet', 'No imports yet.')}</span></Td></Tr>
                        ) : null}
                    </tbody>
                </Table>
            </section>

            <section className={`${ui.sectionCard} mt-5`}>
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{t('admin.import_export.built_in_packs', 'Built-in packs')}</h2>
                </div>
                <p className={ui.muted}>
                    Prefer one-click packs for India/US datasets ({packs.length} available).{' '}
                    <Button variant="secondary" href="/admin/localization/packs">{t('admin.import_export.open_packs', 'Open packs')}</Button>
                </p>
            </section>
        </Layout>
    );
}
