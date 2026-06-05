<?php $pageTitle = 'Add Site'; ?>

<div class="max-w-xl">

  <div class="mb-5">
    <a href="/sites" class="text-xs text-gray-400 hover:text-gray-700 flex items-center gap-1">
      <i class="ti ti-arrow-left text-sm"></i> Back to Sites
    </a>
  </div>

  <div class="bg-white rounded-xl border border-gray-200 p-6" x-data="addSite()">

    <h2 class="text-sm font-semibold text-gray-900 mb-5">Add New Site</h2>

    <form method="POST" action="/sites/add">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <!-- Domain -->
      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Domain Name</label>
        <input type="text" name="domain" required placeholder="example.com"
          class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent">
        <p class="text-[10px] text-gray-400 mt-1">Without www — both www and non-www will be configured.</p>
      </div>

      <!-- Site Type -->
      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Application Type</label>
        <div class="grid grid-cols-3 gap-2">
          <?php foreach ($siteTypes as $value => $label): ?>
          <label class="relative cursor-pointer">
            <input type="radio" name="type" value="<?= e($value) ?>" class="sr-only peer"
              <?= $value === 'wordpress' ? 'checked' : '' ?>
              @change="siteType = '<?= e($value) ?>'">
            <div class="border border-gray-200 rounded-lg px-3 py-2.5 text-center text-xs text-gray-600
                        peer-checked:border-brand peer-checked:bg-brand-pale peer-checked:text-brand
                        hover:border-gray-300 transition-all">
              <i class="ti ti-<?= match($value) {
                'wordpress' => 'brand-wordpress',
                'laravel'   => 'brand-laravel',
                'php'       => 'brand-php',
                'static'    => 'file-code',
                'proxy'     => 'network',
                default     => 'world'
              } ?> block text-xl mb-1"></i>
              <?= e($label) ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- PHP Version (hidden for static) -->
      <div class="mb-4" x-show="siteType !== 'static'" x-cloak>
        <label class="block text-xs font-medium text-gray-700 mb-1.5">PHP Version</label>
        <div class="flex gap-2">
          <?php foreach ($phpVersions as $v): ?>
          <label class="relative cursor-pointer flex-1">
            <input type="radio" name="php_version" value="<?= e($v) ?>" class="sr-only peer"
              <?= $v === '8.3' ? 'checked' : '' ?>>
            <div class="border border-gray-200 rounded-lg py-2 text-center text-sm font-medium text-gray-600
                        peer-checked:border-brand peer-checked:bg-brand-pale peer-checked:text-brand
                        hover:border-gray-300 transition-all">
              PHP <?= e($v) ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Proxy pass (only for proxy type) -->
      <div class="mb-4" x-show="siteType === 'proxy'" x-cloak>
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Proxy Pass URL</label>
        <input type="text" name="proxy_pass" value="http://127.0.0.1:3000" placeholder="http://127.0.0.1:3000"
          class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
        <p class="text-[10px] text-gray-400 mt-1">The upstream server URL (e.g. Node.js, Python app).</p>
      </div>

      <div class="flex items-center gap-3 pt-2">
        <button type="submit"
          class="bg-brand hover:bg-brand-light text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
          Create Site
        </button>
        <a href="/sites" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
function addSite() {
  return { siteType: 'wordpress' }
}
</script>
