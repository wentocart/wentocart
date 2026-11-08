<?php

namespace App\Domain\Localization;

/**
 * English source copy for admin and storefront UI, grouped by page.
 *
 * New screens must call t('page.key', 'English default'). A scan of those
 * calls plus this catalog is what the Translations admin page lists.
 */
final class UiCatalog
{
    /**
     * @return list<array{id: string, name: string, surface: string, strings: array<string, string>}>
     */
    public static function pages(): array
    {
        return [
            [
                'id' => 'admin.nav',
                'name' => 'Admin · Sidebar',
                'surface' => 'admin',
                'strings' => [
                    'dashboard' => 'Dashboard',
                    'dashboards' => 'Dashboards',
                    'catalog' => 'Catalog',
                    'product' => 'Product',
                    'category' => 'Category',
                    'brand' => 'Brand',
                    'supplier' => 'Supplier',
                    'feature' => 'Feature',
                    'files' => 'Files',
                    'orders' => 'Orders',
                    'order' => 'Order',
                    'order_journey' => 'Order Journey',
                    'order_statuses' => 'Order statuses',
                    'invoice' => 'Invoice',
                    'credit_slip' => 'Credit Slip',
                    'delivery_slip' => 'Delivery Slip',
                    'customers' => 'Customers',
                    'customer' => 'Customer',
                    'customer_group' => 'Customer Group',
                    'addresses' => 'Addresses',
                    'reviews' => 'Reviews',
                    'questions' => 'Questions',
                    'marketing' => 'Marketing',
                    'discounts' => 'Discounts',
                    'shipping' => 'Shipping',
                    'shipping_methods' => 'Shipping methods',
                    'payment' => 'Payment',
                    'payment_methods' => 'Payment methods',
                    'design' => 'Design',
                    'pages' => 'Pages',
                    'blocks' => 'Blocks',
                    'menus' => 'Menus',
                    'themes' => 'Themes',
                    'apps' => 'Extensions',
                    'international' => 'International',
                    'localization' => 'Localization',
                    'countries' => 'Countries',
                    'currencies' => 'Currencies',
                    'taxes' => 'Taxes',
                    'languages' => 'Languages',
                    'translations' => 'Translations',
                    'staff' => 'Staff',
                    'employees' => 'Employees',
                    'teams' => 'Teams',
                    'profiles' => 'Profiles',
                    'roles' => 'Roles',
                    'permissions' => 'Permissions',
                    'configure' => 'Configure',
                    'settings' => 'Settings',
                    'email' => 'Email',
                    'ui_kit' => 'UI kit',
                ],
            ],
            [
                'id' => 'admin.shell',
                'name' => 'Admin · Shell',
                'surface' => 'admin',
                'strings' => [
                    'search' => 'Search products, orders, customers…',
                    'create' => 'Create',
                    'log_out' => 'Log out',
                    'super_admin' => 'Super admin',
                    'staff' => 'Staff',
                    'language' => 'Language',
                    'current' => 'Current',
                    'default' => 'Default',
                ],
            ],
            [
                'id' => 'admin.login',
                'name' => 'Admin · Sign in',
                'surface' => 'admin',
                'strings' => [
                    'title' => 'Sign in',
                    'subtitle' => 'Access your store administration',
                    'email' => 'Email address',
                    'email_placeholder' => 'Enter your email address',
                    'password' => 'Password',
                    'password_placeholder' => 'Enter your password',
                    'password_confirm' => 'Confirm password',
                    'remember' => 'Remember me',
                    'forgot' => 'Forgot password?',
                    'submit' => 'Sign in',
                    'brand' => 'Admin',
                    'panel' => 'Admin Panel',
                    'rights' => 'All rights reserved.',
                    'privacy' => 'Privacy Policy',
                    'terms' => 'Terms of Service',
                    'help' => 'Help',
                    'forgot_title' => 'Forgot Password?',
                    'forgot_body' => "Enter your email address and we'll send you a link to reset your password.",
                    'send_reset' => 'Send Reset Link',
                    'or' => 'or',
                    'back' => 'Back to Sign in',
                    'reset_sent' => 'Check your email for the reset link.',
                    'reset_title' => 'Set a new password',
                    'reset_body' => 'Choose a new password for your admin account.',
                    'reset_submit' => 'Save new password',
                    'show_password' => 'Show password',
                    'hide_password' => 'Hide password',
                ],
            ],
            [
                'id' => 'admin.common',
                'name' => 'Admin · Shared actions',
                'surface' => 'admin',
                'strings' => [
                    'save' => 'Save changes',
                    'create' => 'Create',
                    'cancel' => 'Cancel',
                    'delete' => 'Delete',
                    'add_new' => 'Add new',
                    'search' => 'Search…',
                    'reset_filters' => 'Reset filters',
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                    'all' => 'All',
                    'edit' => 'Edit',
                    'reset' => 'Reset',
                    'remove' => 'Remove',
                    'actions' => 'Actions',
                    'select' => 'Select',
                    'showing' => 'Showing',
                    'to' => 'to',
                    'of' => 'of',
                    'results' => 'results',
                    'no_records' => 'No records yet.',
                    'image' => 'Image',
                    'loading' => 'Loading…',
                    'from' => 'From',
                    'up_to' => 'To',
                    'fee' => 'Fee',
                    'add_tier' => 'Add tier',
                    'type_to_search' => 'Type to search…',
                    'searching' => 'Searching…',
                    'no_matches' => 'No matches',
                    'no_options' => 'No options',
                    'delete_item' => 'Delete this item?',
                    'delete_selected' => 'Delete selected items?',
                    'bulk_delete' => 'Bulk delete',
                ],
            ],
            [
                'id' => 'admin.products',
                'name' => 'Admin · Products',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.categories',
                'name' => 'Admin · Categories',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.brands',
                'name' => 'Admin · Brands',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.features',
                'name' => 'Admin · Features',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.suppliers',
                'name' => 'Admin · Suppliers',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.orders',
                'name' => 'Admin · Orders',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.customers',
                'name' => 'Admin · Customers',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.customer_groups',
                'name' => 'Admin · Customer groups',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.addresses',
                'name' => 'Admin · Addresses',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.reviews',
                'name' => 'Admin · Reviews',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.questions',
                'name' => 'Admin · Questions',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.discounts',
                'name' => 'Admin · Discounts',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.shipping',
                'name' => 'Admin · Shipping',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.payments',
                'name' => 'Admin · Payments',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.cms',
                'name' => 'Admin · Pages',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.blocks',
                'name' => 'Admin · Blocks',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.menus',
                'name' => 'Admin · Menus',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.themes',
                'name' => 'Admin · Themes',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.apps',
                'name' => 'Admin · Extensions',
                'surface' => 'admin',
                'strings' => [
                    'apps' => 'Extensions',
                    'manager_title' => 'Extension Manager',
                    'manager_subtitle' => 'Manage and extend your store with powerful extensions.',
                    'hint' => 'Extensions are capability-locked modules. They cannot edit core PHP. Review the permission list before you enable one.',
                    'install_zip' => 'Install from zip',
                    'zip_hint' => 'app.json at the zip root. New extensions install and enable. A newer zip for an installed extension is staged — use Upgrade on the row to apply.',
                    'zip_file' => 'Extension zip',
                    'install' => 'Install Extension',
                    'install_extension' => 'Install Extension',
                    'upload_module' => 'Install Extension',
                    'upgrade' => 'Upgrade',
                    'upgrade_ready' => 'Upgrade ready',
                    'reset' => 'Reset',
                    'reset_confirm' => 'Reset this extension? All of its settings will be deleted, then it will be reinstalled.',
                    'search_modules' => 'Search extensions…',
                    'empty' => 'No extensions installed yet.',
                    'empty_search' => 'No extensions found for this search or filter.',
                    'enabled' => 'Enabled',
                    'disabled' => 'Disabled',
                    'certified' => 'Certified',
                    'safe' => 'Safe extension',
                    'configure' => 'Configure',
                    'enable' => 'Enable',
                    'disable' => 'Disable',
                    'delete_confirm' => 'Delete this extension from disk? This cannot be undone without uploading or restoring the zip.',
                    'uninstall_confirm' => 'Uninstall this extension? It will leave the shop but files stay on disk so you can install again.',
                    'remove' => 'Remove',
                    'host_hint' => 'This screen is rendered by core from a field list the extension declared. The extension cannot inject admin JavaScript.',
                    'disabled_hint' => 'Enable this extension on the Extensions list before the storefront will use it.',
                    'no_settings' => 'This extension has no settings.',
                    'save' => 'Save settings',
                    'module_page_hint' => 'Core hosts this page. The extension returned a page document. It cannot inject React into other admin screens.',
                    'all_categories' => 'All categories',
                    'collapse_category' => 'Collapse {name}',
                    'expand_category' => 'Expand {name}',
                    'uncategorized' => 'Other',
                    'all_statuses' => 'Show all extensions',
                    'status_installed' => 'Installed',
                    'status_available' => 'Available',
                    'status_enabled' => 'Enabled',
                    'status_disabled' => 'Disabled',
                    'status_alert' => 'Needs attention',
                ],
            ],
            [
                'id' => 'admin.localization',
                'name' => 'Admin · Localization',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.countries',
                'name' => 'Admin · Countries',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.currencies',
                'name' => 'Admin · Currencies',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.taxes',
                'name' => 'Admin · Taxes',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.packs',
                'name' => 'Admin · Localization packs',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.import_export',
                'name' => 'Admin · Import / Export',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.employees',
                'name' => 'Admin · Employees',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.teams',
                'name' => 'Admin · Teams',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.profiles',
                'name' => 'Admin · Profiles',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.roles',
                'name' => 'Admin · Roles',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.permissions',
                'name' => 'Admin · Permissions',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.dashboards',
                'name' => 'Admin · Dashboards',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.store',
                'name' => 'Admin · Store',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.email',
                'name' => 'Admin · Email',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.coupons',
                'name' => 'Admin · Coupons',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.placeholder',
                'name' => 'Admin · Coming soon',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.editor',
                'name' => 'Admin · Editor',
                'surface' => 'admin',
                'strings' => [],
            ],
            [
                'id' => 'admin.languages',
                'name' => 'Admin · Languages',
                'surface' => 'admin',
                'strings' => [
                    'title' => 'Languages',
                    'hint' => 'Default is the language new products and pages are saved in. Current (top bar) only previews the admin. Other languages can stay empty and fall back to default.',
                    'add' => 'Add language',
                    'code' => 'Code',
                    'name' => 'Name',
                    'default' => 'Default',
                    'set_default' => 'Set default',
                    'cannot_disable_default' => 'Cannot deactivate the default language.',
                ],
            ],
            [
                'id' => 'admin.translations',
                'name' => 'Admin · Translations',
                'surface' => 'admin',
                'strings' => [
                    'title' => 'Translations',
                    'hint' => 'Words start in English. Choose Translate into, edit the right-hand boxes, then save. The top-bar current language is what the admin shows.',
                    'admin' => 'Admin',
                    'storefront' => 'Storefront',
                    'page' => 'Page',
                    'pages' => 'Pages',
                    'source' => 'English',
                    'translation' => 'Translation',
                    'missing' => 'Missing',
                    'done' => 'Translated',
                    'save_page' => 'Save this page',
                    'search_phrase' => 'Search a phrase…',
                    'search_page' => 'Search pages…',
                    'no_pages' => 'No pages in this group yet.',
                    'no_strings' => 'No phrases on this page yet.',
                    'translate_into' => 'Translate into',
                    'phrases' => 'phrases',
                    'translated_of' => 'translated',
                    'left_to_do' => 'left',
                    'english_original' => 'English original',
                    'your_translation' => 'Your translation',
                    'filled' => 'Filled',
                    'needs_translation' => 'Needs translation',
                ],
            ],
            [
                'id' => 'storefront.shell',
                'name' => 'Storefront · Header',
                'surface' => 'storefront',
                'strings' => [
                    'search' => 'Search products',
                    'compare' => 'Compare',
                    'cart' => 'Cart',
                    'account' => 'Account',
                    'about' => 'About',
                    'privacy' => 'Privacy',
                ],
            ],
            [
                'id' => 'storefront.login',
                'name' => 'Storefront · Sign in',
                'surface' => 'storefront',
                'strings' => [
                    'title' => 'Sign in',
                    'email' => 'Email',
                    'password' => 'Password',
                    'submit' => 'Sign in',
                    'register' => 'Create an account',
                    'invalid' => 'Invalid credentials',
                ],
            ],
            [
                'id' => 'storefront.register',
                'name' => 'Storefront · Register',
                'surface' => 'storefront',
                'strings' => [
                    'title' => 'Create an account',
                    'name' => 'Name',
                    'email' => 'Email',
                    'password' => 'Password',
                    'confirm' => 'Confirm password',
                    'submit' => 'Register',
                ],
            ],
            [
                'id' => 'storefront.cart',
                'name' => 'Storefront · Cart',
                'surface' => 'storefront',
                'strings' => [
                    'title' => 'Cart',
                    'loading' => 'Loading…',
                    'remove' => 'Remove',
                    'coupon' => 'Coupon',
                    'apply' => 'Apply',
                    'total' => 'Total',
                    'checkout' => 'Checkout',
                ],
            ],
            [
                'id' => 'storefront.checkout',
                'name' => 'Storefront · Checkout',
                'surface' => 'storefront',
                'strings' => [
                    'title' => 'Checkout',
                    'guest' => 'Guest checkout is allowed. Create an account anytime.',
                    'email' => 'Email',
                    'failed' => 'Checkout failed',
                    'place' => 'Place order',
                ],
            ],
            [
                'id' => 'storefront.home',
                'name' => 'Storefront · Home',
                'surface' => 'storefront',
                'strings' => [
                    'loading' => 'Loading…',
                    'featured' => 'Featured Products',
                    'new' => 'New Products',
                    'shop_by_category' => 'Shop by Featured Categories',
                    'testimonials' => 'See What Our Customers Say',
                    'bestsellers' => 'Bestseller Products',
                    'brands' => 'Popular Brands',
                    'newsletter_title' => 'Join Our Newsletter',
                    'newsletter_body' => 'Get all latest information on events, sales and offers',
                ],
            ],
            [
                'id' => 'storefront.supplier',
                'name' => 'Storefront · Supplier',
                'surface' => 'storefront',
                'strings' => [
                    'unavailable' => 'This supplier is not available.',
                    'loading' => 'Loading…',
                    'empty' => 'No products from this supplier yet.',
                ],
            ],
        ];
    }

    /**
     * Merchant-facing label for a page id (admin.account → Admin · Account).
     */
    public static function pageName(string $pageId): string
    {
        foreach (self::pages() as $page) {
            if ($page['id'] === $pageId) {
                return $page['name'];
            }
        }

        return self::humanizePageId($pageId);
    }

    protected static function humanizePageId(string $pageId): string
    {
        $parts = array_values(array_filter(explode('.', $pageId), fn ($part) => $part !== ''));
        if ($parts === []) {
            return 'Page';
        }

        $surface = match ($parts[0]) {
            'admin' => 'Admin',
            'storefront' => 'Storefront',
            default => self::labelSegment($parts[0]),
        };

        $rest = array_slice($parts, 1);
        if ($rest === []) {
            return $surface;
        }

        return $surface.' · '.implode(' · ', array_map([self::class, 'labelSegment'], $rest));
    }

    protected static function labelSegment(string $segment): string
    {
        $known = [
            'ai' => 'AI',
            'seo' => 'SEO',
            'pdp' => 'Product page',
            'cms' => 'Pages',
            'urls' => 'URLs',
            'api' => 'API',
            'faq' => 'FAQ',
            'pdf' => 'PDF',
            'csv' => 'CSV',
            'smtp' => 'SMTP',
            'not_found' => 'Not found',
            'order_stages' => 'Order stages',
            'credit_slips' => 'Credit slips',
            'delivery_slips' => 'Delivery slips',
            'customer_groups' => 'Customer groups',
            'import_export' => 'Import / Export',
            'theme_schema' => 'Theme schema',
            'theme_settings' => 'Theme settings',
            'multivendor' => 'Marketplace',
        ];

        if (isset($known[$segment])) {
            return $known[$segment];
        }

        return ucwords(str_replace('_', ' ', $segment));
    }
}
