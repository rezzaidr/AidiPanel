<?php
/**
 * Dashboard — monitoring & server status (Fase 2 §5.1).
 * v1 wires only real data: VPS facts, LIVE system charts (polled every 5s),
 * services, a computed "Needs Attention" advisor, and a real sites snapshot.
 * KPI tiles / network / per-site resource / SSL-OS advisor land in later slices
 * once their telemetry exists — no placeholder numbers here.
 */
$pageTitle = t('nav.dashboard');

/** small helpers for the sites snapshot */
$appIcon = function (string $type): string {
    return [
        'wordpress' => 'ti-brand-wordpress',
        'php'       => 'ti-brand-php',
        'node'      => 'ti-brand-nodejs',
        'nodejs'    => 'ti-brand-nodejs',
        'python'    => 'ti-brand-python',
        'static'    => 'ti-file-text',
        'proxy'     => 'ti-arrow-guide',
    ][strtolower($type)] ?? 'ti-world';
};
$dash = fn($v) => ($v === null || $v === '') ? null : $v;
?>

<!-- page header -->
<div class="flex items-center justify-between mb-5">
  <div>
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('nav.dashboard')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('dash.subtitle')) ?></p>
  </div>
  <span class="badge badge-muted"><span class="dot bg-emerald-500"></span> <?= e(t('dash.live')) ?></span>
</div>

<!-- VPS status -->
<div class="card p-5 mb-5">
  <div class="flex items-center justify-between mb-4">
    <h2 class="card-title"><i class="ti ti-server-2 text-ink"></i> <?= e(t('vps.title')) ?></h2>
    <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('common.online')) ?></span>
  </div>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-4">
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.os')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= e($vps['os']) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.hostname')) ?></p>
      <p class="text-sm font-medium text-zinc-800 mono"><?= e($vps['hostname']) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.cpu')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $dash($vps['cores']) ? e(t('vps.cores', ['n' => $vps['cores']])) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.memory')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= e(format_bytes((int) $vps['mem_total'])) ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.cloud')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $dash($vps['provider']) ? '<i class="ti ti-cloud text-sky-500"></i> ' . e($vps['provider']) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.droplet')) ?></p>
      <p class="text-sm font-medium text-zinc-800 mono"><?= $dash($vps['droplet_id']) ? e($vps['droplet_id']) : '<span class="text-zinc-300 font-sans">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.region')) ?></p>
      <p class="text-sm font-medium text-zinc-800"><?= $dash($vps['region']) ? e(strtoupper((string) $vps['region'])) : '<span class="text-zinc-300">—</span>' ?></p>
    </div>
    <div>
      <p class="eyebrow mb-1"><?= e(t('vps.ipv4')) ?></p>
      <p class="text-sm font-medium text-zinc-800 mono"><?= $dash($vps['ipv4']) ? e($vps['ipv4']) : '<span class="text-zinc-300 font-sans">—</span>' ?></p>
    </div>
  </div>
</div>

<!-- system charts (live) -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-cpu text-ink"></i> <?= e(t('chart.cpu')) ?></span>
      <span class="mono font-semibold text-lg text-zinc-900"><span id="cpuNow"><?= e($metrics['cpu']) ?></span><span class="text-zinc-300 text-sm">%</span></span>
    </div>
    <div id="chartCpu"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-cpu-2 text-speed"></i> <?= e(t('chart.memory')) ?></span>
      <span class="mono font-semibold text-lg text-zinc-900"><span id="memNow"><?= e($metrics['memory']['percent']) ?></span><span class="text-zinc-300 text-sm">%</span></span>
    </div>
    <div id="chartMem"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-device-floppy text-amber-500"></i> <?= e(t('chart.disk')) ?></span>
      <span class="mono font-semibold text-lg text-zinc-900"><span id="diskNow"><?= e($metrics['disk']['percent']) ?></span><span class="text-zinc-300 text-sm">%</span></span>
    </div>
    <div id="chartDisk"></div>
  </div>
  <div class="card p-4">
    <div class="flex items-center justify-between mb-1">
      <span class="text-xs font-semibold text-zinc-500 flex items-center gap-1.5"><i class="ti ti-activity text-emerald-500"></i> <?= e(t('chart.load')) ?></span>
      <span class="mono font-semibold text-lg text-zinc-900"><span id="loadNow"><?= e($metrics['load']['1m']) ?></span><span class="text-zinc-300 text-sm">/ <?= e($vps['cores'] ?? '—') ?></span></span>
    </div>
    <div id="chartLoad"></div>
  </div>
</div>

<!-- services + needs attention -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
  <!-- services -->
  <div class="card overflow-hidden">
    <div class="card-head"><h2 class="card-title"><i class="ti ti-stack-2 text-ink"></i> <?= e(t('services.title')) ?></h2></div>
    <?php if (empty($services)): ?>
      <p class="px-4 py-8 text-sm text-zinc-400 text-center"><?= e(t('services.empty')) ?></p>
    <?php else: ?>
      <div class="divide-y divide-zinc-50">
        <?php foreach ($services as $name => $active): ?>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-xs text-zinc-700 mono"><?= e($name) ?></span>
            <span class="badge <?= $active ? 'badge-ok' : 'badge-danger' ?>">
              <span class="dot <?= $active ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
              <?= $active ? e(t('services.running')) : e(t('services.stopped')) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- needs attention (real, computed) -->
  <div class="card overflow-hidden lg:col-span-2 flex flex-col">
    <div class="card-head">
      <h2 class="card-title"><i class="ti ti-bell text-amber-500"></i> <?= e(t('dash.attention')) ?></h2>
      <?php if (!empty($alerts)): ?>
        <span class="badge badge-warn"><?= count($alerts) ?></span>
      <?php endif; ?>
    </div>
    <?php if (empty($alerts)): ?>
      <div class="flex-1 flex flex-col items-center justify-center text-center px-4 py-10">
        <span class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center mb-2"><i class="ti ti-circle-check text-emerald-500 text-xl"></i></span>
        <p class="text-sm text-zinc-500"><?= e(t('dash.allclear')) ?></p>
      </div>
    <?php else: ?>
      <?php
        $alertStyle = [
          'danger' => 'bg-red-50 text-red-500',
          'warn'   => 'bg-amber-50 text-amber-500',
          'info'   => 'bg-sky-50 text-sky-500',
        ];
      ?>
      <div class="divide-y divide-zinc-50">
        <?php foreach ($alerts as $a): ?>
          <div class="flex items-start gap-2.5 px-4 py-3">
            <span class="w-6 h-6 rounded-md flex items-center justify-center shrink-0 mt-0.5 <?= $alertStyle[$a['level']] ?? $alertStyle['info'] ?>"><i class="ti <?= e($a['icon']) ?> text-sm"></i></span>
            <p class="text-sm text-zinc-700 leading-snug flex-1"><?= e($a['text']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- sites snapshot -->
<div class="card overflow-hidden">
  <div class="card-head">
    <h2 class="card-title"><i class="ti ti-world text-ink"></i> <?= e(t('dash.sites')) ?></h2>
    <a href="/sites" class="btn btn-sm btn-ghost"><?= e(t('sites.view_all')) ?> →</a>
  </div>
  <?php if (empty($sites)): ?>
    <div class="px-6 py-12 text-center">
      <i class="ti ti-world text-4xl text-zinc-200 block mb-2"></i>
      <p class="text-sm font-medium text-zinc-600"><?= e(t('sites.empty')) ?></p>
      <a href="/sites/add" class="btn btn-primary btn-sm mt-3 inline-flex"><i class="ti ti-plus text-sm"></i> <?= e(t('sites.add_first')) ?></a>
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= e(t('col.domain')) ?></th>
          <th><?= e(t('col.app')) ?></th>
          <th><?= e(t('col.cache')) ?></th>
          <th><?= e(t('col.ssl')) ?></th>
          <th class="text-right"><?= e(t('col.action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sites as $site): ?>
          <tr>
            <td>
              <a href="/sites/<?= e($site['domain']) ?>" class="flex items-center gap-2.5 font-medium text-zinc-900 hover:text-ink">
                <span class="w-8 h-8 rounded-lg bg-ink-pale flex items-center justify-center shrink-0"><i class="ti <?= e($appIcon($site['type'])) ?> text-ink"></i></span>
                <?= e($site['domain']) ?>
              </a>
            </td>
            <td><span class="text-xs text-zinc-700"><?= e(ucfirst($site['type'])) ?></span> <span class="mono text-[11px] text-zinc-400">· PHP <?= e($site['php_version']) ?></span></td>
            <td>
              <?php if (!empty($site['cache_enabled'])): ?>
                <span class="badge badge-info"><i class="ti ti-bolt text-xs"></i> <?= e(t('cache.on')) ?></span>
              <?php else: ?>
                <span class="badge badge-muted"><?= e(t('cache.off')) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (($site['ssl_type'] ?? '') === 'letsencrypt'): ?>
                <span class="badge badge-ok"><i class="ti ti-lock-check text-xs"></i> <?= e(t('ssl.le')) ?></span>
              <?php else: ?>
                <span class="badge badge-muted"><?= e(t('ssl.self')) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-right"><a href="/sites/<?= e($site['domain']) ?>" class="btn btn-sm btn-ghost"><?= e(t('action.manage')) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function () {
  const MAX = 60, POLL = 5000;     // ~5 min of live samples at a 5s cadence
  const seed = <?= json_encode([
      'cpu'   => (float) $metrics['cpu'],
      'mem'   => (float) $metrics['memory']['percent'],
      'disk'  => (float) $metrics['disk']['percent'],
      'l1'    => (float) $metrics['load']['1m'],
      'l5'    => (float) $metrics['load']['5m'],
      'l15'   => (float) $metrics['load']['15m'],
      'cores' => (int) ($vps['cores'] ?? 1),
  ], JSON_UNESCAPED_SLASHES) ?>;

  const labels = [];
  const D = { cpu: [], mem: [], disk: [], l1: [], l5: [], l15: [] };
  const round = v => Math.round((+v || 0) * 10) / 10;
  const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  const now = () => { const d = new Date(); const p = n => String(n).padStart(2, '0'); return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); };

  function baseOpts(colors, type, max, legend) {
    return {
      chart: { type, height: 150, toolbar: { show: false }, fontFamily: '"Plus Jakarta Sans"', animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 600 } } },
      stroke: { curve: 'smooth', width: 2 },
      colors, fill: { type: 'solid', opacity: type === 'area' ? 0.10 : 1 },
      dataLabels: { enabled: false },
      legend: { show: legend, position: 'top', horizontalAlign: 'right', fontSize: '11px', markers: { width: 8, height: 8, radius: 4 }, itemMargin: { horizontal: 7 }, offsetY: -4 },
      grid: { borderColor: '#f1f1f4', strokeDashArray: 4, padding: { left: 6, right: 12, top: 0, bottom: -6 } },
      xaxis: { categories: labels, tickAmount: 5, labels: { style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' }, hideOverlappingLabels: true }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
      yaxis: { max, min: 0, tickAmount: 4, labels: { style: { colors: '#a1a1aa', fontSize: '10px', fontFamily: '"JetBrains Mono"' }, formatter: v => Math.round(v * 10) / 10 } },
      tooltip: { theme: 'light', shared: legend },
    };
  }

  const loadMax = Math.max(2, seed.cores || 1);
  const cpu  = new ApexCharts(document.querySelector('#chartCpu'),  Object.assign(baseOpts(['#322C7A'], 'area', 100, false), { series: [{ name: 'CPU', data: D.cpu }] }));
  const mem  = new ApexCharts(document.querySelector('#chartMem'),  Object.assign(baseOpts(['#0891B2'], 'area', 100, false), { series: [{ name: 'Memory', data: D.mem }] }));
  const disk = new ApexCharts(document.querySelector('#chartDisk'), Object.assign(baseOpts(['#F59E0B'], 'area', 100, false), { series: [{ name: 'Disk', data: D.disk }] }));
  const load = new ApexCharts(document.querySelector('#chartLoad'), Object.assign(baseOpts(['#322C7A', '#0891B2', '#F59E0B'], 'line', loadMax, true), { series: [{ name: '1m', data: D.l1 }, { name: '5m', data: D.l5 }, { name: '15m', data: D.l15 }] }));
  cpu.render(); mem.render(); disk.render(); load.render();

  function push(m) {
    labels.push(now());
    D.cpu.push(round(m.cpu)); D.mem.push(round(m.memory.percent)); D.disk.push(round(m.disk.percent));
    D.l1.push(round(m.load['1m'])); D.l5.push(round(m.load['5m'])); D.l15.push(round(m.load['15m']));
    if (labels.length > MAX) { labels.shift(); for (const k in D) D[k].shift(); }
    cpu.updateOptions({ xaxis: { categories: labels } }, false, false);  cpu.updateSeries([{ name: 'CPU', data: D.cpu }]);
    mem.updateOptions({ xaxis: { categories: labels } }, false, false);  mem.updateSeries([{ name: 'Memory', data: D.mem }]);
    disk.updateOptions({ xaxis: { categories: labels } }, false, false); disk.updateSeries([{ name: 'Disk', data: D.disk }]);
    load.updateOptions({ xaxis: { categories: labels } }, false, false); load.updateSeries([{ name: '1m', data: D.l1 }, { name: '5m', data: D.l5 }, { name: '15m', data: D.l15 }]);
    setText('cpuNow', round(m.cpu)); setText('memNow', round(m.memory.percent)); setText('diskNow', round(m.disk.percent)); setText('loadNow', round(m.load['1m']));
  }

  push({ cpu: seed.cpu, memory: { percent: seed.mem }, disk: { percent: seed.disk }, load: { '1m': seed.l1, '5m': seed.l5, '15m': seed.l15 } });

  setInterval(async () => {
    try { const m = await window.api('/api/metrics'); if (m && m.cpu !== undefined) push(m); } catch (e) {}
  }, POLL);
})();
</script>
