<?php $pageTitle = 'Databases'; ?>

<div class="grid grid-cols-3 gap-4">

  <!-- Add Database Form -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
    <h3 class="text-sm font-semibold text-gray-900 mb-4">Create Database</h3>

    <?php
      $creds = flash('db_credentials');
      $creds = $creds ? json_decode($creds, true) : null;
    ?>
    <?php if ($creds): ?>
    <div class="mb-4 bg-green-50 border border-green-200 rounded-lg p-3">
      <p class="text-xs font-semibold text-green-700 mb-2">✓ Database created — save credentials:</p>
      <div class="space-y-1 font-mono text-[11px] text-green-800">
        <p>DB: <strong><?= e($creds['name']) ?></strong></p>
        <p>User: <strong><?= e($creds['user']) ?></strong></p>
        <p>Pass: <strong><?= e($creds['pass']) ?></strong></p>
        <p>Host: <strong>localhost</strong></p>
      </div>
      <p class="text-[10px] text-green-600 mt-2">⚠ This password will not be shown again.</p>
    </div>
    <?php endif; ?>

    <form method="POST" action="/databases/add">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1">Database Name</label>
        <input type="text" name="name" required placeholder="mydb"
          pattern="[a-zA-Z0-9_]+"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
        <p class="text-[10px] text-gray-400 mt-1">Letters, numbers, underscores only.</p>
      </div>

      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1">DB Username <span class="text-gray-400">(optional)</span></label>
        <input type="text" name="user" placeholder="same as db name"
          pattern="[a-zA-Z0-9_]*"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
      </div>

      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Password <span class="text-gray-400">(optional — auto-generated)</span></label>
        <input type="text" name="pass" placeholder="leave blank to auto-generate"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
      </div>

      <button type="submit"
        class="w-full bg-brand hover:bg-brand-light text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        Create Database
      </button>
    </form>
  </div>

  <!-- Database list -->
  <div class="col-span-2">
    <div class="bg-white rounded-xl border border-gray-200">
      <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-800">All Databases</span>
        <span class="text-[10px] text-gray-400">Host: localhost · Port: 3306</span>
      </div>

      <?php $databases = $databases ?? []; ?>
      <?php if (empty($databases)): ?>
        <p class="px-5 py-10 text-sm text-gray-400 text-center">No databases found.</p>
      <?php else: ?>
      <div class="divide-y divide-gray-100">
        <?php foreach ($databases as $database): ?>
        <div class="px-5 py-3 flex items-center justify-between gap-4">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-900 truncate"><?= e($database) ?></p>
            <p class="text-[10px] text-gray-400">localhost:3306</p>
          </div>
          <div class="flex items-center gap-2">
            <form method="POST" action="/databases/backup">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <input type="hidden" name="name" value="<?= e($database) ?>">
              <button type="submit" title="Backup database" class="w-9 h-9 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors">
                <i class="ti ti-download"></i>
              </button>
            </form>
            <form method="POST" action="/databases/delete" onsubmit="return confirm('Delete database <?= e($database) ?>? This cannot be undone.')">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <input type="hidden" name="name" value="<?= e($database) ?>">
              <button type="submit" title="Delete database" class="w-9 h-9 bg-red-50 border border-red-200 text-red-500 rounded-lg hover:bg-red-100 transition-colors">
                <i class="ti ti-trash"></i>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
