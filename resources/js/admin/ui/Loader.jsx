import { ui, cx } from './classes';
import Icon from './Icon';
import { t } from './i18n';

export default function Loader({ label = t('admin.common.loading', 'Loading…'), className, inline }) {
    const mark = <Icon name="spinner" className={cx('animate-spin', !inline && 'text-sky-600')} />;

    if (inline) {
        return mark;
    }

    return (
        <div className={cx(ui.loader, className)} role="status">
            {mark}
            {label}
        </div>
    );
}
