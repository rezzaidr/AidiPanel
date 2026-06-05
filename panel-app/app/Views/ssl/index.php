<?php $pageTitle = 'SSL / TLS'; ?>

<div class="grid grid-cols-3 gap-4">

  <!-- Install SSL form -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
    <div class="flex items-center gap-2 mb-4">
      <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center">
        <i class="ti ti-lock text-green-600"></i>
      </div>
      <h3 class="text-sm font-semibold text-gray-900">Install Let's Encrypt</h3>
    </div>

    <form method="POST" action="/ssl/install">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Domain</label>
        <select name="domain" class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
          <?php foreach ($sites as $site): ?>
          <option value="<?= e($site['domain']) ?>"><?= e($site['domain']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Email <span class="text-gray-400">(optional)</span></label>
        <input type="email" name="email" placeholder="admin@example.com"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
        <p class="text-[10px] text-gray-400 mt-1">Used for expiry notifications from Let's Encrypt.</p>
      </div>

      <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 mb-4">
        <p class="text-[10px] text-blue-700">
          <i class="ti ti-info-circle mr-1"></i>
          Make sure your domain's DNS points to this server before installing SSL.
          Port 80 must be accessible.
        </p>
      </div>

      <button type="submit"
        class="w-full bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        <i class="ti ti-lock mr-1"></i> Install SSL Certificate
      </button>
    </form>

    <!-- Renew all -->
    <form method="POST" action="/ssl/renew" class="mt-3">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <button type="submit"
        class="w-full border border-gray-200 text-gray-600 text-sm py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
        <i class="ti ti-refresh mr-1"></i> Renew All Certificates
      </button>
    </form>
  </div>

  <!-- SSL Status table -->
  <div class="col-span-2 bg-white rounded-xl border border-gray-200">
    <div class="px-5 py-3.5 border-b border-gray-100">
      <span class="text-sm font-semibold text-gray-800">Certificate Status</span>
    </div>

    <?php if (empty($sites)): ?>
      <p class="px-5 py-10 text-sm text-gray-400 text-center">No sites configured.</p>
    <?php else: ?>
    <table class="w-full">
      <thead>
        <tr class="bg-gray-50 border-b border-gray-100">
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Domain</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Type</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Expiry</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Status</th>
          <th class="px-5 py-2.5"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($sites as $site):
          $cert = $certs[$site['domain']] ?? ['type' => 'none', 'expiry' => null, 'daysLeft' => null];
          $isLE = $cert['type'] === "Let's Encrypt";
          $isExpiring = $cert['daysLeft'] !== null && $cert['daysLeft'] < 30;
          $isExpired  = $cert['daysLeft'] !== null && $cert['daysLeft'] < 0;
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-5 py-3">
            <a href="/sites/<?= e($site['domain']) ?>" class="text-sm font-medium text-gray-900 hover:text-brand">
              <?= e($site['domain']) ?>
            </a>
          </td>
          <td class="px-5 py-3">
            <span class="text-xs <?= $isLE ? 'text-green-700' : 'text-gray-500' ?>">
              <?= e($cert['type']) ?>
            </span>
          </td>
          <td class="px-5 py-3 text-xs text-gray-500">
            <?= $cert['expiry'] ? e($cert['expiry']) : '—' ?>
          </td>
          <td class="px-5 py-3">
            <?php if ($cert['type'] === 'none'): ?>
              <span class="text-[11px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">No SSL</span>
            <?php elseif ($isExpired): ?>
              <span class="text-[11px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Expired</span>
            <?php elseif ($isExpiring): ?>
              <span class="text-[11px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">
                Expiring (<?= e($cert['daysLeft']) ?>d)
              </span>
            <?php else: ?>
              <span class="text-[11px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                Valid <?= $cert['daysLeft'] !== null ? '(' . e($cert['daysLeft']) . 'd)' : '' ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 text-right">
            <?php if (!$isLE): ?>
              <form method="POST" action="/ssl/install" class="inline">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
                <button type="submit" class="text-xs text-green-600 hover:text-green-700">
                  Install SSL →
                </button>
              </form>
            <?php else: ?>
              <form method="POST" action="/ssl/renew" class="inline">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
                <button type="submit" class="text-xs text-gray-400 hover:text-brand">Renew</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div class="px-5 py-3 border-t border-gray-100">
      <p class="text-[10px] text-gray-400">
        <i class="ti ti-info-circle mr-1"></i>
        Auto-renewal is configured via cron: <code class="bg-gray-100 px-1 rounded">0 2 * * * certbot renew --nginx</code>
      </p>
    </div>
  </div>
</div>
