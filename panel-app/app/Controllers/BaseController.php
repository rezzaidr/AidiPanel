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
        $data['_flash_error']   = flash('error');
        $data['_flash_success'] = flash('success');
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
}
