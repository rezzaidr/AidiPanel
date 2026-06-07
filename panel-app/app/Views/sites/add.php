<?php
$pageTitle = t('site.add.title');
$typeIcons = [
    'wordpress' => 'ti-brand-wordpress',
    'laravel'   => 'ti-code',
    'php'       => 'ti-brand-php',
    'static'    => 'ti-file-code',
    'proxy'     => 'ti-arrow-guide',
];
?>

<!-- page header -->
<div class="mb-6">
  <a href="/sites" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm"></i> <?= e(t('nav.sites')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('site.add.title')) ?></h1>
</div>

<div class="max-w-xl">
  <div class="card p-6" x-data="{ siteType: 'wordpress' }">
    <form method="POST" action="/sites/add" class="space-y-5">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <!-- Domain -->
      <div>
        <label class="lbl"><?= e(t('site.add.domain_label')) ?></label>
        <input type="text" name="domain" required placeholder="example.com" class="inp">
        <p class="hint"><?= e(t('site.add.domain_hint')) ?></p>
      </div>

      <!-- App type tiles -->
      <div>
        <label class="lbl"><?= e(t('site.add.type_label')) ?></label>
        <div class="grid grid-cols-3 gap-2">
          <?php foreach ($siteTypes as $value => $label): ?>
          <label class="cursor-pointer">
            <input type="radio" name="type" value="<?= e($value) ?>" class="sr-only peer"
                   <?= $value === 'wordpress' ? 'checked' : '' ?>
                   @change="siteType = '<?= e($value) ?>'">
            <div class="border border-zinc-200 rounded-xl px-2 py-3 text-center text-xs text-zinc-500
                        peer-checked:border-ink peer-checked:bg-ink-pale peer-checked:text-ink
                        hover:border-zinc-300 hover:bg-zinc-50 transition-all">
              <i class="ti <?= e($typeIcons[$value] ?? 'ti-world') ?> block text-2xl mb-1.5"></i>
              <?= e($label) ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- PHP version (hidden for static + proxy) -->
      <div x-show="siteType !== 'static' && siteType !== 'proxy'" x-cloak>
        <label class="lbl"><?= e(t('site.add.php_label')) ?></label>
        <div class="flex gap-2">
          <?php foreach ($phpVersions as $v): ?>
          <label class="flex-1 cursor-pointer">
            <input type="radio" name="php_version" value="<?= e($v) ?>" class="sr-only peer"
                   <?= $v === '8.3' ? 'checked' : '' ?>>
            <div class="border border-zinc-200 rounded-lg py-2 text-center text-sm font-medium text-zinc-600 mono
                        peer-checked:border-ink peer-checked:bg-ink-pale peer-checked:text-ink
                        hover:border-zinc-300 transition-all">
              PHP <?= e($v) ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Proxy pass (proxy only) -->
      <div x-show="siteType === 'proxy'" x-cloak>
        <label class="lbl"><?= e(t('site.add.proxy_label')) ?></label>
        <input type="text" name="proxy_pass" value="http://127.0.0.1:3000"
               placeholder="http://127.0.0.1:3000" class="inp mono">
        <p class="hint"><?= e(t('site.add.proxy_hint')) ?></p>
      </div>

      <!-- Submit -->
      <div class="flex items-center gap-3 pt-1">
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-plus text-sm"></i> <?= e(t('site.add.submit')) ?>
        </button>
        <a href="/sites" class="btn btn-ghost"><?= e(t('common.cancel')) ?></a>
      </div>

    </form>
  </div>
</div>
