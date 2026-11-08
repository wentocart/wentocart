import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Button,
    Card,
    FeatureTile,
    FormErrors,
    Input,
    PageHeader,
    Select,
    SettingsCount,
    SettingsSectionHead,
    t,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.review_settings', 'Reviews') },
];

export default function ReviewSettingsPage(props) {
    const form = useForm({
        enabled: props.enabled !== false,
        who: props.who || 'registered',
        moderation: props.moderation || 'required',
        merchant_replies: props.merchant_replies !== false,
        media: props.media || 'images',
        min_length: Number(props.min_length ?? 500),
    });

    useSaveOnChange(form, '/admin/review-settings', {
        textKeys: ['min_length'],
    });

    const body = (
            <form className="space-y-6" onSubmit={(e) => { e.preventDefault(); }}>
                <FormErrors errors={form.errors} />
                <Card>
                    <SettingsSectionHead
                        icon="star"
                        title={t('admin.review_settings.policy', 'Review policy')}
                        hint={t('admin.review_settings.policy_hint', 'Who may review products and whether staff must approve them.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="star"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.review_settings.enabled', 'Reviews')}
                            hint={t('admin.review_settings.enabled_hint', 'Shoppers can leave a rating on a product.')}
                            value={!!form.data.enabled}
                            onChange={(on) => form.setData('enabled', on)}
                            extra={(
                                <Button type="button" variant="link" href="/admin/reviews" className="mt-1 px-0">
                                    {t('admin.nav.reviews', 'Review records')}
                                </Button>
                            )}
                        />
                        <FeatureTile
                            icon="users"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.review_settings.who', 'Who can review')}
                            hint={form.data.who === 'everyone'
                                ? t('admin.review_settings.who_everyone_hint', 'Guests can review without an account. Signed-in customers can review too.')
                                : form.data.who === 'verified'
                                    ? t('admin.review_settings.who_verified_hint', 'Only a signed-in customer who bought this product can review it.')
                                    : t('admin.review_settings.who_registered_hint', 'Only a signed-in customer can review. Guests are asked to sign in.')}
                            control={(
                                <Select className="w-52 shrink-0" value={form.data.who} onChange={(e) => form.setData('who', e.target.value)}>
                                    <option value="everyone">{t('admin.review_settings.everyone', 'Guests and customers')}</option>
                                    <option value="registered">{t('admin.review_settings.registered', 'Signed-in customers')}</option>
                                    <option value="verified">{t('admin.review_settings.verified', 'Verified buyers')}</option>
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="shield"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.review_settings.moderation', 'Moderate')}
                            hint={form.data.moderation === 'automatic'
                                ? t('admin.review_settings.mod_auto_hint', 'Publish the review as soon as it is submitted. It appears on the product page immediately.')
                                : t('admin.review_settings.mod_required_hint', 'Hold the review until a staff member approves it under Customers → Reviews. Shoppers do not see it before then.')}
                            control={(
                                <Select className="w-44 shrink-0" value={form.data.moderation} onChange={(e) => form.setData('moderation', e.target.value)}>
                                    <option value="required">{t('admin.review_settings.mod_required', 'Staff must approve')}</option>
                                    <option value="automatic">{t('admin.review_settings.mod_auto', 'Publish immediately')}</option>
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="mail"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.review_settings.replies', 'Merchant replies')}
                            hint={t('admin.review_settings.replies_hint', 'Staff write the reply on Customers → Reviews. It shows under the review on the product page. A customer cannot reply as staff, even when their email matches an employee.')}
                            value={!!form.data.merchant_replies}
                            onChange={(on) => form.setData('merchant_replies', on)}
                            extra={(
                                <Button type="button" variant="link" href="/admin/reviews" className="mt-1 px-0">
                                    {t('admin.review_settings.open_replies', 'Reply on review records')}
                                </Button>
                            )}
                        />
                        <FeatureTile
                            icon="photo"
                            tone="bg-rose-100 text-rose-600"
                            title={t('admin.review_settings.media', 'Review media')}
                            control={(
                                <Select className="w-44 shrink-0" value={form.data.media} onChange={(e) => form.setData('media', e.target.value)}>
                                    <option value="none">{t('admin.review_settings.media_none', 'None')}</option>
                                    <option value="images">{t('admin.review_settings.media_images', 'Images')}</option>
                                    <option value="video">{t('admin.review_settings.media_video', 'Images and video')}</option>
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="file"
                            tone="bg-indigo-100 text-indigo-600"
                            title={t('admin.review_settings.min', 'Review length limit')}
                            hint={t('admin.review_settings.min_hint', 'The written review cannot be longer than this. A longer review is rejected. 0 hides the title and review text and leaves the star rating.')}
                            control={(
                                <Input className="w-24" type="number" min="0" value={form.data.min_length} onChange={(e) => form.setData('min_length', Number(e.target.value))} />
                            )}
                        />
                    </div>
                </Card>
            </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.review_settings.title', 'Reviews')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.review_settings.subtitle', 'Who may review products and whether staff must approve them. Review records stay under Customers.')}
            />
            {body}
        </Layout>
    );
}
