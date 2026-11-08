<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\WebservicePayload;
use App\Domain\Ops\WebserviceQuery;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebserviceResourcesController extends Controller
{
    public function languages(): JsonResponse
    {
        $rows = Language::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (Language $row) => WebservicePayload::language($row));

        return response()->json(['data' => $rows]);
    }

    public function currencies(): JsonResponse
    {
        $rows = Currency::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (Currency $row) => WebservicePayload::currency($row));

        return response()->json(['data' => $rows]);
    }

    public function countries(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['regions']);
        $q = Country::query()->orderBy('position')->orderBy('id');
        if ($code = strtoupper(trim((string) $request->query('code', '')))) {
            $q->where('code', $code);
        }
        if (in_array('regions', $includes, true)) {
            $q->with('regions');
        }

        return WebserviceQuery::paginate($request, $q, fn (Country $row) => WebservicePayload::country($row, $includes));
    }

    public function showCountry(Request $request, Country $country): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['regions']);

        return response()->json(['data' => WebservicePayload::country($country, $includes)]);
    }

    public function shippingMethods(): JsonResponse
    {
        $rows = ShippingMethod::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (ShippingMethod $row) => WebservicePayload::shippingMethod($row));

        return response()->json(['data' => $rows]);
    }

    public function cmsPages(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['body']);
        $q = CmsPage::query()->public()->with('translations')->orderBy('id');

        return WebserviceQuery::paginate($request, $q, fn (CmsPage $row) => WebservicePayload::cmsPage($row, $includes));
    }

    public function showCmsPage(Request $request, CmsPage $page): JsonResponse
    {
        if (! $page->is_enabled || ! $page->is_visible || $page->status !== CmsPage::STATUS_PUBLISHED) {
            abort(404);
        }
        $includes = WebserviceQuery::includes($request, ['body']);

        return response()->json(['data' => WebservicePayload::cmsPage($page, $includes)]);
    }

    public function stock(Request $request): JsonResponse
    {
        $q = Product::query()->orderBy('id');
        if ($sku = trim((string) $request->query('sku', ''))) {
            $q->where('sku', $sku);
        }

        return WebserviceQuery::paginate($request, $q, fn (Product $row) => WebservicePayload::stock($row));
    }

    public function categories(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['children']);
        $with = ['translations'];
        if (in_array('children', $includes, true)) {
            $with[] = 'children.translations';
        }
        $q = Category::query()->with($with)->orderBy('position')->orderBy('id');

        return WebserviceQuery::paginate($request, $q, fn (Category $row) => WebservicePayload::category($row, $includes));
    }

    public function showCategory(Request $request, Category $category): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['children']);

        return response()->json(['data' => WebservicePayload::category($category, $includes)]);
    }

    public function brands(Request $request): JsonResponse
    {
        $q = Brand::query()->with('translations')->orderBy('position')->orderBy('id');

        return WebserviceQuery::paginate($request, $q, fn (Brand $row) => WebservicePayload::brand($row));
    }

    public function showBrand(Brand $brand): JsonResponse
    {
        return response()->json(['data' => WebservicePayload::brand($brand)]);
    }

    public function tags(): JsonResponse
    {
        $rows = Tag::query()->with('translations')->orderBy('id')->get()
            ->map(fn (Tag $row) => WebservicePayload::tag($row));

        return response()->json(['data' => $rows]);
    }

    public function showTag(Tag $tag): JsonResponse
    {
        return response()->json(['data' => WebservicePayload::tag($tag)]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $q = Supplier::query()->with('translations')->orderBy('position')->orderBy('id');

        return WebserviceQuery::paginate($request, $q, fn (Supplier $row) => WebservicePayload::supplier($row));
    }

    public function showSupplier(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => WebservicePayload::supplier($supplier)]);
    }

    public function features(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['values']);
        $with = ['translations'];
        if (in_array('values', $includes, true)) {
            $with[] = 'values.translations';
        }
        $rows = Feature::query()->with($with)->orderBy('position')->orderBy('id')->get()
            ->map(fn (Feature $row) => WebservicePayload::feature($row, $includes));

        return response()->json(['data' => $rows]);
    }

    public function showFeature(Request $request, Feature $feature): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['values']);

        return response()->json(['data' => WebservicePayload::feature($feature, $includes)]);
    }

    public function customerGroups(): JsonResponse
    {
        $rows = CustomerGroup::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (CustomerGroup $row) => WebservicePayload::customerGroup($row));

        return response()->json(['data' => $rows]);
    }

    public function addresses(Request $request): JsonResponse
    {
        $q = CustomerAddress::query()->orderBy('id');
        if ($customerId = (int) $request->query('customer_id', 0)) {
            $q->where('customer_id', $customerId);
        }

        return WebserviceQuery::paginate($request, $q, fn (CustomerAddress $row) => WebservicePayload::address($row));
    }

    public function showAddress(CustomerAddress $address): JsonResponse
    {
        return response()->json(['data' => WebservicePayload::address($address)]);
    }

    public function customerAddresses(Customer $customer): JsonResponse
    {
        $rows = $customer->addresses()->orderBy('id')->get()
            ->map(fn (CustomerAddress $row) => WebservicePayload::address($row));

        return response()->json(['data' => $rows]);
    }
}
