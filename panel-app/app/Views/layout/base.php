<?php
/**
 * AidiPanel — base layout (Fase 2 top-bar shell).
 *
 * Provides: top bar (brand · Dashboard/Sites/Admin Area · right cluster) and a
 * centered content slot. Pages render their own copy via t(); shared components
 * live in /assets/app.css.
 *
 * Per-page layout hooks (set by the controller in the view() data array):
 *   $_full_bleed  bool   render $_content with no wrapper (page brings its own
 *                        full-width bands, e.g. the Manage Site header).
 *   $_content_max string max-width utility for the default wrapper (default
 *                        'max-w-[1280px]'; site pages use 'max-w-[1100px]').
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$navDashboard = ($uri === '/' || str_starts_with($uri, '/dashboard')) ? 'active' : '';
$navSites     = str_starts_with($uri, '/sites') ? 'active' : '';
$navAdmin     = '';
foreach (['/admin', '/services', '/php', '/cache', '/ssl', '/logs', '/users'] as $p) {
    if (str_starts_with($uri, $p)) { $navAdmin = 'active'; break; }
}

$_username = (string) ($_user['username'] ?? 'admin');
$_initials = strtoupper(mb_substr($_username, 0, 2, 'UTF-8'));
$_hostname = gethostname() ?: 'server';
?>
<!DOCTYPE html>
<html lang="<?= e(current_locale()) ?>" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <title><?= e($pageTitle ?? t('nav.dashboard')) ?> — <?= e(t('app.name')) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

  <!-- Tailwind Play CDN must load before tailwind.config is assigned -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: {
      colors: {
        ink:   { DEFAULT:'#322C7A', light:'#4A42A8', pale:'#ECEBF7' },
        speed: { DEFAULT:'#0891B2', light:'#06B6D4', pale:'#E0F7FB' },
        // legacy alias → ink, so pre-Fase2 pages keep their colour while we migrate
        brand: { DEFAULT:'#322C7A', light:'#4A42A8', pale:'#ECEBF7' },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"','system-ui','sans-serif'],
        head: ['"Space Grotesk"','system-ui','sans-serif'],
        mono: ['"JetBrains Mono"','ui-monospace','monospace'],
      },
    }}};
  </script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full text-zinc-800 antialiased" x-data="{ userMenu: false }">

<!-- ===== TOP BAR ===== -->
<header class="h-16 bg-white border-b border-zinc-200/80 flex items-center px-5 gap-4">
  <a href="/dashboard" class="flex items-center gap-2.5 pr-4 shrink-0">
    <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-ink"><i class="ti ti-bolt text-white text-lg"></i></div>
    <span class="font-head font-bold text-[15px] text-zinc-900"><?= e(t('app.name')) ?></span>
  </a>

  <nav class="flex items-center gap-1">
    <a href="/dashboard" class="topnav <?= $navDashboard ?>"><i class="ti ti-chart-line text-base"></i> <?= e(t('nav.dashboard')) ?></a>
    <a href="/sites" class="topnav <?= $navSites ?>"><i class="ti ti-world text-base"></i> <?= e(t('nav.sites')) ?></a>
    <a href="/admin" class="topnav <?= $navAdmin ?>"><i class="ti ti-server-cog text-base"></i> <?= e(t('nav.admin')) ?></a>
  </nav>

  <div class="ml-auto flex items-center gap-2.5">
    <button type="button" class="hidden md:flex items-center gap-2 text-xs text-zinc-400 bg-zinc-100/70 hover:bg-zinc-100 border border-zinc-200/70 rounded-lg pl-2.5 pr-2 py-1.5" title="⌘K">
      <i class="ti ti-search text-sm"></i> <?= e(t('topbar.search')) ?>
      <span class="mono text-[10px] bg-white border border-zinc-200 rounded px-1 py-0.5 text-zinc-400">⌘K</span>
    </button>

    <div class="flex items-center gap-2 text-xs font-medium text-zinc-700 bg-zinc-50 border border-zinc-200 rounded-lg px-2.5 py-1.5" title="<?= e(t('topbar.server_tooltip')) ?>">
      <span class="pulse"></span>
      <span class="mono"><?= e($_hostname) ?></span>
    </div>

    <button type="button" class="w-8 h-8 rounded-lg hover:bg-zinc-100 flex items-center justify-center text-zinc-400" title="<?= e(t('topbar.theme')) ?>"><i class="ti ti-moon text-[18px]"></i></button>
    <button type="button" class="w-8 h-8 rounded-lg hover:bg-zinc-100 flex items-center justify-center text-zinc-400" title="<?= e(t('topbar.settings')) ?>"><i class="ti ti-settings text-[18px]"></i></button>

    <!-- account menu -->
    <div class="relative">
      <button type="button" @click="userMenu = !userMenu" class="flex items-center gap-1.5 pl-1 hover:bg-zinc-100 rounded-lg py-1 pr-1.5" title="<?= e(t('topbar.account')) ?>">
        <div class="w-8 h-8 rounded-full bg-ink-pale flex items-center justify-center text-ink text-xs font-bold font-head"><?= e($_initials) ?></div>
        <i class="ti ti-chevron-down text-zinc-400 text-sm"></i>
      </button>
      <div x-show="userMenu" x-cloak @click.outside="userMenu = false" x-transition.opacity
           class="absolute right-0 mt-2 w-48 bg-white border border-zinc-200 rounded-xl shadow-lg py-1.5 z-50">
        <div class="px-3.5 py-2 border-b border-zinc-100">
          <p class="text-sm font-semibold text-zinc-900 truncate"><?= e($_username) ?></p>
          <p class="text-[11px] text-zinc-400"><?= !empty($_is_admin) ? 'Administrator' : 'Read-only' ?></p>
        </div>
        <a href="/logout" class="flex items-center gap-2 px-3.5 py-2 text-sm text-zinc-600 hover:bg-zinc-50">
          <i class="ti ti-logout text-base text-zinc-400"></i> <?= e(t('topbar.logout')) ?>
        </a>
      </div>
    </div>
  </div>
</header>

<!-- ===== CONTENT ===== -->
<?php if (!empty($_full_bleed)): ?>
  <?= $_content ?? '' ?>
<?php else: ?>
  <main class="mx-auto px-6 py-6 <?= e($_content_max ?? 'max-w-[1280px]') ?>">
    <?= $_content ?? '' ?>
  </main>
<?php endif; ?>

<!-- ===== TOASTS (flash messages) ===== -->
<div class="fixed top-4 right-4 z-[60] flex flex-col gap-2 w-80 max-w-[calc(100vw-2rem)]">
  <?php if (!empty($_flash_success ?? '')): ?>
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition
         class="toast toast-ok">
      <i class="ti ti-circle-check text-base"></i>
      <span class="flex-1"><?= e($_flash_success) ?></span>
      <button type="button" @click="show = false" class="text-emerald-700/60 hover:text-emerald-700"><i class="ti ti-x text-sm"></i></button>
    </div>
  <?php endif; ?>
  <?php if (!empty($_flash_error ?? '')): ?>
    <div x-data="{ show: true }" x-show="show" x-transition class="toast toast-error">
      <i class="ti ti-alert-circle text-base"></i>
      <span class="flex-1"><?= e($_flash_error) ?></span>
      <button type="button" @click="show = false" class="text-red-600/60 hover:text-red-600"><i class="ti ti-x text-sm"></i></button>
    </div>
  <?php endif; ?>
</div>

<meta name="csrf-token" content="<?= e($_csrf_token ?? '') ?>">

<script>
window.api = async (url, method = 'GET', body = null) => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const opts = {
    method,
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
  };
  if (body && method === 'POST') {
    opts.body = JSON.stringify({ ...body, _csrf_token: csrf });
  }
  const res = await fetch(url, opts);
  return res.json();
};
</script>
</body>
</html>
