<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceLandingSettings;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMarketplaceLandingRestController extends Controller
{
    public function show(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->present(MarketplaceLandingSettings::all())]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $payload = $request->validate([
            'banner_title' => ['nullable', 'string', 'max:190'],
            'bannerTitle' => ['nullable', 'string', 'max:190'],
            'banner_description' => ['nullable', 'string', 'max:2000'],
            'bannerDescription' => ['nullable', 'string', 'max:2000'],
            'banner_button_title' => ['nullable', 'string', 'max:120'],
            'bannerButtonTitle' => ['nullable', 'string', 'max:120'],
            'banner_image' => ['nullable', 'string', 'max:500'],
            'bannerImage' => ['nullable', 'string', 'max:500'],
            'community_count' => ['nullable', 'string', 'max:40'],
            'communityCount' => ['nullable', 'string', 'max:40'],
            'business_hour' => ['nullable', 'string', 'max:40'],
            'businessHour' => ['nullable', 'string', 'max:40'],
            'payment_duration' => ['nullable', 'string', 'max:40'],
            'paymentDuration' => ['nullable', 'string', 'max:40'],
            'serviceable_pincode' => ['nullable', 'string', 'max:40'],
            'serviceablePincode' => ['nullable', 'string', 'max:40'],
            'features_heading' => ['nullable', 'string', 'max:190'],
            'featuresHeading' => ['nullable', 'string', 'max:190'],
            'features_description' => ['nullable', 'string', 'max:2000'],
            'featuresDescription' => ['nullable', 'string', 'max:2000'],
            'features_image' => ['nullable', 'string', 'max:500'],
            'featuresImage' => ['nullable', 'string', 'max:500'],
            'feature_boxes' => ['nullable', 'array', 'max:4'],
            'featureBoxes' => ['nullable', 'array', 'max:4'],
            'journey_heading' => ['nullable', 'string', 'max:190'],
            'journeyHeading' => ['nullable', 'string', 'max:190'],
            'journey_description' => ['nullable', 'string', 'max:2000'],
            'journeyDescription' => ['nullable', 'string', 'max:2000'],
            'journey_steps' => ['nullable', 'array', 'max:5'],
            'journeySteps' => ['nullable', 'array', 'max:5'],
        ]);
        $map = [
            'bannerTitle' => 'banner_title',
            'bannerDescription' => 'banner_description',
            'bannerButtonTitle' => 'banner_button_title',
            'bannerImage' => 'banner_image',
            'communityCount' => 'community_count',
            'businessHour' => 'business_hour',
            'paymentDuration' => 'payment_duration',
            'serviceablePincode' => 'serviceable_pincode',
            'featuresHeading' => 'features_heading',
            'featuresDescription' => 'features_description',
            'featuresImage' => 'features_image',
            'featureBoxes' => 'feature_boxes',
            'journeyHeading' => 'journey_heading',
            'journeyDescription' => 'journey_description',
            'journeySteps' => 'journey_steps',
        ];
        $data = [];
        foreach ($payload as $key => $value) {
            $data[$map[$key] ?? $key] = $value;
        }
        $saved = MarketplaceLandingSettings::save($data);

        return response()->json([
            'message' => 'Landing page saved.',
            'data' => $this->present($saved),
        ]);
    }

    /**
     * @param  array<string, mixed>  $all
     * @return array<string, mixed>
     */
    private function present(array $all): array
    {
        return [
            'bannerTitle' => (string) ($all['banner_title'] ?? ''),
            'bannerDescription' => (string) ($all['banner_description'] ?? ''),
            'bannerButtonTitle' => (string) ($all['banner_button_title'] ?? ''),
            'bannerImage' => (string) ($all['banner_image'] ?? ''),
            'communityCount' => (string) ($all['community_count'] ?? ''),
            'businessHour' => (string) ($all['business_hour'] ?? ''),
            'paymentDuration' => (string) ($all['payment_duration'] ?? ''),
            'serviceablePincode' => (string) ($all['serviceable_pincode'] ?? ''),
            'featuresHeading' => (string) ($all['features_heading'] ?? ''),
            'featuresDescription' => (string) ($all['features_description'] ?? ''),
            'featuresImage' => (string) ($all['features_image'] ?? ''),
            'featureBoxes' => array_values($all['feature_boxes'] ?? []),
            'journeyHeading' => (string) ($all['journey_heading'] ?? ''),
            'journeyDescription' => (string) ($all['journey_description'] ?? ''),
            'journeySteps' => array_values($all['journey_steps'] ?? []),
        ];
    }

    private function guard(): ?JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
