import Layout from './Layout';
import { PageHeader, ui, t } from '../ui';

export default function Placeholder({ title, hint }) {
    return (
        <Layout>
            <PageHeader
                title={title}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: title }]}
            />
            <div className={ui.cardPad}>
                <p className={ui.muted}>{hint || t('admin.placeholder.nothing_to_show_yet', 'Nothing to show yet.')}</p>
            </div>
        </Layout>
    );
}
