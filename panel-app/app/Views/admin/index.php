<?php
/**
 * Admin Area landing — grid of server-wide sections.
 * Sections are data-driven so order/availability is easy to adjust as Fase 2/3
 * build out the missing pages (security, tuning, backups).
 */
$sections = [
    ['key' => 'services', 'icon' => 'ti-stack-2',       'accent' => 'ink',   'href' => '/services'],
    ['key' => 'php',      'icon' => 'ti-brand-php',      'accent' => 'ink',   'href' => '/php'],
    ['key' => 'cache',    'icon' => 'ti-bolt',           'accent' => 'speed', 'href' => '/cache'],
    ['key' => 'ssl',      'icon' => 'ti-lock',           'accent' => 'ink',   'href' => '/ssl'],
    ['key' => 'users',    'icon' => 'ti-users',          'accent' => 'ink',   'href' => '/users', 'admin' => true],
    ['key' => 'logs',     'icon' => 'ti-file-text',      'accent' => 'ink',   'href' => '/logs',  'admin' => true],
    ['key' => 'security', 'icon' => 'ti-shield-lock',    'accent' => 'soon',  'href' => null],
    ['key' => 'tuning',   'icon' => 'ti-adjustments-bolt','accent' => 'soon', 'href' => null],
    ['key' => 'backups',  'icon' => 'ti-database-export', 'accent' => 'soon',  'href' => null],
];

$accentTile = [
    'ink'   => 'bg-ink-pale text-ink',
    'speed' => 'bg-speed-pale text-speed',
    'soon'  => 'bg-zinc-100 text-zinc-400',
];
?>
<div class="mb-5">
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.subtitle')) ?></p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($sections as $s): ?>
    <?php
      if (!empty($s['admin']) && empty($_is_admin)) { continue; }
      $isSoon = ($s['accent'] === 'soon') || empty($s['href']);
      $tile   = $accentTile[$s['accent']] ?? $accentTile['ink'];
      $title  = t('admin.' . $s['key'] . '.title');
      $desc   = t('admin.' . $s['key'] . '.desc');
    ?>
    <?php if ($isSoon): ?>
      <div class="card p-4 flex items-start gap-3.5 opacity-70 select-none">
        <span class="w-10 h-10 rounded-lg <?= $tile ?> flex items-center justify-center shrink-0"><i class="ti <?= e($s['icon']) ?> text-xl" aria-hidden="true"></i></span>
        <div class="min-w-0 flex-1">
          <p class="font-head font-semibold text-sm text-zinc-500 flex items-center gap-2"><?= e($title) ?> <span class="tag tag-muted"><?= e(t('common.soon')) ?></span></p>
          <p class="text-xs text-zinc-400 mt-1 leading-snug"><?= e($desc) ?></p>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= e($s['href']) ?>" class="card p-4 flex items-start gap-3.5 hover:border-ink/30 hover:shadow-sm transition group">
        <span class="w-10 h-10 rounded-lg <?= $tile ?> flex items-center justify-center shrink-0"><i class="ti <?= e($s['icon']) ?> text-xl" aria-hidden="true"></i></span>
        <div class="min-w-0 flex-1">
          <p class="font-head font-semibold text-sm text-zinc-900"><?= e($title) ?></p>
          <p class="text-xs text-zinc-400 mt-1 leading-snug"><?= e($desc) ?></p>
        </div>
        <i class="ti ti-arrow-right text-zinc-300 group-hover:text-ink transition mt-1" aria-hidden="true"></i>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
