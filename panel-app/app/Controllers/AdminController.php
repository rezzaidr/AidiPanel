<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Admin Area — server-wide hub. The top-bar nav has no sidebar, so this page is
 * the single entry point to every server-level section. Existing sections link
 * out to their current pages; not-yet-built ones are shown as disabled previews
 * so the information architecture is visible from day one.
 */
class AdminController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->view('admin/index', ['pageTitle' => t('admin.title')]);
    }
}
