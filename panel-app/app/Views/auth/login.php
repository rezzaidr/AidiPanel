<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — AidiPanel</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
  <script>tailwind.config = { theme: { extend: { colors: { brand: { DEFAULT:'#3C3489', light:'#534AB7', pale:'#EEEDFE' } } } } }</script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center font-sans antialiased">

<div class="w-full max-w-sm">

  <!-- Logo -->
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-2.5">
      <div class="w-10 h-10 bg-brand rounded-xl flex items-center justify-center shadow">
        <i class="ti ti-server text-white text-lg"></i>
      </div>
      <span class="text-xl font-bold text-gray-900">AidiPanel</span>
    </div>
    <p class="text-sm text-gray-500 mt-2">Sign in to manage your server</p>
  </div>

  <!-- Card -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-8 py-8">

    <?php if ($error): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
      <i class="ti ti-alert-circle shrink-0"></i>
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login">
      <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5" for="username">Username</label>
        <div class="relative">
          <i class="ti ti-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input type="text" id="username" name="username" required autofocus
            class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent"
            placeholder="admin">
        </div>
      </div>

      <div class="mb-6">
        <label class="block text-xs font-medium text-gray-700 mb-1.5" for="password">Password</label>
        <div class="relative">
          <i class="ti ti-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input type="password" id="password" name="password" required
            class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent"
            placeholder="••••••••">
        </div>
      </div>

      <button type="submit"
        class="w-full bg-brand hover:bg-brand-light text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        Sign in
      </button>
    </form>
  </div>

  <p class="text-center text-xs text-gray-400 mt-6">AidiPanel v<?= PANEL_VERSION ?> · Nginx + FastCGI Cache + Redis</p>
</div>

</body>
</html>
