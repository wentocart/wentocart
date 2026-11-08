<?php

namespace Modules\Wento\SampleKit\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class ConfigurationController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        return $this->page([
            'title' => 'Configuration',
            'hint' => 'Hosted by core from this module controller.',
            'assets' => ['css' => ['assets/admin.css']],
            'fields' => [
                [
                    'name' => 'office_name',
                    'type' => 'text',
                    'label' => 'Office name',
                ],
            ],
            'blocks' => [
                [
                    'type' => 'card',
                    'title' => 'How menus work',
                    'text' => 'This page was registered with pages()->admin() and menu()->group(). Core rendered the document.',
                ],
            ],
        ]);
    }

    public function store(ModuleRequest $request): array
    {
        $values = $request->all()['values'] ?? [];
        if (! is_array($values)) {
            $values = [];
        }
        $name = trim((string) ($values['office_name'] ?? ''));
        if ($name === '') {
            return $this->invalid($this->index($request), [
                'office_name' => 'Office name is required.',
            ], $values);
        }
        $this->settings()->fill($values);

        return $this->index($request);
    }
}
