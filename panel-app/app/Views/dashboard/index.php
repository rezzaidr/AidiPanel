<?php
/**
 * Dashboard (Fase 2 v2) — CloudPanel/Cloudflare-grade monitoring.
 *
 * Data sources:
 *   Monitoring charts  REAL history from the `metrics` table (bin/collect-metrics.php,
 *                      one sample/minute) — fetched per selected time range via
 *                      /api/metrics/history. /proc keeps no history, so we record it.
 *   $analytics         real traffic & cache stats parsed from the Nginx access log
 *                      (hit ratio, cached vs origin, bandwidth saved, per-minute series).
 *   $metrics           live snapshot, used here only for capacity (disk total).
 *   $alerts            "Needs Attention" advisor. $vps server identity. $sites top sites.
 */
$pageTitle = t('nav.dashboard');

$appIcon = static function (string $type): string {
    return [
        'wordpress' => 'ti-brand-wordpress',
        'laravel'   => 'ti-brand-laravel',
        'php'       => 'ti-brand-php',
        'node'      => 'ti-brand-nodejs',
        'nodejs'    => 'ti-brand-nodejs',
        'python'    => 'ti-brand-python',
        'static'    => 'ti-file-text',
        'proxy'     => 'ti-arrow-guide',
    ][strtolower($type)] ?? 'ti-world';
};

$osLower = strtolower($vps['os'] ?? '');
$osIcon  = str_contains($osLower, 'ubuntu') ? 'ti-brand-ubuntu'
         : (str_contains($osLower, 'debian') ? 'ti-hexagon-letter-d' : 'ti-device-laptop');

// Compact number formatter (1240000 → 1.24M, 11700 → 11.7k)
$compact = static function ($n): string {
    $n = (float) $n;
    if ($n >= 1e6) return rtrim(rtrim(number_format($n / 1e6, 2), '0'), '.') . 'M';
    if ($n >= 1e3) return rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.') . 'k';
    return number_format($n);
};

$cacheable  = (int) ($analytics['cached'] ?? 0) + (int) ($analytics['origin'] ?? 0);
$hasSeries  = !empty($analytics['series']['labels']);   // cacheable traffic to plot
$memTotal   = format_bytes((int) ($vps['mem_total'] ?? 0));
$diskTotal  = format_bytes((int) ($metrics['disk']['total'] ?? 0));
$coreLabel  = $vps['cores'] ? ($vps['cores'] . ' CPU') : '—';

// Monitoring time ranges (value = key understood by /api/metrics/history)
$ranges = [
    '30m' => 'dash.range.30m',
    '1h'  => 'dash.range.1h',
    '3h'  => 'dash.range.3h',
    '6h'  => 'dash.range.6h',
    '12h' => 'dash.range.12h',
];
$range = $history['range'] ?? '1h';
?>

<!-- page header -->
<div class="flex items-center justify-between mb-5">
  <div>
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('nav.dashboard')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('dash.subtitle')) ?></p>
  </div>
</div>

<!-- VPS status -->
<div class="card p-5 mb-5">
  <div class="flex items-center justify-between mb-4">
    <h2 class="card-title"><i class="ti ti-server-2 text-ink" aria-hidden="true"></i> <?= e(t('vps.title')) ?></h2>
    <div class="flex items-center gap-3">
      <?php if (!empty($vps['uptime'])): ?>
        <span class="text-[11px] text-zinc-400"><?= e(t('vps.uptime')) ?> <span class="mono text-zinc-600"><?= e($vps['uptime']) ?></span></span>
      <?php endif; ?>
      <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('common.online')) ?></span>
    </div>
  </div>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-4">
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.os')) ?></p>
      <p class="text-sm font-medium text-zinc-800 flex items-center gap-1.5"><i class="ti <?= e($osIcon) ?> text-zinc-400 text-base shrink-0" aria-hidden="true"></i> <?= e($vps['os']) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.hostname')) ?></p>
      <p class="text-sm font-medium text-zinc-800 mono"><?= e($vps['hostname']) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.cpu')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $vps['cores'] ? e(t('vps.cores', ['n' => $vps['cores']])) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.memory')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= e($memTotal) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.cloud')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $vps['provider'] ? '<i class="ti ti-cloud text-sky-500" aria-hidden="true"></i> ' . e($vps['provider']) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.droplet')) ?></p>
      <p class="text-sm font-medium text-zinc-800 mono"><?= $vps['droplet_id'] ? e($vps['droplet_id']) : '<span class="text-zinc-300 font-sans">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.region')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $vps['region'] ? e(strtoupper((string) $vps['region'])) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.ipv4')) ?></p>
      <?php if ($vps['ipv4']): ?>
      <div class="flex items-center gap-1.5">
        <p class="text-sm font-medium text-zinc-800 mono"><?= e($vps['ipv4']) ?></p>
        <button onclick="navigator.clipboard.writeText('<?= e($vps['ipv4']) ?>');this.innerHTML='<i class=\'ti ti-check text-emerald-500 text-sm\'></i>';setTimeout(()=>this.innerHTML='<i class=\'ti ti-copy text-sm\'></i>',1500)"
                title="Copy IP" class="text-zinc-300 hover:text-zinc-500 transition-colors p-0.5 rounded"><i class="ti ti-copy text-sm" aria-hidden="true"></i></button>
      </div>
      <?php else: ?>
      <span class="text-zinc-300">—</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- KPI row — performance-led -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <!-- Requests -->
  <div class="card p-4">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-arrow-bounce text-ink" aria-hidden="true"></i> <?= e(t('dash.kpi.requests')) ?> <span class="text-zinc-300 font-normal">· <?= e(t('dash.kpi.requests_hint')) ?></span></span>
    </div>
    <div class="mono font-bold text-[26px] text-zinc-900 leading-none mt-2"><?= e($compact($analytics['req_today'] ?? 0)) ?></div>
    <div id="spkReq" class="mt-1 -mb-1"></div>
  </div>
  <!-- Cache hit ratio -->
  <div class="card p-4">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-bolt text-speed" aria-hidden="true"></i> <?= e(t('dash.kpi.hit_ratio')) ?></span>
      <span class="badge badge-info text-[10px] px-1.5 py-0.5">FastCGI</span>
    </div>
    <div class="mono font-bold text-[26px] text-speed leading-none mt-2">
      <?php if ($cacheable > 0): ?><?= e(number_format((float) $analytics['hit_ratio'], 1)) ?><span class="text-zinc-300 text-lg">%</span><?php else: ?><span class="text-zinc-300">—</span><?php endif; ?>
    </div>
    <p class="text-[11px] text-zinc-400 mt-2">
      <?= $cacheable > 0
        ? e(t('dash.kpi.hit_ratio_hint', ['cached' => $compact($analytics['cached']), 'total' => $compact($cacheable)]))
        : e(t('dash.kpi.hit_ratio_empty')) ?>
    </p>
  </div>
  <!-- Bandwidth saved -->
  <div class="card p-4">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-arrow-down-circle text-emerald-500" aria-hidden="true"></i> <?= e(t('dash.kpi.bw_saved')) ?></span>
    </div>
    <div class="mono font-bold text-[26px] text-zinc-900 leading-none mt-2"><?= e(format_bytes((int) ($analytics['bw_saved'] ?? 0))) ?></div>
    <p class="text-[11px] text-zinc-400 mt-2"><?= e(t('dash.kpi.bw_saved_hint')) ?></p>
  </div>
  <!-- Data cached -->
  <div class="card p-4">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-database text-ink" aria-hidden="true"></i> <?= e(t('dash.kpi.data_cached')) ?></span>
    </div>
    <div class="mono font-bold text-[26px] text-zinc-900 leading-none mt-2"><?= e($analytics['cache_size'] ?? '—') ?></div>
    <p class="text-[11px] text-zinc-400 mt-2"><?= e(t('dash.kpi.data_cached_hint')) ?></p>
  </div>
</div>

<!-- traffic analytics (cache effectiveness) -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
  <div class="card lg:col-span-2 overflow-hidden">
    <div class="card-head">
      <h2 class="card-title"><i class="ti ti-chart-area-line text-ink" aria-hidden="true"></i> <?= e(t('dash.traffic.title')) ?> <span class="text-zinc-300 font-sans font-normal text-xs">· <?= e(t('dash.traffic.window')) ?></span></h2>
      <?php if ($hasSeries): ?>
      <div class="flex items-center gap-4">
        <span class="flex items-center gap-1.5 text-[11px] text-zinc-500"><span class="w-2.5 h-2.5 rounded-sm bg-speed"></span> <?= e(t('dash.traffic.cached')) ?></span>
        <span class="flex items-center gap-1.5 text-[11px] text-zinc-500"><span class="w-2.5 h-2.5 rounded-sm bg-ink"></span> <?= e(t('dash.traffic.origin')) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($hasSeries): ?>
      <div class="px-3 pt-3"><div id="chartTraffic"></div></div>
    <?php else: ?>
      <div class="flex flex-col items-center justify-center text-center px-4 py-16">
        <i class="ti ti-chart-area-line text-4xl text-zinc-200 mb-2" aria-hidden="true"></i>
        <p class="text-sm text-zinc-400"><?= e(t('dash.traffic.empty')) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <div class="card overflow-hidden flex flex-col">
    <div class="card-head"><h2 class="card-title"><i class="ti ti-rocket text-speed" aria-hidden="true"></i> <?= e(t('dash.cacheperf.title')) ?></h2></div>
    <?php if ($cacheable > 0): ?>
      <div class="px-4 pt-2"><div id="chartHit"></div></div>
      <div class="px-4 pb-4 mt-1 space-y-2 text-xs">
        <div class="flex items-center justify-between"><span class="flex items-center gap-2 text-zinc-500"><span class="w-2 h-2 rounded-full bg-speed"></span> <?= e(t('dash.cacheperf.served')) ?></span><span class="mono font-semibold text-zinc-800"><?= e($compact($analytics['cached'])) ?></span></div>
        <div class="flex items-center justify-between"><span class="flex items-center gap-2 text-zinc-500"><span class="w-2 h-2 rounded-full bg-ink"></span> <?= e(t('dash.cacheperf.origin')) ?></span><span class="mono font-semibold text-zinc-800"><?= e($compact($analytics['origin'])) ?></span></div>
        <div class="flex items-center justify-between pt-2 border-t border-zinc-100"><span class="flex items-center gap-2 text-zinc-500"><i class="ti ti-arrow-down-circle text-emerald-500" aria-hidden="true"></i> <?= e(t('dash.cacheperf.saved')) ?></span><span class="mono font-semibold text-emerald-600"><?= e(format_bytes((int) $analytics['bw_saved'])) ?></span></div>
      </div>
    <?php else: ?>
      <div class="flex-1 flex flex-col items-center justify-center text-center px-4 py-12">
        <span class="w-10 h-10 rounded-full bg-speed-pale flex items-center justify-center mb-2"><i class="ti ti-rocket text-speed text-xl" aria-hidden="true"></i></span>
        <p class="text-sm text-zinc-400"><?= e(t('dash.cacheperf.empty')) ?></p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- monitoring (real history per selected range) -->
<div class="flex items-center justify-between mb-2.5 mt-1">
  <h2 class="font-head font-semibold text-sm text-zinc-700 flex items-center gap-2"><i class="ti ti-chart-line text-ink" aria-hidden="true"></i> <?= e(t('dash.system.title')) ?></h2>
  <select id="rangeSelect" onchange="location.href='/dashboard?range=' + encodeURIComponent(this.value)"
          class="text-xs font-medium text-zinc-700 bg-white border border-zinc-200 hover:border-zinc-300 rounded-lg pl-3 pr-2.5 py-2 cursor-pointer focus:outline-none focus:border-ink">
    <?php foreach ($ranges as $val => $key): ?>
      <option value="<?= e($val) ?>" <?= $val === $range ? 'selected' : '' ?>><?= e(t($key)) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-5">
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-cpu text-ink" aria-hidden="true"></i> <?= e(t('chart.cpu')) ?></span>
      <span class="mono text-sm font-semibold text-zinc-500"><?= $vps['cores'] ? e(t('vps.cores', ['n' => $vps['cores']])) : '—' ?></span>
    </div>
    <div id="chartCpu"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-cpu-2 text-speed" aria-hidden="true"></i> <?= e(t('chart.memory')) ?></span>
      <span class="mono text-sm font-semibold text-zinc-500"><?= e($memTotal) ?></span>
    </div>
    <div id="chartMem"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-activity text-amber-500" aria-hidden="true"></i> <?= e(t('chart.load')) ?></span>
      <span class="mono text-sm font-semibold text-zinc-500"><?= e($coreLabel) ?></span>
    </div>
    <div id="chartLoad"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-device-floppy text-amber-500" aria-hidden="true"></i> <?= e(t('chart.disk')) ?></span>
      <span class="mono text-sm font-semibold text-zinc-500"><?= e($diskTotal) ?></span>
    </div>
    <div id="chartDisk"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-arrows-up-down text-emerald-500" aria-hidden="true"></i> <?= e(t('chart.network')) ?></span>
      <span class="mono font-semibold text-xs text-zinc-700"><span class="text-emerald-600">↓<span id="vNetIn">0</span></span> <span class="text-ink">↑<span id="vNetOut">0</span></span> <span class="text-zinc-300">Mbps</span></span>
    </div>
    <div id="chartNet"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-database-cog text-speed" aria-hidden="true"></i> <?= e(t('chart.diskio')) ?></span>
      <span class="mono font-semibold text-xs text-zinc-700"><span class="text-speed">R <span id="vDioR">0</span></span> <span class="text-violet-600">W <span id="vDioW">0</span></span> <span class="text-zinc-300">MB/s</span></span>
    </div>
    <div id="chartDio"></div>
  </div>
</div>

<!-- top sites + needs attention -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="card lg:col-span-2 overflow-hidden">
    <div class="card-head">
      <h2 class="card-title"><i class="ti ti-flame text-amber-500" aria-hidden="true"></i> <?= e(t('dash.top_sites')) ?> <span class="text-zinc-300 font-sans font-normal text-xs">· <?= e(t('dash.top_sites.hint')) ?></span></h2>
      <a href="/sites" class="btn btn-sm btn-ghost"><?= e(t('sites.view_all')) ?> →</a>
    </div>
    <?php if (empty($sites)): ?>
      <div class="px-6 py-12 text-center">
        <i class="ti ti-world text-4xl text-zinc-200 block mb-2" aria-hidden="true"></i>
        <p class="text-sm font-medium text-zinc-600"><?= e(t('sites.empty')) ?></p>
        <a href="/sites/add" class="btn btn-primary btn-sm mt-3 inline-flex"><i class="ti ti-plus text-sm" aria-hidden="true"></i> <?= e(t('sites.add_first')) ?></a>
      </div>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th><?= e(t('col.domain')) ?></th>
            <th><?= e(t('col.app')) ?></th>
            <th><?= e(t('col.ssl')) ?></th>
            <th><?= e(t('col.requests')) ?></th>
            <th class="text-right"><?= e(t('col.action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sites as $site): ?>
          <tr>
            <td>
              <a href="/sites/<?= e($site['domain']) ?>" class="flex items-center gap-2.5 font-medium text-zinc-900 hover:text-ink">
                <span class="w-8 h-8 rounded-lg bg-ink-pale flex items-center justify-center shrink-0"><i class="ti <?= e($appIcon($site['type'])) ?> text-ink" aria-hidden="true"></i></span>
                <?= e($site['domain']) ?>
              </a>
            </td>
            <td><span class="text-xs text-zinc-700"><?= e(ucfirst($site['type'])) ?></span> <span class="mono text-[11px] text-zinc-400">· PHP <?= e($site['php_version']) ?></span></td>
            <td>
              <?php if (($site['ssl_type'] ?? '') === 'letsencrypt'): ?>
                <span class="badge badge-ok"><i class="ti ti-lock-check text-xs" aria-hidden="true"></i> <?= e(t('ssl.le')) ?></span>
              <?php else: ?>
                <span class="badge badge-muted"><?= e(t('ssl.self')) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="mono text-sm font-medium text-zinc-700"><?= e($compact($site['req_today'])) ?></span></td>
            <td class="text-right"><a href="/sites/<?= e($site['domain']) ?>" class="btn btn-sm btn-ghost"><?= e(t('action.manage')) ?></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card overflow-hidden flex flex-col">
    <div class="card-head">
      <h2 class="card-title"><i class="ti ti-bell text-amber-500" aria-hidden="true"></i> <?= e(t('dash.attention')) ?></h2>
      <?php if (!empty($alerts)): ?><span class="badge badge-warn"><?= count($alerts) ?></span><?php endif; ?>
    </div>
    <?php if (empty($alerts)): ?>
      <div class="flex-1 flex flex-col items-center justify-center text-center px-4 py-12">
        <span class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center mb-2"><i class="ti ti-circle-check text-emerald-500 text-xl" aria-hidden="true"></i></span>
        <p class="text-sm text-zinc-500"><?= e(t('dash.allclear')) ?></p>
      </div>
    <?php else: ?>
      <?php $alertStyle = ['danger' => 'bg-red-50 text-red-500', 'warn' => 'bg-amber-50 text-amber-500', 'info' => 'bg-sky-50 text-sky-500']; ?>
      <div class="divide-y divide-zinc-50 flex-1">
        <?php foreach ($alerts as $a): ?>
          <div class="flex items-start gap-2.5 px-4 py-3">
            <span class="w-6 h-6 rounded-md flex items-center justify-center shrink-0 mt-0.5 <?= $alertStyle[$a['level']] ?? $alertStyle['info'] ?>"><i class="ti <?= e($a['icon']) ?> text-sm" aria-hidden="true"></i></span>
            <p class="text-xs text-zinc-700 leading-snug flex-1"><?= e($a['text']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function () {
  const $ = s => document.querySelector(s);

  // ── shared chart options (crisp, flat, crosshair tooltips) ────────────────
  function opts(colors, type, yMax, legend, suffix) {
    suffix = suffix || '';
    return {
      chart: { type, height: 130, toolbar: { show: false }, parentHeightOffset: 0, fontFamily: '"Plus Jakarta Sans"', animations: { enabled: false } },
      stroke: { curve: 'smooth', width: type === 'line' ? 2.5 : 2 },
      colors, fill: { type: 'solid', opacity: type === 'line' ? 1 : 0.12 },
      dataLabels: { enabled: false },
      markers: { size: 3, strokeWidth: 0, hover: { size: 5 } },
      legend: { show: legend, position: 'top', horizontalAlign: 'right', fontSize: '11px', fontWeight: 500, labels: { colors: '#71717a' }, markers: { width: 8, height: 8, radius: 4 }, itemMargin: { horizontal: 8 }, offsetY: -2 },
      grid: { borderColor: '#f1f1f4', strokeDashArray: 4, padding: { left: 8, right: 14, top: 4, bottom: -4 }, xaxis: { lines: { show: false } } },
      xaxis: { categories: [], tickAmount: 7, crosshairs: { show: true, stroke: { color: '#d4d4d8', width: 1, dashArray: 3 } }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false }, labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' } } },
      yaxis: { max: yMax, min: 0, tickAmount: 4, labels: { style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' }, formatter: v => (Math.round(v * 10) / 10) + suffix } },
      tooltip: { theme: 'light', shared: true, intersect: false, x: { show: true }, y: { formatter: v => (Math.round(v * 10) / 10) + suffix } },
    };
  }

  // History is embedded server-side for the selected range (CloudPanel-style):
  // the dropdown reloads /dashboard?range=… and PHP renders the matching window,
  // so the x-axis labels and hover tooltips always have real data to show.
  const H = <?= json_encode($history, JSON_UNESCAPED_SLASHES) ?>;
  const setT = (id, v) => { const el = $('#' + id); if (el) el.textContent = v; };
  const last = a => (a && a.length) ? a[a.length - 1] : 0;

  function build(sel, colors, type, yMax, legend, suffix, series) {
    const o = opts(colors, type, yMax, legend, suffix);
    o.xaxis.categories = H.labels;
    o.series = series;
    new ApexCharts($(sel), o).render();
  }
  build('#chartCpu',  ['#322C7A'], 'area', 100, false, '%', [{ name: 'CPU', data: H.cpu }]);
  build('#chartMem',  ['#0891B2'], 'area', 100, false, '%', [{ name: 'Memory', data: H.mem }]);
  build('#chartLoad', ['#322C7A', '#0891B2', '#F59E0B'], 'line', undefined, true, '', [{ name: '1m', data: H.l1 }, { name: '5m', data: H.l5 }, { name: '15m', data: H.l15 }]);
  build('#chartDisk', ['#F59E0B'], 'area', 100, false, '%', [{ name: 'Disk', data: H.disk }]);
  build('#chartNet',  ['#10B981', '#322C7A'], 'area', undefined, true, ' Mb', [{ name: 'In', data: H.nin }, { name: 'Out', data: H.nout }]);
  build('#chartDio',  ['#0891B2', '#7C3AED'], 'area', undefined, true, ' MB', [{ name: 'Read', data: H.dr }, { name: 'Write', data: H.dw }]);
  setT('vNetIn', last(H.nin)); setT('vNetOut', last(H.nout));
  setT('vDioR', last(H.dr));   setT('vDioW', last(H.dw));

  // ── traffic & cache analytics (real, from the Nginx access log) ────────────
  <?php if ($hasSeries): ?>
  (function () {
    const tl = <?= json_encode($analytics['series']['labels'], JSON_UNESCAPED_SLASHES) ?>;
    const tc = <?= json_encode(array_map('intval', $analytics['series']['cached']), JSON_UNESCAPED_SLASHES) ?>;
    const to = <?= json_encode(array_map('intval', $analytics['series']['origin']), JSON_UNESCAPED_SLASHES) ?>;
    new ApexCharts($('#chartTraffic'), {
      chart: { type: 'area', height: 212, stacked: true, toolbar: { show: false }, parentHeightOffset: 0, fontFamily: '"Plus Jakarta Sans"', animations: { enabled: true, speed: 500 } },
      stroke: { curve: 'smooth', width: 2 }, colors: ['#0891B2', '#322C7A'],
      fill: { type: 'solid', opacity: 0.85 }, dataLabels: { enabled: false }, markers: { size: 0, hover: { size: 4 } },
      legend: { show: false },
      grid: { borderColor: '#f1f1f4', strokeDashArray: 4, padding: { left: 8, right: 14, top: 4, bottom: -4 }, xaxis: { lines: { show: false } } },
      xaxis: { categories: tl, tickAmount: 8, crosshairs: { show: true, stroke: { color: '#d4d4d8', width: 1, dashArray: 3 } }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false }, labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' } } },
      yaxis: { min: 0, tickAmount: 4, labels: { style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' }, formatter: v => Math.round(v) } },
      tooltip: { theme: 'light', shared: true, intersect: false, x: { show: true }, y: { formatter: v => Math.round(v) + ' req' } },
      series: [{ name: <?= json_encode(t('dash.traffic.cached')) ?>, data: tc }, { name: <?= json_encode(t('dash.traffic.origin')) ?>, data: to }],
    }).render();

    const totals = tl.map((_, i) => (tc[i] || 0) + (to[i] || 0));
    const spk = $('#spkReq');
    if (spk) new ApexCharts(spk, { chart: { type: 'area', height: 38, sparkline: { enabled: true } }, series: [{ name: 'Req', data: totals }], stroke: { curve: 'smooth', width: 2 }, colors: ['#322C7A'], fill: { type: 'solid', opacity: 0.12 }, tooltip: { enabled: false } }).render();
  })();
  <?php endif; ?>

  <?php if ($cacheable > 0): ?>
  new ApexCharts($('#chartHit'), {
    chart: { type: 'radialBar', height: 212, sparkline: { enabled: true }, fontFamily: '"Plus Jakarta Sans"' },
    series: [<?= (float) $analytics['hit_ratio'] ?>], colors: ['#0891B2'], labels: [<?= json_encode(t('dash.cacheperf.hit')) ?>], stroke: { lineCap: 'round' },
    plotOptions: { radialBar: { hollow: { size: '60%' }, track: { background: '#E0F7FB', strokeWidth: '100%' },
      dataLabels: { name: { show: true, offsetY: 20, color: '#a1a1aa', fontSize: '11px', fontWeight: 600 },
        value: { show: true, offsetY: -10, color: '#18181b', fontSize: '32px', fontWeight: 700, fontFamily: '"JetBrains Mono"', formatter: v => (Math.round(v * 10) / 10) + '%' } } } },
  }).render();
  <?php endif; ?>
})();
</script>
