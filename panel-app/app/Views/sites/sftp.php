<?php
/**
 * Manage Site → SFTP. Per-site jailed SFTP access: enable/disable, password
 * (set/generate/clear) and SSH public keys. Required from detail.php, so
 * $site / $domain are in scope. One card with sections (matches Security/Cron);
 * Alpine + window.api + window.AidiToast; confirmations use a teleported modal.
 */
$sftpReady = !empty($site['site_user']);
?>
<?php if (!$sftpReady): ?>
  <div class="card px-5 py-12 text-center text-sm text-zinc-500"><?= e(t('site.sftp.not_ready')) ?></div>
<?php else: ?>
<div class="space-y-4" x-data="sftpManager('<?= e($domain) ?>')" x-init="init()">
  <div class="card">

    <!-- header + enable toggle -->
    <div class="card-head">
      <div class="flex items-center gap-2.5 min-w-0">
        <?= icon('key', 'text-lg', [':class' => "s.enabled ? 'text-indigo-500' : 'text-zinc-300'"]) ?>
        <div class="min-w-0">
          <h2 class="card-title">
            <?= e(t('site.sftp.title')) ?>
            <span class="badge" :class="s.enabled ? 'badge-ok' : 'badge-muted'">
              <span class="dot" :class="s.enabled ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
              <span x-text="s.enabled ? <?= json_encode(t('site.sftp.on'), JSON_HEX_TAG) ?> : <?= json_encode(t('site.sftp.off'), JSON_HEX_TAG) ?>"></span>
            </span>
          </h2>
          <p class="text-[11px] text-zinc-400 mt-0.5"><?= e(t('site.sftp.subtitle')) ?></p>
        </div>
      </div>
      <label class="inline-flex items-center gap-2 shrink-0" :class="(loading || busy) ? 'opacity-50 pointer-events-none' : 'cursor-pointer'">
        <span class="text-[11px] font-medium text-zinc-500" x-text="s.enabled ? <?= json_encode(t('site.sftp.on'), JSON_HEX_TAG) ?> : <?= json_encode(t('site.sftp.off'), JSON_HEX_TAG) ?>"></span>
        <button type="button" @click="toggle()" :class="s.enabled ? 'sw-on' : 'sw-off'" aria-label="<?= e(t('site.sftp.title')) ?>"><span></span></button>
      </label>
    </div>

    <!-- loading -->
    <div x-show="loading" class="px-5 py-10 text-center text-sm text-zinc-400"><?= e(t('site.sftp.loading')) ?></div>

    <!-- disabled: hint -->
    <template x-if="!loading && !s.enabled">
      <div class="px-5 py-5">
        <p class="text-[13px] text-zinc-600 leading-relaxed"><?= e(t('site.sftp.enable_hint')) ?></p>
      </div>
    </template>

    <!-- connection -->
    <template x-if="!loading && s.enabled">
      <div class="px-5 py-4 border-t border-zinc-100">
        <p class="eyebrow mb-2.5"><?= e(t('site.sftp.connection')) ?></p>
        <?php $sftpConn = [
            ['Host', 's.host', true], ['Port', 's.port', false], ['Username', 's.user', true],
            ['Protocol', 's.protocol', false], ['Folder', 's.path', false],
        ]; ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-3">
          <?php foreach ($sftpConn as [$cLabel, $cExpr, $cCopy]): ?>
          <div class="min-w-0">
            <p class="text-[10px] uppercase tracking-wide text-zinc-400"><?= e($cLabel) ?></p>
            <?php if ($cCopy): ?>
            <button type="button" class="group inline-flex max-w-full items-center gap-1.5 text-left text-[13px] mono text-zinc-700 hover:text-indigo-600" @click="copy(String(<?= $cExpr ?>), $event)" title="<?= e('Copy ' . $cLabel) ?>">
              <span class="truncate min-w-0" x-text="<?= $cExpr ?>"></span>
              <?= icon('copy', 'text-[11px] text-zinc-300 group-hover:text-indigo-400 shrink-0') ?>
            </button>
            <?php else: ?>
            <p class="text-[13px] mono text-zinc-700 truncate" x-text="<?= $cExpr ?>"></p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </template>

    <!-- password -->
    <template x-if="!loading && s.enabled">
      <div class="px-5 py-4 border-t border-zinc-100 space-y-3">
        <div class="flex items-center gap-2">
          <p class="eyebrow"><?= e(t('site.sftp.password')) ?></p>
          <span class="badge" :class="s.password_set ? 'badge-ok' : 'badge-muted'">
            <span class="dot" :class="s.password_set ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
            <span x-text="s.password_set ? <?= json_encode(t('site.sftp.pw_on'), JSON_HEX_TAG) ?> : <?= json_encode(t('site.sftp.pw_off'), JSON_HEX_TAG) ?>"></span>
          </span>
        </div>
        <p class="text-[13px] text-zinc-500 leading-relaxed" x-text="s.password_set ? <?= json_encode(t('site.sftp.pw_set_note'), JSON_HEX_TAG) ?> : <?= json_encode(t('site.sftp.pw_unset_note'), JSON_HEX_TAG) ?>"></p>
        <template x-if="generated">
          <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
            <div class="min-w-0 flex-1">
              <p class="text-[10px] uppercase tracking-wide text-amber-700"><?= e(t('site.sftp.pw_show_once')) ?></p>
              <code class="text-[13px] mono text-amber-900 break-all" x-text="generated"></code>
            </div>
            <button type="button" class="btn btn-ghost btn-sm shrink-0" @click="copy(generated, $event)"><?= icon('copy', 'text-sm') ?></button>
          </div>
        </template>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn btn-secondary btn-sm" @click="askPassword()"><?= icon('pencil', 'text-sm') ?> <?= e(t('site.sftp.pw_set')) ?></button>
          <button type="button" class="btn btn-secondary btn-sm" @click="generatePassword()" :disabled="busy"><?= icon('refresh', 'text-sm') ?> <?= e(t('site.sftp.pw_generate')) ?></button>
          <button type="button" x-show="s.password_set" class="btn btn-ghost btn-sm text-red-600 hover:bg-red-50" @click="askClearPassword()"><?= e(t('site.sftp.pw_clear')) ?></button>
        </div>
      </div>
    </template>

    <!-- ssh keys -->
    <template x-if="!loading && s.enabled">
      <div class="px-5 py-4 border-t border-zinc-100 space-y-3">
        <p class="eyebrow"><?= e(t('site.sftp.keys')) ?></p>
        <template x-if="!s.keys.length"><p class="text-[13px] text-zinc-400"><?= e(t('site.sftp.no_keys')) ?></p></template>
        <div class="space-y-2" x-show="s.keys.length">
          <template x-for="k in s.keys" :key="k.fingerprint">
            <div class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2">
              <?= icon('key', 'text-sm text-zinc-400 shrink-0') ?>
              <div class="min-w-0 flex-1">
                <p class="text-[13px] text-zinc-700 truncate" x-text="k.comment || '(no comment)'"></p>
                <p class="text-[11px] text-zinc-400 mono truncate"><span x-text="k.type"></span> · <span x-text="k.fingerprint"></span></p>
              </div>
              <button type="button" class="w-8 h-8 rounded-lg hover:bg-red-50 text-zinc-400 hover:text-red-600 shrink-0 flex items-center justify-center" @click="askDeleteKey(k)" :title="<?= json_encode(t('site.sftp.key_remove'), JSON_HEX_TAG) ?>"><?= icon('trash', 'text-sm') ?></button>
            </div>
          </template>
        </div>
        <div>
          <textarea x-model="newKey" rows="3" class="inp w-full mono text-[13px]" placeholder="ssh-ed25519 AAAA… user@host"></textarea>
          <div class="flex justify-end mt-2">
            <button type="button" class="btn btn-primary btn-sm" @click="addKey()" :disabled="busy || !newKey.trim()"><?= icon('plus', 'text-sm') ?> <?= e(t('site.sftp.add_key')) ?></button>
          </div>
        </div>
      </div>
    </template>

  </div>

  <!-- modal: set password (teleported) -->
  <template x-teleport="body">
  <div x-show="modal==='password'" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title"><?= icon('lock', 'text-zinc-400') ?> <?= e(t('site.sftp.pw_set')) ?></h3>
        <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
      </div>
      <form @submit.prevent="doSetPassword()" class="p-5 space-y-3">
        <div>
          <label class="lbl"><?= e(t('site.sftp.password')) ?></label>
          <div class="relative"><input x-ref="pwInput" x-model="form.pw" type="password" :type="pwShow ? 'text' : 'password'" autocomplete="off" spellcheck="false" class="inp w-full mono pr-10" style="padding-right:2.5rem" placeholder="<?= e(t('site.sftp.pw_placeholder')) ?>"><button type="button" @click="pwShow=!pwShow" tabindex="-1" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700" :title="pwShow ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>"><span x-show="!pwShow"><?= icon('eye', 'text-base') ?></span><span x-show="pwShow" x-cloak><?= icon('eye-off', 'text-base') ?></span></button></div>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary" :disabled="form.pw.length < 8"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.sftp.pw_set')) ?></button>
        </div>
      </form>
    </div>
  </div>
  </template>

  <!-- modal: confirm (teleported) -->
  <template x-teleport="body">
  <div x-show="confirm.open" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40" @click="confirm.open=false"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title"><?= icon('alert-triangle', 'text-amber-500') ?> <span x-text="confirm.title"></span></h3>
        <button type="button" @click="confirm.open=false" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
      </div>
      <div class="p-5">
        <p class="text-sm text-zinc-600 leading-relaxed" x-text="confirm.message"></p>
        <div class="flex justify-end gap-2 mt-5">
          <button type="button" @click="confirm.open=false" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="button" @click="runConfirm()" class="btn btn-danger" x-text="confirm.label"></button>
        </div>
      </div>
    </div>
  </div>
  </template>

</div>

<script>
(function () {
  window.sftpManager = function (domain) {
    const base = '/sites/' + domain + '/sftp';
    return {
      loading: true, busy: false, modal: null, generated: '', newKey: '',
      form: { pw: '' },
      pwShow: false,
      confirm: { open: false, title: '', message: '', label: '', fn: null },
      s: { enabled: false, password_set: false, keys: [], host: '', port: 22, user: '', path: '/htdocs', protocol: 'SFTP' },

      async init() { await this.load(); },
      async load() {
        this.loading = true;
        const j = await window.api(base + '/status');
        this.loading = false;
        if (j && j.success && j.data) { this.s = Object.assign(this.s, j.data); }
        else { window.AidiToast('error', (j && j.message) || 'Could not load SFTP status.'); }
      },
      async post(path, body) {
        this.busy = true;
        const j = await window.api(base + '/' + path, 'POST', body || {});
        this.busy = false;
        if (!j || !j.success) { window.AidiToast('error', (j && j.message) || 'Action failed.'); return null; }
        return j;
      },

      askConfirm(title, message, label, fn) { this.confirm = { open: true, title: title, message: message, label: label, fn: fn }; },
      runConfirm() { const f = this.confirm.fn; this.confirm.open = false; if (f) f(); },

      toggle() {
        if (this.busy) return;
        if (this.s.enabled) {
          this.askConfirm(<?= json_encode(t('site.sftp.disable'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.disable_confirm'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.disable'), JSON_HEX_TAG) ?>, () => this.doDisable());
        } else { this.enable(); }
      },
      async enable() { const j = await this.post('enable'); if (j) { window.AidiToast('success', 'SFTP enabled.'); await this.load(); } },
      async doDisable() { const j = await this.post('disable'); if (j) { this.generated = ''; window.AidiToast('success', 'SFTP disabled.'); await this.load(); } },

      askPassword() { this.form.pw = ''; this.modal = 'password'; this.$nextTick(() => this.$refs.pwInput && this.$refs.pwInput.focus()); },
      async doSetPassword() {
        if (this.form.pw.length < 8) return;
        const j = await this.post('password', { password: this.form.pw });
        this.modal = null;
        if (j) { this.generated = ''; window.AidiToast('success', 'Password set.'); await this.load(); }
      },
      async generatePassword() {
        const pw = this.randPw(20);
        const j = await this.post('password', { password: pw });
        if (j) { this.generated = pw; window.AidiToast('success', 'Password generated — copy it now.'); await this.load(); }
      },
      askClearPassword() {
        this.askConfirm(<?= json_encode(t('site.sftp.pw_clear'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.pw_clear_confirm'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.pw_clear'), JSON_HEX_TAG) ?>, () => this.doClearPassword());
      },
      async doClearPassword() { const j = await this.post('password-clear'); if (j) { this.generated = ''; window.AidiToast('success', 'Password disabled.'); await this.load(); } },

      async addKey() {
        const k = this.newKey.trim(); if (!k) return;
        const j = await this.post('key-add', { key: k });
        if (j) { this.newKey = ''; window.AidiToast('success', 'Key added.'); await this.load(); }
      },
      askDeleteKey(k) {
        this.askConfirm(<?= json_encode(t('site.sftp.key_remove'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.key_del_confirm'), JSON_HEX_TAG) ?>, <?= json_encode(t('site.sftp.key_remove'), JSON_HEX_TAG) ?>, () => this.doDeleteKey(k));
      },
      async doDeleteKey(k) { const j = await this.post('key-delete', { fingerprint: k.fingerprint }); if (j) { window.AidiToast('success', 'Key removed.'); await this.load(); } },

      randPw(n) {
        const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%^*-_';
        const a = new Uint32Array(n); (window.crypto || window.msCrypto).getRandomValues(a);
        let s = ''; for (let i = 0; i < n; i++) s += c[a[i] % c.length]; return s;
      },
      copy(t, ev) {
        if (!t) return;
        // Match the dashboard / top-bar copy pattern: flip the clicked button's icon
        // to a check for a moment (no toast).
        const btn = ev && ev.currentTarget ? ev.currentTarget : null;
        const done = () => {
          if (!btn) return;
          const ic = btn.querySelector('svg');
          if (!ic) return;
          const prev = ic.outerHTML;
          ic.outerHTML = window.AidiIcons.copyDone;
          setTimeout(() => { const cur = btn.querySelector('svg'); if (cur) cur.outerHTML = prev; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done).catch(() => {}); return; }
        try { const e = document.createElement('textarea'); e.value = t; document.body.appendChild(e); e.select(); document.execCommand('copy'); document.body.removeChild(e); done(); } catch (_) {}
      },
    };
  };
})();
</script>
<?php endif; ?>
