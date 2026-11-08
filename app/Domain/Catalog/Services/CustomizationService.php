<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Support\AttachmentUploadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomizationService
{
    public function types(): array
    {
        return array_values(config('wento.customization.field_types', [
            'text', 'textarea', 'select', 'radio', 'checkbox', 'file', 'image',
        ]));
    }

    public function choiceTypes(): array
    {
        return ['select', 'radio', 'checkbox'];
    }

    public function textTypes(): array
    {
        return ['text', 'textarea'];
    }

    public function fileTypes(): array
    {
        return ['file', 'image'];
    }

    public function sync(Product $product, bool $enabled, array $fields): void
    {
        $product->update(['customization_enabled' => $enabled]);

        $keep = [];
        foreach (array_values($fields) as $index => $row) {
            $payload = $this->normalizeField($row, $index);
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $model = $id ? $product->customizationFields()->whereKey($id)->first() : null;
            if ($model) {
                $model->update($payload);
            } else {
                $model = $product->customizationFields()->create($payload);
            }
            $keep[] = $model->id;
        }

        $query = $product->customizationFields();
        if ($keep) {
            $query->whereNotIn('id', $keep);
        }
        $query->delete();
    }

    /**
     * Create one customizable option (admin nested REST).
     *
     * @param  array<string, mixed>  $row
     */
    public function createField(Product $product, array $row): ProductCustomizationField
    {
        $position = (int) ($row['position'] ?? $row['sortOrder'] ?? $row['sort_order'] ?? (
            (int) ($product->customizationFields()->max('position') ?? -1) + 1
        ));
        $payload = $this->normalizeField($this->mapOptionInput($row), $position);
        $field = $product->customizationFields()->create($payload);
        if (! $product->customization_enabled) {
            $product->update(['customization_enabled' => true]);
        }

        return $field->fresh() ?? $field;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function updateField(ProductCustomizationField $field, array $row): ProductCustomizationField
    {
        $merged = array_merge($this->presentField($field), $this->mapOptionInput($row));
        $position = (int) ($row['position'] ?? $row['sortOrder'] ?? $row['sort_order'] ?? $field->position);
        $payload = $this->normalizeField($merged, $position);
        $field->update($payload);

        return $field->fresh() ?? $field;
    }

    public function deleteField(ProductCustomizationField $field): void
    {
        $product = $field->product;
        $field->delete();
        if ($product && $product->customizationFields()->count() === 0) {
            $product->update(['customization_enabled' => false]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapOptionInput(array $row): array
    {
        $type = (string) ($row['type'] ?? 'text');
        $type = match ($type) {
            'field' => 'text',
            'area' => 'textarea',
            'drop_down', 'dropdown' => 'select',
            default => $type,
        };
        $row['type'] = $type;
        if (isset($row['isRequired']) && ! isset($row['is_required'])) {
            $row['is_required'] = $row['isRequired'];
        }
        if (isset($row['maxCharacters']) && ! isset($row['max_length'])) {
            $row['max_length'] = $row['maxCharacters'];
        }
        if (isset($row['priceType']) && ! isset($row['price_type'])) {
            $row['price_type'] = $row['priceType'];
        }
        if (isset($row['price']) && ! isset($row['price_amount'])) {
            $row['price_amount'] = $row['price'];
        }

        return $row;
    }

    public function duplicate(Product $from, Product $to): void
    {
        $to->update(['customization_enabled' => (bool) $from->customization_enabled]);
        foreach ($from->customizationFields as $field) {
            $copy = $field->replicate();
            $copy->product_id = $to->id;
            $copy->save();
        }
    }

    public function present(Product $product): array
    {
        return $product->customizationFields
            ->sortBy('position')
            ->values()
            ->map(fn (ProductCustomizationField $field) => $this->presentField($field))
            ->all();
    }

    public function presentField(ProductCustomizationField $field): array
    {
        $config = $field->config ?: [];

        return [
            'id' => $field->id,
            'type' => $field->type,
            'label' => $field->label,
            'is_required' => (bool) $field->is_required,
            'position' => (int) $field->position,
            'placeholder' => $config['placeholder'] ?? '',
            'help_text' => $config['help_text'] ?? '',
            'default_value' => $config['default_value'] ?? '',
            'min_length' => $config['min_length'] ?? null,
            'max_length' => $config['max_length'] ?? null,
            'allowed_file_types' => $config['allowed_file_types'] ?? '',
            'max_file_size_kb' => $config['max_file_size_kb'] ?? null,
            'price_type' => $config['price_type'] ?? 'none',
            'price_amount' => $config['price_amount'] ?? 0,
            'options' => array_values($config['options'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw  keyed by field id
     * @param  array<int|string, UploadedFile>  $files
     * @return array{lines: array<int, array<string, mixed>>, extra: float}
     */
    public function resolve(Product $product, array $raw, array $files = []): array
    {
        if (! $product->customization_enabled) {
            return ['lines' => [], 'extra' => 0.0];
        }

        $base = $product->sellingPrice();
        $lines = [];
        $extra = 0.0;

        foreach ($product->customizationFields()->orderBy('position')->get() as $field) {
            $key = (string) $field->id;
            $file = $files[$field->id] ?? $files[$key] ?? null;
            $value = $raw[$key] ?? $raw[$field->id] ?? null;
            $line = $this->resolveField($field, $value, $file instanceof UploadedFile ? $file : null, $base);
            if ($line === null) {
                continue;
            }
            $extra += (float) $line['price'];
            $lines[] = $line;
        }

        return ['lines' => $lines, 'extra' => round($extra, 2)];
    }

    protected function resolveField(ProductCustomizationField $field, mixed $value, ?UploadedFile $file, float $base): ?array
    {
        $config = $field->config ?: [];
        $type = $field->type;
        $display = '';
        $stored = null;
        $used = false;

        if (in_array($type, $this->fileTypes(), true)) {
            if ($file) {
                $this->assertUpload($field, $file);
                $path = $file->store(
                    'catalog/customizations/'.$field->product_id,
                    \App\Domain\Settings\ObjectStorageSettings::diskName()
                );
                $stored = $path;
                $display = $file->getClientOriginalName();
                $used = true;
            } else {
                $token = is_array($value) ? trim((string) ($value[0] ?? '')) : trim((string) ($value ?? ''));
                if ($token !== '') {
                    $staged = $this->consumeStagedFileToken($token, $field);
                    if ($staged !== null) {
                        $stored = $staged['path'];
                        $display = $staged['fileName'];
                        $used = true;
                    }
                }
            }
        } elseif (in_array($type, $this->choiceTypes(), true)) {
            $options = array_values($config['options'] ?? []);
            $selected = $type === 'checkbox' ? array_values((array) $value) : [is_array($value) ? ($value[0] ?? '') : (string) ($value ?? '')];
            $selected = array_values(array_filter(array_map('strval', $selected), fn ($item) => $item !== ''));
            $matched = [];
            foreach ($options as $option) {
                $optValue = (string) ($option['value'] ?? $option['label'] ?? '');
                if (in_array($optValue, $selected, true) || in_array((string) ($option['label'] ?? ''), $selected, true)) {
                    $matched[] = $option;
                }
            }
            if ($matched) {
                $used = true;
                $stored = $type === 'checkbox'
                    ? array_map(fn ($opt) => $opt['value'] ?? $opt['label'] ?? '', $matched)
                    : ($matched[0]['value'] ?? $matched[0]['label'] ?? '');
                $display = implode(', ', array_map(fn ($opt) => $opt['label'] ?? $opt['value'] ?? '', $matched));
            }
        } else {
            $text = is_array($value) ? implode(', ', $value) : trim((string) ($value ?? ''));
            if ($text !== '') {
                $this->assertText($field, $text);
                $stored = $text;
                $display = $text;
                $used = true;
            }
        }

        if (! $used) {
            if ($field->is_required) {
                throw ValidationException::withMessages([
                    'customization.'.$field->id => $field->label.' is required.',
                ]);
            }

            return null;
        }

        $price = $this->amount($config['price_type'] ?? 'none', $config['price_amount'] ?? 0, $base);
        if (in_array($type, $this->choiceTypes(), true)) {
            $options = array_values($config['options'] ?? []);
            $picked = is_array($stored) ? $stored : [$stored];
            foreach ($options as $option) {
                $optValue = (string) ($option['value'] ?? $option['label'] ?? '');
                if (! in_array($optValue, array_map('strval', $picked), true)) {
                    continue;
                }
                $price += $this->amount($option['price_type'] ?? 'fixed', $option['price'] ?? 0, $base);
            }
        }

        return [
            'field_id' => $field->id,
            'label' => $field->label,
            'type' => $type,
            'value' => $stored,
            'display' => $display,
            'price' => round($price, 2),
        ];
    }

    public function sameCustomization(?array $a, ?array $b): bool
    {
        return json_encode($a ?? []) === json_encode($b ?? []);
    }

    protected function normalizeField(array $row, int $position): array
    {
        $type = (string) ($row['type'] ?? 'text');
        if (! in_array($type, $this->types(), true)) {
            $type = 'text';
        }

        $incoming = is_array($row['config'] ?? null) ? $row['config'] : [];
        $options = [];
        foreach (array_values($row['options'] ?? $incoming['options'] ?? []) as $index => $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = trim((string) ($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $options[] = [
                'label' => $label,
                'value' => trim((string) ($option['value'] ?? '')) ?: Str::slug($label),
                'price_type' => in_array($option['price_type'] ?? 'fixed', ['none', 'fixed', 'percent'], true)
                    ? ($option['price_type'] ?? 'fixed')
                    : 'fixed',
                'price' => $this->number($option['price'] ?? 0),
                'is_default' => $this->boolish($option['is_default'] ?? false),
                'position' => $index,
            ];
        }

        $config = array_merge($incoming, [
            'placeholder' => (string) ($row['placeholder'] ?? $incoming['placeholder'] ?? ''),
            'help_text' => (string) ($row['help_text'] ?? $incoming['help_text'] ?? ''),
            'default_value' => (string) ($row['default_value'] ?? $incoming['default_value'] ?? ''),
            'min_length' => $this->nullableInt($row['min_length'] ?? $incoming['min_length'] ?? null),
            'max_length' => $this->nullableInt($row['max_length'] ?? $incoming['max_length'] ?? null),
            'allowed_file_types' => (string) ($row['allowed_file_types'] ?? $incoming['allowed_file_types'] ?? ''),
            'max_file_size_kb' => $this->nullableInt($row['max_file_size_kb'] ?? $incoming['max_file_size_kb'] ?? null),
            'price_type' => in_array($row['price_type'] ?? $incoming['price_type'] ?? 'none', ['none', 'fixed', 'percent'], true)
                ? ($row['price_type'] ?? $incoming['price_type'] ?? 'none')
                : 'none',
            'price_amount' => $this->number($row['price_amount'] ?? $incoming['price_amount'] ?? 0),
            'options' => $options,
        ]);

        return [
            'type' => $type,
            'label' => trim((string) ($row['label'] ?? '')) ?: 'Untitled field',
            'is_required' => $this->boolish($row['is_required'] ?? false),
            'position' => $position,
            'config' => $config,
        ];
    }

    protected function boolish(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function amount(string $type, mixed $amount, float $base): float
    {
        $value = $this->number($amount);
        if ($type === 'percent') {
            return round($base * ($value / 100), 2);
        }
        if ($type === 'fixed') {
            return $value;
        }

        return 0.0;
    }

    protected function number(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     *
     * @return array{token: string, fileName: string, optionId: int}
     */
    public function stageCustomizableFile(Product $product, ProductCustomizationField $field, UploadedFile $file): array
    {
        if ((int) $field->product_id !== (int) $product->id) {
            throw ValidationException::withMessages([
                'option_id' => 'Option does not belong to this product.',
            ]);
        }
        if (! in_array($field->type, $this->fileTypes(), true)) {
            throw ValidationException::withMessages([
                'option_id' => 'Option is not a file-type customizable option.',
            ]);
        }
        $this->assertUpload($field, $file);
        $token = (string) Str::uuid();
        $stagedPath = $file->store('catalog/customizations/staged/'.$product->id, 'local');
        Cache::put($this->stagedCacheKey($token), [
            'path' => $stagedPath,
            'disk' => 'local',
            'fileName' => (string) $file->getClientOriginalName(),
            'optionId' => (int) $field->id,
            'productId' => (int) $product->id,
        ], now()->addMinutes(60));

        return [
            'token' => $token,
            'fileName' => (string) $file->getClientOriginalName(),
            'optionId' => (int) $field->id,
        ];
    }

    /**
     * @return array{path: string, fileName: string}|null
     */
    public function consumeStagedFileToken(string $token, ProductCustomizationField $field): ?array
    {
        $token = trim($token);
        if ($token === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
            return null;
        }
        $payload = Cache::pull($this->stagedCacheKey($token));
        if (! is_array($payload)) {
            return null;
        }
        if ((int) ($payload['optionId'] ?? 0) !== (int) $field->id
            || (int) ($payload['productId'] ?? 0) !== (int) $field->product_id) {
            return null;
        }
        $disk = (string) ($payload['disk'] ?? 'local');
        $staged = (string) ($payload['path'] ?? '');
        if ($staged === '' || ! Storage::disk($disk)->exists($staged)) {
            return null;
        }
        $fileName = (string) ($payload['fileName'] ?? basename($staged));
        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $dest = 'catalog/customizations/'.$field->product_id.'/'.Str::uuid().($ext !== '' ? '.'.$ext : '');
        \App\Domain\Settings\ObjectStorageSettings::disk()->put($dest, Storage::disk($disk)->get($staged));
        Storage::disk($disk)->delete($staged);

        return [
            'path' => $dest,
            'fileName' => $fileName,
        ];
    }

    protected function stagedCacheKey(string $token): string
    {
        return 'wento.customizable_file.'.$token;
    }

    protected function assertText(ProductCustomizationField $field, string $text): void
    {
        $config = $field->config ?: [];
        $min = $config['min_length'] ?? null;
        $max = $config['max_length'] ?? null;
        $len = mb_strlen($text);
        if ($min !== null && $len < (int) $min) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' must be at least '.$min.' characters.',
            ]);
        }
        if ($max !== null && $len > (int) $max) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' must be at most '.$max.' characters.',
            ]);
        }
    }

    /** @var list<string> */
    protected array $blockedUploadExtensions = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'exe', 'sh', 'cgi', 'js', 'html', 'htm', 'svg', 'htaccess', 'shtml', 'asp', 'aspx',
    ];

    protected function assertUpload(ProductCustomizationField $field, UploadedFile $file): void
    {
        $config = $field->config ?: [];
        $maxKb = (int) ($config['max_file_size_kb'] ?? config('wento.upload.max_attachment_kb'));
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' is too large.',
            ]);
        }

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)));
        $name = (string) $file->getClientOriginalName();
        if ($ext === '' || str_contains($name, "\0") || in_array($ext, $this->blockedUploadExtensions, true)) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' file type is not allowed.',
            ]);
        }

        $allowed = array_values(array_diff($this->extensions($field), $this->blockedUploadExtensions));
        if ($field->type === 'image') {
            $allowed = array_values(array_intersect($allowed, ['jpg', 'jpeg', 'png', 'webp', 'gif']));
        }
        if ($allowed === [] || ! in_array($ext, $allowed, true)) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' file type is not allowed.',
            ]);
        }

        $mime = strtolower((string) (AttachmentUploadValidator::detectMime($file) ?: $file->getMimeType() ?: ''));
        $mimeMap = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ];
        $okMimes = $mimeMap[$ext] ?? [];
        if ($okMimes === [] || $mime === '' || $mime === 'application/octet-stream' || ! in_array($mime, $okMimes, true)) {
            throw ValidationException::withMessages([
                'customization.'.$field->id => $field->label.' file type is not allowed.',
            ]);
        }
    }

    protected function extensions(ProductCustomizationField $field): array
    {
        $config = $field->config ?: [];
        $raw = trim((string) ($config['allowed_file_types'] ?? ''));
        if ($raw === '') {
            return $field->type === 'image'
                ? ['jpg', 'jpeg', 'png', 'webp', 'gif']
                : ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'zip'];
        }

        $parsed = array_values(array_filter(array_map(
            fn ($item) => strtolower(ltrim(trim($item), '.')),
            explode(',', $raw)
        )));

        return array_values(array_diff($parsed, $this->blockedUploadExtensions));
    }
}
