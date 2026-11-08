<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminSettingsDataTransferImports;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin REST Settings → Data Transfer Imports REST.
 */
class AdminSettingsDataTransferImportsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::paginate($request->all()));
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminSettingsDataTransferImports::find($id, true);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Import not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $payload = $request->all();
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $payload['csvContent'] = (string) file_get_contents($file->getRealPath());
            $payload['fileName'] = $payload['fileName'] ?? $file->getClientOriginalName();
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::create($payload), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function validateImport(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::validate(array_merge(
                $request->all(),
                ['id' => $id, 'importId' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function start(int $id): JsonResponse
    {
        return $this->advance($id, 'processing');
    }

    public function link(int $id): JsonResponse
    {
        return $this->advance($id, 'linked');
    }

    public function indexImport(int $id): JsonResponse
    {
        return $this->advance($id, 'indexed');
    }

    public function cancel(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::cancel(['id' => $id]));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::delete($id));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::bulkDelete($ids));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    /**
     */
    public function downloadSample(string $type, string $format): StreamedResponse|Response|JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $sample = GraphqlAdminSettingsDataTransferImports::sampleDownload($type, $format);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }

        return response()->streamDownload(function () use ($sample) {
            echo $sample['content'];
        }, $sample['filename'], [
            'Content-Type' => $sample['mime'],
        ]);
    }

    protected function advance(int $id, string $nextState): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminSettingsDataTransferImports::advance(['id' => $id], $nextState));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    protected function guard(): ?JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }

    protected function userError(UserError $e, int $default = 403): JsonResponse
    {
        $msg = $e->getMessage();
        $status = match (true) {
            str_contains(strtolower($msg), 'unauthenticated') => 401,
            str_contains(strtolower($msg), 'not found') => 404,
            str_contains(strtolower($msg), 'unknown') => 404,
            str_contains(strtolower($msg), 'forbidden') => 403,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
