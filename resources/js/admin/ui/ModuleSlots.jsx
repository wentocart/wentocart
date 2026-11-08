import { Card } from './Card';
import Button from './Button';
import KpiCards from './KpiCards';
import { Table, THead, Th, Tr, Td } from './Table';
import { ui } from './classes';
import { t } from './i18n';
import { moduleLabel } from './moduleLabel';
import ExtensionIcon from './ExtensionIcon';

function buttonVariant(slot) {
    const value = slot.variant || 'primary';
    if (['primary', 'secondary', 'outline', 'ghost', 'link', 'danger'].includes(value)) {
        return value;
    }

    return 'primary';
}

function Cta({ slot }) {
    const variant = buttonVariant(slot);
    const label = (
        <>
            {slot.icon ? <ExtensionIcon icon={slot.icon} /> : null}
            {moduleLabel(slot, slot.label)}
        </>
    );

    if (slot.href) {
        const isExternal = /^https?:\/\//i.test(slot.href);
        return (
            <Button
                variant={variant}
                href={slot.href}
                native
                target={isExternal ? '_blank' : undefined}
                rel={isExternal ? 'noopener noreferrer' : undefined}
            >
                {label}
            </Button>
        );
    }

    return (
        <Button variant={variant} type="button">
            {label}
        </Button>
    );
}

export function ModuleActionButtons({ slots = [] }) {
    const buttons = (slots || []).filter((slot) => slot.type === 'button' || slot.type === 'link');
    if (!buttons.length) {
        return null;
    }

    return buttons.map((slot, index) => <Cta key={`${slot.label || 'action'}-${index}`} slot={slot} />);
}

export function ModuleSlots({ slots = [] }) {
    if (!slots.length) {
        return null;
    }

    return (
        <div className="space-y-4">
            {slots.map((slot, i) => {
                if (slot.type === 'kpis') {
                    return <KpiCards key={i} items={slot.items || []} />;
                }
                if (slot.type === 'table') {
                    return (
                        <Card key={i}>
                            <Table>
                                <THead>
                                    <tr>
                                        {(slot.columns || []).map((col) => <Th key={col.key}>{moduleLabel(col, col.label)}</Th>)}
                                    </tr>
                                </THead>
                                <tbody>
                                    {(slot.rows || []).map((row, r) => (
                                        <Tr key={r}>
                                            {(slot.columns || []).map((col) => <Td key={col.key}>{row[col.key]}</Td>)}
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Card>
                    );
                }
                if (slot.type === 'button' || slot.type === 'link') {
                    return (
                        <p key={i} className="flex flex-wrap gap-2">
                            <Cta slot={slot} />
                        </p>
                    );
                }
                if (slot.type === 'section') {
                    const title = moduleLabel(slot, slot.title);
                    const text = slot.text_key
                        ? moduleLabel({ label: slot.text, label_key: slot.text_key }, slot.text)
                        : slot.text;
                    return (
                        <div key={i} className={ui.configSection}>
                            {title ? <h3 className={ui.configSectionTitle}>{title}</h3> : null}
                            {text ? <div className={ui.configSectionBody}>{text}</div> : null}
                        </div>
                    );
                }
                if (slot.type === 'alert' || (slot.type === 'card' && (slot.variant === 'info' || slot.style === 'info'))) {
                    const title = moduleLabel(slot, slot.title);
                    const text = slot.text_key
                        ? moduleLabel({ label: slot.text, label_key: slot.text_key }, slot.text)
                        : slot.text;
                    return (
                        <div key={i} className="my-4 rounded-2xl border border-sky-200 bg-sky-50/90 p-5 shadow-sm text-sky-950">
                            {title ? (
                                <div className="flex items-center gap-2.5 font-semibold text-sky-900 text-base mb-2">
                                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-sky-200 text-sky-800 text-xs font-bold">i</span>
                                    <span>{title}</span>
                                </div>
                            ) : null}
                            {text ? (
                                <div className="text-sm leading-relaxed text-sky-800 whitespace-pre-wrap font-normal">
                                    {text}
                                </div>
                            ) : null}
                            {slot.href ? (
                                <p className="mt-3">
                                    <a className="text-sky-700 font-medium underline hover:text-sky-900" href={slot.href}>
                                        {t('admin.common.open', 'Open')}
                                    </a>
                                </p>
                            ) : null}
                        </div>
                    );
                }
                if (slot.type === 'card') {
                    const title = moduleLabel(slot, slot.title);
                    const text = slot.text_key
                        ? moduleLabel({ label: slot.text, label_key: slot.text_key }, slot.text)
                        : slot.text;
                    const body = (
                        <>
                            {slot.image ? (
                                <img src={slot.image} alt={title || ''} className="mb-3 h-40 w-full rounded-lg object-cover" />
                            ) : null}
                            {title ? <h3 className={ui.sectionTitle}>{title}</h3> : null}
                            {text ? <p className={`${ui.muted} whitespace-pre-wrap`}>{text}</p> : null}
                            {slot.href ? <p className="mt-2"><a className={ui.btnLink} href={slot.href}>{t('admin.common.open', 'Open')}</a></p> : null}
                        </>
                    );
                    return <Card key={i} className="overflow-hidden">{body}</Card>;
                }
                return slot.text ? (
                    <p key={i} className={ui.muted}>
                        {slot.text_key ? moduleLabel({ label: slot.text, label_key: slot.text_key }, slot.text) : slot.text}
                    </p>
                ) : null;
            })}
        </div>
    );
}

export function ModuleColumns({ columns = [], values = {} }) {
    return (
        <>
            {(columns || []).map((col) => (
                <Td key={col.key}>{values?.[col.key] || '—'}</Td>
            ))}
        </>
    );
}

export function ModuleColumnHeaders({ columns = [] }) {
    return (
        <>
            {(columns || []).map((col) => (
                <Th key={col.key}>{moduleLabel(col, col.label)}</Th>
            ))}
        </>
    );
}
