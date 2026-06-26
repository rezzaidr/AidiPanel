<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Server Settings (Admin Area → Settings). General tab wraps the panel custom-domain
 * + SSL CLI; Database Servers tab is read-only. Admin-only for mutations (Router);
 * the GET page is also readable by the demo viewer (status only).
 */
class AdminSettingsController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->view('admin-settings/index', [
            'pageTitle' => t('admin.settings.title'),
            'panelSsl'  => $this->cliKv('panel:ssl', ['--action', 'status']),
            'dbServer'  => $this->cliKv('db:server-info', []),
        ]);
    }

    public function saveDomain(array $params = []): void
    {
        $stream = $this->request->post('stream') === '1';
        $host   = $this->normalizeHost((string) $this->request->post('domain', ''));
        if ($host === '') {
            $this->failSave(t('admin.settings.domain.err_empty'), $stream);
        }

        // 1) Persist hostname + rewrite the panel vhost/ACME conf (fast).
        $set = run_cli('panel:domain', ['--set', $host]);
        if (!$set['success']) {
            $this->failSave('Could not set the panel domain: ' . trim((string) $set['output']), $stream);
        }

        // 2) Issue the certificate (long op → streamed).
        $email = trim((string) (\Core\Auth::user()['email'] ?? ''));
        $args  = ['--action', 'issue'];
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $args[] = '--email';
            $args[] = $email;
        }

        if ($stream) {
            $this->streamCli('panel:ssl', $args, function (array $r) use ($host): array {
                \Core\DB::log('panel:ssl', "Issued panel certificate for {$host} via Settings");
                return ['redirect' => $this->panelSettingsRedirect($host), 'message' => t('admin.settings.domain.issued')];
            });
        }

        $res = run_cli('panel:ssl', $args);
        if (!$res['success']) {
            $this->error('Certificate issuance failed: ' . trim((string) $res['output']), '/admin/settings');
        }
        \Core\DB::log('panel:ssl', "Issued panel certificate for {$host} via Settings");
        $this->success(t('admin.settings.domain.issued'), $this->panelSettingsRedirect($host));
    }

    public function clearDomain(array $params = []): void
    {
        $redirect = $this->panelRecoveryRedirect($this->cliKv('panel:ssl', ['--action', 'status']));
        $res = run_cli('panel:domain', ['--action', 'clear']);
        if (!$res['success']) {
            $this->error('Could not clear the panel domain: ' . trim((string) $res['output']), '/admin/settings');
        }

        \Core\DB::log('panel:domain', 'Cleared panel custom domain via Settings');
        $this->success(t('admin.settings.domain.cleared'), $redirect);
    }

    /** Strip scheme/slashes/space and lowercase; the CLI re-validates the hostname. */
    private function normalizeHost(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = preg_replace('#^https?://#', '', $h) ?? $h;
        $h = explode('/', $h)[0];
        return trim($h);
    }

    private function panelSettingsRedirect(string $host): string
    {
        $host = $this->normalizeHost($host);
        return $host === '' ? '/admin/settings' : "https://{$host}/admin/settings?panel_toast=domain_issued";
    }

    private function panelRecoveryRedirect(array $status): string
    {
        $port = (string) ($status['port'] ?? '8443');
        if (!preg_match('/^[0-9]{2,5}$/', $port)) {
            $port = '8443';
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '127.0.0.1');
        $host = preg_replace('/:\d+$/', '', trim($host)) ?: '127.0.0.1';
        return "https://{$host}:{$port}/admin/settings";
    }

    /** A failed pre-stream step: SSE frame for streamed forms, flash+redirect otherwise. */
    private function failSave(string $msg, bool $stream): never
    {
        if ($stream) {
            stream_begin();
            stream_send(['t' => 'done', 'ok' => false, 'message' => $msg]);
            exit;
        }
        $this->error($msg, '/admin/settings');
    }

    /** Run a read-only CLI command and parse its key=value lines into an array. */
    private function cliKv(string $cmd, array $args): array
    {
        $res = run_cli($cmd, $args);
        $out = [];
        if (!empty($res['success'])) {
            foreach (explode("\n", trim((string) $res['output'])) as $line) {
                if (strpos($line, '=') !== false) {
                    [$k, $v] = explode('=', $line, 2);
                    $out[trim($k)] = trim($v);
                }
            }
        }
        return $out;
    }
}
