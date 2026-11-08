<?php

/**
 * Last-chance page for fatals during install. A Linux host with display_errors off
 * otherwise shows a blank screen when storage or the shop folder is not writable.
 */
function wento_setup_guard_boot(string $root): void
{
    if (is_file($root.'/storage/app/installed.lock')) {
        return;
    }

    register_shutdown_function(static function () use ($root): void {
        if (defined('WENTO_INSTALL_HANDLED')) {
            return;
        }
        $err = error_get_last();
        if (! is_array($err) || ! in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $lib = $root.'/setup/lib.php';
        if (is_file($lib)) {
            require_once $lib;
        }
        $message = function_exists('setup_friendly_error')
            ? setup_friendly_error((string) ($err['message'] ?? ''))
            : 'The installer stopped because of a server error. Check the shop folder permissions, then reload this page.';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isApi = str_contains($script, 'api.php') || str_contains($script, 'worker.php');
        if (! headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store');
            if ($isApi) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return;
            }
        }
        if (function_exists('setup_render_permission_page') && preg_match('/Permission denied|failed to open stream/i', (string) ($err['message'] ?? ''))) {
            setup_render_permission_page([[
                'path' => $root,
                'label' => 'the shop folder',
            ]]);

            return;
        }
        if (! headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Installation stopped</title>'
            .'<style>body{font-family:system-ui,sans-serif;background:#f3f4f8;color:#0f172a}'
            .'.card{max-width:720px;margin:48px auto;background:#fff;border:1px solid #fecaca;border-radius:16px;padding:24px}'
            .'button{margin-top:16px;background:#5b21b6;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer}</style></head><body><div class="card">'
            .'<h1>Installation stopped</h1><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>'
            .'<p>Reload this page after the problem is fixed. You do not need to start again from the first step.</p>'
            .'<button type="button" onclick="location.reload()">Reload this page</button></div></body></html>';
    });
}
