<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Two-Factor — AidiPanel</title>
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
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-2.5">
      <div class="w-10 h-10 bg-[#3C3489] rounded-xl flex items-center justify-center shadow">
        <?= icon('shield-lock', 'text-white text-lg') ?>
      </div>
      <span class="text-xl font-bold text-gray-900">AidiPanel</span>
    </div>
    <p class="text-sm text-gray-500 mt-2">Enter your authentication code</p>
  </div>

  <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-8 py-8">
    <?php if ($error): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
      <?= icon('alert-circle', 'shrink-0') ?>
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login/2fa">
      <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">
      <div class="mb-6">
        <label class="block text-xs font-medium text-gray-700 mb-1.5" for="code">6-digit code or recovery code</label>
        <input type="text" id="code" name="code" required autofocus autocomplete="one-time-code"
          inputmode="numeric" spellcheck="false"
          class="w-full px-3 py-2.5 text-sm tracking-widest text-center border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#3C3489] focus:border-transparent"
          placeholder="123456">
        <p class="text-xs text-gray-400 mt-2">Open your authenticator app, or use one of your saved recovery codes.</p>
      </div>
      <button type="submit"
        class="w-full bg-[#3C3489] hover:bg-[#534AB7] text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        Verify
      </button>
    </form>

    <div class="text-center mt-4">
      <a href="/login" class="text-xs text-gray-500 hover:text-gray-700">Cancel and sign in again</a>
    </div>
  </div>
</div>

</body>
</html>
