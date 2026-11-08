<?php

namespace Tests\Unit;

use App\Http\SubdirectoryFrontController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SubdirectoryFrontControllerTest extends TestCase
{
    public function test_apache_folder_rewrite_exposes_laravel_path_without_the_folder_name(): void
    {
        $backup = $_SERVER;
        try {
            $_SERVER = array_merge($_SERVER, SubdirectoryFrontController::normalizeServer([
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080',
                'REQUEST_URI' => '/shop/installed',
                'QUERY_STRING' => '',
                'SCRIPT_NAME' => '/shop/public/index.php',
                'PHP_SELF' => '/shop/public/index.php',
                'SCRIPT_FILENAME' => '/var/www/shop/public/index.php',
                'DOCUMENT_ROOT' => '/var/www',
            ]));

            $request = Request::createFromGlobals();
            $this->assertSame('/shop/index.php', $_SERVER['SCRIPT_NAME']);
            $this->assertSame('installed', $request->path());
            $this->assertSame('/shop', $request->getBasePath());
        } finally {
            $_SERVER = $backup;
        }
    }

    public function test_nested_folder_and_root_public_docroot_are_unchanged(): void
    {
        $nested = SubdirectoryFrontController::normalizeServer([
            'REQUEST_URI' => '/shop/admin/login',
            'SCRIPT_NAME' => '/shop/public/index.php',
        ]);
        $this->assertSame('/shop/index.php', $nested['SCRIPT_NAME']);

        $root = SubdirectoryFrontController::normalizeServer([
            'REQUEST_URI' => '/installed',
            'SCRIPT_NAME' => '/index.php',
        ]);
        $this->assertSame('/index.php', $root['SCRIPT_NAME']);
    }

    public function test_php83_cgi_script_name_maps_to_the_shop_front_controller(): void
    {
        $backup = $_SERVER;
        try {
            $_SERVER = array_merge($_SERVER, SubdirectoryFrontController::normalizeServer([
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'localhost',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '80',
                'REQUEST_URI' => '/shop/admin/login',
                'QUERY_STRING' => '',
                'SCRIPT_NAME' => '/shop/php83.cgi',
                'PHP_SELF' => '/shop/php83.cgi',
                'SCRIPT_FILENAME' => '/var/www/shop/php83.cgi',
                'DOCUMENT_ROOT' => '/var/www',
            ]));

            $request = Request::createFromGlobals();
            $this->assertSame('/shop/index.php', $_SERVER['SCRIPT_NAME']);
            $this->assertSame('/var/www/shop/index.php', $_SERVER['SCRIPT_FILENAME']);
            $this->assertSame('admin/login', $request->path());
            $this->assertSame('/shop', $request->getBasePath());
        } finally {
            $_SERVER = $backup;
        }
    }

    public function test_root_cgi_script_name_maps_to_index_php(): void
    {
        $root = SubdirectoryFrontController::normalizeServer([
            'REQUEST_URI' => '/installed',
            'SCRIPT_NAME' => '/php83.cgi',
        ]);
        $this->assertSame('/index.php', $root['SCRIPT_NAME']);
        $this->assertSame('/index.php', $root['PHP_SELF']);
    }
}
