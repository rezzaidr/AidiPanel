<?php
declare(strict_types=1);
namespace Controllers;

use Core\DB;

/**
 * Per-site File Manager (Manage Site → Files). Thin courier: validate the request,
 * then call the files:* CLI — the gatekeeper that resolves the site's Linux user
 * from the domain, jails to /home/<site_user>, and drops privilege. Reads/downloads
 * also go through the CLI; the panel user never touches site files directly.
 *
 * Untrusted paths/names travel on STDIN (the web wrapper filters argv content), so
 * only --domain + safe flags cross as arguments. Admin-only + CSRF are enforced by
 * the Router/CsrfMiddleware before we get here.
 */
class SiteFileController extends BaseController
{
    /** GET list?path= — directory listing as JSON. */
    public function list(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->get('path', '');
        if ($path === '.') { $path = ''; }
        $this->reply(run_cli_stdin('files:list', ['--domain', $domain], $path));
    }

    /** GET read?path= — file contents as JSON {content,sha256,mtime,size} or {error}. */
    public function read(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->get('path', '');
        if ($path === '') { $this->fail('Path is required.'); }
        if ($this->demoHidesPath($path)) { $this->fail('This file is hidden in the read-only demo.'); }
        $this->reply(run_cli_stdin('files:read', ['--domain', $domain], $path));
    }

    /** GET download?path= — stream bytes as an attachment. */
    public function download(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->get('path', '');
        $name   = basename(str_replace('\\', '/', $path));
        if ($path === '' || $name === '') { abort(400, 'Bad path.'); }
        if ($this->demoHidesPath($path)) { abort(403, 'This file is hidden in the read-only demo.'); }
        $this->unlockForLongOp();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        $code = run_cli_download_stdin('files:download', ['--domain', $domain], $path);
        DB::log('files.download', "domain={$domain} path={$path} " . ($code === 0 ? 'ok' : 'fail'));
        exit;
    }

    /** POST save (path, content, expect_sha256?, new?) — editor save / new file. */
    public function save(array $params = []): never
    {
        $domain  = $this->site($params);
        $path    = (string) $this->request->post('path', '');
        $content = (string) $this->request->post('content', '');
        if ($path === '') { $this->fail('Path is required.'); }
        $args = ['--domain', $domain];
        if ((string) $this->request->post('new', '') === '1') { $args[] = '--new'; }
        $expect = (string) $this->request->post('expect_sha256', '');
        if ($expect !== '' && ctype_xdigit($expect)) { $args[] = '--expect-sha256'; $args[] = $expect; }
        $r = run_cli_stdin('files:write', $args, $path . "\n" . $content);
        DB::log('files.write', "domain={$domain} path={$path} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST mkdir (path) */
    public function mkdir(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->post('path', '');
        if ($path === '') { $this->fail('Path is required.'); }
        $r = run_cli_stdin('files:mkdir', ['--domain', $domain], $path);
        DB::log('files.mkdir', "domain={$domain} path={$path} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST delete (paths[]) */
    public function delete(array $params = []): never
    {
        $domain = $this->site($params);
        $paths  = $this->paths();
        if (!$paths) { $this->fail('No paths given.'); }
        $r = run_cli_stdin('files:delete', ['--domain', $domain], implode("\n", $paths));
        DB::log('files.delete', "domain={$domain} count=" . count($paths) . ' ' . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST rename (path, newName) */
    public function rename(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->post('path', '');
        $name   = (string) $this->request->post('newName', '');
        if ($path === '' || $name === '') { $this->fail('Path and new name are required.'); }
        $r = run_cli_stdin('files:rename', ['--domain', $domain], $path . "\n" . $name);
        DB::log('files.rename', "domain={$domain} path={$path} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST copy / move (dest, paths[]) */
    public function copy(array $params = []): never { $this->copyMove($params, 'copy'); }
    public function move(array $params = []): never { $this->copyMove($params, 'move'); }

    private function copyMove(array $params, string $mode): never
    {
        $domain = $this->site($params);
        $dest   = (string) $this->request->post('dest', '.');
        $paths  = $this->paths();
        if (!$paths) { $this->fail('No paths given.'); }
        $r = run_cli_stdin("files:{$mode}", ['--domain', $domain], $dest . "\n" . implode("\n", $paths));
        DB::log("files.{$mode}", "domain={$domain} count=" . count($paths) . ' ' . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST chmod (paths[], mode, recursive?) */
    public function chmod(array $params = []): never
    {
        $domain = $this->site($params);
        $mode   = (string) $this->request->post('mode', '');
        $rec    = (string) $this->request->post('recursive', '') === '1';
        $paths  = $this->paths();
        if (!$paths || $mode === '') { $this->fail('Mode and paths are required.'); }
        $args = ['--domain', $domain, '--mode', $mode];
        if ($rec) { $args[] = '--recursive'; }
        $r = run_cli_stdin('files:chmod', $args, implode("\n", $paths));
        DB::log('files.chmod', "domain={$domain} mode={$mode} count=" . count($paths) . ' ' . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST zip (dir, name, paths[]) */
    public function zip(array $params = []): never
    {
        $domain = $this->site($params);
        $dir    = (string) $this->request->post('dir', '.');
        $name   = (string) $this->request->post('name', '');
        $paths  = $this->paths();
        if (!$paths || $name === '') { $this->fail('Name and paths are required.'); }
        $r = run_cli_stdin('files:zip', ['--domain', $domain], $dir . "\n" . $name . "\n" . implode("\n", $paths));
        DB::log('files.zip', "domain={$domain} name={$name} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** POST unzip (path, dest?) */
    public function unzip(array $params = []): never
    {
        $domain = $this->site($params);
        $path   = (string) $this->request->post('path', '');
        $dest   = (string) $this->request->post('dest', '');
        if ($path === '') { $this->fail('Path is required.'); }
        $this->unlockForLongOp();
        $r = run_cli_stdin('files:unzip', ['--domain', $domain], $path . "\n" . $dest);
        DB::log('files.unzip', "domain={$domain} path={$path} " . ($r['success'] ? 'ok' : 'fail'));
        $this->reply($r);
    }

    /** GET download-many?dir=&names[]= — zip the selection (one folder) and stream it. */
    public function downloadMany(array $params = []): never
    {
        $domain = $this->site($params);
        $dir    = (string) $this->request->get('dir', '');
        if ($dir === '.') { $dir = ''; }
        $raw    = $this->request->get('names', []);
        $names  = is_array($raw)
            ? array_values(array_filter(array_map('strval', $raw), static fn($n) => $n !== '' && !str_contains($n, '/')))
            : [];
        if (!$names) { abort(400, 'No files.'); }
        $paths  = array_map(static fn($n) => $dir !== '' ? "{$dir}/{$n}" : $n, $names);
        foreach ($paths as $p) {
            if ($this->demoHidesPath($p)) { abort(403, 'A selected file is hidden in the read-only demo.'); }
        }
        $this->unlockForLongOp();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $domain . '-files.zip"');
        header('X-Content-Type-Options: nosniff');
        $code = run_cli_download_stdin('files:download-many', ['--domain', $domain], implode("\n", $paths));
        DB::log('files.download', "domain={$domain} count=" . count($paths) . ' zip ' . ($code === 0 ? 'ok' : 'fail'));
        exit;
    }

    /**
     * POST upload-chunk (multipart: dest, name, id, final, chunk) — one slice of a
     * chunked upload. The browser streams big files in small parts so nginx/PHP
     * body-size + timeout limits never bind; the CLI appends + finalizes.
     */
    public function uploadChunk(array $params = []): never
    {
        $domain = $this->site($params);
        $dest   = (string) ($_POST['dest'] ?? '');
        if ($dest === '.') { $dest = ''; }
        $name   = basename(str_replace('\\', '/', (string) ($_POST['name'] ?? '')));
        $id     = (string) ($_POST['id'] ?? '');
        $offset = (string) ($_POST['offset'] ?? '0');
        $total  = (string) ($_POST['total'] ?? '0');
        $final  = (string) ($_POST['final'] ?? '') === '1';
        if ($name === '' || str_contains($name, '/') || !preg_match('/^[A-Za-z0-9]{8,64}$/', $id)) { $this->fail('Bad upload parameters.'); }
        if (!ctype_digit($offset)) { $offset = '0'; }
        if (!ctype_digit($total)) { $total = '0'; }
        if (empty($_FILES['chunk']) || (int) ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $this->fail('No chunk received.'); }
        $this->unlockForLongOp();
        $h = fopen((string) $_FILES['chunk']['tmp_name'], 'rb');
        if (!$h) { $this->fail('Could not read the chunk.'); }
        $args = ['--domain', $domain, '--id', $id, '--offset', $offset, '--total', $total];
        if ($final) { $args[] = '--final'; }
        $r = run_cli_upload('files:upload-chunk', $args, $dest . "\n" . $name . "\n", $h);
        fclose($h);
        if ($final) { DB::log('files.upload', "domain={$domain} dest={$dest} name={$name} chunked " . ($r['success'] ? 'ok' : 'fail')); }
        $this->reply($r);
    }

    /** POST upload-cancel (dest, id) — drop a partial chunked upload. */
    public function uploadCancel(array $params = []): never
    {
        $domain = $this->site($params);
        $dest   = (string) $this->request->post('dest', '');
        if ($dest === '.') { $dest = ''; }
        $id     = (string) $this->request->post('id', '');
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $id)) { $this->fail('Bad upload id.'); }
        $r = run_cli_stdin('files:upload-cancel', ['--domain', $domain, '--id', $id], $dest);
        $this->reply($r);
    }

    // ---- helpers ----

    private function site(array $params): string
    {
        $domain = (string) ($params['domain'] ?? '');
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
        return $domain;
    }

    /**
     * Read-only demo: DEFAULT-DENY. The public demo must assume a hostile,
     * anonymous visitor, so only an explicit allowlist of clearly-safe content
     * types may be opened/downloaded. Everything else — PHP, JSON, YAML, INI,
     * .env, dotfiles, anything that could carry secrets — is hidden. Browsing
     * the tree (files:list) still works; only contents are gated. A denylist of
     * known-secret names is too fragile (any new framework config convention not
     * yet on the list would silently leak), so we allowlist instead. No-op off-demo.
     */
    private function demoHidesPath(string $path): bool
    {
        if (!demo_mode()) { return false; }
        $norm = strtolower(str_replace('\\', '/', $path));
        $base = basename($norm);

        // Never expose dotfiles or the VCS dir (.env, .git, .htaccess, ...).
        if ($base !== '' && str_starts_with($base, '.')) { return true; }
        if ($norm === '.git' || str_starts_with($norm, '.git/') || str_contains($norm, '/.git/')) { return true; }

        // Allow only safe-to-demo content. Anything else — config, scripts,
        // structured data, unknown/extensionless files — is denied by default.
        $allowed = '/\.(txt|md|markdown|html?|css|js|mjs|svg|png|jpe?g|gif|webp|ico|bmp|avif|mp4|webm|ogg|mp3|wav|pdf|woff2?|ttf|otf|eot)$/i';
        return !preg_match($allowed, $base);
    }

    /** Read paths[] from POST or JSON body as a clean string list. */
    private function paths(): array
    {
        $raw = $this->request->post('paths', []);
        if (!is_array($raw)) { $raw = [$raw]; }
        return array_values(array_filter(array_map('strval', $raw), static fn($p) => $p !== ''));
    }

    /** Map a CLI result to JSON. list/read echo a JSON body; mutations echo [OK]. */
    private function reply(array $r): never
    {
        if (!$r['success']) { $this->fail($this->cliMessage($r['output'])); }
        $decoded = json_decode($r['output'], true);
        if (is_array($decoded)) {
            if (isset($decoded['error'])) { $this->json(['success' => false, 'error' => $decoded['error']]); }
            $this->json(['success' => true, 'data' => $decoded]);
        }
        $this->json(['success' => true, 'message' => $this->cliMessage($r['output'])]);
    }

    private function fail(string $message, array $extra = []): never
    {
        $this->json(['success' => false, 'message' => $message] + $extra);
    }

    private function cliMessage(string $out): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn($l) => $l !== ''));
        $tail  = $lines ? (string) end($lines) : '';
        return (string) (preg_replace('/^\[(ERROR|WARN|INFO|OK)\]\s*/', '', $tail) ?: 'Done.');
    }
}
