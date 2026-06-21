<?php $pageTitle = t('admin.cache.title'); ?>

<!-- page header -->
<div class="mb-5">
  <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm" aria-hidden="true"></i> <?= e(t('admin.title')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.cache.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.cache.desc')) ?></p>
</div>

<!-- stat + action cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

  <!-- FastCGI stats + purge all -->
  <div class="card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 bg-speed-pale rounded-lg flex items-center justify-center">
        <i class="ti ti-bolt text-speed text-base" aria-hidden="true"></i>
      </div>
      <span class="font-head font-semibold text-sm text-zinc-900">FastCGI Cache</span>
    </div>
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div>
        <p class="eyebrow mb-1"><?= e(t('cache.title_size')) ?></p>
        <p class="text-xl font-bold text-zinc-900 mono"><?= e($stats['fcgi_size']) ?></p>
      </div>
      <div>
        <p class="eyebrow mb-1"><?= e(t('cache.title_files')) ?></p>
        <p class="text-xl font-bold text-zinc-900 mono"><?= e(number_format($stats['fcgi_files'])) ?></p>
      </div>
    </div>
    <form method="POST" action="/cache/purge">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <button type="submit"
              onclick="return confirm('<?= e(t('cache.purge_all_confirm')) ?>')"
              class="btn btn-danger btn-sm w-full">
        <i class="ti ti-trash text-sm" aria-hidden="true"></i> <?= e(t('cache.purge_all')) ?>
      </button>
    </form>
  </div>

  <!-- Redis stats -->
  <div class="card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 bg-ink-pale rounded-lg flex items-center justify-center">
        <i class="ti ti-brand-redis text-ink text-base" aria-hidden="true"></i>
      </div>
      <span class="font-head font-semibold text-sm text-zinc-900"><?= e(t('cache.redis_title')) ?></span>
    </div>
    <?php if (!empty($stats['redis'])): ?>
    <div class="grid grid-cols-3 gap-2 mb-3">
      <div>
        <p class="eyebrow mb-1"><?= e(t('cache.hits')) ?></p>
        <p class="text-base font-bold text-emerald-600 mono"><?= e(number_format($stats['redis']['hits'])) ?></p>
      </div>
      <div>
        <p class="eyebrow mb-1">Misses</p>
        <p class="text-base font-bold text-zinc-700 mono"><?= e(number_format($stats['redis']['misses'])) ?></p>
      </div>
      <div>
        <p class="eyebrow mb-1"><?= e(t('cache.hit_rate')) ?></p>
        <p class="text-base font-bold text-speed mono"><?= e($stats['redis']['hit_rate']) ?>%</p>
      </div>
    </div>
    <div class="h-1.5 bg-zinc-100 rounded-full overflow-hidden">
      <div class="h-1.5 bg-emerald-500 rounded-full"
           style="width:<?= e(min(100, (float) $stats['redis']['hit_rate'])) ?>%"></div>
    </div>
    <?php else: ?>
    <p class="text-sm text-zinc-400"><?= e(t('cache.redis_no_stats')) ?></p>
    <?php endif; ?>
  </div>

  <!-- Purge by URL -->
  <div class="card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center">
        <i class="ti ti-link text-amber-500 text-base" aria-hidden="true"></i>
      </div>
      <span class="font-head font-semibold text-sm text-zinc-900"><?= e(t('cache.purge_url_title')) ?></span>
    </div>
    <form method="POST" action="/cache/purge" class="space-y-3">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="text" name="url"
             placeholder="<?= e(t('cache.purge_url_ph')) ?>"
             class="inp">
      <button type="submit" class="btn btn-secondary btn-sm w-full">
        <i class="ti ti-refresh text-sm" aria-hidden="true"></i> Purge URL
      </button>
    </form>
  </div>

</div>

<!-- Cache per site -->
<div class="card overflow-hidden">
  <div class="card-head">
    <h2 class="card-title"><i class="ti ti-bolt text-speed" aria-hidden="true"></i> <?= e(t('cache.per_site_title')) ?></h2>
  </div>
  <?php if (empty($sites)): ?>
    <div class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('sites.empty')) ?></div>
  <?php else: ?>
  <div class="divide-y divide-zinc-50">
    <?php foreach ($sites as $site):
      $isWordPress = ($site['type'] ?? '') === 'wordpress';
      $cacheOn     = (bool) ($site['cache_enabled'] ?? false);
    ?>
    <div class="flex items-start justify-between gap-4 px-5 py-3.5">
      <div>
        <a href="/sites/<?= e($site['domain']) ?>"
           class="text-sm font-medium text-zinc-900 hover:text-ink"><?= e($site['domain']) ?></a>
        <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(ucfirst($site['type'])) ?></p>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <?php if ($cacheOn): ?>
        <form method="POST" action="/cache/purge" class="inline">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
          <button type="submit"
                  class="text-xs font-semibold text-amber-600 hover:bg-amber-50 px-2.5 py-1.5 rounded-md flex items-center gap-1">
            <i class="ti ti-refresh text-sm" aria-hidden="true"></i> <?= e(t('perf.purge')) ?>
          </button>
        </form>
        <?php endif; ?>

        <form method="POST" action="/cache/toggle">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
          <input type="hidden" name="action" value="<?= $cacheOn ? 'disable' : 'enable' ?>">

          <?php if (!$cacheOn && $isWordPress): ?>
          <div class="card border-zinc-100 bg-zinc-50 px-3 py-2 mb-2 text-xs text-zinc-600 space-y-1.5">
            <p class="font-semibold text-zinc-700">WordPress helpers</p>
            <label class="flex items-center gap-2">
              <input type="checkbox" name="install_nginx_helper" value="1" class="rounded border-zinc-300">
              Nginx Helper
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" name="install_redis_plugin" value="1" class="rounded border-zinc-300">
              Redis Object Cache
            </label>
          </div>
          <?php endif; ?>

          <button type="submit"
                  class="<?= $cacheOn
                    ? 'badge badge-info cursor-pointer hover:bg-red-50 hover:text-red-600 hover:border-red-200'
                    : 'badge badge-muted cursor-pointer hover:bg-speed-pale hover:text-speed hover:border-speed/30' ?>">
            <i class="ti <?= $cacheOn ? 'ti-bolt' : 'ti-bolt-off' ?> text-xs" aria-hidden="true"></i>
            <?= e($cacheOn ? t('cache.on') : t('cache.off')) ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
