import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Badge,
    Button,
    Card,
    Field,
    FormErrors,
    Icon,
    Input,
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
import {
    FILTER_LABELS,
    INCLUDE_LABELS,
    copyText,
    groupRows,
    orderedGroupNames,
    prettyList,
    wsGroupMeta,
} from './wsShared';

function scrollToTry() {
    window.setTimeout(() => {
        const el = document.getElementById('ops-try-request');
        if (!el) {
            return;
        }
        const main = el.closest('main') || document.querySelector('main');
        if (main) {
            const top = el.getBoundingClientRect().top - main.getBoundingClientRect().top + main.scrollTop - 12;
            main.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            return;
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 50);
}

export default function Webservices({ catalog = {}, probe = null, revealed = {} }) {
    const scopeList = catalog.scopes || [];
    const endpoints = catalog.endpoints || [];
    const tryForm = useForm({
        path: '/api/v1/ws',
        token: revealed?.api_key || '',
        path_id: '',
        include: '',
        query: '',
    });
    const [copied, setCopied] = useState('');
    const [openGroups, setOpenGroups] = useState(() => new Set());

    useEffect(() => {
        if (revealed?.api_key && !tryForm.data.token) {
            tryForm.setData('token', revealed.api_key);
        }
    }, [revealed?.api_key]);

    const grouped = useMemo(() => groupRows(endpoints), [endpoints]);
    const getEndpoints = useMemo(
        () => endpoints.filter((row) => (row.methods || []).includes('GET')),
        [endpoints],
    );
    const selectedTry = getEndpoints.find((row) => row.path === tryForm.data.path) || getEndpoints[0];
    const needsPathId = String(tryForm.data.path || '').includes('{id}');

    const catalogTsv = useMemo(() => {
        const header = ['Method', 'Path', 'Scope', 'Include', 'Filters', 'Description', 'Summary'].join('\t');
        const rows = endpoints.map((row) => [
            (row.methods || []).join('|'),
            row.path,
            row.scope || '',
            (row.include || []).join(', '),
            (row.filters || []).join(', '),
            row.hint || '',
            row.summary || row.hint || '',
        ].join('\t'));
        return [header, ...rows].join('\n');
    }, [endpoints]);

    const scopeLabel = (id) => (scopeList.find((row) => row.id === id)?.label || id);

    const copy = (value, id) => {
        copyText(value);
        setCopied(id);
    };

    const toggleOpen = (group) => {
        setOpenGroups((cur) => {
            const next = new Set(cur);
            if (next.has(group)) {
                next.delete(group);
            } else {
                next.add(group);
            }
            return next;
        });
    };

    const openTry = (row) => {
        tryForm.setData({
            ...tryForm.data,
            path: row.path,
            include: '',
        });
        scrollToTry();
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.ops_tools.view_webservices', 'View Webservices')}
                description={t('admin.ops_tools.view_webservices_hint', 'These are the calls a partner app can make with a key. Open a group, then try a sample fetch at the bottom.')}
                crumbs={[
                    { href: '/admin/ops-tools?tab=keys', label: t('admin.ops_tools.title', 'Webhooks') },
                    { href: '/admin/ops-tools?tab=keys', label: t('admin.ops_tools.keys', 'Web Services') },
                    { label: t('admin.ops_tools.view_webservices', 'View Webservices') },
                ]}
                actions={(
                    <Button href="/admin/ops-tools?tab=keys" variant="secondary">
                        {t('admin.common.back', 'Back')}
                    </Button>
                )}
            />

            <div className="space-y-4">
                <Alert type="info">
                    {t('admin.ops_tools.docs_include', 'List calls always return the main fields. They can ask for extra details such as photos or categories when they need them. Large lists can be fetched a page at a time, or only records that changed after a date.')}
                </Alert>

                <div className={ui.filterBar}>
                    <Button type="button" variant="ghost" onClick={() => setOpenGroups(new Set(orderedGroupNames(grouped)))}>
                        {t('admin.ops_tools.expand_all', 'Expand all')}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setOpenGroups(new Set())}>
                        {t('admin.ops_tools.collapse_all', 'Collapse all')}
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => copy(catalogTsv, 'catalog')}>
                        {copied === 'catalog' ? t('admin.common.copied', 'Copied') : t('admin.ops_tools.copy_table', 'Copy list')}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => {
                            const blob = new Blob([catalogTsv.replaceAll('\t', ',')], { type: 'text/csv;charset=utf-8' });
                            const href = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = href;
                            a.download = 'wentocart-web-services.csv';
                            a.click();
                            URL.revokeObjectURL(href);
                        }}
                    >
                        {t('admin.ops_tools.download_csv', 'Download list')}
                    </Button>
                </div>

                <Card padded={false}>
                    {orderedGroupNames(grouped).map((group) => {
                        const meta = wsGroupMeta(group);
                        const rows = grouped[group] || [];
                        const open = openGroups.has(group);

                        return (
                            <div key={group} className="border-b border-neutral-200 last:border-b-0">
                                <button
                                    type="button"
                                    className={open ? ui.groupAccordionBtnOpen : ui.groupAccordionBtn}
                                    aria-expanded={open}
                                    onClick={() => toggleOpen(group)}
                                >
                                    <span className={ui.panelIntroMain}>
                                        <span className={`${ui.statIcon} ${meta.tone}`}>
                                            <Icon name={meta.icon} />
                                        </span>
                                        <span className={ui.cellStack}>
                                            <span className={ui.cellStrong}>{meta.label}</span>
                                            <span className={ui.cellSub}>{meta.hint}</span>
                                        </span>
                                    </span>
                                    <span className={ui.row}>
                                        <Badge tone={meta.badge} dot={false}>
                                            {rows.length}
                                            {' '}
                                            {t('admin.ops_tools.calls_count', 'Calls')}
                                        </Badge>
                                        <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                                    </span>
                                </button>
                                {open ? (
                                    <div className={ui.groupAccordionBody}>
                                        <Table>
                                            <THead>
                                                <Th>{t('admin.ops_tools.what', 'What they can do')}</Th>
                                                <Th>{t('admin.ops_tools.scope', 'Needs access to')}</Th>
                                                <Th>{t('admin.ops_tools.include', 'Extra details (optional)')}</Th>
                                                <Th></Th>
                                            </THead>
                                            <tbody>
                                                {rows.map((row) => (
                                                    <Tr key={`${(row.methods || []).join('|')}-${row.path}`}>
                                                        <Td>
                                                            {row.summary || row.hint}
                                                            {(row.filters || []).length ? (
                                                                <div className={ui.muted}>
                                                                    {t('admin.ops_tools.filters', 'They can narrow by')}: {prettyList(row.filters, FILTER_LABELS)}
                                                                </div>
                                                            ) : null}
                                                        </Td>
                                                        <Td>{scopeLabel(row.scope)}</Td>
                                                        <Td>
                                                            <div className={ui.muted}>{prettyList(row.include, INCLUDE_LABELS) || '—'}</div>
                                                        </Td>
                                                        <Td>
                                                            {(row.methods || []).includes('GET') ? (
                                                                <Button type="button" variant="secondary" icon="play" onClick={() => openTry(row)}>
                                                                    {t('admin.ops_tools.try', 'Try')}
                                                                </Button>
                                                            ) : null}
                                                        </Td>
                                                    </Tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </Card>

                <Card id="ops-try-request" className="space-y-3 scroll-mt-24">
                    <h2 className={ui.sectionTitle}>{t('admin.ops_tools.try_title', 'Try it now')}</h2>
                    <Alert type="info">
                        {t('admin.ops_tools.try_hint', 'Paste the access key you copied when you created it. We will fetch a sample from your shop so you can see what a partner app would receive. Creating or changing records is not available from this form.')}
                    </Alert>
                    <FormErrors errors={tryForm.errors} />
                    <Field label={t('admin.ops_tools.try_path', 'What to fetch')}>
                        <Select wide value={tryForm.data.path} onChange={(e) => tryForm.setData('path', e.target.value)}>
                            {getEndpoints.map((row) => (
                                <option key={row.path} value={row.path}>
                                    {row.summary || row.hint || row.path}
                                </option>
                            ))}
                        </Select>
                        {selectedTry ? <span className={ui.fieldHint}>{scopeLabel(selectedTry.scope)}</span> : null}
                    </Field>
                    {needsPathId ? (
                        <Field
                            label={t('admin.ops_tools.path_id', 'Record number')}
                            hint={t('admin.ops_tools.path_id_hint', 'The ID from Catalog → Products, Orders, or Customers.')}
                            error={tryForm.errors.path_id}
                        >
                            <Input wide value={tryForm.data.path_id} onChange={(e) => tryForm.setData('path_id', e.target.value)} placeholder="12" />
                        </Field>
                    ) : null}
                    <Field
                        label={t('admin.ops_tools.try_token', 'Access key')}
                        hint={t('admin.ops_tools.try_token_hint', 'Create a key first if you no longer have it.')}
                    >
                        <Input
                            wide
                            type="password"
                            autoComplete="off"
                            value={tryForm.data.token}
                            onChange={(e) => tryForm.setData('token', e.target.value)}
                        />
                    </Field>
                    {(selectedTry?.include || []).length ? (
                        <Field
                            label={t('admin.ops_tools.include', 'Extra details (optional)')}
                            hint={t('admin.ops_tools.try_include_hint', 'Leave blank for the main fields only. You can add extras such as photos or categories.')}
                        >
                            <Input wide value={tryForm.data.include} onChange={(e) => tryForm.setData('include', e.target.value)} />
                        </Field>
                    ) : null}
                    <Field
                        label={t('admin.ops_tools.try_query', 'Optional extras')}
                        hint={t('admin.ops_tools.try_query_hint', 'Usually leave this blank. Their developer can ask for a page of results or only records changed after a date.')}
                    >
                        <Input wide value={tryForm.data.query} onChange={(e) => tryForm.setData('query', e.target.value)} />
                    </Field>
                    <Button
                        type="button"
                        busy={tryForm.processing}
                        onClick={() => tryForm.post('/admin/ops-tools/try', { preserveScroll: true, preserveState: true })}
                    >
                        {t('admin.ops_tools.send_request', 'Fetch sample')}
                    </Button>
                    {probe ? (
                        <div className="space-y-2">
                            <Badge tone={probe.ok ? 'success' : 'danger'}>
                                {probe.ok
                                    ? t('admin.ops_tools.try_ok', 'It worked')
                                    : t('admin.ops_tools.try_fail', 'It did not work')}
                                {probe.status != null ? ` (${probe.status})` : ''}
                                {probe.ms != null ? ` · ${probe.ms}ms` : ''}
                            </Badge>
                            {probe.url ? <p className={ui.muted}>{probe.url}</p> : null}
                            <pre className="overflow-x-auto rounded-xl bg-neutral-50 p-3 text-xs text-neutral-800">
                                {typeof probe.body === 'string' ? probe.body : JSON.stringify(probe.body, null, 2)}
                            </pre>
                        </div>
                    ) : null}
                </Card>
            </div>
        </Layout>
    );
}
