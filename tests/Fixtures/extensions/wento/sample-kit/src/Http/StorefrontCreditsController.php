<?php

namespace Modules\Wento\SampleKit\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class StorefrontCreditsController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        $q = strtolower(trim($request->string('q')));
        $sort = $request->string('sort', 'id');
        $dir = strtolower($request->string('dir')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['id', 'total', 'reference', 'status', 'due_date', 'penalty'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }
        $perPage = (int) $request->string('per_page', '10');
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }
        $page = max(1, (int) $request->string('page', '1'));

        $rows = $this->demoRows();
        if ($q !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($q) {
                return str_contains(strtolower(implode(' ', $row)), $q);
            }));
        }
        usort($rows, function (array $a, array $b) use ($sort, $dir) {
            $cmp = strnatcasecmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));

            return $dir === 'desc' ? -$cmp : $cmp;
        });

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->page([
            'title' => 'Store credit list',
            'hint' => 'Classic draws this table from a page document. The zip only returns columns, rows, and actions.',
            'crumbs' => [
                ['name' => 'Home', 'href' => '/'],
                ['name' => 'Your account', 'href' => '/account'],
                ['name' => 'Store credit list'],
            ],
            'list' => [
                'search' => true,
                'per_page_options' => [10, 25, 50],
                'per_page' => $perPage,
                'page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'sort' => $sort,
                'dir' => $dir,
                'empty' => 'No store credits yet.',
                'columns' => [
                    ['key' => 'id', 'label' => 'ID', 'sort' => true],
                    ['key' => 'total', 'label' => 'Order total', 'sort' => true],
                    ['key' => 'reference', 'label' => 'Order reference', 'sort' => true],
                    ['key' => 'status', 'label' => 'Order status', 'badge' => true],
                    ['key' => 'due_date', 'label' => 'Due date', 'sort' => true],
                    ['key' => 'penalty', 'label' => 'Penalty'],
                ],
                'rows' => array_map(function (array $row) {
                    return $row + [
                        'status_tone' => 'danger',
                        'actions' => [
                            [
                                'label' => 'View',
                                'href' => '/m/wento/sample-kit/credits?id='.$row['id'],
                                'icon' => 'eye',
                                'variant' => 'ghost',
                            ],
                            [
                                'label' => 'Complete payment',
                                'href' => '/m/wento/sample-kit/credits?id='.$row['id'].'&pay=1',
                                'variant' => 'secondary',
                            ],
                        ],
                    ];
                }, $slice),
            ],
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    protected function demoRows(): array
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = [
                'id' => (string) $i,
                'total' => '€'.number_format(20 + ($i * 1.21), 2, '.', ''),
                'reference' => 'NJWHOXMDS'.$i,
                'status' => 'Store Credit',
                'due_date' => sprintf('2026-10-%02d', min(28, $i)),
                'penalty' => '€'.number_format($i * 0.9, 2, '.', ''),
            ];
        }

        return $rows;
    }
}
