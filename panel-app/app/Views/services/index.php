<?php $pageTitle = t('admin.services.title');

// Presentation helpers — the controller hands us raw unit status (name/active/
// enabled); the friendly name + icon are view concerns, keyed off the unit name.
$svcLabel = static function (string $unit): string {
    if (preg_match('/^php(\d+\.\d+)-fpm$/', $unit, $m)) {
        return t('svc.name_php', ['ver' => $m[1]]);
    }
    return match ($unit) {
        'nginx'            => t('svc.name_nginx'),
        'mysql', 'mariadb' => t('svc.name_database'),
        'redis-server'     => t('svc.name_redis'),
        default            => $unit,
    };
};
// Returns [icon, iconBg, iconColor] for the small leading glyph.
$svcIcon = static function (string $unit): array {
    if (str_starts_with($unit, 'php')) return ['ti-brand-php', 'bg-ink-pale', 'text-ink'];
    return match ($unit) {
        'nginx'            => ['ti-server',   'bg-emerald-50', 'text-emerald-600'],
        'mysql', 'mariadb' => ['ti-database', 'bg-amber-50',   'text-amber-600'],
        'redis-server'     => ['ti-database', 'bg-red-50',     'text-red-500'],
        default            => ['ti-settings', 'bg-zinc-100',   'text-zinc-500'],
    };
};

// Split the flat status map into the two sections the page shows. The controller
// already orders them (nginx, database, redis, then php<ver>-fpm).
$core = $php = [];
foreach ($services as $name => $svc) {
    if (str_starts_with($name, 'php')) { $php[$name] = $svc; }
    else                               { $core[$name] = $svc; }
}
$sections = [
    ['title' => t('svc.sec_core'), 'icon' => 'ti-stack-2',  'rows' => $core],
    ['title' => t('svc.sec_php'),  'icon' => 'ti-brand-php', 'rows' => $php],
];
?>

<!-- page header -->
<div class="mb-5">
  <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm" aria-hidden="true"></i> <?= e(t('admin.title')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.services.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.services.desc')) ?></p>
</div>

<?php if (empty($services)): ?>
<div class="card px-8 py-14 text-center">
  <i class="ti ti-server text-5xl text-zinc-200 block mb-3" aria-hidden="true"></i>
  <p class="text-sm text-zinc-400"><?= e(t('services.empty')) ?></p>
</div>
<?php else: ?>

<?php foreach ($sections as $section): if (empty($section['rows'])) continue; ?>
<div class="card mb-6">
  <div class="card-head">
    <div class="card-title">
      <i class="ti <?= e($section['icon']) ?> text-base text-zinc-400" aria-hidden="true"></i> <?= e($section['title']) ?>
    </div>
  </div>
  <!-- table-layout:fixed + shared colgroup so both section tables (Core / PHP)
       keep identical column geometry regardless of their cell content -->
  <table class="tbl" style="table-layout:fixed">
    <colgroup>
      <col style="width:30%"><col style="width:22%"><col style="width:16%"><col style="width:12%"><col style="width:20%">
    </colgroup>
    <thead>
      <tr>
        <th><?= e(t('svc.col_service')) ?></th>
        <th><?= e(t('svc.col_unit')) ?></th>
        <th><?= e(t('svc.col_status')) ?></th>
        <th><?= e(t('svc.col_autostart')) ?></th>
        <th style="text-align:right"><?= e(t('svc.col_actions')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($section['rows'] as $name => $svc):
        [$icon, $iconBg, $iconColor] = $svcIcon($name);
        $active  = (bool) ($svc['active'] ?? false);
        $enabled = (bool) ($svc['enabled'] ?? false);
        $canReload = $name === 'nginx' || (bool) preg_match('/^php\d+\.\d+-fpm$/', $name);
    ?>
      <tr>
        <td class="px-5 py-3">
          <div class="flex items-center gap-3">
            <span class="w-8 h-8 rounded-lg <?= $iconBg ?> flex items-center justify-center shrink-0">
              <i class="ti <?= e($icon) ?> <?= $iconColor ?>" aria-hidden="true"></i>
            </span>
            <span class="font-medium text-zinc-900"><?= e($svcLabel($name)) ?></span>
          </div>
        </td>
        <td class="px-3 py-3"><span class="mono text-xs text-zinc-500"><?= e($name) ?>.service</span></td>
        <td class="px-3 py-3">
          <?php if ($active): ?>
            <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('svc.running')) ?></span>
          <?php else: ?>
            <span class="badge badge-danger"><span class="dot bg-red-500"></span> <?= e(t('svc.stopped')) ?></span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-3">
          <?php if ($enabled): ?>
            <span class="text-xs text-zinc-600"><?= e(t('svc.on')) ?></span>
          <?php else: ?>
            <span class="text-xs text-amber-600"><?= e(t('svc.off')) ?></span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3">
          <div class="relative flex justify-end" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open" :aria-expanded="open" aria-label="<?= e(t('svc.col_actions')) ?>" class="btn btn-ghost btn-sm px-2">
              <i class="ti ti-dots-vertical text-base" aria-hidden="true"></i>
            </button>
            <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 top-full mt-1 z-30 min-w-[10rem] card shadow-xl py-1">
              <?php if ($canReload): ?>
              <form method="POST" action="/services/action">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="service" value="<?= e($name) ?>">
                <input type="hidden" name="action" value="reload">
                <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">
                  <i class="ti ti-reload text-sm text-sky-600" aria-hidden="true"></i> <?= e(t('svc.reload')) ?>
                </button>
              </form>
              <?php endif; ?>
              <form method="POST" action="/services/action">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="service" value="<?= e($name) ?>">
                <input type="hidden" name="action" value="restart">
                <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">
                  <i class="ti ti-refresh text-sm text-zinc-400" aria-hidden="true"></i> <?= e(t('svc.restart')) ?>
                </button>
              </form>
              <?php if ($active): ?>
              <form method="POST" action="/services/action"
                    onsubmit="return confirm('<?= e(t('svc.stop_confirm', ['svc' => $name])) ?>')">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="service" value="<?= e($name) ?>">
                <input type="hidden" name="action" value="stop">
                <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                  <i class="ti ti-player-stop-filled text-sm" aria-hidden="true"></i> <?= e(t('svc.stop')) ?>
                </button>
              </form>
              <?php else: ?>
              <form method="POST" action="/services/action">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="service" value="<?= e($name) ?>">
                <input type="hidden" name="action" value="start">
                <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50">
                  <i class="ti ti-player-play-filled text-sm" aria-hidden="true"></i> <?= e(t('svc.start')) ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php endif; ?>
