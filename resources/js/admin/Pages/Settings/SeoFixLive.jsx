import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge, Button, Card, Icon, Loader, ui, t, cx } from '../../ui';

const STATUS_URL = '/admin/seo-fix/status';

function csrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function idleLive() {
    return {
        id: '',
        running: false,
        complete: false,
        error: '',
        steps: [],
        total: 0,
        fixed: 0,
        message: '',
        payload: null,
        href: '/admin/health',
        percent: 0,
        current: null,
        source: '',
    };
}

function fromRun(run) {
    if (!run) {
        return idleLive();
    }
    const status = run.status || (run.running ? 'running' : (run.complete ? 'complete' : ''));

    return {
        id: run.id || '',
        running: status === 'queued' || status === 'running' || Boolean(run.running),
        complete: status === 'complete' || Boolean(run.complete),
        error: run.error || '',
        steps: Array.isArray(run.steps) ? run.steps : [],
        total: Number(run.total || run.steps?.length || 0),
        fixed: Number(run.fixed || 0),
        message: run.message || '',
        payload: run.payload || null,
        href: run.href || ((run.source === 'seo') ? '/admin/seo' : '/admin/health'),
        percent: Number(run.percent || 0),
        current: run.current || null,
        source: run.source || '',
    };
}

function queuedLive(jobs = []) {
    return {
        ...idleLive(),
        running: true,
        steps: jobs.map((step) => ({
            id: step.id,
            title: step.title || step.id,
            count: Number(step.count || 0),
            total: Number(step.count || 0),
            done: 0,
            status: 'queued',
            message: t('admin.health.fix_queued', 'Queued'),
        })),
        total: Math.max(1, jobs.length),
    };
}

let liveState = idleLive();
const listeners = new Set();
let pollTimer = null;
let pollInFlight = false;
let dismissedId = '';
let hideTimer = null;
const HIDE_AFTER_MS = 8000;

function emitLive() {
    listeners.forEach((fn) => fn(liveState));
}

function setLiveState(next) {
    liveState = typeof next === 'function' ? next(liveState) : next;
    emitLive();
}

function applyRun(run) {
    if (!run) {
        if (liveState.running) {
            return;
        }
        setLiveState(idleLive());
        stopPoll();

        return;
    }
    const next = fromRun(run);
    if (!next.payload && liveState.payload && next.id && next.id === liveState.id) {
        next.payload = liveState.payload;
    }
    if (!next.running && dismissedId && next.id && next.id === dismissedId) {
        return;
    }
    if (next.running) {
        dismissedId = '';
        cancelHideTimer();
    }
    setLiveState(next);
    if (next.running) {
        ensurePoll();
    } else {
        stopPollSoon();
        if (next.complete || next.error) {
            scheduleHideLive();
        }
    }
}

async function pollStatus() {
    if (pollInFlight) {
        return;
    }
    pollInFlight = true;
    try {
        const response = await fetch(STATUS_URL, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (response.status === 401) {
            stopPoll();

            return;
        }
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        applyRun(data.run || null);
    } catch {
        // Keep the last snapshot; the next tick retries.
    } finally {
        pollInFlight = false;
    }
}

function ensurePoll() {
    if (pollTimer) {
        return;
    }
    pollStatus();
    pollTimer = window.setInterval(pollStatus, 1000);
}

function stopPoll() {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function stopPollSoon() {
    window.setTimeout(() => {
        if (!liveState.running) {
            stopPoll();
        }
    }, 2500);
}

function cancelHideTimer() {
    if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
    }
}

function scheduleHideLive() {
    cancelHideTimer();
    const id = liveState.id;
    hideTimer = window.setTimeout(() => {
        if (!liveState.running && liveState.id === id) {
            clearSeoFix();
        }
    }, HIDE_AFTER_MS);
}

export function clearSeoFix() {
    cancelHideTimer();
    dismissedId = liveState.id || 'done';
    stopPoll();
    setLiveState(idleLive());
}

export function jobsFromIssues(rows = []) {
    const seen = new Set();
    const jobs = [];
    rows.forEach((row) => {
        const id = row.fix;
        if (!id || seen.has(id)) {
            return;
        }
        seen.add(id);
        jobs.push({
            id,
            title: row.title || id,
            count: Number(row.count || 0),
        });
    });

    return jobs;
}

export async function startSeoFix({ url, id, jobs }) {
    setLiveState(queuedLive(jobs));
    ensurePoll();
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ id, live: 1, jobs }),
    });
    if (!response.ok) {
        throw new Error(t('admin.health.fix_failed', 'Could not start automatic fixes.'));
    }
    const data = await response.json();
    applyRun(data.run || liveState);
    ensurePoll();

    return data.run || null;
}

export function resumeSeoFix(run) {
    if (run) {
        applyRun(run);
    }
    if (liveState.running) {
        ensurePoll();
    } else if (liveState.complete && !liveState.payload) {
        pollStatus();
    }
}

export function dismissSeoFix() {
    clearSeoFix();
}

export function subscribeSeoFix(fn) {
    listeners.add(fn);
    fn(liveState);

    return () => listeners.delete(fn);
}

export function useSeoFix() {
    const [live, setLive] = useState(liveState);
    const { fixRun } = usePage().props;

    useEffect(() => subscribeSeoFix(setLive), []);
    useEffect(() => {
        resumeSeoFix(fixRun);
    }, [fixRun]);

    return live;
}

function stepTone(status) {
    if (status === 'done') {
        return 'success';
    }
    if (status === 'running') {
        return 'info';
    }
    if (status === 'error') {
        return 'danger';
    }

    return 'neutral';
}

function stepLabel(status) {
    if (status === 'done') {
        return t('admin.health.fix_done', 'Fixed');
    }
    if (status === 'running') {
        return t('admin.health.fix_working', 'Working');
    }
    if (status === 'error') {
        return t('admin.health.fix_error', 'Failed');
    }

    return t('admin.health.fix_queued', 'Queued');
}

export function issueRunStatus(live, fixId) {
    if (!live?.running && !live?.complete) {
        return null;
    }
    const step = (live.steps || []).find((row) => row.id === fixId);

    return step?.status || (live.running ? 'queued' : null);
}

function livePercent(live) {
    if (Number(live.percent || 0) > 0) {
        return Math.min(100, Number(live.percent));
    }
    const total = Math.max(1, live.total || live.steps?.length || 1);
    const finished = (live.steps || []).filter((row) => row.status === 'done' || row.status === 'error').length;
    const current = (live.steps || []).find((row) => row.status === 'running');
    const partial = current && current.total
        ? Math.min(1, Number(current.done || 0) / Number(current.total))
        : 0;

    return Math.min(100, Math.round(((finished + partial) / total) * 100));
}

export function HeaderFixProgress() {
    const live = useSeoFix();
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        if (live.running) {
            setDismissed(false);
        }
    }, [live.running]);

    if (dismissed || (!live.running && !live.complete && !live.error) || (!live.running && live.id && live.id === dismissedId)) {
        return null;
    }

    const pct = livePercent(live);
    const current = live.current || (live.steps || []).find((row) => row.status === 'running');
    const label = live.running
        ? (current?.title || t('admin.health.fix_live_title', 'Fixing store issues'))
        : (live.error
            ? t('admin.health.fix_error', 'Failed')
            : t('admin.health.fix_live_finished', 'Automatic fixes finished'));

    return (
        <>
            <div className={ui.fixProgressRail} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct}>
                <div className={ui.fixProgressFill} style={{ width: `${live.running ? Math.max(4, pct) : 100}%` }} />
            </div>
            <Link href={live.href || '/admin/health'} className={ui.fixProgressChip} title={current?.message || live.message || label}>
                {live.running ? <Icon name="spinner" className="animate-spin text-violet-700" /> : <Icon name={live.error ? 'alert-circle' : 'check-circle'} />}
                <span className="truncate">{label}</span>
                <span>{live.running ? `${pct}%` : t('admin.health.fix_done', 'Fixed')}</span>
            </Link>
            {!live.running ? (
                <button type="button" className={ui.menuBtn} onClick={() => { dismissSeoFix(); setDismissed(true); }} aria-label={t('admin.common.dismiss', 'Dismiss')}>
                    <Icon name="x" />
                </button>
            ) : null}
        </>
    );
}

export function LiveFixPanel({ live, onHistory }) {
    if (!live?.running && !live?.complete && !live?.error) {
        return null;
    }

    const total = Math.max(1, live.total || live.steps?.length || 1);
    const finished = (live.steps || []).filter((row) => row.status === 'done' || row.status === 'error').length;
    const pct = livePercent(live);
    const current = live.current || (live.steps || []).find((row) => row.status === 'running');
    const currentPct = current && current.total
        ? Math.min(100, Math.round((Number(current.done || 0) / Number(current.total)) * 100))
        : 0;

    return (
        <Card className="mb-4 space-y-3" role="status" aria-live="polite">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                        {live.running
                            ? t('admin.health.fix_live_title', 'Fixing store issues')
                            : t('admin.health.fix_live_finished', 'Automatic fixes finished')}
                    </h2>
                    <p className={cx(ui.muted, 'mt-1')}>
                        {live.running
                            ? t('admin.health.fix_live_hint', 'You can keep using admin. Progress stays in the header.')
                            : t('admin.health.fix_live_done_hint', 'This summary hides in a few seconds. Each run stays in History.')}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {live.running ? <Loader inline /> : <Badge tone={live.error ? 'danger' : 'success'}>{finished}/{total}</Badge>}
                    {!live.running ? (
                        <button type="button" className={ui.menuBtn} onClick={() => dismissSeoFix()} aria-label={t('admin.common.dismiss', 'Dismiss')}>
                            <Icon name="x" />
                        </button>
                    ) : null}
                </div>
            </div>
            <div>
                <div className="mb-1 flex justify-between text-sm text-neutral-600">
                    <span>{t('admin.health.fix_steps', 'Checks')}</span>
                    <span>{finished} / {total}</span>
                </div>
                <div className={ui.i18nProgressTrack} aria-hidden>
                    <div className={ui.i18nProgressBar} style={{ width: `${pct}%` }} />
                </div>
            </div>
            {current && current.total ? (
                <div>
                    <div className="mb-1 flex justify-between text-sm text-neutral-600">
                        <span>{current.title}</span>
                        <span>{Number(current.done || 0).toLocaleString()} / {Number(current.total).toLocaleString()}</span>
                    </div>
                    <div className={ui.i18nProgressTrack} aria-hidden>
                        <div className={ui.i18nProgressBar} style={{ width: `${currentPct}%` }} />
                    </div>
                </div>
            ) : null}
            <ul className="space-y-2">
                {(live.steps || []).map((step) => (
                    <li key={step.id} className="flex flex-wrap items-start justify-between gap-2 rounded-xl border border-neutral-100 px-3 py-2">
                        <div className="min-w-0">
                            <div className="mb-0.5 flex flex-wrap items-center gap-2">
                                {step.status === 'running' ? <Icon name="spinner" className="animate-spin text-violet-600" /> : null}
                                {step.status === 'done' ? <Icon name="check-circle" className="text-emerald-600" /> : null}
                                <span className="font-medium">{step.title}</span>
                                <Badge tone={stepTone(step.status)}>{stepLabel(step.status)}</Badge>
                            </div>
                            {step.message ? <p className={ui.muted}>{step.message}</p> : null}
                        </div>
                        {step.total ? (
                            <span className={ui.muted}>
                                {Number(step.done || 0).toLocaleString()} / {Number(step.total).toLocaleString()}
                            </span>
                        ) : null}
                    </li>
                ))}
            </ul>
            {live.error ? <p className="text-sm text-red-600">{live.error}</p> : null}
            {live.complete ? (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-medium text-emerald-700">
                        {t('admin.health.fix_records', ':count records updated.').replace(':count', Number(live.fixed || 0).toLocaleString())}
                    </p>
                    {onHistory ? (
                        <Button type="button" variant="secondary" icon="layers" onClick={onHistory}>
                            {t('admin.health.view_history', 'View this run')}
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </Card>
    );
}

export function useLiveFix() {
    const live = useSeoFix();

    const start = async ({ url, id, jobs }) => {
        try {
            return await startSeoFix({ url, id, jobs });
        } catch (error) {
            setLiveState({
                ...liveState,
                running: false,
                error: error?.message || t('admin.health.fix_failed', 'Could not start automatic fixes.'),
            });

            return null;
        }
    };

    return { live, start };
}
