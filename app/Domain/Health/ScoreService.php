<?php

namespace App\Domain\Health;

final class ScoreService
{
    /**
     * @param  list<array<string, mixed>>  $hits
     * @return array{overall: int, categories: array<string, array{score: int, open: int, passed: int, checks: int}>, critical: int, warning: int, opportunity: int, passed: int}
     */
    public function fromHits(array $hits): array
    {
        $byCat = [];
        foreach (RuleCatalog::CATEGORIES as $cat) {
            $byCat[$cat] = ['points' => 0, 'max' => 0, 'open' => 0, 'passed' => 0, 'checks' => 0];
        }
        $critical = $warning = $opportunity = $passed = 0;

        foreach ($hits as $hit) {
            $code = (string) ($hit['code'] ?? '');
            $rule = RuleCatalog::get($code);
            $cat = $rule['category'] ?? 'technical';
            if (! isset($byCat[$cat])) {
                $cat = 'technical';
            }
            $weight = (int) ($rule['weight'] ?? 3);
            $byCat[$cat]['max'] += $weight;
            $byCat[$cat]['checks']++;
            if (! empty($hit['passed'])) {
                $byCat[$cat]['points'] += $weight;
                $byCat[$cat]['passed']++;
                $passed++;
            } else {
                $byCat[$cat]['open']++;
                $sev = (string) ($hit['severity'] ?? 'warning');
                match ($sev) {
                    'critical' => $critical++,
                    'opportunity' => $opportunity++,
                    default => $warning++,
                };
                $penalty = match ($sev) {
                    'critical' => 0,
                    'opportunity' => (int) round($weight * 0.55),
                    default => (int) round($weight * 0.25),
                };
                $byCat[$cat]['points'] += $penalty;
            }
        }

        $categories = [];
        $overallNum = 0;
        $overallDen = 0;
        foreach (RuleCatalog::CATEGORIES as $cat) {
            $row = $byCat[$cat];
            $score = $row['max'] > 0 ? (int) round(100 * $row['points'] / $row['max']) : 100;
            $categories[$cat] = [
                'score' => $score,
                'open' => $row['open'],
                'passed' => $row['passed'],
                'checks' => $row['checks'],
                'label' => RuleCatalog::scoreLabel($score),
            ];
            $w = RuleCatalog::WEIGHTS[$cat] ?? 10;
            $overallNum += $score * $w;
            $overallDen += $w;
        }

        return [
            'overall' => $overallDen > 0 ? (int) round($overallNum / $overallDen) : 100,
            'categories' => $categories,
            'critical' => $critical,
            'warning' => $warning,
            'opportunity' => $opportunity,
            'passed' => $passed,
        ];
    }
}
