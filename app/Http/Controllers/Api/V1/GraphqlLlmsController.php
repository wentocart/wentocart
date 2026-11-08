<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminSchema;
use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Domain\Api\Graphql\ShopSchema;
use App\Http\Controllers\Controller;
use GraphQL\Type\Definition\ObjectType;
use Illuminate\Http\Response;

/**
 * Machine-readable GraphQL surface index for AI agents —
 * generated from live schemas.
 */
class GraphqlLlmsController extends Controller
{
    public function __invoke(): Response
    {
        $shop = ShopSchema::build();
        $admin = AdminSchema::build();

        $lines = [
            '# Wentocart API',
            '',
            '> Live GraphQL operation index for WentoCart (Shop + Admin). Generated from the running schemas.',
            '',
            '- Shop GraphQL: `POST /api/v1/graphql` (optional `X-STOREFRONT-KEY`; Bearer for customer/cart)',
            '- Admin GraphQL: `POST /api/v1/console/graphql` (admin session or Integration Bearer)',
            '- GraphiQL Shop: `GET /api/v1/graphiql` · Admin: `GET /api/v1/console/graphiql` · Sandbox: `GET /api/v1/sandbox`',
            '- OpenAPI: `GET /api/v1/store/docs` · `GET /api/v1/console/docs` · export: `php artisan wento:export-api-schema`',
            '- Security: depth '.GraphqlSecurityRules::MAX_DEPTH
                .', complexity '.GraphqlSecurityRules::MAX_COMPLEXITY
                .', max query bytes '.GraphqlSecurityRules::MAX_QUERY_BYTES
                .', max aliases '.GraphqlSecurityRules::MAX_ALIASES
                .', max root fields '.GraphqlSecurityRules::MAX_ROOT_FIELDS
                .', max operations '.GraphqlSecurityRules::MAX_OPERATIONS
                .' (JSON array batching rejected)',
            '',
            '## GraphQL API › Shop › Query',
            '',
        ];

        foreach ($this->fieldNames($shop->getQueryType()) as $name) {
            $lines[] = '- `'.$name.'`';
        }

        $lines[] = '';
        $lines[] = '## GraphQL API › Shop › Mutation';
        $lines[] = '';
        foreach ($this->fieldNames($shop->getMutationType()) as $name) {
            $lines[] = '- `'.$name.'`';
        }

        $lines[] = '';
        $lines[] = '## GraphQL API › Admin › Query';
        $lines[] = '';
        foreach ($this->fieldNames($admin->getQueryType()) as $name) {
            $lines[] = '- `'.$name.'`';
        }

        $lines[] = '';
        $lines[] = '## GraphQL API › Admin › Mutation';
        $lines[] = '';
        foreach ($this->fieldNames($admin->getMutationType()) as $name) {
            $lines[] = '- `'.$name.'`';
        }

        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        $lines[] = '- Anonymous Shop queries may return `X-Wento-GQL-Cache`, `ETag`, `Cache-Control`, and `Server-Timing`.';
        $lines[] = '- Integration tokens support per-minute + per-day buckets with `Retry-After` / `X-RateLimit-*` shared across Admin GraphQL and REST CSV exports.';
        $lines[] = '- Storefront keys support hourly quotas shared across Shop GraphQL and REST catalog routes.';
        $lines[] = '';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * @return list<string>
     */
    protected function fieldNames(?ObjectType $type): array
    {
        if (! $type) {
            return [];
        }

        $names = array_keys($type->getFields());
        sort($names);

        return array_values($names);
    }
}
