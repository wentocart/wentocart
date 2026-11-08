<?php

namespace Tests\Feature;

use App\Domain\Security\SessionRevoker;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SessionRevokerTest extends TestCase
{
    public function test_revokes_only_the_matching_users_other_file_sessions(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-sessions-'.bin2hex(random_bytes(4));
        mkdir($dir);
        config(['session.driver' => 'file', 'session.files' => $dir]);

        $key = 'login_admin_'.sha1(SessionGuard::class);
        File::put($dir.'/mine-old', serialize([$key => 7]));
        File::put($dir.'/other-user', serialize([$key => 9]));
        File::put($dir.'/anonymous', serialize(['foo' => 'bar']));

        try {
            $killed = SessionRevoker::revokeOthers('admin', 7);

            $this->assertSame(1, $killed);
            $this->assertFileDoesNotExist($dir.'/mine-old');
            $this->assertFileExists($dir.'/other-user');
            $this->assertFileExists($dir.'/anonymous');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_missing_session_table_is_a_no_op_not_an_error(): void
    {
        config(['session.driver' => 'database']);

        $this->assertSame(0, SessionRevoker::revokeOthers('admin', 123456));
    }
}
