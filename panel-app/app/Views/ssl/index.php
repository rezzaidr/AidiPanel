<?php $pageTitle = t('admin.ssl.title'); ?>

<!-- page header -->
<div class="mb-5">
  <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <i class="ti ti-arrow-left text-sm" aria-hidden="true"></i> <?= e(t('admin.title')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.ssl.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.ssl.desc')) ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

  <!-- Install form -->
  <div class="space-y-4">
    <div class="card p-5">
      <div class="flex items-center gap-2.5 mb-4">
        <div class="w-8 h-8 bg-emerald-50 rounded-lg flex items-center justify-center">
          <i class="ti ti-lock text-emerald-600 text-base" aria-hidden="true"></i>
        </div>
        <h2 class="font-head font-semibold text-sm text-zinc-900"><?= e(t('ssl.install_title')) ?></h2>
      </div>

      <form method="POST" action="/ssl/install" class="space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

        <div>
          <label class="lbl"><?= e(t('ssl.install_domain')) ?></label>
          <select name="domain" class="inp">
            <?php foreach ($sites as $site): ?>
            <option value="<?= e($site['domain']) ?>"><?= e($site['domain']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="lbl">
            <?= e(t('ssl.install_email')) ?>
            <span class="text-zinc-400 font-normal">(optional)</span>
          </label>
          <input type="email" name="email" placeholder="admin@example.com" class="inp">
          <p class="hint"><?= e(t('ssl.install_email_hint')) ?></p>
        </div>

        <div class="bg-speed-pale border border-speed/20 rounded-lg px-3 py-2.5 text-[11px] text-speed flex items-start gap-2">
          <i class="ti ti-info-circle shrink-0 mt-0.5" aria-hidden="true"></i>
          <?= e(t('ssl.install_dns_note')) ?>
        </div>

        <button type="submit" class="btn btn-primary w-full">
          <i class="ti ti-lock text-sm" aria-hidden="true"></i> <?= e(t('ssl.install_btn')) ?>
        </button>
      </form>
    </div>

    <form method="POST" action="/ssl/renew">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <button type="submit" class="btn btn-secondary w-full">
        <i class="ti ti-refresh text-sm" aria-hidden="true"></i> <?= e(t('ssl.renew_all')) ?>
      </button>
    </form>
  </div>

  <!-- Certificate status table -->
  <div class="lg:col-span-2 card overflow-hidden">
    <div class="card-head">
      <h2 class="card-title"><i class="ti ti-certificate text-zinc-400" aria-hidden="true"></i> <?= e(t('ssl.cert_status_title')) ?></h2>
    </div>

    <?php if (empty($sites)): ?>
      <div class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('sites.empty')) ?></div>
    <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= e(t('col.domain')) ?></th>
          <th><?= e(t('col.cert_type')) ?></th>
          <th><?= e(t('col.expiry')) ?></th>
          <th><?= e(t('col.status')) ?></th>
          <th class="text-right"><?= e(t('col.action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sites as $site):
          $cert       = $certs[$site['domain']] ?? ['type' => 'none', 'expiry' => null, 'daysLeft' => null];
          $isLE       = $cert['type'] === "Let's Encrypt";
          $daysLeft   = $cert['daysLeft'];
          $isExpiring = $daysLeft !== null && $daysLeft < 30 && $daysLeft >= 0;
          $isExpired  = $daysLeft !== null && $daysLeft < 0;
        ?>
        <tr>
          <td class="font-medium text-zinc-900">
            <a href="/sites/<?= e($site['domain']) ?>" class="hover:text-ink"><?= e($site['domain']) ?></a>
          </td>
          <td>
            <span class="text-xs <?= $isLE ? 'text-emerald-700 font-medium' : 'text-zinc-400' ?>">
              <?= e($cert['type'] === 'none' ? '—' : $cert['type']) ?>
            </span>
          </td>
          <td class="mono text-xs text-zinc-500">
            <?= $cert['expiry'] ? e($cert['expiry']) : '—' ?>
          </td>
          <td>
            <?php if ($cert['type'] === 'none'): ?>
              <span class="badge badge-muted"><?= e(t('ssl.no_ssl')) ?></span>
            <?php elseif ($isExpired): ?>
              <span class="badge badge-danger"><?= e(t('ssl.expired')) ?></span>
            <?php elseif ($isExpiring): ?>
              <span class="badge badge-warn">
                <?= e(t('ssl.expiring_n', ['n' => $daysLeft])) ?>
              </span>
            <?php else: ?>
              <span class="badge badge-ok">
                <span class="dot bg-emerald-500"></span>
                <?= $daysLeft !== null ? e(t('ssl.valid_n', ['n' => $daysLeft])) : e(t('site.ov.ssl_active')) ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="text-right">
            <?php if (!$isLE): ?>
            <form method="POST" action="/ssl/install" class="inline">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
              <button type="submit"
                      class="text-xs font-semibold text-emerald-600 hover:bg-emerald-50 px-2.5 py-1.5 rounded-md">
                <?= e(t('ssl.install_link')) ?>
              </button>
            </form>
            <?php else: ?>
            <form method="POST" action="/ssl/renew" class="inline">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
              <button type="submit"
                      class="text-xs font-semibold text-ink hover:bg-ink-pale px-2.5 py-1.5 rounded-md">
                <?= e(t('ssl.renew')) ?>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="px-5 py-3 border-t border-zinc-100">
      <p class="text-[11px] text-zinc-400 flex items-center gap-1.5">
        <i class="ti ti-info-circle text-sm" aria-hidden="true"></i>
        <?= e(t('ssl.auto_renew_note')) ?>
        <span class="mono bg-zinc-100 px-1.5 py-0.5 rounded text-[10px]">0 2 * * * certbot renew --nginx</span>
      </p>
    </div>
    <?php endif; ?>
  </div>

</div>
