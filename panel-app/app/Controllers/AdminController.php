<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Admin Area — server-wide hub. Navigation now lives in a persistent left sidebar
 * (rendered by layout/base.php for every admin route), so /admin has no landing of
 * its own: it drops the user into the first sidebar section. Legacy top-level
 * Admin Area URLs remain authenticated GET redirects for bookmark compatibility.
 */
class AdminController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->redirect('/admin/users');
    }

    public function legacyUsers(array $params = []): never
    {
        $this->redirectLegacy('/admin/users');
    }

    public function legacyServices(array $params = []): never
    {
        $this->redirectLegacy('/admin/services');
    }

    public function legacyLogs(array $params = []): never
    {
        $this->redirectLegacy('/admin/logs');
    }

    private function redirectLegacy(string $path): never
    {
        $query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
        $this->redirect($path . ($query !== '' ? '?' . $query : ''));
    }
}
