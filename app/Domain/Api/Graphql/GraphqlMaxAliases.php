<?php

namespace App\Domain\Api\Graphql;

use GraphQL\Error\Error;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * Caps field aliases per document — mitigates GraphQL alias/batch DoS
 * (OWASP GraphQL Cheat Sheet).
 */
final class GraphqlMaxAliases extends ValidationRule
{
    public function __construct(private readonly int $maxAliases)
    {
    }

    public function getVisitor(QueryValidationContext $context): array
    {
        $count = 0;
        $reported = false;

        return [
            NodeKind::FIELD => function (FieldNode $node) use ($context, &$count, &$reported): void {
                if ($node->alias === null) {
                    return;
                }

                $count++;
                if ($count <= $this->maxAliases || $reported) {
                    return;
                }

                $reported = true;
                $context->reportError(new Error(
                    "GraphQL query exceeds the maximum of {$this->maxAliases} field aliases."
                ));
            },
        ];
    }
}
