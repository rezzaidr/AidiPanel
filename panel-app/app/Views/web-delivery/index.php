<?php
$pageTitle = t('admin.web_delivery.title');
$status = is_array($delivery ?? null) ? $delivery : \Core\WebDeliveryStatus::unavailable();
$available = !empty($status['available']);
$sample = !empty($status['sample']);
$total = (int) ($status['https_sites_total'] ?? 0);
$http2Configured = (int) ($status['http2_configured'] ?? 0);
$http2Unknown = (int) ($status['http2_unknown'] ?? 0);
$http3Configured = (int) ($status['http3_configured'] ?? 0);
$http3Partial = (int) ($status['http3_partial'] ?? 0);
$http3Missing = (int) ($status['http3_not_configured'] ?? 0);
$http3Unknown = (int) ($status['http3_unknown'] ?? 0);

$stateBadge = static function (string $state): array {
    return match ($state) {
        'configured', 'detected', 'listening' => ['badge-ok', 'bg-emerald-500'],
        'partial', 'unknown'                  => ['badge-warn', 'bg-amber-500'],
        default                               => ['badge-muted', ''],
    };
};
$stateLabel = static function (string $state): string {
    $allowed = ['configured', 'not_configured', 'detected', 'not_detected', 'listening', 'not_listening', 'partial', 'unknown', 'not_tested'];
    return t('admin.web_delivery.state.' . (in_array($state, $allowed, true) ? $state : 'unknown'));
};
$renderBadge = static function (string $state) use ($stateBadge, $stateLabel): string {
    [$class, $dot] = $stateBadge($state);
    $dotHtml = $dot !== '' ? '<span class="dot ' . e($dot) . '"></span>' : '';
    return '<span class="badge ' . e($class) . '">' . $dotHtml . e($stateLabel($state)) . '</span>';
};

if (!$available || $http2Unknown > 0) {
    $http2State = 'unknown';
} elseif ($total === 0 || $http2Configured === 0) {
    $http2State = 'not_configured';
} elseif ($http2Configured === $total) {
    $http2State = 'configured';
} else {
    $http2State = 'partial';
}

if (!$available || $http3Unknown > 0) {
    $http3State = 'unknown';
} elseif ($total === 0 || ($http3Configured === 0 && $http3Partial === 0)) {
    $http3State = 'not_configured';
} elseif ($http3Configured === $total) {
    $http3State = 'configured';
} else {
    $http3State = 'partial';
}

$recommendations = [];
if (($status['brotli_module'] ?? 'unknown') === 'not_detected') {
    $recommendations[] = t('admin.web_delivery.rec_brotli');
}
if (($status['http3_module'] ?? 'unknown') === 'not_detected') {
    $recommendations[] = t('admin.web_delivery.rec_http3_module');
}
if (($status['http3_module'] ?? 'unknown') === 'detected'
    && $total > 0
    && ($http3Configured + $http3Partial + $http3Unknown) === 0) {
    $recommendations[] = t('admin.web_delivery.rec_http3_available');
}
if (($http3Configured + $http3Partial) > 0 && ($status['udp_443'] ?? 'unknown') !== 'listening') {
    $recommendations[] = t('admin.web_delivery.rec_http3_listener');
}
$recommendations[] = t('admin.web_delivery.rec_observation');
$gzipDetail = match ((string) ($status['gzip'] ?? 'unknown')) {
    'configured'     => t('admin.web_delivery.gzip_global'),
    'not_configured' => t('admin.web_delivery.gzip_missing'),
    default          => t('admin.web_delivery.check_incomplete'),
};
?>

<div class="space-y-5">
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
      <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.web_delivery.title')) ?></h1>
      <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.web_delivery.desc')) ?></p>
    </div>
    <div class="flex items-center gap-3 sm:justify-end">
      <?php if (!$sample): ?>
      <span class="text-xs text-zinc-400">
        <?= e(t('admin.web_delivery.last_checked')) ?>:
        <span class="text-zinc-600"><?= e(($status['checked_at'] ?? '') !== '' ? fmt_dt((string) $status['checked_at']) : '—') ?></span>
      </span>
      <a href="/admin/web-delivery" class="btn btn-secondary btn-sm">
        <?= icon('refresh', 'text-sm') ?> <?= e(t('admin.web_delivery.refresh')) ?>
      </a>
      <?php else: ?>
      <button type="button" disabled aria-disabled="true" class="btn btn-secondary btn-sm opacity-60 cursor-not-allowed">
        <?= icon('refresh', 'text-sm') ?> <?= e(t('admin.web_delivery.refresh')) ?>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($sample): ?>
  <div class="card p-5 flex items-start gap-3">
    <?= icon('info-circle', 'text-indigo-500 text-lg mt-0.5') ?>
    <div>
      <p class="text-sm font-semibold text-zinc-800"><?= e(t('admin.web_delivery.sample_status')) ?></p>
      <p class="text-xs text-zinc-500 mt-1"><?= e(t('admin.web_delivery.sample_desc')) ?></p>
    </div>
  </div>
  <?php elseif (!$available): ?>
  <div class="card p-5 flex items-start gap-3 border-amber-200">
    <?= icon('info-circle', 'text-amber-500 text-lg mt-0.5') ?>
    <div>
      <p class="text-sm font-semibold text-zinc-800"><?= e(t('admin.web_delivery.unavailable_title')) ?></p>
      <p class="text-xs text-zinc-500 mt-1"><?= e(t('admin.web_delivery.unavailable_desc')) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head">
      <h2 class="card-title"><?= icon('file-zip', 'text-zinc-400') ?> <?= e(t('admin.web_delivery.compression')) ?></h2>
    </div>
    <div class="divide-y divide-zinc-100">
      <div class="px-5 py-4 flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.gzip')) ?></p>
          <p class="text-xs text-zinc-400 mt-1"><?= e($gzipDetail) ?></p>
        </div>
        <?= $renderBadge((string) ($status['gzip'] ?? 'unknown')) ?>
      </div>
      <div class="px-5 py-4 flex items-center justify-between gap-4">
        <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.brotli_module')) ?></p>
        <?= $renderBadge((string) ($status['brotli_module'] ?? 'unknown')) ?>
      </div>
      <div class="px-5 py-4 flex items-center justify-between gap-4">
        <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.brotli_config')) ?></p>
        <?= $renderBadge((string) ($status['brotli_config'] ?? 'unknown')) ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2 class="card-title"><?= icon('world', 'text-zinc-400') ?> <?= e(t('admin.web_delivery.protocols')) ?></h2>
    </div>
    <div class="divide-y divide-zinc-100">
      <div class="px-5 py-4 flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.http2')) ?></p>
          <p class="text-xs text-zinc-400 mt-1"><?= e($total > 0 ? t('admin.web_delivery.sites_configured', ['n' => $http2Configured, 'total' => $total]) : t('admin.web_delivery.no_https_sites')) ?></p>
        </div>
        <?= $renderBadge($http2State) ?>
      </div>
      <div class="px-5 py-4 flex items-center justify-between gap-4">
        <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.http3_module')) ?></p>
        <?= $renderBadge((string) ($status['http3_module'] ?? 'unknown')) ?>
      </div>
      <div class="px-5 py-4 flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.http3_config')) ?></p>
          <?php if ($total > 0): ?>
          <p class="text-xs text-zinc-400 mt-1"><?= e(t('admin.web_delivery.sites_configured', ['n' => $http3Configured, 'total' => $total])) ?></p>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('admin.web_delivery.http3_breakdown', ['partial' => $http3Partial, 'missing' => $http3Missing])) ?></p>
          <?php else: ?>
          <p class="text-xs text-zinc-400 mt-1"><?= e(t('admin.web_delivery.no_https_sites')) ?></p>
          <?php endif; ?>
        </div>
        <?= $renderBadge($http3State) ?>
      </div>
      <div class="px-5 py-4 flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.udp443')) ?></p>
          <p class="text-xs text-zinc-400 mt-1"><?= e(t('admin.web_delivery.udp443_hint')) ?></p>
        </div>
        <?= $renderBadge((string) ($status['udp_443'] ?? 'unknown')) ?>
      </div>
      <div class="px-5 py-4 flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-zinc-800"><?= e(t('admin.web_delivery.external')) ?></p>
          <p class="text-xs text-zinc-400 mt-1"><?= e(t('admin.web_delivery.external_hint')) ?></p>
        </div>
        <?= $renderBadge('not_tested') ?>
      </div>
    </div>
  </div>

  <div class="card p-5 flex items-start gap-3">
    <?= icon('info-circle', 'text-indigo-500 text-lg mt-0.5') ?>
    <div class="min-w-0">
      <p class="text-sm font-semibold text-zinc-800"><?= e(t('admin.web_delivery.recommendation')) ?></p>
      <ul class="mt-2 space-y-1.5 text-xs text-zinc-500 leading-relaxed list-disc pl-4">
        <?php foreach ($recommendations as $recommendation): ?>
        <li><?= e($recommendation) ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3 text-[11px]">
        <a href="https://nginx.org/en/docs/http/ngx_http_gzip_module.html" target="_blank" rel="noopener noreferrer" class="text-ink hover:underline"><?= e(t('admin.web_delivery.docs_nginx')) ?>: Gzip</a>
        <a href="https://nginx.org/en/docs/http/ngx_http_v3_module.html" target="_blank" rel="noopener noreferrer" class="text-ink hover:underline"><?= e(t('admin.web_delivery.docs_nginx')) ?>: HTTP/3</a>
        <a href="https://developers.cloudflare.com/speed/optimization/content/compression/" target="_blank" rel="noopener noreferrer" class="text-ink hover:underline"><?= e(t('admin.web_delivery.docs_cloudflare')) ?>: Compression</a>
        <a href="https://developers.cloudflare.com/speed/optimization/protocol/http3/" target="_blank" rel="noopener noreferrer" class="text-ink hover:underline"><?= e(t('admin.web_delivery.docs_cloudflare')) ?>: HTTP/3</a>
      </div>
    </div>
  </div>
</div>
