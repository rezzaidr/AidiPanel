<?php
declare(strict_types=1);
namespace Controllers;

use Core\Auth;
use Core\DB;

/** Server-wide S3 Remote Backups. Root-only work stays behind the CLI boundary. */
class RemoteBackupController extends BaseController
{
    public function index(array $params = []): void
    {
        $status = [
            'configured' => false,
            'provider' => 'AWS',
            'region' => 'us-east-1',
            'endpoint' => '',
            'bucket' => '',
            'folder' => 'aidipanel',
            'frequency' => 'daily',
            'weekday' => '1',
            'time' => '03:00',
            'exclude' => '',
            'retention' => 5,
            'credentials_saved' => false,
            'last_verified_at' => '',
            'last_run' => null,
        ];
        if (!demo_mode()) {
            $result = run_cli('remote-backup:status');
            $decoded = json_decode((string) ($result['output'] ?? ''), true);
            if (!empty($result['success']) && is_array($decoded)) {
                $status = array_replace($status, $decoded);
            }
        }
        $this->view('remote-backups/index', ['remoteBackup' => $status]);
    }

    public function test(array $params = []): never
    {
        $this->unlockForLongOp();
        $result = run_cli_stdin('remote-backup:test', [], $this->destinationPayload());
        DB::log('remote-backup.test', 'actor=' . $this->actor() . ' ' . (!empty($result['success']) ? 'ok' : 'fail'));
        if (empty($result['success'])) {
            $this->json(['ok' => false, 'message' => $this->cliMessage((string) ($result['output'] ?? ''))], 422);
        }
        $decoded = json_decode((string) ($result['output'] ?? ''), true);
        if (!is_array($decoded) || empty($decoded['ok']) || !is_array($decoded['checks'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Connection test returned an invalid response.'], 500);
        }
        $this->json($decoded);
    }

    public function saveDestination(array $params = []): never
    {
        $this->unlockForLongOp();
        $result = run_cli_stdin('remote-backup:save-destination', [], $this->destinationPayload());
        DB::log('remote-backup.destination', 'actor=' . $this->actor() . ' ' . (!empty($result['success']) ? 'ok' : 'fail'));
        if (empty($result['success'])) {
            $this->error($this->cliMessage((string) ($result['output'] ?? '')), '/admin/backups');
        }
        $this->success('Remote backup destination verified and saved.', '/admin/backups');
    }

    public function savePolicy(array $params = []): never
    {
        $payload = json_encode([
            'frequency' => (string) $this->request->post('frequency', 'daily'),
            'weekday' => (string) $this->request->post('weekday', ''),
            'time' => (string) $this->request->post('time', '03:00'),
            'exclude' => (string) $this->request->post('exclude', ''),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->unlockForLongOp();
        $result = run_cli_stdin('remote-backup:save-policy', [], $payload);
        DB::log('remote-backup.policy', 'actor=' . $this->actor() . ' ' . (!empty($result['success']) ? 'ok' : 'fail'));
        if (empty($result['success'])) {
            $this->error($this->cliMessage((string) ($result['output'] ?? '')), '/admin/backups');
        }
        $this->success('Backup policy saved.', '/admin/backups');
    }

    public function run(array $params = []): never
    {
        $actor = $this->actor();
        $this->streamCli('remote-backup:run', [], function (array $result) use ($actor): array {
            DB::log('remote-backup.run', "actor={$actor} ok");
            return ['redirect' => '/admin/backups', 'message' => 'Remote backup completed.'];
        }, function (string $output) use ($actor): string {
            DB::log('remote-backup.run', "actor={$actor} fail");
            return $this->cliMessage($output);
        });
    }

    private function destinationPayload(): string
    {
        return json_encode([
            'provider' => (string) $this->request->post('provider', ''),
            'use_saved_credentials' => $this->request->post('use_saved_credentials', '') === '1' ? '1' : '0',
            'access_key_id' => (string) $this->request->post('access_key_id', ''),
            'secret_access_key' => (string) $this->request->post('secret_access_key', ''),
            'region' => (string) $this->request->post('region', ''),
            'endpoint' => (string) $this->request->post('endpoint', ''),
            'bucket' => (string) $this->request->post('bucket', ''),
            'folder' => (string) $this->request->post('folder', 'aidipanel'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function actor(): string
    {
        return (string) (Auth::user()['username'] ?? 'system');
    }

    private function cliMessage(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $tail = $lines ? (string) end($lines) : 'Remote backup operation failed.';
        return (string) preg_replace('/^\[(ERROR|WARN|INFO|OK)\]\s*/', '', $tail);
    }
}
