<?php $pageTitle = e($site['domain']); ?>

<div class="mb-4">
  <a href="/sites" class="text-xs text-gray-400 hover:text-gray-700 flex items-center gap-1">
    <i class="ti ti-arrow-left text-sm"></i> Back to Sites
  </a>
</div>

<div class="grid grid-cols-3 gap-4 mb-4">

  <!-- Site Info -->
  <div class="col-span-2 bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h2 class="text-base font-semibold text-gray-900"><?= e($site['domain']) ?></h2>
        <p class="text-xs text-gray-400 mt-0.5"><?= e(ucfirst($site['type'])) ?> · Created <?= e($site['created_at']) ?></p>
      </div>
      <a href="https://<?= e($site['domain']) ?>" target="_blank"
         class="text-xs text-gray-400 hover:text-brand flex items-center gap-1">
        <i class="ti ti-external-link"></i> Visit
      </a>
    </div>

    <div class="grid grid-cols-2 gap-3 text-xs">
      <div class="bg-gray-50 rounded-lg p-3">
        <p class="text-gray-400 mb-0.5">Webroot</p>
        <p class="font-mono text-gray-700 text-[11px] break-all"><?= e($site['webroot']) ?></p>
      </div>
      <div class="bg-gray-50 rounded-lg p-3">
        <p class="text-gray-400 mb-0.5">FastCGI Cache</p>
        <p class="font-medium <?= $site['cache_enabled'] ? 'text-brand' : 'text-gray-500' ?>">
          <?= $site['cache_enabled'] ? 'Enabled' : 'Disabled' ?>
        </p>
      </div>
      <div class="bg-gray-50 rounded-lg p-3">
        <p class="text-gray-400 mb-0.5">SSL Certificate</p>
        <p class="font-medium <?= $site['ssl_type'] === 'letsencrypt' ? 'text-green-600' : 'text-gray-500' ?>">
          <?= e($site['ssl_type'] === 'letsencrypt' ? "Let's Encrypt" : ucfirst($site['ssl_type'])) ?>
          <?= $sslExpiry ? " · expires {$sslExpiry}" : '' ?>
        </p>
      </div>
      <div class="bg-gray-50 rounded-lg p-3">
        <p class="text-gray-400 mb-0.5">Nginx Config</p>
        <a href="/sites/<?= e($site['domain']) ?>/nginx" class="text-brand hover:underline text-[11px] font-mono">
          /etc/nginx/sites-available/<?= e($site['domain']) ?>.conf
        </a>
      </div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <h3 class="text-xs font-semibold text-gray-700 mb-3">Actions</h3>

    <!-- Change PHP Version -->
    <form method="POST" action="/sites/<?= e($site['domain']) ?>/php" class="mb-3">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <label class="block text-[10px] text-gray-500 mb-1">PHP Version</label>
      <div class="flex gap-2">
        <select name="php_version" class="flex-1 text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-brand">
          <?php foreach (['8.1','8.2','8.3'] as $v): ?>
          <option value="<?= e($v) ?>" <?= $site['php_version'] === $v ? 'selected' : '' ?>>PHP <?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-brand text-white text-xs px-3 py-1.5 rounded-lg hover:bg-brand-light">Apply</button>
      </div>
    </form>

    <!-- Cache toggle -->
    <form method="POST" action="/cache/toggle" class="mb-3">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
      <input type="hidden" name="action" value="<?= $site['cache_enabled'] ? 'disable' : 'enable' ?>">
      <button type="submit" class="w-full text-xs border <?= $site['cache_enabled'] ? 'border-red-200 text-red-500 hover:bg-red-50' : 'border-brand text-brand hover:bg-brand-pale' ?> rounded-lg px-3 py-2 transition-colors">
        <i class="ti ti-bolt mr-1"></i>
        <?= $site['cache_enabled'] ? 'Disable Cache' : 'Enable Cache' ?>
      </button>
    </form>

    <!-- SSL -->
    <?php if ($site['ssl_type'] !== 'letsencrypt'): ?>
    <a href="/ssl" class="w-full block text-center text-xs border border-green-200 text-green-600 hover:bg-green-50 rounded-lg px-3 py-2 transition-colors mb-3">
      <i class="ti ti-lock mr-1"></i> Install SSL
    </a>
    <?php endif; ?>

    <!-- Purge cache -->
    <form method="POST" action="/cache/purge" class="mb-3">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
      <button type="submit" class="w-full text-xs border border-amber-200 text-amber-600 hover:bg-amber-50 rounded-lg px-3 py-2 transition-colors">
        <i class="ti ti-refresh mr-1"></i> Purge Cache
      </button>
    </form>

    <!-- Delete -->
    <form method="POST" action="/sites/<?= e($site['domain']) ?>/delete"
          onsubmit="return confirm('Delete site <?= e($site['domain']) ?>? This cannot be undone.')">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <button type="submit" class="w-full text-xs border border-red-200 text-red-500 hover:bg-red-50 rounded-lg px-3 py-2 transition-colors">
        <i class="ti ti-trash mr-1"></i> Delete Site
      </button>
    </form>
  </div>
</div>

<!-- Nginx Editor link -->
<div class="bg-white rounded-xl border border-gray-200 mb-4">
  <div class="flex items-center justify-between px-5 py-3.5">
    <div class="flex items-center gap-2">
      <i class="ti ti-code text-gray-400"></i>
      <span class="text-sm font-medium text-gray-800">Nginx Configuration</span>
    </div>
    <a href="/sites/<?= e($site['domain']) ?>/nginx"
       class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
      <i class="ti ti-edit mr-1"></i> Edit Config
    </a>
  </div>
  <div class="border-t border-gray-100 px-5 py-3 bg-gray-50 rounded-b-xl">
    <pre class="text-[10px] font-mono text-gray-600 overflow-x-auto max-h-24 leading-relaxed"><?= e(substr($nginxConf ?? '', 0, 400)) ?>...</pre>
  </div>
</div>

<!-- Activity log for this site -->
<?php if (!empty($logs)): ?>
<div class="bg-white rounded-xl border border-gray-200">
  <div class="px-5 py-3 border-b border-gray-100">
    <span class="text-sm font-medium text-gray-800">Activity</span>
  </div>
  <div class="divide-y divide-gray-50">
    <?php foreach ($logs as $log): ?>
    <div class="flex items-center gap-3 px-5 py-2.5">
      <span class="text-xs font-mono text-brand"><?= e($log['action']) ?></span>
      <span class="text-xs text-gray-500 flex-1"><?= e($log['detail']) ?></span>
      <span class="text-[10px] text-gray-300"><?= e($log['created_at']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
