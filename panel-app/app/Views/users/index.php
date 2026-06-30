<?php $pageTitle = t('admin.users.title'); ?>
<?php $GLOBALS['__allSitesJson'] = json_encode(array_map(static fn($s) => ['id' => (int)$s['id'], 'domain' => $s['domain']], $allSites ?? []), JSON_HEX_TAG); ?>
<script>window.__allSites = <?= $GLOBALS['__allSitesJson'] ?>;</script>

<div x-data="usersPage()">

  <!-- page header — mirrors the Sites page header (title block + primary action) -->
  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.users.title')) ?></h1>
      <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.users.desc')) ?></p>
    </div>
    <button type="button" @click="openAdd()" class="btn btn-primary"><?= icon('plus', 'text-sm') ?> <?= e(t('users.add_btn')) ?></button>
  </div>

  <!-- Users table — no overflow wrapper: the kebab dropdown must be able to extend
       below a row without being clipped. table-layout:fixed + 100% columns fit the card. -->
  <div class="card">
    <div class="card-head">
      <h2 class="card-title"><?= icon('users', 'text-zinc-400') ?> <?= e(t('users.list_title')) ?></h2>
      <span class="badge badge-muted"><?= count($users) ?></span>
    </div>
    <table class="tbl" style="table-layout:fixed">
      <colgroup>
        <col style="width:17%"><col style="width:11%"><col style="width:20%"><col style="width:17%"><col style="width:12%"><col style="width:9%"><col style="width:14%">
      </colgroup>
      <thead>
        <tr>
          <th><?= e(t('col.user')) ?></th>
          <th><?= e(t('col.role')) ?></th>
          <th><?= e(t('col.email')) ?></th>
          <th><?= e(t('col.site')) ?></th>
          <th><?= e(t('col.last_login')) ?></th>
          <th><?= e(t('col.status')) ?></th>
          <th style="text-align:right"><?= e(t('col.action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): $isSelf = ($user['id'] == ($_user['id'] ?? 0)); ?>
        <tr>
          <td class="px-5 py-3">
            <div class="flex items-center gap-3">
              <span class="w-8 h-8 bg-ink-pale rounded-full flex items-center justify-center shrink-0">
                <span class="text-ink text-[10px] font-bold font-head">
                  <?= e(strtoupper(mb_substr($user['username'], 0, 2))) ?>
                </span>
              </span>
              <span class="font-medium text-zinc-900 truncate"><?= e($user['username']) ?></span>
            </div>
          </td>
          <td class="px-3 py-3">
            <?php $roleClass = ['admin' => 'badge-info', 'manager' => 'badge-warn', 'client' => 'badge-muted'][$user['role']] ?? 'badge-muted'; ?>
            <span class="badge <?= $roleClass ?>"><?= e(t('users.role_' . $user['role'])) ?></span>
          </td>
          <td class="px-3 py-3 text-xs text-zinc-500 truncate"><?= e((string)($user['email'] ?: '—')) ?></td>
          <td class="px-3 py-3 text-xs text-zinc-500 truncate">
            <?php if ($user['role'] === 'client'): ?>
              <?php $ds = $siteMap[(int)$user['id']] ?? []; ?>
              <?= $ds ? e(implode(', ', $ds)) : e(t('users.sites_none')) ?>
            <?php else: ?>
              <span class="text-zinc-400"><?= e(t('users.sites_all')) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-3 py-3 text-xs text-zinc-400">
            <?= $user['last_login'] ? e(fmt_dt($user['last_login'])) : e(t('users.never_login')) ?>
          </td>
          <td class="px-3 py-3">
            <span class="badge <?= $user['active'] ? 'badge-ok' : 'badge-muted' ?>">
              <?php if ($user['active']): ?>
                <span class="dot bg-emerald-500"></span>
              <?php endif; ?>
              <?= e($user['active'] ? t('users.active') : t('users.inactive')) ?>
            </span>
          </td>
          <td class="px-5 py-3">
            <div class="relative flex justify-end" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
              <button type="button" @click="open = !open" :aria-expanded="open" aria-label="<?= e(t('col.action')) ?>" class="btn btn-ghost btn-sm px-2">
                <?= icon('dots-vertical', 'text-base') ?>
              </button>
              <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 top-full mt-1 z-30 min-w-[10rem] card shadow-xl py-1">
                <button type="button" @click="open = false; openEdit(<?= e(json_encode([
                    'id' => (int)$user['id'], 'username' => $user['username'],
                    'email' => (string)$user['email'], 'first_name' => (string)$user['first_name'],
                    'last_name' => (string)$user['last_name'], 'role' => $user['role'],
                    'active' => (int)$user['active'], 'timezone' => (string)$user['timezone'],
                    'sites' => $siteMap[(int)$user['id']] ?? [],
                ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 whitespace-nowrap">
                  <?= icon('pencil', 'text-sm text-zinc-400') ?> <?= e(t('users.edit_btn')) ?>
                </button>
                <button type="button" @click="open = false; openPass(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>')"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 whitespace-nowrap">
                  <?= icon('key', 'text-sm text-zinc-400') ?> <?= e(t('users.change_pass')) ?>
                </button>
                <?php if (!$isSelf): ?>
                <button type="button" @click="open = false; askDelete(<?= (int)$user['id'] ?>, <?= e(json_encode(t('users.delete_confirm', ['username' => $user['username']]), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-600 hover:bg-red-50 whitespace-nowrap">
                  <?= icon('trash', 'text-sm') ?> <?= e(t('common.delete')) ?>
                </button>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Hidden native form the confirm modal submits to delete a user. -->
  <form id="usr-del-form" method="POST" action="/admin/users/delete" class="hidden">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
    <input type="hidden" name="id" :value="del.id">
  </form>

  <!-- Modal: Add / Edit panel user (shared). Native POST — the controller redirects
       (success/error), not JSON, matching the Change Password modal pattern. Alpine
       drives only the dynamic bits (action URL, pre-fill, the client-only site list,
       and the password-required toggle). -->
  <div x-show="modal==='edit'" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-lg card shadow-2xl" style="max-height:88vh; overflow-y:auto">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title"><?= icon('user-plus', 'text-zinc-400') ?>
          <span x-text="editingId ? <?= e(json_encode(t('users.edit_title'))) ?> : <?= e(json_encode(t('users.add_title'))) ?>"></span>
        </h3>
        <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
      </div>
      <form method="POST" :action="editingId ? '/admin/users/edit' : '/admin/users/add'" class="p-5 space-y-3">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="id" :value="editingId || ''">

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="lbl"><?= e(t('users.username')) ?> <span class="text-rose-500">*</span></label>
            <input x-model="form.username" name="username" type="text" required pattern="[a-zA-Z0-9_]+"
                   autocomplete="off" spellcheck="false" placeholder="john"
                   :disabled="editingId ? true : false"
                   class="inp w-full disabled:opacity-60">
          </div>
          <div>
            <label class="lbl"><?= e(t('users.email')) ?> <span class="text-rose-500">*</span></label>
            <input x-model="form.email" name="email" type="email" required placeholder="john@example.com" class="inp w-full">
          </div>
          <div>
            <label class="lbl"><?= e(t('users.first_name')) ?> <span class="text-rose-500">*</span></label>
            <input x-model="form.first_name" name="first_name" type="text" required class="inp w-full">
          </div>
          <div>
            <label class="lbl"><?= e(t('users.last_name')) ?></label>
            <input x-model="form.last_name" name="last_name" type="text" class="inp w-full">
          </div>
          <div>
            <label class="lbl"><?= e(t('users.password')) ?> <span x-show="!editingId" class="text-rose-500">*</span></label>
            <div class="relative">
              <input x-model="form.password" name="password" :type="showPw ? 'text' : 'password'" minlength="8" autocomplete="new-password"
                     :required="editingId ? false : true"
                     :placeholder="editingId ? <?= e(json_encode(t('users.password_edit_hint'))) ?> : 'min 8 characters'"
                     class="inp w-full pr-10" style="padding-right:2.5rem">
              <button type="button" @click="showPw = !showPw" tabindex="-1"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700"
                      :title="showPw ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>">
                <span x-show="!showPw"><?= icon('eye', 'text-base') ?></span>
                <span x-show="showPw" x-cloak><?= icon('eye-off', 'text-base') ?></span>
              </button>
            </div>
          </div>
          <div>
            <label class="lbl"><?= e(t('users.role')) ?> <span class="text-rose-500">*</span></label>
            <select x-model="form.role" name="role" class="inp w-full">
              <option value="admin"><?= e(t('users.role_admin')) ?></option>
              <option value="manager"><?= e(t('users.role_manager')) ?></option>
              <option value="client"><?= e(t('users.role_client')) ?></option>
            </select>
          </div>
          <div>
            <label class="lbl"><?= e(t('users.status')) ?> <span class="text-rose-500">*</span></label>
            <select x-model="form.active" name="active" class="inp w-full">
              <option value="1"><?= e(t('users.status_active')) ?></option>
              <option value="0"><?= e(t('users.status_inactive')) ?></option>
            </select>
          </div>
          <div>
            <label class="lbl"><?= e(t('users.timezone')) ?> <span class="text-rose-500">*</span></label>
            <select x-model="form.timezone" name="timezone" class="inp w-full">
              <?php foreach ($tzGroups as $continent => $zones): ?>
                <?php if ($continent === ''): ?>
                  <?php foreach ($zones as $z): ?>
                    <option value="<?= e($z['value']) ?>"><?= e($z['label']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <optgroup label="<?= e($continent) ?>">
                    <?php foreach ($zones as $z): ?>
                      <option value="<?= e($z['value']) ?>"><?= e($z['label']) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Site assignment — clients only. Viewport-relative max height so a long
             site list scrolls inside instead of overflowing the modal. -->
        <div x-show="form.role === 'client'" x-cloak class="pt-1">
          <label class="lbl"><?= e(t('users.sites')) ?></label>
          <div class="border border-zinc-200 rounded-lg p-3 space-y-1.5" style="max-height:240px; overflow-y:auto">
            <?php if (!empty($allSites)): foreach ($allSites as $s): ?>
              <label class="flex items-center gap-2.5 text-sm text-zinc-700">
                <input type="checkbox" name="sites[]" value="<?= (int)$s['id'] ?>"
                       :checked="form.sites.includes(<?= (int)$s['id'] ?>)"
                       @change="toggleSite(<?= (int)$s['id'] ?>)"
                       class="rounded border-zinc-300">
                <span class="mono text-[13px]"><?= e($s['domain']) ?></span>
              </label>
            <?php endforeach; else: ?>
              <p class="text-xs text-zinc-400"><?= e(t('users.sites_none')) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('users.save')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Change password (from the kebab action). Single-line title; show/hide eye. -->
  <div x-show="modal==='pass'" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-lg card shadow-2xl">
      <div class="card-head flex items-center justify-between gap-3">
        <h3 class="card-title flex items-center gap-2 min-w-0">
          <?= icon('key', 'text-zinc-400 shrink-0') ?>
          <span class="truncate"><?= e(t('users.change_pass_title')) ?> — <span class="text-zinc-400 font-normal" x-text="targetUsername"></span></span>
        </h3>
        <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700 shrink-0"><?= icon('x') ?></button>
      </div>
      <form method="POST" action="/admin/users/passwd" class="p-5 space-y-3">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="id" :value="targetId">
        <div>
          <label class="lbl"><?= e(t('users.password')) ?> <span class="text-rose-500">*</span></label>
          <div class="relative">
            <input name="password" required minlength="8" autocomplete="new-password"
                   :type="showPass ? 'text' : 'password'"
                   placeholder="<?= e(t('users.new_pass_ph')) ?>" class="inp w-full pr-10" style="padding-right:2.5rem">
            <button type="button" @click="showPass = !showPass" tabindex="-1"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700"
                    :title="showPass ? <?= e(json_encode(t('users.hide'))) ?> : <?= e(json_encode(t('users.show'))) ?>">
              <span x-show="!showPass"><?= icon('eye', 'text-base') ?></span>
              <span x-show="showPass" x-cloak><?= icon('eye-off', 'text-base') ?></span>
            </button>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: confirm (teleported) — used by Delete so the OK/Cancel matches the panel,
       not the browser's native confirm() dialog. -->
  <template x-teleport="body">
  <div x-show="confirm.open" x-cloak class="fixed inset-0 z-[60]">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title text-red-600"><?= icon('alert-triangle', 'text-amber-500') ?> <span x-text="confirm.title"></span></h3>
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
function usersPage() {
  return {
    modal: null,
    editingId: null,
    targetId: null,
    targetUsername: '',
    showPw: false,
    showPass: false,
    del: { id: 0 },
    confirm: { open: false, title: '', message: '', label: '', fn: null },
    form: { username:'', email:'', first_name:'', last_name:'', password:'', role:'client', active:'1', timezone:'UTC', sites: [] },

    openAdd() {
      this.editingId = null;
      this.showPw = false;
      this.form = { username:'', email:'', first_name:'', last_name:'', password:'', role:'client', active:'1', timezone:'UTC', sites: [] };
      this.modal = 'edit';
    },
    openEdit(u) {
      this.editingId = u.id;
      this.showPw = false;
      // Resolve assigned domain names to site IDs (the checkboxes key on id).
      var byDom = {};
      (window.__allSites || []).forEach(function (s) { byDom[s.domain] = s.id; });
      var siteIds = (u.sites || []).map(function (d) { return byDom[d]; }).filter(function (i) { return i; });
      this.form = {
        username: u.username || '', email: u.email || '',
        first_name: u.first_name || '', last_name: u.last_name || '',
        password: '',
        role: u.role || 'client',
        active: String(u.active ?? 1),
        timezone: u.timezone || 'UTC',
        sites: siteIds,
      };
      this.modal = 'edit';
    },
    toggleSite(id) {
      var i = this.form.sites.indexOf(id);
      if (i >= 0) { this.form.sites.splice(i, 1); } else { this.form.sites.push(id); }
    },
    openPass(id, username) { this.targetId = id; this.targetUsername = username; this.showPass = false; this.modal = 'pass'; },

    askConfirm(title, message, label, fn) { this.confirm = { open: true, title: title, message: message, label: label, fn: fn }; },
    runConfirm() { var f = this.confirm.fn; this.confirm.open = false; if (f) f(); },
    askDelete(id, message) {
      this.del = { id: id };
      this.askConfirm(<?= json_encode(t('users.delete_title'), JSON_HEX_TAG) ?>, message, <?= json_encode(t('common.delete'), JSON_HEX_TAG) ?>, () => this.doDelete());
    },
    doDelete() { var f = document.getElementById('usr-del-form'); if (f) f.submit(); },
  };
}
</script>
