<?php $pageTitle = 'Dashboard'; ?>

<div x-data="dashboard()" x-init="init()">

  <!-- Metrics -->
  <div class="grid grid-cols-4 gap-4 mb-5">
    <!-- CPU -->
    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3.5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-gray-500 flex items-center gap-1.5">
          <i class="ti ti-cpu text-brand"></i> CPU Usage
        </span>
        <span class="text-xs text-gray-400"><?= e(shell_exec('nproc 2>/dev/null') ?: '?') ?> cores</span>
      </div>
      <div class="text-2xl font-semibold text-gray-900" x-text="metrics.cpu + '%'"><?= e($metrics['cpu']) ?>%</div>
      <div class="mt-2 h-1 bg-gray-100 rounded-full">
        <div class="h-1 bg-brand rounded-full transition-all" :style="'width:'+metrics.cpu+'%'"></div>
      </div>
    </div>

    <!-- Memory -->
    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3.5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-gray-500 flex items-center gap-1.5">
          <i class="ti ti-cpu-2 text-blue-500"></i> Memory
        </span>
        <span class="text-xs text-gray-400" x-text="metrics.memory?.percent + '%'"><?= e($metrics['memory']['percent']) ?>%</span>
      </div>
      <div class="text-2xl font-semibold text-gray-900" x-text="fmtBytes(metrics.memory?.used)"><?= e(format_bytes($metrics['memory']['used'])) ?></div>
      <div class="mt-2 h-1 bg-gray-100 rounded-full">
        <div class="h-1 bg-blue-500 rounded-full transition-all" :style="'width:'+(metrics.memory?.percent||0)+'%'"></div>
      </div>
      <div class="text-[10px] text-gray-400 mt-1" x-text="'of ' + fmtBytes(metrics.memory?.total)">of <?= e(format_bytes($metrics['memory']['total'])) ?></div>
    </div>

    <!-- Disk -->
    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3.5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-gray-500 flex items-center gap-1.5">
          <i class="ti ti-device-floppy text-amber-500"></i> Disk
        </span>
        <span class="text-xs text-gray-400" x-text="metrics.disk?.percent + '%'"><?= e($metrics['disk']['percent']) ?>%</span>
      </div>
      <div class="text-2xl font-semibold text-gray-900" x-text="fmtBytes(metrics.disk?.used)"><?= e(format_bytes($metrics['disk']['used'])) ?></div>
      <div class="mt-2 h-1 bg-gray-100 rounded-full">
        <div class="h-1 bg-amber-500 rounded-full transition-all" :style="'width:'+(metrics.disk?.percent||0)+'%'"></div>
      </div>
      <div class="text-[10px] text-gray-400 mt-1" x-text="'of ' + fmtBytes(metrics.disk?.total)">of <?= e(format_bytes($metrics['disk']['total'])) ?></div>
    </div>

    <!-- Load -->
    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3.5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-gray-500 flex items-center gap-1.5">
          <i class="ti ti-activity text-green-500"></i> Load Avg
        </span>
        <span class="text-xs text-gray-400">Uptime: <?= e($metrics['uptime']) ?></span>
      </div>
      <div class="text-2xl font-semibold text-gray-900" x-text="metrics.load?.['1m']"><?= e($metrics['load']['1m']) ?></div>
      <div class="text-[10px] text-gray-400 mt-1.5" x-text="'5m: ' + metrics.load?.['5m'] + ' · 15m: ' + metrics.load?.['15m']">
        5m: <?= e($metrics['load']['5m']) ?> · 15m: <?= e($metrics['load']['15m']) ?>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-3 gap-4 mb-5">

    <!-- Services -->
    <div class="bg-white rounded-xl border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-800">Services</span>
      </div>
      <div class="divide-y divide-gray-50">
        <?php foreach ($services as $name => $active): ?>
        <div class="flex items-center justify-between px-4 py-2.5">
          <span class="text-xs text-gray-700"><?= e($name) ?></span>
          <span class="text-[10px] font-medium px-2 py-0.5 rounded-full <?= $active ? 'bg-green-100 text-green-700' : 'bg-red-50 text-red-500' ?>">
            <?= $active ? 'running' : 'stopped' ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Recent Sites -->
    <div class="col-span-2 bg-white rounded-xl border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-800">Recent Sites</span>
        <a href="/sites" class="text-xs text-brand hover:text-brand-light">View all →</a>
      </div>
      <?php if (empty($sites)): ?>
        <div class="px-4 py-8 text-center">
          <i class="ti ti-world text-3xl text-gray-200 block mb-2"></i>
          <p class="text-sm text-gray-400">No sites yet.</p>
          <a href="/sites/add" class="mt-2 inline-block text-xs text-brand hover:underline">Add your first site →</a>
        </div>
      <?php else: ?>
      <div class="divide-y divide-gray-50">
        <?php foreach ($sites as $site): ?>
        <div class="flex items-center justify-between px-4 py-2.5">
          <div>
            <a href="/sites/<?= e($site['domain']) ?>" class="text-xs font-medium text-gray-900 hover:text-brand"><?= e($site['domain']) ?></a>
            <p class="text-[10px] text-gray-400"><?= e(ucfirst($site['type'])) ?> · PHP <?= e($site['php_version']) ?></p>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $site['cache_enabled'] ? 'bg-brand-pale text-brand' : 'bg-gray-100 text-gray-500' ?>">
              <?= $site['cache_enabled'] ? 'cached' : 'no cache' ?>
            </span>
            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $site['ssl_type'] === 'letsencrypt' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= e($site['ssl_type']) ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Activity Log -->
  <div class="bg-white rounded-xl border border-gray-200">
    <div class="px-4 py-3 border-b border-gray-100">
      <span class="text-sm font-medium text-gray-800">Recent Activity</span>
    </div>
    <div class="divide-y divide-gray-50">
      <?php if (empty($activity)): ?>
        <p class="px-4 py-6 text-sm text-gray-400 text-center">No activity yet.</p>
      <?php else: ?>
        <?php foreach ($activity as $log): ?>
        <div class="flex items-center gap-3 px-4 py-2.5">
          <span class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
            <i class="ti ti-activity text-gray-400 text-xs"></i>
          </span>
          <div class="flex-1 min-w-0">
            <span class="text-xs font-mono text-brand"><?= e($log['action']) ?></span>
            <span class="text-xs text-gray-500 ml-2"><?= e($log['detail']) ?></span>
          </div>
          <div class="text-right shrink-0">
            <p class="text-[10px] text-gray-400"><?= e($log['username'] ?? 'system') ?></p>
            <p class="text-[10px] text-gray-300"><?= e($log['created_at']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function dashboard() {
  return {
    metrics: <?= json_encode($metrics) ?>,
    init() {
      setInterval(() => this.refresh(), 5000);
    },
    async refresh() {
      const data = await api('/api/metrics');
      if (data) this.metrics = data;
    },
    fmtBytes(b) {
      if (!b) return '0 B';
      const u = ['B','KB','MB','GB','TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return Math.round(b * 10) / 10 + ' ' + u[i];
    }
  }
}
</script>
