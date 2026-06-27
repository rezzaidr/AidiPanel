<?php
/**
 * Account settings — self-service profile + security.
 * Profile tab is functional (profile fields, timezone, change password).
 * The Security (2FA) tab is still a preview until a follow-up PR.
 */
?>
<div x-data="{ tab: '<?= (($twofa['pending'] ?? null) || ($twofa['show_codes'] ?? null)) ? 'security' : 'profile' ?>' }">

  <div class="mb-5">
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('settings.title')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('settings.subtitle')) ?></p>
  </div>

  <!-- Tabs (shared .tab component, matching the site-detail tabs) -->
  <div class="border-b border-zinc-200 mb-5">
    <div class="flex items-center overflow-x-auto -mb-px">
      <button type="button" @click="tab='profile'" :class="tab==='profile' ? 'active' : ''" class="tab">
        <?= icon('user-circle', 'text-[15px]') ?> <?= e(t('settings.tab.profile')) ?>
      </button>
      <button type="button" @click="tab='security'" :class="tab==='security' ? 'active' : ''" class="tab">
        <?= icon('shield-lock', 'text-[15px]') ?> <?= e(t('settings.tab.security')) ?>
      </button>
    </div>
  </div>

  <!-- ================= PROFILE ================= -->
  <div x-show="tab==='profile'" x-cloak class="space-y-5">

    <!-- Profile details -->
    <div class="card">
      <div class="card-head"><h2 class="card-title"><?= icon('id-badge-2', 'text-ink') ?> <?= e(t('settings.profile.title')) ?></h2></div>
      <form method="POST" action="/settings/profile">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div class="px-5 py-4 space-y-4">
          <div>
            <label class="lbl"><?= e(t('settings.f.username')) ?></label>
            <input type="text" class="inp w-full bg-zinc-50 text-zinc-500" value="<?= e($profile['username'] ?? '') ?>" disabled>
            <p class="hint"><?= e(t('settings.f.username_hint')) ?></p>
          </div>
          <div>
            <label class="lbl"><?= e(t('settings.f.email')) ?></label>
            <input type="email" name="email" class="inp w-full" placeholder="you@example.com" value="<?= e($profile['email'] ?? '') ?>">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="lbl"><?= e(t('settings.f.first_name')) ?></label>
              <input type="text" name="first_name" class="inp w-full" placeholder="<?= e(t('settings.f.first_name')) ?>" value="<?= e($profile['first_name'] ?? '') ?>">
            </div>
            <div>
              <label class="lbl"><?= e(t('settings.f.last_name')) ?></label>
              <input type="text" name="last_name" class="inp w-full" placeholder="<?= e(t('settings.f.last_name')) ?>" value="<?= e($profile['last_name'] ?? '') ?>">
            </div>
          </div>
          <div>
            <label class="lbl"><?= e(t('settings.f.timezone')) ?></label>
            <select name="timezone" class="inp w-full">
              <?php foreach ($tzGroups as $continent => $zones): ?>
                <?php if ($continent === ''): ?>
                  <?php foreach ($zones as $z): ?>
                  <option value="<?= e($z['value']) ?>"<?= $z['value'] === $tzSelected ? ' selected' : '' ?>><?= e($z['label']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <optgroup label="<?= e($continent) ?>">
                    <?php foreach ($zones as $z): ?>
                    <option value="<?= e($z['value']) ?>"<?= $z['value'] === $tzSelected ? ' selected' : '' ?>><?= e($z['label']) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
          <button type="submit" class="btn btn-primary"><?= e(t('settings.profile.btn')) ?></button>
        </div>
      </form>
    </div>

    <!-- Change password -->
    <div class="card">
      <div class="card-head"><h2 class="card-title"><?= icon('key', 'text-ink') ?> <?= e(t('settings.password.title')) ?></h2></div>
      <form method="POST" action="/settings/password">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div class="px-5 py-4 space-y-4">
          <div>
            <label class="lbl"><?= e(t('settings.f.current_password')) ?></label>
            <div class="relative" x-data="{ show:false }"><input type="password" name="current_password" required :type="show ? 'text' : 'password'" class="inp w-full pr-10" style="padding-right:2.5rem" autocomplete="current-password"><button type="button" @click="show=!show" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="show ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!show"><?= icon('eye', 'text-base') ?></span><span x-show="show" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="lbl"><?= e(t('settings.f.new_password')) ?></label>
              <div class="relative" x-data="{ show:false }"><input type="password" name="new_password" required minlength="8" :type="show ? 'text' : 'password'" class="inp w-full pr-10" style="padding-right:2.5rem" autocomplete="new-password"><button type="button" @click="show=!show" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="show ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!show"><?= icon('eye', 'text-base') ?></span><span x-show="show" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
            </div>
            <div>
              <label class="lbl"><?= e(t('settings.f.confirm_password')) ?></label>
              <div class="relative" x-data="{ show:false }"><input type="password" name="confirm_password" required minlength="8" :type="show ? 'text' : 'password'" class="inp w-full pr-10" style="padding-right:2.5rem" autocomplete="new-password"><button type="button" @click="show=!show" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="show ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!show"><?= icon('eye', 'text-base') ?></span><span x-show="show" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
            </div>
          </div>
        </div>
        <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
          <button type="submit" class="btn btn-primary"><?= e(t('settings.password.btn')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- ================= SECURITY ================= -->
  <div x-show="tab==='security'" x-cloak class="space-y-5">

    <?php if (!empty($twofa['show_codes'])): ?>
    <!-- One-time recovery codes (shown once, right after enable / regenerate) -->
    <div class="card border-emerald-200">
      <div class="card-head bg-emerald-50/50">
        <h2 class="card-title text-emerald-800"><?= icon('shield-check', 'text-emerald-600') ?> <?= e(t('settings.2fa.recovery.title')) ?></h2>
      </div>
      <div class="px-5 py-4">
        <p class="hint mb-3"><?= e(t('settings.2fa.recovery.hint')) ?></p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mono text-sm bg-zinc-50 border border-zinc-200 rounded-lg p-4">
          <?php foreach ($twofa['show_codes'] as $rc): ?>
          <div class="text-zinc-800"><?= e($rc) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="flex gap-2 mt-3" x-data="{ copied:false }">
          <button type="button" class="btn btn-secondary btn-sm"
            @click="navigator.clipboard.writeText($refs.codes.innerText.trim()); copied=true; setTimeout(()=>copied=false,1500)">
            <?= icon('copy', 'text-sm') ?> <span x-show="!copied"><?= e(t('settings.2fa.recovery.copy')) ?></span><span x-show="copied" x-cloak class="text-emerald-600"><?= e(t('topbar.copied')) ?></span>
          </button>
        </div>
        <pre x-ref="codes" class="hidden"><?php foreach ($twofa['show_codes'] as $rc) { echo e($rc) . "\n"; } ?></pre>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">
        <h2 class="card-title"><?= icon('shield-lock', 'text-ink') ?> <?= e(t('settings.2fa.title')) ?></h2>
        <?php if (!empty($twofa['enabled'])): ?>
        <span class="badge badge-ok"><span class="dot bg-emerald-500"></span> <?= e(t('settings.2fa.on')) ?></span>
        <?php else: ?>
        <span class="badge badge-muted"><?= e(t('settings.2fa.off')) ?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($twofa['enabled'])): ?>
      <!-- STATE: enabled — disable + regenerate recovery codes (both re-auth with password) -->
      <div class="px-5 py-4" x-data="{ modal:null }">
        <div class="flex items-start gap-3.5">
          <span class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><?= icon('shield-check', 'text-xl') ?></span>
          <div class="flex-1">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('settings.2fa.enabled_heading')) ?></p>
            <p class="hint mt-1"><?= e(t('settings.2fa.remaining', ['n' => (string) ($twofa['remaining'] ?? 0)])) ?></p>
          </div>
        </div>
        <div class="flex gap-2 mt-4">
          <button type="button" class="btn btn-secondary btn-sm" @click="modal='regen'"><?= icon('refresh', 'text-sm') ?> <?= e(t('settings.2fa.regen_btn')) ?></button>
          <button type="button" class="btn btn-danger btn-sm" @click="modal='disable'"><?= icon('shield-off', 'text-sm') ?> <?= e(t('settings.2fa.disable_btn')) ?></button>
        </div>

        <!-- Modal: disable (password) -->
        <div x-show="modal==='disable'" x-cloak class="fixed inset-0 z-50">
          <div class="absolute inset-0 bg-zinc-900/40"></div>
          <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
            <div class="card-head flex items-center justify-between bg-red-50/50">
              <h3 class="card-title text-red-700"><?= icon('shield-off') ?> <?= e(t('settings.2fa.disable_title')) ?></h3>
              <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
            </div>
            <form method="POST" action="/settings/2fa/disable" class="p-5 space-y-3">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <p class="text-sm text-zinc-600"><?= e(t('settings.2fa.disable_warn')) ?></p>
              <label class="lbl"><?= e(t('settings.f.current_password')) ?></label>
              <div class="relative" x-data="{ show:false }"><input type="password" name="current_password" required :type="show ? 'text' : 'password'" class="inp w-full pr-10" style="padding-right:2.5rem" autocomplete="current-password"><button type="button" @click="show=!show" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="show ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!show"><?= icon('eye', 'text-base') ?></span><span x-show="show" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
              <div class="flex justify-end gap-2 pt-1">
                <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-danger"><?= e(t('settings.2fa.disable_btn')) ?></button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal: regenerate recovery codes (password) -->
        <div x-show="modal==='regen'" x-cloak class="fixed inset-0 z-50">
          <div class="absolute inset-0 bg-zinc-900/40"></div>
          <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
            <div class="card-head flex items-center justify-between">
              <h3 class="card-title"><?= icon('refresh') ?> <?= e(t('settings.2fa.regen_title')) ?></h3>
              <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
            </div>
            <form method="POST" action="/settings/2fa/recovery" class="p-5 space-y-3">
              <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
              <p class="text-sm text-zinc-600"><?= e(t('settings.2fa.regen_warn')) ?></p>
              <label class="lbl"><?= e(t('settings.f.current_password')) ?></label>
              <div class="relative" x-data="{ show:false }"><input type="password" name="current_password" required :type="show ? 'text' : 'password'" class="inp w-full pr-10" style="padding-right:2.5rem" autocomplete="current-password"><button type="button" @click="show=!show" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="show ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!show"><?= icon('eye', 'text-base') ?></span><span x-show="show" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
              <div class="flex justify-end gap-2 pt-1">
                <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('settings.2fa.regen_btn')) ?></button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <?php elseif (!empty($twofa['pending'])): ?>
      <!-- STATE: enrolling — show QR + secret + confirm code -->
      <div class="px-5 py-4">
        <p class="hint mb-4"><?= e(t('settings.2fa.enroll_hint')) ?></p>
        <div class="flex flex-col sm:flex-row gap-5 items-center sm:items-start">
          <div class="shrink-0 p-3 bg-white border border-zinc-200 rounded-lg">
            <div id="totp-qr"></div>
          </div>
          <div class="flex-1 w-full">
            <p class="lbl"><?= e(t('settings.2fa.manual_label')) ?></p>
            <code class="block mono text-xs bg-zinc-50 border border-zinc-200 rounded px-3 py-2 break-all"><?= e($twofa['pending']['secret']) ?></code>
            <label class="lbl mt-4"><?= e(t('settings.2fa.confirm_label')) ?></label>
            <div class="flex items-center gap-2 flex-wrap">
              <form method="POST" action="/settings/2fa/enable" class="flex items-center gap-2">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="text" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code"
                  class="inp w-32 text-center tracking-widest" placeholder="123456" spellcheck="false">
                <button type="submit" class="btn btn-primary"><?= icon('check', 'text-sm') ?> <?= e(t('settings.2fa.confirm_btn')) ?></button>
              </form>
              <form method="POST" action="/settings/2fa/cancel">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <button type="submit" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <script defer src="/assets/vendor/qrcode.min.js"></script>
      <script>
        // Render the otpauth URI as a QR, client-side, from a vendored local lib (no CDN).
        document.addEventListener('DOMContentLoaded', function () {
          var uri = <?= json_encode($twofa['pending']['uri'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          var el = document.getElementById('totp-qr');
          if (!el || typeof qrcode === 'undefined') return;
          var qr = qrcode(0, 'M');
          qr.addData(uri);
          qr.make();
          el.innerHTML = qr.createImgTag(5, 8);   // cellSize, margin
        });
      </script>

      <?php else: ?>
      <!-- STATE: disabled — offer enrollment -->
      <div class="px-5 py-4">
        <div class="flex items-start gap-3.5">
          <span class="w-10 h-10 rounded-lg bg-ink-pale text-ink flex items-center justify-center shrink-0"><?= icon('device-mobile-check', 'text-xl') ?></span>
          <div class="flex-1">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('settings.2fa.heading')) ?></p>
            <p class="hint mt-1"><?= e(t('settings.2fa.desc')) ?></p>
          </div>
        </div>
      </div>
      <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
        <form method="POST" action="/settings/2fa/start">
          <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
          <button type="submit" class="btn btn-primary"><?= icon('shield-plus', 'text-sm') ?> <?= e(t('settings.2fa.btn')) ?></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
