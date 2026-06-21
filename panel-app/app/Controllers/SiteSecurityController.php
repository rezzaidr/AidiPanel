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

    public function ipBlock(array $params = []): void
    {
        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        $this->requireSite($domain);
        $redirect = $this->tab($domain);

        if (!$this->checked('enabled')) {
            $result = run_cli('security:ip-block', ['--domain', $domain, '--action', 'disable']);
            if (!$result['success']) {
                $this->error('Could not disable IP Blocking: ' . $this->ipBlockError((string) $result['output']), $redirect);
            }
            \Core\DB::log('security:ip-block', "Disabled IP Blocking for {$domain}");
            $this->success('IP Blocking disabled.', $redirect);
        }

        $rawList = (string) $this->request->post('ips', '');
        if (strlen($rawList) > 65536) {
            $this->error('The IP list is too large (max 64 KiB).', $redirect);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rawList)) {
            $this->error('The IP list contains unsupported control characters.', $redirect);
        }
        if (trim($rawList) === '') {
            $this->error('Enter at least one IP address or CIDR to enable IP Blocking.', $redirect);
        }

        // The list travels over stdin (never argv/logs). The CLI is the final
        // validator; on failure we surface only a mapped message, never the list.
        $result = run_cli_stdin('security:ip-block', [
            '--domain', $domain,
            '--action', 'set',
            '--ips-stdin',
        ], $rawList . "\n");

        if (!$result['success']) {
            $this->error('Could not update IP Blocking: ' . $this->ipBlockError((string) $result['output']), $redirect);
        }

        \Core\DB::log('security:ip-block', "Updated IP Blocking for {$domain}");
        $this->success('IP Blocking updated.', $redirect);
    }

    public function cloudflareOnly(array $params = []): void
    {
        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        $this->requireSite($domain);
        $redirect = $this->tab($domain);

        $action = $this->checked('enabled') ? 'enable' : 'disable';
        $result = run_cli('security:cloudflare-only', [
            '--domain', $domain,
            '--action', $action,
        ]);

        if (!$result['success']) {
            $verb = $action === 'enable' ? 'enable' : 'disable';
            $this->error(
                "Could not {$verb} direct-origin rejection: " . $this->cloudflareOnlyError((string) $result['output']),
                $redirect
            );
        }

        \Core\DB::log('security:cloudflare-only', ucfirst($action) . "d direct-origin rejection for {$domain}");
        $this->success(
            $action === 'enable' ? 'Direct-origin rejection enabled.' : 'Direct-origin rejection disabled.',
            $redirect
        );
    }

    private function cloudflareOnlyError(string $output): string
    {
        if (preg_match('/^error=([^\s:]+)/m', $output, $m)) {
            $map = [
                'dependency_unhealthy' => 'Cloudflare real-IP restoration must be active and healthy before direct-origin rejection can be enabled.',
                'sibling_drift'        => 'Another Security feature on this site has drifted; resolve it before changing direct-origin rejection.',
                'marker_drift'         => 'The direct-origin configuration has drifted from its saved state. Try again to reconcile it.',
                'state_mismatch'       => 'The direct-origin configuration has drifted from its saved state. Try again to reconcile it.',
                'missing_state'        => 'The direct-origin configuration has drifted from its saved state. Try again to reconcile it.',
                'malformed_state'      => 'The direct-origin configuration has drifted from its saved state. Try again to reconcile it.',
                'nginx_rejected'       => 'The web server rejected the change; the previous configuration was restored.',
                'busy'                 => 'Another change is in progress for this site; please try again.',
                'vhost_edit'           => 'The site vhost could not be edited (it needs exactly one HTTP and one HTTPS server block).',
            ];
            return $map[$m[1]] ?? 'The change could not be applied.';
        }
        return 'The change could not be applied.';
    }

    private function ipBlockError(string $output): string
    {
        if (preg_match('/^error=(\S+)/m', $output, $m)) {
            $map = [
                'invalid_list'   => 'The list has an invalid address or CIDR, a /0, too many entries (max 1000), or exceeds 64 KiB.',
                'sibling_drift'  => 'Another Security feature on this site has drifted; resolve it before changing IP Blocking.',
                'nginx_rejected' => 'The web server rejected the change; the previous configuration was restored.',
                'busy'           => 'Another change is in progress for this site; please try again.',
                'vhost_edit'     => 'The site vhost could not be edited (it needs exactly one HTTP and one HTTPS server block).',
            ];
            return $map[$m[1]] ?? 'The change could not be applied.';
        }
        return 'The change could not be applied.';
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
