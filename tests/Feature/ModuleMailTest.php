<?php

namespace Tests\Feature;

use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleMail;
use App\Domain\Settings\Models\EmailTemplate;
use App\Mail\ShopHtmlMail;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        Mail::fake();
    }

    public function test_send_template_uses_shop_mailer(): void
    {
        EmailTemplate::query()->create([
            'code' => 'membership_activated',
            'name' => 'Membership activated',
            'subject' => 'Welcome {{plan_name}}',
            'body' => '<p>Hi {{customer_name}}, you are Prime.</p>',
            'is_enabled' => true,
            'is_custom' => true,
            'recipient' => 'custom',
        ]);

        $this->mailer(['mail.send'])->sendTemplate('membership_activated', 'member@example.com', [
            'customer_name' => 'Ada',
            'plan_name' => 'Yearly',
            'evil_html' => '<script>alert(1)</script>ignored-key-ok',
        ]);

        Mail::assertSent(ShopHtmlMail::class, function (ShopHtmlMail $mail) {
            return $mail->hasTo('member@example.com');
        });
    }

    public function test_mail_send_requires_capability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mail.send');
        $this->mailer(['settings'])->sendTemplate('welcome', 'a@example.com', []);
    }

    public function test_missing_template_is_rejected(): void
    {
        try {
            $this->mailer(['mail.send'])->sendTemplate('does_not_exist', 'a@example.com', []);
            $this->fail('missing template should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    public function test_invalid_recipient_is_rejected(): void
    {
        try {
            $this->mailer(['mail.send'])->sendTemplate('welcome', 'not-an-email', []);
            $this->fail('invalid email should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to', $e->errors());
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function mailer(array $capabilities): ModuleMail
    {
        $dir = storage_path('framework/testing/'.uniqid('modmail_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'notifier',
            'display_name' => 'Notifier',
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'test',
            'capabilities' => $capabilities,
        ]));

        $manifest = PluginManifest::fromArray(
            json_decode((string) file_get_contents($dir.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dir,
            false
        );

        return new ModuleMail($manifest);
    }
}
