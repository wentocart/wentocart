<?php

namespace App\Http;

/**
 * Apache in a folder rewrites to public/index.php while leaving REQUEST_URI
 * as /folder/installed. Symfony then thinks the path is folder/installed.
 *
 * XAMPP PHP 7 + system PHP 8 CGI bridges also leave SCRIPT_NAME as php83.cgi,
 * which makes Laravel think the whole shop folder is the route path (404).
 */
final class SubdirectoryFrontController
{
    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    public static function normalizeServer(array $server): array
    {
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $uriPath = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $uriPath = str_replace('\\', '/', $uriPath);

        if (preg_match('#^(.*?)/[^/]+\.cgi$#i', $script, $cgi)) {
            $shop = $cgi[1];
            if ($shop === '') {
                $server['SCRIPT_NAME'] = '/index.php';
                $server['PHP_SELF'] = '/index.php';
                self::alignCgiScriptFilename($server);

                return $server;
            }
            if ($uriPath === $shop || str_starts_with($uriPath, $shop.'/')) {
                $server['SCRIPT_NAME'] = $shop.'/index.php';
                $server['PHP_SELF'] = $server['SCRIPT_NAME'];
                self::alignCgiScriptFilename($server);
                $script = $server['SCRIPT_NAME'];
            }
        }

        if (! preg_match('#^(.*?)/public/index\.php$#', $script, $match)) {
            return $server;
        }

        $shop = $match[1];
        if ($shop === '') {
            return $server;
        }

        $publicPrefix = $shop.'/public';
        if ($uriPath === $publicPrefix || str_starts_with($uriPath, $publicPrefix.'/')) {
            return $server;
        }

        if ($uriPath === $shop || str_starts_with($uriPath, $shop.'/')) {
            $server['SCRIPT_NAME'] = $shop.'/index.php';
            $server['PHP_SELF'] = $server['SCRIPT_NAME'];
        }

        return $server;
    }

    /**
     * Symfony only trusts SCRIPT_NAME when its basename matches SCRIPT_FILENAME.
     * A php83.cgi Action target must look like index.php or the shop folder stays in the route.
     *
     * @param  array<string, mixed>  $server
     */
    private static function alignCgiScriptFilename(array &$server): void
    {
        $filename = str_replace('\\', '/', (string) ($server['SCRIPT_FILENAME'] ?? ''));
        if ($filename !== '' && ! preg_match('#/[^/]+\.cgi$#i', $filename)) {
            return;
        }

        $dir = $filename === '' ? '' : rtrim(str_replace('\\', '/', dirname($filename)), '/');
        $server['SCRIPT_FILENAME'] = ($dir === '' || $dir === '.') ? 'index.php' : $dir.'/index.php';
    }
}
