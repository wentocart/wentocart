import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Card,
    Field,
    PageHeader,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../ui';

export default function Import({ fields = [], draft = null }) {
    const upload = useForm({ file: null, delimiter: ',' });
    const mapForm = useForm({ mapping: draft?.mapping || {} });

    return (
        <Layout>
            <PageHeader
                title={t('admin.import.title', 'Import studio')}
                description={t('admin.import.hint', 'Map → validate → preview → import. The batch is an Activity ChangeSet, so you can roll it back.')}
            />
            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="space-y-4">
                    <h2 className={ui.sectionTitle}>{t('admin.import.upload', '1. Upload CSV')}</h2>
                    <Field label={t('admin.import.file', 'File')}>
                        <input type="file" accept=".csv,text/csv" onChange={(e) => upload.setData('file', e.target.files?.[0] || null)} />
                    </Field>
                    <Field label={t('admin.import.delimiter', 'Delimiter')}>
                        <Select value={upload.data.delimiter} onChange={(e) => upload.setData('delimiter', e.target.value)}>
                            <option value=",">,</option>
                            <option value=";">;</option>
                            <option value="tab">tab</option>
                        </Select>
                    </Field>
                    <Button type="button" busy={upload.processing} onClick={() => upload.post('/admin/import-studio/upload', { forceFormData: true })}>
                        {t('admin.import.load', 'Load file')}
                    </Button>
                </Card>
                <Card className="space-y-4">
                    <h2 className={ui.sectionTitle}>{t('admin.import.map', '2. Map columns')}</h2>
                    {!draft ? (
                        <p className={ui.muted}>{t('admin.import.map_empty', 'Upload a file to map headers.')}</p>
                    ) : (
                        <>
                            {fields.map((field) => (
                                <Field key={field} label={field}>
                                    <Select
                                        wide
                                        value={mapForm.data.mapping?.[field] || ''}
                                        onChange={(e) => mapForm.setData('mapping', { ...mapForm.data.mapping, [field]: e.target.value })}
                                    >
                                        <option value="">{t('admin.import.skip', 'Skip')}</option>
                                        {(draft.headers || []).map((header) => (
                                            <option key={header} value={header}>{header}</option>
                                        ))}
                                    </Select>
                                </Field>
                            ))}
                            <Button type="button" variant="secondary" onClick={() => mapForm.post('/admin/import-studio/map')}>
                                {t('admin.import.save_map', 'Save mapping')}
                            </Button>
                        </>
                    )}
                </Card>
            </div>
            {draft?.rows?.length ? (
                <Card className="mt-4 space-y-3">
                    <h2 className={ui.sectionTitle}>{t('admin.import.preview_rows', '3. Preview rows')}</h2>
                    <Table>
                        <THead>
                            {(draft.headers || []).slice(0, 6).map((header) => <Th key={header}>{header}</Th>)}
                        </THead>
                        <tbody>
                            {draft.rows.slice(0, 8).map((row, index) => (
                                <Tr key={index}>
                                    {(draft.headers || []).slice(0, 6).map((header) => (
                                        <Td key={header}>{row[header]}</Td>
                                    ))}
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <p className={ui.muted}>{draft.rows.length} {t('admin.import.rows', 'rows')} (max 500).</p>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.post('/admin/import-studio/preview')}>
                            {t('admin.import.preview', 'Preview ChangeSet')}
                        </Button>
                        <Button type="button" onClick={() => router.post('/admin/import-studio/execute')}>
                            {t('admin.import.execute', 'Import')}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => router.post('/admin/import-studio/clear')}>
                            {t('admin.import.clear', 'Clear')}
                        </Button>
                    </div>
                </Card>
            ) : null}
        </Layout>
    );
}
