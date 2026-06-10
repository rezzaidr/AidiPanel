<?php
$pageTitle = $site['domain'];
$domain    = $site['domain'];
$type      = $site['type'] ?? 'php';
$phpVer    = $site['php_version'] ?? '';
$sslState  = $ssl['state'] ?? 'none';
$hasLe     = $sslState === 'letsencrypt';
$hasTrustedSsl = $ssl['trusted'] ?? false;   // CA-signed + valid for this domain + not expired
$sslTypeLabel  = match ($sslState) {
    'letsencrypt' => t('site.ssl.le'),
    'custom'      => t('site.ssl.custom'),
    'self-signed' => t('site.ssl.self'),
    default       => t('site.ssl.none'),
};
// Why a present-but-untrusted cert is still flagged — guides the user to a fix.
$sslReason = null;
if (!$hasTrustedSsl && $sslState !== 'none') {
    if (!empty($ssl['expiry']) && (int) ($ssl['daysLeft'] ?? 1) <= 0) {
        $sslReason = t('site.ssl.reason_expired');
    } elseif (!empty($ssl['selfSigned'])) {
        $sslReason = t('site.ssl.reason_selfsigned');
    } elseif (empty($ssl['covers'])) {
        $sslReason = t('site.ssl.reason_mismatch', ['names' => implode(', ', $ssl['domains'] ?? []) ?: '—']);
    }
}
$hasCache  = (bool) ($site['cache_enabled'] ?? false);
$isStatic  = $type === 'static';
$iconBg    = $isStatic ? 'bg-zinc-100' : 'bg-ink-pale';
$iconColor = $isStatic ? 'text-zinc-400' : 'text-ink';
$visitUrl  = ($hasTrustedSsl ? 'https' : 'http') . '://' . $domain;   // only browser-trusted certs get https

$appIcon = static function (string $type): string {
    return match ($type) {
        'wordpress' => 'ti-brand-wordpress',
        'laravel'   => 'ti-code',
        'proxy'     => 'ti-arrow-guide',
        'static'    => 'ti-file-text',
        default     => 'ti-code',
    };
};
$appLabel = static function (string $type): string {
    return match ($type) {
        'wordpress' => t('app.wordpress'),
        'laravel'   => t('app.laravel'),
        'static'    => t('app.static'),
        'proxy'     => t('app.proxy'),
        default     => t('app.php'),
    };
};

$tabs = [
    'overview'    => ['icon' => 'ti-layout-grid',  'label' => t('site.tab.overview')],
    'performance' => ['icon' => 'ti-bolt',          'label' => t('site.tab.performance')],
    'ssl'         => ['icon' => 'ti-lock',          'label' => t('site.tab.ssl')],
    'database'    => ['icon' => 'ti-database',      'label' => t('site.tab.database')],
    'security'    => ['icon' => 'ti-shield-lock',   'label' => t('site.tab.security')],
    'cron'        => ['icon' => 'ti-clock-hour-4',  'label' => t('site.tab.cron')],
    'files'       => ['icon' => 'ti-folder',        'label' => t('site.tab.files')],
    'settings'    => ['icon' => 'ti-settings',      'label' => t('site.tab.settings')],
];

// ── OPcache stats (Performance tab only) ────────────────────────────────────
$opcacheEnabled = !empty($opcache['opcache_enabled']);
$opcHitRate  = '—';
$opcHits     = '—';
$opcUsedMem  = '—';
$opcTotalMem = '—';
if ($opcacheEnabled && isset($opcache['opcache_statistics'])) {
    $st         = $opcache['opcache_statistics'];
    $opcHitRate = round((float) ($st['opcache_hit_rate'] ?? 0), 1) . '%';
    $opcHits    = number_format((int) ($st['hits'] ?? 0));
    $mu         = $opcache['memory_usage'] ?? [];
    $opcUsedMem  = format_bytes((int) ($mu['used_memory'] ?? 0));
    $opcTotalMem = format_bytes((int) (($mu['used_memory'] ?? 0) + ($mu['free_memory'] ?? 0)));
}
?>

<!-- ===== FULL-BLEED SITE HEADER BAND ===== -->
<div class="bg-white border-b border-zinc-200/80 px-6 pt-4">
  <div class="max-w-[1100px] mx-auto">

    <!-- identity row -->
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <a href="/sites" class="text-zinc-400 hover:text-ink flex items-center text-sm" title="<?= e(t('site.back')) ?>">
          <i class="ti ti-arrow-left"></i>
        </a>
        <span class="w-10 h-10 rounded-lg <?= $iconBg ?> flex items-center justify-center shrink-0">
          <i class="ti <?= e($appIcon($type)) ?> <?= $iconColor ?> text-xl"></i>
        </span>
        <div>
          <div class="flex items-center gap-2">
            <h1 class="font-head font-bold text-[19px] text-zinc-900 leading-none"><?= e($domain) ?></h1>
            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              <?= e(t('site.active')) ?>
            </span>
          </div>
          <p class="text-xs text-zinc-400 mt-1.5">
            <?= e($appLabel($type)) ?>
            <?php if ($phpVer && $type !== 'static' && $type !== 'proxy'): ?>
              · <span class="mono">PHP <?= e($phpVer) ?></span>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <a href="<?= e($visitUrl) ?>" target="_blank" rel="noopener"
           class="btn btn-secondary text-xs flex items-center gap-1.5">
          <i class="ti ti-external-link text-sm"></i> <?= e(t('site.visit')) ?>
        </a>
      </div>
    </div>

    <!-- tab nav -->
    <div class="flex items-center overflow-x-auto -mb-px">
      <?php foreach ($tabs as $key => $tab): ?>
      <a href="/sites/<?= e($domain) ?>?tab=<?= $key ?>"
         class="tab <?= $activeTab === $key ? 'active' : '' ?>">
        <i class="ti <?= $tab['icon'] ?> text-[15px]"></i>
        <?= e($tab['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<!-- ===== TAB CONTENT ===== -->
<main class="max-w-[1100px] mx-auto px-6 py-6">

<?php if ($activeTab === 'overview'): ?>
<!-- ─────────────────────────── OVERVIEW ─────────────────────────────────── -->

  <!-- status cards -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">

    <!-- SSL -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="eyebrow"><?= e(t('site.ov.ssl_label')) ?></span>
        <i class="ti <?= $hasTrustedSsl ? 'ti-lock-check text-emerald-500' : 'ti-lock text-zinc-400' ?>"></i>
      </div>
      <p class="text-sm font-semibold text-zinc-900">
        <?= e($hasTrustedSsl ? t('site.ov.ssl_active') : t('site.ov.ssl_self')) ?>
      </p>
      <p class="text-[11px] text-zinc-400 mt-0.5">
        <?php if ($sslDaysLeft !== null): ?>
          <?= e(t('site.ov.days_left', ['n' => $sslDaysLeft])) ?>
          · <?= e($sslTypeLabel) ?>
        <?php else: ?>
          <?= e($sslTypeLabel) ?>
        <?php endif; ?>
      </p>
    </div>

    <!-- Cache -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="eyebrow"><?= e(t('site.ov.cache_label')) ?></span>
        <i class="ti ti-bolt <?= $hasCache ? 'text-speed' : 'text-zinc-300' ?>"></i>
      </div>
      <p class="text-sm font-semibold text-zinc-900">
        <?= e($hasCache ? t('site.ov.cache_on') : t('site.ov.cache_off')) ?>
      </p>
      <p class="text-[11px] text-zinc-400 mt-0.5">FastCGI</p>
    </div>

    <!-- PHP -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="eyebrow"><?= e(t('site.ov.php_label')) ?></span>
        <i class="ti ti-brand-php text-ink"></i>
      </div>
      <p class="text-sm font-semibold text-zinc-900 mono">
        <?= $phpVer ? e($phpVer) : '—' ?>
      </p>
      <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.ov.fpm_running')) ?></p>
    </div>

    <!-- Disk -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2">
        <span class="eyebrow"><?= e(t('site.ov.disk_label')) ?></span>
        <i class="ti ti-folder text-amber-500"></i>
      </div>
      <p class="text-sm font-semibold text-zinc-900 mono"><?= e($diskSize) ?></p>
      <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.ov.files_uploads')) ?></p>
    </div>

  </div>

  <!-- main 2-col layout -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- left: site details + activity log -->
    <div class="lg:col-span-2 space-y-5">

      <!-- site details -->
      <div class="card p-5">
        <h2 class="font-head font-semibold text-sm text-zinc-900 mb-4"><?= e(t('site.detail.title')) ?></h2>
        <div class="grid grid-cols-2 gap-x-6 gap-y-4">
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.domain')) ?></p>
            <p class="text-sm text-zinc-800"><?= e($domain) ?></p>
          </div>
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.app_type')) ?></p>
            <p class="text-sm text-zinc-800"><?= e($appLabel($type)) ?></p>
          </div>
          <?php if ($phpVer && $type !== 'static' && $type !== 'proxy'): ?>
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.php')) ?></p>
            <p class="text-sm text-zinc-800 mono"><?= e($phpVer) ?></p>
          </div>
          <?php endif; ?>
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.created')) ?></p>
            <p class="text-sm text-zinc-800">
              <?= e($site['created_at'] ? date('M j, Y', strtotime($site['created_at'])) : '—') ?>
            </p>
          </div>
          <div class="col-span-2">
            <p class="eyebrow mb-1"><?= e(t('site.detail.webroot')) ?></p>
            <p class="text-sm text-zinc-800 mono truncate"><?= e($site['webroot'] ?? '—') ?></p>
          </div>
          <?php if (!empty($site['site_user'])): ?>
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.site_user')) ?></p>
            <p class="text-sm text-zinc-800 mono"><?= e($site['site_user']) ?>
              <span class="text-zinc-400 text-xs">· <?= e(t('site.detail.no_login')) ?></span></p>
          </div>
          <?php if ($phpVer && $type !== 'static' && $type !== 'proxy'): ?>
          <div>
            <p class="eyebrow mb-1"><?= e(t('site.detail.fpm_socket')) ?></p>
            <p class="text-sm text-zinc-800 mono truncate" title="/run/php/php<?= e($phpVer) ?>-fpm-<?= e($site['site_user']) ?>.sock">pool <?= e($site['site_user']) ?> · /run/php/php<?= e($phpVer) ?>-fpm-<?= e($site['site_user']) ?>.sock</p>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- recent activity -->
      <div class="card overflow-hidden">
        <div class="card-head">
          <h2 class="card-title"><?= e(t('site.activity.title')) ?></h2>
          <a href="/logs" class="text-xs text-ink font-medium hover:text-ink-light flex items-center gap-1">
            <?= e(t('site.activity.view_all')) ?> <i class="ti ti-arrow-right text-sm"></i>
          </a>
        </div>
        <?php if (empty($logs)): ?>
          <div class="px-5 py-6 text-center text-sm text-zinc-400"><?= e(t('site.activity.empty')) ?></div>
        <?php else: ?>
          <div class="divide-y divide-zinc-50 font-mono text-[11px]">
            <?php foreach ($logs as $log): ?>
            <div class="px-5 py-2.5 flex items-center gap-3">
              <span class="text-zinc-300 shrink-0 w-24 truncate">
                <?= e(date('M d H:i', strtotime($log['created_at']))) ?>
              </span>
              <span class="badge badge-info shrink-0"><?= e($log['action']) ?></span>
              <span class="text-zinc-600 truncate"><?= e($log['detail']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- right: quick actions -->
    <div class="space-y-5">
      <div class="card p-3">
        <p class="eyebrow px-2 pt-1 pb-2"><?= e(t('site.qa.title')) ?></p>

        <a href="<?= e($visitUrl) ?>" target="_blank" rel="noopener"
           class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-zinc-50 text-sm text-zinc-700 transition-colors">
          <i class="ti ti-external-link text-[18px] text-zinc-400"></i>
          <?= e(t('site.qa.visit')) ?>
        </a>

        <?php if ($hasCache): ?>
        <form method="POST" action="/cache/purge">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <button type="submit"
                  class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-zinc-50 text-sm text-zinc-700 transition-colors text-left">
            <i class="ti ti-bolt text-[18px] text-speed"></i>
            <?= e(t('site.qa.clear_cache')) ?>
          </button>
        </form>
        <?php endif; ?>

        <form method="POST" action="/php/restart">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <button type="submit"
                  class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-zinc-50 text-sm text-zinc-700 transition-colors text-left">
            <i class="ti ti-refresh text-[18px] text-zinc-400"></i>
            <?= e(t('site.qa.restart_php')) ?>
          </button>
        </form>
      </div>
    </div>

  </div>

<?php elseif ($activeTab === 'performance'): ?>
<!-- ─────────────────────────── PERFORMANCE ──────────────────────────────── -->

  <!-- Page Cache card -->
  <div class="card overflow-hidden mb-5">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <i class="ti ti-bolt text-speed text-lg"></i>
        <div>
          <h2 class="font-head font-semibold text-sm text-zinc-900 flex items-center gap-2">
            <?= e(t('perf.page_cache')) ?>
            <span class="text-[10px] font-semibold text-speed bg-speed-pale px-1.5 py-0.5 rounded">
              <?= e(t('perf.page_cache.tech')) ?>
            </span>
          </h2>
          <p class="text-[11px] text-zinc-400"><?= e(t('perf.page_cache.desc')) ?></p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <?php if ($hasCache): ?>
        <form method="POST" action="/cache/purge" class="inline">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <button type="submit"
                  class="text-xs font-semibold text-ink hover:bg-ink-pale px-2.5 py-1.5 rounded-md flex items-center gap-1">
            <i class="ti ti-refresh text-sm"></i> <?= e(t('perf.purge')) ?>
          </button>
        </form>
        <?php endif; ?>
        <form method="POST" action="/cache/toggle" class="inline">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <input type="hidden" name="action" value="<?= $hasCache ? 'disable' : 'enable' ?>">
          <button type="submit" class="cursor-pointer bg-transparent border-0 p-0">
            <?php if ($hasCache): ?>
            <span class="sw-on"><span></span></span>
            <?php else: ?>
            <span class="sw-off"><span></span></span>
            <?php endif; ?>
          </button>
        </form>
      </div>
    </div>

    <?php if (!$hasCache): ?>
    <div class="px-5 py-6 text-center">
      <p class="text-sm text-zinc-500 mb-3">
        FastCGI cache is currently <strong>disabled</strong> for this site.
      </p>
      <form method="POST" action="/cache/toggle">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="domain" value="<?= e($domain) ?>">
        <input type="hidden" name="action" value="enable">
        <?php if ($type === 'wordpress'): ?>
        <div class="bg-zinc-50 border border-zinc-200 rounded-lg px-4 py-3 mb-4 text-left text-sm max-w-sm mx-auto">
          <p class="font-semibold text-zinc-700 mb-2">WordPress helpers</p>
          <label class="flex items-center gap-2 text-zinc-600 mb-1.5">
            <input type="checkbox" name="install_nginx_helper" value="1"
                   class="rounded border-zinc-300">
            Install Nginx Helper (auto-purge on publish)
          </label>
          <label class="flex items-center gap-2 text-zinc-600">
            <input type="checkbox" name="install_redis_plugin" value="1"
                   class="rounded border-zinc-300">
            Install Redis Object Cache plugin
          </label>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-bolt text-sm"></i> <?= e(t('perf.enable')) ?>
        </button>
      </form>
    </div>
    <?php else: ?>
    <div class="px-5 py-4 bg-zinc-50/50 border-t border-zinc-100">
      <p class="text-xs text-zinc-400 flex items-center gap-1.5">
        <i class="ti ti-info-circle text-sm"></i>
        Per-site cache configuration (TTL, exclusions, cookies) — available in next update.
      </p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Object Cache (Redis) card -->
  <div class="card overflow-hidden mb-5">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <i class="ti ti-brand-redis text-speed text-lg"></i>
        <div>
          <h2 class="font-head font-semibold text-sm text-zinc-900 flex items-center gap-2">
            <?= e(t('perf.object_cache')) ?>
            <span class="text-[10px] font-semibold text-zinc-500 bg-zinc-100 px-1.5 py-0.5 rounded">
              <?= e(t('perf.object_cache.tech')) ?>
            </span>
          </h2>
          <p class="text-[11px] text-zinc-400"><?= e(t('perf.object_cache.desc')) ?></p>
        </div>
      </div>
    </div>
    <div class="px-5 py-4">
      <?php if (!empty($redisInfo['ok'])): ?>
        <p class="text-sm flex items-center gap-2 text-zinc-700">
          <i class="ti ti-circle-check text-emerald-500"></i>
          <?= e(t('perf.redis.ok', ['keys' => number_format($redisInfo['keys']), 'mem' => $redisInfo['memory']])) ?>
        </p>
      <?php else: ?>
        <p class="text-sm flex items-center gap-2 text-zinc-400">
          <i class="ti ti-plug-x text-zinc-300"></i>
          <?= e(t('perf.redis.fail')) ?>
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- OPcache card (compact) -->
  <div class="card px-5 py-4 mb-5 flex items-center justify-between">
    <div>
      <h2 class="font-head font-semibold text-sm text-zinc-900"><?= e(t('perf.opcache')) ?></h2>
      <?php if ($opcacheEnabled): ?>
        <p class="text-[11px] text-zinc-400 mt-0.5">
          <?= e(t('perf.opcache.hit', ['rate' => $opcHitRate, 'hits' => $opcHits])) ?>
          · <?= e(t('perf.opcache.mem', ['used' => $opcUsedMem, 'total' => $opcTotalMem])) ?>
        </p>
      <?php else: ?>
        <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('perf.opcache.disabled')) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Delivery card (server default, compact) -->
  <div class="bg-zinc-50 rounded-xl border border-zinc-200/70 px-5 py-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="font-head font-semibold text-sm text-zinc-700 flex items-center gap-2">
          <i class="ti ti-rocket text-zinc-400"></i>
          <?= e(t('perf.delivery')) ?>
          <span class="text-[10px] font-semibold text-zinc-500 bg-zinc-200/70 px-1.5 py-0.5 rounded">
            <?= e(t('perf.server_default')) ?>
          </span>
        </h2>
        <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('perf.delivery.desc')) ?></p>
      </div>
      <a href="/admin/tuning"
         class="text-xs font-semibold text-ink hover:underline flex items-center gap-1 whitespace-nowrap shrink-0">
        <?= e(t('perf.server_tuning')) ?> <i class="ti ti-arrow-right text-sm"></i>
      </a>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <span class="text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full flex items-center gap-1">
        <i class="ti ti-check text-xs"></i> Brotli
      </span>
      <span class="text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full flex items-center gap-1">
        <i class="ti ti-check text-xs"></i> Gzip
      </span>
      <span class="text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full flex items-center gap-1">
        <i class="ti ti-check text-xs"></i> HTTP/2
      </span>
      <span class="text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full flex items-center gap-1">
        <i class="ti ti-check text-xs"></i> Browser cache headers
      </span>
    </div>
  </div>

<?php elseif ($activeTab === 'settings'): ?>
<!-- ─────────────────────────── SETTINGS ─────────────────────────────────── -->

  <div class="space-y-5 max-w-2xl">

    <?php if ($type !== 'static' && $type !== 'proxy'): ?>
    <!-- PHP version -->
    <div class="card p-5">
      <h2 class="font-head font-semibold text-sm text-zinc-900 mb-1"><?= e(t('site.set.php_title')) ?></h2>
      <p class="text-[11px] text-zinc-400 mb-4"><?= e(t('site.set.php_hint')) ?></p>
      <form method="POST" action="/sites/<?= e($domain) ?>/php" class="flex items-end gap-3">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div class="flex-1">
          <label class="lbl">PHP version</label>
          <select name="php_version" class="inp">
            <?php foreach (['8.1', '8.2', '8.3'] as $v): ?>
            <option value="<?= e($v) ?>" <?= $phpVer === $v ? 'selected' : '' ?>>PHP <?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><?= e(t('site.set.php_apply')) ?></button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Nginx config -->
    <div class="card overflow-hidden">
      <div class="card-head">
        <div>
          <h2 class="card-title"><i class="ti ti-code text-zinc-400"></i> <?= e(t('site.set.nginx_title')) ?></h2>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.set.nginx_hint')) ?></p>
        </div>
        <a href="/sites/<?= e($domain) ?>/nginx" class="btn btn-sm btn-secondary">
          <i class="ti ti-edit text-sm"></i> <?= e(t('site.set.nginx_edit')) ?>
        </a>
      </div>
      <?php if ($nginxConf): ?>
      <div class="border-t border-zinc-100 px-5 py-3 bg-zinc-50">
        <pre class="text-[10px] mono text-zinc-500 overflow-x-auto max-h-28 leading-relaxed"><?= e(substr($nginxConf, 0, 500)) ?>…</pre>
      </div>
      <?php endif; ?>
    </div>

    <!-- Danger zone -->
    <?php $siteUser = (string)($site['site_user'] ?? ''); $webRoot = $siteUser !== '' ? "/home/{$siteUser}/htdocs/{$domain}" : ''; ?>
    <div class="card border-red-200 overflow-hidden" x-data="{ open:false, typed:'' }">
      <div class="card-head bg-red-50/50">
        <h2 class="font-head font-semibold text-sm text-red-700"><?= e(t('site.set.danger_zone')) ?></h2>
      </div>
      <div class="p-5">
        <p class="text-sm text-zinc-600 mb-1 font-medium"><?= e(t('site.set.delete_site')) ?></p>
        <p class="text-xs text-zinc-400 mb-4"><?= e(t('site.set.delete_hint')) ?></p>
        <button type="button" @click="open=true; typed=''" class="btn btn-danger">
          <i class="ti ti-trash text-sm"></i>
          <?= e(t('site.set.delete_btn', ['domain' => $domain])) ?>
        </button>
      </div>

      <!-- Modal: permanent delete (type-the-domain confirm) -->
      <div x-show="open" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-zinc-900/40" @click="open=false"></div>
        <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl"
             @keydown.escape.window="open=false">
          <div class="card-head flex items-center justify-between bg-red-50/50">
            <h3 class="card-title text-red-700"><i class="ti ti-alert-triangle"></i> <?= e(t('site.set.delete_modal_title', ['domain' => $domain])) ?></h3>
            <button type="button" @click="open=false" class="text-zinc-400 hover:text-zinc-700"><i class="ti ti-x"></i></button>
          </div>
          <div class="p-5 space-y-3">
            <p class="text-sm text-zinc-600"><?= e(t('site.set.delete_modal_removes')) ?></p>
            <ul class="text-xs text-zinc-600 space-y-1 mono">
              <li>• <?= e(t('site.set.delete_li_vhost')) ?></li>
              <li>• <?= e(t('site.set.delete_li_pool')) ?></li>
              <li>• <?= e(t('site.set.delete_li_user', ['user' => $siteUser])) ?></li>
              <?php if ($webRoot !== ''): ?><li>• <?= e(t('site.set.delete_li_webroot', ['path' => $webRoot])) ?></li><?php endif; ?>
              <?php if ($siteUser !== ''): ?><li>• <?= e(t('site.set.delete_li_home', ['path' => "/home/{$siteUser}"])) ?></li><?php endif; ?>
            </ul>
            <label class="lbl"><?= e(t('site.set.delete_modal_type_to_confirm', ['domain' => $domain])) ?></label>
            <input type="text" x-model="typed" class="inp w-full" autocomplete="off" spellcheck="false" placeholder="<?= e($domain) ?>">
            <form method="POST" action="/sites/<?= e($domain) ?>/delete" class="flex justify-end gap-2 pt-1">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <button type="button" @click="open=false" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              <button type="submit" class="btn btn-danger"
                      :disabled="typed !== '<?= e($domain) ?>'"
                      :class="{ 'opacity-50 cursor-not-allowed': typed !== '<?= e($domain) ?>' }">
                <i class="ti ti-trash text-sm"></i> <?= e(t('site.set.delete_modal_confirm_btn')) ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

  </div>

<?php elseif ($activeTab === 'ssl'): ?>
<!-- ──────────────── SSL / TLS ──────────────── -->
  <div class="max-w-2xl" x-data="{ modal: null, submitting: false, domains: ['<?= e($domain) ?>'] }">

    <div class="card overflow-hidden">
      <div class="card-head">
        <div>
          <h2 class="card-title"><i class="ti <?= $hasTrustedSsl ? 'ti-lock-check text-emerald-500' : 'ti-lock-open text-amber-500' ?>"></i> <?= e(t('site.ssl.title')) ?></h2>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.ssl.subtitle')) ?></p>
        </div>
        <span class="badge <?= $hasTrustedSsl ? 'badge-ok' : 'badge-warn' ?>"><?= e($sslTypeLabel) ?></span>
      </div>

      <div class="p-5">
        <?php if (!$hasTrustedSsl): ?>
        <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3 mb-5">
          <i class="ti ti-alert-triangle text-amber-500 mt-0.5 shrink-0"></i>
          <div>
            <?php if ($sslReason !== null): ?>
            <p class="text-xs font-semibold text-amber-900 mb-0.5"><?= e(t('site.ssl.not_secure_because', ['reason' => $sslReason])) ?></p>
            <?php endif; ?>
            <p class="text-xs text-amber-800 leading-relaxed"><?= e(t('site.ssl.warn_untrusted')) ?></p>
          </div>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-3 gap-4">
          <div>
            <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1"><?= e(t('site.ssl.f.type')) ?></p>
            <p class="text-sm font-medium text-zinc-800"><?= e($sslTypeLabel) ?></p>
            <?php if (!empty($ssl['issuer'])): ?>
            <p class="text-[11px] text-zinc-400 mt-0.5 truncate" title="<?= e($ssl['issuer']) ?>"><?= e(t('site.ssl.f.issuer')) ?>: <?= e($ssl['issuer']) ?></p>
            <?php endif; ?>
          </div>
          <div>
            <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1"><?= e(t('site.ssl.f.domains')) ?></p>
            <p class="text-sm mono text-zinc-800 leading-relaxed">
              <?php foreach ((!empty($ssl['domains']) ? $ssl['domains'] : [$domain]) as $i => $cd): ?><?= $i ? '<br>' : '' ?><?= e($cd) ?><?php endforeach; ?>
            </p>
          </div>
          <div>
            <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1"><?= e(t('site.ssl.f.valid_until')) ?></p>
            <p class="text-sm font-medium text-zinc-800">
              <?php if (!empty($ssl['expiry'])): ?><?= e($ssl['expiry']) ?> <span class="text-zinc-400">(<?= e(t('site.ssl.days_left', ['n' => $ssl['daysLeft']])) ?>)</span><?php else: ?>—<?php endif; ?>
            </p>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2.5 px-5 py-3.5 border-t border-zinc-100 bg-zinc-50/60">
        <?php if ($sslState === 'letsencrypt'): ?>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/renew" @submit="submitting = true">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <button type="submit" class="btn btn-primary" :disabled="submitting">
            <i class="ti ti-refresh text-sm" x-show="!submitting"></i>
            <i class="ti ti-loader-2 text-sm animate-spin" x-show="submitting" x-cloak></i>
            <span x-show="!submitting"><?= e(t('site.ssl.renew')) ?></span>
            <span x-show="submitting" x-cloak><?= e(t('site.ssl.processing')) ?></span>
          </button>
        </form>
        <?php else: ?>
        <button type="button" @click="modal='le'" class="btn btn-primary"><i class="ti ti-lock-check text-sm"></i> <?= e(t('site.ssl.install_le')) ?></button>
        <?php endif; ?>
        <button type="button" @click="modal='import'" class="btn btn-secondary"><i class="ti ti-certificate text-sm"></i> <?= e(t('site.ssl.import')) ?></button>
      </div>
    </div>

    <!-- Modal: Install Let's Encrypt -->
    <div x-show="modal==='le'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl" @keydown.escape.window="modal=null">
        <div class="card-head">
          <h3 class="card-title"><i class="ti ti-rosette-discount-check text-speed"></i> <?= e(t('site.ssl.le_modal_title')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/install" @submit="submitting = true">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div class="p-5">
            <div class="flex items-start gap-2.5 bg-speed-pale border border-speed/20 rounded-lg px-4 py-3 mb-4">
              <i class="ti ti-info-circle text-speed mt-0.5 shrink-0"></i>
              <p class="text-xs text-zinc-600 leading-relaxed"><?= e(t('site.ssl.le_prereq', ['domain' => $domain])) ?></p>
            </div>

            <label class="lbl"><?= e(t('site.ssl.f.domains')) ?></label>
            <template x-for="(d, i) in domains" :key="i">
              <div class="flex items-center gap-2 mb-2">
                <input type="text" name="domains[]" x-model="domains[i]" required
                       class="inp mono text-sm flex-1" placeholder="example.com" autocomplete="off" spellcheck="false">
                <button type="button" @click="domains.splice(i, 1)" x-show="domains.length > 1"
                        class="shrink-0 w-9 h-9 grid place-items-center rounded-lg border border-zinc-200 text-zinc-400 hover:text-red-500 hover:border-red-200 transition"
                        title="<?= e(t('common.remove')) ?>"><i class="ti ti-x text-sm"></i></button>
              </div>
            </template>
            <button type="button" @click="domains.push('')"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-speed hover:underline">
              <i class="ti ti-plus text-xs"></i> <?= e(t('site.ssl.add_domain')) ?>
            </button>
            <p class="hint mt-1"><?= e(t('site.ssl.domains_hint')) ?></p>

            <label class="lbl mt-4"><?= e(t('site.ssl.email')) ?> <span class="text-zinc-400 font-normal">(<?= e(t('common.optional')) ?>)</span></label>
            <input type="email" name="email" class="inp" placeholder="you@example.com">
            <p class="hint"><?= e(t('site.ssl.email_hint')) ?></p>
          </div>
          <div class="flex items-center justify-end gap-2 px-5 py-3.5 border-t border-zinc-100">
            <button type="button" @click="modal=null" class="btn btn-ghost" :disabled="submitting"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <i class="ti ti-lock-check text-sm" x-show="!submitting"></i>
              <i class="ti ti-loader-2 text-sm animate-spin" x-show="submitting" x-cloak></i>
              <span x-show="!submitting"><?= e(t('site.ssl.install_btn')) ?></span>
              <span x-show="submitting" x-cloak><?= e(t('site.ssl.processing')) ?></span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Import certificate -->
    <div x-show="modal==='import'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-lg card shadow-2xl max-h-[90vh] overflow-y-auto" @keydown.escape.window="modal=null">
        <div class="card-head">
          <h3 class="card-title"><i class="ti ti-certificate text-speed"></i> <?= e(t('site.ssl.import_modal_title')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/import" @submit="submitting = true">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div class="p-5 space-y-4">
            <p class="text-[11px] text-zinc-400"><?= e(t('site.ssl.import_hint')) ?></p>
            <div>
              <label class="lbl"><?= e(t('site.ssl.cert')) ?></label>
              <textarea name="cert" rows="4" class="inp mono text-xs leading-relaxed" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
            </div>
            <div>
              <label class="lbl"><?= e(t('site.ssl.key')) ?></label>
              <textarea name="key" rows="4" class="inp mono text-xs leading-relaxed" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
              <p class="hint"><?= e(t('site.ssl.key_hint')) ?></p>
            </div>
            <div>
              <label class="lbl"><?= e(t('site.ssl.chain')) ?> <span class="text-zinc-400 font-normal">(<?= e(t('common.optional')) ?>)</span></label>
              <textarea name="chain" rows="3" class="inp mono text-xs leading-relaxed" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
              <p class="hint"><?= e(t('site.ssl.chain_hint')) ?></p>
            </div>
          </div>
          <div class="flex items-center justify-end gap-2 px-5 py-3.5 border-t border-zinc-100">
            <button type="button" @click="modal=null" class="btn btn-ghost" :disabled="submitting"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <i class="ti ti-upload text-sm" x-show="!submitting"></i>
              <i class="ti ti-loader-2 text-sm animate-spin" x-show="submitting" x-cloak></i>
              <span x-show="!submitting"><?= e(t('site.ssl.import_btn')) ?></span>
              <span x-show="submitting" x-cloak><?= e(t('site.ssl.processing')) ?></span>
            </button>
          </div>
        </form>
      </div>
    </div>

  </div>

<?php else: ?>
<!-- ──────────────── DATABASE / SECURITY / CRON / FILES ────────────── -->

  <div class="card px-8 py-16 text-center max-w-lg mx-auto">
    <?php
    $tabIcons = ['ssl' => 'ti-lock', 'database' => 'ti-database', 'security' => 'ti-shield-lock',
                 'cron' => 'ti-clock-hour-4', 'files' => 'ti-folder'];
    $tabIcon = $tabIcons[$activeTab] ?? 'ti-tool';
    ?>
    <i class="ti <?= $tabIcon ?> text-5xl text-zinc-200 block mb-3"></i>
    <p class="text-sm font-semibold text-zinc-700 mb-1"><?= e($tabs[$activeTab]['label'] ?? '') ?></p>
    <p class="text-xs text-zinc-400"><?= e(t('site.tab.soon')) ?></p>
  </div>

<?php endif; ?>

</main>
