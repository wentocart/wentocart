<?php

namespace App\Domain\Api\Graphql;

use GraphQL\Error\Error;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * Caps top-level fields per operation — blocks breadth/batch DoS via many root selections.
 */
final class GraphqlMaxRootFields extends ValidationRule
{
    public function __construct(private readonly int $maxRootFields)
    {
    }

    public function getVisitor(QueryValidationContext $context): array
    {
        return [
            NodeKind::OPERATION_DEFINITION => function (OperationDefinitionNode $op) use ($context): void {
                $selectionSet = $op->selectionSet;
                if ($selectionSet === null) {
                    return;
                }

                $roots = 0;
                foreach ($selectionSet->selections as $selection) {
                    if ($selection instanceof FieldNode) {
                        $roots++;
                    }
                }

                if ($roots > $this->maxRootFields) {
                    $context->reportError(new Error(
                        "GraphQL operation exceeds the maximum of {$this->maxRootFields} root fields."
                    ));
                }
            },
        ];
    }
}
