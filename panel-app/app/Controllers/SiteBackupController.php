<?php
declare(strict_types=1);
namespace Controllers;

use Core\Auth;
use Core\DB;

/**
 * Per-site Backups (Manage Site → Backup tab). Thin courier: validate the request, then
 * call the backup:* CLI — which resolves the site's Linux user from the domain and
 * reads/writes ~/backups as that user (never as root, never via www-data). Backup names
 * travel on STDIN; only --domain crosses as an argument. canManageSite + CSRF are
 * enforced by the Router/CsrfMiddleware before we get here.
 */
class SiteBackupController extends BaseController
{
    /** POST /sites/{domain}/backups/create — streamed create with live progress. */
    public function create(array $params = []): never
    {
        $domain = $this->site($params);
        $actor  = (string) (Auth::user()['username'] ?? 'system');
        $this->streamCli('backup:create', ['--domain', $domain], function (array $r) use ($domain, $actor) {
            DB::log('backup.create', "domain={$domain} actor={$actor}");
            return ['redirect' => "/sites/{$domain}?tab=backup", 'message' => 'Backup created.'];
        });
    }

    /** GET /sites/{domain}/backups/download?name= — stream one archive as an attachment. */
    public function download(array $params = []): never
    {
        $domain = $this->site($params);
        $name   = basename((string) $this->request->get('name', ''));
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $name) || !str_starts_with($name, $domain . '_')) {
            abort(400, 'Invalid backup name.');
        }
        $this->unlockForLongOp();
        $code = run_cli_download_stdin('backup:download', ['--domain', $domain], $name, 'application/gzip', $name);
        if ($code !== 0 && !headers_sent()) {
            abort(404, 'Backup not available.');
        }
        DB::log('backup.download', "domain={$domain} name={$name} " . ($code === 0 ? 'ok' : 'fail'));
        exit;
    }

    /** POST /sites/{domain}/backups/delete — delete one archive (modal-confirmed). */
    public function delete(array $params = []): never
    {
        $domain = $this->site($params);
        $name   = basename((string) $this->request->post('name', ''));
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $name) || !str_starts_with($name, $domain . '_')) {
            $this->error('Invalid backup name.');
        }
        $actor = (string) (Auth::user()['username'] ?? 'system');
        $r = run_cli_stdin('backup:delete', ['--domain', $domain], $name);
        DB::log('backup.delete', "domain={$domain} name={$name} actor={$actor} " . ($r['success'] ? 'ok' : 'fail'));
        if (!$r['success']) {
            $this->error('Could not delete that backup.');
        }
        $this->success('Backup deleted.', "/sites/{$domain}?tab=backup");
    }

    /** Resolve + validate the {domain} route param; 404 if it is not a real site. */
    private function site(array $params): string
    {
        $domain = (string) ($params['domain'] ?? '');
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
        return $domain;
    }
}
