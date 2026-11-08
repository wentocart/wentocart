<?php

namespace App\Domain\Api\Graphql;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * Caps operation definitions per document — blocks multi-operation batch payloads.
 */
final class GraphqlMaxOperations extends ValidationRule
{
    public function __construct(private readonly int $maxOperations)
    {
    }

    public function getVisitor(QueryValidationContext $context): array
    {
        return [
            NodeKind::DOCUMENT => function (DocumentNode $document) use ($context): void {
                $count = 0;
                foreach ($document->definitions as $definition) {
                    if ($definition instanceof OperationDefinitionNode) {
                        $count++;
                    }
                }

                if ($count > $this->maxOperations) {
                    $context->reportError(new Error(
                        "GraphQL document exceeds the maximum of {$this->maxOperations} operations."
                    ));
                }
            },
        ];
    }
}
