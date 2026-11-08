<?php

namespace App\Domain\Ops;

interface Ability
{
    public function code(): string;

    public function label(): string;

    public function permission(): string;

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{entity_type: string, entity_id: int|null, action: string, label: string, before: array, after: array, reversible: bool}>
     */
    public function plan(array $input): array;

    /**
     * Apply one planned item. Return the actual after-state.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $item, array $input): array;

    /**
     * @param  array<string, mixed>  $item
     */
    public function rollback(array $item): void;

    public function eventName(): ?string;
}
