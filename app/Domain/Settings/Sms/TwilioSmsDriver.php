<?php

namespace App\Domain\Settings\Sms;

use App\Domain\Security\SafeUrl;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class TwilioSmsDriver implements SmsDriver
{
    public const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/';

    public static function key(): string
    {
        return 'twilio';
    }

    public static function label(): string
    {
        return 'Twilio';
    }

    public function send(string $to, string $body, string $purpose): array
    {
        $sid = trim((string) ShopSetting::get('auth.sms_twilio_sid', ''));
        $token = trim((string) ShopSetting::get('auth.sms_twilio_token', ''));
        $from = trim((string) ShopSetting::get('auth.sms_twilio_from', ''));
        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'error' => 'Twilio is not configured.'];
        }

        $url = self::ENDPOINT.rawurlencode($sid).'/Messages.json';
        try {
            SafeUrl::assertPublicHttp($url, true);
            $response = Http::timeout(10)
                ->withOptions(SafeUrl::outboundOptions($url, true))
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post($url, [
                    'From' => $from,
                    'To' => $to,
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'error' => null];
            }

            return ['ok' => false, 'error' => 'Twilio HTTP '.$response->status().': '.substr((string) $response->body(), 0, 200)];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'Twilio endpoint is not allowed.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
