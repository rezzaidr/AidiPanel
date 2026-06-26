<?php
declare(strict_types=1);
namespace Controllers;

use Core\DB;

/**
 * Per-site SFTP access (Manage Site → SFTP). Thin courier over the sftp:* CLI —
 * the gatekeeper that resolves the site's Linux user from the domain and edits the
 * sshd group / chroot / credentials as root. Untrusted keys/passwords travel on
 * STDIN. Admin-only + CSRF are enforced upstream; every change is audit-logged.
 */
class SiteSftpController extends BaseController
{
    /** GET status — JSON {enabled, password_set, keys[], host, port, user, path, ...}. */
    public function status(array $params = []): never
    {
        $domain = $this->site($params);
        $r = run_cli('sftp:status', ['--domain', $domain]);
        if ($r['success']) {
            $data = json_decode((string) $r['output'], true);
            if (is_array($data)) {
                // Show the public IPv4 (same source as the header/dashboard) rather
                // than the CLI's first NIC address: VPC/NAT hosts list a private
                // 10.x/192.168.x first, and on NAT clouds only this helper's external
                // echo can resolve the reachable IP. Falls back to the CLI host.
                $ip = server_public_ip();
                if ($ip !== '') { $data['host'] = $ip; }
                $this->json(['success' => true, 'data' => $data]);
            }
        }
        $this->reply($r);
    }

    /** POST enable — provision the sshd block + chroot, add the user to the group. */
    public function enable(array $params = []): never
    {
        $domain = $this->site($params);
        $r = run_cli('sftp:enable', ['--domain', $domain]);
        DB::log('sftp.enable', "domain={$domain} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST disable */
    public function disable(array $params = []): never
    {
        $domain = $this->site($params);
        $r = run_cli('sftp:disable', ['--domain', $domain]);
        DB::log('sftp.disable', "domain={$domain} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST password (password) — set/replace the SFTP password. */
    public function setPassword(array $params = []): never
    {
        $domain = $this->site($params);
        $pw = (string) $this->request->post('password', '');
        if (strlen($pw) < 8) { $this->fail('Password must be at least 8 characters.'); }
        $r = run_cli_stdin('sftp:passwd', ['--domain', $domain], $pw);
        DB::log('sftp.passwd', "domain={$domain} " . ($r['success'] ? 'set' : 'fail'));
        $this->reply($r);
    }

    /** POST password-clear — disable the password (keys still work). */
    public function clearPassword(array $params = []): never
    {
        $domain = $this->site($params);
        $r = run_cli('sftp:passwd-clear', ['--domain', $domain]);
        DB::log('sftp.passwd', "domain={$domain} " . ($r['success'] ? 'cleared' : 'fail'));
        $this->reply($r);
    }

    /** POST key-add (key) */
    public function addKey(array $params = []): never
    {
        $domain = $this->site($params);
        $key = trim((string) $this->request->post('key', ''));
        if ($key === '') { $this->fail('Paste a public key.'); }
        $key = (string) preg_replace('/\R.*$/s', '', $key);   // one key (first line) only
        $r = run_cli_stdin('sftp:key-add', ['--domain', $domain], $key);
        DB::log('sftp.key-add', "domain={$domain} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST key-delete (fingerprint) */
    public function deleteKey(array $params = []): never
    {
        $domain = $this->site($params);
        $fp = (string) $this->request->post('fingerprint', '');
        if ($fp === '') { $this->fail('No key specified.'); }
        $r = run_cli_stdin('sftp:key-delete', ['--domain', $domain], $fp);
        DB::log('sftp.key-delete', "domain={$domain} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    // ---- helpers (mirror SiteFileController) ----

    private function site(array $params): string
    {
        $domain = (string) ($params['domain'] ?? '');
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
        return $domain;
    }

    private function reply(array $r): never
    {
        if (!$r['success']) { $this->fail($this->cliMessage($r['output'])); }
        $decoded = json_decode($r['output'], true);
        if (is_array($decoded)) { $this->json(['success' => true, 'data' => $decoded]); }
        $this->json(['success' => true, 'message' => $this->cliMessage($r['output'])]);
    }

    private function fail(string $message): never
    {
        $this->json(['success' => false, 'message' => $message]);
    }

    private function cliMessage(string $out): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn($l) => $l !== ''));
        $tail  = $lines ? (string) end($lines) : '';
        return (string) (preg_replace('/^\[(ERROR|WARN|INFO|OK)\]\s*/', '', $tail) ?: 'Done.');
    }
}
