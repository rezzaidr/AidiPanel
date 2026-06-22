<?php
/**
 * Account settings — self-service profile + security.
 * Profile tab is functional (profile fields, timezone, change password).
 * The Security (2FA) tab is still a preview until a follow-up PR.
 */
?>
<div x-data="{ tab: 'profile' }">

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
  <div x-show="tab==='profile'" x-cloak class="max-w-2xl space-y-5">

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
            <input type="password" name="current_password" required class="inp w-full" autocomplete="current-password">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="lbl"><?= e(t('settings.f.new_password')) ?></label>
              <input type="password" name="new_password" required minlength="8" class="inp w-full" autocomplete="new-password">
            </div>
            <div>
              <label class="lbl"><?= e(t('settings.f.confirm_password')) ?></label>
              <input type="password" name="confirm_password" required minlength="8" class="inp w-full" autocomplete="new-password">
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
  <div x-show="tab==='security'" x-cloak class="max-w-2xl">
    <div class="card">
      <div class="card-head">
        <h2 class="card-title"><?= icon('shield-lock', 'text-ink') ?> <?= e(t('settings.2fa.title')) ?></h2>
        <span class="badge badge-muted"><?= e(t('common.soon')) ?></span>
      </div>
      <div class="px-5 py-4">
        <div class="flex items-start gap-3.5">
          <span class="w-10 h-10 rounded-lg bg-ink-pale text-ink flex items-center justify-center shrink-0"><?= icon('device-mobile-check', 'text-xl') ?></span>
          <div class="flex-1">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('settings.2fa.heading')) ?></p>
            <p class="hint mt-1"><?= e(t('settings.2fa.desc')) ?></p>
          </div>
        </div>
        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200/70 rounded-md px-3 py-2 mt-3"><?= e(t('settings.2fa.soon_note')) ?></p>
      </div>
      <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
        <button type="button" class="btn btn-primary opacity-50 cursor-not-allowed" disabled title="<?= e(t('settings.soon_tooltip')) ?>"><?= icon('shield-plus', 'text-sm') ?> <?= e(t('settings.2fa.btn')) ?></button>
      </div>
    </div>
  </div>

</div>
