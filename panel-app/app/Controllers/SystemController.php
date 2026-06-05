<?php declare(strict_types=1); namespace Controllers;

class SystemController extends BaseController
{
    public function logs(array $params = []): void
    {
        $domain  = (string) $this->request->get('domain', '');
        $type    = (string) $this->request->get('type', 'access');
        $lines   = min(500, max(20, (int) $this->request->get('lines', 100)));
        $sites   = $this->db->rows('SELECT domain FROM sites ORDER BY domain');

        $logContent = '';
        $logFile    = '';

        if ($domain) {
            $logFile = match($type) {
                'access'    => "/var/log/nginx/{$domain}-access.log",
                'error'     => "/var/log/nginx/{$domain}-error.log",
                'aidipanel' => '/var/log/aidipanel-cli.log',
                default     => '',
            };

            if ($logFile && file_exists($logFile) && is_readable($logFile)) {
                // Read last N lines safely
                $output = [];
                exec("tail -n " . escapeshellarg((string)$lines) . " " . escapeshellarg($logFile) . " 2>/dev/null", $output);
                $logContent = implode("\n", $output);
            }
        }

        $activity = $this->db->rows(
            'SELECT a.*, u.username FROM activity_log a
             LEFT JOIN users u ON a.user_id = u.id
             ORDER BY a.created_at DESC LIMIT 50'
        );

        $this->view('logs/index', compact('sites', 'domain', 'type', 'lines', 'logContent', 'logFile', 'activity'));
    }

    public function apiCli(array $params = []): void
    {
        // Restricted CLI API — only whitelisted safe commands
        $allowed = [
            'cache:status', 'site:list',
            'php:list', 'ssl:status', 'service:status',
            'system:info', 'db:list',
        ];

        $command = (string) $this->request->post('command', '');
        if (!in_array($command, $allowed, true)) {
            $this->json(['success' => false, 'message' => 'Command not allowed.'], 403);
        }

        $result = run_cli($command, []);
        $this->json($result);
    }
}
