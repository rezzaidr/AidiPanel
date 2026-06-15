<?php
declare(strict_types=1);
namespace Controllers;

use Core\Request;
use Core\DB;
use Core\Auth;
use Core\Session;

abstract class BaseController
{
    protected Request $request;
    protected DB $db;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->db      = DB::instance();
    }

    protected function view(string $template, array $data = []): void
    {
        $data['_user']       = Auth::user();
        $data['_is_admin']   = Auth::isAdmin();
        $data['_csrf_token'] = Session::csrfToken();
        $data['_flash_error']        = flash('error');
        $data['_flash_warning']      = flash('warning');
        $data['_flash_success']      = flash('success');
        $data['_flash_success_warn'] = flash('success_warn');
        layout($template, $data);
    }

    protected function json(mixed $data, int $code = 200): never
    {
        json($data, $code);
    }

    protected function redirect(string $url): never
    {
        redirect($url);
    }

    protected function back(): never
    {
        redirect(safe_back_url());
    }

    protected function success(string $message, string $redirect = ''): never
    {
        flash('success', $message);
        redirect($redirect ?: safe_back_url());
    }

    protected function error(string $message, string $redirect = ''): never
    {
        flash('error', $message);
        redirect($redirect ?: safe_back_url());
    }

    protected function warning(string $message, string $redirect = ''): never
    {
        flash('warning', $message);
        redirect($redirect ?: safe_back_url());
    }

    /**
     * Release the session lock + lift the time limit before a slow, blocking CLI call
     * made from a NORMAL (non-streamed) request. Holding the session lock for a
     * multi-second op blocks every other same-session request, which can exhaust the
     * on-demand panel PHP-FPM pool and surface as a 502. This mirrors what
     * stream_begin() does for streamed ops; flash() re-opens the session afterwards so
     * success()/error() messages still survive the redirect.
     */
    protected function unlockForLongOp(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    /**
     * Run a long CLI op as an SSE progress stream (see run_cli_stream / stream_begin).
     * Emits a frame per @@PROGRESS marker, then a terminal frame. On success it calls
     * $onSuccess(array $result) — which may write to the DB / log — and uses its
     * ['redirect','message'] for the done frame. On failure the CLI's last line
     * becomes the error message. Never returns (the stream ends the request).
     */
    protected function streamCli(string $command, array $args, callable $onSuccess, ?callable $onError = null): never
    {
        stream_begin();

        $result = run_cli_stream($command, $args, function (string $pct, string $key, string $msg): void {
            stream_send(['t' => 'p', 'pct' => (int) $pct, 'key' => $key, 'msg' => $msg]);
        });

        if (!$result['success']) {
            if ($onError !== null) {
                $msg = $onError($result['output']);
            } else {
                // Default: the CLI's last line, minus any [ERROR]/[INFO] tag.
                $lines = array_values(array_filter(array_map('trim', explode("\n", $result['output'])), fn($l) => $l !== ''));
                $tail  = $lines ? (string) end($lines) : '';
                $tail  = preg_replace('/^\[(ERROR|WARN|INFO|OK)\]\s*/', '', $tail);
                $msg   = $tail !== '' ? $tail : 'Operation failed.';
            }
            stream_send(['t' => 'done', 'ok' => false, 'message' => $msg]);
            exit;
        }

        $done = $onSuccess($result);
        stream_send([
            't'        => 'done',
            'ok'       => true,
            'redirect' => $done['redirect'] ?? null,
            'message'  => $done['message'] ?? 'Done.',
        ]);
        exit;
    }
}
