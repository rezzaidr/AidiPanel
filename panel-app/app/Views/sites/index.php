<?php
$pageTitle = t('nav.sites');
$count     = count($sites);

$appIcon = static function (string $type): string {
    return match ($type) {
        'wordpress' => 'ti-brand-wordpress',
        'laravel'   => 'ti-code',
        'proxy'     => 'ti-arrow-guide',
        'static'    => 'ti-file-text',
        default     => 'ti-code',
    };
};
$appLabel = static function (string $type): string {
    return match ($type) {
        'wordpress' => t('app.wordpress'),
        'laravel'   => t('app.laravel'),
        'static'    => t('app.static'),
        'proxy'     => t('app.proxy'),
        default     => t('app.php'),
    };
};
$usesPhp = static function (string $type): bool {
    return in_array($type, ['wordpress', 'laravel', 'php'], true);
};
?>

<!-- page header -->
<div class="flex items-center justify-between mb-5">
  <div>
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('nav.sites')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('sites.subtitle', ['n' => $count])) ?></p>
  </div>
  <a href="/sites/add" class="btn btn-primary"><i class="ti ti-plus text-sm"></i> <?= e(t('sites.new')) ?></a>
</div>

<?php if (empty($sites)): ?>

<div class="card px-8 py-16 text-center">
  <i class="ti ti-world text-5xl text-zinc-200 block mb-3"></i>
  <p class="text-sm font-medium text-zinc-700 mb-1"><?= e(t('sites.empty')) ?></p>
  <p class="text-xs text-zinc-400 mb-5"><?= e(t('sites.add_first')) ?></p>
  <a href="/sites/add" class="btn btn-primary"><i class="ti ti-plus text-sm"></i> <?= e(t('sites.new')) ?></a>
</div>

<?php else: ?>

<!-- toolbar -->
<div class="flex items-center gap-3 mb-4">
  <div class="relative flex-1 max-w-xs">
    <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-sm pointer-events-none"></i>
    <input type="text"
           id="siteSearch"
           placeholder="<?= e(t('sites.search_ph')) ?>"
           oninput="filterSites()"
           class="inp pl-9 w-full">
  </div>
  <select id="appFilter"
          onchange="filterSites()"
          class="bg-white border border-zinc-200 hover:border-zinc-300 focus:border-ink/40 focus:ring-2 focus:ring-ink/10 rounded-lg px-3 py-2 text-xs font-medium text-zinc-600 outline-none cursor-pointer">
    <option value="all"><?= e(t('sites.filter_all')) ?></option>
    <option value="wordpress"><?= e(t('app.wordpress')) ?></option>
    <option value="laravel"><?= e(t('app.laravel')) ?></option>
    <option value="php"><?= e(t('app.php')) ?></option>
    <option value="static"><?= e(t('app.static')) ?></option>
    <option value="proxy"><?= e(t('app.proxy')) ?></option>
  </select>
</div>

<!-- sites table -->
<div class="card overflow-hidden">
  <table class="tbl" id="sitesTable">
    <thead>
      <tr>
        <th><?= e(t('col.domain')) ?></th>
        <th><?= e(t('col.app')) ?></th>
        <th class="text-right"><?= e(t('col.action')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $site):
        $type       = $site['type'] ?? 'php';
        $domain     = $site['domain'];
        $isStatic   = $type === 'static';
        $iconBg     = $isStatic ? 'bg-zinc-100' : 'bg-ink-pale';
        $iconColor  = $isStatic ? 'text-zinc-400' : 'text-ink';
        $hasLe      = ($site['ssl_type'] ?? '') === 'letsencrypt';
        $hasCache   = (bool) ($site['cache_enabled'] ?? false);
        $phpVer     = $site['php_version'] ?? '';
        $createdAt  = $site['created_at'] ?? '';
        $createdFmt = $createdAt ? date('M j, Y', strtotime($createdAt)) : '';
    ?>
      <tr data-domain="<?= e($domain) ?>" data-type="<?= e($type) ?>" class="cursor-pointer">
        <td class="px-5 py-3.5">
          <div class="flex items-center gap-3">
            <span class="w-8 h-8 rounded-lg <?= $iconBg ?> flex items-center justify-center shrink-0">
              <i class="ti <?= e($appIcon($type)) ?> <?= $iconColor ?>"></i>
            </span>
            <div>
              <div class="font-medium text-zinc-900 flex items-center gap-1.5">
                <?= e($domain) ?>
                <?php if ($hasLe): ?>
                  <i class="ti ti-lock-check text-emerald-500 text-sm" title="SSL active"></i>
                <?php endif; ?>
                <?php if ($hasCache): ?>
                  <i class="ti ti-bolt text-speed text-sm" title="Cache on"></i>
                <?php endif; ?>
              </div>
              <?php if ($createdFmt): ?>
                <div class="text-[11px] text-zinc-400"><?= e(t('sites.created_on', ['date' => $createdFmt])) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td class="px-3 py-3.5">
          <span class="text-xs text-zinc-700"><?= e($appLabel($type)) ?></span>
          <?php if ($phpVer && $usesPhp($type)): ?>
            <span class="mono text-[11px] text-zinc-400">· PHP <?= e($phpVer) ?></span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3.5">
          <div class="flex items-center justify-end">
            <a href="/sites/<?= e($domain) ?>"
               class="text-xs font-semibold text-ink hover:bg-ink-pale px-2.5 py-1.5 rounded-md transition-colors">
              <?= e(t('action.manage')) ?>
            </a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function filterSites() {
  var q    = document.getElementById('siteSearch').value.toLowerCase();
  var type = document.getElementById('appFilter').value;
  document.querySelectorAll('#sitesTable tbody tr[data-domain]').forEach(function (tr) {
    var show = (type === 'all' || tr.dataset.type === type)
            && (!q || tr.dataset.domain.includes(q));
    tr.style.display = show ? '' : 'none';
  });
}
</script>

<?php endif; ?>
