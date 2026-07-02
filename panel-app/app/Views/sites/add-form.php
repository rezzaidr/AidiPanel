<?php
/**
 * Add Site — Step 2: data-driven per-type form.
 * $form comes from SiteController::addFormConfig(). Each field has `enabled`
 * (true = backend ready → active input; false = render disabled + "Soon").
 * `creatable` = type can be provisioned today (node/python = false → preview only).
 */
$pageTitle = t($form['title']);
$creatable = (bool) $form['creatable'];

$hasApp = false;
foreach ($form['fields'] as $f) {
    if (($f['input'] ?? '') === 'application') { $hasApp = true; break; }
}

/** Render one field per its config (active vs "Soon"). */
$renderField = function (array $f): void {
    $dis   = empty($f['enabled']);
    $req   = !empty($f['required']) ? ' <span class="text-rose-500">*</span>' : '';
    $soon  = $dis ? ' <span class="tag tag-soon">' . e(t('site.add.soon')) . '</span>' : '';
    $inCls = 'inp' . ($dis ? ' inp-soon' : '') . (!empty($f['mono']) ? ' mono' : '');
    $da    = $dis ? ' disabled' : '';

    echo '<div>';
    echo '<label class="lbl">' . e(t($f['label'])) . $req . $soon . '</label>';

    switch ($f['input']) {
        case 'application':
            echo '<select name="type" class="inp">';
            foreach ($f['active'] as [$val, $label]) {
                echo '<option value="' . e($val) . '">' . e($label) . '</option>';
            }
            echo '<option disabled>──── ' . e(t('site.add.app_soon')) . ' ────</option>';
            foreach ($f['soon'] as $label) {
                echo '<option disabled>' . e($label) . '</option>';
            }
            echo '</select>';
            break;

        case 'select':
            echo '<select name="' . e($f['key']) . '" class="' . $inCls . '"' . $da . '>';
            foreach ($f['options'] as $opt) {
                if (is_array($opt)) {   // structured: value/label, optional disabled/selected
                    $od = !empty($opt['disabled']) ? ' disabled' : '';
                    $os = !empty($opt['selected']) ? ' selected' : '';
                    echo '<option value="' . e((string) $opt['value']) . '"' . $od . $os . '>'
                       . e((string) ($opt['label'] ?? $opt['value'])) . '</option>';
                } else {                // plain string: value == label
                    echo '<option value="' . e($opt) . '">' . e($opt) . '</option>';
                }
            }
            echo '</select>';
            break;

        case 'phpselect':
            echo '<select name="' . e($f['key']) . '" class="' . $inCls . '" data-phpselect' . $da . '>';
            foreach ($f['options'] as $opt) {
                $needs = !empty($opt['disabled']) ? ' data-needs-install="1"' : '';
                $sel   = !empty($opt['default']) ? ' selected' : '';
                $sfx   = !empty($opt['disabled']) ? ' — ' . t('php.will_install') : '';
                echo '<option value="' . e($opt['value']) . '"' . $needs . $sel . '>PHP ' . e($opt['value']) . e($sfx) . '</option>';
            }
            echo '</select>';
            echo '<p class="hint">' . e(t('site.add.php_autoinstall_hint')) . '</p>';
            break;

        default: // text | password (shown as visible text)
            $val = isset($f['value'])       ? ' value="' . e($f['value']) . '"'             : '';
            $ph  = isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
            // Enforce required at the browser too (an empty WP admin email used to
            // slip through and skip the whole WordPress install), and keep every
            // submitted value — incl. generated admin passwords — out of the
            // browser's autofill history.
            $rq  = (!$dis && !empty($f['required'])) ? ' required' : '';
            echo '<input type="text" name="' . e($f['key']) . '" class="' . $inCls . '" autocomplete="off" spellcheck="false"' . $val . $ph . $rq . $da . '>';
            if (!empty($f['generate'])) {
                $gc = $dis ? 'text-zinc-300 cursor-not-allowed' : 'text-ink hover:underline';
                echo '<button type="button" data-gen-pass="' . e($f['key']) . '" class="text-[11px] ' . $gc . ' mt-1 inline-flex items-center gap-1"' . $da . '>'
                   . icon('refresh', 'text-xs') . ' ' . e(t('site.add.generate')) . '</button>';
            }
    }

    if (!empty($f['note'])) {
        echo '<p class="hint">' . e(t($f['note'])) . '</p>';
    }
    echo '</div>';
};
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-1.5 text-xs text-zinc-400 mb-3">
    <a href="/sites/add" class="hover:text-ink flex items-center gap-1"><?= icon('arrow-left', 'text-sm') ?> <?= e(t('site.add.back')) ?></a>
    <span>·</span><span class="mono text-zinc-500">/sites/add/<?= e($form['slug']) ?></span>
  </div>

  <div class="card p-6">
    <div class="flex items-center gap-3 mb-5">
      <span class="w-10 h-10 rounded-lg <?= $creatable ? 'bg-ink-pale' : 'bg-zinc-100' ?> flex items-center justify-center">
        <?= icon($form['icon'], ($creatable ? 'text-ink' : 'text-zinc-400') . ' text-xl') ?>
      </span>
      <div>
        <h2 class="font-head font-bold text-lg <?= $creatable ? 'text-zinc-900' : 'text-zinc-700' ?> leading-none"><?= e(t($form['title'])) ?></h2>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t($form['desc'])) ?></p>
      </div>
    </div>

    <?php if (!empty($form['banner'])): ?>
    <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-2.5 mb-5">
      <?= icon('tools', 'text-amber-500 mt-0.5 text-sm') ?>
      <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t($form['banner'])) ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="/sites/add" autocomplete="off" x-data="phpCreateForm()" @submit="onSubmit($event)" x-ref="form">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <?php if ($creatable && !$hasApp): ?>
        <input type="hidden" name="type" value="<?= e($form['type']) ?>">
      <?php endif; ?>

      <div data-op-fields>
        <div class="space-y-4">
          <?php foreach ($form['fields'] as $f) { $renderField($f); } ?>
        </div>

        <div class="flex items-center gap-3 mt-6 pt-5 border-t border-zinc-100">
          <?php if ($creatable): ?>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
              <span x-show="!submitting"><?= icon('plus', 'text-sm') ?> <?= e(t('site.add.create')) ?></span>
              <span x-show="submitting" x-cloak><?= icon('loader-2', 'text-sm spin') ?> <?= e(t('site.add.creating')) ?></span>
            </button>
            <a href="/sites/add" class="text-xs font-medium text-zinc-500 hover:text-zinc-700 px-2"><?= e(t('common.cancel')) ?></a>
          <?php else: ?>
            <button type="button" class="btn btn-secondary" disabled><?= icon('clock', 'text-sm') ?> <?= e(t('site.add.coming_soon')) ?></button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($creatable): ?>
        <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
      <?php endif; ?>

      <!-- Modal: confirm on-demand PHP install before create -->
      <div x-show="confirmVer" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-zinc-900/40"></div>
        <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
          <div class="card-head flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <span class="w-8 h-8 rounded-lg bg-ink-pale flex items-center justify-center shrink-0">
                <?= icon('download', 'text-ink') ?>
              </span>
              <h3 class="card-title"><span x-text="'PHP ' + confirmVer"></span> <?= e(t('site.add.php_modal_title')) ?></h3>
            </div>
            <button type="button" @click="confirmVer=null" :disabled="submitting" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed"><?= icon('x') ?></button>
          </div>
          <div class="p-5 space-y-4">
            <p class="text-sm text-zinc-600"><?= e(t('site.add.php_modal_body')) ?></p>
            <div class="flex items-start gap-2 bg-amber-50 border border-amber-200/70 rounded-lg px-3 py-2">
              <?= icon('clock', 'text-amber-500 mt-0.5 text-sm') ?>
              <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t('site.add.php_modal_eta')) ?></p>
            </div>
            <div x-show="submitting" x-cloak class="flex items-start gap-2 bg-ink-pale border border-ink/15 rounded-lg px-3 py-2">
              <?= icon('loader-2', 'text-ink mt-0.5 text-sm spin') ?>
              <p class="text-[11px] text-ink leading-relaxed"><?= e(t('site.add.php_installing_note')) ?></p>
            </div>
            <div class="flex justify-end gap-2 pt-1">
              <button type="button" @click="confirmVer=null" :disabled="submitting" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              <button type="button" @click="proceed()" :disabled="submitting" class="btn btn-primary">
                <span x-show="!submitting"><?= icon('check', 'text-sm') ?> <?= e(t('site.add.php_modal_confirm')) ?></span>
                <span x-show="submitting" x-cloak><?= icon('loader-2', 'text-sm spin') ?> <?= e(t('site.add.creating')) ?></span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
// Prefill the Site User input from the domain (first label, sanitised) until the
// user edits it manually. Mirrors the CLI's _suggest_site_user.
(function () {
  var d = document.querySelector('input[name="domain"]');
  var u = document.querySelector('input[name="site_user"]');
  if (!d || !u) return;
  var edited = false;
  u.addEventListener('input', function () { edited = true; });
  d.addEventListener('input', function () {
    if (edited) return;
    var base = (d.value.split('.')[0] || '').toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-+/, '');
    if (base && !/^[a-z]/.test(base)) base = 'site' + base;
    u.value = base.slice(0, 32);
  });
})();

// Wire each "Generate" button to refill its password field with a fresh random
// value (the field also carries a server-random default on first render).
document.querySelectorAll('[data-gen-pass]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.querySelector('input[name="' + btn.getAttribute('data-gen-pass') + '"]');
    if (!input || input.disabled) return;
    var bytes = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    input.value = Array.prototype.map.call(bytes, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  });
});
</script>
<script>
function phpCreateForm() {
  return {
    submitting: false,
    confirmVer: null,   // set to the version string when an in-app confirm is needed
    onSubmit(e) {
      e.preventDefault();                  // always stream — never a native submit
      // If a not-installed PHP version is selected, confirm first; the modal's
      // Confirm calls proceed(). Otherwise stream the create straight away.
      var opt = this._selectedNeedsInstall();
      if (opt && !this.confirmVer) {
        this.confirmVer = opt.value;
        return;
      }
      this._stream();
    },
    proceed() {                            // modal Confirm
      this.confirmVer = null;
      this._stream();
    },
    // Submit via SSE: show the live progress bar, then redirect on success.
    _stream() {
      if (this.submitting) return;
      this.submitting = true;
      window.opGuard.start();              // warn on reload/leave until we redirect
      var form   = this.$root;
      var fields = form.querySelector('[data-op-fields]');
      var ui     = window.opProgressController(form.querySelector('[data-op-progress]'));
      var fd     = new FormData(form);
      fd.set('stream', '1');
      if (fields) fields.classList.add('hidden');
      if (ui) ui.show();
      var self = this;
      window.opStream(form.getAttribute('action'), fd, {
        onProgress: function (pct, key, msg) { if (ui) ui.set(pct, key, msg); },
        onDone: function (frame) {
          window.opGuard.stop();
          if (frame.ok) {
            if (ui) ui.done();
            try { sessionStorage.setItem('aidipanel_toast', JSON.stringify({ kind: 'success', message: frame.message })); } catch (e) {}
            window.location.href = frame.redirect;
          } else {
            self.submitting = false;
            if (ui) ui.fail(frame.message || 'Operation failed.');
            if (fields) fields.classList.remove('hidden');
          }
        },
        onError: function (msg) {
          window.opGuard.stop();
          self.submitting = false;
          if (ui) ui.fail(msg);
          if (fields) fields.classList.remove('hidden');
        }
      });
    },
    _selectedNeedsInstall() {
      var sel = this.$root.querySelector('select[data-phpselect]');
      if (!sel) return null;
      var opt = sel.options[sel.selectedIndex];
      return (opt && opt.getAttribute('data-needs-install') === '1') ? opt : null;
    }
  };
}
</script>
