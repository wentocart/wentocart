<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlShopAttributes;
use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Shop REST GET /api/v1/store/attributes[+ /{id}][+ /options].
 */
class ShopAttributesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();

        $q = Feature::query()
            ->where('is_enabled', true)
            ->with(['translations', 'values' => fn ($v) => $v->where('is_enabled', true)->with('translations')->orderBy('position')])
            ->orderBy('position')
            ->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Feature $f) => $this->restAttribute($f, $locale))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show(int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $feature = Feature::query()
            ->where('is_enabled', true)
            ->with(['translations', 'values' => fn ($v) => $v->where('is_enabled', true)->with('translations')->orderBy('position')])
            ->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'success' => false], 404);
        }

        return response()->json($this->restAttribute($feature, $locale));
    }

    /**
     */
    public function families(Request $request): JsonResponse
    {
        $rows = $this->familyRows();
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->paginated($slice, $total, $page, $perPage);
    }

    public function showFamily(int $id): JsonResponse
    {
        $row = collect($this->familyRows())->first(fn (array $r) => (int) ($r['id'] ?? 0) === $id);
        if (! $row) {
            return response()->json(['message' => 'Product template not found.', 'success' => false], 404);
        }

        return response()->json($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function familyRows(): array
    {
        if (Schema::hasTable('product_templates')) {
            $fromDb = ProductTemplate::query()->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn (ProductTemplate $f) => [
                    'id' => (int) $f->id,
                    'code' => (string) $f->code,
                    'name' => (string) $f->name,
                    'adminName' => (string) $f->name,
                    'status' => 1,
                ])->values()->all();
            if ($fromDb !== []) {
                return $fromDb;
            }
        }

        return [[
            'id' => 0,
            'code' => 'default',
            'name' => 'Default',
            'adminName' => 'Default',
            'status' => 1,
        ]];
    }

    public function options(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();
        $attributeId = (int) $request->query('attribute_id', $request->query('attributeId', 0));

        $q = FeatureValue::query()
            ->where('is_enabled', true)
            ->with(['translations', 'feature.translations'])
            ->orderBy('position')
            ->orderBy('id');
        if ($attributeId > 0) {
            $q->where('feature_id', $attributeId);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (FeatureValue $v) => $this->restOption($v, $locale))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     */
    public function showOption(int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $value = FeatureValue::query()
            ->where('is_enabled', true)
            ->with(['translations', 'feature.translations'])
            ->find($id);
        if (! $value) {
            return response()->json(['message' => 'Attribute option not found.', 'success' => false], 404);
        }

        return response()->json($this->restOption($value, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function restAttribute(Feature $f, string $locale): array
    {
        $mapped = GraphqlShopAttributes::mapFeature($f, $locale);
        $options = $f->values->map(fn (FeatureValue $v) => $this->restOption($v, $locale))->values()->all();

        return [
            'id' => (int) $f->id,
            'code' => (string) $f->code,
            'adminName' => (string) ($mapped['adminName'] ?? $f->code),
            'type' => (string) ($mapped['type'] ?? 'select'),
            'swatchType' => $mapped['swatchType'] ?? null,
            'position' => (int) ($f->position ?? 0),
            'isRequired' => 0,
            'isUnique' => 0,
            'isFilterable' => $f->is_filterable ? 1 : 0,
            'isComparable' => 1,
            'isConfigurable' => $f->used_for_combinations ? 1 : 0,
            'isUserDefined' => 1,
            'isVisibleOnFront' => 1,
            'valuePerLocale' => 1,
            'valuePerStore' => 0,
            'enableWysiwyg' => 0,
            'validations' => null,
            'columnName' => null,
            'createdAt' => $f->created_at?->toIso8601String(),
            'updatedAt' => $f->updated_at?->toIso8601String(),
            'options' => $options,
            'translation' => [
                'id' => (int) $f->id,
                'attributeId' => (int) $f->id,
                'locale' => $locale,
                'name' => (string) ($mapped['adminName'] ?? $f->code),
            ],
            'translations' => [
                '/api/v1/store/attribute_translations/'.$f->id,
            ],
        ];
    }

    /**
     * Wento also accepts hyphen alias. {id} is the attribute (feature) id referenced from attributes.translations[].
     */
    public function translations(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();

        $q = Feature::query()->where('is_enabled', true)->with('translations')->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Feature $f) => $this->translationRow($f, $locale))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showTranslation(int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $feature = Feature::query()->where('is_enabled', true)->with('translations')->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute translation not found.', 'success' => false], 404);
        }

        return response()->json($this->translationRow($feature, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function translationRow(Feature $f, string $locale): array
    {
        $mapped = GraphqlShopAttributes::mapFeature($f, $locale);

        return [
            'id' => (int) $f->id,
            'attributeId' => (int) $f->id,
            'locale' => $locale,
            'name' => (string) ($mapped['adminName'] ?? $f->code),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restOption(FeatureValue $v, string $locale): array
    {
        $label = $v->translation($locale)?->value ?? $v->code;

        return [
            'id' => (int) $v->id,
            'adminName' => (string) $label,
            'sortOrder' => (int) ($v->position ?? 0),
            'attributeId' => (int) $v->feature_id,
            'translation' => [
                'id' => (int) $v->id,
                'attributeOptionId' => (int) $v->id,
                'locale' => $locale,
                'label' => (string) $label,
            ],
            'translations' => [[
                'id' => (int) $v->id,
                'attributeOptionId' => (int) $v->id,
                'locale' => $locale,
                'label' => (string) $label,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function paginated(array $data, int $total, int $page, int $perPage): JsonResponse
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json($data)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }
}
