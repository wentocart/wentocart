<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminSchema;
use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Domain\Api\Graphql\GraphqlTiming;
use App\Http\Controllers\Controller;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminGraphqlController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $started = GraphqlTiming::start();

        // Prefer Auth::guard — Request::user('admin') may not see Bearer-bound users.
        // Integration Bearer bind + rate-limit headers run in route middleware.
        if (! Auth::guard('admin')->user()) {
            if ($request->bearerToken()) {
                return GraphqlTiming::withHeaders(response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'unauthenticated',
                ], 401), $started);
            }

            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => 'Unauthenticated.']]], 401), $started);
        }

        if ($batchError = GraphqlSecurityRules::assertNotBatched($request)) {
            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => $batchError]]], 400), $started);
        }

        $query = (string) $request->input('query', '');
        if ($query === '' && $request->isJson()) {
            $query = (string) $request->json('query', '');
        }
        if ($query === '') {
            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => 'GraphQL query is required.']]], 400), $started);
        }
        if ($sizeError = GraphqlSecurityRules::assertQuerySize($query)) {
            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => $sizeError]]], 400), $started);
        }

        $variables = $request->input('variables', $request->json('variables'));
        if (! is_array($variables)) {
            $variables = null;
        }
        $operation = $request->input('operationName', $request->json('operationName'));
        $opName = is_string($operation) ? $operation : null;

        try {
            $result = GraphQL::executeQuery(
                AdminSchema::build(),
                $query,
                null,
                null,
                $variables,
                $opName,
                null,
                GraphqlSecurityRules::rules(),
            );
            $flags = config('app.debug')
                ? (DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE)
                : DebugFlag::NONE;

            return GraphqlTiming::withHeaders(response()->json($result->toArray($flags)), $started, $opName);
        } catch (Throwable $e) {
            return GraphqlTiming::withHeaders(response()->json([
                'errors' => [['message' => config('app.debug') ? $e->getMessage() : 'GraphQL execution failed.']],
            ], 500), $started, $opName);
        }
    }
}
