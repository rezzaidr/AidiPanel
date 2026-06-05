<?php
declare(strict_types=1);
namespace Middleware;

use Core\Request;
use Core\Session;

class CsrfMiddleware
{
    public static function handle(Request $request): void
    {
        $token  = $request->post('_csrf_token', '');
        $stored = Session::csrfToken();

        if (!hash_equals($stored, (string) $token)) {
            // Regenerate token after failure
            Session::remove('_csrf_token');
            abort(403, 'CSRF token mismatch. Please go back and try again.');
        }
    }
}
