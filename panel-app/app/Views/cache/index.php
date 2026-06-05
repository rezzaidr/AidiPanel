<?php $pageTitle = 'FastCGI Cache'; ?>

<div class="grid grid-cols-3 gap-4 mb-5">
  <!-- Cache stats -->
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-8 h-8 bg-brand-pale rounded-lg flex items-center justify-center">
        <i class="ti ti-bolt text-brand text-base"></i>
      </div>
      <span class="text-sm font-semibold text-gray-800">FastCGI Cache</span>
    </div>
    <div class="grid grid-cols-2 gap-3 text-xs">
      <div>
        <p class="text-gray-400 mb-0.5">Cache Size</p>
        <p class="text-lg font-semibold text-gray-900"><?= e($stats['fcgi_size']) ?></p>
      </div>
      <div>
        <p class="text-gray-400 mb-0.5">Cached Files</p>
        <p class="text-lg font-semibold text-gray-900"><?= e(number_format($stats['fcgi_files'])) ?></p>
      </div>
    </div>
    <form method="POST" action="/cache/purge" class="mt-4">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <button type="submit"
        onclick="return confirm('Purge ALL FastCGI cache?')"
        class="w-full text-xs border border-red-200 text-red-500 hover:bg-red-50 rounded-lg px-3 py-2 transition-colors">
        <i class="ti ti-trash mr-1"></i> Purge All Cache
      </button>
    </form>
  </div>

  <!-- Redis stats -->
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center">
        <i class="ti ti-database text-blue-500 text-base"></i>
      </div>
      <span class="text-sm font-semibold text-gray-800">Redis (Object Cache)</span>
    </div>
    <?php if (!empty($stats['redis'])): ?>
    <div class="grid grid-cols-3 gap-2 text-xs">
      <div>
        <p class="text-gray-400 mb-0.5">Hits</p>
        <p class="text-base font-semibold text-green-600"><?= e(number_format($stats['redis']['hits'])) ?></p>
      </div>
      <div>
        <p class="text-gray-400 mb-0.5">Misses</p>
        <p class="text-base font-semibold text-gray-700"><?= e(number_format($stats['redis']['misses'])) ?></p>
      </div>
      <div>
        <p class="text-gray-400 mb-0.5">Hit Rate</p>
        <p class="text-base font-semibold text-brand"><?= e($stats['redis']['hit_rate']) ?>%</p>
      </div>
    </div>
    <div class="mt-3 h-1.5 bg-gray-100 rounded-full">
      <div class="h-1.5 bg-green-500 rounded-full" style="width: <?= e($stats['redis']['hit_rate']) ?>%"></div>
    </div>
    <?php else: ?>
    <p class="text-xs text-gray-400">Redis is not running or stats unavailable.</p>
    <?php endif; ?>
  </div>

  <!-- Purge by URL -->
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center">
        <i class="ti ti-link text-amber-500 text-base"></i>
      </div>
      <span class="text-sm font-semibold text-gray-800">Purge by URL</span>
    </div>
    <form method="POST" action="/cache/purge">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="text" name="url" placeholder="https://example.com/page"
        class="w-full text-xs px-3 py-2 border border-gray-200 rounded-lg mb-2 focus:outline-none focus:ring-1 focus:ring-brand">
      <button type="submit"
        class="w-full text-xs bg-amber-50 border border-amber-200 text-amber-700 hover:bg-amber-100 rounded-lg px-3 py-2 transition-colors">
        <i class="ti ti-refresh mr-1"></i> Purge URL
      </button>
    </form>
  </div>
</div>

<!-- Per-site cache management -->
<div class="bg-white rounded-xl border border-gray-200">
  <div class="px-5 py-3.5 border-b border-gray-100">
    <span class="text-sm font-semibold text-gray-800">Cache per Site</span>
  </div>
  <?php if (empty($sites)): ?>
    <p class="px-5 py-8 text-sm text-gray-400 text-center">No sites configured yet.</p>
  <?php else: ?>
  <div class="divide-y divide-gray-50">
    <?php foreach ($sites as $site): ?>
    <div class="flex items-center justify-between px-5 py-3.5">
      <div>
        <a href="/sites/<?= e($site['domain']) ?>" class="text-sm font-medium text-gray-900 hover:text-brand">
          <?= e($site['domain']) ?>
        </a>
        <p class="text-[10px] text-gray-400"><?= e(ucfirst($site['type'])) ?></p>
      </div>
      <div class="flex items-center gap-3">
        <!-- Per-site purge -->
        <form method="POST" action="/cache/purge">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
          <button type="submit" class="text-[11px] text-amber-600 hover:text-amber-700 flex items-center gap-1">
            <i class="ti ti-refresh text-sm"></i> Purge
          </button>
        </form>

        <!-- Toggle -->
        <form method="POST" action="/cache/toggle">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
          <input type="hidden" name="action" value="<?= $site['cache_enabled'] ? 'disable' : 'enable' ?>">
          <button type="submit"
            class="text-[11px] font-medium px-3 py-1 rounded-full border transition-colors
              <?= $site['cache_enabled']
                ? 'bg-brand-pale text-brand border-brand/20 hover:bg-red-50 hover:text-red-500 hover:border-red-200'
                : 'bg-gray-100 text-gray-500 border-gray-200 hover:bg-brand-pale hover:text-brand hover:border-brand/20' ?>">
            <?= $site['cache_enabled'] ? '● Enabled' : '○ Disabled' ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
