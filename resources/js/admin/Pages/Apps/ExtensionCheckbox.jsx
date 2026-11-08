import { useId } from 'react';
import { Icon, ui } from '../../ui';

const variants = {
    card: {
        label: ui.cardSelectLabel,
        input: ui.cardSelectInput,
        box: ui.cardSelectBox,
        mark: ui.cardSelectMark,
    },
    list: {
        label: ui.listSelectLabel,
        input: ui.listSelectInput,
        box: ui.listSelectBox,
        mark: ui.listSelectMark,
    },
};

export default function ExtensionCheckbox({ checked, onChange, ariaLabel, variant = 'card' }) {
    const id = useId();
    const styles = variants[variant] || variants.card;

    return (
        <label htmlFor={id} className={styles.label}>
            <input
                id={id}
                type="checkbox"
                className={styles.input}
                checked={!!checked}
                onChange={(event) => {
                    event.stopPropagation();
                    onChange?.(event);
                }}
                onClick={(event) => event.stopPropagation()}
                aria-label={ariaLabel}
            />
            <span className={styles.box}>
                <Icon name="check" className={styles.mark} />
            </span>
        </label>
    );
}
