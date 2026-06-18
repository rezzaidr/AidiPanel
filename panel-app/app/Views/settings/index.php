<?php
/**
 * Account settings — self-service profile + security.
 * Design-complete preview: layout is final; saving + 2FA enrolment land in a
 * follow-up PR (need new user columns + a TOTP flow). Inputs are shown for the
 * design; the Save/Enable actions are disabled until the PR wires them up.
 */
$_username = (string) ($_user['username'] ?? 'admin');
// A short, sensible timezone list for the preview; the PR ships the full set.
$timezones = ['UTC', 'Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'Asia/Singapore', 'Europe/London', 'America/New_York'];
?>
<div x-data="{ tab: 'profile' }">

  <div class="mb-5">
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('settings.title')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('settings.subtitle')) ?></p>
  </div>

  <!-- Preview notice: layout is final, saving + 2FA come in a follow-up update -->
  <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-3 mb-5 max-w-2xl">
    <i class="ti ti-tool text-amber-500 mt-0.5 shrink-0"></i>
    <p class="text-xs text-amber-800 leading-relaxed"><?= e(t('settings.preview_note')) ?></p>
  </div>

  <!-- Tabs -->
  <div class="flex items-center gap-1 border-b border-zinc-200 mb-5">
    <button type="button" @click="tab='profile'"
            :class="tab==='profile' ? 'text-ink border-ink' : 'text-zinc-500 border-transparent hover:text-zinc-800'"
            class="flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer">
      <i class="ti ti-user-circle text-base"></i> <?= e(t('settings.tab.profile')) ?>
    </button>
    <button type="button" @click="tab='security'"
            :class="tab==='security' ? 'text-ink border-ink' : 'text-zinc-500 border-transparent hover:text-zinc-800'"
            class="flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer">
      <i class="ti ti-shield-lock text-base"></i> <?= e(t('settings.tab.security')) ?>
    </button>
  </div>

  <!-- ================= PROFILE ================= -->
  <div x-show="tab==='profile'" x-cloak class="max-w-2xl space-y-5">

    <!-- Profile details -->
    <div class="card">
      <div class="card-head"><h2 class="card-title"><i class="ti ti-id-badge-2 text-ink"></i> <?= e(t('settings.profile.title')) ?></h2></div>
      <div class="px-5 py-4 space-y-4">
        <div>
          <label class="lbl"><?= e(t('settings.f.username')) ?></label>
          <input type="text" class="inp w-full bg-zinc-50 text-zinc-500" value="<?= e($_username) ?>" disabled>
          <p class="hint"><?= e(t('settings.f.username_hint')) ?></p>
        </div>
        <div>
          <label class="lbl"><?= e(t('settings.f.email')) ?></label>
          <input type="email" class="inp w-full" placeholder="you@example.com">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="lbl"><?= e(t('settings.f.first_name')) ?></label>
            <input type="text" class="inp w-full" placeholder="<?= e(t('settings.f.first_name')) ?>">
          </div>
          <div>
            <label class="lbl"><?= e(t('settings.f.last_name')) ?></label>
            <input type="text" class="inp w-full" placeholder="<?= e(t('settings.f.last_name')) ?>">
          </div>
        </div>
        <div>
          <label class="lbl"><?= e(t('settings.f.timezone')) ?></label>
          <select class="inp w-full">
            <?php foreach ($timezones as $tz): ?>
            <option value="<?= e($tz) ?>"<?= $tz === 'UTC' ? ' selected' : '' ?>><?= e($tz) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
        <button type="button" class="btn btn-primary opacity-50 cursor-not-allowed" disabled title="<?= e(t('settings.soon_tooltip')) ?>"><?= e(t('settings.profile.btn')) ?></button>
      </div>
    </div>

    <!-- Change password -->
    <div class="card">
      <div class="card-head"><h2 class="card-title"><i class="ti ti-key text-ink"></i> <?= e(t('settings.password.title')) ?></h2></div>
      <div class="px-5 py-4 space-y-4">
        <div>
          <label class="lbl"><?= e(t('settings.f.current_password')) ?></label>
          <input type="password" class="inp w-full" autocomplete="current-password">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="lbl"><?= e(t('settings.f.new_password')) ?></label>
            <input type="password" class="inp w-full" autocomplete="new-password">
          </div>
          <div>
            <label class="lbl"><?= e(t('settings.f.confirm_password')) ?></label>
            <input type="password" class="inp w-full" autocomplete="new-password">
          </div>
        </div>
      </div>
      <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
        <button type="button" class="btn btn-primary opacity-50 cursor-not-allowed" disabled title="<?= e(t('settings.soon_tooltip')) ?>"><?= e(t('settings.password.btn')) ?></button>
      </div>
    </div>
  </div>

  <!-- ================= SECURITY ================= -->
  <div x-show="tab==='security'" x-cloak class="max-w-2xl">
    <div class="card">
      <div class="card-head">
        <h2 class="card-title"><i class="ti ti-shield-lock text-ink"></i> <?= e(t('settings.2fa.title')) ?></h2>
        <span class="badge badge-muted"><?= e(t('common.soon')) ?></span>
      </div>
      <div class="px-5 py-4">
        <div class="flex items-start gap-3.5">
          <span class="w-10 h-10 rounded-lg bg-ink-pale text-ink flex items-center justify-center shrink-0"><i class="ti ti-device-mobile-check text-xl"></i></span>
          <div class="flex-1">
            <p class="text-sm font-medium text-zinc-800"><?= e(t('settings.2fa.heading')) ?></p>
            <p class="hint mt-1"><?= e(t('settings.2fa.desc')) ?></p>
          </div>
        </div>
      </div>
      <div class="flex justify-end px-5 py-3.5 border-t border-zinc-100">
        <button type="button" class="btn btn-primary opacity-50 cursor-not-allowed" disabled title="<?= e(t('settings.soon_tooltip')) ?>"><i class="ti ti-shield-plus text-sm"></i> <?= e(t('settings.2fa.btn')) ?></button>
      </div>
    </div>
  </div>

</div>
