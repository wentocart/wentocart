<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\TemplateContentLocales;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id',
    'code',
    'name',
    'type',
    'email_subject',
    'body',
    'design_json',
    'is_system',
    'is_custom',
    'is_enabled',
    'is_in_use',
    'position',
])]
class DocumentTemplate extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'settings';

    public function translations(): HasMany
    {
        return $this->hasMany(DocumentTemplateTranslation::class);
    }

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_SLIP = 'credit_slip';

    public const TYPE_DELIVERY_SLIP = 'delivery_slip';

    public const TYPE_CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_INVOICE => 'Invoice',
            self::TYPE_CREDIT_SLIP => 'Credit slip',
            self::TYPE_DELIVERY_SLIP => 'Delivery slip',
            self::TYPE_CUSTOM => 'Custom document',
        ];
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_custom' => 'boolean',
            'is_enabled' => 'boolean',
            'is_in_use' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        $this->loadMissing('translations');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'email_subject' => $this->email_subject,
            'body' => $this->body,
            'design_json' => $this->design_json,
            'translations' => TemplateContentLocales::documentMap($this),
            'is_system' => $this->is_system,
            'is_custom' => $this->is_custom,
            'is_enabled' => $this->is_enabled,
            'is_in_use' => $this->is_in_use,
            'position' => $this->position,
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
