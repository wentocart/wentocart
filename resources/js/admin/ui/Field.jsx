import { forwardRef } from 'react';
import { ui, cx } from './classes';
import { FieldError } from './Alert';
import { t } from './i18n';
import { integerInputValue, normalizeIntegerInput } from './integerInput';

/** Red asterisk for required field labels (Orders / Catalog pattern). */
export function RequiredMark({ className }) {
    return (
        <span className={cx(ui.req, '!text-red-600', className)} aria-hidden>
            *
        </span>
    );
}

/** Prepend a red * before a label (required config rows). */
export function withLeadingRequiredMark(label, required = false) {
    if (!required || label == null || label === false) {
        return label;
    }

    if (typeof label === 'string') {
        const text = label.replace(/^\s*\*\s*/, '').replace(/\s*\*\s*$/, '').trim();

        return (
            <>
                <RequiredMark /> {text}
            </>
        );
    }

    if (labelAlreadyHasRequiredMark(label)) {
        return label;
    }

    return (
        <>
            <RequiredMark /> {label}
        </>
    );
}

/** Append a red * after a label; strip a trailing * so it is never duplicated. */
export function withRequiredMark(label, required = false) {
    if (!required || label == null || label === false) {
        return label;
    }

    if (typeof label === 'string') {
        const text = label.replace(/\s*\*\s*$/, '').trimEnd();

        return (
            <>
                {text} <RequiredMark />
            </>
        );
    }

    if (labelAlreadyHasRequiredMark(label)) {
        return label;
    }

    return (
        <>
            {label} <RequiredMark />
        </>
    );
}

export function withOptionalMark(label, optional = false) {
    if (!optional || label == null || label === false) {
        return label;
    }

    const mark = <span className={ui.opt}>{t('admin.common.optional', '(Optional)')}</span>;

    return (
        <>
            {label} {mark}
        </>
    );
}

function labelAlreadyHasRequiredMark(node) {
    if (node == null || typeof node === 'boolean') {
        return false;
    }

    if (typeof node === 'string' || typeof node === 'number') {
        return /\*\s*$/.test(String(node));
    }

    if (Array.isArray(node)) {
        return node.some(labelAlreadyHasRequiredMark);
    }

    if (typeof node === 'object' && node.props) {
        if (node.type === RequiredMark) {
            return true;
        }

        const className = String(node.props.className || '');
        const child = node.props.children;
        if (String(child).trim() === '*' && (className.includes(ui.req) || className.includes('text-red'))) {
            return true;
        }

        return labelAlreadyHasRequiredMark(child);
    }

    return false;
}

export function Label({ children, className, required, optional, ...props }) {
    const marked = required
        ? withRequiredMark(children, true)
        : withOptionalMark(children, optional);

    return (
        <label className={cx(ui.label, className)} {...props}>
            {marked}
        </label>
    );
}

export const Input = forwardRef(function Input({ className, wide, grow, compact, narrow, unstyled, invalid, ...props }, ref) {
    const base = unstyled ? '' : compact ? ui.inputSm : narrow ? ui.inputNarrow : wide ? ui.inputFull : grow ? ui.inputGrow : ui.input;

    return <input ref={ref} className={cx(base, invalid && ui.inputInvalid, className)} aria-invalid={invalid || undefined} {...props} />;
});

/**
 * Integer settings input that keeps the displayed value free of leading zeros.
 * onValueChange receives number | '' (empty while the field is cleared).
 */
export const IntegerInput = forwardRef(function IntegerInput({
    className,
    wide,
    grow,
    compact,
    narrow,
    unstyled,
    invalid,
    value,
    onValueChange,
    onChange,
    ...props
}, ref) {
    return (
        <Input
            ref={ref}
            type="number"
            className={className}
            wide={wide}
            grow={grow}
            compact={compact}
            narrow={narrow}
            unstyled={unstyled}
            invalid={invalid}
            {...props}
            value={integerInputValue(value)}
            onChange={(event) => {
                const next = normalizeIntegerInput(event.target.value);
                onValueChange?.(next);
                onChange?.(event, next);
            }}
        />
    );
});

export function Select({ className, wide, tall, filter, invalid, children, ...props }) {
    const base = filter ? ui.filterSelect : tall ? ui.selectTall : wide ? ui.selectFull : ui.select;

    return (
        <select className={cx(base, filter && wide && 'w-full', invalid && ui.inputInvalid, className)} aria-invalid={invalid || undefined} {...props}>
            {children}
        </select>
    );
}

export function Textarea({ className, invalid, ...props }) {
    return <textarea className={cx(ui.textarea, invalid && ui.inputInvalid, className)} aria-invalid={invalid || undefined} {...props} />;
}

export function Checkbox({ label, className, size, ...props }) {
    return (
        <label className={cx(ui.checkboxRow, size === 'lg' && 'items-start', className)}>
            <input type="checkbox" className={size === 'lg' ? ui.checkLg : ui.check} {...props} />
            {label}
        </label>
    );
}

export function Toggle({ value, onChange, label, error, hint, disabled, className, inline, ariaLabel }) {
    const on = !!value;
    const switchBtn = (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={ariaLabel || (typeof label === 'string' ? label : undefined)}
            disabled={disabled}
            className={cx(ui.toggle, on ? ui.toggleOn : ui.toggleOff, disabled && ui.toggleDisabled, inline && '!mt-0')}
            onClick={() => {
                if (!disabled) {
                    onChange?.(!on);
                }
            }}
        >
            <span className={cx(ui.toggleKnob, on ? ui.toggleKnobOn : ui.toggleKnobOff)} />
        </button>
    );

    if (inline) {
        return (
            <div className={cx(ui.configRow, className)}>
                <div className={ui.configLabelCol}>
                    <div className={ui.configLabelAlign}>
                        {label ? <span className={ui.configLabel}>{label}</span> : null}
                    </div>
                </div>
                <div className={ui.configFieldCol}>
                    <div className={ui.configControlMain}>{switchBtn}</div>
                    {hint ? <span className={ui.configHint}>{hint}</span> : null}
                    <FieldError>{error}</FieldError>
                </div>
            </div>
        );
    }

    return (
        <div className={className}>
            <div className={ui.toggleRow}>
                {switchBtn}
                {label || hint ? (
                    <div className={ui.toggleCopy}>
                        {label ? <span className={ui.label}>{label}</span> : null}
                        {hint ? <span className={ui.fieldHint}>{hint}</span> : null}
                    </div>
                ) : null}
            </div>
            <FieldError>{error}</FieldError>
        </div>
    );
}

export function Field({ label, className, error, hint, children, required, optional, inline, alignTop }) {
    if (inline) {
        const labelAlign = alignTop ? ui.configLabelAlignTop : ui.configLabelAlign;
        const controlMain = alignTop ? ui.configControlMainTop : ui.configControlMain;
        const labelNode = required
            ? withLeadingRequiredMark(label, true)
            : withOptionalMark(label, optional);

        return (
            <div className={cx(ui.configRow, className)}>
                <div className={ui.configLabelCol}>
                    <div className={labelAlign}>
                        {label ? <span className={ui.configLabel}>{labelNode}</span> : null}
                    </div>
                </div>
                <div className={ui.configFieldCol}>
                    <div className={controlMain}>{children}</div>
                    {hint ? <span className={ui.configHint}>{hint}</span> : null}
                    <FieldError>{error}</FieldError>
                </div>
            </div>
        );
    }

    return (
        <div className={cx('space-y-1', className)}>
            {label ? <Label required={required} optional={optional}>{label}</Label> : null}
            {children}
            {hint ? <span className={ui.fieldHint}>{hint}</span> : null}
            <FieldError>{error}</FieldError>
        </div>
    );
}
