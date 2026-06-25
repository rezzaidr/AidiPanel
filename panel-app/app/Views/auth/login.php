<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — AidiPanel</title>
  <!-- Local production assets — no third-party CDN. Login is a standalone page;
       it loads the self-hosted fonts, the built Tailwind utilities and app.css
       (for the theme tokens + dark-mode bridge). Icons are inline local SVGs
       (icon() helper) — no icon webfont. Its brand purple is kept via arbitrary
       colour values below so the light look is unchanged. -->
  <script>
    // Apply the saved/OS colour theme before paint (no flash), same as the app shell.
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
  <link rel="preload" href="/assets/fonts/plus-jakarta-sans-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/fonts.css">
  <link rel="stylesheet" href="/assets/tailwind.css">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center font-sans antialiased">

<div class="w-full max-w-sm">

  <!-- Logo -->
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-2.5">
      <div class="w-10 h-10 bg-[#3C3489] rounded-xl flex items-center justify-center shadow">
        <?= icon('server', 'text-white text-lg') ?>
      </div>
      <span class="text-xl font-bold text-gray-900">AidiPanel</span>
    </div>
    <p class="text-sm text-gray-500 mt-2">Sign in to manage your server</p>
  </div>

  <!-- Card -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-8 py-8">

    <?php if ($error): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
      <?= icon('alert-circle', 'shrink-0') ?>
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login">
      <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5" for="username">Username</label>
        <div class="relative">
          <?= icon('user', 'absolute left-3 top-1/2 -translate-y-1/2 text-gray-400') ?>
          <input type="text" id="username" name="username" required autofocus
            class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3C3489] focus:border-transparent"
            placeholder="admin">
        </div>
      </div>

      <div class="mb-6">
        <label class="block text-xs font-medium text-gray-700 mb-1.5" for="password">Password</label>
        <div class="relative">
          <?= icon('lock', 'absolute left-3 top-1/2 -translate-y-1/2 text-gray-400') ?>
          <input type="password" id="password" name="password" required
            class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3C3489] focus:border-transparent"
            placeholder="••••••••">
        </div>
      </div>

      <button type="submit"
        class="w-full bg-[#3C3489] hover:bg-[#534AB7] text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        Sign in
      </button>
    </form>
  </div>

  <p class="text-center text-xs text-gray-500 mt-6">AidiPanel v<?= PANEL_VERSION ?> · Nginx + FastCGI Cache + Redis</p>
</div>

</body>
</html>
