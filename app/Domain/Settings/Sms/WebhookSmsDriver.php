<?php

namespace App\Domain\Settings\Sms;

use App\Domain\Security\SafeUrl;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class WebhookSmsDriver implements SmsDriver
{
    public static function key(): string
    {
        return 'webhook';
    }

    public static function label(): string
    {
        return 'Generic webhook';
    }

    public function send(string $to, string $body, string $purpose): array
    {
        $hook = trim((string) ShopSetting::get('auth.sms_webhook', ''));
        if ($hook === '') {
            return ['ok' => true, 'error' => null];
        }

        try {
            SafeUrl::assertPublicHttp($hook, true);
            $response = Http::timeout(8)
                ->withOptions(SafeUrl::outboundOptions($hook, true))
                ->acceptJson()
                ->post($hook, [
                    'to' => $to,
                    'body' => $body,
                    'purpose' => $purpose,
                ]);

            return $response->successful()
                ? ['ok' => true, 'error' => null]
                : ['ok' => false, 'error' => 'HTTP '.$response->status()];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'Webhook URL is not allowed.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
