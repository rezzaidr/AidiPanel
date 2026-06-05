<?php $pageTitle = 'Sites'; ?>

<div class="flex items-center justify-between mb-5">
  <div>
    <h2 class="text-sm font-semibold text-gray-900">All Sites</h2>
    <p class="text-xs text-gray-400 mt-0.5"><?= count($sites) ?> site(s) configured</p>
  </div>
  <a href="/sites/add" class="inline-flex items-center gap-1.5 bg-brand hover:bg-brand-light text-white text-xs font-medium px-3.5 py-2 rounded-lg transition-colors">
    <i class="ti ti-plus text-sm"></i> Add Site
  </a>
</div>

<?php if (empty($sites)): ?>
<div class="bg-white rounded-xl border border-gray-200 px-8 py-16 text-center">
  <i class="ti ti-world text-5xl text-gray-200 block mb-3"></i>
  <p class="text-sm font-medium text-gray-700">No sites yet</p>
  <p class="text-xs text-gray-400 mb-4">Add your first site to get started</p>
  <a href="/sites/add" class="inline-flex items-center gap-1.5 bg-brand text-white text-xs font-medium px-4 py-2 rounded-lg">
    <i class="ti ti-plus"></i> Add Site
  </a>
</div>
<?php else: ?>
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
  <table class="w-full">
    <thead>
      <tr class="bg-gray-50 border-b border-gray-200">
        <th class="text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider px-4 py-3">Domain</th>
        <th class="text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider px-4 py-3">Type</th>
        <th class="text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider px-4 py-3">PHP</th>
        <th class="text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider px-4 py-3">Cache</th>
        <th class="text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider px-4 py-3">SSL</th>
        <th class="px-4 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($sites as $site): ?>
      <tr class="hover:bg-gray-50 transition-colors">
        <td class="px-4 py-3">
          <a href="/sites/<?= e($site['domain']) ?>" class="text-sm font-medium text-gray-900 hover:text-brand">
            <?= e($site['domain']) ?>
          </a>
        </td>
        <td class="px-4 py-3">
          <span class="text-xs text-gray-600"><?= e(ucfirst($site['type'])) ?></span>
        </td>
        <td class="px-4 py-3">
          <span class="text-[11px] font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">
            PHP <?= e($site['php_version']) ?>
          </span>
        </td>
        <td class="px-4 py-3">
          <?php if ($site['cache_enabled']): ?>
            <span class="text-[11px] font-medium bg-brand-pale text-brand px-2 py-0.5 rounded-full">FastCGI</span>
          <?php else: ?>
            <span class="text-[11px] text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">Disabled</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3">
          <?php if ($site['ssl_type'] === 'letsencrypt'): ?>
            <span class="text-[11px] font-medium bg-green-100 text-green-700 px-2 py-0.5 rounded-full">Let's Encrypt</span>
          <?php else: ?>
            <span class="text-[11px] text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">Self-signed</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-right">
          <a href="/sites/<?= e($site['domain']) ?>" class="inline-flex items-center text-xs text-gray-500 hover:text-brand gap-1 transition-colors">
            <i class="ti ti-settings text-sm"></i> Manage
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
