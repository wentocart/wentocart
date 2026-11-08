import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { Button, InlineForm, Input, List, ListItem, PageHeader, Select, t } from '../../ui';

export default function Coupons({ coupons }) {
    const form = useForm({ code: '', type: 'percent', value: 10, min_cart: '', expires_at: '' });

    return (
        <Layout>
            <PageHeader title={t('admin.coupons.coupons', 'Coupons')} />
            <InlineForm
                className="flex-wrap"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/coupons');
                }}
            >
                <Input placeholder={t('admin.coupons.code', 'CODE')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                    <option value="percent">{t('admin.coupons.percent', 'Percent')}</option>
                    <option value="fixed">{t('admin.coupons.fixed', 'Fixed')}</option>
                </Select>
                <Input narrow type="number" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                <Button icon="plus" busy={form.processing}>
                    {t('admin.coupons.add', 'Add')}
                </Button>
            </InlineForm>
            <List>
                {coupons.map((c) => (
                    <ListItem key={c.id}>
                        {c.code} {c.type} {c.value}
                    </ListItem>
                ))}
            </List>
        </Layout>
    );
}
