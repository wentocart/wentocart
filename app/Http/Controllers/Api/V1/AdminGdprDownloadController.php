<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminGdprDownload;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminGdprDownloadController extends Controller
{
    public function __invoke(Request $request, int $customerId): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        try {
            $export = GraphqlAdminGdprDownload::export($customerId);
        } catch (UserError $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 403;

            return response()->json(['message' => $message, 'error' => 'gdpr_download_error'], $status);
        }

        return response()->json([
            'customerId' => (int) $export['customerId'],
            'customerEmail' => $export['customerEmail'],
            'generatedAt' => $export['generatedAt'],
            'data' => $export['data'],
            'success' => true,
            'message' => $export['message'],
        ]);
    }
}
