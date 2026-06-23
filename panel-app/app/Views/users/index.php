<?php $pageTitle = t('admin.users.title'); ?>

<div x-data="usersPage()">

  <!-- page header — mirrors the Sites page header (title block + primary action) -->
  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.users.title')) ?></h1>
      <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.users.desc')) ?></p>
    </div>
    <button type="button" @click="openAdd()" class="btn btn-primary"><?= icon('plus', 'text-sm') ?> <?= e(t('users.add_btn')) ?></button>
  </div>

  <!-- Users table — mirrors the Services table geometry (table-layout:fixed +
       identical colgroup) and its kebab actions menu so both admin tables line up. -->
  <div class="card">
    <div class="card-head">
      <h2 class="card-title"><?= icon('users', 'text-zinc-400') ?> <?= e(t('users.list_title')) ?></h2>
      <span class="badge badge-muted"><?= count($users) ?></span>
    </div>
    <table class="tbl" style="table-layout:fixed">
      <colgroup>
        <col style="width:30%"><col style="width:22%"><col style="width:16%"><col style="width:12%"><col style="width:20%">
      </colgroup>
      <thead>
        <tr>
          <th><?= e(t('col.user')) ?></th>
          <th><?= e(t('col.role')) ?></th>
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
            <span class="badge <?= $user['role'] === 'admin' ? 'badge-info' : 'badge-muted' ?>">
              <?= e($user['role']) ?>
            </span>
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
                <button type="button" @click="open = false; openPass(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>')"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">
                  <?= icon('key', 'text-sm text-zinc-400') ?> <?= e(t('users.change_pass')) ?>
                </button>
                <?php if (!$isSelf): ?>
                <form method="POST" action="/users/delete"
                      onsubmit="return confirm('<?= e(t('users.delete_confirm', ['username' => $user['username']])) ?>')">
                  <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                  <input type="hidden" name="id" value="<?= e((string)$user['id']) ?>">
                  <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                    <?= icon('trash', 'text-sm') ?> <?= e(t('common.delete')) ?>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Modal: Add panel user -->
  <div x-show="modal==='add'" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title"><?= icon('user-plus', 'text-zinc-400') ?> <?= e(t('users.add_title')) ?></h3>
        <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
      </div>
      <form method="POST" action="/users/add" class="p-5 space-y-3">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <div>
          <label class="lbl"><?= e(t('users.username')) ?></label>
          <input type="text" name="username" required pattern="[a-zA-Z0-9_]+"
                 autocomplete="off" spellcheck="false" placeholder="john" class="inp w-full">
        </div>
        <div>
          <label class="lbl"><?= e(t('users.password')) ?></label>
          <input type="password" name="password" required minlength="8"
                 autocomplete="new-password" placeholder="min 8 characters" class="inp w-full">
        </div>
        <div>
          <label class="lbl"><?= e(t('users.role')) ?></label>
          <select name="role" class="inp w-full">
            <option value="admin"><?= e(t('users.role_admin')) ?></option>
            <option value="viewer"><?= e(t('users.role_viewer')) ?></option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><?= icon('plus', 'text-sm') ?> <?= e(t('users.create_btn')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Change password -->
  <div x-show="modal==='pass'" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[92%] max-w-md card shadow-2xl">
      <div class="card-head flex items-center justify-between">
        <h3 class="card-title"><?= icon('key', 'text-zinc-400') ?> <?= e(t('users.change_pass_title')) ?> — <span class="text-zinc-500" x-text="targetUsername"></span></h3>
        <button type="button" @click="modal=null" aria-label="<?= e(t('common.dismiss')) ?>" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
      </div>
      <form method="POST" action="/users/passwd" class="p-5 space-y-3">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="id" :value="targetId">
        <div>
          <label class="lbl"><?= e(t('users.password')) ?></label>
          <input type="password" name="password" required minlength="8"
                 autocomplete="new-password" placeholder="<?= e(t('users.new_pass_ph')) ?>" class="inp w-full">
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="modal=null" class="btn btn-ghost"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
function usersPage() {
  return {
    modal: null,
    targetId: null,
    targetUsername: '',
    openAdd() {
      this.modal = 'add';
    },
    openPass(id, username) {
      this.targetId = id;
      this.targetUsername = username;
      this.modal = 'pass';
    },
  };
}
</script>
