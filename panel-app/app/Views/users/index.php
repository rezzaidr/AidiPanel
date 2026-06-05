<?php $pageTitle = 'Users'; ?>

<div class="grid grid-cols-3 gap-4">

  <!-- Add User form -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
    <h3 class="text-sm font-semibold text-gray-900 mb-4">Add Panel User</h3>

    <form method="POST" action="/users/add">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">

      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Username</label>
        <input type="text" name="username" required placeholder="john"
          pattern="[a-zA-Z0-9_]+"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
      </div>

      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Password</label>
        <input type="password" name="password" required minlength="8" placeholder="min 8 characters"
          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
      </div>

      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1.5">Role</label>
        <select name="role" class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="admin">Admin (full access)</option>
          <option value="viewer">Viewer (read-only)</option>
        </select>
      </div>

      <button type="submit"
        class="w-full bg-brand hover:bg-brand-light text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
        Create User
      </button>
    </form>
  </div>

  <!-- User list -->
  <div class="col-span-2 bg-white rounded-xl border border-gray-200">
    <div class="px-5 py-3.5 border-b border-gray-100">
      <span class="text-sm font-semibold text-gray-800">Panel Users</span>
    </div>

    <table class="w-full">
      <thead>
        <tr class="bg-gray-50 border-b border-gray-100">
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">User</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Role</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Last Login</th>
          <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wider px-5 py-2.5">Status</th>
          <th class="px-5 py-2.5"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50" x-data="usersTable()">
        <?php foreach ($users as $user): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-5 py-3">
            <div class="flex items-center gap-2.5">
              <div class="w-7 h-7 bg-brand-pale rounded-full flex items-center justify-center">
                <i class="ti ti-user text-brand text-xs"></i>
              </div>
              <span class="text-sm font-medium text-gray-900"><?= e($user['username']) ?></span>
            </div>
          </td>
          <td class="px-5 py-3">
            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full
              <?= $user['role'] === 'admin' ? 'bg-brand-pale text-brand' : 'bg-gray-100 text-gray-500' ?>">
              <?= e($user['role']) ?>
            </span>
          </td>
          <td class="px-5 py-3 text-xs text-gray-500">
            <?= $user['last_login'] ? e($user['last_login']) : 'Never' ?>
          </td>
          <td class="px-5 py-3">
            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full
              <?= $user['active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>">
              <?= $user['active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-2">
              <!-- Change password -->
              <button type="button"
                @click="openPassModal(<?= e((string)$user['id']) ?>, '<?= e($user['username']) ?>')"
                class="text-xs text-gray-400 hover:text-brand transition-colors">
                <i class="ti ti-key text-sm"></i>
              </button>

              <!-- Delete -->
              <?php if ($user['id'] != ($_user['id'] ?? 0)): ?>
              <form method="POST" action="/users/delete"
                    onsubmit="return confirm('Delete user <?= e($user['username']) ?>?')">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
                <input type="hidden" name="id" value="<?= e((string)$user['id']) ?>">
                <button type="submit" class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                  <i class="ti ti-trash text-sm"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Change password modal -->
    <div x-data="usersTable()" x-cloak>
      <div x-show="showPassModal"
           class="fixed inset-0 bg-black/30 flex items-center justify-center z-50"
           @click.self="showPassModal = false">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xl p-6 w-80">
          <h3 class="text-sm font-semibold text-gray-900 mb-4">Change Password — <span x-text="targetUsername"></span></h3>
          <form method="POST" action="/users/passwd">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <input type="hidden" name="id" :value="targetId">
            <input type="password" name="password" required minlength="8" placeholder="New password (min 8 chars)"
              class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg mb-3 focus:outline-none focus:ring-2 focus:ring-brand">
            <div class="flex gap-2">
              <button type="submit" class="flex-1 bg-brand text-white text-sm py-2.5 rounded-lg hover:bg-brand-light">Save</button>
              <button type="button" @click="showPassModal = false" class="flex-1 border border-gray-200 text-sm py-2.5 rounded-lg hover:bg-gray-50">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function usersTable() {
  return {
    showPassModal: false,
    targetId: null,
    targetUsername: '',
    openPassModal(id, username) {
      this.targetId = id;
      this.targetUsername = username;
      this.showPassModal = true;
    }
  }
}
</script>
