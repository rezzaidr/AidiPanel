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
// Admin Area sections — drive both the top-nav active state and the left sidebar
// that wraps every admin route. 'soon' entries are previews (no page yet); 'admin'
// entries only show to administrators. Server-wide PHP/Cache/SSL pages were removed
// (those live per-site now), so they are intentionally absent here.
$adminSections = [
    ['key' => 'users',    'icon' => 'ti-users',          'href' => '/users', 'admin' => true],
    ['key' => 'services', 'icon' => 'ti-stack-2',        'href' => '/services'],
    ['key' => 'logs',     'icon' => 'ti-file-text',      'href' => '/logs',  'admin' => true],
    ['key' => 'security', 'icon' => 'ti-shield-lock',    'href' => null,     'soon' => true],
    ['key' => 'tuning',   'icon' => 'ti-adjustments-bolt','href' => null,    'soon' => true],
    ['key' => 'backups',  'icon' => 'ti-database-export','href' => null,     'soon' => true],
    ['key' => 'support',  'icon' => 'ti-lifebuoy',       'href' => null,     'soon' => true],
    ['key' => 'settings', 'icon' => 'ti-settings',       'href' => '/admin/settings', 'admin' => true],
];

$navAdmin = '';
foreach (['/admin', '/services', '/logs', '/users'] as $p) {
    if (str_starts_with($uri, $p)) { $navAdmin = 'active'; break; }
}
$_is_admin_area = ($navAdmin === 'active');

$_username = (string) ($_user['username'] ?? 'admin');
$_initials = strtoupper(mb_substr($_username, 0, 2, 'UTF-8'));
$_hostname = gethostname() ?: 'server';
// Server IP for the top-bar chip (click-to-copy). Prefer the resolved public IP
// (correct on NAT clouds like Tencent/AWS/GCP, where SERVER_ADDR is the private
// NIC address); fall back to the address this request was reached on.
$_ip = server_public_ip();
if ($_ip === '') {
    $_ip = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if ($_ip === '' || $_ip === '127.0.0.1' || $_ip === '::1') {
        $_ip = (string) (@gethostbyname($_hostname) ?: '');
    }
}
if ($_ip === '') { $_ip = $_hostname; }

// Cache-bust the built stylesheets by file mtime so a deploy is picked up on a
// normal refresh (this layout is no-store, so the stamp is always current).
$assetVer = static fn (string $p): string => $p . '?v=' . (@filemtime(PUBLIC_ROOT . $p) ?: PANEL_VERSION);
?>
<!DOCTYPE html>
<html lang="<?= e(current_locale()) ?>" class="h-full" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    // Set the colour theme before any CSS paints, so there is no light-mode
    // flash. A saved choice wins; otherwise follow the OS preference; else light.
    (function () {
      try {
        var t = localStorage.getItem('aidipanel-theme');
        if (t !== 'dark' && t !== 'light') {
          t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.dataset.theme = t;
      } catch (e) { document.documentElement.dataset.theme = 'light'; }
    })();
  </script>
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <title><?= e($pageTitle ?? t('nav.dashboard')) ?> — <?= e(t('app.name')) ?></title>

  <!-- Local production assets — no third-party CDN. Order matters: fonts →
       built Tailwind utilities → app.css component layer (overrides utilities).
       Icons are inline local SVGs (see the icon() helper) — there is no icon
       webfont. Theme/colours that lived in the old inline tailwind.config now
       live in tailwind.config.js and are baked into tailwind.css. Only the main
       body weight is preloaded. -->
  <link rel="preload" href="/assets/fonts/plus-jakarta-sans-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/fonts.css">
  <link rel="stylesheet" href="<?= e($assetVer('/assets/tailwind.css')) ?>">
  <link rel="stylesheet" href="<?= e($assetVer('/assets/app.css')) ?>">

  <script defer src="/assets/vendor/alpine.min.js"></script>
</head>
<body class="h-full text-zinc-800 antialiased" x-data="{ userMenu: false }">

<!-- ===== TOP BAR ===== -->
<header class="h-16 bg-white border-b border-zinc-200/80 flex items-center px-5 gap-4">
  <a href="/dashboard" class="flex items-center gap-2.5 pr-4 shrink-0">
    <svg viewBox="0 0 32 32" class="w-9 h-9 shrink-0" aria-hidden="true">
      <rect width="32" height="32" rx="8" fill="#322C7A"/>
      <path d="M8 22 L16 10 L24 22" stroke="#fff" stroke-width="2.5" fill="none" stroke-linejoin="round"/>
      <circle cx="16" cy="22" r="2" fill="#fff"/>
    </svg>
    <span class="font-head font-bold text-[15px] text-zinc-900"><?= e(t('app.name')) ?></span>
  </a>

  <nav class="flex items-center gap-1">
    <a href="/dashboard" class="topnav <?= $navDashboard ?>"<?= $navDashboard ? ' aria-current="page"' : '' ?>><?= icon('chart-line', 'text-base') ?> <?= e(t('nav.dashboard')) ?></a>
    <a href="/sites" class="topnav <?= $navSites ?>"<?= $navSites ? ' aria-current="page"' : '' ?>><?= icon('world', 'text-base') ?> <?= e(t('nav.sites')) ?></a>
    <?php if (\Core\Access::canAccessAdminArea()): ?>
    <a href="/admin" class="topnav <?= $navAdmin ?>"<?= $navAdmin ? ' aria-current="page"' : '' ?>><?= icon('server-cog', 'text-base') ?> <?= e(t('nav.admin')) ?></a>
    <?php endif; ?>
  </nav>

  <div class="ml-auto flex items-center gap-2.5">
    <button type="button" x-data="{ copied: false }" data-ip="<?= e($_ip) ?>"
            @click="navigator.clipboard && navigator.clipboard.writeText($el.dataset.ip); copied = true; setTimeout(() => copied = false, 1200)"
            class="flex items-center gap-2 text-xs font-medium text-zinc-700 bg-zinc-50 hover:bg-zinc-100 border border-zinc-200 rounded-lg px-2.5 py-1.5 cursor-pointer transition"
            title="<?= e(t('topbar.copy_ip')) ?>">
      <span class="pulse"></span>
      <span class="mono" x-show="!copied"><?= e($_ip) ?></span>
      <span class="mono text-emerald-600" x-show="copied" x-cloak><?= e(t('topbar.copied')) ?></span>
    </button>

    <button type="button"
            x-data="{ dark: document.documentElement.dataset.theme === 'dark' }"
            @click="dark = !dark; document.documentElement.dataset.theme = dark ? 'dark' : 'light'; try { localStorage.setItem('aidipanel-theme', dark ? 'dark' : 'light'); } catch (e) {}"
            class="w-8 h-8 rounded-lg hover:bg-zinc-100 flex items-center justify-center text-zinc-400" title="<?= e(t('topbar.theme')) ?>">
      <span x-show="!dark"><?= icon('moon', 'text-[18px]') ?></span>
      <span x-show="dark" x-cloak><?= icon('sun', 'text-[18px]') ?></span>
    </button>

    <!-- account menu -->
    <div class="relative">
      <button type="button" @click="userMenu = !userMenu" class="flex items-center gap-1.5 pl-1 hover:bg-zinc-100 rounded-lg py-1 pr-1.5" title="<?= e(t('topbar.account')) ?>">
        <div class="w-8 h-8 rounded-full bg-ink-pale flex items-center justify-center text-ink text-xs font-bold font-head"><?= e($_initials) ?></div>
        <?= icon('chevron-down', 'text-zinc-400 text-sm') ?>
      </button>
      <div x-show="userMenu" x-cloak @click.outside="userMenu = false" x-transition.opacity
           class="absolute right-0 mt-2 w-48 bg-white border border-zinc-200 rounded-xl shadow-lg py-1.5 z-50">
        <div class="px-3.5 py-2 border-b border-zinc-100">
          <p class="text-sm font-semibold text-zinc-900 truncate"><?= e($_username) ?></p>
          <p class="text-[11px] text-zinc-400"><?php
            $roleLabel = match (\Core\Auth::role()) {
                'admin'   => 'Administrator',
                'manager' => 'Site manager',
                'client'  => 'Client',
                default   => (demo_mode() ? 'Read-only' : 'User'),
            };
            echo e($roleLabel);
          ?></p>
        </div>
        <a href="/settings" class="flex items-center gap-2 px-3.5 py-2 text-sm text-zinc-600 hover:bg-zinc-50">
          <?= icon('settings', 'text-base text-zinc-400') ?> <?= e(t('topbar.settings')) ?>
        </a>
        <a href="/logout" class="flex items-center gap-2 px-3.5 py-2 text-sm text-zinc-600 hover:bg-zinc-50">
          <?= icon('logout', 'text-base text-zinc-400') ?> <?= e(t('topbar.logout')) ?>
        </a>
      </div>
    </div>
  </div>
</header>

<!-- ===== CONTENT ===== -->
<?php if (!empty($_full_bleed)): ?>
  <?= $_content ?? '' ?>
<?php elseif (!empty($_is_admin_area)): ?>
  <!-- Admin Area shell: persistent left sidebar + section content on the right. -->
  <main class="mx-auto px-6 py-6 max-w-[1280px]">
    <div class="flex gap-7 items-start">
      <aside class="w-56 shrink-0 sticky top-6">
        <nav class="sidenav">
          <?php foreach ($adminSections as $s): ?>
            <?php
              if (!empty($s['admin']) && empty($_is_admin)) {
                  // Read-only demo: still surface the admin READ sections (the viewer may
                  // browse them), but keep /logs hidden — it can carry IPs/paths.
                  if (!demo_mode() || $s['key'] === 'logs') { continue; }
              }
              $isSoon = !empty($s['soon']) || empty($s['href']);
              $active = (!$isSoon && str_starts_with($uri, $s['href'])) ? ' active' : '';
              $title  = t('admin.' . $s['key'] . '.title');
            ?>
            <?php if ($isSoon): ?>
              <span class="sidenav-item is-soon">
                <?= icon($s['icon'], 'text-[18px]') ?>
                <span class="flex-1"><?= e($title) ?></span>
                <span class="tag tag-muted"><?= e(t('common.soon')) ?></span>
              </span>
            <?php else: ?>
              <a href="<?= e($s['href']) ?>" class="sidenav-item<?= $active ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                <?= icon($s['icon'], 'text-[18px]') ?>
                <span class="flex-1"><?= e($title) ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </aside>
      <div class="flex-1 min-w-0">
        <?= $_content ?? '' ?>
      </div>
    </div>
  </main>
<?php else: ?>
  <main class="mx-auto px-6 py-6 <?= e($_content_max ?? 'max-w-[1280px]') ?>">
    <?= $_content ?? '' ?>
  </main>
<?php endif; ?>

<!-- ===== TOASTS (flash messages) ===== -->
<div id="toast-stack" aria-live="polite" class="fixed top-4 right-4 z-[60] flex flex-col gap-2 w-80 max-w-[calc(100vw-2rem)]">
  <?php if (!empty($_flash_success ?? '')): ?>
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)" x-transition
         class="toast toast-ok">
      <?= icon('circle-check', 'text-base shrink-0') ?>
      <span class="flex-1">
        <?= e($_flash_success) ?>
        <?php if (!empty($_flash_success_warn ?? '')): ?>
          <span class="block text-amber-700 mt-0.5"><?= e($_flash_success_warn) ?></span>
        <?php endif; ?>
      </span>
      <button type="button" @click="show = false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-emerald-700/60 hover:text-emerald-700 shrink-0"><?= icon('x', 'text-sm') ?></button>
    </div>
  <?php endif; ?>
  <?php if (!empty($_flash_warning ?? '')): ?>
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 10000)" x-transition
         class="toast toast-warn">
      <?= icon('alert-triangle', 'text-base') ?>
      <span class="flex-1"><?= e($_flash_warning) ?></span>
      <button type="button" @click="show = false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-amber-700/60 hover:text-amber-700"><?= icon('x', 'text-sm') ?></button>
    </div>
  <?php endif; ?>
  <?php if (!empty($_flash_error ?? '')): ?>
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 10000)" x-transition class="toast toast-error">
      <?= icon('alert-circle', 'text-base') ?>
      <span class="flex-1"><?= e($_flash_error) ?></span>
      <button type="button" @click="show = false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-red-600/60 hover:text-red-600"><?= icon('x', 'text-sm') ?></button>
    </div>
  <?php endif; ?>
</div>

<meta name="csrf-token" content="<?= e($_csrf_token ?? '') ?>">

<script>
// opGuard — UX safety layer for long server operations (PHP on-demand install,
// site create/delete, PHP switch, SSL issuance). While an op runs, a native
// beforeunload dialog warns the user that reloading/leaving may interrupt it.
// This is ONLY a warning — the backend already hardens these ops
// (ignore_user_abort + per-version lock), so the work completes regardless; this
// just stops an accidental reload mid-wait.
//
// Subtlety: these are plain synchronous form POSTs, and beforeunload fires at the
// START of a navigation — including the form's OWN submit. Arming naively would
// pop the dialog the instant the user clicks the legitimate button. So start() is
// called right before the form navigates and flags that ONE navigation as
// expected: the handler lets it through, then re-arms. The op's POST is in flight
// while the old page stays visible; any FURTHER navigation (a real reload / tab
// close) during that wait is what gets warned. The success/error redirect is a
// continuation of the same POST navigation, so it does not re-fire beforeunload —
// no spurious prompt on completion. The next page load resets everything.
// ── Icon strings for JS-injected icons (toasts, op-progress). Rendered from the
// same local Tabler SVGs as the rest of the UI via the icon() helper, so the
// panel needs no icon webfont. JSON_HEX_TAG keeps the markup safe inside <script>.
window.AidiIcons = {
  loading:  <?= json_encode(icon('loader-2', 'spin text-ink text-base'), JSON_HEX_TAG) ?>,
  failed:   <?= json_encode(icon('alert-circle', 'text-rose-600 text-base'), JSON_HEX_TAG) ?>,
  done:     <?= json_encode(icon('circle-check', 'text-emerald-600 text-base'), JSON_HEX_TAG) ?>,
  copyIdle: <?= json_encode(icon('copy', 'text-sm'), JSON_HEX_TAG) ?>,
  copyDone: <?= json_encode(icon('check', 'text-emerald-500 text-sm'), JSON_HEX_TAG) ?>,
  tSuccess: <?= json_encode(icon('circle-check', 'text-base shrink-0'), JSON_HEX_TAG) ?>,
  tError:   <?= json_encode(icon('alert-circle', 'text-base shrink-0'), JSON_HEX_TAG) ?>,
  tWarning: <?= json_encode(icon('alert-triangle', 'text-base shrink-0'), JSON_HEX_TAG) ?>,
  tClose:   <?= json_encode(icon('x', 'text-sm'), JSON_HEX_TAG) ?>,
};

window.opGuard = (function () {
  var armed = false;
  var expectSelfNav = false;   // the op's own form submit is an allowed navigation
  function handler(e) {
    if (expectSelfNav) { expectSelfNav = false; return; }
    e.preventDefault();
    e.returnValue = '';
    return '';
  }
  return {
    start: function () {
      expectSelfNav = true;    // allow the immediate form-submit navigation
      if (armed) return;
      armed = true;
      window.addEventListener('beforeunload', handler);
    },
    stop: function () {
      armed = false;
      expectSelfNav = false;
      window.removeEventListener('beforeunload', handler);
    },
    get running() { return armed; },
  };
})();

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

// ── AidiToast — inject a toast (used after a streamed op redirects) ───────────
window.AidiToast = function (kind, message) {
  var stack = document.getElementById('toast-stack');
  if (!stack || !message) return;
  var map = {
    success: ['toast-ok', 'tSuccess'],
    error:   ['toast-error', 'tError'],
    warning: ['toast-warn', 'tWarning'],
  };
  var cfg = map[kind] || map.success;
  var el = document.createElement('div');
  el.className = 'toast ' + cfg[0];
  el.innerHTML = (window.AidiIcons[cfg[1]] || '')
    + '<span class="flex-1"></span>'
    + '<button type="button" aria-label="Dismiss" class="shrink-0">' + (window.AidiIcons.tClose || '') + '</button>';
  el.querySelector('span').textContent = message;        // textContent = no HTML injection
  el.querySelector('button').addEventListener('click', function () { el.remove(); });
  stack.appendChild(el);
  setTimeout(function () { el.remove(); }, 8000);
};

// A streamed op finishes by stashing its toast, then navigating — show it on arrival.
(function () {
  try {
    var raw = sessionStorage.getItem('aidipanel_toast');
    if (raw) {
      sessionStorage.removeItem('aidipanel_toast');
      var t = JSON.parse(raw);
      if (t && t.message) window.AidiToast(t.kind || 'success', t.message);
    }
    var url = new URL(window.location.href);
    var toastKey = url.searchParams.get('panel_toast');
    var toastMap = {
      domain_issued: <?= json_encode(t('admin.settings.domain.issued')) ?>,
    };
    if (toastKey && toastMap[toastKey]) {
      window.AidiToast('success', toastMap[toastKey]);
      url.searchParams.delete('panel_toast');
      window.history.replaceState({}, '', url.pathname + (url.search || '') + url.hash);
    }
  } catch (e) {}
})();

// ── opStream — POST a form and read an SSE progress stream ────────────────────
// handlers: { onProgress(pct,key,msg), onDone(frame), onError(message) }
window.opStream = function (url, formData, handlers) {
  handlers = handlers || {};
  fetch(url, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (!res.ok || ct.indexOf('text/event-stream') === -1) {
        // A handled error (e.g. the read-only demo guard) replies with JSON {message};
        // surface that instead of the generic "couldn't start" line.
        res.json().then(function (d) {
          if (handlers.onError) handlers.onError((d && d.message) ? d.message : 'Could not start the operation. Please check your input and try again.');
        }).catch(function () {
          if (handlers.onError) handlers.onError('Could not start the operation. Please check your input and try again.');
        });
        return;
      }
      var reader = res.body.getReader();
      var decoder = new TextDecoder();
      var buf = '';
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return;
          buf += decoder.decode(r.value, { stream: true });
          var parts = buf.split('\n\n');
          buf = parts.pop();
          parts.forEach(function (chunk) {
            var data = chunk.split('\n')
              .filter(function (l) { return l.indexOf('data:') === 0; })
              .map(function (l) { return l.slice(5).trim(); }).join('');
            if (!data) return;
            var frame; try { frame = JSON.parse(data); } catch (e) { return; }
            if (frame.t === 'p' && handlers.onProgress) handlers.onProgress(frame.pct, frame.key, frame.msg);
            else if (frame.t === 'done' && handlers.onDone) handlers.onDone(frame);
          });
          return pump();
        });
      }
      return pump();
    })
    .catch(function () {
      if (handlers.onError) handlers.onError('Connection interrupted. The operation may still be running on the server.');
    });
};

// ── opProgressController — drive a [data-op-progress] UI block (see partial) ──
window.opProgressController = function (root) {
  if (!root) return null;
  var bar = root.querySelector('[data-op-bar]');
  var label = root.querySelector('[data-op-label]');
  var elapsed = root.querySelector('[data-op-elapsed]');
  var icon = root.querySelector('[data-op-icon]');
  var errEl = root.querySelector('[data-op-error]');
  var stepStart = 0, timer = null;
  function tick() {
    var s = Math.floor((Date.now() - stepStart) / 1000);
    if (elapsed) elapsed.textContent = s > 0 ? s + 's' : '';
    if (s >= 3 && bar) bar.classList.add('op-working');   // shimmer once a step runs long
  }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }
  return {
    show: function () {
      root.classList.remove('hidden');
      if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }
      if (icon) icon.innerHTML = window.AidiIcons.loading;
      if (bar) { bar.style.width = '0%'; bar.classList.remove('op-working', 'op-bar-fail'); }
      stepStart = Date.now(); stopTimer(); timer = setInterval(tick, 1000); tick();
    },
    set: function (pct, key, msg) {
      pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
      if (bar) { bar.style.width = pct + '%'; bar.classList.remove('op-working'); }
      if (msg && label) label.textContent = msg;
      stepStart = Date.now(); tick();
    },
    fail: function (msg) {
      stopTimer();
      if (icon) icon.innerHTML = window.AidiIcons.failed;
      if (bar) { bar.classList.remove('op-working'); bar.classList.add('op-bar-fail'); }
      if (label) label.textContent = 'Failed';
      if (elapsed) elapsed.textContent = '';
      if (errEl) { errEl.textContent = msg; errEl.classList.remove('hidden'); }
    },
    done: function () {
      stopTimer();
      if (bar) { bar.style.width = '100%'; bar.classList.remove('op-working'); }
      if (icon) icon.innerHTML = window.AidiIcons.done;
      if (elapsed) elapsed.textContent = '';
    },
  };
};

// ── Declarative streaming: <form data-op-stream> submits via opStream with a bar ──
// The form should contain the op-progress partial ([data-op-progress]) and wrap its
// inputs/buttons in [data-op-fields]. On success: redirect (+ toast) or reload.
window.setFormSubmitting = function (form, value) {
  var root = form && form.closest ? form.closest('[x-data]') : null;
  if (!root || !window.Alpine || !window.Alpine.$data) return;
  try {
    var data = window.Alpine.$data(root);
    if (data && Object.prototype.hasOwnProperty.call(data, 'submitting')) {
      data.submitting = value;
    }
  } catch (e) {}
};
window.opStreamSubmit = function (form) {
  if (!form || form.dataset.opStreaming === '1') return;
  form.dataset.opStreaming = '1';
  setFormSubmitting(form, true);
  var fields = form.querySelector('[data-op-fields]');
  var ui     = window.opProgressController(form.querySelector('[data-op-progress]'));
  var fd     = new FormData(form);
  fd.set('stream', '1');
  if (fields) fields.classList.add('hidden');
  if (ui) ui.show();
  window.opGuard.start();
  window.opStream(form.getAttribute('action'), fd, {
    onProgress: function (p, k, m) { if (ui) ui.set(p, k, m); },
    onDone: function (frame) {
      window.opGuard.stop();
      if (frame.ok) {
        if (ui) ui.done();
        try { sessionStorage.setItem('aidipanel_toast', JSON.stringify({ kind: 'success', message: frame.message })); } catch (e) {}
        if (frame.redirect) window.location.href = frame.redirect; else window.location.reload();
      } else {
        form.dataset.opStreaming = '0';
        setFormSubmitting(form, false);
        if (ui) ui.fail(frame.message || 'Operation failed.');
        if (fields) fields.classList.remove('hidden');
      }
    },
    onError: function (msg) {
      window.opGuard.stop();
      form.dataset.opStreaming = '0';
      setFormSubmitting(form, false);
      if (ui) ui.fail(msg);
      if (fields) fields.classList.remove('hidden');
    }
  });
};
document.addEventListener('submit', function (e) {
  var form = e.target;
  if (form && form.matches && form.matches('form[data-op-stream]')) {
    e.preventDefault();
    window.opStreamSubmit(form);
  }
});
</script>
</body>
</html>
