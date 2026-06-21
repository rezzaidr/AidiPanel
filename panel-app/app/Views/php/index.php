<?php $pageTitle = t('admin.php.title'); ?>

<!-- page header -->
<div class="flex items-center justify-between mb-5">
  <div>
    <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
      <i class="ti ti-arrow-left text-sm" aria-hidden="true"></i> <?= e(t('admin.title')) ?>
    </a>
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.php.title')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.php.desc')) ?></p>
  </div>
  <form method="POST" action="/php/restart">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
    <input type="hidden" name="version" value="all">
    <button type="submit" class="btn btn-secondary">
      <i class="ti ti-refresh text-sm" aria-hidden="true"></i> <?= e(t('php.restart_all')) ?>
    </button>
  </form>
</div>

<!-- PHP version cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php foreach ($versions as $ver => $info): ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2.5">
        <div class="w-9 h-9 bg-ink-pale rounded-lg flex items-center justify-center">
          <i class="ti ti-brand-php text-ink text-lg" aria-hidden="true"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-zinc-900">
            PHP <?= e($ver) ?>
            <span class="badge badge-muted text-[10px] ml-1"><?= e(t($info['label'])) ?></span>
          </p>
          <p class="text-[11px] text-zinc-400">
            <?php if ($info['installed']): ?>
              <?= e($info['full_ver']) ?><?= $info['default'] ? ' · ' . e(t('php.is_default')) : '' ?>
            <?php else: ?>
              <?= e(t('php.not_installed')) ?>
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php if ($info['installed']): ?>
        <?php if ($info['fpm_active']): ?>
          <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('services.running')) ?></span>
        <?php else: ?>
          <span class="badge badge-danger"><?= e(t('services.stopped')) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="badge badge-muted"><?= e(t('php.available')) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($info['installed']): ?>
    <form method="POST" action="/php/restart">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="version" value="<?= e($ver) ?>">
      <button type="submit" class="btn btn-ghost btn-sm w-full">
        <i class="ti ti-refresh text-sm" aria-hidden="true"></i> Restart PHP <?= e($ver) ?>-FPM
      </button>
    </form>
    <?php else: ?>
    <form method="POST" action="/php/install" data-op-stream>
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="version" value="<?= e($ver) ?>">
      <div data-op-fields>
        <button type="submit" class="btn btn-primary btn-sm w-full">
          <i class="ti ti-download text-sm" aria-hidden="true"></i> <?= e(t('php.install_btn')) ?> <?= e($ver) ?>
        </button>
      </div>
      <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- PHP version per site -->
<div class="card overflow-hidden">
  <div class="card-head">
    <h2 class="card-title"><i class="ti ti-world text-zinc-400" aria-hidden="true"></i> <?= e(t('php.per_site_title')) ?></h2>
  </div>
  <?php if (empty($sites)): ?>
    <div class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('sites.empty')) ?></div>
  <?php else: ?>
  <table class="tbl">
    <thead>
      <tr>
        <th><?= e(t('col.domain')) ?></th>
        <th><?= e(t('col.app')) ?></th>
        <th><?= e(t('site.ov.php_label')) ?></th>
        <th class="text-right"><?= e(t('col.action')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sites as $site): ?>
      <tr>
        <td class="font-medium text-zinc-900"><?= e($site['domain']) ?></td>
        <td class="text-xs text-zinc-500"><?= e(ucfirst($site['type'])) ?></td>
        <td>
          <span class="badge badge-info mono text-[11px]">PHP <?= e($site['php_version']) ?></span>
        </td>
        <td class="text-right">
          <a href="/sites/<?= e($site['domain']) ?>?tab=settings"
             class="text-xs font-semibold text-ink hover:bg-ink-pale px-2.5 py-1.5 rounded-md">
            <?= e(t('php.change')) ?>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
