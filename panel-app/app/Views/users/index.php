<?php $pageTitle = t('admin.users.title'); ?>

<!-- page header -->
<div class="mb-5">
  <a href="/admin" class="text-xs text-zinc-400 hover:text-ink flex items-center gap-1 mb-2">
    <?= icon('arrow-left', 'text-sm') ?> <?= e(t('admin.title')) ?>
  </a>
  <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.users.title')) ?></h1>
  <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.users.desc')) ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5" x-data="usersPage()">

  <!-- Add user form -->
  <div class="card p-5 h-fit">
    <h2 class="font-head font-semibold text-sm text-zinc-900 mb-4"><?= e(t('users.add_title')) ?></h2>
    <form method="POST" action="/users/add" class="space-y-4">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <div>
        <label class="lbl"><?= e(t('users.username')) ?></label>
        <input type="text" name="username" required pattern="[a-zA-Z0-9_]+"
               placeholder="john" class="inp">
      </div>

      <div>
        <label class="lbl"><?= e(t('users.password')) ?></label>
        <input type="password" name="password" required minlength="8"
               placeholder="min 8 characters" class="inp">
      </div>

      <div>
        <label class="lbl"><?= e(t('users.role')) ?></label>
        <select name="role" class="inp">
          <option value="admin"><?= e(t('users.role_admin')) ?></option>
          <option value="viewer"><?= e(t('users.role_viewer')) ?></option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary w-full"><?= e(t('users.create_btn')) ?></button>
    </form>
  </div>

  <!-- Users table -->
  <div class="lg:col-span-2 card overflow-hidden">
    <div class="card-head">
      <h2 class="card-title"><?= icon('users', 'text-zinc-400') ?> <?= e(t('users.list_title')) ?></h2>
    </div>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= e(t('col.user')) ?></th>
          <th><?= e(t('col.role')) ?></th>
          <th><?= e(t('col.last_login')) ?></th>
          <th><?= e(t('col.status')) ?></th>
          <th class="text-right"><?= e(t('col.action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
        <tr>
          <td>
            <div class="flex items-center gap-2.5">
              <div class="w-7 h-7 bg-ink-pale rounded-full flex items-center justify-center shrink-0">
                <span class="text-ink text-[10px] font-bold font-head">
                  <?= e(strtoupper(mb_substr($user['username'], 0, 2))) ?>
                </span>
              </div>
              <span class="text-sm font-medium text-zinc-900"><?= e($user['username']) ?></span>
            </div>
          </td>
          <td>
            <span class="badge <?= $user['role'] === 'admin' ? 'badge-info' : 'badge-muted' ?>">
              <?= e($user['role']) ?>
            </span>
          </td>
          <td class="text-xs text-zinc-400">
            <?= $user['last_login'] ? e(fmt_dt($user['last_login'])) : e(t('users.never_login')) ?>
          </td>
          <td>
            <span class="badge <?= $user['active'] ? 'badge-ok' : 'badge-muted' ?>">
              <?php if ($user['active']): ?>
                <span class="dot bg-emerald-500"></span>
              <?php endif; ?>
              <?= e($user['active'] ? t('users.active') : t('users.inactive')) ?>
            </span>
          </td>
          <td class="text-right">
            <div class="flex items-center justify-end gap-1">
              <button type="button"
                      @click="openPassModal(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>')"
                      class="w-7 h-7 rounded-md hover:bg-zinc-100 flex items-center justify-center text-zinc-400"
                      title="<?= e(t('users.change_pass')) ?>">
                <?= icon('key', 'text-sm') ?>
              </button>

              <?php if ($user['id'] != ($_user['id'] ?? 0)): ?>
              <form method="POST" action="/users/delete"
                    onsubmit="return confirm('<?= e(t('users.delete_confirm', ['username' => $user['username']])) ?>')">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="id" value="<?= e((string)$user['id']) ?>">
                <button type="submit" aria-label="<?= e(t('common.delete')) ?>"
                        class="w-7 h-7 rounded-md hover:bg-red-50 flex items-center justify-center text-zinc-400 hover:text-red-500">
                  <?= icon('trash', 'text-sm') ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Change password modal -->
  <div x-show="showPassModal" x-cloak
       class="fixed inset-0 bg-black/30 flex items-center justify-center z-50"
       @click.self="showPassModal = false">
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-xl p-6 w-80 mx-4">
      <h3 class="font-head font-semibold text-sm text-zinc-900 mb-4">
        <?= e(t('users.change_pass_title')) ?> — <span x-text="targetUsername"></span>
      </h3>
      <form method="POST" action="/users/passwd">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="id" :value="targetId">
        <input type="password" name="password" required minlength="8"
               placeholder="<?= e(t('users.new_pass_ph')) ?>"
               class="inp mb-4">
        <div class="flex gap-2">
          <button type="submit" class="btn btn-primary flex-1"><?= e(t('common.save')) ?></button>
          <button type="button" @click="showPassModal = false" class="btn btn-ghost flex-1"><?= e(t('common.cancel')) ?></button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
function usersPage() {
  return {
    showPassModal: false,
    targetId: null,
    targetUsername: '',
    openPassModal(id, username) {
      this.targetId = id;
      this.targetUsername = username;
      this.showPassModal = true;
    }
  };
}
</script>
