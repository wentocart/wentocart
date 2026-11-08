<?php

namespace App\Domain\Plugin\Support;

class ModuleRequest
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $method,
        protected array $input,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function capture(string $method, array $input): self
    {
        return new self(strtoupper($method), self::scalarTree($input, 0));
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->input;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST' || $this->method === 'PUT' || $this->method === 'PATCH';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function scalarTree(array $input, int $depth): array
    {
        if ($depth > 3) {
            return [];
        }
        $out = [];
        foreach ($input as $key => $value) {
            if (is_int($key)) {
                $name = (string) $key;
            } elseif (! is_string($key) || $key === '' || $key[0] === '_' || strlen($key) > 80) {
                continue;
            } else {
                $name = $key;
            }
            if (is_scalar($value) || $value === null) {
                $out[$name] = $value;
            } elseif (is_array($value)) {
                $out[$name] = self::scalarTree($value, $depth + 1);
            }
        }

        return $out;
    }
}
