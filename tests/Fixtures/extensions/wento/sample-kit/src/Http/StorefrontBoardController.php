<?php

namespace Modules\Wento\SampleKit\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class StorefrontBoardController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        return $this->page([
            'title' => 'Account board',
            'hint' => 'A storefront controller under /m/wento/sample-kit/board. Customers must be signed in.',
            'blocks' => [
                [
                    'type' => 'card',
                    'title' => 'Your board',
                    'text' => 'Community apps add account pages this way. They cannot take over /account/orders.',
                ],
            ],
        ]);
    }
}
