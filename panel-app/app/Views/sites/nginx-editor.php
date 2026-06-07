<?php $pageTitle = t('nginx.editor.title') . ' — ' . $site['domain']; ?>

<!-- page header -->
<div class="mb-5">
  <a href="/sites/<?= e($site['domain']) ?>"
     class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm"></i> <?= e($site['domain']) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('nginx.editor.title')) ?></h1>
</div>

<div class="card overflow-hidden">

  <!-- header -->
  <div class="card-head">
    <div>
      <h2 class="card-title">
        <i class="ti ti-code text-zinc-400"></i>
        /etc/nginx/sites-available/<span class="text-ink"><?= e($site['domain']) ?></span>.conf
      </h2>
    </div>
    <span class="badge badge-warn">
      <i class="ti ti-alert-triangle text-xs"></i>
      <?= e(t('nginx.editor.caution')) ?>
    </span>
  </div>

  <!-- editor -->
  <form method="POST" action="/sites/<?= e($site['domain']) ?>/nginx">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

    <textarea name="nginx_conf"
              spellcheck="false"
              class="w-full mono text-xs text-emerald-300 bg-zinc-950 p-5 resize-y focus:outline-none focus:ring-0 border-0"
              style="min-height:520px; tab-size:4; line-height:1.65;"><?= e($nginxConf) ?></textarea>

    <div class="flex items-center gap-3 px-5 py-3.5 border-t border-zinc-100">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-device-floppy text-sm"></i> <?= e(t('nginx.editor.save')) ?>
      </button>
      <a href="/sites/<?= e($site['domain']) ?>" class="btn btn-ghost"><?= e(t('common.cancel')) ?></a>
      <span class="ml-auto text-[11px] text-zinc-400"><?= e(t('nginx.editor.note')) ?></span>
    </div>
  </form>

</div>
