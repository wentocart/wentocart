<?php

namespace App\Domain\Cms;

/**
 * Predefined CMS page body templates for admin “pick and edit”.
 */
final class CmsPageTemplates
{
    public static function options(): array
    {
        return [
            ['value' => 'blank', 'label' => 'Blank', 'hint' => 'Start from an empty page'],
            ['value' => 'about', 'label' => 'About us', 'hint' => 'Company story layout'],
            ['value' => 'contact', 'label' => 'Contact', 'hint' => 'Contact details and form intro'],
            ['value' => 'faq', 'label' => 'FAQ', 'hint' => 'Question and answer sections'],
            ['value' => 'legal', 'label' => 'Legal / policy', 'hint' => 'Numbered policy sections'],
            ['value' => 'landing', 'label' => 'Landing', 'hint' => 'Hero + features + CTA'],
        ];
    }

    public static function codes(): array
    {
        return array_column(self::options(), 'value');
    }

    /**
     * Map legacy / unknown codes (e.g. seeded "default") to a valid option.
     */
    public static function normalize(?string $code): string
    {
        $code = trim((string) $code);
        if ($code !== '' && in_array($code, self::codes(), true)) {
            return $code;
        }

        return 'blank';
    }

    public static function body(string $code): string
    {
        return match ($code) {
            'about' => <<<'HTML'
<h2>Our story</h2>
<p>Tell customers who you are and why you started this store.</p>
<h2>What we believe</h2>
<ul>
<li>Quality products</li>
<li>Honest pricing</li>
<li>Helpful support</li>
</ul>
HTML,
            'contact' => <<<'HTML'
<h2>Get in touch</h2>
<p>We usually reply within one business day.</p>
<p><strong>Email:</strong> support@example.com<br><strong>Phone:</strong> +1 000 000 0000</p>
<h2>Store address</h2>
<p>123 Example Street<br>City, Region 00000</p>
HTML,
            'faq' => <<<'HTML'
<h2>Frequently asked questions</h2>
<h3>How long does shipping take?</h3>
<p>Most orders arrive within 3–7 business days. Exact options appear at checkout.</p>
<h3>Can I return an item?</h3>
<p>Yes — unused items can usually be returned within 7 days. See the returns policy for steps.</p>
<h3>How do I track an order?</h3>
<p>Sign in and open My orders, or use the link in your confirmation email.</p>
HTML,
            'legal' => <<<'HTML'
<h2>1. Introduction</h2>
<p>Replace this with your policy introduction.</p>
<h2>2. Information we collect</h2>
<p>Describe what data you collect and why.</p>
<h2>3. How we use information</h2>
<p>Explain how customer data is used.</p>
<h2>4. Contact</h2>
<p>Questions about this policy? Email support@example.com.</p>
HTML,
            'landing' => <<<'HTML'
<h1>Welcome</h1>
<p>A short hero sentence that explains your offer.</p>
<h2>Why shop with us</h2>
<ul>
<li>Feature one</li>
<li>Feature two</li>
<li>Feature three</li>
</ul>
<p><a href="/catalog">Shop the catalog</a></p>
HTML,
            default => '',
        };
    }
}
