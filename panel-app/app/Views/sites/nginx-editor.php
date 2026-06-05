<?php $pageTitle = 'Nginx Config — ' . e($site['domain']); ?>

<div class="mb-4">
  <a href="/sites/<?= e($site['domain']) ?>" class="text-xs text-gray-400 hover:text-gray-700 flex items-center gap-1">
    <i class="ti ti-arrow-left text-sm"></i> Back to <?= e($site['domain']) ?>
  </a>
</div>

<div class="bg-white rounded-xl border border-gray-200">
  <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-200">
    <div>
      <span class="text-sm font-semibold text-gray-900">Nginx Config Editor</span>
      <span class="ml-2 text-xs font-mono text-gray-400">/etc/nginx/sites-available/<?= e($site['domain']) ?>.conf</span>
    </div>
    <span class="text-[10px] bg-amber-50 border border-amber-200 text-amber-700 px-2.5 py-1 rounded-full">
      <i class="ti ti-alert-triangle mr-0.5"></i> Edit with caution
    </span>
  </div>

  <form method="POST" action="/sites/<?= e($site['domain']) ?>/nginx">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
    <textarea name="nginx_conf" spellcheck="false"
      class="w-full font-mono text-xs text-gray-700 bg-gray-950 text-green-300 p-5 resize-y focus:outline-none rounded-b-xl"
      style="min-height: 520px; tab-size: 4;"><?= e($nginxConf) ?></textarea>

    <div class="flex items-center gap-3 px-5 py-3.5 border-t border-gray-100">
      <button type="submit"
        class="bg-brand hover:bg-brand-light text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
        <i class="ti ti-device-floppy mr-1"></i> Save & Reload Nginx
      </button>
      <a href="/sites/<?= e($site['domain']) ?>" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
      <span class="ml-auto text-[10px] text-gray-400">Nginx config is tested before saving. Backup is created automatically.</span>
    </div>
  </form>
</div>
