<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Account settings (self-service) — reached from the top-bar profile menu.
 *
 * This is currently a design-complete PREVIEW: the Profile + Security (2FA)
 * layout is final, but saving and 2FA enrolment are deliberately not wired yet —
 * they need new `users` columns (email, first/last name, timezone, 2FA secret)
 * and a TOTP flow. Tracked for a follow-up PR in
 * _private/specs/2026-06-18-account-settings-page.md. Mirrors AdminController's
 * "show the IA as a disabled preview from day one" approach.
 */
class SettingsController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->view('settings/index', ['pageTitle' => t('settings.title')]);
    }
}
