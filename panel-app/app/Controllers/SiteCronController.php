<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Site-scoped cron actions (Manage Site → Cron tab). Mirrors SiteDatabaseController:
 * validate → run_cli → flash → redirect ?tab=cron. The CLI is the real gatekeeper;
 * the command travels on stdin because cron commands legitimately contain shell
 * operators (&&, |, >) that the web wrapper's argument sanitizer rejects.
 */
class SiteCronController extends BaseController
{
    /** Add a cron job, or replace an existing one when an id is supplied (Edit). */
    public function add(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $sched   = $this->schedule($domain);
        $command = trim((string) $this->request->post('command', ''));
        if ($command === '') {
            $this->error('Command is required.', $this->tab($domain));
        }

        $args = ['--domain', $domain, '--schedule', $sched];
        $id = preg_replace('/[^a-z0-9]/', '', (string) $this->request->post('id', '')) ?? '';
        if ($id !== '') { $args[] = '--id'; $args[] = $id; }

        $result = run_cli_stdin('cron:add', $args, $command);
        if (!$result['success']) {
            $this->error('Could not save the cron job: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('cron:add', "Saved cron job for {$domain}");
        $this->success('Cron job saved.', $this->tab($domain));
    }

    /** Edit is an upsert by id (the form re-submits the existing id). */
    public function update(array $params = []): void
    {
        $this->add($params);
    }

    public function delete(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $id = preg_replace('/[^a-z0-9]/', '', (string) $this->request->post('id', '')) ?? '';
        if ($id === '') {
            $this->error('Job id is required.', $this->tab($domain));
        }
        if ($id === 'wpcron') {           // reserved preset → revert DISABLE_WP_CRON too
            $this->runWp($domain, 'disable');
            return;
        }
        $result = run_cli('cron:delete', ['--domain', $domain, '--id', $id]);
        if (!$result['success']) {
            $this->error('Could not delete the cron job: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('cron:delete', "Deleted cron job {$id} for {$domain}");
        $this->success('Cron job deleted.', $this->tab($domain));
    }

    public function toggle(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $id    = preg_replace('/[^a-z0-9]/', '', (string) $this->request->post('id', '')) ?? '';
        $state = $this->request->post('state') === 'on' ? 'on' : 'off';
        if ($id === '') {
            $this->error('Job id is required.', $this->tab($domain));
        }
        if ($id === 'wpcron' && $state === 'off') {
            $this->runWp($domain, 'disable');
            return;
        }
        $result = run_cli('cron:toggle', ['--domain', $domain, '--id', $id, '--state', $state]);
        if (!$result['success']) {
            $this->error('Could not update the cron job: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('cron:toggle', "Toggled cron job {$id} {$state} for {$domain}");
        $this->success('Cron job updated.', $this->tab($domain));
    }

    /** One-click WordPress real-cron preset (enable/disable). */
    public function wpCron(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);
        $action   = $this->request->post('action') === 'disable' ? 'disable' : 'enable';
        $interval = (int) $this->request->post('interval', 5);
        $this->runWp($domain, $action, $interval);
    }

    // ---- helpers ----

    private function runWp(string $domain, string $action, int $interval = 5): void
    {
        $args = ['--domain', $domain, '--action', $action];
        if ($action === 'enable') {
            if ($interval < 1 || $interval > 60) { $interval = 5; }
            $args[] = '--interval'; $args[] = (string) $interval;
        }
        $result = run_cli('cron:wp', $args);
        if (!$result['success']) {
            $this->error('Could not update WordPress cron: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('cron:wp', "WordPress cron {$action} for {$domain}");
        $this->success('WordPress cron updated.', $this->tab($domain));
    }

    /** Build + lightly check the 5-field schedule; the CLI re-validates with python3. */
    private function schedule(string $domain): string
    {
        $parts = [];
        foreach (['m', 'h', 'dom', 'mon', 'dow'] as $k) {
            $v = trim((string) $this->request->post($k, ''));
            if ($v === '') {
                $this->error('All schedule fields are required.', $this->tab($domain));
            }
            $parts[] = $v;
        }
        return implode(' ', $parts);
    }

    private function requireSite(string $domain): void
    {
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
    }

    private function tab(string $domain): string
    {
        return "/sites/{$domain}?tab=cron";
    }
}
