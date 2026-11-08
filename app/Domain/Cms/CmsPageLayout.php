<?php

namespace App\Domain\Cms;

/**
 * Modern storefront chrome for CMS pages (hero, highlight cards, sidebar).
 * Editable page copy still lives in CMS translations (title / short / body).
 */
final class CmsPageLayout
{
    /**
     * @return array{
     *   hero: string,
     *   display_title?: string,
     *   highlights: list<array{icon: string, title: string, body: string}>,
     *   sidebar: list<array{label: string, url: string}>,
     *   trust: array{title: string, body: string},
     *   panels: list<array{title: string, body: string, action?: array{label: string, url: string}, links?: list<array{label: string, url: string}>}>
     * }
     */
    public static function forSlug(?string $slug, ?string $template = null): array
    {
        $slug = strtolower(trim((string) $slug));

        return match ($slug) {
            'shipping' => self::shipping(),
            'returns' => self::returns(),
            'faq' => self::faq(),
            'contact' => self::contact(),
            'privacy', 'terms' => self::legal($slug),
            'payment-security' => self::paymentSecurity(),
            'order-tracking' => self::orderTracking(),
            'warranty' => self::warranty(),
            'help' => self::help(),
            'about' => self::about(),
            default => self::generic($template),
        };
    }

    /** @return array<string, mixed> */
    protected static function shipping(): array
    {
        return [
            'hero' => 'shipping',
            'display_title' => 'Shipping & Delivery',
            'highlights' => [
                ['icon' => 'truck', 'title' => 'Fast & Reliable Delivery', 'body' => 'Clear transit windows for every method at checkout.'],
                ['icon' => 'tag', 'title' => 'Free Shipping Over $75', 'body' => 'Qualifying carts unlock free shipping automatically.'],
                ['icon' => 'shield', 'title' => 'Safe & Secure', 'body' => 'Packed carefully and tracked through to delivery.'],
                ['icon' => 'help', 'title' => 'Helpful Support', 'body' => 'Questions about a parcel? We’re here to help.'],
            ],
            'sidebar' => self::serviceLinks('shipping'),
            'trust' => [
                'title' => "We've Got You Covered",
                'body' => 'Damaged or wrong items are covered under our returns policy after a quick inspection.',
            ],
            'panels' => [
                [
                    'title' => 'Addresses & Tracking',
                    'body' => 'Use a complete shipping address. After checkout, follow progress from My orders or your confirmation email.',
                    'action' => ['label' => 'Track Your Order', 'url' => '/account/orders'],
                ],
                [
                    'title' => 'Need Help?',
                    'body' => 'Still stuck on a delivery question? Open the help center or write to support.',
                    'links' => [
                        ['label' => 'Visit Help Center', 'url' => '/help'],
                        ['label' => 'Contact Support', 'url' => '/contact'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function returns(): array
    {
        return [
            'hero' => 'returns',
            'display_title' => 'Returns & Refunds',
            'highlights' => [
                ['icon' => 'return', 'title' => '30-Day Returns', 'body' => 'Most unused items can come back within a month.'],
                ['icon' => 'shield', 'title' => 'Fair Inspection', 'body' => 'We check the parcel, then process your refund.'],
                ['icon' => 'card', 'title' => 'Original Payment', 'body' => 'Refunds go back to the method you paid with.'],
                ['icon' => 'gift', 'title' => 'Optional Voucher', 'body' => 'Admins can also issue a store voucher when needed.'],
            ],
            'sidebar' => self::serviceLinks('returns'),
            'trust' => [
                'title' => "We've Got You Covered",
                'body' => 'Wrong or damaged items are prioritized — contact us with your order number.',
            ],
            'panels' => [
                [
                    'title' => 'Start a return',
                    'body' => 'Open the order in your account and request a return, or email support with the order number.',
                    'action' => ['label' => 'My Orders', 'url' => '/account/orders'],
                ],
                [
                    'title' => 'Need Help?',
                    'body' => 'Read shipping rules or reach the team directly.',
                    'links' => [
                        ['label' => 'Shipping policy', 'url' => '/shipping'],
                        ['label' => 'Contact Support', 'url' => '/contact'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function faq(): array
    {
        return [
            'hero' => 'help',
            'display_title' => 'FAQ',
            'highlights' => [
                ['icon' => 'truck', 'title' => 'Shipping', 'body' => 'Timing and costs explained at checkout.'],
                ['icon' => 'return', 'title' => 'Returns', 'body' => 'Unused items within 30 days in most cases.'],
                ['icon' => 'tag', 'title' => 'Coupons', 'body' => 'Try SAVE10 or WELCOME15 in this demo.'],
                ['icon' => 'user', 'title' => 'Account', 'body' => 'Demo login details are listed below.'],
            ],
            'sidebar' => self::serviceLinks('faq'),
            'trust' => [
                'title' => 'Still need a human?',
                'body' => 'Contact support with your order number for faster help.',
            ],
            'panels' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected static function contact(): array
    {
        return [
            'hero' => 'contact',
            'display_title' => 'Contact Us',
            'highlights' => [
                ['icon' => 'mail', 'title' => 'Email', 'body' => 'We usually reply within one business day.'],
                ['icon' => 'phone', 'title' => 'Phone', 'body' => 'Call during store hours for urgent order issues.'],
                ['icon' => 'pin', 'title' => 'Visit', 'body' => 'Store address is listed in the page body.'],
                ['icon' => 'help', 'title' => 'Self-serve', 'body' => 'Try Help, FAQ, and order tracking first.'],
            ],
            'sidebar' => self::serviceLinks('contact'),
            'trust' => [
                'title' => "We're here to help",
                'body' => 'Include your order number when you write — it speeds everything up.',
            ],
            'panels' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected static function help(): array
    {
        return [
            'hero' => 'help',
            'display_title' => 'Help Center',
            'highlights' => [
                ['icon' => 'package', 'title' => 'Orders', 'body' => 'Track status and payment from My orders.'],
                ['icon' => 'truck', 'title' => 'Shipping', 'body' => 'Methods and timing on the shipping page.'],
                ['icon' => 'return', 'title' => 'Returns', 'body' => 'Start from the order or email support.'],
                ['icon' => 'tag', 'title' => 'Coupons', 'body' => 'SAVE10 and WELCOME15 work in this demo.'],
            ],
            'sidebar' => self::serviceLinks('help'),
            'trust' => [
                'title' => 'Guides at a glance',
                'body' => 'Use the sidebar for shipping, returns, FAQ, and account links.',
            ],
            'panels' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected static function about(): array
    {
        return [
            'hero' => 'about',
            'display_title' => 'About Us',
            'highlights' => [
                ['icon' => 'store', 'title' => 'Real catalog', 'body' => 'Products, checkout, and accounts ready to demo.'],
                ['icon' => 'shield', 'title' => 'Honest pricing', 'body' => 'Tax and shipping calculated at checkout.'],
                ['icon' => 'truck', 'title' => 'Clear delivery', 'body' => 'Shipping methods come from admin settings.'],
                ['icon' => 'help', 'title' => 'Supportive UX', 'body' => 'Help, FAQ, and order tracking built in.'],
            ],
            'sidebar' => self::serviceLinks('about'),
            'trust' => [
                'title' => 'Built for merchants',
                'body' => 'Edit this page anytime from Admin → CMS with the rich text editor.',
            ],
            'panels' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected static function legal(string $slug): array
    {
        return [
            'hero' => 'legal',
            'display_title' => $slug === 'privacy' ? 'Privacy Policy' : 'Terms of Service',
            'highlights' => [
                ['icon' => 'shield', 'title' => 'Your data', 'body' => 'We only keep what we need to run the shop.'],
                ['icon' => 'lock', 'title' => 'Secure sessions', 'body' => 'Cookie login — no JWT in localStorage.'],
                ['icon' => 'list', 'title' => 'Clear rules', 'body' => 'Replace demo legal copy before going live.'],
                ['icon' => 'mail', 'title' => 'Questions', 'body' => 'Contact support about privacy or terms.'],
            ],
            'sidebar' => self::serviceLinks($slug),
            'trust' => [
                'title' => 'Demo legal text',
                'body' => 'Have counsel review and replace this content before launch.',
            ],
            'panels' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected static function paymentSecurity(): array
    {
        return [
            'hero' => 'legal',
            'display_title' => 'Payment & Security',
            'highlights' => [
                ['icon' => 'lock', 'title' => 'SSL Checkout', 'body' => 'HTTPS encrypts account and payment pages in transit.'],
                ['icon' => 'card', 'title' => 'Cards & Wallets', 'body' => 'Live gateways can accept cards and digital wallets.'],
                ['icon' => 'shield', 'title' => 'Offline Demo Methods', 'body' => 'Bank transfer, COD, check, and store credit for testing.'],
                ['icon' => 'user', 'title' => 'Cookie Sessions', 'body' => 'Sanctum cookies — no JWT stored in localStorage.'],
            ],
            'sidebar' => self::serviceLinks('payment-security'),
            'trust' => [
                'title' => 'Shop with confidence',
                'body' => 'We never ask for your full card number by email or phone.',
            ],
            'panels' => [
                [
                    'title' => 'Billing help',
                    'body' => 'Questions about a charge or offline payment instructions? Reach support with your order number.',
                    'links' => [
                        ['label' => 'Contact Support', 'url' => '/contact'],
                        ['label' => 'Privacy Policy', 'url' => '/privacy'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function orderTracking(): array
    {
        return [
            'hero' => 'shipping',
            'display_title' => 'Order Tracking',
            'highlights' => [
                ['icon' => 'package', 'title' => 'My Orders', 'body' => 'Signed-in customers track every order from the account.'],
                ['icon' => 'mail', 'title' => 'Email Links', 'body' => 'Confirmation and shipping emails include order details.'],
                ['icon' => 'truck', 'title' => 'Carrier Updates', 'body' => 'Tracking numbers appear once the parcel ships.'],
                ['icon' => 'help', 'title' => 'Stuck Status?', 'body' => 'Allow 1–2 business days after payment for packing.'],
            ],
            'sidebar' => self::serviceLinks('order-tracking'),
            'trust' => [
                'title' => "We've Got You Covered",
                'body' => 'Missing a parcel marked delivered? Contact us quickly with your order number.',
            ],
            'panels' => [
                [
                    'title' => 'Track now',
                    'body' => 'Open My orders for live status, payment, and shipping method.',
                    'action' => ['label' => 'Track Your Order', 'url' => '/account/orders'],
                ],
                [
                    'title' => 'Need Help?',
                    'body' => 'Review shipping windows or write to support.',
                    'links' => [
                        ['label' => 'Shipping policy', 'url' => '/shipping'],
                        ['label' => 'Contact Support', 'url' => '/contact'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function warranty(): array
    {
        return [
            'hero' => 'legal',
            'display_title' => 'Warranty & Authenticity',
            'highlights' => [
                ['icon' => 'shield', 'title' => 'Genuine Products', 'body' => 'Sourced through legitimate retail channels.'],
                ['icon' => 'package', 'title' => '12-Month Cover', 'body' => 'Limited warranty against manufacturing defects.'],
                ['icon' => 'return', 'title' => 'Clear Claims', 'body' => 'Photos, order number, then inspection and resolution.'],
                ['icon' => 'mail', 'title' => 'Fast Response', 'body' => 'We aim to acknowledge claims within one business day.'],
            ],
            'sidebar' => self::serviceLinks('warranty'),
            'trust' => [
                'title' => "We've Got You Covered",
                'body' => 'Counterfeit concerns and defects are prioritized — contact us with evidence.',
            ],
            'panels' => [
                [
                    'title' => 'Start a claim',
                    'body' => 'Email support or open the order in your account and reference the warranty.',
                    'action' => ['label' => 'My Orders', 'url' => '/account/orders'],
                ],
                [
                    'title' => 'Change of mind?',
                    'body' => 'Unused items within 30 days follow the returns policy instead.',
                    'links' => [
                        ['label' => 'Returns policy', 'url' => '/returns'],
                        ['label' => 'Contact Support', 'url' => '/contact'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function generic(?string $template): array
    {
        return [
            'hero' => $template === 'landing' ? 'about' : 'help',
            'highlights' => [],
            'sidebar' => self::serviceLinks(''),
            'trust' => [
                'title' => "We've Got You Covered",
                'body' => 'Browse help guides or contact support if you need a hand.',
            ],
            'panels' => [],
        ];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    protected static function serviceLinks(string $current): array
    {
        $links = [
            ['label' => 'Shipping & Delivery', 'url' => '/shipping'],
            ['label' => 'Returns & Refunds', 'url' => '/returns'],
            ['label' => 'Order Tracking', 'url' => '/order-tracking'],
            ['label' => 'Payment & Security', 'url' => '/payment-security'],
            ['label' => 'Warranty', 'url' => '/warranty'],
            ['label' => 'FAQ', 'url' => '/faq'],
            ['label' => 'Help Center', 'url' => '/help'],
            ['label' => 'Contact Us', 'url' => '/contact'],
            ['label' => 'Privacy Policy', 'url' => '/privacy'],
            ['label' => 'Terms of Service', 'url' => '/terms'],
            ['label' => 'Track Your Order', 'url' => '/account/orders'],
        ];

        return array_values(array_filter(
            $links,
            fn (array $row) => trim($row['url'], '/') !== $current
        ));
    }
}
