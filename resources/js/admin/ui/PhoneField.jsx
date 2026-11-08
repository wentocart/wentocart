import { Field, Input, Select } from './Field';
import { ui, cx } from './classes';
import { t } from './i18n';

export const PHONE_CODES = ['+91', '+1', '+44', '+971'];

/** Split stored "+91 9876543210" (or bare digits) into code + national number. */
export function parsePhone(phone) {
    const raw = String(phone || '').trim();
    if (!raw) {
        return { phone_code: '+91', phone: '' };
    }
    const match = PHONE_CODES.find((code) => raw === code || raw.startsWith(`${code} `) || raw.startsWith(code));
    if (match) {
        const rest = raw.slice(match.length).trim();
        return { phone_code: match, phone: rest.replace(/\D/g, '') };
    }
    return { phone_code: '+91', phone: raw.replace(/\D/g, '') };
}

/** Digits only for the national part. */
export function sanitizePhoneNational(value) {
    return String(value || '').replace(/\D/g, '').slice(0, 15);
}

/** Build storage value: "+91 9876543210" or empty. */
export function formatPhone(phoneCode, national) {
    const digits = sanitizePhoneNational(national);
    if (!digits) {
        return '';
    }
    return `${phoneCode || '+91'} ${digits}`;
}

/**
 * Optional field: empty is OK.
 * If filled, national number must be 6–15 digits.
 */
export function isValidPhoneNational(national, { required = false } = {}) {
    const digits = sanitizePhoneNational(national);
    if (!digits) {
        return !required;
    }
    return digits.length >= 6 && digits.length <= 15;
}

export function phoneValidationMessage(national, { required = false } = {}) {
    const digits = sanitizePhoneNational(national);
    if (!digits) {
        return required
            ? t('admin.common.phone_required', 'Phone number is required.')
            : '';
    }
    if (digits.length < 6 || digits.length > 15) {
        return t('admin.common.phone_invalid', 'Enter a valid phone number (6–15 digits).');
    }
    return '';
}

/**
 * Shared phone UI: country code select + national number input.
 * Stores parts separately via onCodeChange / onPhoneChange; parent combines on save with formatPhone().
 */
export default function PhoneField({
    label = t('admin.common.phone', 'Phone'),
    phoneCode = '+91',
    phone = '',
    onCodeChange,
    onPhoneChange,
    error,
    hint,
    required = false,
    disabled = false,
    className,
}) {
    const message = error || '';

    return (
        <Field
            label={label}
            error={message}
            required={required}
            hint={hint || t('admin.common.phone_hint', 'Digits only, 6–15 characters.')}
            className={className}
        >
            <div className={cx(ui.row)}>
                <Select
                    value={phoneCode || '+91'}
                    disabled={disabled}
                    invalid={!!message}
                    onChange={(e) => onCodeChange?.(e.target.value)}
                    aria-label={t('admin.common.phone_code', 'Country code')}
                >
                    {PHONE_CODES.map((code) => (
                        <option key={code} value={code}>{code}</option>
                    ))}
                </Select>
                <Input
                    grow
                    inputMode="numeric"
                    autoComplete="tel-national"
                    disabled={disabled}
                    invalid={!!message}
                    value={phone}
                    placeholder={t('admin.common.enter_phone_number', 'Enter phone number')}
                    onChange={(e) => onPhoneChange?.(sanitizePhoneNational(e.target.value))}
                />
            </div>
        </Field>
    );
}
