<?php

namespace App\Domain\Installer;

/**
 * Demo progress keeps each kind of sample data on its own counter.
 * Categories, brands, and products are not added into one total.
 */
final class DemoInstallMeter
{
    /**
     * @param  array<string, array{done: int, total: int}>  $sections
     */
    public function __construct(public array $sections) {}

    /**
     * @param  array<string, int>  $sections
     */
    public static function begin(array $sections): self
    {
        $normalized = [];
        foreach ($sections as $name => $total) {
            $normalized[(string) $name] = ['done' => 0, 'total' => max(1, (int) $total)];
        }
        $meter = new self($normalized);
        config(['wento.demo_meter' => $meter]);

        return $meter;
    }

    public static function current(): ?self
    {
        $meter = config('wento.demo_meter');

        return $meter instanceof self ? $meter : null;
    }

    public function show(string $name): void
    {
        $section = $this->sections[$name] ?? null;
        if ($section === null) {
            return;
        }

        $this->emit($section['done'], $section['total'], $name.' '.$section['done'].' / '.$section['total']);
    }

    public function advance(string $name): void
    {
        if (! isset($this->sections[$name])) {
            return;
        }

        $this->sections[$name]['done'] = min(
            $this->sections[$name]['total'],
            $this->sections[$name]['done'] + 1,
        );
        $this->show($name);
    }

    public function expect(string $name, int $total): void
    {
        $this->sections[$name] = ['done' => 0, 'total' => max(1, $total)];
        $this->show($name);
    }

    public function complete(string $message): void
    {
        $name = 'Products';
        foreach ($this->sections as $sectionName => $section) {
            if ($section['done'] > 0) {
                $name = $sectionName;
            }
        }

        $section = $this->sections[$name] ?? ['done' => 1, 'total' => 1];
        $this->emit($section['done'], $section['total'], $message, 100);
    }

    protected function emit(int $done, int $total, string $message, ?int $phasePct = null): void
    {
        $progress = config('wento.install_progress');
        if (! is_callable($progress)) {
            return;
        }

        InstallProgress::tick($progress, 'demo', $done, $total, $message, $phasePct ?? $this->phasePercent());
    }

    protected function phasePercent(): int
    {
        $weight = 0;
        $doneWeight = 0;
        foreach ($this->sections as $section) {
            $weight += $section['total'];
            $doneWeight += $section['done'];
        }

        if ($weight < 1) {
            return 0;
        }

        return (int) min(100, floor(100 * $doneWeight / $weight));
    }
}
