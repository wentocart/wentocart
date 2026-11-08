<?php

namespace App\Domain\Settings;

use App\Domain\Localization\Models\Language;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\Models\DocumentTemplateTranslation;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\Models\EmailTemplateTranslation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language subject/body/design for email + document (invoice) templates.
 * Default-language content stays on the parent row for backward compatibility.
 */
final class TemplateContentLocales
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('email_template_translations')) {
            Schema::create('email_template_translations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_template_id');
                $table->string('language_code', 8);
                $table->string('subject')->nullable();
                $table->longText('body')->nullable();
                $table->longText('design_json')->nullable();
                $table->timestamps();
                $table->unique(['email_template_id', 'language_code'], 'email_tpl_tr_lang_unique');
                $table->foreign('email_template_id', 'email_tpl_tr_fk')
                    ->references('id')->on('email_templates')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('document_template_translations')) {
            Schema::create('document_template_translations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_template_id');
                $table->string('language_code', 8);
                $table->string('email_subject')->nullable();
                $table->longText('body')->nullable();
                $table->longText('design_json')->nullable();
                $table->timestamps();
                $table->unique(['document_template_id', 'language_code'], 'doc_tpl_tr_lang_unique');
                $table->foreign('document_template_id', 'doc_tpl_tr_fk')
                    ->references('id')->on('document_templates')->cascadeOnDelete();
            });
        }
    }

    public static function defaultCode(): string
    {
        return (string) (Language::defaultCode() ?: 'en');
    }

    /**
     * @return list<array{code: string, name: string, is_default: bool}>
     */
    public static function languages(): array
    {
        self::ensure();

        return Language::query()->enabled()->orderBy('position')->orderBy('name')
            ->get(['code', 'name', 'is_default'])
            ->map(fn (Language $row) => [
                'code' => $row->code,
                'name' => $row->name,
                'is_default' => (bool) $row->is_default,
            ])
            ->all();
    }

    /**
     * @return array<string, array{subject: string, body: string, design_json: string}>
     */
    public static function emailMap(EmailTemplate $template): array
    {
        self::ensure();
        $default = self::defaultCode();
        $map = [];
        foreach (self::languages() as $lang) {
            $map[$lang['code']] = [
                'subject' => '',
                'body' => '',
                'design_json' => '',
            ];
        }
        $map[$default] = [
            'subject' => (string) $template->subject,
            'body' => (string) $template->body,
            'design_json' => (string) ($template->design_json ?? ''),
        ];

        if (Schema::hasTable('email_template_translations')) {
            foreach ($template->translations as $row) {
                $map[$row->language_code] = [
                    'subject' => (string) ($row->subject ?? ''),
                    'body' => (string) ($row->body ?? ''),
                    'design_json' => (string) ($row->design_json ?? ''),
                ];
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{email_subject: string, body: string, design_json: string}>
     */
    public static function documentMap(DocumentTemplate $template): array
    {
        self::ensure();
        $default = self::defaultCode();
        $map = [];
        foreach (self::languages() as $lang) {
            $map[$lang['code']] = [
                'email_subject' => '',
                'body' => '',
                'design_json' => '',
            ];
        }
        $map[$default] = [
            'email_subject' => (string) ($template->email_subject ?? ''),
            'body' => (string) $template->body,
            'design_json' => (string) ($template->design_json ?? ''),
        ];

        if (Schema::hasTable('document_template_translations')) {
            foreach ($template->translations as $row) {
                $map[$row->language_code] = [
                    'email_subject' => (string) ($row->email_subject ?? ''),
                    'body' => (string) ($row->body ?? ''),
                    'design_json' => (string) ($row->design_json ?? ''),
                ];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    public static function syncEmail(EmailTemplate $template, array $translations): void
    {
        self::ensure();
        $default = self::defaultCode();
        $allowed = collect(self::languages())->pluck('code')->all();

        foreach ($translations as $code => $row) {
            if (! is_string($code) || ! in_array($code, $allowed, true) || ! is_array($row)) {
                continue;
            }
            $subject = trim((string) ($row['subject'] ?? ''));
            $body = (string) ($row['body'] ?? '');
            $design = (string) ($row['design_json'] ?? '');

            if ($code === $default) {
                $template->subject = $subject !== '' ? $subject : $template->subject;
                if ($body !== '') {
                    $template->body = $body;
                }
                $template->design_json = $design !== '' ? $design : $template->design_json;
                $template->save();
            }

            if ($subject === '' && $body === '' && $design === '') {
                EmailTemplateTranslation::query()
                    ->where('email_template_id', $template->id)
                    ->where('language_code', $code)
                    ->delete();

                continue;
            }

            EmailTemplateTranslation::query()->updateOrCreate(
                ['email_template_id' => $template->id, 'language_code' => $code],
                [
                    'subject' => $subject !== '' ? $subject : null,
                    'body' => $body !== '' ? $body : null,
                    'design_json' => $design !== '' ? $design : null,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    public static function syncDocument(DocumentTemplate $template, array $translations): void
    {
        self::ensure();
        $default = self::defaultCode();
        $allowed = collect(self::languages())->pluck('code')->all();

        foreach ($translations as $code => $row) {
            if (! is_string($code) || ! in_array($code, $allowed, true) || ! is_array($row)) {
                continue;
            }
            $subject = trim((string) ($row['email_subject'] ?? ''));
            $body = (string) ($row['body'] ?? '');
            $design = (string) ($row['design_json'] ?? '');

            if ($code === $default) {
                $template->email_subject = $subject !== '' ? $subject : $template->email_subject;
                if ($body !== '') {
                    $template->body = $body;
                }
                $template->design_json = $design !== '' ? $design : $template->design_json;
                $template->save();
            }

            if ($subject === '' && $body === '' && $design === '') {
                DocumentTemplateTranslation::query()
                    ->where('document_template_id', $template->id)
                    ->where('language_code', $code)
                    ->delete();

                continue;
            }

            DocumentTemplateTranslation::query()->updateOrCreate(
                ['document_template_id' => $template->id, 'language_code' => $code],
                [
                    'email_subject' => $subject !== '' ? $subject : null,
                    'body' => $body !== '' ? $body : null,
                    'design_json' => $design !== '' ? $design : null,
                ],
            );
        }
    }

    /**
     * @return array{subject: string, body: string}
     */
    public static function resolveEmail(EmailTemplate $template, ?string $locale = null): array
    {
        self::ensure();
        $locale = $locale ?: (string) (Language::currentCode() ?: self::defaultCode());
        $default = self::defaultCode();

        $subject = (string) $template->subject;
        $body = (string) $template->body;

        if ($locale !== $default && Schema::hasTable('email_template_translations')) {
            $row = EmailTemplateTranslation::query()
                ->where('email_template_id', $template->id)
                ->where('language_code', $locale)
                ->first();
            if ($row) {
                if (trim((string) $row->subject) !== '') {
                    $subject = (string) $row->subject;
                }
                if (trim((string) $row->body) !== '') {
                    $body = (string) $row->body;
                }
            }
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @return array{email_subject: string, body: string, design_json: string}
     */
    public static function resolveDocument(DocumentTemplate $template, ?string $locale = null): array
    {
        self::ensure();
        $locale = $locale ?: (string) (Language::currentCode() ?: self::defaultCode());
        $default = self::defaultCode();

        $subject = (string) ($template->email_subject ?? '');
        $body = (string) $template->body;
        $design = (string) ($template->design_json ?? '');

        if ($locale !== $default && Schema::hasTable('document_template_translations')) {
            $row = DocumentTemplateTranslation::query()
                ->where('document_template_id', $template->id)
                ->where('language_code', $locale)
                ->first();
            if ($row) {
                if (trim((string) $row->email_subject) !== '') {
                    $subject = (string) $row->email_subject;
                }
                if (trim((string) $row->body) !== '') {
                    $body = (string) $row->body;
                }
                if (trim((string) $row->design_json) !== '') {
                    $design = (string) $row->design_json;
                }
            }
        }

        return ['email_subject' => $subject, 'body' => $body, 'design_json' => $design];
    }
}
