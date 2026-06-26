<?php
/**
 * Server Settings (Admin Area). General = panel custom domain + SSL (mirrors the
 * CloudPanel "Custom Domain" card); Database Servers = read-only local server.
 * Mirrors the .tab/.card/.tbl components used across the admin UI.
 */
$ssl     = $panelSsl ?? [];
$db      = $dbServer ?? [];
$host    = (string) ($ssl['hostname'] ?? '');
$trusted = (($ssl['trusted'] ?? '0') === '1');
$dbEngineLabel = match ($db['engine'] ?? '') { 'mariadb' => 'MariaDB', 'mysql' => 'MySQL', default => '—' };
$dbActive = (($db['active'] ?? '0') === '1');
?>
<div x-data="{ tab: 'general', clearOpen: false }">

  <div class="mb-5">
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.settings.title')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.settings.subtitle')) ?></p>
  </div>

  <div class="border-b border-zinc-200 mb-5">
    <div class="flex items-center overflow-x-auto -mb-px">
      <button type="button" @click="tab='general'" :class="tab==='general' ? 'active' : ''" class="tab">
        <?= icon('adjustments-bolt', 'text-[15px]') ?> <?= e(t('admin.settings.tab.general')) ?>
      </button>
      <button type="button" @click="tab='database'" :class="tab==='database' ? 'active' : ''" class="tab">
        <?= icon('database', 'text-[15px]') ?> <?= e(t('admin.settings.tab.db_servers')) ?>
      </button>
    </div>
  </div>

  <!-- ================= GENERAL ================= -->
  <div x-show="tab==='general'" x-cloak class="space-y-5">
    <div class="card">
      <div class="card-head"><h2 class="card-title"><?= icon('world', 'text-ink') ?> <?= e(t('admin.settings.domain.title')) ?></h2></div>
      <form method="POST" action="/admin/settings/domain" data-op-stream x-data="{ submitting:false }">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="stream" value="1">
        <div data-op-fields class="px-5 py-4 space-y-3">
          <div>
            <label class="lbl"><?= e(t('admin.settings.domain.field')) ?></label>
            <div class="flex">
              <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-zinc-200 bg-zinc-50 text-zinc-500 text-sm mono">https://</span>
              <input type="text" name="domain" value="<?= e($host) ?>" placeholder="panel.example.com"
                     autocomplete="off" spellcheck="false" class="inp w-full rounded-l-none">
            </div>
            <p class="hint"><?= e(t('admin.settings.domain.hint')) ?></p>
          </div>
          <?php if ($host !== ''): ?>
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <?php if ($trusted): ?>
              <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('admin.settings.domain.trusted')) ?></span>
              <span class="text-zinc-400"><?= e(t('admin.settings.domain.expires', ['date' => (string) ($ssl['expiry'] ?? '')])) ?> · <?= e(t('admin.settings.domain.autorenew')) ?></span>
            <?php else: ?>
              <span class="badge badge-muted"><?= e(t('admin.settings.domain.not_trusted')) ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
        <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
          <button type="submit" class="btn btn-primary" :disabled="submitting"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('common.save')) ?></button>
        </div>
      </form>
      <?php if ($host !== ''): ?>
      <div class="flex justify-between items-center px-5 py-3.5 border-t border-zinc-100">
        <span class="text-xs text-zinc-400 mono"><?= e($host) ?></span>
        <button type="button" @click="clearOpen = true" class="btn btn-secondary btn-sm">
          <?= icon('trash', 'text-sm') ?> <?= e(t('admin.settings.domain.clear')) ?>
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ================= DATABASE SERVERS ================= -->
  <div x-show="tab==='database'" x-cloak class="space-y-5">
    <div class="card">
      <div class="card-head">
        <h2 class="card-title"><?= icon('database', 'text-ink') ?> <?= e(t('admin.settings.db.title')) ?></h2>
        <button type="button" disabled aria-disabled="true" class="btn btn-secondary btn-sm opacity-60 cursor-not-allowed">
          <?= icon('plus', 'text-sm') ?> <?= e(t('admin.settings.db.add')) ?>
          <span class="tag tag-muted ml-1.5"><?= e(t('common.soon')) ?></span>
        </button>
      </div>
      <table class="tbl" style="table-layout:fixed">
        <colgroup><col style="width:30%"><col style="width:28%"><col style="width:14%"><col style="width:14%"><col style="width:14%"></colgroup>
        <thead>
          <tr>
            <th><?= e(t('admin.settings.db.col.host')) ?></th>
            <th><?= e(t('admin.settings.db.col.engine')) ?></th>
            <th><?= e(t('admin.settings.db.col.port')) ?></th>
            <th><?= e(t('admin.settings.db.col.active')) ?></th>
            <th style="text-align:right"><?= e(t('admin.settings.db.col.action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="px-5 py-3 mono text-zinc-700"><?= e((string) ($db['host'] ?? '127.0.0.1')) ?></td>
            <td class="px-3 py-3 text-zinc-700"><?= e($dbEngineLabel) ?> <span class="text-zinc-400"><?= e((string) ($db['version'] ?? '')) ?></span></td>
            <td class="px-3 py-3 text-zinc-600"><?= e((string) ($db['port'] ?? '3306')) ?></td>
            <td class="px-3 py-3">
              <span class="badge <?= $dbActive ? 'badge-ok' : 'badge-muted' ?>">
                <?php if ($dbActive): ?><span class="dot bg-emerald-500"></span><?php endif; ?>
                <?= e($dbActive ? t('admin.settings.db.yes') : t('admin.settings.db.no')) ?>
              </span>
            </td>
            <td class="px-5 py-3 text-right text-zinc-300">—</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ================= CLEAR DOMAIN CONFIRM ================= -->
  <?php if ($host !== ''): ?>
  <template x-teleport="body">
    <div x-show="clearOpen" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40" @click="clearOpen = false"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('trash', 'text-red-500') ?> <?= e(t('admin.settings.domain.clear')) ?></h3>
          <button type="button" @click="clearOpen = false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/admin/settings/domain/clear" class="p-5 space-y-4">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <p class="text-sm text-zinc-600 leading-relaxed"><?= e(t('admin.settings.domain.clear_confirm')) ?></p>
          <p class="text-[11px] text-zinc-500 mono break-all bg-zinc-50 border border-zinc-100 rounded px-3 py-2"><?= e($host) ?></p>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="clearOpen = false" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 'text-sm') ?> <?= e(t('admin.settings.domain.clear')) ?></button>
          </div>
        </form>
      </div>
    </div>
  </template>
  <?php endif; ?>

</div>
