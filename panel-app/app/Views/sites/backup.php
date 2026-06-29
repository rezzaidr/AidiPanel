<?php
/**
 * Per-site Backup tab — required by sites/detail.php when $activeTab === 'backup'.
 *
 * Available here (set by SiteController::detail): $domain, $backupEntries (each
 * ['name','size','mtime']), $_csrf_token, plus helpers e()/t()/icon()/format_bytes().
 * Backups are local archives in the site-user's ~/backups; create/download/delete go
 * through the backup:* CLI (root → drop to site-user). Read-only demo: actions hidden.
 *
 * One Alpine scope (x-data="{ deleteName }") wraps the table + the delete modal so the
 * row action menu and the (teleported) modal share state — mirrors the cron tab.
 */
$canBackup = !demo_mode() && \Core\Access::canManageSite($domain);
?>
<div class="space-y-5" x-data="{ deleteName: null }">

  <div class="card">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <?= icon('database-export', 'text-lg text-zinc-400') ?>
        <div>
          <h2 class="card-title"><?= e(t('site.backup.title')) ?></h2>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.backup.hint')) ?></p>
        </div>
      </div>
    </div>

    <div class="p-5 space-y-4">

      <?php if ($canBackup): ?>
      <!-- Backup now (streamed with live progress) -->
      <form method="POST" action="/sites/<?= e($domain) ?>/backups/create"
            x-data="backupCreateForm()" @submit="onSubmit($event)" x-ref="form">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div data-op-fields>
          <div class="flex items-center justify-between gap-4">
            <p class="text-[11px] text-zinc-400"><?= e(t('site.backup.retention_hint')) ?></p>
            <button type="submit" class="btn btn-primary shrink-0" :disabled="submitting">
              <span x-show="!submitting"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.backup.now')) ?></span>
              <span x-show="submitting" x-cloak><?= icon('loader-2', 'text-sm spin') ?> <?= e(t('site.backup.now')) ?></span>
            </button>
          </div>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
      </form>
      <?php else: ?>
      <p class="text-sm text-zinc-400"><?= e(t('site.backup.demo_disabled')) ?></p>
      <?php endif; ?>

      <!-- Privacy warning: a backup archive contains site files + DB dumps — keep it private. -->
      <p class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200/70 rounded-lg px-3 py-2 flex items-start gap-2">
        <?= icon('alert-triangle', 'text-amber-500 text-sm shrink-0 mt-0.5') ?>
        <span><?= e(t('site.backup.privacy_warning')) ?></span>
      </p>

      <!-- Backup list -->
      <?php if (empty($backupEntries)): ?>
        <p class="text-sm text-zinc-400 py-8 text-center"><?= e(t('site.backup.empty')) ?></p>
      <?php else: ?>
        <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-[11px] text-zinc-400 border-b border-zinc-100">
                <th class="py-2 pr-3 font-medium"><?= e(t('site.backup.col_file')) ?></th>
                <th class="py-2 pr-3 font-medium"><?= e(t('site.backup.col_date')) ?></th>
                <th class="py-2 pr-3 font-medium"><?= e(t('site.backup.col_size')) ?></th>
                <th class="py-2 font-medium text-center"><?= e(t('site.backup.col_action')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($backupEntries as $b):
                $bname  = (string) ($b['name'] ?? '');
                $bsize  = (int) ($b['size'] ?? 0);
                $bmtime = (int) ($b['mtime'] ?? 0);
                $bjname = htmlspecialchars(json_encode($bname), ENT_QUOTES); ?>
              <tr class="border-b border-zinc-50">
                <td class="py-2 pr-3 font-mono text-[12px] break-all"><?= e($bname) ?></td>
                <td class="py-2 pr-3 text-zinc-500 whitespace-nowrap"><?= $bmtime ? e(date('Y-m-d H:i', $bmtime)) : '—' ?></td>
                <td class="py-2 pr-3 text-zinc-500 whitespace-nowrap"><?= $bsize ? e(format_bytes($bsize)) : '—' ?></td>
                <td class="py-2 text-center">
                  <div class="relative inline-block text-left" x-data="{ row:false }" @click.outside="row=false">
                    <button type="button" @click="row=!row" aria-label="<?= e(t('common.actions')) ?>" class="w-8 h-8 rounded-lg hover:bg-zinc-100 text-zinc-400 hover:text-zinc-700"><?= icon('dots-vertical') ?></button>
                    <div x-show="row" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-44 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30 text-left">
                      <a href="/sites/<?= urlencode($domain) ?>/backups/download?name=<?= urlencode($bname) ?>" @click="row=false" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('download', 'text-sm text-zinc-400') ?> <?= e(t('site.backup.download')) ?></a>
                      <?php if ($canBackup): ?>
                      <button type="button" @click="row=false; deleteName = <?= $bjname ?>" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left"><?= icon('trash', 'text-sm') ?> <?= e(t('site.backup.delete')) ?></button>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
      <?php endif; ?>

    </div>
  </div>

  <?php if ($canBackup): ?>
  <!-- Delete confirmation modal (teleported to body; shares deleteName with the table). -->
  <template x-teleport="body">
    <div x-show="deleteName" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between gap-3">
          <h3 class="card-title"><?= e(t('site.backup.confirm_title')) ?></h3>
          <button type="button" @click="deleteName=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <div class="p-5 space-y-4">
          <p class="text-sm text-zinc-600"><?= e(t('site.backup.confirm_body')) ?></p>
          <p class="font-mono text-[12px] text-zinc-500 break-all" x-text="deleteName"></p>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="deleteName=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <form method="POST" action="/sites/<?= e($domain) ?>/backups/delete">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <input type="hidden" name="name" :value="deleteName">
              <button type="submit" class="btn btn-primary" style="background:#dc2626;border-color:#dc2626"><?= e(t('site.backup.delete')) ?></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </template>
  <?php endif; ?>

</div>

<?php if ($canBackup): ?>
<script>
// Stream backup:create (SSE @@PROGRESS) like the PHP-settings create form. Gated out of
// the read-only demo ($canBackup above), so it never runs there.
function backupCreateForm() {
  return {
    submitting: false,
    onSubmit(e) { e.preventDefault(); this._stream(); },
    _stream() {
      if (this.submitting) return;
      this.submitting = true;
      window.opGuard.start();
      var form   = this.$root;
      var fields = form.querySelector('[data-op-fields]');
      var ui     = window.opProgressController(form.querySelector('[data-op-progress]'));
      var fd     = new FormData(form);
      fd.set('stream', '1');
      if (fields) fields.classList.add('hidden');
      if (ui) ui.show();
      var self = this;
      window.opStream(form.getAttribute('action'), fd, {
        onProgress: function (pct, key, msg) { if (ui) ui.set(pct, key, msg); },
        onDone: function (frame) {
          window.opGuard.stop();
          if (frame.ok) {
            if (ui) ui.done();
            try { sessionStorage.setItem('aidipanel_toast', JSON.stringify({ kind: 'success', message: frame.message })); } catch (e) {}
            if (frame.redirect) window.location.href = frame.redirect; else window.location.reload();
          } else {
            self.submitting = false;
            if (ui) ui.fail(frame.message || 'Operation failed.');
            if (fields) fields.classList.remove('hidden');
          }
        },
        onError: function (msg) {
          window.opGuard.stop();
          self.submitting = false;
          if (ui) ui.fail(msg);
          if (fields) fields.classList.remove('hidden');
        }
      });
    }
  };
}
</script>
<?php endif; ?>
