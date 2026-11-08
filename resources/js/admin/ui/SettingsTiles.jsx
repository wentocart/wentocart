import { FieldError } from './Alert';
import Badge from './Badge';
import { Toggle } from './Field';
import Icon from './Icon';
import { ui, cx } from './classes';

function firstMessage(error) {
    if (error == null || error === false || error === '') {
        return null;
    }
    if (Array.isArray(error)) {
        return error.find(Boolean) || null;
    }
    if (typeof error === 'object') {
        return null;
    }

    return error;
}

function SettingsSectionHead({ icon, title, hint, extra }) {
    return (
        <div className="mb-5 flex items-start justify-between gap-3">
            <div className="flex min-w-0 items-start gap-3">
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-violet-600 text-white">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <h2 className={ui.sectionTitle}>{title}</h2>
                    {hint ? <p className={cx(ui.muted, 'mt-0.5')}>{hint}</p> : null}
                </div>
            </div>
            {extra}
        </div>
    );
}

function FeatureTile({ icon, mark, tone, title, hint, error, value, onChange, disabled, extra, control, children, className, fill = false }) {
    const errorMessage = firstMessage(error);

    return (
        <div className={cx('rounded-xl border border-neutral-200 bg-white px-4 py-3.5', fill ? 'flex min-h-0 flex-col self-stretch' : 'self-start', className)}>
            <div className={cx('flex gap-3', extra || errorMessage ? 'items-start' : 'items-center')}>
                {mark ? (
                    <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-white">
                        {mark}
                    </span>
                ) : (
                    <span className={cx('grid h-11 w-11 shrink-0 place-items-center rounded-xl', tone)}>
                        <Icon name={icon} className="h-5 w-5" />
                    </span>
                )}
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-neutral-900">{title}</div>
                    {hint ? <p className="mt-0.5 text-xs leading-5 text-neutral-500">{hint}</p> : null}
                    {extra}
                </div>
                {control ?? (onChange ? (
                    <Toggle value={value} onChange={onChange} disabled={disabled} ariaLabel={title} />
                ) : null)}
            </div>
            {errorMessage ? <FieldError>{errorMessage}</FieldError> : null}
            {children ? <div className={cx('mt-3 border-t border-neutral-100 pt-3', fill ? 'flex min-h-0 flex-1 flex-col' : 'space-y-3')}>{children}</div> : null}
        </div>
    );
}

function SettingsNote({ children }) {
    return (
        <div className={cx(ui.softAlert, 'mt-4')} role="status">
            <Icon name="info" className="mt-0.5 h-4 w-4 shrink-0 text-violet-600" />
            <div>{children}</div>
        </div>
    );
}

function SettingsCount({ children }) {
    return (
        <Badge tone="primary" dot={false}>
            {children}
        </Badge>
    );
}

export { SettingsSectionHead, FeatureTile, SettingsNote, SettingsCount };
