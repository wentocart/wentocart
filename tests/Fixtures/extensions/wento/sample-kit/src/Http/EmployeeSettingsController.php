<?php

namespace Modules\Wento\SampleKit\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class EmployeeSettingsController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        return $this->page([
            'title' => 'Employee Settings',
            'hint' => 'Example nested page in the Account Manager group.',
            'fields' => [
                [
                    'name' => 'desk_label',
                    'type' => 'text',
                    'label' => 'Front desk label',
                ],
            ],
        ]);
    }

    public function store(ModuleRequest $request): array
    {
        $values = $request->all()['values'] ?? [];
        if (is_array($values)) {
            $this->settings()->fill($values);
        }

        return $this->index($request);
    }
}
