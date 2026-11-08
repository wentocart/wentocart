<?php

namespace Tests\Unit;

use App\Domain\Settings\SendmailPath;
use PHPUnit\Framework\TestCase;

class SendmailPathTest extends TestCase
{
    public function test_a_bare_binary_gets_the_php_mail_flags(): void
    {
        $this->assertSame('/usr/sbin/sendmail -t -i', SendmailPath::normalize('/usr/sbin/sendmail'));
    }

    public function test_an_existing_mode_is_left_alone(): void
    {
        $this->assertSame('/usr/sbin/sendmail -bs', SendmailPath::normalize('/usr/sbin/sendmail -bs'));
        $this->assertSame('/usr/sbin/sendmail -t -i', SendmailPath::normalize('/usr/sbin/sendmail -t -i'));
    }

    public function test_php_ini_path_is_used_as_the_server_mail_program(): void
    {
        $this->assertSame(
            '/usr/sbin/sendmail -t -i',
            SendmailPath::resolve('/usr/sbin/sendmail -t -i'),
        );
    }

    public function test_windows_without_sendmail_path_has_no_program(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Linux can fall back to /usr/sbin/sendmail when that file exists.');
        }

        $this->assertNull(SendmailPath::resolve(''));
    }
}
