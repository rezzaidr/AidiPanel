<?php
declare(strict_types=1);
namespace Core;

/**
 * Capability layer for the panel's role model (admin / manager / client).
 * Reads the session identity (Auth) + the user_sites assignment table.
 *
 * Roles:
 *   admin   — everything, incl. the admin area (Users, Logs, Settings, services, PHP).
 *   manager — all sites (add/delete/manage), but NO admin area.
 *   client  — assigned sites only; full per-site management except delete-site; NO admin area.
 *   viewer  — public read-only demo (unchanged). In demo_mode it may still browse admin READ
 *             pages, preserving the existing demo behaviour (the Router demo guard blocks writes).
 *
 * All checks are server-side; the UI hiding is cosmetic only.
 */
class Access
{
    /** Admin area (Users, Logs, Settings, services, PHP, global cache). Admin only — except the
     *  demo viewer, which keeps its ability to browse admin read pages. */
    public static function canAccessAdminArea(): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        return demo_mode() && Auth::role() === 'viewer';
    }

    /** Add a site: admin or manager. */
    public static function canAddSite(): bool
    {
        return Auth::isAdmin() || Auth::isManager();
    }

    /** Delete a site: admin or manager (never client). */
    public static function canDeleteSite(): bool
    {
        return Auth::isAdmin() || Auth::isManager();
    }

    /** Edit PHP settings (version + tuning + additional config): admin or manager.
     *  Clients are read-only for the whole PHP settings card. */
    public static function canEditSiteSettings(): bool
    {
        return Auth::isAdmin() || Auth::isManager();
    }

    /** Site IDs this user may see. null = all (admin/manager); a list = client assignments. */
    public static function visibleSiteIds(): ?array
    {
        // admin/manager see every site. The public demo viewer (role 'viewer') also
        // sees all sites (read-only — the Router demo guard blocks every write).
        if (Auth::isAdmin() || Auth::isManager() || (demo_mode() && Auth::role() === 'viewer')) {
            return null;
        }
        $uid = (int) (Auth::user()['id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        $rows = DB::instance()->rows(
            'SELECT site_id FROM user_sites WHERE user_id = ?',
            [$uid]
        );
        return array_map(static fn($r) => (int) $r['site_id'], $rows);
    }

    /** May this user manage the given site (by domain)? Admin/manager always; client iff assigned. */
    public static function canManageSite(string $domain): bool
    {
        if (Auth::isAdmin() || Auth::isManager() || (demo_mode() && Auth::role() === 'viewer')) {
            return true;
        }
        $ids = self::visibleSiteIds();
        if ($ids === []) {
            return false;
        }
        $site = DB::instance()->row('SELECT id FROM sites WHERE domain = ?', [$domain]);
        return (bool) $site && in_array((int) $site['id'], $ids, true);
    }

    /** Assigned site IDs for a specific user (used by UserController edit/assign). */
    public static function assignedSiteIdsForUser(int $userId): array
    {
        $rows = DB::instance()->rows('SELECT site_id FROM user_sites WHERE user_id = ?', [$userId]);
        return array_map(static fn($r) => (int) $r['site_id'], $rows);
    }

    /**
     * Reconcile a user's site assignments. Only clients keep assignments; for any
     * other role the assignments are cleared. $siteIds is the full desired set.
     */
    public static function syncUserSiteAssignments(int $userId, string $role, array $siteIds): void
    {
        DB::instance()->run('DELETE FROM user_sites WHERE user_id = ?', [$userId]);
        if ($role !== 'client') {
            return;
        }
        $siteIds = array_values(array_filter(array_map('intval', $siteIds), static fn($i) => $i > 0));
        if ($siteIds === []) {
            return;
        }
        // Only attach IDs that actually exist (defence vs. stale/tampered input).
        $place = implode(',', array_fill(0, count($siteIds), '?'));
        $valid = DB::instance()->rows("SELECT id FROM sites WHERE id IN ({$place})", $siteIds);
        foreach ($valid as $row) {
            DB::instance()->run(
                'INSERT OR IGNORE INTO user_sites (user_id, site_id) VALUES (?, ?)',
                [$userId, (int) $row['id']]
            );
        }
    }

    /** Is this user the last administrator? Used to prevent self-lockout. */
    public static function isLastAdmin(int $userId): bool
    {
        $user = DB::instance()->row('SELECT role, active FROM users WHERE id = ?', [$userId]);
        if (!$user || $user['role'] !== 'admin' || (int) $user['active'] !== 1) {
            return false;
        }
        $row = DB::instance()->row("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND active = 1");
        return (int) ($row['c'] ?? 0) <= 1;
    }
}
