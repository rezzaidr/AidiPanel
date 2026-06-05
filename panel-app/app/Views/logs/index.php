<?php $pageTitle = 'Logs'; ?>

<div class="grid grid-cols-4 gap-4">

  <!-- Filters sidebar -->
  <div class="bg-white rounded-xl border border-gray-200 p-4 h-fit">
    <h3 class="text-xs font-semibold text-gray-700 mb-3">Log Viewer</h3>

    <form method="GET" action="/logs">
      <div class="mb-3">
        <label class="block text-[10px] text-gray-500 mb-1">Domain</label>
        <select name="domain" class="w-full text-xs px-2.5 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-brand">
          <option value="">— Select domain —</option>
          <?php foreach ($sites as $s): ?>
          <option value="<?= e($s['domain']) ?>" <?= $domain === $s['domain'] ? 'selected' : '' ?>>
            <?= e($s['domain']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="block text-[10px] text-gray-500 mb-1">Log Type</label>
        <select name="type" class="w-full text-xs px-2.5 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-brand">
          <option value="access"    <?= $type === 'access'    ? 'selected' : '' ?>>Nginx Access</option>
          <option value="error"     <?= $type === 'error'     ? 'selected' : '' ?>>Nginx Error</option>
          <option value="aidipanel" <?= $type === 'aidipanel' ? 'selected' : '' ?>>AidiPanel CLI</option>
        </select>
      </div>

      <div class="mb-4">
        <label class="block text-[10px] text-gray-500 mb-1">Lines</label>
        <select name="lines" class="w-full text-xs px-2.5 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-brand">
          <?php foreach ([50, 100, 200, 500] as $n): ?>
          <option value="<?= $n ?>" <?= $lines == $n ? 'selected' : '' ?>><?= $n ?> lines</option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit"
        class="w-full bg-brand text-white text-xs font-medium py-2 rounded-lg hover:bg-brand-light transition-colors">
        <i class="ti ti-refresh mr-1"></i> Load Log
      </button>
    </form>
  </div>

  <!-- Log output -->
  <div class="col-span-3 bg-white rounded-xl border border-gray-200">
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100">
      <div>
        <span class="text-sm font-semibold text-gray-800">
          <?= $domain ? e($domain) . ' — ' . e(ucfirst($type)) : 'Select a domain' ?>
        </span>
        <?php if ($logFile): ?>
        <span class="ml-2 text-[10px] font-mono text-gray-400"><?= e($logFile) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($logContent): ?>
      <span class="text-[10px] text-gray-400"><?= e($lines) ?> lines</span>
      <?php endif; ?>
    </div>

    <?php if (!$domain): ?>
      <div class="px-5 py-16 text-center">
        <i class="ti ti-file-text text-4xl text-gray-200 block mb-2"></i>
        <p class="text-sm text-gray-400">Select a domain and log type to view logs.</p>
      </div>
    <?php elseif (!$logContent): ?>
      <div class="px-5 py-16 text-center">
        <i class="ti ti-file-off text-4xl text-gray-200 block mb-2"></i>
        <p class="text-sm text-gray-400">Log file is empty or not found.</p>
        <p class="text-xs text-gray-300 mt-1 font-mono"><?= e($logFile) ?></p>
      </div>
    <?php else: ?>
      <div class="overflow-auto" style="max-height: 540px;">
        <pre class="text-[10px] font-mono text-green-300 bg-gray-950 p-5 leading-relaxed whitespace-pre-wrap break-all"><?= e($logContent) ?></pre>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Panel Activity Log -->
<div class="mt-4 bg-white rounded-xl border border-gray-200">
  <div class="px-5 py-3.5 border-b border-gray-100">
    <span class="text-sm font-semibold text-gray-800">Panel Activity Log</span>
  </div>
  <div class="divide-y divide-gray-50 max-h-64 overflow-y-auto">
    <?php if (empty($activity)): ?>
      <p class="px-5 py-6 text-sm text-gray-400 text-center">No activity yet.</p>
    <?php else: ?>
      <?php foreach ($activity as $log): ?>
      <div class="flex items-center gap-3 px-5 py-2.5">
        <span class="text-[11px] font-mono text-brand min-w-0"><?= e($log['action']) ?></span>
        <span class="text-xs text-gray-500 flex-1 truncate"><?= e($log['detail']) ?></span>
        <span class="text-[10px] text-gray-400 shrink-0"><?= e($log['username'] ?? 'system') ?></span>
        <span class="text-[10px] text-gray-300 shrink-0"><?= e($log['created_at']) ?></span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
