<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Site-scoped access protection actions (Manage Site -> Security tab).
 */
class SiteSecurityController extends BaseController
{
    public function basicAuth(array $params = []): void
    {
        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        $this->requireSite($domain);
        $redirect = $this->tab($domain);

        if (!$this->checked('enabled')) {
            $result = run_cli('security:basic-auth', [
                '--domain', $domain,
                '--action', 'disable',
            ]);
            if (!$result['success']) {
                $this->error('Could not disable HTTP Basic Authentication: ' . $result['output'], $redirect);
            }
            \Core\DB::log('security:basic-auth', "Disabled HTTP Basic Authentication for {$domain}");
            $this->success('HTTP Basic Authentication disabled.', $redirect);
        }

        $scope = strtolower(trim((string) $this->request->post('scope', '')));
        if (!in_array($scope, ['wp-login', 'custom', 'site'], true)) {
            $this->error('Invalid protection scope.', $redirect);
        }

        $username = trim((string) $this->request->post('username', ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            $this->error('Username must be 1-64 letters, numbers, dots, underscores, or hyphens.', $redirect);
        }

        $path = trim((string) $this->request->post('path', ''));
        if ($scope === 'custom') {
            $path = rtrim($path, '/');
            if (
                strlen($path) < 2
                || strlen($path) > 200
                || !preg_match('#^/[A-Za-z0-9._~/-]+$#', $path)
                || str_contains($path, '//')
                || in_array('..', explode('/', $path), true)
            ) {
                $this->error('Enter a custom path such as /private without spaces, percent encoding, //, or .. segments.', $redirect);
            }
        } else {
            $path = '';
        }

        $rawIps = trim((string) $this->request->post('bypass_ips', ''));
        $tokens = preg_split('/[\s,]+/', $rawIps) ?: [];
        $ips = [];
        foreach ($tokens as $token) {
            $ip = trim($token);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $this->error("Invalid bypass IP address: {$ip}", $redirect);
            }
            if (!in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        $password = (string) $this->request->post('password', '');
        if (strlen($password) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $this->error('Password contains unsupported control characters or is too long.', $redirect);
        }

        $args = [
            '--domain', $domain,
            '--action', 'enable',
            '--scope', $scope,
            '--user', $username,
        ];
        if ($scope === 'custom') {
            $args[] = '--path';
            $args[] = $path;
        }
        if ($ips !== []) {
            $args[] = '--bypass-ips';
            $args[] = implode(',', $ips);
        }

        if ($password !== '') {
            $args[] = '--password-stdin';
            $result = run_cli_stdin('security:basic-auth', $args, $password . "\n");
        } else {
            $result = run_cli('security:basic-auth', $args);
        }

        if (!$result['success']) {
            $this->error('Could not update HTTP Basic Authentication: ' . $result['output'], $redirect);
        }

        \Core\DB::log(
            'security:basic-auth',
            "Enabled HTTP Basic Authentication ({$scope}) for {$domain}"
        );
        $this->success('HTTP Basic Authentication updated.', $redirect);
    }

    private function checked(string $field): bool
    {
        $value = $this->request->post($field);
        return $value !== null && $value !== '' && $value !== '0';
    }

    private function requireSite(string $domain): void
    {
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
    }

    private function tab(string $domain): string
    {
        return "/sites/{$domain}?tab=security";
    }
}
