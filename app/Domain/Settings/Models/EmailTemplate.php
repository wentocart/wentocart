<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\TemplateContentLocales;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'subject', 'body', 'design_json', 'is_enabled', 'event_code', 'recipient', 'is_custom', 'store_id'])]
class EmailTemplate extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'email';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EmailTemplateTranslation::class);
    }

    public static function render(string $code, array $vars, ?string $locale = null): ?self
    {
        $template = static::query()->where('code', $code)->where('is_enabled', true)->first();

        if (! $template) {
            return null;
        }

        $localized = TemplateContentLocales::resolveEmail($template, $locale);
        $clone = $template->replicate();
        $clone->id = $template->id;
        $clone->subject = self::replaceVars($localized['subject'], $vars);
        $clone->body = self::replaceVars($localized['body'], $vars);

        return $clone;
    }

    public static function replaceVars(string $text, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($text, $replace);
    }
}
