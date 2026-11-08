<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use InvalidArgumentException;
use RuntimeException;

class ContentWriter
{
    public const ACTIONS = ['generate', 'structure', 'improve', 'shorten', 'expand', 'seo'];

    public function __construct(protected AiGateway $gateway)
    {
        // Gateway kept for future chat lanes; content uses AiProviderFactory.
    }

    /**
     * @param  array{action: string, field: string, text?: ?string, locale?: ?string, context?: array<string, mixed>}  $input
     */
    public function write(array $input): string
    {
        if (! AiSettings::contentEnabled()) {
            throw new RuntimeException('AI content generation is not enabled. Configure it under Configure → AI.');
        }

        $action = strtolower(trim((string) ($input['action'] ?? '')));
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported AI content action.');
        }

        $field = trim((string) ($input['field'] ?? 'text'));
        $text = trim((string) ($input['text'] ?? ''));
        $locale = trim((string) ($input['locale'] ?? app()->getLocale() ?: 'en'));
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];

        if (in_array($action, ['improve', 'shorten', 'expand', 'seo'], true) && $text === '') {
            throw new InvalidArgumentException('Enter some text before asking AI to rewrite it.');
        }

        if (
            in_array($action, ['generate', 'structure'], true)
            && $this->isRichBody($field)
            && $text === ''
            && ! $this->hasEnoughContext($context)
        ) {
            throw new InvalidArgumentException('Add a page or product title first, or enter some text for AI to use.');
        }

        if (mb_strlen($text) > 8000) {
            throw new InvalidArgumentException('Text is too long for AI (max 8000 characters).');
        }

        $provider = $this->provider();
        $active = AiSettings::activeConnection();
        $prompt = $this->prompt($action, $field, $text, $locale, $context);
        $htmlBody = $this->isRichBody($field);

        $result = $provider->complete($prompt, [
            'model' => $active['model'] ?: AiSettings::model(),
            'system' => $htmlBody
                ? 'You write clear ecommerce page and product HTML for a TipTap editor. Return ONLY HTML fragments using h2, h3, p, ul, ol, li, strong, em, and a. No html/body wrappers, no markdown fences, no preamble.'
                : 'You are an ecommerce copywriter for a merchant admin. Return only the field content. No quotes around the whole answer unless the field itself needs them.',
            'max_tokens' => $this->maxTokens($field, $action),
            'temperature' => in_array($action, ['seo', 'structure'], true) ? 0.4 : 0.65,
        ]);

        return $this->normalize($result, $field);
    }

    protected function provider(): AiProvider
    {
        $active = AiSettings::activeConnection();
        if ($active === null) {
            throw new RuntimeException('No active AI connection. Add one under Configure → AI and set it as current.');
        }

        return AiProviderFactory::make($active);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function hasEnoughContext(array $context): bool
    {
        foreach (['product_name', 'name', 'title', 'sku', 'brand', 'shop_name'] as $key) {
            if (! empty($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function isRichBody(string $field): bool
    {
        if (str_contains($field, 'short_description') || str_contains($field, 'meta_')) {
            return false;
        }

        return str_contains($field, 'body')
            || preg_match('/(^|\.)description$/', $field) === 1
            || in_array($field, ['description', 'body'], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function prompt(string $action, string $field, string $text, string $locale, array $context): string
    {
        $bits = [];
        foreach (['product_name', 'name', 'title', 'sku', 'brand', 'shop_name', 'slug', 'template'] as $key) {
            if (! empty($context[$key]) && is_scalar($context[$key])) {
                $bits[] = $key.': '.(string) $context[$key];
            }
        }
        if (! empty($context['categories']) && is_array($context['categories'])) {
            $bits[] = 'categories: '.implode(', ', array_map('strval', $context['categories']));
        }
        $ctx = $bits !== [] ? "Shop context:\n- ".implode("\n- ", $bits)."\n\n" : '';
        $label = $this->fieldLabel($field);
        $lang = "Write in language code \"{$locale}\".";
        $htmlRules = $this->isRichBody($field)
            ? "\nHTML rules: use semantic structure (h2/h3 section headings, short paragraphs, bullet or numbered lists where helpful). Keep tone helpful and merchant-trustworthy. Do not invent legal claims, prices, or contact details that are not implied by the context."
            : '';

        return match ($action) {
            'generate' => $this->isRichBody($field)
                ? "{$ctx}{$lang}{$htmlRules}\nGenerate a complete, well-organized {$label} suitable for a store info/policy/content page or product detail. Prefer clear sections with headings and lists over one long blob. Return only the HTML."
                : "{$ctx}{$lang}\nGenerate a strong ecommerce {$label}. Return only the {$label}.",
            'structure' => $text !== ''
                ? "{$ctx}{$lang}{$htmlRules}\nReorganize and format this {$label} into clear, scannable HTML. Keep the meaning and facts. Add sensible headings and lists; fix awkward walls of text. Return only the HTML.\n\nCurrent content:\n{$text}"
                : "{$ctx}{$lang}{$htmlRules}\nWrite a well-organized {$label} from the shop context above. Use clear headings, short paragraphs, and lists. Return only the HTML.",
            'improve' => "{$ctx}{$lang}{$htmlRules}\nImprove this ecommerce {$label}. Keep the meaning, make it clearer and more persuasive.\n\nCurrent text:\n{$text}",
            'shorten' => "{$ctx}{$lang}{$htmlRules}\nShorten this ecommerce {$label} without losing key facts.\n\nCurrent text:\n{$text}",
            'expand' => "{$ctx}{$lang}{$htmlRules}\nExpand this ecommerce {$label} with useful detail for shoppers. Keep structure clear.\n\nCurrent text:\n{$text}",
            'seo' => "{$ctx}{$lang}{$htmlRules}\nRewrite this as SEO-friendly ecommerce {$label}. Avoid keyword stuffing.\n\nCurrent text:\n{$text}",
            default => throw new InvalidArgumentException('Unsupported AI content action.'),
        };
    }

    protected function fieldLabel(string $field): string
    {
        return match ($field) {
            'product.name', 'category.name', 'brand.name', 'cms.title', 'title', 'name' => 'title / name',
            'product.description' => 'HTML product description',
            'category.description' => 'HTML category description',
            'cms.body', 'body' => 'HTML CMS page body',
            'description' => 'HTML description',
            'product.short_description', 'cms.short_description', 'short_description' => 'short description',
            'product.meta_title', 'cms.meta_title', 'meta_title' => 'SEO meta title (max ~60 chars)',
            'product.meta_description', 'cms.meta_description', 'meta_description' => 'SEO meta description (max ~160 chars)',
            'product.meta_keywords', 'cms.meta_keywords', 'meta_keywords' => 'comma-separated SEO keywords',
            default => 'text field ('.$field.')',
        };
    }

    protected function maxTokens(string $field, string $action): int
    {
        if (str_contains($field, 'meta_title')) {
            return 80;
        }
        if (str_contains($field, 'meta_description') || str_contains($field, 'short_description')) {
            return 200;
        }
        if (str_contains($field, 'meta_keywords') || str_contains($field, 'name') || str_contains($field, 'title')) {
            return 120;
        }
        if ($this->isRichBody($field)) {
            if ($action === 'shorten') {
                return 600;
            }
            if (in_array($action, ['generate', 'structure', 'expand'], true)) {
                return 2200;
            }

            return 1600;
        }

        return 800;
    }

    protected function normalize(string $text, string $field): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:html|text)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);

        if (str_contains($field, 'meta_title')) {
            return mb_substr($text, 0, 70);
        }
        if (str_contains($field, 'meta_description')) {
            return mb_substr($text, 0, 180);
        }

        return $text;
    }
}
