<?php
declare(strict_types=1);
namespace Middleware;

use Core\Request;
use Core\Session;

class CsrfMiddleware
{
    public static function handle(Request $request): void
    {
        // Form posts send the hidden field; the JSON/file API sends the header.
        $token = (string) $request->post('_csrf_token', '');
        if ($token === '') {
            $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }
        $stored = Session::csrfToken();

        if (!hash_equals($stored, $token)) {
            // Regenerate token after failure
            Session::remove('_csrf_token');
            if ($request->isAjax()) {
                json(['success' => false, 'message' => 'CSRF token mismatch. Reload the page.'], 403);
            }
            abort(403, 'CSRF token mismatch. Please go back and try again.');
        }
    }
}
