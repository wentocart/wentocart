import { useEffect, useMemo, useState } from 'react';

function flag(code = '') {
    return String(code).toUpperCase().replace(/[A-Z]/g, (letter) => String.fromCodePoint(letter.charCodeAt(0) + 127397));
}

function normalizeCallingCode(value) {
    const raw = String(value || '').trim();
    return raw ? (raw.startsWith('+') ? raw : `+${raw}`) : '';
}

export default function PhoneField({ value = '', onChange, countries = [], required = false, disabled = false, className = '', name }) {
    const activeCountries = useMemo(() => (countries || []).filter((country) => country?.code && country?.calling_code), [countries]);
    const fallback = activeCountries[0] || { code: 'US', name: 'United States', calling_code: '+1' };
    const [countryCode, setCountryCode] = useState(fallback.code);
    const [localValue, setLocalValue] = useState(String(value || ''));

    useEffect(() => {
        const raw = String(value || '').trim();
        const match = activeCountries
            .map((country) => ({ ...country, dial: normalizeCallingCode(country.calling_code) }))
            .sort((a, b) => b.dial.length - a.dial.length)
            .find((country) => raw.startsWith(country.dial));
        if (match) {
            setCountryCode(match.code);
            setLocalValue(raw.slice(match.dial.length).replace(/^\s+/, ''));
        } else {
            setLocalValue(raw);
        }
    }, [value, activeCountries]);

    const country = activeCountries.find((row) => row.code === countryCode) || fallback;
    const emit = (nextCountry, nextLocal) => {
        const digits = String(nextLocal || '').replace(/\D/g, '');
        if (!digits) {
            onChange?.('');
            return;
        }
        const dial = normalizeCallingCode(nextCountry?.calling_code || country.calling_code);
        onChange?.(`${dial} ${digits}`.trim());
    };

    return (
        <div className={`shop-phone-field ${className}`}>
            <span className="shop-phone-country-wrap">
                <select
                    className="shop-phone-country"
                    value={country.code}
                    onChange={(event) => {
                        const next = activeCountries.find((row) => row.code === event.target.value) || fallback;
                        setCountryCode(next.code);
                        emit(next, localValue);
                    }}
                    aria-label="Country calling code"
                    disabled={disabled}
                >
                    {activeCountries.length ? activeCountries.map((row) => (
                        <option key={row.code} value={row.code}>
                            {flag(row.code)} {normalizeCallingCode(row.calling_code)}
                        </option>
                    )) : <option value={fallback.code}>{flag(fallback.code)} {fallback.calling_code}</option>}
                </select>
            </span>
            <input
                className="shop-phone-number"
                type="tel"
                name={name}
                value={localValue}
                inputMode="numeric"
                pattern="[0-9]*"
                onChange={(event) => {
                    const digits = event.target.value.replace(/\D/g, '');
                    setLocalValue(digits);
                    emit(country, digits);
                }}
                required={required}
                disabled={disabled}
                autoComplete="tel-national"
            />
        </div>
    );
}
