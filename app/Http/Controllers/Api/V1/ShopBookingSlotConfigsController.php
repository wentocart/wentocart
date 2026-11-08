<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 */
class ShopBookingSlotConfigsController extends Controller
{
    public function defaultSlots(Request $request): JsonResponse
    {
        return $this->collection($request, 'default');
    }

    public function showDefaultSlot(int $id): JsonResponse
    {
        return $this->showOne($id, 'default');
    }

    public function appointmentSlots(Request $request): JsonResponse
    {
        return $this->collection($request, 'appointment');
    }

    public function showAppointmentSlot(int $id): JsonResponse
    {
        return $this->showOne($id, 'appointment');
    }

    public function rentalSlots(Request $request): JsonResponse
    {
        return $this->collection($request, 'rental');
    }

    public function showRentalSlot(int $id): JsonResponse
    {
        return $this->showOne($id, 'rental');
    }

    public function tableSlots(Request $request): JsonResponse
    {
        return $this->collection($request, 'table');
    }

    public function showTableSlot(int $id): JsonResponse
    {
        return $this->showOne($id, 'table');
    }

    public function eventTickets(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        // Event tickets not modeled yet — empty REST-shaped collection (no 404).
        return $this->paginated([], 0, $page, $perPage);
    }

    public function showEventTicket(int $id): JsonResponse
    {
        return response()->json(['message' => 'Event ticket not found.', 'success' => false], 404);
    }

    private function collection(Request $request, string $type): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $all = $this->matchingProducts($type)
            ->map(fn (Product $p) => $this->slotRow($p, $type))
            ->values()
            ->all();
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return $this->paginated($slice, $total, $page, $perPage);
    }

    private function showOne(int $id, string $type): JsonResponse
    {
        $product = Product::query()->enabled()->with(['serviceSetting', 'rentalSetting'])->find($id);
        if (! $product || ! $this->matchesType($product, $type)) {
            return response()->json(['message' => 'Booking slot config not found.', 'success' => false], 404);
        }

        return response()->json($this->slotRow($product, $type));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function matchingProducts(string $type)
    {
        $q = Product::query()->enabled()->with(['serviceSetting', 'rentalSetting'])->orderBy('id');
        if ($type === 'rental') {
            return $q->get()->filter(fn (Product $p) => $p->isRental())->values();
        }
        if ($type === 'table') {
            return collect(); // not modeled
        }

        return $q->get()->filter(fn (Product $p) => $this->matchesType($p, $type))->values();
    }

    private function matchesType(Product $product, string $type): bool
    {
        if ($type === 'rental') {
            return $product->isRental();
        }
        if ($type === 'table') {
            return false;
        }
        if (! $product->isService()) {
            return false;
        }
        $stype = (string) ($product->serviceSetting?->service_type ?? 'appointment');
        $mapped = $stype === 'appointment' ? 'appointment' : 'default';

        return $mapped === $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRow(Product $product, string $type): array
    {
        $duration = null;
        $breakTime = null;
        $slots = null;
        if ($product->isService()) {
            $duration = $product->serviceSetting?->duration !== null
                ? (int) $product->serviceSetting->duration
                : null;
        }
        if ($product->isRental()) {
            $duration = $product->rentalSetting?->default_duration !== null
                ? (int) $product->rentalSetting->default_duration
                : null;
        }

        return [
            'id' => (int) $product->id,
            'bookingType' => $type === 'rental' ? 'many' : 'one',
            'duration' => $duration,
            'breakTime' => $breakTime,
            'slots' => $slots !== null ? json_encode($slots) : json_encode([]),
            'bookingProductId' => (int) $product->id,
            'type' => $type,
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
