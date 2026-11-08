<?php

namespace App\Domain\Api\Graphql;

use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Http\Request;

/**
 * GraphQL abuse guards.
 * (complexity 400 / depth 20 in config/api-platform.php).
 */
final class GraphqlSecurityRules
{
    public const MAX_COMPLEXITY = 300;

    public const MAX_DEPTH = 15;

    /** Reject oversized documents before parse (has no documented byte cap). */
    public const MAX_QUERY_BYTES = 32768;

    /** Alias DoS cap — has no documented alias limit. */
    public const MAX_ALIASES = 25;

    /** Breadth DoS cap on root selections per operation. */
    public const MAX_ROOT_FIELDS = 20;

    /** Cap operation definitions per document (multi-op batch defense). */
    public const MAX_OPERATIONS = 1;

    /**
     * @return list<\GraphQL\Validator\Rules\ValidationRule>
     */
    public static function rules(): array
    {
        $rules = array_values(DocumentValidator::defaultRules());
        $rules[] = new QueryComplexity(self::MAX_COMPLEXITY);
        $rules[] = new QueryDepth(self::MAX_DEPTH);
        $rules[] = new GraphqlMaxAliases(self::MAX_ALIASES);
        $rules[] = new GraphqlMaxRootFields(self::MAX_ROOT_FIELDS);
        $rules[] = new GraphqlMaxOperations(self::MAX_OPERATIONS);

        if (! (bool) config('app.debug')) {
            $rules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        return $rules;
    }

    public static function assertQuerySize(string $query): ?string
    {
        if (strlen($query) > self::MAX_QUERY_BYTES) {
            return 'GraphQL query exceeds the maximum size of '.self::MAX_QUERY_BYTES.' bytes.';
        }

        return null;
    }

    /**
     * Reject Apollo-style JSON array batching (OWASP batching attacks).
     * Wento fails closed with an explicit error.
     */
    public static function assertNotBatched(Request $request): ?string
    {
        if (! $request->isJson()) {
            return null;
        }

        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded) && $decoded !== [] && array_is_list($decoded)) {
            return 'GraphQL request batching is not supported. Send a single operation object.';
        }

        return null;
    }
}
