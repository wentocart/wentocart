import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Card,
    BrandMark,
    FeatureTile,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    StatusTabs,
    ui,
    t,
} from '../../ui';
import { CUSTOMER_CLUB_TABS } from './clubTabs';
import ReviewSettingsPage from './ReviewSettings';
import { useSaveOnChange } from './useSaveOnChange';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.customer_settings', 'Customers') },
];

function FieldModeSelect({ value, onChange, includeCountryRules = false, disabled = false }) {
    return (
        <Select className="w-40 shrink-0" value={value} onChange={onChange} disabled={disabled}>
            {includeCountryRules ? <option value="country_rules">{t('admin.customer_settings.mode_country', 'Country rules')}</option> : null}
            <option value="required">{t('admin.customer_settings.mode_required', 'Required')}</option>
            <option value="optional">{t('admin.customer_settings.mode_optional', 'Optional')}</option>
            {!includeCountryRules ? <option value="hidden">{t('admin.customer_settings.mode_hidden', 'Hidden')}</option> : null}
        </Select>
    );
}

export default function Customers({
    accounts = {},
    groups = {},
    addresses = {},
    groupOptions = [],
    social = {},
    tab = 'accounts',
    reviewSettings = {},
}) {
    const form = useForm({
        registration_enabled: accounts.registration_enabled !== false,
        guest_checkout: accounts.guest_checkout !== false,
        email_verification: accounts.email_verification || 'optional',
        account_activation: accounts.account_activation || 'automatic',
        default_group_code: accounts.default_group_code || groups.default_group_code || 'retailer',
        allow_account_deletion: !!accounts.allow_account_deletion,
        allow_profile_editing: accounts.allow_profile_editing !== false,
        phone_mode: accounts.phone_mode || 'optional',
        dob_mode: accounts.dob_mode || 'hidden',
        newsletter_opt_in: accounts.newsletter_opt_in !== false,
        groups_enabled: groups.groups_enabled !== false,
        group_pricing: !!groups.group_pricing,
        group_discounts: groups.group_discounts !== false,
        require_billing: addresses.require_billing !== false,
        require_shipping: addresses.require_shipping !== false,
        billing_same_default: addresses.billing_same_default !== false,
        allow_multiple: addresses.allow_multiple !== false,
        max_saved: addresses.max_saved ?? '',
        address_phone_mode: addresses.phone_mode || 'optional',
        address_company_mode: addresses.company_mode || 'optional',
        address_tax_id_mode: addresses.tax_id_mode || 'hidden',
        address_line2_mode: addresses.line2_mode || 'optional',
        address_state_mode: addresses.state_mode || 'country_rules',
        address_postal_mode: addresses.postal_mode || 'country_rules',
        google_enabled: !!social.google?.enabled,
        google_client_id: social.google?.client_id || '',
        google_client_secret: '',
        facebook_enabled: !!social.facebook?.enabled,
        facebook_client_id: social.facebook?.client_id || '',
        facebook_client_secret: '',
        linkedin_enabled: !!social.linkedin?.enabled,
        linkedin_client_id: social.linkedin?.client_id || '',
        linkedin_client_secret: '',
    });

    const goTab = (key) => {
        router.get('/admin/customer-settings', { tab: key }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const { saveDeferred } = useSaveOnChange(form, `/admin/customer-settings?tab=${encodeURIComponent(tab)}`, {
        textKeys: ['google_client_id', 'facebook_client_id', 'linkedin_client_id', 'max_saved'],
        deferKeys: ['google_client_secret', 'facebook_client_secret', 'linkedin_client_secret'],
        transform: (data) => ({
            ...data,
            max_saved: data.max_saved === '' || data.max_saved === null ? null : Number(data.max_saved),
        }),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.customer_settings.title', 'Customers')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.customer_settings.subtitle', 'Global account, group, and address behavior for the whole store.')}
            />

            <StatusTabs
                className="mb-4 bg-white rounded-xl border border-neutral-200 px-2"
                tabs={CUSTOMER_CLUB_TABS}
                value={tab}
                onChange={goTab}
            />

            {tab === 'reviews' ? <ReviewSettingsPage embedded {...reviewSettings} /> : (
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                }}
            >
                <FormErrors errors={form.errors} />

                {tab === 'accounts' ? (
                    <div className="space-y-4">
                        <Card>
                            <SettingsSectionHead
                                icon="user"
                                title={t('admin.customer_settings.panel_registration', 'Account registration')}
                                hint={t('admin.customer_settings.panel_registration_hint', 'Who can create an account and how new accounts start.')}
                                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="user"
                                    tone="bg-violet-100 text-violet-600"
                                    title={t('admin.customer_settings.registration_enabled', 'Allow customer registration')}
                                    hint={t('admin.customer_settings.registration_enabled_hint', 'Shoppers can create an account from the register page.')}
                                    value={!!form.data.registration_enabled}
                                    onChange={(on) => form.setData('registration_enabled', on)}
                                />
                                <FeatureTile
                                    icon="bag"
                                    tone="bg-orange-100 text-orange-600"
                                    title={t('admin.customer_settings.guest_checkout', 'Allow guest checkout')}
                                    hint={t('admin.customer_settings.guest_checkout_hint', 'When off, shoppers still open checkout. They enter an email and password so the shop can create their account, save the address, and then place the order. If registration is also off, checkout explains that an account is required.')}
                                    value={!!form.data.guest_checkout}
                                    onChange={(on) => form.setData('guest_checkout', on)}
                                />
                                <FeatureTile
                                    icon="mail"
                                    tone="bg-sky-100 text-sky-600"
                                    title={t('admin.customer_settings.email_verification', 'Email verification')}
                                    hint={t('admin.customer_settings.email_verification_hint', 'Whether a new account must confirm its email.')}
                                    control={(
                                        <Select className="w-40 shrink-0" value={form.data.email_verification} onChange={(e) => form.setData('email_verification', e.target.value)}>
                                            <option value="required">{t('admin.customer_settings.verify_required', 'Required')}</option>
                                            <option value="optional">{t('admin.customer_settings.verify_optional', 'Optional')}</option>
                                            <option value="disabled">{t('admin.customer_settings.verify_disabled', 'Disabled')}</option>
                                        </Select>
                                    )}
                                />
                                <FeatureTile
                                    icon="shield"
                                    tone="bg-emerald-100 text-emerald-600"
                                    title={t('admin.customer_settings.account_activation', 'Account activation')}
                                    hint={t('admin.customer_settings.account_activation_hint', 'Automatic accounts can sign in at once. Admin approval holds them.')}
                                    control={(
                                        <Select className="w-40 shrink-0" value={form.data.account_activation} onChange={(e) => form.setData('account_activation', e.target.value)}>
                                            <option value="automatic">{t('admin.customer_settings.activation_auto', 'Automatic')}</option>
                                            <option value="admin_approval">{t('admin.customer_settings.activation_admin', 'Admin approval')}</option>
                                        </Select>
                                    )}
                                />
                            </div>
                            <SettingsNote>
                                {t('admin.customer_settings.accounts_note', 'You can change these settings anytime. They apply to registration and checkout for the whole store.')}
                            </SettingsNote>
                        </Card>
                        <Card>
                            <SettingsSectionHead
                                icon="settings"
                                title={t('admin.customer_settings.panel_profile', 'Customer information')}
                                hint={t('admin.customer_settings.panel_profile_hint', 'Profile fields, editing, and account self-service.')}
                                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="pencil"
                                    tone="bg-sky-100 text-sky-600"
                                    title={t('admin.customer_settings.allow_profile_editing', 'Allow customer profile editing')}
                                    hint={t('admin.customer_settings.allow_profile_editing_hint', 'Signed-in customers can update their name and contact details.')}
                                    value={!!form.data.allow_profile_editing}
                                    onChange={(on) => form.setData('allow_profile_editing', on)}
                                />
                                <FeatureTile
                                    icon="trash"
                                    tone="bg-rose-100 text-rose-600"
                                    title={t('admin.customer_settings.allow_account_deletion', 'Allow customer account deletion')}
                                    hint={t('admin.customer_settings.allow_account_deletion_hint', 'When GDPR is off, customers can delete the account from Profile. When GDPR is on, they send a Delete request under GDPR Requests, and you delete the account from the customer page after the request is completed.')}
                                    value={!!form.data.allow_account_deletion}
                                    onChange={(on) => form.setData('allow_account_deletion', on)}
                                />
                                <FeatureTile
                                    icon="phone"
                                    tone="bg-teal-100 text-teal-600"
                                    title={t('admin.customer_settings.phone_mode', 'Phone number')}
                                    hint={t('admin.customer_settings.phone_mode_hint', 'Show, require, or hide the phone field on the account.')}
                                    control={<FieldModeSelect value={form.data.phone_mode} onChange={(e) => form.setData('phone_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="calendar"
                                    tone="bg-indigo-100 text-indigo-600"
                                    title={t('admin.customer_settings.dob_mode', 'Date of birth')}
                                    hint={t('admin.customer_settings.dob_mode_hint', 'Show, require, or hide date of birth.')}
                                    control={<FieldModeSelect value={form.data.dob_mode} onChange={(e) => form.setData('dob_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="mail"
                                    tone="bg-blue-100 text-blue-600"
                                    title={t('admin.customer_settings.newsletter_opt_in', 'Newsletter opt-in')}
                                    hint={t('admin.customer_settings.newsletter_opt_in_hint', 'Show the newsletter checkbox on register and checkout.')}
                                    value={!!form.data.newsletter_opt_in}
                                    onChange={(on) => form.setData('newsletter_opt_in', on)}
                                />
                            </div>
                        </Card>
                        <Card>
                            <SettingsSectionHead
                                icon="lock"
                                title={t('admin.customer_settings.social_title', 'Social sign-in')}
                                hint={t('admin.customer_settings.social_hint', 'Turn a provider on after both keys are saved. The sign-in page shows that button only then.')}
                                extra={<SettingsCount>{t('admin.customer_settings.provider_count', '{count} providers', { count: 3 })}</SettingsCount>}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    mark={<BrandMark brand="google" />}
                                    title={t('admin.customer_settings.google_enabled', 'Google')}
                                    hint={t('admin.customer_settings.google_hint', 'Sign in with a Google account.')}
                                    value={!!form.data.google_enabled}
                                    onChange={(on) => form.setData('google_enabled', on)}
                                >
                                    <Field label={t('admin.customer_settings.google_client_id', 'Client ID')} error={form.errors.google_client_id}>
                                        <Input wide value={form.data.google_client_id} autoComplete="off" onChange={(e) => form.setData('google_client_id', e.target.value)} />
                                    </Field>
                                    <Field
                                        label={t('admin.customer_settings.google_secret', 'Client secret')}
                                        hint={social.google?.secret_set
                                            ? t('admin.customer_settings.secret_kept', 'A secret is saved. Leave this blank to keep it.')
                                            : t('admin.customer_settings.secret_missing', 'Paste the secret from the provider.')}
                                        error={form.errors.google_client_secret}
                                    >
                                        <Input wide type="password" value={form.data.google_client_secret} autoComplete="new-password" placeholder={social.google?.secret_set ? '••••••••' : ''} onChange={(e) => form.setData('google_client_secret', e.target.value)} onBlur={saveDeferred} />
                                    </Field>
                                    <Field label={t('admin.customer_settings.redirect_url', 'Redirect URL')} hint={t('admin.customer_settings.google_redirect_hint', 'Paste this into the Google OAuth client as an authorized redirect URI.')}>
                                        <Input wide readOnly className="font-mono text-xs" value={social.google?.redirect || ''} onFocus={(e) => e.target.select()} />
                                    </Field>
                                </FeatureTile>
                                <FeatureTile
                                    mark={<BrandMark brand="facebook" className="h-11 w-11" />}
                                    title={t('admin.customer_settings.facebook_enabled', 'Facebook')}
                                    hint={t('admin.customer_settings.facebook_hint', 'Sign in with a Facebook account.')}
                                    value={!!form.data.facebook_enabled}
                                    onChange={(on) => form.setData('facebook_enabled', on)}
                                >
                                    <Field label={t('admin.customer_settings.facebook_app_id', 'App ID')} error={form.errors.facebook_client_id}>
                                        <Input wide value={form.data.facebook_client_id} autoComplete="off" onChange={(e) => form.setData('facebook_client_id', e.target.value)} />
                                    </Field>
                                    <Field
                                        label={t('admin.customer_settings.facebook_secret', 'App secret')}
                                        hint={social.facebook?.secret_set
                                            ? t('admin.customer_settings.secret_kept', 'A secret is saved. Leave this blank to keep it.')
                                            : t('admin.customer_settings.secret_missing', 'Paste the secret from the provider.')}
                                        error={form.errors.facebook_client_secret}
                                    >
                                        <Input wide type="password" value={form.data.facebook_client_secret} autoComplete="new-password" placeholder={social.facebook?.secret_set ? '••••••••' : ''} onChange={(e) => form.setData('facebook_client_secret', e.target.value)} onBlur={saveDeferred} />
                                    </Field>
                                    <Field label={t('admin.customer_settings.redirect_url', 'Redirect URL')} hint={t('admin.customer_settings.facebook_redirect_hint', 'Paste this into Facebook Login as a valid OAuth redirect URI.')}>
                                        <Input wide readOnly className="font-mono text-xs" value={social.facebook?.redirect || ''} onFocus={(e) => e.target.select()} />
                                    </Field>
                                </FeatureTile>
                                <FeatureTile
                                    mark={<BrandMark brand="linkedin" className="h-11 w-11" />}
                                    title={t('admin.customer_settings.linkedin_enabled', 'LinkedIn')}
                                    hint={t('admin.customer_settings.linkedin_hint', 'Sign in with a LinkedIn account.')}
                                    value={!!form.data.linkedin_enabled}
                                    onChange={(on) => form.setData('linkedin_enabled', on)}
                                >
                                    <Field label={t('admin.customer_settings.linkedin_client_id', 'Client ID')} error={form.errors.linkedin_client_id}>
                                        <Input wide value={form.data.linkedin_client_id} autoComplete="off" onChange={(e) => form.setData('linkedin_client_id', e.target.value)} />
                                    </Field>
                                    <Field
                                        label={t('admin.customer_settings.linkedin_secret', 'Client secret')}
                                        hint={social.linkedin?.secret_set
                                            ? t('admin.customer_settings.secret_kept', 'A secret is saved. Leave this blank to keep it.')
                                            : t('admin.customer_settings.secret_missing', 'Paste the secret from the provider.')}
                                        error={form.errors.linkedin_client_secret}
                                    >
                                        <Input wide type="password" value={form.data.linkedin_client_secret} autoComplete="new-password" placeholder={social.linkedin?.secret_set ? '••••••••' : ''} onChange={(e) => form.setData('linkedin_client_secret', e.target.value)} onBlur={saveDeferred} />
                                    </Field>
                                    <Field label={t('admin.customer_settings.redirect_url', 'Redirect URL')} hint={t('admin.customer_settings.linkedin_redirect_hint', 'Paste this into the LinkedIn OAuth app as an authorized redirect URL.')}>
                                        <Input wide readOnly className="font-mono text-xs" value={social.linkedin?.redirect || ''} onFocus={(e) => e.target.select()} />
                                    </Field>
                                </FeatureTile>
                            </div>
                        </Card>
                    </div>
                ) : null}

                {tab === 'groups' ? (
                    <Card>
                        <SettingsSectionHead
                            icon="users"
                            title={t('admin.customer_settings.panel_groups', 'Customer group engine')}
                            hint={t('admin.customer_settings.panel_groups_hint', 'Controls how groups behave store-wide. Tax exemption is set on each group. Create Retail / Wholesale / VIP under Customers → Groups.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="users"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.customer_settings.groups_enabled', 'Enable customer groups')}
                                hint={t('admin.customer_settings.groups_enabled_hint', 'When this is off, group prices, group discounts, hidden prices, and each group’s tax exemption are ignored. The group list stays so you can edit groups.')}
                                value={!!form.data.groups_enabled}
                                onChange={(on) => form.setData('groups_enabled', on)}
                                extra={(
                                    <Button type="button" variant="link" href="/admin/customer-groups" className="mt-1 px-0">
                                        {t('admin.customer_settings.open_groups', 'Manage customer groups')}
                                    </Button>
                                )}
                            />
                            <FeatureTile
                                icon="tag"
                                tone="bg-fuchsia-100 text-fuchsia-600"
                                title={t('admin.customer_settings.default_group', 'Default customer group')}
                                hint={t('admin.customer_settings.default_group_hint', 'New accounts join this group.')}
                                control={(
                                    <Select className="w-40 shrink-0" value={form.data.default_group_code} onChange={(e) => form.setData('default_group_code', e.target.value)} disabled={!form.data.groups_enabled}>
                                        {groupOptions.map((g) => (
                                            <option key={g.code} value={g.code}>{g.name}</option>
                                        ))}
                                    </Select>
                                )}
                            />
                            <FeatureTile
                                icon="price-tag"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.customer_settings.group_pricing', 'Group-specific pricing')}
                                hint={t('admin.customer_settings.group_pricing_hint', 'Signed-in customers pay the discount percent saved on their group (Customers → Groups). A group at 0% does not change the price. This is separate from Marketing → Discounts.')}
                                value={!!form.data.group_pricing}
                                onChange={(on) => form.setData('group_pricing', on)}
                                disabled={!form.data.groups_enabled}
                            />
                            <FeatureTile
                                    icon="tag"
                                tone="bg-orange-100 text-orange-600"
                                title={t('admin.customer_settings.group_discounts', 'Group-specific discounts')}
                                hint={t('admin.customer_settings.group_discounts_hint', 'Discount rules in Marketing → Discounts can target a customer group. When this is off, those rules do not apply.')}
                                value={!!form.data.group_discounts}
                                onChange={(on) => form.setData('group_discounts', on)}
                                disabled={!form.data.groups_enabled}
                            />
                        </div>
                    </Card>
                ) : null}

                {tab === 'addresses' ? (
                    <div className="space-y-4">
                        <Card>
                            <SettingsSectionHead
                                icon="home"
                                title={t('admin.customer_settings.panel_address_rules', 'Address rules')}
                                hint={t('admin.customer_settings.panel_address_rules_hint', 'Checkout and saved-address behavior. Prefer country-aware state/ZIP where possible.')}
                                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="file"
                                    tone="bg-violet-100 text-violet-600"
                                    title={t('admin.customer_settings.require_billing', 'Require billing address')}
                                    hint={t('admin.customer_settings.require_billing_hint', 'Checkout asks for a billing address.')}
                                    value={!!form.data.require_billing}
                                    onChange={(on) => form.setData('require_billing', on)}
                                />
                                <FeatureTile
                                    icon="home"
                                    tone="bg-sky-100 text-sky-600"
                                    title={t('admin.customer_settings.require_shipping', 'Require shipping address')}
                                    hint={t('admin.customer_settings.require_shipping_hint', 'Checkout asks for a shipping address.')}
                                    value={!!form.data.require_shipping}
                                    onChange={(on) => form.setData('require_shipping', on)}
                                />
                                <FeatureTile
                                    icon="copy"
                                    tone="bg-emerald-100 text-emerald-600"
                                    title={t('admin.customer_settings.billing_same_default', 'Billing same as shipping by default')}
                                    hint={t('admin.customer_settings.billing_same_default_hint', 'The billing form starts copied from shipping.')}
                                    value={!!form.data.billing_same_default}
                                    onChange={(on) => form.setData('billing_same_default', on)}
                                />
                                <FeatureTile
                                    icon="grid"
                                    tone="bg-orange-100 text-orange-600"
                                    title={t('admin.customer_settings.allow_multiple', 'Allow multiple addresses')}
                                    hint={t('admin.customer_settings.allow_multiple_hint', 'Customers can save more than one address.')}
                                    value={!!form.data.allow_multiple}
                                    onChange={(on) => form.setData('allow_multiple', on)}
                                >
                                    {form.data.allow_multiple ? (
                                        <Field
                                            label={t('admin.customer_settings.max_saved', 'Maximum saved addresses')}
                                            hint={t('admin.customer_settings.max_saved_hint', 'Leave empty for unlimited.')}
                                            error={form.errors.max_saved}
                                        >
                                            <Input wide type="number" min="1" max="100" value={form.data.max_saved} onChange={(e) => form.setData('max_saved', e.target.value)} />
                                        </Field>
                                    ) : null}
                                </FeatureTile>
                            </div>
                        </Card>
                        <Card>
                            <SettingsSectionHead
                                icon="file"
                                title={t('admin.customer_settings.panel_address_fields', 'Address fields')}
                                hint={t('admin.customer_settings.panel_address_fields_hint', 'Visibility and requirement for address form fields.')}
                                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 6 })}</SettingsCount>}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="phone"
                                    tone="bg-teal-100 text-teal-600"
                                    title={t('admin.customer_settings.address_phone', 'Phone in address')}
                                    control={<FieldModeSelect value={form.data.address_phone_mode} onChange={(e) => form.setData('address_phone_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="building"
                                    tone="bg-violet-100 text-violet-600"
                                    title={t('admin.customer_settings.address_company', 'Company field')}
                                    control={<FieldModeSelect value={form.data.address_company_mode} onChange={(e) => form.setData('address_company_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="shield"
                                    tone="bg-emerald-100 text-emerald-600"
                                    title={t('admin.customer_settings.address_tax_id', 'Tax / VAT ID field')}
                                    control={<FieldModeSelect value={form.data.address_tax_id_mode} onChange={(e) => form.setData('address_tax_id_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="file"
                                    tone="bg-sky-100 text-sky-600"
                                    title={t('admin.customer_settings.address_line2', 'Address line 2')}
                                    control={<FieldModeSelect value={form.data.address_line2_mode} onChange={(e) => form.setData('address_line2_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="globe"
                                    tone="bg-orange-100 text-orange-600"
                                    title={t('admin.customer_settings.address_state', 'State / Province')}
                                    control={<FieldModeSelect includeCountryRules value={form.data.address_state_mode} onChange={(e) => form.setData('address_state_mode', e.target.value)} />}
                                />
                                <FeatureTile
                                    icon="pin"
                                    tone="bg-indigo-100 text-indigo-600"
                                    title={t('admin.customer_settings.address_postal', 'Postal / ZIP code')}
                                    control={<FieldModeSelect includeCountryRules value={form.data.address_postal_mode} onChange={(e) => form.setData('address_postal_mode', e.target.value)} />}
                                />
                            </div>
                        </Card>
                    </div>
                ) : null}
            </form>
            )}
        </Layout>
    );
}
