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
$basicAuthInfo = $basicAuthInfo ?? [];
$baEnabled = ($basicAuthInfo['enabled'] ?? '0') === '1';
$baScope = in_array(($basicAuthInfo['scope'] ?? ''), ['wp-login', 'custom', 'site'], true)
    ? (string) $basicAuthInfo['scope']
    : 'wp-login';
$baError = (string) ($basicAuthInfo['error'] ?? '');
$baForceHttps = ($basicAuthInfo['force_https'] ?? '0') === '1';
$baHasPassword = ($basicAuthInfo['htpasswd_exists'] ?? '0') === '1';
$cloudflareInfo = $cloudflareInfo ?? [];
$cfEnabled = ($cloudflareInfo['enabled'] ?? '0') === '1';
$cfSource = (string) ($cloudflareInfo['source'] ?? 'seed');
$cfAgeDays = max(0, intdiv((int) ($cloudflareInfo['age_seconds'] ?? 0), 86400));
$cfRanges = (int) ($cloudflareInfo['ranges_v4'] ?? 0) . ' IPv4 · '
    . (int) ($cloudflareInfo['ranges_v6'] ?? 0) . ' IPv6';
$cfError = (string) ($cloudflareInfo['error'] ?? '');
$cfStale = ($cloudflareInfo['warning'] ?? '') === 'stale';
$cloudflareOnlyInfo = $cloudflareOnlyInfo ?? [];
$cfoEnabled = ($cloudflareOnlyInfo['enabled'] ?? '0') === '1';
$cfoError = (string) ($cloudflareOnlyInfo['error'] ?? '');

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

// (OPcache data is now in $opcacheInfo — built by SiteController::buildOpcacheInfo)
?>

<!-- ===== FULL-BLEED SITE HEADER BAND ===== -->
<div class="bg-white border-b border-zinc-200/80 px-6 pt-4">
  <div class="max-w-[1100px] mx-auto">

    <!-- identity row -->
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <a href="/sites" class="text-zinc-400 hover:text-ink flex items-center text-sm" title="<?= e(t('site.back')) ?>">
          <?= icon('arrow-left') ?>
        </a>
        <span class="w-10 h-10 rounded-lg <?= $iconBg ?> flex items-center justify-center shrink-0">
          <?= icon($appIcon($type), $iconColor . ' text-xl') ?>
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
          <?= icon('external-link', 'text-sm') ?> <?= e(t('site.visit')) ?>
        </a>
      </div>
    </div>

    <!-- tab nav -->
    <div class="flex items-center overflow-x-auto -mb-px">
      <?php foreach ($tabs as $key => $tab): ?>
      <a href="/sites/<?= e($domain) ?>?tab=<?= $key ?>"
         class="tab <?= $activeTab === $key ? 'active' : '' ?>">
        <?= icon($tab['icon'], 'text-[15px]') ?>
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
        <?= icon($hasTrustedSsl ? 'lock-check' : 'lock', $hasTrustedSsl ? 'text-emerald-500' : 'text-zinc-400') ?>
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
        <?= icon('bolt', $hasCache ? 'text-speed' : 'text-zinc-300') ?>
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
        <?= icon('brand-php', 'text-ink') ?>
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
        <?= icon('folder', 'text-amber-500') ?>
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
            <?= e(t('site.activity.view_all')) ?> <?= icon('arrow-right', 'text-sm') ?>
          </a>
        </div>
        <?php if (empty($logs)): ?>
          <div class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('site.activity.empty')) ?></div>
        <?php else: ?>
          <div class="divide-y divide-zinc-50 font-mono text-[11px]">
            <?php foreach ($logs as $log): ?>
            <div class="px-5 py-2.5 flex items-center gap-3">
              <span class="text-zinc-300 shrink-0 w-24 truncate">
                <?= e(fmt_dt($log['created_at'], null, 'M d H:i')) ?>
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
          <?= icon('external-link', 'text-[18px] text-zinc-400') ?>
          <?= e(t('site.qa.visit')) ?>
        </a>

        <?php if ($hasCache): ?>
        <form method="POST" action="/cache/purge">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <button type="submit"
                  class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-zinc-50 text-sm text-zinc-700 transition-colors text-left">
            <?= icon('bolt', 'text-[18px] text-speed') ?>
            <?= e(t('site.qa.clear_cache')) ?>
          </button>
        </form>
        <?php endif; ?>

        <form method="POST" action="/php/restart">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <button type="submit"
                  class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-zinc-50 text-sm text-zinc-700 transition-colors text-left">
            <?= icon('refresh', 'text-[18px] text-zinc-400') ?>
            <?= e(t('site.qa.restart_php')) ?>
          </button>
        </form>
      </div>
    </div>

  </div>

<?php elseif ($activeTab === 'performance'): ?>
<!-- ─────────────────────────── PERFORMANCE ──────────────────────────────── -->
<?php
  // Page Cache state (from CLI status; falls back to DB $hasCache if CLI unavailable)
  $pcStatus   = $pageCacheInfo['site_cache_status'] ?? 'unknown';
  $pcEngineOk = $pageCacheInfo['engine_ok'] ?? false;
  $pcActive   = $pcStatus === 'active';
  $pcUnsup    = $pcStatus === 'unsupported';
  if ($pcStatus === 'unknown' && empty($pageCacheInfo)) { $pcActive = $hasCache; }
  $wpHelper   = $pageCacheInfo['wp_helper_status'] ?? '';

  $currentTtl   = $cacheConfig['ttl'] ?? '1h';
  $staleOn      = ($cacheConfig['stale-revalidate'] ?? 'off') === 'on';
  $debugOn      = ($cacheConfig['debug-header'] ?? 'on') === 'on';
  $bypassQOn    = ($cacheConfig['bypass-query'] ?? 'off') === 'on';
  $excludeLines = implode("\n", array_filter(array_map('trim', explode(',', $cacheConfig['exclude-urls'] ?? ''))));
  // URL bypass baseline: always enforced server-side; shown as locked chips above the
  // editable exclude list (mirrors the cookie baseline below).
  $baselineExcludeUrls = cache_baseline_exclude_urls();
  // Bypass cookies: the baseline is always enforced server-side, so show it as locked
  // chips and let the user edit only their own additions (stored list minus baseline).
  $baselineCookies = cache_baseline_cookies();
  $storedCookies   = array_filter(array_map('trim', explode(',', $cacheConfig['bypass-cookies'] ?? '')));
  $extraCookies    = implode(', ', array_values(array_diff($storedCookies, $baselineCookies)));
  $ttlLabels    = ['5m'=>'5 minutes','10m'=>'10 minutes','15m'=>'15 minutes','30m'=>'30 minutes',
                   '1h'=>'1 hour','2h'=>'2 hours','6h'=>'6 hours','12h'=>'12 hours','1d'=>'1 day','7d'=>'7 days'];

  // Object Cache state
  $ocStatus  = $objectCacheInfo['site_cache_status'] ?? 'unknown';
  $ocSvcOk   = $objectCacheInfo['service_ok'] ?? false;
  $ocPlugin  = $objectCacheInfo['plugin_status'] ?? 'unknown';
  $ocDropin  = $objectCacheInfo['dropin_status'] ?? 'unknown';
  $ocPrefix  = $objectCacheInfo['prefix'] ?? '';
  $ocManaged = $objectCacheInfo['prefix_managed'] ?? false;
  $ocWpCli   = $objectCacheInfo['wp_cli_missing'] ?? false;
  $ocActive  = $ocStatus === 'active';
  $ocUnsup   = $ocStatus === 'unsupported';
  $ocSvcDown = $ocStatus === 'service_down';

  // OPcache state
  $opcEnabled = $opcacheInfo['enabled'] ?? false;
  $opcHitRate = $opcacheInfo['hit_rate'] ?? '—';
  $opcHits    = $opcacheInfo['hits'] ?? '—';
  $opcUsedMem = $opcacheInfo['memory_used'] ?? '—';
  $opcLimit   = $opcacheInfo['memory_limit'] ?? '—';

  // Protocol state — $protocolInfo is a flat map of feature => 'on'|'off'|...
  $protoLabels   = [
    'http2'                 => 'HTTP/2',
    'http3'                 => 'HTTP/3 (QUIC)',
    'brotli'                => 'Brotli',
    'gzip'                  => 'Gzip',
    'browser_cache_headers' => 'Browser cache headers',
  ];
?>

  <!-- Page Cache card (full width) -->
  <div class="card overflow-hidden mb-5">

    <!-- Card head -->
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <?= icon('bolt', ($pcActive ? 'text-speed' : 'text-zinc-300') . ' text-lg') ?>
        <div>
          <h2 class="card-title">
            <?= e(t('perf.page_cache')) ?>
            <span class="tag tag-info"><?= e(t('perf.page_cache.tech')) ?></span>
            <?php if ($pcUnsup): ?>
              <span class="badge badge-muted">Not supported</span>
            <?php elseif ($pcActive): ?>
              <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> Enabled</span>
            <?php else: ?>
              <span class="badge badge-warn">Disabled</span>
            <?php endif; ?>
          </h2>
          <p class="text-[11px] text-zinc-400"><?= e(t('perf.page_cache.desc')) ?></p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <?php if ($pcActive): ?>
        <form method="POST" action="/cache/purge" class="inline">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <button type="submit" class="btn btn-secondary btn-sm">
            <?= icon('refresh', 'text-sm') ?> <?= e(t('perf.purge')) ?>
          </button>
        </form>
        <span class="w-px h-4 bg-zinc-200 mx-1"></span>
        <form method="POST" action="/cache/toggle" class="inline flex items-center gap-2">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <input type="hidden" name="action" value="disable">
          <span class="text-[11px] font-medium text-zinc-500">Cache on</span>
          <button type="submit" class="flex items-center bg-transparent p-0 border-0 cursor-pointer" title="<?= e(t('perf.disable')) ?>">
            <span class="sw-on"><span></span></span>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($pcUnsup): ?>
    <!-- UNSUPPORTED STATE -->
    <div class="px-5 py-5">
      <div class="flex items-start gap-3">
        <?= icon('info-circle', 'text-zinc-400 text-lg flex-none mt-0.5') ?>
        <div>
          <p class="text-sm font-medium text-zinc-700 mb-0.5">Page cache is not supported for this site type.</p>
          <p class="text-[11px] text-zinc-400">FastCGI page cache applies to PHP sites only. Static and reverse-proxy sites are served directly by Nginx without a cache layer.</p>
        </div>
      </div>
    </div>

    <?php elseif (!$pcActive): ?>
    <!-- DISABLED STATE -->
    <div class="px-5 py-6">
      <?php if (!$pcEngineOk && !empty($pageCacheInfo)): ?>
      <div class="flex items-start gap-3 mb-5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3">
        <?= icon('alert-triangle', 'text-amber-500 text-sm shrink-0 mt-0.5') ?>
        <div>
          <p class="text-xs font-semibold text-amber-800 mb-0.5">FastCGI cache engine not configured</p>
          <p class="text-[11px] text-amber-700 leading-relaxed">The server-level cache zone is not active. Go to <a href="/admin" class="underline">Admin Area</a> to configure it first.</p>
        </div>
      </div>
      <?php endif; ?>
      <div class="flex items-start gap-3 mb-5">
        <span class="flex-none w-9 h-9 rounded-lg bg-speed-pale flex items-center justify-center shrink-0">
          <?= icon('bolt', 'text-speed text-lg') ?>
        </span>
        <div>
          <p class="text-sm font-semibold text-zinc-800 mb-0.5">Start caching this site</p>
          <p class="text-[11px] text-zinc-500 leading-relaxed">Serve ready HTML, skip PHP — the single biggest performance win for most sites.</p>
        </div>
      </div>
      <div class="space-y-2.5 mb-5">
        <div class="flex items-center gap-2.5">
          <?= icon('check', 'text-emerald-500 text-sm flex-none') ?>
          <span class="text-sm text-zinc-600"><?= e(t('perf.setup.checklist.1')) ?></span>
        </div>
        <div class="flex items-center gap-2.5">
          <?= icon('check', 'text-emerald-500 text-sm flex-none') ?>
          <span class="text-sm text-zinc-600"><?= e(t('perf.setup.checklist.2')) ?></span>
        </div>
        <div class="flex items-center gap-2.5">
          <?= icon('check', 'text-emerald-500 text-sm flex-none') ?>
          <span class="text-sm text-zinc-600"><?= e(t('perf.setup.checklist.3')) ?></span>
        </div>
      </div>
      <form method="POST" action="/cache/toggle" data-op-stream<?php if ($type === 'php'): ?> x-data="{ confirm: false, submitting: false }"<?php endif; ?>>
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="domain" value="<?= e($domain) ?>">
        <input type="hidden" name="action" value="enable">
        <input type="hidden" name="stream" value="1">
        <div data-op-fields>
          <?php if ($type === 'wordpress'): ?>
          <div class="mb-5">
            <p class="eyebrow mb-2.5">WordPress optimization</p>
            <label class="flex items-start gap-2.5 p-3 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-50 transition-colors">
              <input type="checkbox" name="install_nginx_helper" value="1" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
              <span>
                <span class="block text-xs font-semibold text-zinc-800"><?= e(t('perf.setup.install_helper')) ?></span>
                <span class="block text-[11px] text-zinc-400 mt-0.5">Auto-purge the page cache on publish &amp; updates. Configured automatically.</span>
              </span>
            </label>
          </div>
          <?php endif; ?>
          <div class="pt-4 border-t border-zinc-100 flex items-center justify-between">
            <p class="text-[11px] text-zinc-400">Safe defaults applied automatically.</p>
            <button type="<?= $type === 'php' ? 'button' : 'submit' ?>"<?php if ($type === 'php'): ?> @click="confirm = true"<?php endif; ?> class="btn btn-primary" <?= (!$pcEngineOk && !empty($pageCacheInfo)) ? 'disabled' : '' ?>>
              <?= icon('bolt', 'text-sm') ?> <?= e(t('perf.enable')) ?>
            </button>
          </div>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
        <?php if ($type === 'php'): ?>
        <!-- Modal: confirm caching a dynamic PHP app (#3 — stale CSRF/nonce risk); styled like the other panel modals -->
        <div x-show="confirm" x-cloak class="fixed inset-0 z-50">
          <div class="absolute inset-0 bg-zinc-900/40"></div>
          <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl text-left">
            <div class="card-head flex items-center justify-between gap-3">
              <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
                  <?= icon('alert-triangle', 'text-amber-500') ?>
                </span>
                <h3 class="card-title"><?= e(t('perf.cache.dynamic_modal_title')) ?></h3>
              </div>
              <button type="button" @click="confirm=false" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
            </div>
            <div class="p-5 space-y-4">
              <p class="text-sm text-zinc-600 leading-relaxed"><?= e(t('perf.cache.dynamic_modal_body')) ?></p>
              <div class="flex justify-end gap-2 pt-1">
                <button type="button" @click="confirm=false" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
                <button type="submit" @click="confirm = false" class="btn btn-primary"><?= icon('bolt', 'text-sm') ?> <?= e(t('perf.cache.dynamic_modal_confirm')) ?></button>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <?php else: ?>
    <!-- ENABLED STATE: metric tiles + single-column config form -->
    <?php if ($type === 'wordpress' && $wpHelper === 'not_installed'): ?>
    <div class="flex items-center gap-2 bg-sky-50 border-b border-sky-100 px-5 py-2.5">
      <?= icon('info-circle', 'text-sky-500 text-sm shrink-0') ?>
      <p class="text-[11px] text-sky-800">
        Install <strong>Nginx Helper</strong> in WordPress to auto-purge the page cache on publish and updates.
      </p>
    </div>
    <?php endif; ?>

    <!-- Metric tiles -->
    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-zinc-100 border-b border-zinc-100">
      <div class="px-5 py-3.5 bg-emerald-50/40">
        <p class="eyebrow mb-1.5">Status</p>
        <p class="text-sm font-semibold text-emerald-700 flex items-center gap-1.5">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-none"></span>
          <?= e(t('perf.cache.status.active')) ?>
        </p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5"><?= e(t('perf.cache.ttl')) ?></p>
        <p class="text-sm font-semibold text-zinc-800"><?= e($ttlLabels[$currentTtl] ?? $currentTtl) ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5"><?= e(t('perf.cache.last_purge')) ?></p>
        <p class="text-sm font-semibold text-zinc-800">
          <?= $lastPurge !== null ? e($lastPurge) : e(t('perf.cache.never_purged')) ?>
        </p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5"><?= e(t('perf.cache.zone')) ?></p>
        <p class="text-sm font-semibold text-zinc-800 mono"><?= e($cacheZoneSize ?? '—') ?></p>
      </div>
    </div>

    <!-- Cache tools: check a URL + purge specific URLs -->
    <div class="px-5 py-4 border-b border-zinc-100 space-y-4" data-cache-tools data-domain="<?= e($domain) ?>">
      <p class="eyebrow">Cache tools</p>

      <div>
        <label class="lbl">Check a URL</label>
        <div class="flex items-center gap-2">
          <input type="url" data-check-url class="inp flex-1 font-mono text-xs"
                 value="https://<?= e($domain) ?>/" placeholder="https://<?= e($domain) ?>/path">
          <button type="button" data-check-btn class="btn btn-secondary btn-sm shrink-0">
            <?= icon('search', 'text-sm') ?> Check
          </button>
        </div>
        <p data-check-result class="hidden mt-2 text-xs"></p>
      </div>

      <form method="POST" action="/cache/purge-urls">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="domain" value="<?= e($domain) ?>">
        <label class="lbl">Purge specific URLs</label>
        <textarea name="urls" rows="3" class="inp w-full font-mono text-xs"
                  placeholder="https://<?= e($domain) ?>/about&#10;https://<?= e($domain) ?>/blog/post"></textarea>
        <div class="flex items-center justify-between mt-1">
          <p class="hint">One URL per line.</p>
          <button type="submit" class="btn btn-secondary btn-sm">
            <?= icon('trash', 'text-sm') ?> Purge URLs
          </button>
        </div>
      </form>
    </div>

    <!-- Config form: single-column layout -->
    <form method="POST" action="/cache/config" id="cache-config-form">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="domain" value="<?= e($domain) ?>">

      <div class="px-5 py-4 space-y-4">
        <p class="eyebrow"><?= e(t('perf.col.cache_rules')) ?></p>

        <!-- TTL -->
        <div>
          <label class="lbl"><?= e(t('perf.cache.ttl')) ?></label>
          <select name="ttl" class="inp w-full">
            <?php foreach ($ttlLabels as $val => $label): ?>
            <option value="<?= e($val) ?>" <?= $currentTtl === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint"><?= e(t('perf.cache.ttl.desc')) ?></p>
        </div>

        <!-- Serve stale while revalidating -->
        <div class="flex items-center justify-between gap-4" data-seg>
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('perf.cache.stale')) ?></p>
            <p class="hint"><?= e(t('perf.cache.stale.desc')) ?></p>
          </div>
          <div class="inline-flex flex-none rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
            <button type="button" data-seg-opt="1" class="px-3 py-1 text-xs font-semibold rounded-md transition">On</button>
            <button type="button" data-seg-opt="0" class="px-3 py-1 text-xs font-semibold rounded-md transition">Off</button>
          </div>
          <input type="hidden" name="stale_revalidate" value="<?= $staleOn ? '1' : '0' ?>" data-seg-input>
        </div>

        <!-- Add X-FastCGI-Cache header -->
        <div class="flex items-center justify-between gap-4" data-seg>
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('perf.cache.debug')) ?></p>
            <p class="hint"><?= e(t('perf.cache.debug.desc')) ?></p>
          </div>
          <div class="inline-flex flex-none rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
            <button type="button" data-seg-opt="1" class="px-3 py-1 text-xs font-semibold rounded-md transition">On</button>
            <button type="button" data-seg-opt="0" class="px-3 py-1 text-xs font-semibold rounded-md transition">Off</button>
          </div>
          <input type="hidden" name="debug_header" value="<?= $debugOn ? '1' : '0' ?>" data-seg-input>
        </div>

        <!-- Bypass rules -->
        <p class="eyebrow pt-4 border-t border-zinc-100"><?= e(t('perf.col.bypass_rules')) ?></p>

        <!-- Exclude URLs -->
        <div>
          <label class="lbl"><?= e(t('perf.cache.exclude')) ?></label>
          <!-- Non-removable security baseline: shown locked, enforced server-side -->
          <div class="flex flex-wrap gap-1.5 mb-2">
            <?php foreach ($baselineExcludeUrls as $bu): ?>
              <span class="tag tag-muted" title="<?= e(t('perf.cache.exclude.baseline_hint')) ?>">
                <?= icon('lock') ?><?= e($bu) ?>
              </span>
            <?php endforeach; ?>
          </div>
          <textarea name="exclude_urls" rows="5" class="inp w-full font-mono text-xs"><?= e($excludeLines) ?></textarea>
          <p class="hint"><?= e(t('perf.cache.exclude.desc')) ?></p>
        </div>

        <!-- Bypass cookies -->
        <div>
          <label class="lbl"><?= e(t('perf.cache.cookies')) ?></label>
          <!-- Non-removable security baseline: shown locked, enforced server-side -->
          <div class="flex flex-wrap gap-1.5 mb-2">
            <?php foreach ($baselineCookies as $bc): ?>
              <span class="tag tag-muted" title="<?= e(t('perf.cache.cookies.baseline_hint')) ?>">
                <?= icon('lock') ?><?= e($bc) ?>
              </span>
            <?php endforeach; ?>
          </div>
          <input type="text" name="bypass_cookies"
                 value="<?= e($extraCookies) ?>"
                 class="inp w-full font-mono text-xs"
                 placeholder="my_session_cookie, custom_login">
          <p class="hint"><?= e(t('perf.cache.cookies.desc')) ?></p>
        </div>

        <!-- Bypass query strings -->
        <div class="flex items-center justify-between gap-4" data-seg>
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('perf.cache.bypass_q')) ?></p>
            <p class="hint"><?= e(t('perf.cache.bypass_q.desc')) ?></p>
          </div>
          <div class="inline-flex flex-none rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
            <button type="button" data-seg-opt="1" class="px-3 py-1 text-xs font-semibold rounded-md transition">On</button>
            <button type="button" data-seg-opt="0" class="px-3 py-1 text-xs font-semibold rounded-md transition">Off</button>
          </div>
          <input type="hidden" name="bypass_query" value="<?= $bypassQOn ? '1' : '0' ?>" data-seg-input>
        </div>

        <!-- Always-on cache policy (display only; enforced server-side) -->
        <div class="rounded-lg border border-zinc-200 bg-zinc-50/60 px-3 py-2.5 space-y-1.5">
          <p class="hint flex items-start gap-1.5"><?= icon('info-circle', 'mt-0.5 flex-none') ?><span><?= e(t('perf.cache.policy.always_bypass')) ?></span></p>
          <p class="hint flex items-start gap-1.5"><?= icon('info-circle', 'mt-0.5 flex-none') ?><span><?= e(t('perf.cache.policy.status_codes')) ?></span></p>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-5 py-3.5 bg-zinc-50/60 border-t border-zinc-100 flex items-center justify-end">
        <button type="submit" class="btn btn-primary"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('perf.cache.save')) ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- Segmented On/Off selector wiring (no Alpine dependency) -->
  <script>
  document.querySelectorAll('[data-seg]').forEach(function (row) {
    var input = row.querySelector('[data-seg-input]');
    var opts  = row.querySelectorAll('[data-seg-opt]');
    if (!input || !opts.length) return;
    function render() {
      opts.forEach(function (b) {
        var sel  = b.getAttribute('data-seg-opt') === String(input.value);
        var isOn = b.getAttribute('data-seg-opt') === '1';
        // On = green, Off = grey; only the selected side is filled. Inactive side
        // gets a clear hover affordance + pointer so it reads as a real button.
        b.classList.add('cursor-pointer');
        b.classList.remove('bg-emerald-500', 'bg-zinc-500', 'bg-zinc-600', 'text-white', 'shadow-sm', 'text-zinc-500', 'hover:bg-white', 'hover:text-zinc-900');
        if (sel) {
          b.classList.add('text-white', 'shadow-sm', isOn ? 'bg-emerald-500' : 'bg-zinc-600');
        } else {
          b.classList.add('text-zinc-500', 'hover:bg-white', 'hover:text-zinc-900');
        }
      });
    }
    opts.forEach(function (b) {
      b.addEventListener('click', function () { input.value = b.getAttribute('data-seg-opt'); render(); });
    });
    render();
  });
  </script>

  <!-- Cache tools: URL check (async, non-blocking) -->
  <script>
  (function () {
    var box = document.querySelector('[data-cache-tools]');
    if (!box) return;
    var btn   = box.querySelector('[data-check-btn]');
    var urlEl = box.querySelector('[data-check-url]');
    var out   = box.querySelector('[data-check-result]');
    if (!btn || !urlEl || !out) return;
    var colors = { HIT: 'text-emerald-600', BYPASS: 'text-zinc-500',
                   MISS: 'text-amber-600', EXPIRED: 'text-amber-600',
                   STALE: 'text-amber-600', UPDATING: 'text-amber-600' };
    btn.addEventListener('click', function () {
      var domain = box.getAttribute('data-domain');
      var url = (urlEl.value || '').trim();
      if (!url) return;
      out.className = 'mt-2 text-xs text-zinc-500';
      out.textContent = 'Checking…';
      fetch('/api/cache/check?domain=' + encodeURIComponent(domain) + '&url=' + encodeURIComponent(url),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok) { out.className = 'mt-2 text-xs text-rose-600'; out.textContent = 'Could not check this URL.'; return; }
          var c = colors[d.status] || 'text-zinc-500';
          var hint = d.status === 'unknown' ? '  ·  turn on “Add X-FastCGI-Cache header” to read HIT/MISS' : '';
          out.className = 'mt-2 text-xs font-semibold ' + c;
          out.textContent = d.status + ' · TTFB ' + d.ttfb_ms + ' ms' + hint;
        })
        .catch(function () { out.className = 'mt-2 text-xs text-rose-600'; out.textContent = 'Request failed.'; });
    });
  })();

  function cacheZone(domain, hasCache) {
    return {
      zone: 'shared', zoneName: '', keys: '-', maxSize: '-',
      load() {
        if (!hasCache) return;
        fetch('/api/cache/zone-status?domain=' + encodeURIComponent(domain),
              { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(r => r.json())
          .then(d => {
            if (!d || !d.ok) return;
            this.zone = d.zone || 'shared';
            this.zoneName = d.zone_name || '';
            this.keys = d.keys || '-';
            this.maxSize = d.max_size || '-';
          })
          .catch(() => {});
      }
    };
  }
  </script>

  <!-- Object Cache (Redis) card -->
  <div class="card overflow-hidden mb-5">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <?= icon('database', ($ocActive ? 'text-speed' : 'text-zinc-300') . ' text-lg') ?>
        <div>
          <h2 class="card-title">
            <?= e(t('perf.object_cache')) ?>
            <span class="tag tag-info"><?= e(t('perf.object_cache.tech')) ?></span>
            <?php if ($ocUnsup): ?>
              <span class="badge badge-muted">Not supported</span>
            <?php elseif ($ocSvcDown): ?>
              <span class="badge badge-warn">Service down</span>
            <?php elseif ($ocActive && !$ocManaged): ?>
              <span class="badge badge-warn">Enabled · Manual</span>
            <?php elseif ($ocActive): ?>
              <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> Enabled</span>
            <?php else: ?>
              <span class="badge badge-warn">Disabled</span>
            <?php endif; ?>
          </h2>
          <p class="text-[11px] text-zinc-400"><?= e(t('perf.object_cache.desc')) ?></p>
        </div>
      </div>
      <!-- Right: Flush + on/off toggle (only when AidiPanel-managed; Redis health now lives in the metrics row) -->
      <div class="flex items-center gap-2">
        <?php if ($ocActive && $ocManaged): ?>
        <form method="POST" action="/cache/object" class="inline">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <input type="hidden" name="action" value="flush">
          <button type="submit" class="btn btn-secondary btn-sm" title="Clear this site's object cache">
            <?= icon('refresh', 'text-sm') ?> Flush
          </button>
        </form>
        <span class="w-px h-4 bg-zinc-200 mx-1"></span>
        <form method="POST" action="/cache/object" class="inline flex items-center gap-2">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <input type="hidden" name="action" value="disable">
          <span class="text-[11px] font-medium text-zinc-500">Cache on</span>
          <button type="submit" class="flex items-center bg-transparent p-0 border-0 cursor-pointer" title="Disable object cache for this site">
            <span class="sw-on"><span></span></span>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($objectCacheInfo)): ?>
    <div class="px-5 py-4">
      <p class="text-[11px] text-zinc-400">Status unavailable — performance CLI may not be deployed yet.</p>
    </div>

    <?php elseif ($ocUnsup): ?>
    <div class="px-5 py-5">
      <div class="flex items-start gap-3">
        <?= icon('info-circle', 'text-zinc-400 text-lg flex-none mt-0.5') ?>
        <div>
          <p class="text-sm font-medium text-zinc-700 mb-0.5">WordPress only</p>
          <p class="text-[11px] text-zinc-400 leading-relaxed">Redis object cache integration is available for WordPress sites. WP stores queries, transients, and object data in memory for faster page generation.</p>
        </div>
      </div>
    </div>

    <?php elseif ($ocSvcDown): ?>
    <div class="px-5 py-5">
      <div class="flex items-start gap-3 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3.5">
        <?= icon('alert-triangle', 'text-amber-500 text-sm shrink-0 mt-0.5') ?>
        <div>
          <p class="text-xs font-semibold text-amber-800 mb-0.5">Redis service is not running</p>
          <p class="text-[11px] text-amber-700 leading-relaxed">
            The Redis server is not responding. Go to
            <a href="/services" class="underline font-medium">Services</a>
            to check and restart it.
          </p>
        </div>
      </div>
    </div>

    <?php elseif ($ocActive): ?>
    <!-- ENABLED STATE: status + live metric tiles (actions live in the header) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-zinc-100 border-b border-zinc-100">
      <div class="px-5 py-3.5 <?= $ocSvcOk ? 'bg-emerald-50/40' : '' ?>">
        <p class="eyebrow mb-1.5">Redis</p>
        <p class="text-sm font-semibold flex items-center gap-1.5 <?= $ocSvcOk ? 'text-emerald-700' : 'text-red-600' ?>">
          <span class="w-1.5 h-1.5 rounded-full flex-none <?= $ocSvcOk ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
          <?= $ocSvcOk ? 'Running' : 'Down' ?>
        </p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Plugin</p>
        <p class="text-sm font-semibold text-zinc-800"><?= $ocPlugin === 'active' ? 'Active' : e(ucfirst($ocPlugin)) ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Drop-in</p>
        <p class="text-sm font-semibold text-zinc-800"><?= $ocDropin === 'present' ? 'Present' : 'Missing' ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Prefix</p>
        <p class="text-sm font-semibold <?= $ocManaged ? 'text-zinc-800' : 'text-amber-700' ?> mono truncate" title="<?= e($ocPrefix) ?>">
          <?= $ocPrefix !== '' ? e($ocPrefix) : '—' ?>
        </p>
      </div>
    </div>
    <?php if ($ocManaged): ?>
    <!-- Live runtime metrics (async fetch; never blocks page load) -->
    <div class="grid grid-cols-2 divide-x divide-zinc-100 border-b border-zinc-100" data-oc-metrics data-domain="<?= e($domain) ?>">
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Cached keys</p>
        <p class="text-sm font-semibold text-zinc-800 tabular-nums" data-oc-keys><span class="text-zinc-300">···</span></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Memory used</p>
        <p class="text-sm font-semibold text-zinc-800 tabular-nums" data-oc-memory><span class="text-zinc-300">···</span></p>
      </div>
    </div>
    <?php else: ?>
    <div class="flex items-center gap-2 bg-amber-50 border-b border-amber-100 px-5 py-2.5">
      <?= icon('info-circle', 'text-amber-500 text-sm shrink-0') ?>
      <p class="text-[11px] text-amber-800">This site's Redis prefix was not set by AidiPanel, so cache actions are hidden to avoid affecting manually-configured data.</p>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- NOT CONNECTED: service OK but plugin/drop-in not set up -->
    <div class="px-5 py-5 space-y-4">
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-lg border border-zinc-100 bg-zinc-50 px-3.5 py-3">
          <p class="eyebrow mb-1.5">Plugin</p>
          <?php $pluginOk = in_array($ocPlugin, ['installed', 'active'], true); ?>
          <div class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full <?= $pluginOk ? 'bg-emerald-500' : 'bg-zinc-300' ?> flex-none"></span>
            <span class="text-xs font-semibold <?= $pluginOk ? 'text-emerald-700' : 'text-zinc-500' ?>">
              <?= $ocPlugin === 'active' ? 'Active' : ($ocPlugin === 'installed' ? 'Installed' : ($ocPlugin === 'not_installed' ? 'Not installed' : e(ucfirst($ocPlugin)))) ?>
            </span>
          </div>
        </div>
        <div class="rounded-lg border border-zinc-100 bg-zinc-50 px-3.5 py-3">
          <p class="eyebrow mb-1.5">Drop-in</p>
          <div class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full <?= $ocDropin === 'present' ? 'bg-emerald-500' : 'bg-zinc-300' ?> flex-none"></span>
            <span class="text-xs font-semibold <?= $ocDropin === 'present' ? 'text-emerald-700' : 'text-zinc-500' ?>">
              <?= $ocDropin === 'present' ? 'Present' : 'Missing' ?>
            </span>
          </div>
        </div>
      </div>
      <?php if ($ocWpCli): ?>
      <div class="flex items-start gap-2 bg-amber-50 border border-amber-200/70 rounded-lg px-3 py-2.5">
        <?= icon('alert-triangle', 'text-amber-500 mt-0.5 text-sm shrink-0') ?>
        <p class="text-[11px] text-amber-800 leading-relaxed">WP-CLI is not installed on this server. It is required for safe object cache management.</p>
      </div>
      <?php endif; ?>
      <?php if ($ocWpCli): ?>
      <div class="pt-1 border-t border-zinc-100 flex items-center justify-between">
        <p class="text-[11px] text-zinc-400">WP-CLI is required to manage object cache.</p>
        <button type="button" disabled class="btn btn-primary opacity-50 cursor-not-allowed">
          <?= icon('bolt', 'text-sm') ?> Enable Object Cache
        </button>
      </div>
      <?php else: ?>
      <form method="POST" action="/cache/object" data-op-stream class="pt-1 border-t border-zinc-100">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="domain" value="<?= e($domain) ?>">
        <input type="hidden" name="action" value="enable">
        <div data-op-fields class="flex items-center justify-between">
          <p class="text-[11px] text-zinc-400">Installs the Redis Object Cache plugin and connects this site (~30–60s).</p>
          <button type="submit" class="btn btn-primary shrink-0">
            <?= icon('bolt', 'text-sm') ?> Enable Object Cache
          </button>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Cache zone card -->
  <div class="card overflow-hidden mb-5"
       x-data="cacheZone('<?= e($domain) ?>', <?= $pcActive ? 'true' : 'false' ?>)"
       x-init="load()">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <?= icon('stack-2', ($pcActive ? 'text-speed' : 'text-zinc-300') . ' text-lg') ?>
        <div>
          <h2 class="card-title">
            <?= e(t('perf.cache.zone_title')) ?>
            <span class="tag tag-info">Nginx</span>
            <?php if (!$pcActive): ?>
              <span class="badge badge-muted">Page cache off</span>
            <?php else: ?>
              <span class="badge" :class="zone === 'dedicated' ? 'badge-ok' : 'badge-muted'"
                    x-text="zone === 'dedicated' ? '<?= e(t('perf.cache.zone_dedicated')) ?>' : '<?= e(t('perf.cache.zone_shared')) ?>'"><?= e(t('perf.cache.zone_shared')) ?></span>
            <?php endif; ?>
          </h2>
          <p class="text-[11px] text-zinc-400"><?= e(t('perf.cache.zone_desc')) ?></p>
        </div>
      </div>
    </div>

    <?php if (!$pcActive): ?>
    <!-- NEEDS PAGE CACHE -->
    <div class="px-5 py-5">
      <div class="flex items-start gap-3 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3.5">
        <?= icon('info-circle', 'text-amber-500 text-sm shrink-0 mt-0.5') ?>
        <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('perf.cache.zone_need_cache')) ?></p>
      </div>
    </div>

    <?php else: ?>
    <!-- DEDICATED: live budget tiles + revert action -->
    <div x-show="zone === 'dedicated'" x-cloak>
      <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-zinc-100 border-b border-zinc-100">
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('perf.cache.zone_keys')) ?></p>
          <p class="text-sm font-semibold text-zinc-800 mono" x-text="keys">—</p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('perf.cache.zone_max_size')) ?></p>
          <p class="text-sm font-semibold text-zinc-800 mono" x-text="maxSize">—</p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('perf.cache.zone_inactive')) ?></p>
          <p class="text-sm font-semibold text-zinc-800 mono">60m</p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('perf.cache.zone_name_label')) ?></p>
          <p class="text-sm font-semibold text-zinc-800 mono truncate" x-text="zoneName" :title="zoneName">—</p>
        </div>
      </div>
      <div class="px-5 py-4">
        <form method="POST" action="/cache/zone" data-op-stream>
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($domain) ?>">
          <input type="hidden" name="action" value="disable">
          <div data-op-fields class="flex items-center justify-between gap-3">
            <p class="text-[11px] text-zinc-400 leading-relaxed">This site has its own cache budget. Revert it to share the default zone.</p>
            <button type="submit" class="btn btn-secondary shrink-0">
              <?= icon('arrow-back-up', 'text-sm') ?> <?= e(t('perf.cache.zone_disable')) ?>
            </button>
          </div>
          <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
        </form>
      </div>
    </div>

    <!-- SHARED: explain + enable a dedicated zone -->
    <div x-show="zone !== 'dedicated'" x-cloak class="px-5 py-4 space-y-3">
      <p class="text-[11px] text-zinc-500 leading-relaxed"><?= e(t('perf.cache.zone_help')) ?></p>
      <form method="POST" action="/cache/zone" data-op-stream>
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="domain" value="<?= e($domain) ?>">
        <input type="hidden" name="action" value="enable">
        <div data-op-fields class="flex flex-wrap items-start gap-3">
          <div class="flex-1 min-w-[140px]">
            <label class="lbl"><?= e(t('perf.cache.zone_keys')) ?></label>
            <select name="keys" class="inp w-full">
              <option value="16m">16 MB</option>
              <option value="32m" selected>32 MB</option>
              <option value="64m">64 MB</option>
              <option value="128m">128 MB</option>
            </select>
            <p class="hint"><?= e(t('perf.cache.zone_keys_hint')) ?></p>
          </div>
          <div class="flex-1 min-w-[140px]">
            <label class="lbl"><?= e(t('perf.cache.zone_max_size')) ?></label>
            <select name="max_size" class="inp w-full">
              <option value="1g">1 GB</option>
              <option value="2g" selected>2 GB</option>
              <option value="5g">5 GB</option>
              <option value="10g">10 GB</option>
            </select>
            <p class="hint"><?= e(t('perf.cache.zone_max_hint')) ?></p>
          </div>
          <button type="submit" class="btn btn-primary shrink-0 mt-[23px]">
            <?= icon('stack-push', 'text-sm') ?> <?= e(t('perf.cache.zone_enable')) ?>
          </button>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- OPcache card — read-only (manage via Admin Area → PHP) -->
  <div class="card overflow-hidden mb-5">
    <div class="card-head">
      <div class="flex items-center gap-2.5">
        <?= icon('cpu', ($opcEnabled ? 'text-speed' : 'text-zinc-300') . ' text-lg') ?>
        <div>
          <h2 class="card-title">
            <?= e(t('perf.opcache')) ?>
            <span class="tag tag-muted">PHP</span>
            <?php if ($opcEnabled): ?>
              <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> Enabled</span>
            <?php else: ?>
              <span class="badge badge-warn">Disabled</span>
            <?php endif; ?>
          </h2>
          <?php if ($opcEnabled): ?>
            <p class="text-[11px] text-zinc-400">
              Hit rate <?= e($opcHitRate) ?> · <?= e($opcHits) ?> hits · <?= e($opcUsedMem) ?> / <?= e($opcLimit) ?> memory
            </p>
          <?php else: ?>
            <p class="text-[11px] text-zinc-400"><?= e(t('perf.opcache.disabled')) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <a href="/php" class="btn btn-ghost btn-sm shrink-0">
        <?= icon('settings', 'text-sm') ?> Manage in PHP Settings
      </a>
    </div>
    <?php if ($opcEnabled): ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-zinc-100 border-t border-zinc-100">
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Hit rate</p>
        <p class="text-sm font-semibold text-zinc-800"><?= e($opcHitRate) ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Hits</p>
        <p class="text-sm font-semibold text-zinc-800"><?= e($opcHits) ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Memory used</p>
        <p class="text-sm font-semibold text-zinc-800"><?= e($opcUsedMem) ?></p>
      </div>
      <div class="px-5 py-3.5">
        <p class="eyebrow mb-1.5">Memory limit</p>
        <p class="text-sm font-semibold text-zinc-800"><?= e($opcLimit) ?></p>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Protocol & Compression (server-level, detected) -->
  <div class="bg-zinc-50 rounded-xl border border-zinc-200/70 px-5 py-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="font-head font-semibold text-sm text-zinc-700 flex items-center gap-2">
          <?= icon('rocket', 'text-zinc-400') ?>
          <?= e(t('perf.delivery')) ?>
          <span class="tag tag-muted"><?= e(t('perf.server_default')) ?></span>
        </h2>
        <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('perf.delivery.desc')) ?></p>
      </div>
      <a href="/admin"
         class="text-xs font-semibold text-ink hover:underline flex items-center gap-1 whitespace-nowrap shrink-0">
        <?= e(t('perf.server_tuning')) ?> <?= icon('arrow-right', 'text-sm') ?>
      </a>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      <?php if (!empty($protocolInfo)): ?>
        <?php foreach ($protoLabels as $key => $label): ?>
          <?php $protoActive = ($protocolInfo[$key] ?? '') === 'on'; ?>
          <span class="text-[11px] font-medium <?= $protoActive ? 'text-emerald-700 bg-emerald-50' : 'text-zinc-400 bg-zinc-100' ?> px-2.5 py-1 rounded-full flex items-center gap-1">
            <?= icon($protoActive ? 'check' : 'minus', 'text-xs') ?>
            <?= e($label) ?>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="text-[11px] text-zinc-400">Protocol detection unavailable.</span>
      <?php endif; ?>
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
      <form method="POST" action="/sites/<?= e($domain) ?>/php"
            x-data="phpSwitchForm()" @submit="onSubmit($event)" x-ref="form">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div data-op-fields class="flex items-end gap-3">
          <div class="flex-1">
            <label class="lbl">PHP version</label>
            <select name="php_version" class="inp" data-phpselect>
              <?php foreach (php_versions_status() as $v => $s): ?>
              <option value="<?= e($v) ?>"
                <?= $phpVer === $v ? 'selected' : '' ?>
                <?= $s['installed'] ? '' : 'data-needs-install="1"' ?>>PHP <?= e($v) ?><?= $s['installed'] ? '' : ' — ' . e(t('php.will_install')) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint mt-1"><?= e(t('site.set.php_autoinstall_hint')) ?></p>
          </div>
          <button type="submit" class="btn btn-primary" :disabled="submitting">
            <span x-show="!submitting"><?= e(t('site.set.php_apply')) ?></span>
            <span x-show="submitting" x-cloak><?= icon('loader-2', 'text-sm spin') ?> <?= e(t('site.set.php_applying')) ?></span>
          </button>
        </div>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>

        <!-- Modal: confirm on-demand PHP install before switching -->
        <div x-show="confirmVer" x-cloak class="fixed inset-0 z-50">
          <div class="absolute inset-0 bg-zinc-900/40"></div>
          <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl text-left">
            <div class="card-head flex items-center justify-between gap-3">
              <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-ink-pale flex items-center justify-center shrink-0">
                  <?= icon('download', 'text-ink') ?>
                </span>
                <h3 class="card-title"><span x-text="'PHP ' + confirmVer"></span> <?= e(t('site.add.php_modal_title')) ?></h3>
              </div>
              <button type="button" @click="confirmVer=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
            </div>
            <div class="p-5 space-y-4">
              <p class="text-sm text-zinc-600"><?= e(t('site.add.php_modal_body')) ?></p>
              <div class="flex items-start gap-2 bg-amber-50 border border-amber-200/70 rounded-lg px-3 py-2">
                <?= icon('clock', 'text-amber-500 mt-0.5 text-sm') ?>
                <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.add.php_modal_eta')) ?></p>
              </div>
              <div x-show="submitting" x-cloak class="flex items-start gap-2 bg-ink-pale border border-ink/15 rounded-lg px-3 py-2">
                <?= icon('loader-2', 'text-ink mt-0.5 text-sm spin') ?>
                <p class="text-[11px] text-ink leading-relaxed"><?= e(t('site.add.php_installing_note')) ?></p>
              </div>
              <div class="flex justify-end gap-2 pt-1">
                <button type="button" @click="confirmVer=null" :disabled="submitting" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
                <button type="button" @click="proceed()" :disabled="submitting" class="btn btn-primary">
                  <span x-show="!submitting"><?= icon('check', 'text-sm') ?> <?= e(t('site.set.php_modal_confirm')) ?></span>
                  <span x-show="submitting" x-cloak><?= icon('loader-2', 'text-sm spin') ?> <?= e(t('site.set.php_applying')) ?></span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Nginx config -->
    <div class="card overflow-hidden">
      <div class="card-head">
        <div>
          <h2 class="card-title"><?= icon('code', 'text-zinc-400') ?> <?= e(t('site.set.nginx_title')) ?></h2>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.set.nginx_hint')) ?></p>
        </div>
        <a href="/sites/<?= e($domain) ?>/nginx" class="btn btn-sm btn-secondary">
          <?= icon('edit', 'text-sm') ?> <?= e(t('site.set.nginx_edit')) ?>
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
          <?= icon('trash', 'text-sm') ?>
          <?= e(t('site.set.delete_btn', ['domain' => $domain])) ?>
        </button>
      </div>

      <!-- Modal: permanent delete (type-the-domain confirm) -->
      <div x-show="open" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-zinc-900/40"></div>
        <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
          <div class="card-head flex items-center justify-between bg-red-50/50">
            <h3 class="card-title text-red-700"><?= icon('alert-triangle') ?> <?= e(t('site.set.delete_modal_title', ['domain' => $domain])) ?></h3>
            <button type="button" @click="open=false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
          </div>
          <div class="p-5 space-y-3">
            <p class="text-sm text-zinc-600"><?= e(t('site.set.delete_modal_warning')) ?></p>
            <label class="lbl"><?= e(t('site.set.delete_modal_type_to_confirm', ['domain' => $domain])) ?></label>
            <input type="text" x-model="typed" class="inp w-full" autocomplete="off" spellcheck="false" placeholder="<?= e($domain) ?>">
            <form method="POST" action="/sites/<?= e($domain) ?>/delete" class="flex justify-end gap-2 pt-1"
                  @submit="window.opGuard.start()">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <button type="button" @click="open=false" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              <button type="submit" class="btn btn-danger"
                      :disabled="typed !== '<?= e($domain) ?>'"
                      :class="{ 'opacity-50 cursor-not-allowed': typed !== '<?= e($domain) ?>' }">
                <?= icon('trash', 'text-sm') ?> <?= e(t('site.set.delete_modal_confirm_btn')) ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

  </div>

<?php elseif ($activeTab === 'ssl'): ?>
<!-- ──────────────── SSL / TLS ──────────────── -->
<?php
  $sslDomains  = !empty($ssl['domains']) ? array_values($ssl['domains']) : [$domain];
  $domainsAttr = e(json_encode($sslDomains));
  $autoRenew   = $httpsOptions['auto_renew'] ?? null;   // bool|null (null = no LE cert)
  $forceHttps  = !empty($httpsOptions['force_https']);
  $hstsOn      = !empty($httpsOptions['hsts']);
?>
  <div x-data="sslTab(<?= $domainsAttr ?>)">

    <!-- SSL/TLS — single consolidated card -->
    <div class="card mb-5">

      <!-- header + Manage SSL -->
      <div class="card-head">
        <div class="flex items-center gap-2.5">
          <?= icon($hasTrustedSsl ? 'lock-check' : 'lock-open', ($hasTrustedSsl ? 'text-emerald-500' : 'text-amber-500') . ' text-lg') ?>
          <div>
            <h2 class="card-title">
              <?= e(t('site.ssl.title')) ?>
              <span class="badge <?= $hasTrustedSsl ? 'badge-ok' : 'badge-warn' ?>"><?php if ($hasTrustedSsl): ?><span class="dot bg-emerald-500"></span> <?php endif; ?><?= e($hasTrustedSsl ? t('site.ssl.protected') : t('site.ssl.not_secure')) ?></span>
            </h2>
            <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.ssl.manage_desc', ['domain' => $domain])) ?></p>
          </div>
        </div>

        <div class="relative" @click.outside="manage=false">
          <button type="button" @click="manage=!manage" class="btn btn-secondary btn-sm">
            <?= icon('settings', 'text-sm') ?> <?= e(t('site.ssl.manage')) ?>
            <?= icon('chevron-down', 'text-xs') ?>
          </button>
          <div x-show="manage" x-cloak x-transition.opacity
               class="absolute right-0 mt-1 w-56 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30">
            <?php if ($sslState === 'letsencrypt'): ?>
            <button type="button" @click="manage=false; modal='renew'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('refresh', 'text-zinc-400') ?> <?= e(t('site.ssl.renew_cert')) ?></button>
            <button type="button" @click="manage=false; modal='le'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('rosette-discount-check', 'text-zinc-400') ?> <?= e(t('site.ssl.reissue')) ?></button>
            <?php else: ?>
            <button type="button" @click="manage=false; modal='le'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('lock-check', 'text-zinc-400') ?> <?= e(t('site.ssl.issue_le')) ?></button>
            <?php endif; ?>
            <button type="button" @click="manage=false; modal='import'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('certificate', 'text-zinc-400') ?> <?= e(t('site.ssl.import')) ?></button>
            <div class="h-px bg-zinc-100 my-1"></div>
            <button type="button" @click="manage=false; runCheck()" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('shield-search', 'text-zinc-400') ?> <?= e(t('site.ssl.run_check')) ?></button>
          </div>
        </div>
      </div>

      <?php if (!$hasTrustedSsl): ?>
      <div class="px-5 pt-4">
        <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3">
          <?= icon('alert-triangle', 'text-amber-500 mt-0.5 shrink-0') ?>
          <div>
            <?php if ($sslReason !== null): ?>
            <p class="text-xs font-semibold text-amber-900 mb-0.5"><?= e(t('site.ssl.not_secure_because', ['reason' => $sslReason])) ?></p>
            <?php endif; ?>
            <p class="text-xs text-amber-800 leading-relaxed"><?= e(t('site.ssl.warn_untrusted')) ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- metric tiles: Valid until · Provider · Auto-renew -->
      <div class="grid grid-cols-3 divide-x divide-zinc-100 border-t border-b border-zinc-100">
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.ssl.f.valid_until')) ?></p>
          <p class="text-sm font-semibold text-zinc-800">
            <?= !empty($ssl['expiry']) ? e(date('M j, Y', strtotime((string) $ssl['expiry']))) : '—' ?>
            <?php if (!empty($ssl['expiry']) && $ssl['daysLeft'] !== null): ?>
            <span class="text-[11px] font-normal text-zinc-400 ml-1"><?= e(t('site.ssl.days_left', ['n' => $ssl['daysLeft']])) ?></span>
            <?php endif; ?>
          </p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.ssl.provider')) ?></p>
          <p class="text-sm font-semibold text-zinc-800">
            <?= e($sslTypeLabel) ?>
            <?php if (!empty($ssl['issuer'])): ?>
            <span class="text-[11px] font-normal text-zinc-400 ml-1" title="<?= e($ssl['issuer']) ?>"><?= e($ssl['issuer']) ?></span>
            <?php endif; ?>
          </p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.ssl.opt.auto_renew')) ?></p>
          <p class="text-sm font-semibold <?= $autoRenew ? 'text-emerald-700' : 'text-zinc-800' ?>"><?= $autoRenew === null ? '—' : e($autoRenew ? t('site.ssl.enabled') : t('site.ssl.disabled')) ?></p>
        </div>
      </div>

      <!-- async SSL check result -->
      <div x-show="check" x-cloak class="px-5 pt-3">
        <p class="text-xs font-semibold" :class="check ? check.cls : ''" x-text="check ? check.text : ''"></p>
      </div>

      <!-- Certificates -->
      <div class="px-5 pt-4 pb-1"><p class="eyebrow"><?= e(t('site.ssl.certs_title')) ?></p></div>
      <table class="tbl">
        <thead>
          <tr>
            <th class="pl-5"><?= e(t('site.ssl.f.type')) ?></th>
            <th><?= e(t('site.ssl.f.domains')) ?></th>
            <th><?= e(t('site.ssl.f.expiration')) ?></th>
            <th><?= e(t('site.ssl.f.installed')) ?></th>
            <th class="pr-5 th-center"><?= e(t('site.ssl.f.action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($certs as $c): ?>
          <tr>
            <td class="pl-5 font-medium text-zinc-900"><?= e($c['type']) ?></td>
            <td><span class="mono text-xs text-zinc-600 break-all"><?= e(implode(', ', $c['domains'])) ?></span></td>
            <td class="text-zinc-700"><?= !empty($c['expiry']) ? e(date('M j, Y', strtotime((string) $c['expiry']))) : '—' ?></td>
            <td>
              <?php if (!empty($c['installed'])): ?>
                <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('site.ssl.installed_yes')) ?></span>
              <?php else: ?>
                <span class="badge badge-muted"><?= e(t('site.ssl.installed_backup')) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if (empty($c['active'])): ?>
              <div class="relative inline-block text-left" x-data="{ row:false }" @click.outside="row=false">
                <button type="button" @click="row=!row" class="w-7 h-7 grid place-items-center rounded-md hover:bg-zinc-100 text-zinc-400" title="<?= e(t('site.ssl.use_cert')) ?>"><?= icon('dots-vertical') ?></button>
                <div x-show="row" x-cloak class="absolute right-0 mt-1 w-44 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30">
                  <form method="POST" action="/sites/<?= e($domain) ?>/ssl/use">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                    <input type="hidden" name="type" value="<?= e($c['state']) ?>">
                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('check', 'text-zinc-400') ?> <?= e(t('site.ssl.use_cert')) ?></button>
                  </form>
                </div>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- HTTPS options: stacked rows, On/Off applies immediately -->
      <div class="px-5 pt-4 pb-2 border-t border-zinc-100"><p class="eyebrow"><?= e(t('site.ssl.https_options')) ?></p></div>
      <div class="divide-y divide-zinc-100">

        <!-- Force HTTPS -->
        <div class="flex items-center justify-between gap-4 px-5 py-3.5">
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('site.ssl.opt.force_https')) ?></p>
            <p class="hint"><?= e(t('site.ssl.opt.force_https_desc')) ?></p>
          </div>
          <form method="POST" action="/sites/<?= e($domain) ?>/ssl/force-https" class="flex-none">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <div class="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
              <button type="submit" name="action" value="on"  class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= $forceHttps ? 'bg-emerald-500 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">On</button>
              <button type="submit" name="action" value="off" class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= !$forceHttps ? 'bg-zinc-600 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">Off</button>
            </div>
          </form>
        </div>

        <!-- HSTS -->
        <div class="flex items-center justify-between gap-4 px-5 py-3.5">
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('site.ssl.opt.hsts')) ?></p>
            <p class="hint"><?= e(t('site.ssl.opt.hsts_desc')) ?></p>
          </div>
          <form method="POST" action="/sites/<?= e($domain) ?>/ssl/hsts" class="flex-none">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <div class="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
              <button type="submit" name="action" value="on"  class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= $hstsOn ? 'bg-emerald-500 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">On</button>
              <button type="submit" name="action" value="off" class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= !$hstsOn ? 'bg-zinc-600 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">Off</button>
            </div>
          </form>
        </div>

        <!-- Auto Renew -->
        <div class="flex items-center justify-between gap-4 px-5 py-3.5">
          <div class="pr-2">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('site.ssl.opt.auto_renew')) ?></p>
            <p class="hint"><?= $autoRenew === null ? e(t('site.ssl.opt.na')) : e(t('site.ssl.opt.auto_renew_desc')) ?></p>
          </div>
          <?php if ($autoRenew === null): ?>
          <div class="inline-flex flex-none rounded-lg border border-zinc-200 bg-zinc-100 p-0.5 opacity-50" role="group">
            <span class="px-3 py-1 text-xs font-semibold text-zinc-400">On</span>
            <span class="px-3 py-1 text-xs font-semibold text-zinc-400">Off</span>
          </div>
          <?php else: ?>
          <form method="POST" action="/sites/<?= e($domain) ?>/ssl/autorenew" class="flex-none">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <div class="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5" role="group">
              <button type="submit" name="action" value="on"  class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= $autoRenew ? 'bg-emerald-500 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">On</button>
              <button type="submit" name="action" value="off" class="px-3 py-1 text-xs font-semibold rounded-md transition cursor-pointer <?= !$autoRenew ? 'bg-zinc-600 text-white shadow-sm' : 'text-zinc-500 hover:bg-white hover:text-zinc-900' ?>">Off</button>
            </div>
          </form>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- Modal: Install Let's Encrypt -->
    <div x-show="modal==='le'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head">
          <h3 class="card-title"><?= icon('rosette-discount-check', 'text-speed') ?> <?= e(t('site.ssl.le_modal_title')) ?></h3>
          <button type="button" @click="modal=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/install" data-op-stream>
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div data-op-fields>
          <div class="p-5">
            <div class="flex items-start gap-2.5 bg-speed-pale border border-speed/20 rounded-lg px-4 py-3 mb-4">
              <?= icon('info-circle', 'text-speed mt-0.5 shrink-0') ?>
              <p class="text-xs text-zinc-600 leading-relaxed"><?= e(t('site.ssl.le_prereq', ['domain' => $domain])) ?></p>
            </div>

            <label class="lbl"><?= e(t('site.ssl.f.domains')) ?></label>
            <template x-for="(d, i) in domains" :key="i">
              <div class="flex items-center gap-2 mb-2">
                <input type="text" name="domains[]" x-model="domains[i]" required
                       class="inp mono text-sm flex-1" placeholder="example.com" autocomplete="off" spellcheck="false">
                <button type="button" @click="domains.splice(i, 1)" x-show="domains.length > 1"
                        class="shrink-0 w-9 h-9 grid place-items-center rounded-lg border border-zinc-200 text-zinc-400 hover:text-red-500 hover:border-red-200 transition"
                        title="<?= e(t('common.remove')) ?>"><?= icon('x', 'text-sm') ?></button>
              </div>
            </template>
            <button type="button" @click="domains.push('')"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-speed hover:underline">
              <?= icon('plus', 'text-xs') ?> <?= e(t('site.ssl.add_domain')) ?>
            </button>
            <p class="hint mt-1"><?= e(t('site.ssl.domains_hint')) ?></p>

            <label class="lbl mt-4"><?= e(t('site.ssl.email')) ?> <span class="text-zinc-400 font-normal">(<?= e(t('common.optional')) ?>)</span></label>
            <input type="email" name="email" class="inp" placeholder="you@example.com">
            <p class="hint"><?= e(t('site.ssl.email_hint')) ?></p>
          </div>
          <div class="flex items-center justify-end gap-2 px-5 py-3.5 border-t border-zinc-100">
            <button type="button" @click="modal=null" class="btn btn-ghost" :disabled="submitting"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <?= icon('lock-check', 'text-sm', ['x-show' => '!submitting']) ?>
              <?= icon('loader-2', 'text-sm animate-spin', ['x-show' => 'submitting', 'x-cloak' => true]) ?>
              <span x-show="!submitting"><?= e(t('site.ssl.install_btn')) ?></span>
              <span x-show="submitting" x-cloak><?= e(t('site.ssl.processing')) ?></span>
            </button>
          </div>
          </div>
          <div class="p-5"><?php include APP_ROOT . '/Views/partials/op-progress.php'; ?></div>
        </form>
      </div>
    </div>

    <!-- Modal: Import certificate -->
    <div x-show="modal==='import'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-lg card shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="card-head">
          <h3 class="card-title"><?= icon('certificate', 'text-speed') ?> <?= e(t('site.ssl.import_modal_title')) ?></h3>
          <button type="button" @click="modal=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/import" @submit="submitting = true; window.opGuard.start()">
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
              <?= icon('upload', 'text-sm', ['x-show' => '!submitting']) ?>
              <?= icon('loader-2', 'text-sm animate-spin', ['x-show' => 'submitting', 'x-cloak' => true]) ?>
              <span x-show="!submitting"><?= e(t('site.ssl.import_btn')) ?></span>
              <span x-show="submitting" x-cloak><?= e(t('site.ssl.processing')) ?></span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Renew certificate -->
    <div x-show="modal==='renew'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head">
          <h3 class="card-title"><?= icon('refresh', 'text-speed') ?> <?= e(t('site.ssl.renew_cert')) ?></h3>
          <button type="button" @click="modal=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/ssl/renew" data-op-stream>
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div data-op-fields class="p-5">
            <p class="text-sm text-zinc-600 leading-relaxed mb-4"><?= e(t('site.ssl.renew')) ?> · <span class="mono"><?= e($domain) ?></span></p>
            <div class="flex justify-end gap-2">
              <button type="button" @click="modal=null" :disabled="submitting" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              <button type="submit" :disabled="submitting" class="btn btn-primary"><?= icon('refresh', 'text-sm') ?> <?= e(t('site.ssl.renew_cert')) ?></button>
            </div>
          </div>
          <div class="px-5 pb-5"><?php include APP_ROOT . '/Views/partials/op-progress.php'; ?></div>
        </form>
      </div>
    </div>

    <script>
    function sslTab(domains) {
      return {
        manage: false,
        modal: null,
        submitting: false,
        domains: domains,
        check: null,
        runCheck() {
          var self = this;
          this.check = { cls: 'text-zinc-500', text: <?= json_encode(t('site.ssl.check_running')) ?> };
          fetch('/api/ssl/check?domain=' + encodeURIComponent(<?= json_encode($domain) ?>),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (!d || !d.ok) { self.check = { cls: 'text-rose-600', text: <?= json_encode(t('site.ssl.check_fail')) ?> }; return; }
              var parts = [d.state, d.expiry ? ('exp ' + d.expiry) : '', (d.days_left || 0) + 'd',
                           'Force HTTPS ' + d.force_https, 'HSTS ' + d.hsts];
              self.check = { cls: d.trusted ? 'text-emerald-600' : 'text-amber-600', text: parts.filter(Boolean).join(' · ') };
            })
            .catch(function () { self.check = { cls: 'text-rose-600', text: <?= json_encode(t('site.ssl.check_fail')) ?> }; });
        }
      };
    }
    </script>

  </div>

<?php elseif ($activeTab === 'database'): ?>
<!-- ──────────────── DATABASE ──────────────── -->
<?php
  $databases = $databases ?? [];
  $dbUsers   = $dbUsers ?? [];
  $dbPrefix  = $dbPrefix ?? '';
  $pmaInstalled = !empty($pmaInstalled);
  $creds     = flash('db_credentials');
  $creds     = $creds ? json_decode($creds, true) : null;
  // "<prefix>wp" is reserved for the auto-provisioned WordPress database, so the
  // "Add Database" modal defaults to "<prefix>app" to avoid colliding with it.
  $defDb     = $dbPrefix !== '' ? $dbPrefix . 'app'  : '';
  $defUser   = $dbPrefix !== '' ? $dbPrefix . 'user' : '';
?>
  <div x-data="{ modal:null, createUser:true, editName:'', editDb:'', editPerm:'rw', editRegen:false, delName:'', delKind:'', typed:'' }">

    <?php if ($creds): ?>
    <!-- Show-once credentials -->
    <div class="card mb-5 border-emerald-200" x-data="{ copied:false }">
      <div class="px-5 py-4 flex items-start gap-2.5">
        <?= icon('circle-check', 'text-emerald-500 text-lg mt-0.5 shrink-0') ?>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-emerald-800 mb-0.5"><?= e(t('site.database.created_title')) ?></p>
          <p class="text-[11px] text-zinc-500 mb-3"><?= e(t('site.database.created_once')) ?></p>
          <div class="space-y-1 text-xs mono">
            <?php if (!empty($creds['database'])): ?><p class="text-zinc-500"><?= e(t('site.database.f.database')) ?>: <span class="text-zinc-800"><?= e($creds['database']) ?></span></p><?php endif; ?>
            <?php if (!empty($creds['user'])): ?><p class="text-zinc-500"><?= e(t('site.database.f.user')) ?>: <span class="text-zinc-800"><?= e($creds['user']) ?></span></p><?php endif; ?>
            <p class="text-zinc-500"><?= e(t('site.database.f.password')) ?>:
              <span class="text-zinc-800" x-ref="pw"><?= e($creds['pass']) ?></span>
              <button type="button" class="ml-2 text-ink hover:underline"
                      @click="navigator.clipboard && navigator.clipboard.writeText($refs.pw.textContent); copied=true; setTimeout(()=>copied=false,1200)">
                <span x-show="!copied"><?= e(t('site.database.copy_password')) ?></span>
                <span x-show="copied" x-cloak class="text-emerald-600"><?= e(t('topbar.copied')) ?></span>
              </button>
            </p>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($wpDbInfo)): ?>
    <!-- WordPress detection -->
    <div class="bg-ink-pale border border-ink/15 rounded-lg px-5 py-3 mb-5">
      <div class="flex items-center gap-2.5">
        <?= icon('brand-wordpress', 'text-ink text-lg shrink-0') ?>
        <div class="min-w-0">
          <h2 class="card-title"><?= e(t('site.database.wp_detected')) ?></h2>
          <p class="text-[11px] text-zinc-500 mono break-all mt-0.5"><?= e(t('site.database.f.database')) ?>: <?= e($wpDbInfo['db']) ?> · <?= e(t('site.database.f.user')) ?>: <?= e($wpDbInfo['user']) ?> · <?= e(t('site.database.wp_prefix')) ?>: <?= e($wpDbInfo['prefix']) ?></p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Databases -->
    <div class="card mb-5">
      <div class="card-head">
        <div class="flex items-center gap-2.5">
          <?= icon('database', 'text-zinc-400 text-lg') ?>
          <div>
            <h2 class="card-title"><?= e(t('site.database.databases')) ?></h2>
            <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.database.host_meta', ['host' => $dbHost, 'port' => $dbPort])) ?></p>
          </div>
        </div>
        <button type="button" @click="modal='addDb'; createUser=true" class="btn btn-primary btn-sm">
          <?= icon('plus', 'text-sm') ?> <?= e(t('site.database.add_db')) ?>
        </button>
      </div>

      <?php if (empty($databases)): ?>
      <div class="px-5 py-10 text-center">
        <p class="text-sm font-medium text-zinc-600"><?= e(t('site.database.empty_db_title')) ?></p>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t('site.database.empty_db_hint')) ?></p>
      </div>
      <?php else: ?>
      <table class="tbl table-fixed" data-db-table-grid="databases">
        <colgroup>
          <col style="width:24%">
          <col style="width:20%">
          <col style="width:16%">
          <col style="width:20%">
          <col style="width:20%">
        </colgroup>
        <thead>
          <tr>
            <th class="pl-5"><?= e(t('site.database.f.database')) ?></th>
            <th><?= e(t('site.database.f.size')) ?></th>
            <th><?= e(t('site.database.f.tables')) ?></th>
            <th><?= e(t('site.database.f.status')) ?></th>
            <th class="pr-5 th-center"><?= e(t('site.database.f.action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($databases as $db): ?>
          <?php $active = ($db['status'] ?? '') === 'Active'; ?>
          <tr>
            <td class="pl-5 font-medium text-zinc-900 mono break-all"><?= e($db['name'] ?? '') ?></td>
            <td class="text-zinc-700"><?= !empty($db['size_mb']) ? e($db['size_mb']) . ' MB' : '—' ?></td>
            <td class="text-zinc-700"><?= isset($db['tables']) && (int) $db['tables'] > 0 ? e($db['tables']) : '—' ?></td>
            <td><span class="badge <?= $active ? 'badge-ok' : 'badge-muted' ?>"><?php if ($active): ?><span class="dot bg-emerald-500"></span> <?php endif; ?><?= e($active ? t('site.database.status.active') : t('site.database.status.empty')) ?></span></td>
            <td class="text-center">
              <div class="relative inline-block text-left" x-data="{ row:false }" @click.outside="row=false">
                <button type="button" @click="row=!row" aria-label="<?= e(t('common.actions')) ?>" class="w-8 h-8 rounded-lg hover:bg-zinc-100 text-zinc-400 hover:text-zinc-700"><?= icon('dots-vertical') ?></button>
                <div x-show="row" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-44 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30 text-left">
                  <button type="button" @click="row=false; delName='<?= e($db['name'] ?? '') ?>'; delKind='db'; typed=''; modal='delDb'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left"><?= icon('trash', 'text-sm') ?> <?= e(t('site.database.delete_db')) ?></button>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Database Users -->
    <div class="card mb-5">
      <div class="card-head">
        <div>
          <h2 class="card-title"><?= icon('users', 'text-zinc-400') ?> <?= e(t('site.database.users')) ?></h2>
        </div>
        <button type="button" @click="modal='addUser'" class="btn btn-primary btn-sm"<?= empty($databases) ? ' disabled' : '' ?>>
          <?= icon('plus', 'text-sm') ?> <?= e(t('site.database.add_user')) ?>
        </button>
      </div>

      <?php if (empty($dbUsers)): ?>
      <div class="px-5 py-10 text-center">
        <p class="text-sm font-medium text-zinc-600"><?= e(t('site.database.empty_user_title')) ?></p>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t('site.database.empty_user_hint')) ?></p>
      </div>
      <?php else: ?>
      <table class="tbl table-fixed" data-db-table-grid="users">
        <colgroup>
          <col style="width:24%">
          <col style="width:20%">
          <col style="width:16%">
          <col style="width:20%">
          <col style="width:20%">
        </colgroup>
        <thead>
          <tr>
            <th class="pl-5"><?= e(t('site.database.f.user')) ?></th>
            <th><?= e(t('site.database.f.database')) ?></th>
            <th><?= e(t('site.database.pma')) ?></th>
            <th><?= e(t('site.database.f.permissions')) ?></th>
            <th class="pr-5 th-center"><?= e(t('site.database.f.action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dbUsers as $u): ?>
          <?php $rw = ($u['permission'] ?? 'ro') === 'rw'; ?>
          <tr>
            <td class="pl-5 font-medium text-zinc-900 mono break-all"><?= e($u['user'] ?? '') ?></td>
            <td class="text-zinc-700 mono break-all"><?= e($u['database'] ?? '') ?></td>
            <td>
              <?php if ($pmaInstalled && !empty($pmaUrl)): ?>
              <form method="POST" action="/sites/<?= e($domain) ?>/database/phpmyadmin/open" target="_blank" class="inline">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="user" value="<?= e($u['user'] ?? '') ?>">
                <input type="hidden" name="database" value="<?= e($u['database'] ?? '') ?>">
                <button type="submit" class="text-ink hover:underline text-sm"><?= icon('external-link', 'text-xs') ?> <?= e(t('site.database.pma_manage')) ?></button>
              </form>
              <?php else: ?>
              <span class="badge badge-muted"><?= e(t('site.database.pma_not_installed')) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $rw ? 'badge-ok' : 'badge-muted' ?>"><?= e($rw ? t('site.database.perm.rw') : t('site.database.perm.ro')) ?></span></td>
            <td class="text-center">
              <div class="relative inline-block text-left" x-data="{ row:false }" @click.outside="row=false">
                <button type="button" @click="row=!row" aria-label="<?= e(t('common.actions')) ?>" class="w-8 h-8 rounded-lg hover:bg-zinc-100 text-zinc-400 hover:text-zinc-700"><?= icon('dots-vertical') ?></button>
                <div x-show="row" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-48 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30 text-left">
                  <?php if ($pmaInstalled && !empty($pmaUrl)): ?>
                  <form method="POST" action="/sites/<?= e($domain) ?>/database/phpmyadmin/open" target="_blank">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                    <input type="hidden" name="user" value="<?= e($u['user'] ?? '') ?>">
                    <input type="hidden" name="database" value="<?= e($u['database'] ?? '') ?>">
                    <button type="submit" @click="row=false" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('external-link', 'text-sm text-zinc-400') ?> <?= e(t('site.database.open_pma')) ?></button>
                  </form>
                  <?php else: ?>
                  <button type="button" @click="row=false; modal='installPma'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('download', 'text-sm text-zinc-400') ?> <?= e(t('site.database.pma_install')) ?></button>
                  <?php endif; ?>
                  <button type="button" @click="row=false; editName='<?= e($u['user'] ?? '') ?>'; editDb='<?= e($u['database'] ?? '') ?>'; editPerm='<?= e($u['permission'] ?? 'rw') ?>'; editRegen=false; modal='editUser'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('edit', 'text-sm text-zinc-400') ?> <?= e(t('site.database.edit_user')) ?></button>
                  <button type="button" @click="row=false; delName='<?= e($u['user'] ?? '') ?>'; delKind='user'; typed=''; modal='delUser'" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left"><?= icon('trash', 'text-sm') ?> <?= e(t('site.database.delete_user')) ?></button>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Modal: Install phpMyAdmin -->
    <div x-show="modal==='installPma'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div x-data="{ submitting:false }" class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('brand-mysql', 'text-zinc-400') ?> <?= e(t('site.database.pma_install')) ?></h3>
          <button type="button" @click="modal=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/database/phpmyadmin/install" data-op-stream class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div data-op-fields class="space-y-3">
            <p class="text-sm text-zinc-600"><?= e(t('site.database.pma_install_hint')) ?></p>
            <div class="flex justify-end gap-2 pt-1">
              <button type="button" @click="modal=null" :disabled="submitting" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              <button type="submit" :disabled="submitting" class="btn btn-primary"><?= icon('download', 'text-sm') ?> <?= e(t('site.database.pma_install')) ?></button>
            </div>
          </div>
          <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
        </form>
      </div>
    </div>

    <!-- Modal: Add Database -->
    <div x-show="modal==='addDb'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('database', 'text-zinc-400') ?> <?= e(t('site.database.add_db')) ?></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/database/add" class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div>
            <label class="lbl"><?= e(t('site.database.f.database')) ?></label>
            <input type="text" name="name" class="inp w-full mono" autocomplete="off" spellcheck="false" pattern="[a-zA-Z0-9_]+" value="<?= e($defDb) ?>" required>
          </div>
          <label class="flex items-center gap-2 text-sm text-zinc-600">
            <input type="checkbox" name="create_user" value="1" x-model="createUser" class="rounded border-zinc-300">
            <?= e(t('site.database.create_user')) ?>
          </label>
          <div x-show="createUser" x-cloak class="space-y-3">
            <div>
              <label class="lbl"><?= e(t('site.database.f.user')) ?></label>
              <input type="text" name="user" class="inp w-full mono" autocomplete="off" spellcheck="false" pattern="[a-zA-Z0-9_]*" value="<?= e($defUser) ?>">
            </div>
            <div>
              <label class="lbl"><?= e(t('site.database.f.password')) ?> <span class="text-zinc-400"><?= e(t('site.database.pass_optional')) ?></span></label>
              <input type="text" name="pass" class="inp w-full mono" autocomplete="off" spellcheck="false" placeholder="<?= e(t('site.database.pass_generate')) ?>">
            </div>
            <div>
              <label class="lbl"><?= e(t('site.database.f.permissions')) ?></label>
              <select name="permission" class="inp w-full">
                <option value="rw"><?= e(t('site.database.perm.rw')) ?></option>
                <option value="ro"><?= e(t('site.database.perm.ro')) ?></option>
              </select>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary"><?= icon('plus', 'text-sm') ?> <?= e(t('site.database.create_db_btn')) ?></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Add Database User -->
    <div x-show="modal==='addUser'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('user-plus', 'text-zinc-400') ?> <?= e(t('site.database.add_user')) ?></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/database/user/add" class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <div>
            <label class="lbl"><?= e(t('site.database.f.user')) ?></label>
            <input type="text" name="name" class="inp w-full mono" autocomplete="off" spellcheck="false" pattern="[a-zA-Z0-9_]+" value="<?= e($defUser) ?>" required>
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.database')) ?></label>
            <select name="database" class="inp w-full mono" required>
              <?php foreach ($databases as $db): ?>
              <option value="<?= e($db['name'] ?? '') ?>"><?= e($db['name'] ?? '') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.password')) ?> <span class="text-zinc-400"><?= e(t('site.database.pass_optional')) ?></span></label>
            <input type="text" name="pass" class="inp w-full mono" autocomplete="off" spellcheck="false" placeholder="<?= e(t('site.database.pass_generate')) ?>">
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.permissions')) ?></label>
            <select name="permission" class="inp w-full">
              <option value="rw"><?= e(t('site.database.perm.rw')) ?></option>
              <option value="ro"><?= e(t('site.database.perm.ro')) ?></option>
            </select>
          </div>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary"><?= icon('plus', 'text-sm') ?> <?= e(t('site.database.create_user_btn')) ?></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Edit Database User -->
    <div x-show="modal==='editUser'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('edit', 'text-zinc-400') ?> <?= e(t('site.database.edit_user')) ?></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($domain) ?>/database/user/edit" class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="name" :value="editName">
          <div>
            <label class="lbl"><?= e(t('site.database.f.user')) ?></label>
            <input type="text" class="inp w-full mono bg-zinc-50" :value="editName" readonly>
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.database')) ?></label>
            <input type="text" class="inp w-full mono bg-zinc-50" :value="editDb" readonly>
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.permissions')) ?></label>
            <select name="permission" class="inp w-full" x-model="editPerm">
              <option value="rw"><?= e(t('site.database.perm.rw')) ?></option>
              <option value="ro"><?= e(t('site.database.perm.ro')) ?></option>
            </select>
          </div>
          <div>
            <label class="lbl"><?= e(t('site.database.f.new_password')) ?> <span class="text-zinc-400"><?= e(t('site.database.pass_optional_keep')) ?></span></label>
            <input type="text" name="pass" class="inp w-full mono" autocomplete="off" spellcheck="false" :disabled="editRegen" placeholder="••••••••">
            <label class="flex items-center gap-2 text-xs text-zinc-500 mt-2">
              <input type="checkbox" name="regenerate" value="1" x-model="editRegen" class="rounded border-zinc-300">
              <?= e(t('site.database.generate_new')) ?>
            </label>
          </div>
          <div class="flex items-start gap-2 bg-amber-50 border border-amber-200/70 rounded-lg px-3 py-2">
            <?= icon('alert-triangle', 'text-amber-500 mt-0.5 text-sm shrink-0') ?>
            <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.database.pass_warning')) ?></p>
          </div>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.database.save_btn')) ?></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Delete Database / User (type-to-confirm) -->
    <div x-show="modal==='delDb' || modal==='delUser'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between bg-red-50/50">
          <h3 class="card-title text-red-700"><?= icon('alert-triangle') ?> <span x-show="modal==='delDb'"><?= e(t('site.database.delete_db')) ?></span><span x-show="modal==='delUser'" x-cloak><?= e(t('site.database.delete_user')) ?></span></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <div class="p-5 space-y-3">
          <p class="text-sm text-zinc-600"><span x-show="modal==='delDb'"><?= e(t('site.database.delete_db_warn')) ?></span><span x-show="modal==='delUser'" x-cloak><?= e(t('site.database.delete_user_warn')) ?></span></p>
          <label class="lbl"><?= e(t('site.database.delete_type_confirm')) ?> <span class="mono text-zinc-800" x-text="delName"></span></label>
          <input type="text" x-model="typed" class="inp w-full mono" autocomplete="off" spellcheck="false" :placeholder="delName">
          <form method="POST" :action="'/sites/<?= e($domain) ?>/database/' + (delKind==='db' ? 'delete' : 'user/delete')" class="flex justify-end gap-2 pt-1">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <input type="hidden" name="name" :value="delName">
            <input type="hidden" name="confirm" :value="typed">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-danger" :disabled="typed !== delName" :class="{ 'opacity-50 cursor-not-allowed': typed !== delName }">
              <?= icon('trash', 'text-sm') ?> <span x-show="modal==='delDb'"><?= e(t('site.database.delete_db_btn')) ?></span><span x-show="modal==='delUser'" x-cloak><?= e(t('site.database.delete_user_btn')) ?></span>
            </button>
          </form>
        </div>
      </div>
    </div>

  </div>

<?php elseif ($activeTab === 'security'): ?>
<!-- ==================== SECURITY TAB ==================== -->
  <?php
  $driftMessage = match ($baError) {
      'missing_vhost_marker'   => t('site.security.drift.missing_vhost_marker'),
      'missing_http_include'   => t('site.security.drift.missing_http_include'),
      'missing_credentials'    => t('site.security.drift.missing_credentials'),
      'unreadable_credentials' => t('site.security.drift.unreadable_credentials'),
      'state_mismatch'         => t('site.security.drift.state_mismatch'),
      'force_https_drift'      => t('site.security.drift.force_https_drift'),
      'status_unavailable'     => t('site.security.drift.status_unavailable'),
      default                  => '',
  };
  $driftCritical = in_array($baError, [
      'missing_vhost_marker',
      'missing_http_include',
      'missing_credentials',
      'unreadable_credentials',
      'force_https_drift',
  ], true);
  $cfErrorMessage = match ($cfError) {
      'busy'                    => t('site.security.cloudflare.error.busy'),
      'missing_seed'            => t('site.security.cloudflare.error.missing_seed'),
      'missing_live'            => t('site.security.cloudflare.error.missing_live'),
      'missing_generated'       => t('site.security.cloudflare.error.missing_generated'),
      'missing_state'           => t('site.security.cloudflare.error.missing_state'),
      'malformed_state'         => t('site.security.cloudflare.error.malformed_state'),
      'hash_count_mismatch'     => t('site.security.cloudflare.error.hash_count_mismatch'),
      'generated_content_drift' => t('site.security.cloudflare.error.generated_content_drift'),
      'status_unavailable'      => t('site.security.cloudflare.error.status_unavailable'),
      default                   => '',
  };
  // Direct-origin rejection (Cloudflare-only) depends on a healthy, active global
  // real-IP foundation. The CLI is the final guard; the panel mirrors it for UX.
  $cfoDependencyOk = $cfErrorMessage === '' && $cfEnabled && !$cfStale;
  $cfoErrorMessage = match ($cfoError) {
      'busy'                 => t('site.security.cloudflare_only.error.busy'),
      'dependency_unhealthy' => t('site.security.cloudflare_only.error.dependency'),
      'sibling_drift'        => t('site.security.cloudflare_only.error.sibling_drift'),
      'marker_drift', 'missing_marker', 'state_mismatch',
      'missing_state', 'malformed_state'
                             => t('site.security.cloudflare_only.error.drift'),
      'status_unavailable'   => t('site.security.cloudflare_only.error.status_unavailable'),
      ''                     => '',
      default                => t('site.security.cloudflare_only.error.generic'),
  };
  ?>
  <div class="space-y-4" x-data="{ enabled: <?= $baEnabled ? 'true' : 'false' ?>, scope: '<?= e($baScope) ?>' }">

    <form method="POST" action="/sites/<?= e($domain) ?>/security/cloudflare-only"
          class="card overflow-hidden" data-security-card="cloudflare-protection"
          x-data="{ cfo: <?= $cfoEnabled ? 'true' : 'false' ?> }">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="enabled" value="0">
      <div class="p-5 space-y-4">
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-center gap-3">
            <?= icon('cloud-lock', ($cfErrorMessage === '' && $cfEnabled ? 'text-emerald-500' : 'text-zinc-400') . ' text-lg') ?>
            <div>
              <h3 class="text-sm font-semibold text-zinc-800"><?= e(t('site.security.cloudflare.title')) ?></h3>
              <p class="text-xs text-zinc-500 mt-1"><?= e(t('site.security.cloudflare.desc')) ?></p>
            </div>
          </div>
          <span class="badge <?= $cfErrorMessage === '' && $cfEnabled ? 'badge-ok' : 'badge-muted' ?>">
            <?= e($cfErrorMessage !== ''
                ? t('site.security.cloudflare.status_error')
                : ($cfEnabled ? t('site.security.cloudflare.status_active') : t('site.security.cloudflare.status_inactive'))) ?>
          </span>
        </div>

        <?php if ($cfErrorMessage !== ''): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
          <?= e($cfErrorMessage) ?>
        </div>
        <?php elseif ($cfStale): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <?= e(t('site.security.cloudflare.stale')) ?>
        </div>
        <?php endif; ?>

        <p class="text-[11px] leading-relaxed text-zinc-500"><?= e(t('site.security.cloudflare.identity_hint')) ?></p>
      </div>

      <?php if ($cfErrorMessage === ''): ?>
      <div class="grid grid-cols-3 divide-x divide-zinc-100 border-t border-zinc-100">
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.security.cloudflare.source')) ?></p>
          <p class="text-sm font-semibold text-zinc-800"><?= e(ucfirst($cfSource)) ?></p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.security.cloudflare.age')) ?></p>
          <p class="text-sm font-semibold text-zinc-800"><?= e((string) $cfAgeDays) ?> days</p>
        </div>
        <div class="px-5 py-3.5">
          <p class="eyebrow mb-1.5"><?= e(t('site.security.cloudflare.ranges')) ?></p>
          <p class="text-sm font-semibold text-zinc-800"><?= e($cfRanges) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <?php $cfoCanToggle = $cfoDependencyOk || $cfoEnabled; ?>
      <div class="p-5 border-t border-zinc-100 space-y-3" data-security-row="cloudflare-only">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h4 class="text-sm font-semibold text-zinc-800">
              <?= e(t('site.security.cloudflare_only.label')) ?>
              <?php if ($cfoErrorMessage !== ''): ?>
              <span class="badge badge-muted"><span class="dot bg-red-500"></span><?= e(t('site.security.cloudflare.status_error')) ?></span>
              <?php endif; ?>
            </h4>
            <p class="text-xs text-zinc-500 mt-1"><?= e(t('site.security.cloudflare_only.desc')) ?></p>
          </div>
          <label class="inline-flex items-center gap-2 <?= $cfoCanToggle ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>" data-security-toggle="cloudflare-only">
            <span class="text-[11px] font-medium text-zinc-500"
                  x-text="cfo ? '<?= e(t('site.security.enabled')) ?>' : '<?= e(t('site.security.disabled')) ?>'"></span>
            <input type="checkbox" name="enabled" value="1" x-model="cfo" class="sr-only" <?= $cfoCanToggle ? '' : 'disabled' ?>>
            <span aria-hidden="true" :class="cfo ? 'sw-on' : 'sw-off'"><span></span></span>
          </label>
        </div>

        <?php if ($cfoErrorMessage !== ''): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"><?= e($cfoErrorMessage) ?></div>
        <?php endif; ?>

        <?php if (!$cfoDependencyOk): ?>
        <div class="flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200/70 px-3 py-2">
          <?= icon('alert-triangle', 'text-amber-600 mt-0.5 text-sm') ?>
          <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.security.cloudflare_only.requires')) ?></p>
        </div>
        <?php endif; ?>

        <p class="text-[11px] leading-relaxed text-zinc-400"><?= icon('info-circle') ?> <?= e(t('site.security.cloudflare_only.recovery')) ?></p>

        <div class="flex justify-end">
          <button type="submit" class="btn btn-primary" <?= $cfoCanToggle ? '' : 'disabled' ?>>
            <?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.security.save')) ?>
          </button>
        </div>
      </div>
    </form>

    <?php if ($driftMessage !== ''): ?>
    <div class="flex items-start gap-3 rounded-lg border <?= $driftCritical ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50' ?> px-4 py-3">
      <?= icon('alert-triangle', ($driftCritical ? 'text-red-600' : 'text-amber-600') . ' mt-0.5') ?>
      <div>
        <p class="text-sm font-semibold <?= $driftCritical ? 'text-red-800' : 'text-amber-800' ?>"><?= e(t('site.security.drift.title')) ?></p>
        <p class="text-xs <?= $driftCritical ? 'text-red-700' : 'text-amber-700' ?> mt-0.5"><?= e($driftMessage) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$baForceHttps && $baError !== 'force_https_drift'): ?>
    <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
      <?= icon('lock-off', 'text-amber-600 mt-0.5') ?>
      <p class="text-xs text-amber-800 leading-relaxed"><?= e(t('site.security.https_required')) ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="/sites/<?= e($domain) ?>/security/basic-auth"
          class="card overflow-hidden" data-security-card="basic-auth">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="enabled" value="0">

      <div class="card-head">
        <div class="flex items-center gap-2.5">
          <?= icon('shield-lock', 'text-lg', [':class' => "enabled ? 'text-speed' : 'text-zinc-300'"]) ?>
          <div>
            <h2 class="card-title">
              <?= e(t('site.security.access.title')) ?>
              <span class="badge" :class="enabled ? 'badge-ok' : 'badge-muted'">
                <span class="dot" :class="enabled ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                <span x-text="enabled ? '<?= e(t('site.security.enabled')) ?>' : '<?= e(t('site.security.disabled')) ?>'"></span>
              </span>
            </h2>
            <p class="text-[11px] text-zinc-400"><?= e(t('site.security.access.desc')) ?></p>
          </div>
        </div>
        <label class="inline-flex items-center gap-2 cursor-pointer" data-security-toggle="basic-auth">
          <span class="text-[11px] font-medium text-zinc-500"
                x-text="enabled ? '<?= e(t('site.security.enabled')) ?>' : '<?= e(t('site.security.disabled')) ?>'"></span>
          <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only">
          <span aria-hidden="true" :class="enabled ? 'sw-on' : 'sw-off'"><span></span></span>
        </label>
      </div>

      <div class="p-5 space-y-5">
        <div class="space-y-4">
          <div data-security-row="scope">
            <label class="lbl"><?= e(t('site.security.scope_label')) ?></label>
            <select name="scope" class="inp w-full" x-model="scope">
              <option value="wp-login"><?= e(t('site.security.scope.wp_login')) ?></option>
              <option value="custom"><?= e(t('site.security.scope.custom')) ?></option>
              <option value="site"><?= e(t('site.security.scope.site')) ?></option>
            </select>
            <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('site.security.scope_hint')) ?></p>
          </div>

          <div x-show="scope === 'custom'" x-cloak>
            <label class="lbl"><?= e(t('site.security.path_label')) ?></label>
            <input type="text" name="path" value="<?= e((string) ($basicAuthInfo['path'] ?? '')) ?>"
                   class="inp w-full mono" placeholder="/private" maxlength="200"
                   :required="enabled && scope === 'custom'">
            <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('site.security.path_hint')) ?></p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4" data-security-row="credentials">
            <div>
              <label class="lbl"><?= e(t('site.security.username_label')) ?></label>
              <input type="text" name="username" value="<?= e((string) ($basicAuthInfo['username'] ?? '')) ?>"
                     class="inp w-full mono" maxlength="64" autocomplete="username"
                     :required="enabled">
            </div>

            <div>
              <label class="lbl"><?= e(t('site.security.password_label')) ?></label>
              <input type="password" name="password" class="inp w-full mono" maxlength="1024"
                     autocomplete="new-password" spellcheck="false" placeholder="••••••••••••">
              <p class="text-[11px] mt-1 <?= $baHasPassword ? 'text-emerald-600' : 'text-zinc-400' ?>">
                <?= e($baHasPassword ? t('site.security.password_keep') : t('site.security.password_new')) ?>
              </p>
            </div>
          </div>

          <div>
            <label class="lbl"><?= e(t('site.security.bypass_label')) ?></label>
            <textarea name="bypass_ips" rows="3" class="inp w-full mono resize-y"
                      placeholder="203.0.113.10&#10;2001:db8::10"><?= e((string) ($basicAuthInfo['bypass_ips'] ?? '')) ?></textarea>
            <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('site.security.bypass_hint')) ?></p>
          </div>
        </div>

        <div x-show="enabled" x-cloak class="space-y-2">
          <div class="flex items-start gap-2 rounded-lg bg-zinc-50 border border-zinc-200 px-3 py-2">
            <?= icon('database-off', 'text-zinc-500 mt-0.5 text-sm') ?>
            <p class="text-[11px] text-zinc-600 leading-relaxed">
              <span x-show="scope === 'site'"><?= e(t('site.security.cache_site_warning')) ?></span>
              <span x-show="scope !== 'site'" x-cloak><?= e(t('site.security.cache_scoped_warning')) ?></span>
            </p>
          </div>
          <?php if (!$hasTrustedSsl): ?>
          <div class="flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200/70 px-3 py-2">
            <?= icon('certificate-off', 'text-amber-600 mt-0.5 text-sm') ?>
            <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.security.trusted_cert_warning')) ?></p>
          </div>
          <?php endif; ?>
        </div>

        <div class="flex justify-end border-t border-zinc-100 pt-4">
          <button type="submit" class="btn btn-primary">
            <?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.security.save')) ?>
          </button>
        </div>
      </div>
    </form>

<?php
        $ibEnabled = ($ipBlockInfo['enabled'] ?? '0') === '1';
        $ibError   = (string) ($ipBlockInfo['error'] ?? '');
        $ibCount   = (int) ($ipBlockInfo['entry_count'] ?? 0);
        $ibRealip  = ($ipBlockInfo['realip_active'] ?? '0') === '1';
        $ibList    = (string) ($ipBlockList ?? '');
        $ibErrorMap = [
            'busy'                       => t('site.security.ipblock.error.busy'),
            'missing_list'               => t('site.security.ipblock.error.drift'),
            'missing_include'            => t('site.security.ipblock.error.drift'),
            'missing_marker'             => t('site.security.ipblock.error.drift'),
            'marker_drift'               => t('site.security.ipblock.error.drift'),
            'hash_count_mismatch'        => t('site.security.ipblock.error.drift'),
            'generated_content_mismatch' => t('site.security.ipblock.error.drift'),
            'state_mismatch'             => t('site.security.ipblock.error.drift'),
            'status_unavailable'         => t('site.security.ipblock.error.status_unavailable'),
        ];
        $ibErrorMessage = $ibError === '' ? '' : ($ibErrorMap[$ibError] ?? t('site.security.ipblock.error.generic'));
?>
      <form method="POST" action="/sites/<?= e($domain) ?>/security/ip-block"
            class="card overflow-hidden md:col-span-2" data-security-card="ip-blocking"
            x-data="{ enabled: <?= $ibEnabled ? 'true' : 'false' ?> }">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="enabled" value="0">

        <div class="card-head">
          <div class="flex items-center gap-2.5">
            <?= icon('ban', 'text-lg', [':class' => "enabled ? 'text-speed' : 'text-zinc-300'"]) ?>
            <div>
              <h2 class="card-title">
                <?= e(t('site.security.ip_blocking.title')) ?>
                <?php if ($ibErrorMessage !== ''): ?>
                <span class="badge badge-muted"><span class="dot bg-red-500"></span><?= e(t('site.security.ipblock.status_error')) ?></span>
                <?php else: ?>
                <span class="badge" :class="enabled ? 'badge-ok' : 'badge-muted'">
                  <span class="dot" :class="enabled ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                  <span x-text="enabled ? '<?= e(t('site.security.enabled')) ?>' : '<?= e(t('site.security.disabled')) ?>'"></span>
                </span>
                <?php endif; ?>
              </h2>
              <p class="text-[11px] text-zinc-400"><?= e(t('site.security.ip_blocking.desc')) ?></p>
            </div>
          </div>
          <label class="inline-flex items-center gap-2 cursor-pointer" data-security-toggle="ip-blocking">
            <span class="text-[11px] font-medium text-zinc-500"
                  x-text="enabled ? '<?= e(t('site.security.enabled')) ?>' : '<?= e(t('site.security.disabled')) ?>'"></span>
            <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only">
            <span aria-hidden="true" :class="enabled ? 'sw-on' : 'sw-off'"><span></span></span>
          </label>
        </div>

        <div class="p-5 space-y-4">
          <?php if ($ibErrorMessage !== ''): ?>
          <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"><?= e($ibErrorMessage) ?></div>
          <?php endif; ?>

          <?php if (!$ibRealip): ?>
          <div class="flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200/70 px-3 py-2">
            <?= icon('alert-triangle', 'text-amber-600 mt-0.5 text-sm') ?>
            <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.security.ipblock.realip_warning')) ?></p>
          </div>
          <?php endif; ?>

          <div class="space-y-2">
            <label class="lbl"><?= e(t('site.security.ipblock.list_label')) ?></label>
            <textarea name="ips" rows="6" class="inp w-full mono resize-y"
                      placeholder="203.0.113.10&#10;198.51.100.0/24&#10;2001:db8::/32"><?= e($ibList) ?></textarea>
            <p class="text-[11px] text-zinc-400"><?= e(t('site.security.ipblock.list_hint')) ?></p>
            <p class="text-[11px] text-zinc-400"><?= icon('info-circle') ?> <?= e(t('site.security.ipblock.acme_note')) ?></p>
            <?php if ($ibEnabled && $ibErrorMessage === ''): ?>
            <p class="text-[11px] text-zinc-500"><?= e(sprintf(t('site.security.ipblock.count'), $ibCount)) ?></p>
            <?php endif; ?>
          </div>

          <div class="flex justify-end border-t border-zinc-100 pt-4">
            <button type="submit" class="btn btn-primary">
              <?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.security.save')) ?>
            </button>
          </div>
        </div>
      </form>
  </div>

<?php elseif ($activeTab === 'cron'): ?>
<!-- ──────────────── CRON JOBS ──────────────── -->
<?php
  $cronSiteUser = (string) ($site['site_user'] ?? '');
  $cronWebroot  = (string) ($site['webroot'] ?? '');
  $cronPhp      = (string) ($site['php_version'] ?? '');
  $cronIsWp     = ($site['type'] ?? '') === 'wordpress';
  $cronPrefill  = $cronWebroot !== '' ? 'cd ' . $cronWebroot . ' && ' : '';
  $cronRunsAs   = sprintf(t('site.cron.runs_as'), $cronSiteUser ?: '—', $cronWebroot ?: '—', $cronPhp ?: '—');
  // The WordPress preset has its own card below; pull it out of the list + read its interval.
  $wpJob = null;
  foreach ($cronJobs as $cj) { if (($cj['id'] ?? '') === 'wpcron') { $wpJob = $cj; break; } }
  $wpActive   = $wpJob !== null;
  $wpInterval = 5;
  if ($wpActive && preg_match('#^\*/(\d+)$#', (string) strtok((string) ($wpJob['schedule'] ?? ''), ' '), $wm)) { $wpInterval = (int) $wm[1]; }
  $cronUserJobs = array_values(array_filter($cronJobs, static fn($j) => ($j['id'] ?? '') !== 'wpcron'));
?>
  <div class="space-y-4" x-data="cronForm()">

    <?php if ($cronIsWp): ?>
    <!-- WordPress cron preset (tinted banner, top) — single stateful control for the wpcron job -->
    <div class="bg-ink-pale border border-ink/15 rounded-lg px-5 py-3">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2.5 min-w-0">
          <?= icon('brand-wordpress', 'text-ink text-lg shrink-0') ?>
          <div class="min-w-0">
            <h2 class="card-title">
              <?= e(t('site.cron.wp_title')) ?>
              <?php if ($wpActive): ?><span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('site.cron.enabled')) ?></span><?php endif; ?>
            </h2>
            <p class="text-[11px] text-zinc-500 mt-0.5"><?= $wpActive ? e(sprintf(t('site.cron.wp_active'), $wpInterval)) : e(t('site.cron.wp_note')) ?></p>
          </div>
        </div>
        <form method="POST" action="/sites/<?= e($site['domain']) ?>/cron/wp" class="flex items-center gap-2 shrink-0">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <?php if ($wpActive): ?>
            <input type="hidden" name="action" value="disable">
            <button type="submit" class="btn btn-danger btn-sm"><?= icon('player-stop', 'text-sm') ?> <?= e(t('site.cron.wp_disable')) ?></button>
          <?php else: ?>
            <input type="hidden" name="action" value="enable">
            <select name="interval" class="inp text-sm py-1.5">
              <option value="1">1 min</option><option value="5" selected>5 min</option><option value="15">15 min</option><option value="30">30 min</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('bolt', 'text-sm') ?> <?= e(t('site.cron.wp_enable')) ?></button>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Cron jobs -->
    <div class="card">
      <div class="card-head">
        <div class="flex items-center gap-2.5 min-w-0">
          <?= icon('clock-hour-4', 'text-zinc-400 text-lg') ?>
          <div class="min-w-0">
            <h2 class="card-title"><?= e(t('site.cron.title')) ?></h2>
            <p class="text-[11px] text-zinc-400 mt-0.5 truncate"><?= icon('user') ?> <?= e($cronRunsAs) ?></p>
          </div>
        </div>
        <button type="button" @click="openAdd()" class="btn btn-primary btn-sm">
          <?= icon('plus', 'text-sm') ?> <?= e(t('site.cron.add')) ?>
        </button>
      </div>

      <?php if (empty($cronUserJobs)): ?>
      <div class="px-5 py-10 text-center">
        <p class="text-sm font-medium text-zinc-600"><?= e(t('site.cron.empty')) ?></p>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t('site.cron.empty_hint')) ?></p>
      </div>
      <?php else: ?>
      <table class="tbl table-fixed">
        <colgroup>
          <col style="width:26%">
          <col style="width:48%">
          <col style="width:12%">
          <col style="width:14%">
        </colgroup>
        <thead>
          <tr>
            <th class="pl-5"><?= e(t('site.cron.col_schedule')) ?></th>
            <th><?= e(t('site.cron.col_command')) ?></th>
            <th><?= e(t('site.cron.col_status')) ?></th>
            <th class="pr-5 th-center"><?= e(t('site.cron.col_action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cronUserJobs as $job):
            $jid = (string) ($job['id'] ?? ''); $jstate = (string) ($job['state'] ?? 'active');
            $jsched = (string) ($job['schedule'] ?? ''); $jcmd = (string) ($job['command'] ?? '');
            $jjson = htmlspecialchars(json_encode(['id' => $jid, 'schedule' => $jsched, 'command' => $jcmd]), ENT_QUOTES); ?>
          <tr>
            <td class="pl-5">
              <span class="font-medium text-zinc-900" x-text="cronText(<?= htmlspecialchars(json_encode($jsched), ENT_QUOTES) ?>)"><?= e($jsched) ?></span>
              <span class="block text-[11px] text-zinc-400 mono"><?= e($jsched) ?></span>
            </td>
            <td class="mono text-[13px] text-zinc-700 break-all"><?= e($jcmd) ?></td>
            <td>
              <form method="POST" action="/sites/<?= e($site['domain']) ?>/cron/toggle">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="id" value="<?= e($jid) ?>">
                <input type="hidden" name="state" value="<?= $jstate === 'active' ? 'off' : 'on' ?>">
                <button type="submit" class="<?= $jstate === 'active' ? 'sw-on' : 'sw-off' ?>" title="<?= $jstate === 'active' ? e(t('site.cron.click_off')) : e(t('site.cron.click_on')) ?>"><span></span></button>
              </form>
            </td>
            <td class="pr-5 text-center">
              <div class="relative inline-block text-left" x-data="{ row:false }" @click.outside="row=false">
                <button type="button" @click="row=!row" aria-label="<?= e(t('common.actions')) ?>" class="w-8 h-8 rounded-lg hover:bg-zinc-100 text-zinc-400 hover:text-zinc-700"><?= icon('dots-vertical') ?></button>
                <div x-show="row" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-44 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30 text-left">
                  <button type="button" @click="row=false; openEdit(<?= $jjson ?>)" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 text-left"><?= icon('edit', 'text-sm text-zinc-400') ?> <?= e(t('site.cron.edit')) ?></button>
                  <button type="button" @click="row=false; openDel(<?= $jjson ?>)" class="w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left"><?= icon('trash', 'text-sm') ?> <?= e(t('site.cron.delete')) ?></button>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if ($cronManualCount > 0): ?>
      <p class="px-5 py-2 text-[11px] text-zinc-400 border-t border-zinc-100"><?= icon('info-circle') ?> <?= e(sprintf(t('site.cron.manual_note'), $cronManualCount)) ?></p>
      <?php endif; ?>
    </div>

    <!-- Modal: Add / Edit cron job (teleported to body so the overlay covers the full viewport) -->
    <template x-teleport="body">
    <div x-show="modal==='form'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('clock-hour-4', 'text-zinc-400') ?> <span x-text="editId ? <?= htmlspecialchars(json_encode(t('site.cron.edit')), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('site.cron.add')), ENT_QUOTES) ?>"></span></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" :action="'/sites/<?= e($site['domain']) ?>/cron/' + (editId ? 'update' : 'add')" class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="id" :value="editId">
          <div>
            <label class="lbl"><?= e(t('site.cron.preset_label')) ?> <span class="text-red-500">*</span></label>
            <select class="inp w-full" x-model="preset" @change="applyPreset()">
              <option value="* * * * *"><?= e(t('site.cron.p_every_min')) ?></option>
              <option value="*/5 * * * *"><?= e(t('site.cron.p_5min')) ?></option>
              <option value="*/15 * * * *"><?= e(t('site.cron.p_15min')) ?></option>
              <option value="0 * * * *"><?= e(t('site.cron.p_hourly')) ?></option>
              <option value="0 0 * * *"><?= e(t('site.cron.p_daily')) ?></option>
              <option value="0 0 * * 0"><?= e(t('site.cron.p_weekly')) ?></option>
              <option value="0 0 1 * *"><?= e(t('site.cron.p_monthly')) ?></option>
              <option value="custom"><?= e(t('site.cron.p_custom')) ?></option>
            </select>
          </div>
          <div class="grid grid-cols-5 gap-2">
            <?php foreach (['m' => 'Min', 'h' => 'Hour', 'dom' => 'Day', 'mon' => 'Month', 'dow' => 'Wkday'] as $f => $lbl): ?>
            <div>
              <label class="block text-[10px] text-zinc-400 mb-1"><?= $lbl ?> <span class="text-red-500">*</span></label>
              <input type="text" name="<?= $f ?>" x-model="<?= $f ?>" required class="inp w-full text-center mono">
            </div>
            <?php endforeach; ?>
          </div>
          <p class="text-[11px] text-zinc-500"><?= icon('clock') ?> <?= e(t('site.cron.preview')) ?>: <span class="font-medium text-zinc-700" x-text="cronText(m+' '+h+' '+dom+' '+mon+' '+dow)"></span></p>
          <div>
            <label class="lbl"><?= e(t('site.cron.command_label')) ?> <span class="text-red-500">*</span></label>
            <input type="text" name="command" x-model="command" required class="inp w-full mono" autocomplete="off" spellcheck="false" placeholder="<?= e($cronPrefill) ?>your-script.sh">
            <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('site.cron.one_line')) ?></p>
          </div>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-primary"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.cron.save')) ?></button>
          </div>
        </form>
      </div>
    </div>
    </template>

    <!-- Modal: Delete cron job (teleported to body) -->
    <template x-teleport="body">
    <div x-show="modal==='del'" x-cloak class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-zinc-900/40"></div>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
        <div class="card-head flex items-center justify-between">
          <h3 class="card-title"><?= icon('trash', 'text-red-500') ?> <?= e(t('site.cron.delete')) ?></h3>
          <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form method="POST" action="/sites/<?= e($site['domain']) ?>/cron/delete" class="p-5 space-y-3">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="id" :value="delId">
          <p class="text-sm text-zinc-600"><?= e(t('site.cron.delete_confirm')) ?></p>
          <p class="text-[11px] text-zinc-500 mono break-all bg-zinc-50 border border-zinc-100 rounded px-3 py-2" x-text="delCmd"></p>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
            <button type="submit" class="btn btn-danger"><?= icon('trash', 'text-sm') ?> <?= e(t('site.cron.delete')) ?></button>
          </div>
        </form>
      </div>
    </div>
    </template>

  </div>

  <script>
  function cronForm() {
    return {
      modal: null, editId: '', command: <?= json_encode($cronPrefill) ?>,
      m: '*', h: '*', dom: '*', mon: '*', dow: '*', preset: '* * * * *',
      delId: '', delCmd: '',
      applyPreset() {
        if (this.preset === 'custom') return;
        var p = this.preset.split(' ');
        this.m = p[0]; this.h = p[1]; this.dom = p[2]; this.mon = p[3]; this.dow = p[4];
      },
      openAdd() {
        this.editId = ''; this.command = <?= json_encode($cronPrefill) ?>;
        this.preset = '* * * * *'; this.applyPreset(); this.modal = 'form';
      },
      openEdit(j) {
        this.editId = j.id; this.command = j.command; this.preset = 'custom';
        var p = (j.schedule || '').split(' ');
        this.m = p[0] || '*'; this.h = p[1] || '*'; this.dom = p[2] || '*'; this.mon = p[3] || '*'; this.dow = p[4] || '*';
        this.modal = 'form';
      },
      openDel(j) { this.delId = j.id; this.delCmd = j.command; this.modal = 'del'; }
    };
  }
  function cronText(expr) {
    var p = (expr || '').trim().split(/\s+/);
    if (p.length !== 5) return expr;
    var m = p[0], h = p[1], dom = p[2], mon = p[3], dow = p[4];
    var num = function (x) { return /^[0-9]+$/.test(x); };
    var pad = function (x) { return ('0' + x).slice(-2); };
    if (expr === '* * * * *') return 'Every minute';
    if (m.indexOf('*/') === 0 && h === '*' && dom === '*' && mon === '*' && dow === '*') return 'Every ' + m.slice(2) + ' minutes';
    if (h === '*' && dom === '*' && mon === '*' && dow === '*' && num(m)) return 'Hourly at :' + pad(m);
    if (dom === '*' && mon === '*' && dow === '*' && num(m) && num(h)) return 'Daily at ' + pad(h) + ':' + pad(m);
    if (dom === '*' && mon === '*' && num(dow) && num(m) && num(h)) return 'Weekly (day ' + dow + ') at ' + pad(h) + ':' + pad(m);
    if (mon === '*' && num(dom) && num(m) && num(h)) return 'Monthly (day ' + dom + ') at ' + pad(h) + ':' + pad(m);
    return expr;
  }
  </script>

<?php else: ?>
<!-- ──────────────── FILES ──────────────── -->

  <div class="card px-8 py-16 text-center max-w-lg mx-auto">
    <?php
    $tabIcons = ['ssl' => 'ti-lock', 'database' => 'ti-database', 'security' => 'ti-shield-lock',
                 'cron' => 'ti-clock-hour-4', 'files' => 'ti-folder'];
    $tabIcon = $tabIcons[$activeTab] ?? 'ti-tool';
    ?>
    <?= icon($tabIcon, 'text-5xl text-zinc-200 block mb-3') ?>
    <p class="text-sm font-semibold text-zinc-700 mb-1"><?= e($tabs[$activeTab]['label'] ?? '') ?></p>
    <p class="text-xs text-zinc-400"><?= e(t('site.tab.soon')) ?></p>
  </div>

<?php endif; ?>

</main>

<script>
function phpSwitchForm() {
  return {
    submitting: false,
    confirmVer: null,   // set to the version string when an in-app confirm is needed
    onSubmit(e) {
      e.preventDefault();                  // always stream
      var opt = this._selectedNeedsInstall();
      if (opt && !this.confirmVer) {
        this.confirmVer = opt.value;
        return;
      }
      this._stream();
    },
    proceed() {
      this.confirmVer = null;
      this._stream();
    },
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
    },
    _selectedNeedsInstall() {
      var sel = this.$root.querySelector('select[data-phpselect]');
      if (!sel) return null;
      var opt = sel.options[sel.selectedIndex];
      return (opt && opt.getAttribute('data-needs-install') === '1') ? opt : null;
    }
  };
}
</script>

<script>
// Object Cache live metrics — async, non-blocking; fills the Keys/Memory tiles.
(function () {
  var box = document.querySelector('[data-oc-metrics]');
  if (!box) return;
  var domain = box.getAttribute('data-domain') || '';
  var kEl = box.querySelector('[data-oc-keys]');
  var mEl = box.querySelector('[data-oc-memory]');
  var dash = function (el) { if (el) el.textContent = '—'; };
  fetch('/api/cache/object-metrics?domain=' + encodeURIComponent(domain), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) { dash(kEl); dash(mEl); return; }
      if (kEl) {
        var n = parseInt(d.keys, 10);
        kEl.textContent = isNaN(n) ? '—' : (n.toLocaleString() + (d.limited ? '+' : ''));
      }
      if (mEl) mEl.textContent = (d.memory && d.memory !== 'unknown') ? d.memory : '—';
    })
    .catch(function () { dash(kEl); dash(mEl); });
})();
</script>
