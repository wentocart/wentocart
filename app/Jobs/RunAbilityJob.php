<?php

namespace App\Jobs;

use App\Domain\Ops\Operator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAbilityJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $ability,
        public array $input,
        public array $context = [],
    ) {}

    public function handle(Operator $operator): void
    {
        $operator->execute($this->ability, $this->input, $this->context);
    }
}
