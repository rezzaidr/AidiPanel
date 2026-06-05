<?php $pageTitle = 'PHP-FPM'; ?>

<!-- PHP Versions -->
<div class="grid grid-cols-3 gap-4 mb-5">
  <?php foreach ($versions as $ver => $info): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2.5">
        <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center">
          <i class="ti ti-brand-php text-blue-500 text-lg"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-gray-900">PHP <?= e($ver) ?></p>
          <p class="text-[10px] text-gray-400"><?= $info['installed'] ? e($info['full_ver']) : 'Not installed' ?></p>
        </div>
      </div>
      <?php if ($info['installed']): ?>
        <span class="text-[10px] font-medium px-2 py-1 rounded-full <?= $info['fpm_active'] ? 'bg-green-100 text-green-700' : 'bg-red-50 text-red-500' ?>">
          <?= $info['fpm_active'] ? '● running' : '● stopped' ?>
        </span>
      <?php else: ?>
        <span class="text-[10px] text-gray-300 bg-gray-100 px-2 py-1 rounded-full">not installed</span>
      <?php endif; ?>
    </div>

    <?php if ($info['installed']): ?>
    <form method="POST" action="/php/restart">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="version" value="<?= e($ver) ?>">
      <button type="submit"
        class="w-full text-xs border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg px-3 py-2 transition-colors mt-2">
        <i class="ti ti-refresh mr-1"></i> Restart PHP <?= e($ver) ?>-FPM
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Sites PHP usage -->
<div class="bg-white rounded-xl border border-gray-200">
  <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100">
    <span class="text-sm font-semibold text-gray-800">PHP Version per Site</span>
    <form method="POST" action="/php/restart" class="flex items-center gap-2">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="version" value="all">
      <button type="submit" class="text-xs text-brand hover:text-brand-light flex items-center gap-1">
        <i class="ti ti-refresh text-sm"></i> Restart all PHP-FPM
      </button>
    </form>
  </div>

  <?php if (empty($sites)): ?>
    <p class="px-5 py-8 text-sm text-gray-400 text-center">No sites configured.</p>
  <?php else: ?>
  <table class="w-full">
    <thead>
      <tr class="bg-gray-50 border-b border-gray-100">
        <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Domain</th>
        <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Type</th>
        <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">PHP Version</th>
        <th class="px-5 py-2.5"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($sites as $site): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-5 py-3">
          <a href="/sites/<?= e($site['domain']) ?>" class="text-sm font-medium text-gray-900 hover:text-brand">
            <?= e($site['domain']) ?>
          </a>
        </td>
        <td class="px-5 py-3 text-xs text-gray-500"><?= e(ucfirst($site['type'])) ?></td>
        <td class="px-5 py-3">
          <span class="text-[11px] font-medium bg-blue-50 text-blue-700 px-2.5 py-0.5 rounded-full">
            PHP <?= e($site['php_version']) ?>
          </span>
        </td>
        <td class="px-5 py-3 text-right">
          <a href="/sites/<?= e($site['domain']) ?>" class="text-xs text-gray-400 hover:text-brand">Change →</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
