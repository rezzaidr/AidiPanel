<?php $pageTitle = 'Services'; ?>

<div class="grid grid-cols-2 gap-4" x-data="services()" x-init="init()">

  <?php foreach ($services as $name => $svc): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center
          <?= match(true) {
            str_starts_with($name, 'nginx')   => 'bg-green-50',
            str_starts_with($name, 'mysql') || str_starts_with($name, 'mariadb') => 'bg-amber-50',
            str_starts_with($name, 'redis')   => 'bg-red-50',
            str_starts_with($name, 'php')     => 'bg-blue-50',
            default => 'bg-gray-100'
          } ?>">
          <i class="ti ti-<?= match(true) {
            str_starts_with($name, 'nginx')   => 'server',
            str_starts_with($name, 'mysql') || str_starts_with($name, 'mariadb')  => 'database',
            str_starts_with($name, 'redis')   => 'database',
            str_starts_with($name, 'php')     => 'brand-php',
            default => 'settings'
          } ?> text-lg <?= match(true) {
            str_starts_with($name, 'nginx')   => 'text-green-600',
            str_starts_with($name, 'mysql') || str_starts_with($name, 'mariadb')  => 'text-amber-600',
            str_starts_with($name, 'redis')   => 'text-red-500',
            str_starts_with($name, 'php')     => 'text-blue-500',
            default => 'text-gray-500'
          } ?>"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-gray-900"><?= e($name) ?></p>
          <p class="text-[10px] <?= $svc['active'] ? 'text-green-600' : 'text-red-500' ?>">
            <?= $svc['active'] ? '● running' : '○ stopped' ?>
            · <?= $svc['enabled'] ? 'auto-start on' : 'auto-start off' ?>
          </p>
        </div>
      </div>

      <!-- Status badge -->
      <span class="text-[11px] font-medium px-2.5 py-1 rounded-full
        <?= $svc['active'] ? 'bg-green-100 text-green-700' : 'bg-red-50 text-red-500' ?>">
        <?= e($svc['status']) ?>
      </span>
    </div>

    <!-- Action buttons -->
    <div class="flex gap-2">
      <form method="POST" action="/services/action" class="flex-1">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="restart">
        <button type="submit"
          class="w-full text-xs border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg px-3 py-2 transition-colors">
          <i class="ti ti-refresh mr-1"></i> Restart
        </button>
      </form>

      <?php if (!$svc['active']): ?>
      <form method="POST" action="/services/action" class="flex-1">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="start">
        <button type="submit"
          class="w-full text-xs bg-green-50 border border-green-200 text-green-700 hover:bg-green-100 rounded-lg px-3 py-2 transition-colors">
          <i class="ti ti-player-play mr-1"></i> Start
        </button>
      </form>
      <?php else: ?>
      <form method="POST" action="/services/action" class="flex-1"
            onsubmit="return confirm('Stop <?= e($name) ?>?')">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="service" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="stop">
        <button type="submit"
          class="w-full text-xs bg-red-50 border border-red-200 text-red-500 hover:bg-red-100 rounded-lg px-3 py-2 transition-colors">
          <i class="ti ti-player-stop mr-1"></i> Stop
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div>

<!-- Auto-refresh status every 10s -->
<script>
function services() {
  return {
    init() {
      setInterval(async () => {
        const data = await api('/api/services');
        if (data) {
          // Simple page refresh for now
          // In a full SPA this would update Alpine reactive state
        }
      }, 10000);
    }
  }
}
</script>
