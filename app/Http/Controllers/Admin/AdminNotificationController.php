<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notify\AdminInbox;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        return response()->json([
            'unread' => AdminInbox::unreadCount($admin),
            'items' => $request->boolean('summary')
                ? []
                : AdminInbox::recent($admin, $request->boolean('unread')),
        ]);
    }

    /**
     * Server-Sent Events stream for the admin bell (realtime without a websocket broker).
     */
    public function stream(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        $lastUnread = -1;
        $ticks = 0;
        $maxSeconds = min(90, max(10, (int) $request->query('seconds', 60)));

        return response()->stream(function () use ($admin, &$lastUnread, &$ticks, $maxSeconds) {
            $started = time();
            while ((time() - $started) < $maxSeconds) {
                if (connection_aborted()) {
                    break;
                }
                $unread = AdminInbox::unreadCount($admin);
                if ($unread !== $lastUnread || $ticks % 5 === 0) {
                    $payload = json_encode([
                        'unread' => $unread,
                        'at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_SLASHES);
                    echo "event: inbox\n";
                    echo 'data: '.$payload."\n\n";
                    $lastUnread = $unread;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
                $ticks++;
                sleep(2);
            }
            echo "event: done\ndata: {}\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $admin = $this->admin($request);
        $row = AdminInbox::findVisible($notification);
        abort_unless($row, 404);
        AdminInbox::markRead($admin, $row);

        return response()->json(['unread' => AdminInbox::unreadCount($admin)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        AdminInbox::markAllRead($admin);

        return response()->json(['unread' => 0]);
    }

    protected function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin) {
            abort(403);
        }

        return $admin;
    }
}
