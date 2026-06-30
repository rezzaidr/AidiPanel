<?php $pageTitle = t('admin.logs.title'); ?>

<!-- page header -->
<div class="mb-5">
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.logs.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.logs.desc')) ?></p>
</div>

<!-- Filter toolbar -->
<div class="card p-4 mb-5">
  <form method="GET" action="/admin/logs" class="flex flex-wrap items-end gap-3">
    <div class="min-w-[200px] flex-1">
      <label class="lbl"><?= e(t('col.domain')) ?></label>
      <select name="domain" class="inp w-full h-9">
        <option value=""><?= e(t('logs.domain_ph')) ?></option>
        <?php foreach ($sites as $s): ?>
        <option value="<?= e($s['domain']) ?>" <?= $domain === $s['domain'] ? 'selected' : '' ?>>
          <?= e($s['domain']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="w-44">
      <label class="lbl"><?= e(t('logs.log_type')) ?></label>
      <select name="type" class="inp w-full h-9">
        <option value="access"    <?= $type === 'access'    ? 'selected' : '' ?>>Nginx Access</option>
        <option value="error"     <?= $type === 'error'     ? 'selected' : '' ?>>Nginx Error</option>
        <option value="aidipanel" <?= $type === 'aidipanel' ? 'selected' : '' ?>>AidiPanel CLI</option>
      </select>
    </div>

    <div class="w-32">
      <label class="lbl"><?= e(t('logs.lines')) ?></label>
      <select name="lines" class="inp w-full h-9">
        <?php foreach ([50, 100, 200, 500] as $n): ?>
        <option value="<?= $n ?>" <?= $lines == $n ? 'selected' : '' ?>><?= $n ?> lines</option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary shrink-0 h-9">
      <?= icon('refresh', 'text-sm') ?> <?= e(t('logs.load_btn')) ?>
    </button>
  </form>
</div>

<!-- Log output -->
<div class="card overflow-hidden mb-5">
  <div class="card-head">
    <div>
      <h2 class="card-title">
        <?= icon('file-text', 'text-zinc-400') ?>
        <?= $domain ? e($domain) . ' — ' . e(ucfirst($type)) : 'Log output' ?>
      </h2>
      <?php if ($logFile): ?>
        <p class="mono text-[10px] text-zinc-400 mt-0.5"><?= e($logFile) ?></p>
      <?php endif; ?>
    </div>
    <?php if ($logContent): ?>
      <span class="badge badge-muted"><?= e($lines) ?> lines</span>
    <?php endif; ?>
  </div>

  <?php if (!$domain): ?>
    <div class="px-5 py-16 text-center">
      <?= icon('file-text', 'text-5xl text-zinc-200 block mb-3') ?>
      <p class="text-sm text-zinc-400"><?= e(t('logs.select_hint')) ?></p>
    </div>
  <?php elseif (!$logContent): ?>
    <div class="px-5 py-16 text-center">
      <?= icon('file-off', 'text-5xl text-zinc-200 block mb-3') ?>
      <p class="text-sm text-zinc-400"><?= e(t('logs.empty_log')) ?></p>
      <?php if ($logFile): ?>
        <p class="mono text-[10px] text-zinc-300 mt-1"><?= e($logFile) ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="overflow-auto max-h-[540px]">
      <pre class="text-[10px] mono text-emerald-300 bg-zinc-950 p-5 leading-relaxed whitespace-pre-wrap break-all"><?= e($logContent) ?></pre>
    </div>
  <?php endif; ?>
</div>

<!-- Panel activity log -->
<div class="card overflow-hidden">
  <div class="card-head">
    <h2 class="card-title"><?= icon('activity', 'text-zinc-400') ?> <?= e(t('logs.activity_title')) ?></h2>
  </div>
  <?php if (empty($activity)): ?>
    <div class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('logs.activity_empty')) ?></div>
  <?php else: ?>
  <div class="divide-y divide-zinc-50 max-h-72 overflow-y-auto">
    <?php foreach ($activity as $log): ?>
    <div class="flex items-center gap-3 px-5 py-2.5">
      <span class="badge badge-info mono text-[10px] shrink-0"><?= e($log['action']) ?></span>
      <span class="text-xs text-zinc-500 flex-1 truncate"><?= e($log['detail']) ?></span>
      <span class="text-[10px] text-zinc-400 shrink-0"><?= e($log['username'] ?? 'system') ?></span>
      <span class="mono text-[10px] text-zinc-300 shrink-0"><?= e(fmt_dt($log['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
