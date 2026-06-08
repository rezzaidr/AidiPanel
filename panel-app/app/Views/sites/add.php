<?php
/**
 * Add Site — Step 1: application-type picker.
 * $cards comes from SiteController::addTypeCards(). "soon" cards are backend-not-wired
 * but still navigate to a preview form.
 */
$pageTitle = t('site.add.picker.title');
?>

<div class="text-center mb-7">
  <h1 class="font-head font-bold text-[26px] text-zinc-900 leading-tight"><?= e(t('site.add.picker.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-2"><?= e(t('site.add.picker.subtitle')) ?></p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-4xl mx-auto">
  <?php foreach ($cards as $c): ?>
    <?php if ($c['soon']): ?>
    <a href="/sites/add/<?= e($c['slug']) ?>" class="relative group card p-5 opacity-80 hover:opacity-100 transition">
      <span class="absolute top-4 right-4 tag tag-soon"><?= e(t('site.add.soon')) ?></span>
      <span class="w-11 h-11 rounded-lg bg-zinc-100 flex items-center justify-center mb-3"><i class="ti <?= e($c['icon']) ?> text-zinc-400 text-2xl"></i></span>
      <h3 class="font-head font-semibold text-[15px] text-zinc-700"><?= e(t($c['title'])) ?></h3>
      <p class="text-xs text-zinc-400 mt-1 leading-relaxed"><?= e(t($c['desc'])) ?></p>
    </a>
    <?php else: ?>
    <a href="/sites/add/<?= e($c['slug']) ?>" class="relative group card p-5 hover:border-ink hover:shadow-sm transition">
      <span class="w-11 h-11 rounded-lg bg-ink-pale flex items-center justify-center mb-3"><i class="ti <?= e($c['icon']) ?> text-ink text-2xl"></i></span>
      <h3 class="font-head font-semibold text-[15px] text-zinc-900"><?= e(t($c['title'])) ?></h3>
      <p class="text-xs text-zinc-400 mt-1 leading-relaxed"><?= e(t($c['desc'])) ?></p>
      <i class="ti ti-arrow-right absolute top-5 right-5 text-zinc-300 group-hover:text-ink transition"></i>
    </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
