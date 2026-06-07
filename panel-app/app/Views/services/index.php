<?php $pageTitle = t('admin.services.title'); ?>

<!-- page header -->
<div class="mb-6">
  <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm"></i> <?= e(t('admin.title')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.services.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.services.desc')) ?></p>
</div>

<?php if (empty($services)): ?>
<div class="card px-8 py-14 text-center">
  <i class="ti ti-server text-5xl text-zinc-200 block mb-3"></i>
  <p class="text-sm text-zinc-400"><?= e(t('services.empty')) ?></p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
  <?php foreach ($services as $name => $svc):
    // per-service palette
    $isNginx  = str_starts_with($name, 'nginx');
    $isDb     = str_starts_with($name, 'mysql') || str_starts_with($name, 'mariadb');
    $isRedis  = str_starts_with($name, 'redis');
    $isPhp    = str_starts_with($name, 'php');
    $iconBg   = match(true) {
        $isNginx => 'bg-emerald-50', $isDb => 'bg-amber-50',
        $isRedis => 'bg-red-50',     $isPhp => 'bg-ink-pale',
        default  => 'bg-zinc-100',
    };
    $iconName = match(true) {
        $isNginx => 'ti-server', $isDb => 'ti-database',
        $isRedis => 'ti-database', $isPhp => 'ti-brand-php',
        default  => 'ti-settings',
    };
    $iconColor = match(true) {
        $isNginx => 'text-emerald-600', $isDb => 'text-amber-600',
        $isRedis => 'text-red-500',     $isPhp => 'text-ink',
        default  => 'text-zinc-500',
    };
  ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center <?= $iconBg ?>">
          <i class="ti <?= $iconName ?> text-lg <?= $iconColor ?>"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-zinc-900"><?= e($name) ?></p>
          <p class="text-[11px] text-zinc-400">
            <?= e($svc['enabled'] ? t('svc.autostart_on') : t('svc.autostart_off')) ?>
          </p>
        </div>
      </div>
      <?php if ($svc['active']): ?>
        <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('services.running')) ?></span>
      <?php else: ?>
        <span class="badge badge-danger"><?= e(t('services.stopped')) ?></span>
      <?php endif; ?>
    </div>

    <div class="flex gap-2">
      <form method="POST" action="/services/action" class="flex-1">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="restart">
        <button type="submit" class="btn btn-ghost btn-sm w-full">
          <i class="ti ti-refresh text-sm"></i> <?= e(t('svc.restart')) ?>
        </button>
      </form>

      <?php if (!$svc['active']): ?>
      <form method="POST" action="/services/action" class="flex-1">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="start">
        <button type="submit" class="btn btn-sm w-full bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100">
          <i class="ti ti-player-play text-sm"></i> <?= e(t('svc.start')) ?>
        </button>
      </form>
      <?php else: ?>
      <form method="POST" action="/services/action" class="flex-1"
            onsubmit="return confirm('<?= e(t('svc.stop_confirm', ['svc' => $name])) ?>')">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="stop">
        <button type="submit" class="btn btn-sm w-full bg-red-50 border border-red-200 text-red-600 hover:bg-red-100">
          <i class="ti ti-player-stop text-sm"></i> <?= e(t('svc.stop')) ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
