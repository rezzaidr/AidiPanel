<?php declare(strict_types=1); namespace Controllers;

class SslController extends BaseController
{
    public function index(array $params = []): void
    {
        $sites = $this->db->rows('SELECT domain, ssl_type FROM sites ORDER BY domain');
        $certs = [];
        foreach ($sites as $site) {
            $domain = $site['domain'];
            $lePath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $selfPath = "/etc/ssl/aidipanel-self/{$domain}.crt";

            $expiry = null; $type = 'none'; $daysLeft = null;
            if (file_exists($lePath)) {
                $type = "Let's Encrypt";
                $info = openssl_x509_parse((string) file_get_contents($lePath));
                if ($info) {
                    $expiry   = date('Y-m-d', $info['validTo_time_t']);
                    $daysLeft = (int) ceil(($info['validTo_time_t'] - time()) / 86400);
                }
            } elseif (file_exists($selfPath)) {
                $type   = 'Self-signed';
                $info   = openssl_x509_parse((string) file_get_contents($selfPath));
                $expiry = $info ? date('Y-m-d', $info['validTo_time_t']) : null;
            }
            $certs[$domain] = compact('type', 'expiry', 'daysLeft');
        }
        $this->view('ssl/index', compact('sites', 'certs'));
    }

    public function install(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->post('domain', '')));
        $email  = trim((string) $this->request->post('email', ''));

        if (!is_valid_domain($domain)) $this->error('Invalid domain name.');

        $args = ['--domain', $domain];
        if ($email) $args = array_merge($args, ['--email', $email]);

        if ($this->request->post('stream') === '1') {
            $this->streamCli('ssl:install', $args, function (array $r) use ($domain): array {
                $this->db->run("UPDATE sites SET ssl_type = 'letsencrypt' WHERE domain = ?", [$domain]);
                \Core\DB::log('ssl:install', "Installed SSL for: {$domain}");
                return ['redirect' => '/ssl', 'message' => "SSL certificate installed for {$domain}."];
            });
        }

        $result = run_cli('ssl:install', $args);
        if (!$result['success']) $this->error('SSL installation failed: ' . $result['output']);

        $this->db->run("UPDATE sites SET ssl_type = 'letsencrypt' WHERE domain = ?", [$domain]);
        \Core\DB::log('ssl:install', "Installed SSL for: {$domain}");
        $this->success("SSL certificate installed for {$domain}.", '/ssl');
    }

    public function renew(array $params = []): void
    {
        $domain = (string) $this->request->post('domain', '');
        if ($domain !== '' && !is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }

        $args   = $domain ? ['--domain', $domain] : [];

        if ($this->request->post('stream') === '1') {
            $this->streamCli('ssl:renew', $args, function (array $r) use ($domain): array {
                \Core\DB::log('ssl:renew', "Renewed SSL: " . ($domain ?: 'all'));
                return ['redirect' => '/ssl', 'message' => 'SSL certificates renewed.'];
            });
        }

        $result = run_cli('ssl:renew', $args);
        if (!$result['success']) $this->error('SSL renewal failed: ' . $result['output']);

        \Core\DB::log('ssl:renew', "Renewed SSL: " . ($domain ?: 'all'));
        $this->success('SSL certificates renewed.', '/ssl');
    }
}
