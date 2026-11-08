import { ui, cx } from './classes';
import { t } from './i18n';

/**
 * Selectable type cards (shipping rate, payment driver, etc.).
 * options: [{ value, label, label_key?, hint?, hint_key? }]
 */
export default function ChoiceCards({ options = [], value, onChange, className, name = 'choice' }) {
    return (
        <div className={cx(ui.choiceGrid, className)} role="radiogroup" aria-label={name}>
            {options.map((opt) => {
                const active = value === opt.value;
                const label = opt.label_key ? t(opt.label_key, opt.label) : opt.label;
                const hint = opt.hint
                    ? (opt.hint_key ? t(opt.hint_key, opt.hint) : opt.hint)
                    : null;
                return (
                    <button
                        key={opt.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        className={active ? ui.choiceCardActive : ui.choiceCard}
                        onClick={() => onChange?.(opt.value)}
                    >
                        <div className={ui.choiceTitle}>{label}</div>
                        {hint ? <div className={ui.choiceHint}>{hint}</div> : null}
                    </button>
                );
            })}
        </div>
    );
}
