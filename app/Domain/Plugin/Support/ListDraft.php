<?php

namespace App\Domain\Plugin\Support;

class ListDraft
{
    /**
     * @param  array{table: string, joins: array<string, array<string, string>>, selects: list<string>}  $definition
     */
    public function __construct(protected array $definition)
    {
    }

    /** @var list<string> */
    protected array $joins = [];

    /** @var list<array{expr: string, alias: string}> */
    protected array $selects = [];

    /** @var list<array<string, mixed>> */
    protected array $columns = [];

    public function join(string $key): self
    {
        if (isset($this->definition['joins'][$key])) {
            $this->joins[] = $key;
        }

        return $this;
    }

    public function select(string $expr, string $alias): self
    {
        $alias = preg_replace('/[^a-z0-9_]/', '', strtolower($alias)) ?? '';
        if ($alias === '' || ! in_array($expr, $this->definition['selects'], true)) {
            return $this;
        }
        $this->selects[] = ['expr' => $expr, 'alias' => $alias];

        return $this;
    }

    /**
     * @param  array{label?: string, after?: string}  $meta
     */
    public function column(string $key, array $meta = []): self
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?? '';
        if ($key === '') {
            return $this;
        }
        $this->columns[] = [
            'key' => $key,
            'alias' => $key,
            'label' => trim((string) ($meta['label'] ?? $key)),
            'after' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($meta['after'] ?? ''))) ?: null,
        ];

        return $this;
    }

    /** @return list<string> */
    public function joins(): array
    {
        return $this->joins;
    }

    /** @return list<array{expr: string, alias: string}> */
    public function selects(): array
    {
        return $this->selects;
    }

    /** @return list<array<string, mixed>> */
    public function columns(): array
    {
        return $this->columns;
    }
}
