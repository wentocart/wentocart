import { Select, Label } from './Field';
import { FieldError } from './Alert';
import { ui, cx } from './classes';

export default function LocalizedField({
    label,
    languages = [],
    currentLanguage,
    onLanguageChange,
    error,
    hint,
    children,
    ai,
    required,
    beside = false,
}) {
    const language = languages.find((row) => row.code === currentLanguage) || languages[0] || null;
    const languageSelect = (
        <Select
            className={beside ? 'h-10 w-24 shrink-0' : ui.localizedFieldSelect}
            value={currentLanguage}
            onChange={(e) => onLanguageChange?.(e.target.value)}
        >
            {languages.map((row) => (
                <option key={row.code} value={row.code}>
                    {row.code.toUpperCase()}
                </option>
            ))}
        </Select>
    );
    const control = typeof children === 'function' ? children({ language }) : children;

    if (beside) {
        return (
            <div className={ui.localizedField}>
                {label ? <Label required={required}>{label}</Label> : null}
                <div className="flex w-full items-start gap-2">
                    <div className={cx('min-w-0 flex-1', ai && ui.aiAssistWrap)}>
                        {control}
                        {ai || null}
                    </div>
                    {languageSelect}
                </div>
                {hint ? <div className={ui.configHint}>{hint}</div> : null}
                <FieldError>{error}</FieldError>
            </div>
        );
    }

    return (
        <div className={ui.localizedField}>
            <div className={ui.localizedFieldHead}>
                <Label required={required}>{label}</Label>
                <div className={ui.localizedFieldTools}>
                    {languageSelect}
                </div>
            </div>
            <div className={ai ? ui.aiAssistWrap : undefined}>
                {control}
                {ai || null}
            </div>
            {hint ? <div className={ui.fieldHint}>{hint}</div> : null}
            <FieldError>{error}</FieldError>
        </div>
    );
}
