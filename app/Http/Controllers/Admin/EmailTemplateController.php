<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\DefaultMailHtml;
use App\Domain\Settings\EmailLogIndex;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\MailRuntime;
use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\ShopMailer;
use App\Domain\Settings\ShopTools;
use App\Domain\Settings\TemplateContentLocales;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->string('tab')->toString() === 'settings') {
            return redirect()->route('admin.email.index', ['tab' => 'smtp']);
        }

        MailEvents::ensure();

        $tab = match ($request->string('tab')->toString()) {
            'templates' => 'templates',
            'smtp' => 'smtp',
            'logs' => 'logs',
            default => 'events',
        };

        $settings = EmailEventSetting::query()
            ->get()
            ->keyBy(fn (EmailEventSetting $row) => $row->event_code.'.'.$row->recipient);

        $events = collect(MailEvents::catalog())->map(function (array $event) use ($settings) {
            $recipients = collect($event['recipients'])->map(function (array $row) use ($event, $settings) {
                $setting = $settings->get($event['code'].'.'.$row['key']);
                $template = EmailTemplate::query()->where('code', $row['template'])->first();

                return [
                    ...$row,
                    'enabled' => $row['required'] ? true : ($setting?->is_enabled ?? true),
                    'template_id' => $template?->id,
                ];
            })->all();

            return [...$event, 'recipients' => $recipients];
        })->all();

        $templates = EmailTemplate::query()
            ->orderByDesc('is_custom')
            ->orderBy('name')
            ->get()
            ->map(fn (EmailTemplate $template) => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'subject' => $template->subject,
                'event_code' => $template->event_code,
                'recipient' => $template->recipient,
                'is_custom' => $template->is_custom,
                'is_enabled' => $template->is_enabled,
                'can_disable' => ! in_array($template->code, ['forgot_password', 'admin_forgot_password'], true),
                'updated_at' => optional($template->updated_at)?->toIso8601String(),
            ]);

        $logs = app(EmailLogIndex::class);

        return Inertia::render('Settings/Email', [
            'tab' => $tab,
            'events' => $events,
            'templates' => $templates,
            'smtp' => ShopTools::mailConfig(),
            'email_logs' => $tab === 'logs' ? $logs->paginator($request) : null,
            'email_log_filters' => $tab === 'logs' ? $logs->filters($request) : null,
            'email_log_stats' => $tab === 'logs' ? $logs->stats($request) : null,
            'email_log_templates' => $tab === 'logs' ? $logs->templates() : [],
        ]);
    }

    public function saveSmtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mailer' => ['required', 'in:log,smtp,sendmail,array'],
            'host' => ['nullable', 'string', 'max:180'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', Rule::in(['', 'tls', 'ssl'])],
            'username' => ['nullable', 'string', 'max:180'],
            'password' => ['nullable', 'string', 'max:180'],
            'from_address' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['mailer'] === 'smtp') {
            $request->validate([
                'host' => ['required', 'string', 'max:180'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
            ]);
        }

        MailRuntime::save($data);
        FileChangeLog::record('updated', 'email/smtp');

        return back()->with('success', 'SMTP settings saved.');
    }

    public function sendTest(Request $request, ShopMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:180'],
            'html' => ['nullable', 'string', 'max:200000'],
        ]);

        $subject = trim((string) ($data['subject'] ?? ''));
        $html = trim((string) ($data['html'] ?? ''));

        try {
            $mailer->sendTest(
                $data['to'],
                $subject !== '' ? $subject : ((string) config('app.name').' test email'),
                $html !== '' ? $html : '<p>This is a test message from your shop SMTP settings.</p>',
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Test email failed: '.$e->getMessage());
        }

        return back()->with('success', 'Test email sent.');
    }

    public function saveEvents(Request $request): RedirectResponse
    {
        MailEvents::ensure();

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.event_code' => ['required', 'string'],
            'settings.*.recipient' => ['required', 'in:customer,admin,seller,staff'],
            'settings.*.is_enabled' => ['required', 'boolean'],
        ]);

        foreach ($data['settings'] as $row) {
            if (MailEvents::isRequired($row['event_code'], $row['recipient'])) {
                $row['is_enabled'] = true;
            }
            if (! MailEvents::recipient($row['event_code'], $row['recipient'])) {
                continue;
            }
            EmailEventSetting::query()->updateOrCreate(
                ['event_code' => $row['event_code'], 'recipient' => $row['recipient']],
                ['is_enabled' => (bool) $row['is_enabled']],
            );
        }

        return wento_admin_toast(back()->with('success', 'Email events saved.'));
    }

    public function create(): Response
    {
        TemplateContentLocales::ensure();
        $default = TemplateContentLocales::defaultCode();
        $body = DefaultMailHtml::make(
            'Heading',
            '<p style="margin:0 0 16px;">Write your message here. Use variables such as {{customer_name}}.</p>',
            'Visit the shop',
            '{{shop_url}}',
        );

        return Inertia::render('Settings/EmailForm', [
            'template' => [
                'id' => null,
                'code' => '',
                'name' => '',
                'subject' => '',
                'body' => $body,
                'design_json' => null,
                'translations' => [$default => ['subject' => '', 'body' => $body, 'design_json' => '']],
                'is_custom' => true,
                'is_enabled' => true,
                'event_code' => null,
                'recipient' => 'custom',
            ],
            'languages' => TemplateContentLocales::languages(),
            'default_language' => $default,
            'variables' => $this->variables(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TemplateContentLocales::ensure();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'design_json' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
        ]);

        $template = EmailTemplate::query()->create([
            'code' => $this->uniqueCode($data['name']),
            'name' => $data['name'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'design_json' => $data['design_json'] ?? null,
            'is_enabled' => true,
            'is_custom' => true,
            'recipient' => 'custom',
            'event_code' => null,
        ]);
        if (! empty($data['translations']) && is_array($data['translations'])) {
            TemplateContentLocales::syncEmail($template, $data['translations']);
        }

        return redirect()->route('admin.email.edit', $template)->with('success', 'Template created.');
    }

    public function edit(EmailTemplate $emailTemplate): Response
    {
        TemplateContentLocales::ensure();

        return Inertia::render('Settings/EmailForm', [
            'template' => $this->payload($emailTemplate),
            'languages' => TemplateContentLocales::languages(),
            'default_language' => TemplateContentLocales::defaultCode(),
            'variables' => $this->variables(),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        TemplateContentLocales::ensure();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'design_json' => ['nullable', 'string'],
            'is_enabled' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'array'],
        ]);

        $emailTemplate->update([
            'name' => $data['name'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'design_json' => array_key_exists('design_json', $data) ? $data['design_json'] : $emailTemplate->design_json,
            'is_enabled' => $data['is_enabled'] ?? $emailTemplate->is_enabled,
        ]);
        if (! empty($data['translations']) && is_array($data['translations'])) {
            TemplateContentLocales::syncEmail($emailTemplate->fresh(), $data['translations']);
        }
        FileChangeLog::record('updated', 'email/templates/'.$emailTemplate->code);

        return back()->with('success', 'Template saved.');
    }

    public function duplicate(EmailTemplate $emailTemplate): RedirectResponse
    {
        $copy = $emailTemplate->replicate();
        $copy->code = $this->uniqueCode($emailTemplate->name.' copy');
        $copy->name = $emailTemplate->name.' copy';
        $copy->is_custom = true;
        $copy->event_code = null;
        $copy->recipient = 'custom';
        $copy->save();

        return redirect()->route('admin.email.edit', $copy)->with('success', 'Template duplicated.');
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        if (! $emailTemplate->is_custom) {
            return back()->with('error', 'System templates cannot be deleted.');
        }

        $emailTemplate->delete();

        return redirect()->route('admin.email.index', ['tab' => 'templates'])->with('success', 'Template deleted.');
    }

    public function toggle(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $enabled = $request->exists('is_enabled')
            ? $request->boolean('is_enabled')
            : ! $emailTemplate->is_enabled;

        if (in_array($emailTemplate->code, ['forgot_password', 'admin_forgot_password'], true) && ! $enabled) {
            return wento_admin_toast(back()->with('error', 'Password recovery cannot be turned off.'));
        }

        $emailTemplate->update(['is_enabled' => $enabled]);
        FileChangeLog::record('updated', 'email/templates/'.$emailTemplate->code);

        return wento_admin_toast(back()->with('success', $enabled ? 'Template enabled.' : 'Template disabled.'));
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $query = EmailTemplate::query()->whereIn('id', $data['ids']);
        if (! $data['enabled']) {
            $query->whereNotIn('code', ['forgot_password', 'admin_forgot_password']);
        }

        $updated = $query->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return wento_admin_toast(back()->with('error', 'No templates were updated.'));
        }

        return wento_admin_toast(back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Template enabled.' : $updated.' templates enabled.')
            : ($updated === 1 ? 'Template disabled.' : $updated.' templates disabled.')));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = EmailTemplate::query()
            ->whereIn('id', $data['ids'])
            ->where('is_custom', true)
            ->delete();

        if ($deleted === 0) {
            return wento_admin_toast(back()->with('error', 'System templates cannot be deleted.'));
        }

        return wento_admin_toast(back()->with('success', $deleted === 1 ? 'Template deleted.' : $deleted.' templates deleted.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (EmailTemplate::query()->whereIn('id', $data['ids'])->get() as $template) {
            $copy = $template->replicate();
            $copy->code = $this->uniqueCode($template->name.' copy');
            $copy->name = $template->name.' copy';
            $copy->is_custom = true;
            $copy->event_code = null;
            $copy->recipient = 'custom';
            $copy->save();
            $copied++;
        }

        if ($copied === 0) {
            return wento_admin_toast(back()->with('error', 'No templates were duplicated.'));
        }

        return wento_admin_toast(back()->with('success', $copied === 1 ? 'Template duplicated.' : $copied.' templates duplicated.'));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    protected function variables(): array
    {
        return [
            ['key' => 'shop_name', 'label' => 'Shop name'],
            ['key' => 'shop_url', 'label' => 'Shop URL'],
            ['key' => 'shop_email', 'label' => 'Shop email'],
            ['key' => 'customer_name', 'label' => 'Customer name'],
            ['key' => 'customer_email', 'label' => 'Customer email'],
            ['key' => 'order_number', 'label' => 'Order number'],
            ['key' => 'grand_total', 'label' => 'Order total'],
            ['key' => 'status', 'label' => 'Order status'],
            ['key' => 'payment_status', 'label' => 'Payment status'],
            ['key' => 'reset_url', 'label' => 'Password reset URL'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(EmailTemplate $template): array
    {
        $template->loadMissing('translations');

        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'subject' => $template->subject,
            'body' => $template->body,
            'design_json' => $template->design_json,
            'translations' => TemplateContentLocales::emailMap($template),
            'is_custom' => $template->is_custom,
            'is_enabled' => $template->is_enabled,
            'event_code' => $template->event_code,
            'recipient' => $template->recipient,
        ];
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $code = $base;
        $i = 2;
        while (EmailTemplate::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }
}
