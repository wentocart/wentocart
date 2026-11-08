<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Settings\DocumentPdf;
use App\Domain\Settings\MailRuntime;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\ShopMailer;
use App\Mail\ShopHtmlMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * PDF generation for extensions via core Dompdf. Optional email uses shop mailer.
 */
class ModulePdf
{
    public function __construct(protected PluginManifest $manifest) {}

    public function renderHtml(string $html, string $paper = 'a4'): string
    {
        $this->assertCan();
        $html = $this->sanitizeHtml($html);
        if ($html === '') {
            throw ValidationException::withMessages(['html' => 'PDF HTML is empty.']);
        }

        return app(DocumentPdf::class)->output($html, $paper === 'letter' ? 'letter' : 'a4');
    }

    /**
     * Email a PDF attachment using an enabled shop email template for the body/subject.
     *
     * @param  array<string, mixed>  $vars
     */
    public function mail(string $templateCode, string $to, array $vars, string $html, string $filename = 'document.pdf'): void
    {
        $this->assertCan();
        $templateCode = trim($templateCode);
        $to = trim($to);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['to' => 'A valid recipient email is required.']);
        }
        $template = EmailTemplate::query()->where('code', $templateCode)->where('is_enabled', true)->first();
        if (! $template) {
            throw ValidationException::withMessages(['code' => 'Email template "'.$templateCode.'" was not found or is disabled.']);
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) ?: 'document.pdf';
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        $binary = $this->renderHtml($html);
        $mailer = app(ShopMailer::class);
        $vars = $this->scalarVars($vars);
        $subject = EmailTemplate::replaceVars($template->subject, $mailer->withShopVars($vars));
        $body = EmailTemplate::replaceVars($template->body, $mailer->withShopVars($vars));

        MailRuntime::apply();
        Mail::to($to)->send(new ShopHtmlMail($body, $subject, [
            ['filename' => $filename, 'content' => $binary, 'mime' => 'application/pdf'],
        ]));
    }

    protected function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if (mb_strlen($html) > 200_000) {
            $html = mb_substr($html, 0, 200_000);
        }
        // Block obvious script execution in PDF HTML.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;

        return $html;
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    protected function scalarVars(array $vars): array
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
            if (is_scalar($value)) {
                $clean[$key] = Str::limit(trim(strip_tags((string) $value)), 500, '');
                $i++;
            }
        }

        return $clean;
    }

    protected function assertCan(): void
    {
        if (! $this->manifest->can(Capabilities::DOCUMENTS_PDF)) {
            throw new InvalidArgumentException('This app did not declare documents.pdf.');
        }
    }
}
