import { useId, useState } from 'react';
import { Icon } from './Icons.jsx';
import { t } from '../i18n.js';

export default function StarRatingInput({
    value = 0,
    onChange,
    required = false,
    error,
    label,
    disabled = false,
}) {
    const [hover, setHover] = useState(0);
    const groupId = useId();
    const display = hover || value || 0;

    const select = (star) => {
        if (disabled) {
            return;
        }
        onChange?.(star);
    };

    const onStarKeyDown = (e, star) => {
        if (disabled) {
            return;
        }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            select(star);
            return;
        }

        let next = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
            next = Math.min(5, (value || star) + 1);
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
            next = Math.max(1, (value || star) - 1);
        } else if (e.key >= '1' && e.key <= '5') {
            next = Number(e.key);
        }

        if (next != null) {
            e.preventDefault();
            select(next);
        }
    };

    return (
        <div className={`shop-star-rating${error ? ' is-invalid' : ''}${disabled ? ' is-disabled' : ''}`}>
            {label ? (
                <span className="shop-star-rating__label" id={`${groupId}-label`}>
                    {label}
                    {required ? <span className="shop-star-rating__req shop-req-mark" aria-hidden="true"> *</span> : null}
                </span>
            ) : null}
            <div
                className="shop-star-rating__row"
                role="radiogroup"
                aria-labelledby={label ? `${groupId}-label` : undefined}
                aria-required={required || undefined}
                aria-invalid={error ? true : undefined}
                aria-disabled={disabled || undefined}
                onMouseLeave={() => setHover(0)}
            >
                {[1, 2, 3, 4, 5].map((star) => {
                    const active = star <= display;
                    return (
                        <button
                            key={star}
                            type="button"
                            role="radio"
                            aria-checked={value === star}
                            aria-label={`${star} ${star === 1 ? 'star' : 'stars'}`}
                            tabIndex={disabled ? -1 : (value === star || (!value && star === 1) ? 0 : -1)}
                            disabled={disabled}
                            className={`shop-star-rating__star${active ? ' is-active' : ''}`}
                            onMouseEnter={() => { if (!disabled) setHover(star); }}
                            onFocus={() => { if (!disabled) setHover(star); }}
                            onBlur={() => setHover(0)}
                            onClick={() => select(star)}
                            onKeyDown={(e) => onStarKeyDown(e, star)}
                        >
                            <Icon name={active ? 'star' : 'starOutline'} className="shop-star-rating__icon" />
                        </button>
                    );
                })}
                <span className="shop-star-rating__value" aria-live="polite">
                    {value > 0
                        ? `${value} ${t('storefront.shop.out_of_5', 'out of 5')}`
                        : t('storefront.shop.rating_select', 'Select a rating')}
                </span>
            </div>
            {error ? <em className="shop-field-error" role="alert">{error}</em> : null}
        </div>
    );
}
