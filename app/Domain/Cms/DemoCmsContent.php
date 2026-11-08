<?php

namespace App\Domain\Cms;

/**
 * Demo / starter CMS HTML bodies (TipTap-compatible).
 * Merchants edit these in Admin → CMS with the rich text editor.
 */
final class DemoCmsContent
{
    /**
     * @return list<array{slug: string, title: string, category: string, template: string, short: string, body: string}>
     */
    public static function pages(): array
    {
        return [
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'category' => 'legal',
                'template' => 'legal',
                'short' => 'How we collect, use, share, and protect your personal information (GDPR / CCPA style).',
                'body' => <<<'HTML'
<h2>1. Introduction</h2>
<p>This Privacy Policy describes how this shop (“we”, “us”, or “our”) collects, uses, shares, and protects personal information when you browse the storefront, create an account, place an order, or contact support. It is written in a GDPR / CCPA-style structure so merchants can adapt it with counsel before going live.</p>
<p>By using the store, you acknowledge this policy. If you do not agree, please do not use the site or create an account. For questions, see <a href="/contact">Contact us</a> or email <a href="mailto:support@example.com">support@example.com</a>.</p>
<blockquote><p><strong>Important:</strong> This demo text is a starting point only. Replace it with counsel-approved wording before launch.</p></blockquote>

<h2>2. Information we collect</h2>
<p>Depending on how you use the shop, we may collect:</p>
<ul>
<li><strong>Account data</strong> — name, email address, phone number, password (stored securely), and profile preferences.</li>
<li><strong>Order &amp; fulfillment data</strong> — items purchased, quantities, prices, tax, shipping method, billing and shipping addresses, and order history.</li>
<li><strong>Payment-related data</strong> — the payment method you choose (for example card, wallet, bank transfer, or cash on delivery). Card numbers are processed by payment partners when live gateways are enabled; we do not store full card PANs in this demo.</li>
<li><strong>Support communications</strong> — messages you send via the contact form, email, or phone, including any order number you provide.</li>
<li><strong>Technical &amp; usage data</strong> — IP address, browser type, device information, pages viewed, and session identifiers needed for cart, login, and security.</li>
</ul>

<h2>3. Cookies and similar technologies</h2>
<p>We use cookies and similar technologies to keep you signed in, remember your cart, protect against CSRF, and measure basic storefront performance. This shop uses Sanctum <strong>cookie sessions</strong> — not JWT tokens in localStorage.</p>
<ul>
<li><strong>Essential cookies</strong> — required for login, cart, and checkout. These cannot be disabled if you want to shop.</li>
<li><strong>Preference cookies</strong> — may remember language or display choices when enabled.</li>
<li><strong>Analytics</strong> — only if the merchant later enables a measurement tool; none are required for the core demo.</li>
</ul>
<p>You can control cookies through your browser settings. Blocking essential cookies may prevent checkout or account features from working.</p>

<h2>4. How we use your information</h2>
<p>We use personal information to:</p>
<ul>
<li>Create and manage your account, wishlist, saved addresses, and vouchers.</li>
<li>Process, fulfill, and track orders; send order confirmation and shipping emails.</li>
<li>Calculate tax and shipping at checkout and handle returns or refunds.</li>
<li>Respond to support requests and improve product pages, search, and checkout UX.</li>
<li>Detect fraud, abuse, and security incidents; comply with legal obligations.</li>
</ul>
<p>We do not use your data for unrelated third-party advertising in this demo configuration.</p>

<h2>5. Sharing and disclosure</h2>
<p>We do <strong>not sell</strong> customer lists. We may share information only as needed with:</p>
<ul>
<li><strong>Payment processors</strong> — to authorize and settle payments when live gateways are enabled.</li>
<li><strong>Shipping carriers and fulfillment partners</strong> — name, address, and parcel details required for delivery.</li>
<li><strong>Email / infrastructure providers</strong> — to send transactional messages (order confirmations, password resets).</li>
<li><strong>Staff with admin permissions</strong> — to view and manage orders in the back office.</li>
<li><strong>Legal authorities</strong> — when required by law, court order, or to protect rights and safety.</li>
</ul>
<p>Partners receive only what they need to perform their service. See also our <a href="/terms">Terms of Service</a>.</p>

<h2>6. Retention</h2>
<p>We keep account and order records for as long as needed to provide the service, handle returns, and meet accounting or tax requirements. Support tickets are retained long enough to resolve your issue and improve service quality.</p>
<p>When you request deletion, we remove or anonymize personal data that is no longer required, except where the law requires us to keep certain records (for example completed invoices).</p>

<h2>7. Your rights (GDPR / CCPA style)</h2>
<p>Depending on where you live, you may have the right to:</p>
<ul>
<li><strong>Access</strong> — request a copy of the personal data we hold about you.</li>
<li><strong>Correction</strong> — update inaccurate profile or address details from your account, or ask us to correct them.</li>
<li><strong>Deletion</strong> — request erasure of personal data, subject to legal retention duties.</li>
<li><strong>Portability</strong> — receive certain data in a commonly used format where applicable.</li>
<li><strong>Opt out of sale / sharing</strong> — we do not sell personal information; contact us if you have questions about sharing for advertising.</li>
<li><strong>Restrict or object</strong> — ask us to limit certain processing where the law allows.</li>
</ul>
<p>To exercise these rights, email <a href="mailto:support@example.com">support@example.com</a> or use the <a href="/contact">contact page</a>. We may need to verify your identity before responding. California residents may also designate an authorized agent where CCPA applies.</p>

<h2>8. Security</h2>
<p>We use industry-standard practices such as HTTPS/SSL encryption in transit, hashed passwords, and role-based admin access. No method of transmission over the Internet is 100% secure; please use a strong password and sign out on shared devices. Learn more on <a href="/payment-security">Payment &amp; Security</a>.</p>

<h2>9. Children</h2>
<p>This store is not directed at children under 16 (or the age required in your jurisdiction). We do not knowingly collect personal information from children. If you believe a child has provided data, contact us and we will delete it promptly.</p>

<h2>10. Contact</h2>
<p>Privacy questions or requests: <a href="mailto:support@example.com">support@example.com</a> · <a href="/contact">Contact us</a> · Phone (+1) 1234-567-890.</p>
<p>Postal address: 123 Demo Street, City, Region 00000, United States.</p>
HTML,
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms & Conditions',
                'category' => 'legal',
                'template' => 'legal',
                'short' => 'Rules for using this shop, placing orders, and using your account.',
                'body' => <<<'HTML'
<h2>1. Agreement to these terms</h2>
<p>Welcome to this store. By accessing the website, creating an account, or placing an order, you agree to these Terms &amp; Conditions (“Terms”) and our <a href="/privacy">Privacy Policy</a>. If you do not agree, do not use the shop.</p>
<blockquote><p>This demo text is a starting point. Have counsel review and replace it before you accept live customer orders.</p></blockquote>

<h2>2. Eligibility and accounts</h2>
<p>You must be able to form a binding contract in your jurisdiction to place an order. Keep your login credentials confidential. You are responsible for activity under your account. Notify us immediately if you suspect unauthorized use. We may suspend or terminate accounts that abuse the store, violate these Terms, or engage in fraud.</p>
<p>Manage your profile and orders from <a href="/account/orders">My orders</a>. Demo customer credentials (local installs only) may appear on the <a href="/help">help center</a>.</p>

<h2>3. Orders and acceptance</h2>
<p>An order is a request to purchase the listed goods at the displayed price plus applicable tax and shipping. We may accept, refuse, or cancel orders that appear fraudulent, cannot be fulfilled, contain pricing or stock errors, or violate these Terms. If we cancel after payment, we will refund the amount paid for the cancelled items.</p>
<p>Order confirmation emails acknowledge receipt of your request; they do not guarantee inventory until the order is processed and shipped.</p>

<h2>4. Pricing, tax, and payments</h2>
<p>Prices are shown in the store currency and may change without notice until checkout is completed. Tax and shipping are calculated at checkout based on your address and selected method. Promo codes (for example SAVE10) apply only when valid and subject to their rules.</p>
<p>This demo may use offline payment methods such as bank transfer, cash on delivery, check, store credit, or invoice until a live card gateway is enabled. See <a href="/payment-security">Payment &amp; Security</a> for accepted methods and security practices.</p>

<h2>5. Shipping and risk of loss</h2>
<p>Delivery options, fees, and timing follow the <a href="/shipping">Shipping &amp; Delivery Policy</a>. Risk of loss passes according to carrier terms once the parcel is handed over for delivery, or when you collect a pickup order. Incorrect addresses supplied by you may delay delivery or cause additional charges.</p>

<h2>6. Returns and refunds</h2>
<p>Returns and refunds are governed by the <a href="/returns">Refund &amp; Return Policy</a>. Unless we issue a store voucher, refunds are sent to the original payment method after inspection. Warranty claims for defective products follow the <a href="/warranty">Warranty &amp; Authenticity Guarantee</a>.</p>

<h2>7. Intellectual property</h2>
<p>Product descriptions, images, logos, themes, and store branding belong to the merchant or their licensors. You may not copy, scrape, or reuse catalog content for commercial purposes without prior written permission. Trademarks mentioned are the property of their respective owners.</p>

<h2>8. User conduct</h2>
<p>You agree not to misuse the site, attempt unauthorized access, interfere with other users, upload malware, or use the shop for unlawful purposes. We may remove content or restrict access that violates these rules.</p>

<h2>9. Disclaimers and limitation of liability</h2>
<p>The storefront and demo catalog are provided “as is” to the fullest extent permitted by law. We do not warrant uninterrupted or error-free operation. To the maximum extent allowed, we are not liable for indirect, incidental, special, or consequential damages, or for loss of profits, data, or goodwill arising from use of the shop.</p>
<p>Our total liability for any claim related to an order is limited to the amount you paid for that order, except where liability cannot be limited by law (for example personal injury caused by negligence in some jurisdictions).</p>

<h2>10. Indemnity</h2>
<p>You agree to indemnify and hold us harmless from claims arising out of your misuse of the shop, violation of these Terms, or infringement of third-party rights, to the extent permitted by applicable law.</p>

<h2>11. Governing law</h2>
<p>These Terms are governed by the laws of the jurisdiction where the merchant is established, without regard to conflict-of-law rules. Courts in that jurisdiction shall have exclusive venue for disputes, except where consumer protection laws require otherwise.</p>

<h2>12. Changes</h2>
<p>We may update these Terms from time to time. The “last updated” date in Admin → CMS, or a notice on this page, will reflect material changes. Continued use after changes constitutes acceptance of the revised Terms.</p>

<h2>13. Contact</h2>
<p>Questions about these Terms? Visit <a href="/contact">Contact us</a> or email <a href="mailto:support@example.com">support@example.com</a>.</p>
HTML,
            ],
            [
                'slug' => 'shipping',
                'title' => 'Shipping & Delivery Policy',
                'category' => 'info',
                'template' => 'blank',
                'short' => 'Rates, handling times, tracking, international shipping, and delay guidance.',
                'body' => <<<'HTML'
<h2>How shipping works</h2>
<p>Choose a delivery method at checkout. The price and label come from the shipping methods configured in admin — totals update as soon as you pick an option. You will receive a confirmation email after placing the order, and tracking details once the parcel ships.</p>

<h2>Shipping rates</h2>
<table>
<thead>
<tr><th>Method</th><th>Cost</th><th>Estimated delivery</th><th>Notes</th></tr>
</thead>
<tbody>
<tr><td><strong>Standard delivery</strong></td><td>$5.00</td><td>3–5 business days</td><td>Everyday shipping for most domestic orders.</td></tr>
<tr><td><strong>Free shipping</strong></td><td>$0.00</td><td>3–5 business days</td><td>Unlocks automatically when the cart is $75 or more.</td></tr>
<tr><td><strong>Express</strong></td><td>$12.00</td><td>1–2 business days</td><td>Available when enabled by the merchant in admin.</td></tr>
<tr><td><strong>Overnight</strong></td><td>$25.00</td><td>Next business day</td><td>Cutoff times apply; weekends and holidays excluded.</td></tr>
</tbody>
</table>
<p>Admins can add local pickup, carrier rules, or regional rates under <strong>Shipping methods</strong>. Merchants edit this page anytime from <strong>CMS → Pages</strong>.</p>

<h2>Handling and processing times</h2>
<ul>
<li><strong>In-stock items</strong> — typically packed within <strong>1–2 business days</strong> after payment confirmation (or after COD acceptance rules are met).</li>
<li><strong>Pre-order or backorder</strong> — ship dates appear on the product page when available; confirmation emails include the expected window.</li>
<li><strong>Business days</strong> — Monday through Friday, excluding public holidays at the fulfillment location.</li>
</ul>
<p>Orders placed after the daily cutoff may start processing the next business day.</p>

<h2>Tracking your shipment</h2>
<ol>
<li>Sign in and open <a href="/account/orders">My orders</a> for live status, payment, and shipping method.</li>
<li>Use the link in your order confirmation or shipping email (guests included).</li>
<li>When a carrier tracking number is available, it appears on the order detail page and in email.</li>
</ol>
<p>More detail: <a href="/order-tracking">Order Tracking</a>. Still stuck? See the <a href="/faq">FAQ</a> or <a href="/help">help center</a>.</p>

<h2>International shipping</h2>
<p>When international methods are enabled, duties, taxes, and customs delays are the buyer’s responsibility unless stated otherwise at checkout. Delivery times for cross-border parcels are estimates and often longer than domestic Standard or Express.</p>
<ul>
<li>Provide a complete address including postal code and phone number for customs clearance.</li>
<li>Restricted or prohibited items cannot ship to certain destinations.</li>
<li>Failed delivery due to incomplete customs information may incur return fees.</li>
</ul>

<h2>Delays and exceptions</h2>
<blockquote><p>Delivery windows are estimates. Carriers may take longer during peak seasons, severe weather, strikes, or customs holds — track your order for live status.</p></blockquote>
<p>If your parcel is marked delivered but you cannot find it, check with neighbors and building staff, then contact us within a few days with your order number. Damaged or wrong items are covered under our <a href="/returns">Refund &amp; Return Policy</a>.</p>

<h2>Need help?</h2>
<p>Visit <a href="/contact">Contact us</a> or email <a href="mailto:support@example.com">support@example.com</a> with your order number.</p>
HTML,
            ],
            [
                'slug' => 'returns',
                'title' => 'Refund & Return Policy',
                'category' => 'info',
                'template' => 'legal',
                'short' => '30-day returns, eligibility, restocking, and step-by-step refund process.',
                'body' => <<<'HTML'
<h2>1. Overview</h2>
<p>We want you to be happy with every purchase. Most unused items can be returned within <strong>30 days</strong> of delivery when they meet the conditions below. Personalized, perishable, digital, or final-sale items may be excluded — check the product page before you buy.</p>
<blockquote><p>Keep your confirmation email and order number handy — it makes returns and refunds much faster.</p></blockquote>

<h2>2. Eligibility conditions</h2>
<ul>
<li>Return request started within <strong>30 days</strong> of the delivery date (or estimated delivery if tracking is missing).</li>
<li>Item is unused, unwashed, and in original packaging with all tags, accessories, and manuals included.</li>
<li>Proof of purchase (order number) is provided via <a href="/account/orders">My orders</a> or email.</li>
<li>Item is not marked final sale, opened hygiene-sensitive, or custom-made unless defective.</li>
</ul>
<p>Wrong, damaged, or defective items are always eligible — contact us promptly with photos. Warranty coverage after the return window may still apply under the <a href="/warranty">Warranty &amp; Authenticity Guarantee</a>.</p>

<h2>3. Restocking fee</h2>
<p>Returns that arrive incomplete, used, or without original packaging may be refused or subject to a restocking fee of up to <strong>15%</strong> of the item price, at our discretion. No restocking fee applies when the return is our fault (wrong item, defect, or shipping damage).</p>

<h2>4. Step-by-step refund process</h2>
<ol>
<li><strong>Start the return</strong> — Sign in, open the order from <a href="/account/orders">My orders</a>, and request a return from the order detail page. Guests may email support with the order number and reason.</li>
<li><strong>Receive instructions</strong> — We confirm eligibility and send packing and label instructions (prepaid label when the return is our fault; otherwise return shipping may be your responsibility).</li>
<li><strong>Pack securely</strong> — Use the original box when possible, include all accessories, and write the order number on a note inside the parcel.</li>
<li><strong>Ship the parcel</strong> — Drop it off with the carrier specified in our instructions and keep the drop-off receipt.</li>
<li><strong>Inspection</strong> — After we receive the return, we inspect it within a few business days.</li>
<li><strong>Refund issued</strong> — Approved refunds go to the <strong>original payment method</strong>. When an admin issues a store voucher instead, it appears under <a href="/account/vouchers">My vouchers</a>.</li>
</ol>

<h2>5. Refund timing and shipping fees</h2>
<ul>
<li>Bank or card refunds often appear within <strong>5–10 business days</strong> after we process them; timing depends on your provider.</li>
<li>Offline methods (bank transfer, COD, check) are refunded using the path we confirm by email.</li>
<li>Original shipping fees are refunded only when the return is our fault (damaged, wrong item, or lost parcel we replace).</li>
<li>Return shipping costs are yours unless we provide a prepaid label for an error on our side.</li>
</ul>

<h2>6. Exchanges</h2>
<p>Prefer a different size, color, or model? Return the original item under this policy, then place a new order — or contact us and we will advise the fastest path for your case. Stock is not reserved until the new order is placed.</p>

<h2>7. Non-returnable items</h2>
<ul>
<li>Gift cards and downloadable content (when offered).</li>
<li>Items damaged by misuse, unauthorized modification, or normal wear after delivery.</li>
<li>Products missing serial numbers or authenticity seals when those were required on the packaging.</li>
</ul>

<h2>8. Contact</h2>
<p>Questions? Read the <a href="/faq">FAQ</a>, visit <a href="/shipping">Shipping &amp; Delivery</a>, or reach us via <a href="/contact">Contact us</a>.</p>
HTML,
            ],
            [
                'slug' => 'about',
                'title' => 'About Us',
                'category' => 'info',
                'template' => 'about',
                'short' => 'Our brand story, mission, and commitment to quality.',
                'body' => <<<'HTML'
<h2>Our story</h2>
<p>We built this store for people who want a clear catalog, honest prices, and checkout that stays out of the way. Every product page, cart, and account screen is ready for real shopping — not a placeholder theme.</p>
<p>What started as a focused selection of reliable everyday essentials has grown into a full demo catalog you can click through end to end: browse, compare, add to cart, pay with an offline method, and manage orders from your account.</p>

<h2>Our mission</h2>
<p>Make online shopping feel straightforward and trustworthy. We believe customers deserve accurate product information, transparent shipping costs, and support that does not hide behind ticket bots.</p>
<ul>
<li><strong>Clarity first</strong> — photos, specs, and pricing you can verify before you buy.</li>
<li><strong>Fair checkout</strong> — tax and shipping calculated up front; no surprise fees at the last step.</li>
<li><strong>Human help</strong> — order tracking, returns, and account tools in one place, with a real <a href="/contact">contact</a> path when you need it.</li>
</ul>

<h2>Quality we stand behind</h2>
<p>We curate products we would use ourselves and back them with clear policies for <a href="/shipping">shipping</a>, <a href="/returns">returns</a>, and <a href="/warranty">warranty &amp; authenticity</a>. Defective or wrong items are prioritized — include your order number and we will make it right.</p>
<blockquote><p>This demo catalog is seeded so merchants can judge the theme with realistic journeys. Replace this story with your own brand voice before launch.</p></blockquote>

<h2>Visit the shop</h2>
<p>Explore the <a href="/catalog">full catalog</a>, skim the <a href="/faq">FAQ</a>, or open the <a href="/help">help center</a> if you need a hand. Prefer email or phone? See <a href="/contact">Contact us</a>.</p>
HTML,
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact Us',
                'category' => 'info',
                'template' => 'contact',
                'short' => 'Email, phone, hours, address, and the on-page contact form.',
                'body' => <<<'HTML'
<h2>Get in touch</h2>
<p>We usually reply within <strong>one business day</strong>. For order questions, include your order number so we can help faster. You can also use the <strong>contact form on this page</strong> — it sends your message straight to the store team.</p>

<h2>Channels</h2>
<ul>
<li><strong>Email:</strong> <a href="mailto:support@example.com">support@example.com</a></li>
<li><strong>Phone:</strong> (+1) 1234-567-890</li>
<li><strong>Contact form:</strong> use the form on this page for general questions, order help, or partnership requests.</li>
</ul>

<h2>Store hours</h2>
<table>
<thead>
<tr><th>Day</th><th>Hours</th></tr>
</thead>
<tbody>
<tr><td>Monday – Friday</td><td>9:00 AM – 6:00 PM (local store time)</td></tr>
<tr><td>Saturday</td><td>10:00 AM – 4:00 PM</td></tr>
<tr><td>Sunday &amp; holidays</td><td>Closed (email monitored next business day)</td></tr>
</tbody>
</table>

<h2>Store address</h2>
<p>123 Demo Street<br>City, Region 00000<br>United States</p>

<h2>Before you write</h2>
<ul>
<li>Track an existing order from <a href="/account/orders">My orders</a> or read <a href="/order-tracking">Order Tracking</a>.</li>
<li>Review <a href="/shipping">shipping</a>, <a href="/returns">returns</a>, and <a href="/payment-security">payment &amp; security</a> policies.</li>
<li>Common answers live in the <a href="/faq">FAQ</a> and <a href="/help">help center</a>.</li>
</ul>
<blockquote><p>Urgent delivery issues on a business day? Call during store hours and have your order number ready.</p></blockquote>
HTML,
            ],
            [
                'slug' => 'faq',
                'title' => 'FAQ',
                'category' => 'info',
                'template' => 'faq',
                'short' => 'Tracking, sizing, payments, shipping, returns, and account answers.',
                'body' => <<<'HTML'
<h2>Frequently asked questions</h2>

<h3>How do I track my order?</h3>
<p>Sign in and open <a href="/account/orders">My orders</a>. Guests should use the link in the order confirmation email. Full guide: <a href="/order-tracking">Order Tracking</a>.</p>

<h3>Can I change or cancel my order?</h3>
<p>If the order has not yet shipped, email <a href="mailto:support@example.com">support@example.com</a> or use <a href="/contact">Contact us</a> with your order number. Once a carrier label is created, changes may not be possible — we will advise the fastest option (redirect, return, or replacement).</p>

<h3>How do sizing or product options work?</h3>
<p>Choose size, color, or other options on the product page before adding to cart. Need a different option after purchase? Start a return under the <a href="/returns">Refund &amp; Return Policy</a>, then place a new order for the correct variant.</p>

<h3>How long does shipping take?</h3>
<p>Standard is usually 3–5 business days after handling. Express and Overnight are faster when enabled. Free shipping unlocks at $75+. See <a href="/shipping">Shipping &amp; Delivery</a> for the full rates table.</p>

<h3>Which payment methods do you accept?</h3>
<p>This demo supports offline methods such as bank transfer, cash on delivery, check, store credit, and invoice. Live card and wallet gateways can be enabled from admin. Details: <a href="/payment-security">Payment &amp; Security</a>.</p>

<h3>Can I return an item?</h3>
<p>Yes — most unused items within <strong>30 days</strong> of delivery. Start from the order page. Policy: <a href="/returns">Refund &amp; Return Policy</a>.</p>

<h3>Are products authentic?</h3>
<p>Yes. We stand behind genuineness and defect coverage — see the <a href="/warranty">Warranty &amp; Authenticity Guarantee</a>.</p>

<h3>Which coupons work in this demo?</h3>
<p><strong>SAVE10</strong> (10% off) and <strong>WELCOME15</strong> ($15 off carts over $80). Coupons apply in the cart before you pay.</p>

<h3>Demo customer login?</h3>
<p><strong>demo@wentocart.test</strong> / <strong>password</strong>. Admin: <strong>admin@wentocart.test</strong> / <strong>password</strong> (local installs).</p>

<h3>How do I become a seller?</h3>
<p>Sign in, open Become a seller, and submit a shop request. After an admin accepts it, Seller Dashboard appears in My Account.</p>

<p>Still stuck? Visit the <a href="/help">help center</a> or <a href="/contact">contact support</a>.</p>
HTML,
            ],
            [
                'slug' => 'payment-security',
                'title' => 'Payment & Security Methods',
                'category' => 'info',
                'template' => 'legal',
                'short' => 'Accepted payments, wallets, COD/offline demo methods, and SSL security.',
                'body' => <<<'HTML'
<h2>Secure checkout</h2>
<p>Protecting your payment details is essential. The storefront is served over <strong>HTTPS / SSL</strong> so data in transit is encrypted. Account sessions use Sanctum <strong>cookie authentication</strong> with CSRF protection — we do not store JWT tokens in localStorage.</p>
<blockquote><p>Never share your password or one-time codes. Our team will never ask for your full card number by email or phone.</p></blockquote>

<h2>Accepted payment methods</h2>
<table>
<thead>
<tr><th>Method</th><th>Availability</th><th>Notes</th></tr>
</thead>
<tbody>
<tr><td><strong>Credit / debit cards</strong></td><td>When a live gateway is enabled</td><td>Visa, Mastercard, and other cards supported by your processor.</td></tr>
<tr><td><strong>Digital wallets</strong></td><td>When enabled</td><td>Wallet options depend on the payment module configured in admin.</td></tr>
<tr><td><strong>Bank transfer</strong></td><td>Demo / offline</td><td>Instructions appear after checkout; order may wait for confirmation.</td></tr>
<tr><td><strong>Cash on delivery (COD)</strong></td><td>Demo / offline</td><td>Pay the courier when the parcel arrives, where offered.</td></tr>
<tr><td><strong>Check / invoice / store credit</strong></td><td>Demo / offline</td><td>Useful for B2B-style flows and voucher balances.</td></tr>
</tbody>
</table>
<p>This demo ships with offline methods so you can complete checkout without a live processor. Merchants enable card gateways later from admin payment settings.</p>

<h2>How card and wallet payments work</h2>
<ol>
<li>Select your method at checkout and confirm the order total (items, tax, shipping).</li>
<li>You are redirected or prompted by the payment partner to authorize the charge.</li>
<li>On success, you receive a confirmation email and can track the order from <a href="/account/orders">My orders</a>.</li>
</ol>
<p>We do not store full card primary account numbers on our servers when using tokenized gateways.</p>

<h2>Offline and COD demo flow</h2>
<ul>
<li>Follow the on-screen instructions after placing the order (bank details, check mailing, or COD reminder).</li>
<li>Orders may stay in a pending payment state until staff confirms receipt.</li>
<li>COD refusals or failed bank transfers may cancel the order after a merchant-defined waiting period.</li>
</ul>

<h2>Security practices</h2>
<ul>
<li><strong>SSL / TLS</strong> — encrypts pages that collect account or checkout data.</li>
<li><strong>Hashed passwords</strong> — credentials are never stored in plain text.</li>
<li><strong>Role-based admin access</strong> — staff see only what their permissions allow.</li>
<li><strong>Fraud review</strong> — suspicious orders may be held or cancelled under our <a href="/terms">Terms of Service</a>.</li>
</ul>
<p>Privacy details: <a href="/privacy">Privacy Policy</a>. Billing issues: <a href="/contact">Contact us</a>.</p>
HTML,
            ],
            [
                'slug' => 'order-tracking',
                'title' => 'Order Tracking',
                'category' => 'info',
                'template' => 'blank',
                'short' => 'How to track shipments from your account and confirmation email.',
                'body' => <<<'HTML'
<h2>Track your order in two ways</h2>
<p>Every confirmed order can be followed from your customer account or from the links in your confirmation and shipping emails. Status updates cover payment, packing, shipment, and delivery.</p>

<h2>Option 1 — Track from your account</h2>
<ol>
<li>Sign in to the storefront.</li>
<li>Open <a href="/account/orders">My orders</a>.</li>
<li>Select the order to see status, payment method, shipping method, and any carrier tracking number once available.</li>
</ol>
<p>Guests who created an account at checkout can use the same path after verifying their email.</p>

<h2>Option 2 — Track from email</h2>
<ol>
<li>Open the order confirmation email sent after checkout.</li>
<li>Use the order link to view details (some installs require the same browser session or a signed link).</li>
<li>When the parcel ships, a shipping email may include the carrier name and tracking number.</li>
</ol>
<blockquote><p>Tip: Add <strong>support@example.com</strong> to your address book so confirmation messages do not land in spam.</p></blockquote>

<h2>What each status usually means</h2>
<table>
<thead>
<tr><th>Status</th><th>Meaning</th></tr>
</thead>
<tbody>
<tr><td><strong>Pending / awaiting payment</strong></td><td>Order received; waiting for offline payment confirmation when applicable.</td></tr>
<tr><td><strong>Processing</strong></td><td>Payment accepted; warehouse is packing your items.</td></tr>
<tr><td><strong>Shipped</strong></td><td>Parcel handed to the carrier; tracking may appear here.</td></tr>
<tr><td><strong>Delivered</strong></td><td>Carrier reports delivery — check the address and neighbors if missing.</td></tr>
<tr><td><strong>Cancelled / refunded</strong></td><td>Order stopped or money returned per our policies.</td></tr>
</tbody>
</table>

<h2>Missing email or stuck status?</h2>
<ul>
<li>Check spam and search for your order number.</li>
<li>Confirm the email on your account is correct.</li>
<li>Allow <strong>1–2 business days</strong> after payment for processing before expecting a ship notice.</li>
<li>Read <a href="/shipping">Shipping &amp; Delivery</a> for handling times and delay causes.</li>
</ul>
<p>Still need help? <a href="/contact">Contact us</a> with your order number, or browse the <a href="/faq">FAQ</a> and <a href="/help">help center</a>.</p>
HTML,
            ],
            [
                'slug' => 'warranty',
                'title' => 'Warranty & Authenticity Guarantee',
                'category' => 'info',
                'template' => 'legal',
                'short' => 'Genuine products, coverage period, and how to file a warranty claim.',
                'body' => <<<'HTML'
<h2>Authenticity guarantee</h2>
<p>We sell <strong>genuine products</strong> sourced through legitimate channels. Packaging, serial numbers (when applicable), and manufacturer seals should match what the brand intends for retail sale. If you receive an item you believe is counterfeit, contact us immediately with photos and your order number — we will investigate and make it right.</p>

<h2>Warranty coverage</h2>
<p>Unless a product page states a different period, eligible items include a <strong>12-month limited warranty</strong> against manufacturing defects from the date of delivery. The warranty covers faults that appear under normal use according to the manufacturer’s instructions.</p>
<ul>
<li><strong>Covered</strong> — defects in materials or workmanship present at sale or arising in normal use during the coverage period.</li>
<li><strong>Not covered</strong> — accidental damage, liquid damage, unauthorized modification, misuse, normal wear, or consumables (batteries, cables) beyond their expected life.</li>
<li><strong>Third-party brand warranties</strong> — some electronics include a manufacturer warranty that runs in parallel; keep your proof of purchase.</li>
</ul>
<blockquote><p>Change-of-mind returns within 30 days follow the <a href="/returns">Refund &amp; Return Policy</a>, not this warranty process.</p></blockquote>

<h2>How to file a claim</h2>
<ol>
<li><strong>Gather details</strong> — order number, product name/SKU, clear photos or short video of the issue, and a brief description.</li>
<li><strong>Contact us</strong> — email <a href="mailto:support@example.com">support@example.com</a>, use the <a href="/contact">contact form</a>, or open the order from <a href="/account/orders">My orders</a> and reference the warranty.</li>
<li><strong>Diagnosis</strong> — we confirm coverage and may ask troubleshooting steps or a return for inspection.</li>
<li><strong>Resolution</strong> — depending on the case: repair guidance, replacement, store voucher, or refund to the original payment method.</li>
</ol>

<h2>Claim timelines</h2>
<p>We aim to acknowledge warranty requests within <strong>one business day</strong> and complete inspection within a few business days after we receive a returned unit. Complex manufacturer escalations may take longer — we will keep you updated by email.</p>

<h2>Shipping for warranty returns</h2>
<p>If we ask you to return a defective item, we provide packing instructions. Prepaid labels are typically issued when the defect is confirmed or highly likely; otherwise we will explain options before you ship.</p>

<h2>Related policies</h2>
<ul>
<li><a href="/returns">Refund &amp; Return Policy</a> — 30-day change-of-mind and wrong-item returns.</li>
<li><a href="/shipping">Shipping &amp; Delivery</a> — damaged-in-transit reporting.</li>
<li><a href="/terms">Terms &amp; Conditions</a> — general purchase terms.</li>
<li><a href="/payment-security">Payment &amp; Security</a> — how charges and refunds are handled.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'help',
                'title' => 'Help center',
                'category' => 'info',
                'template' => 'blank',
                'short' => 'Orders, shipping, returns, payments, warranties, and your account.',
                'body' => <<<'HTML'
<h2>Welcome to the help center</h2>
<p>Quick links to the policies and tools most shoppers need. For a longer Q&amp;A list, open the <a href="/faq">FAQ</a>.</p>

<h2>Track an order</h2>
<p>Sign in and open <a href="/account/orders">My orders</a>. Guests can use the confirmation email link. Step-by-step: <a href="/order-tracking">Order Tracking</a>.</p>

<h2>Shipping</h2>
<p>At checkout you can pick from the shipping methods configured for the shop (demo includes Standard delivery and Free shipping when the cart is $75+). Full rates and international notes: <a href="/shipping">Shipping &amp; Delivery Policy</a>.</p>

<h2>Returns &amp; refunds</h2>
<p>Most unused items can be returned within <strong>30 days</strong>. Start from the order page or email support. Policy: <a href="/returns">Refund &amp; Return Policy</a>.</p>

<h2>Warranty &amp; authenticity</h2>
<p>Genuine products with limited defect coverage — claim steps on <a href="/warranty">Warranty &amp; Authenticity Guarantee</a>.</p>

<h2>Payments &amp; security</h2>
<p>This demo uses offline methods such as bank transfer, cash on delivery, check, store credit, and invoice. Live card gateways can be enabled later from admin. Read <a href="/payment-security">Payment &amp; Security Methods</a>.</p>

<h2>Coupons</h2>
<p>Try <strong>SAVE10</strong> for 10% off, or <strong>WELCOME15</strong> for $15 off orders over $80. Coupons apply in the cart before you pay.</p>

<h2>Account &amp; selling</h2>
<ul>
<li>Demo customer: <strong>demo@wentocart.test</strong> / <strong>password</strong></li>
<li>To sell: sign in → Become a seller → wait for admin approval → use Seller Dashboard.</li>
</ul>

<h2>Legal</h2>
<p><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms &amp; Conditions</a> · <a href="/contact">Contact us</a></p>
HTML,
            ],
        ];
    }

    public static function bodyForSlug(string $slug): ?string
    {
        foreach (self::pages() as $page) {
            if ($page['slug'] === $slug) {
                return $page['body'];
            }
        }

        return null;
    }
}
