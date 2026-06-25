<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Admin Area — server-wide hub. Navigation now lives in a persistent left sidebar
 * (rendered by layout/base.php for every admin route), so /admin has no landing of
 * its own: it drops the user into the first sidebar section. Admins land on Panel
 * Users (the top item); read-only viewers (who cannot open /users) fall back to
 * Services so they are never bounced to an access-denied page.
 */
class AdminController extends BaseController
{
    public function index(array $params = []): void
    {
        // In the read-only demo the viewer may open /users too, so land there like an admin.
        $this->redirect((demo_mode() || \Core\Auth::isAdmin()) ? '/users' : '/services');
    }
}
