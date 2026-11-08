<?php

namespace Modules\Wento\SampleNotice;

use App\Domain\Plugin\Support\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        $this->hooks()->listen('storefront.header.notice', function (array $payload) {
            if (! $this->settings()->bool('enabled', true)) {
                return $payload;
            }
            $message = trim((string) $this->settings()->get('message', 'This notice comes from a Safe app, not from core PHP.'));
            if ($message === '') {
                return $payload;
            }
            $existing = trim((string) ($payload['notice'] ?? ''));
            $payload['notice'] = $existing === '' ? $message : $message.' — '.$existing;

            return $payload;
        });
    }

    public function adminSchema(): array
    {
        return [
            'title' => 'Sample notice',
            'hint' => 'Core renders this form. The app only declared the fields.',
            'fields' => [
                [
                    'name' => 'enabled',
                    'type' => 'toggle',
                    'label' => 'Show on the storefront header',
                ],
                [
                    'name' => 'message',
                    'type' => 'textarea',
                    'label' => 'Notice text',
                    'hint' => 'Shown in the storefront header slot.',
                ],
            ],
        ];
    }
}
