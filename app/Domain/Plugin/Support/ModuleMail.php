<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\ShopMailer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Extensions send mail only through the shop mailer and an enabled EmailTemplate.
 * Merchants control subject/body in Configure → Email. Vars are scalar and stripped.
 */
class ModuleMail
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * @param  array<string, mixed>  $vars  Placeholders like {{customer_name}} in the template
     */
    public function sendTemplate(string $code, string $to, array $vars = []): void
    {
        $this->assertCanSend();

        $code = trim($code);
        if ($code === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $code)) {
            throw ValidationException::withMessages(['code' => 'Invalid email template code.']);
        }

        $to = trim($to);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['to' => 'A valid recipient email is required.']);
        }

        $template = EmailTemplate::query()->where('code', $code)->where('is_enabled', true)->first();
        if (! $template) {
            throw ValidationException::withMessages([
                'code' => 'Email template "'.$code.'" was not found or is disabled. Create it under Configure → Email.',
            ]);
        }

        app(ShopMailer::class)->sendTemplate($code, $this->sanitizeVars($vars), $to);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function listTemplates(): array
    {
        $this->assertCanSend();

        return EmailTemplate::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(static fn (EmailTemplate $t) => [
                'code' => (string) $t->code,
                'name' => (string) $t->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    protected function sanitizeVars(array $vars): array
    {
        $clean = [];
        $i = 0;
        foreach ($vars as $key => $value) {
            if ($i >= 40) {
                break;
            }
            $key = is_string($key) ? trim($key) : '';
            if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]{0,47}$/i', $key)) {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $clean[$key] = (string) $value;
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit(trim(strip_tags($value)), 500, '');
            } else {
                continue;
            }
            $i++;
        }

        return $clean;
    }

    protected function assertCanSend(): void
    {
        if (! $this->manifest->can(Capabilities::MAIL_SEND)) {
            throw new InvalidArgumentException('This app did not declare mail.send.');
        }
    }
}
