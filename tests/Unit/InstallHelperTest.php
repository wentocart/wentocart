<?php

namespace Tests\Unit;

use App\Domain\Installer\InstallerService;
use Tests\TestCase;

class InstallHelperTest extends TestCase
{
    public function test_windows_helper_pins_document_root_and_server_php(): void
    {
        $vbs = (string) file_get_contents(base_path('package/Install.vbs'));

        $this->assertStringContainsString('-t """ & root & """', $vbs);
        $this->assertStringContainsString('\server.php"""', $vbs);
        $this->assertStringContainsString('cd /d """ & root & """', $vbs);
        $this->assertStringContainsString('helper.port', $vbs);
        $this->assertStringContainsString('FindFreePort', $vbs);
        $this->assertStringContainsString('helper.php', $vbs);
        $this->assertFileExists(base_path('scripts/install/helper.php'));
    }

    public function test_unix_helper_pins_document_root_and_server_php(): void
    {
        $sh = (string) file_get_contents(base_path('package/install.sh'));

        $this->assertStringContainsString('-t "${ROOT_DIR}"', $sh);
        $this->assertStringContainsString('"${ROOT_DIR}/server.php"', $sh);
        $this->assertStringContainsString('helper.php', $sh);
    }

    public function test_find_free_port_skips_a_busy_port_when_no_preference(): void
    {
        $holder = @stream_socket_server('tcp://127.0.0.1:8099');
        if ($holder === false) {
            $this->markTestSkipped('Could not bind 8099 for the probe.');
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/install/helper.php')).' port';
        $picked = trim((string) shell_exec($cmd));
        fclose($holder);
        $this->assertNotSame('8099', $picked);
        $this->assertMatchesRegularExpression('/^\d+$/', $picked);
    }

    public function test_find_free_port_keeps_the_saved_shop_port_even_when_it_is_listening(): void
    {
        $holder = @stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($holder);
        $name = stream_socket_get_name($holder, false);
        $this->assertIsString($name);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/install/helper.php')).' port '.$port;
        $picked = trim((string) shell_exec($cmd));
        fclose($holder);
        $this->assertSame((string) $port, $picked);
    }

    public function test_helper_listen_spawn_uses_cli_php_not_php_win(): void
    {
        $lib = (string) file_get_contents(base_path('setup/lib.php'));
        $spawn = strstr($lib, 'function setup_spawn_helper_listen');
        $this->assertIsString($spawn);
        $spawn = explode('function setup_tcp_port_in_use', $spawn)[0];
        $this->assertStringContainsString('setup_cli_php_binary', $spawn);
        $this->assertStringContainsString('setup_windows_start_listen', $spawn);
        $this->assertStringNotContainsString('proc_open', $spawn);
        $this->assertStringNotContainsString('setup_background_php_binary', $spawn);
        $this->assertStringContainsString('start-listen.vbs', $lib);
        $this->assertFileExists(base_path('setup/start-listen.vbs'));
    }

    public function test_front_controller_defers_tidy_until_installed_page(): void
    {
        $front = (string) file_get_contents(base_path('index.php'));

        $this->assertStringContainsString("\$path === '/installed'", $front);
        $this->assertStringContainsString('str_starts_with($path, \'/installed/\')', $front);
        $this->assertStringContainsString("\$path === '/_extract.php'", $front);
        $this->assertStringContainsString('wento_render_install_gate', $front);

        $wrapper = (string) file_get_contents(base_path('package/index.php'));
        $this->assertStringContainsString('preInstallContents()', $wrapper);
        $this->assertStringContainsString('wrapper_render_install_guide', $wrapper);
        $this->assertStringNotContainsString('$fromZip', $wrapper);
    }

    public function test_install_task_rows_keep_grid_layout(): void
    {
        $wizard = (string) file_get_contents(base_path('setup/index.php'));

        $this->assertStringContainsString('grid-template-columns: 28px 1fr auto', $wizard);
        $this->assertStringContainsString('.task[hidden]', $wizard);
        $this->assertStringContainsString('function chosenShopOrigin()', $wizard);
    }

    public function test_install_worker_starts_chosen_port_before_finish_redirect(): void
    {
        $worker = (string) file_get_contents(base_path('setup/install-worker.php'));

        $this->assertStringContainsString('setup_spawn_helper_listen($shopPort, 8)', $worker);
        $this->assertStringContainsString('setup_spawn_helper_listen($shopPort, 15)', $worker);
        $this->assertStringNotContainsString('opening the installer Port instead', $worker);
        $this->assertStringContainsString('AdminPath::segment()', $worker);
        $this->assertStringNotContainsString("'/console/login'", $worker);
    }

    public function test_cli_install_success_url_uses_admin_path_not_console(): void
    {
        $cli = (string) file_get_contents(base_path('app/Console/Commands/InstallCommand.php'));

        $this->assertStringContainsString('AdminPath::segment()', $cli);
        $this->assertStringNotContainsString('/console/login', $cli);
    }

    public function test_env_example_has_empty_app_key_and_no_redacted_placeholders(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $example);
        $this->assertStringNotContainsString('<blanked>', $example);
        $this->assertStringContainsString('APP_NAME=WentoCart', $example);
    }

    public function test_app_key_validation_rejects_redacted_and_short_keys(): void
    {
        $this->assertFalse(InstallerService::appKeyIsValid(''));
        $this->assertFalse(InstallerService::appKeyIsValid('<blanked>'));
        $this->assertFalse(InstallerService::appKeyIsValid('base64:not-long-enough'));
        $this->assertFalse(InstallerService::appKeyIsValid('base64:fkUdmNWTpjA5MAyXWgEYdSyMXT5+nlnPISWS1H369Tc=<blanked>'));
        $this->assertTrue(InstallerService::appKeyIsValid('base64:'.base64_encode(str_repeat('a', 32))));
    }
}
