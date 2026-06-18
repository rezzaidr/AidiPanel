<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Site-scoped SSL/TLS actions (Manage Site → SSL/TLS tab).
 * Mirrors the SiteController@changePhp pattern: validate → run_cli → flash → redirect ?tab=ssl.
 */
class SiteSslController extends BaseController
{
    /** Install a free Let's Encrypt certificate. */
    public function install(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $email = trim((string) $this->request->post('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.', $this->tab($domain));
        }

        // CloudPanel-style multi-domain list. The user decides which names go on
        // the cert; we validate each and guarantee the site's own domain is
        // present and first (certbot uses the first -d as the certificate name).
        $raw = $this->request->post('domains', []);
        if (!is_array($raw)) { $raw = [$raw]; }
        $domains = [];
        foreach ($raw as $d) {
            $d = strtolower(trim((string) $d));
            if ($d === '') { continue; }
            if (!is_valid_domain($d)) {
                $this->error("Invalid domain: {$d}", $this->tab($domain));
            }
            if (!in_array($d, $domains, true)) { $domains[] = $d; }
        }
        $domains = array_values(array_filter($domains, fn($d) => $d !== $domain));
        array_unshift($domains, $domain);

        $args = ['--domains', implode(',', $domains)];
        if ($email !== '') { $args[] = '--email'; $args[] = $email; }

        if ($this->request->post('stream') === '1') {
            $this->streamCli('ssl:install', $args, function (array $r) use ($domain, $domains): array {
                $this->db->run("UPDATE sites SET ssl_type = 'letsencrypt' WHERE domain = ?", [$domain]);
                \Core\DB::log('ssl:install', "Installed Let's Encrypt for: " . implode(', ', $domains));
                return ['redirect' => $this->tab($domain), 'message' => "Let's Encrypt certificate installed for {$domain}."];
            });
        }

        $result = run_cli('ssl:install', $args);
        if (!$result['success']) {
            $this->error("Let's Encrypt failed: " . $result['output'], $this->tab($domain));
        }

        $this->db->run("UPDATE sites SET ssl_type = 'letsencrypt' WHERE domain = ?", [$domain]);
        \Core\DB::log('ssl:install', "Installed Let's Encrypt for: " . implode(', ', $domains));
        $this->success("Let's Encrypt certificate installed for {$domain}.", $this->tab($domain));
    }

    /** Renew the site's certificate. */
    public function renew(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        if ($this->request->post('stream') === '1') {
            $this->streamCli('ssl:renew', ['--domain', $domain], function (array $r) use ($domain): array {
                \Core\DB::log('ssl:renew', "Renewed SSL for: {$domain}");
                return ['redirect' => $this->tab($domain), 'message' => "Certificate renewed for {$domain}."];
            });
        }

        $result = run_cli('ssl:renew', ['--domain', $domain]);
        if (!$result['success']) {
            $this->error('Renewal failed: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('ssl:renew', "Renewed SSL for: {$domain}");
        $this->success("Certificate renewed for {$domain}.", $this->tab($domain));
    }

    /** Import a user-supplied certificate (paid / wildcard / existing). */
    public function import(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $cert  = (string) $this->request->post('cert', '');
        $key   = (string) $this->request->post('key', '');
        $chain = (string) $this->request->post('chain', '');

        // Basic shape + size checks before touching the filesystem/CLI.
        foreach (['certificate' => $cert, 'private key' => $key] as $label => $pem) {
            if (trim($pem) === '')                 $this->error("The {$label} is required.", $this->tab($domain));
            if (!str_contains($pem, '-----BEGIN'))  $this->error("The {$label} is not valid PEM.", $this->tab($domain));
            if (strlen($pem) > 65536)               $this->error("The {$label} is too large.", $this->tab($domain));
        }
        if ($chain !== '' && (!str_contains($chain, '-----BEGIN') || strlen($chain) > 65536)) {
            $this->error('The certificate chain is not valid PEM.', $this->tab($domain));
        }

        // Stage the PEMs in a private temp dir and pass FILE PATHS to the CLI, so
        // the private key never appears in process args. Cleaned up afterwards.
        $stageDir = '/opt/aidipanel/storage/tmp/ssl-import-'
            . preg_replace('/[^a-z0-9.]/i', '', $domain) . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($stageDir, 0700, true) && !is_dir($stageDir)) {
            $this->error('Could not prepare import (storage not writable).', $this->tab($domain));
        }
        $certPath = "{$stageDir}/cert.pem";
        $keyPath  = "{$stageDir}/key.pem";
        file_put_contents($certPath, $cert); @chmod($certPath, 0600);
        file_put_contents($keyPath,  $key);  @chmod($keyPath,  0600);

        $args = ['--domain', $domain, '--cert', $certPath, '--key', $keyPath];
        if ($chain !== '') {
            $chainPath = "{$stageDir}/chain.pem";
            file_put_contents($chainPath, $chain); @chmod($chainPath, 0600);
            $args[] = '--chain'; $args[] = $chainPath;
        }

        $result = run_cli('ssl:import', $args);

        // Always clean up the staged PEMs (especially the private key).
        @unlink($certPath); @unlink($keyPath); @unlink("{$stageDir}/chain.pem");
        @rmdir($stageDir);

        if (!$result['success']) {
            $this->error('Import failed: ' . $result['output'], $this->tab($domain));
        }

        $this->db->run("UPDATE sites SET ssl_type = 'custom' WHERE domain = ?", [$domain]);
        \Core\DB::log('ssl:import', "Imported certificate for: {$domain}");
        $this->success("Certificate imported for {$domain}.", $this->tab($domain));
    }

    /** Toggle the HTTP→HTTPS redirect for this site. */
    public function forceHttps(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $action = $this->requireAction($domain);

        $result = run_cli('ssl:force-https', ['--domain', $domain, '--action', $action]);
        if (!$result['success']) {
            $this->error('Could not update Force HTTPS: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('ssl:force-https', "Force HTTPS {$action} for: {$domain}");
        $this->success("Force HTTPS turned {$action} for {$domain}.", $this->tab($domain));
    }

    /** Toggle the HSTS security header for this site. */
    public function hsts(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $action = $this->requireAction($domain);

        $result = run_cli('ssl:hsts', ['--domain', $domain, '--action', $action]);
        if (!$result['success']) {
            $this->error('Could not update HSTS: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('ssl:hsts', "HSTS {$action} for: {$domain}");
        $this->success("HSTS turned {$action} for {$domain}.", $this->tab($domain));
    }

    /** Toggle per-site Let's Encrypt auto-renew. */
    public function autoRenew(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $action = $this->requireAction($domain);

        $result = run_cli('ssl:autorenew', ['--domain', $domain, '--action', $action]);
        if (!$result['success']) {
            $this->error('Could not update auto-renew: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('ssl:autorenew', "Auto-renew {$action} for: {$domain}");
        $this->success("Auto-renew turned {$action} for {$domain}.", $this->tab($domain));
    }

    /** Switch the vhost to an already-installed certificate (from the certs table). */
    public function useCert(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $type = strtolower(trim((string) $this->request->post('type', '')));
        if (!in_array($type, ['letsencrypt', 'custom', 'self-signed'], true)) {
            $this->error('Unknown certificate type.', $this->tab($domain));
        }

        $result = run_cli('ssl:use', ['--domain', $domain, '--type', $type]);
        if (!$result['success']) {
            $this->error('Could not switch certificate: ' . $result['output'], $this->tab($domain));
        }
        $this->db->run('UPDATE sites SET ssl_type = ? WHERE domain = ?', [$type, $domain]);
        \Core\DB::log('ssl:use', "Switched to {$type} certificate for: {$domain}");
        $this->success("Now using the {$type} certificate for {$domain}.", $this->tab($domain));
    }

    /** Machine-readable SSL status for the "Run SSL check" action (JSON). */
    public function check(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->get('domain', '')));
        if (!is_valid_domain($domain)) {
            $this->json(['ok' => false, 'error' => 'invalid_domain'], 400);
        }
        if (!$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $result = run_cli('ssl:check', ['--domain', $domain]);
        $out    = trim((string) $result['output']);
        // Isolate the JSON object in case the sudo wrapper adds any stray output.
        if (preg_match('/\{.*\}/s', $out, $m)) {
            $out = $m[0];
        }
        $data = json_decode($out, true);
        if (!$result['success'] || !is_array($data)) {
            $this->json(['ok' => false, 'error' => 'cli_failed']);
        }
        $this->json(['ok' => true] + $data);
    }

    /** Validate the on/off action shared by the HTTPS-option toggles. */
    private function requireAction(string $domain): string
    {
        $action = strtolower(trim((string) $this->request->post('action', '')));
        if ($action !== 'on' && $action !== 'off') {
            $this->error('Invalid action.', $this->tab($domain));
        }
        return $action;
    }

    private function requireSite(string $domain): void
    {
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
    }

    private function tab(string $domain): string
    {
        return "/sites/{$domain}?tab=ssl";
    }
}
