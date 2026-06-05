<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <title><?= e($pageTitle ?? 'AidiPanel') ?> — AidiPanel</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { DEFAULT: '#3C3489', light: '#534AB7', pale: '#EEEDFE' },
          }
        }
      }
    }
  </script>
  <style>
    [x-cloak] { display: none !important; }
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    /* sidebar-link: plain CSS, @apply tidak didukung Tailwind CDN */
    .sidebar-link {
      display: flex; align-items: center; gap: 10px;
      padding: 7px 12px; font-size: 0.875rem; color: #4b5563;
      border-radius: 8px; transition: all 0.15s; text-decoration: none;
      white-space: nowrap;
    }
    .sidebar-link:hover { background: #f3f4f6; color: #111827; }
    .sidebar-link.active { background: #EEEDFE; color: #3C3489; font-weight: 500; }
  </style>
</head>
<body class="bg-gray-50 h-full font-sans antialiased" x-data="{ mobileSidebar: false }">

<div class="flex h-screen overflow-hidden">

  <!-- Sidebar -->
  <aside class="w-56 min-w-56 bg-white border-r border-gray-200 flex flex-col overflow-hidden">

    <!-- Logo -->
    <div class="h-14 flex items-center gap-2.5 px-4 border-b border-gray-200 shrink-0">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:#3C3489">
        <i class="ti ti-server text-white text-base"></i>
      </div>
      <span class="text-sm font-semibold text-gray-900">AidiPanel</span>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-3 py-3 overflow-y-auto">
      <?php
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $isActive = fn(string $path) => str_starts_with($uri, $path) ? 'active' : '';
      ?>

      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 mb-1" style="font-size:10px">Overview</p>
      <a href="/dashboard" class="sidebar-link <?= $uri === '/dashboard' || $uri === '/' ? 'active' : '' ?>">
        <i class="ti ti-layout-dashboard" style="font-size:16px"></i> Dashboard
      </a>
      <a href="/sites" class="sidebar-link <?= $isActive('/sites') ?>">
        <i class="ti ti-world" style="font-size:16px"></i> Sites
      </a>

      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 mt-4 mb-1" style="font-size:10px">Server</p>
      <a href="/cache" class="sidebar-link <?= $isActive('/cache') ?>">
        <i class="ti ti-bolt" style="font-size:16px"></i> FastCGI Cache
      </a>
      <a href="/php" class="sidebar-link <?= $isActive('/php') ?>">
        <i class="ti ti-brand-php" style="font-size:16px"></i> PHP-FPM
      </a>
      <a href="/services" class="sidebar-link <?= $isActive('/services') ?>">
        <i class="ti ti-activity" style="font-size:16px"></i> Services
      </a>

      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 mt-4 mb-1" style="font-size:10px">Management</p>
      <a href="/databases" class="sidebar-link <?= $isActive('/databases') ?>">
        <i class="ti ti-database" style="font-size:16px"></i> Databases
      </a>
      <a href="/ssl" class="sidebar-link <?= $isActive('/ssl') ?>">
        <i class="ti ti-lock" style="font-size:16px"></i> SSL / TLS
      </a>
      <?php if (!empty($_is_admin)): ?>
        <a href="/users" class="sidebar-link <?= $isActive('/users') ?>">
          <i class="ti ti-users" style="font-size:16px"></i> Users
        </a>
        <a href="/logs" class="sidebar-link <?= $isActive('/logs') ?>">
          <i class="ti ti-file-text" style="font-size:16px"></i> Logs
        </a>
      <?php endif; ?>
    </nav>

    <!-- Server info footer -->
    <div class="px-3 pb-3 shrink-0">
      <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
        <div class="overflow-hidden">
          <p class="text-xs font-medium text-gray-800 truncate"><?= e(gethostname() ?: 'server') ?></p>
          <p class="text-gray-400" style="font-size:10px">AidiPanel v<?= PANEL_VERSION ?></p>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main content area -->
  <div class="flex-1 flex flex-col overflow-hidden">

    <!-- Topbar -->
    <header class="h-14 bg-white border-b border-gray-200 flex items-center justify-between px-5 shrink-0">
      <h1 class="text-sm font-semibold text-gray-900"><?= e($pageTitle ?? 'Dashboard') ?></h1>
      <div class="flex items-center gap-2">
        <?php if (!empty($_flash_success ?? '')): ?>
          <div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)"
               class="text-xs bg-green-50 text-green-700 border border-green-200 px-3 py-1 rounded-full flex items-center gap-1.5">
            <i class="ti ti-circle-check"></i> <?= e($_flash_success) ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_flash_error ?? '')): ?>
          <div x-data="{show:true}" x-show="show"
               class="text-xs bg-red-50 text-red-600 border border-red-200 px-3 py-1 rounded-full flex items-center gap-1.5">
            <i class="ti ti-alert-circle"></i> <?= e($_flash_error) ?>
          </div>
        <?php endif; ?>
        <div class="flex items-center gap-1.5 ml-2 pl-3 border-l border-gray-200">
          <div class="w-7 h-7 rounded-full flex items-center justify-center" style="background:#EEEDFE">
            <i class="ti ti-user text-xs" style="color:#3C3489"></i>
          </div>
          <span class="text-xs text-gray-700"><?= e($_user['username'] ?? 'admin') ?></span>
          <a href="/logout" class="ml-1 text-gray-400 hover:text-gray-700 transition-colors" title="Logout">
            <i class="ti ti-logout" style="font-size:14px"></i>
          </a>
        </div>
      </div>
    </header>

    <!-- Page content -->
    <main class="flex-1 overflow-y-auto p-5">
      <?= $_content ?? '' ?>
    </main>

  </div>
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
