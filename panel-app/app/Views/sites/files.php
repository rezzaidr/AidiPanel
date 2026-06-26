<?php
/**
 * Manage Site → Files (v1A + UI revisions). Right-click-driven file manager,
 * jailed per site. Required from detail.php, so $site / $domain are in scope.
 * Notifications use the panel toast (window.AidiToast); requests use window.api
 * (CSRF baked in). The editor lazy-loads a vendored CodeMirror (no CDN).
 */
$fmReady = !empty($site['site_user']);
$cmVer   = @filemtime(PUBLIC_ROOT . '/assets/vendor/codemirror.js') ?: PANEL_VERSION;  // cache-bust the editor assets
?>
<?php if (!$fmReady): ?>
  <div class="card px-5 py-12 text-center text-sm text-zinc-500"><?= e(t('site.files.not_ready')) ?></div>
<?php else: ?>
<style>
  .fm-cm-host .CodeMirror { height: 100%; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 13px; }
  .fm-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
  .fm-scroll::-webkit-scrollbar-thumb { background: #d4d4d8; border-radius: 6px; }
</style>
<div class="card overflow-hidden relative" x-data="fileManager('<?= e($domain) ?>')" x-init="init()" @keydown.window="onKey($event)">

  <!-- upload progress overlay (modal-style: same dark backdrop as the other modals) -->
  <div x-show="uploading" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-zinc-900/40"></div>
    <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm p-4">
      <p class="text-sm text-zinc-700 mb-1 flex items-center gap-2"><?= icon('upload', 'text-sm text-indigo-500') ?> <span x-text="up.count > 1 ? `Uploading (${up.idx}/${up.count})…` : 'Uploading…'"></span><span class="ml-auto mono text-xs shrink-0" x-text="uploadPct + '%'"></span></p>
      <p class="text-xs text-zinc-400 mb-2 truncate mono" x-show="up.name" x-text="up.name"></p>
      <div class="h-2 bg-zinc-100 rounded-full overflow-hidden mb-3"><div class="h-full bg-indigo-500 transition-all duration-150" :style="`width:${uploadPct}%`"></div></div>
      <div class="flex justify-end"><button type="button" class="btn btn-secondary btn-sm" @click="cancelUpload()"><?= icon('x', 'text-sm') ?> Cancel</button></div>
    </div>
  </div>

  <!-- top bar: sidebar toggle + breadcrumb + refresh + add new -->
  <div class="flex items-center justify-between gap-3 px-3 py-2 border-b border-zinc-200">
    <div class="flex items-center gap-1.5 min-w-0">
      <button type="button" class="w-7 h-7 rounded-md hover:bg-zinc-100 text-zinc-500 flex items-center justify-center shrink-0"
              @click="sidebarOpen=!sidebarOpen" :class="sidebarOpen && 'text-ink bg-zinc-100'" title="Toggle tree"><?= icon('layout-sidebar', 'text-sm') ?></button>
      <button type="button" class="w-7 h-7 rounded-md hover:bg-zinc-100 text-zinc-500 flex items-center justify-center shrink-0 disabled:opacity-40 disabled:cursor-not-allowed"
              @click="goUp()" :disabled="!cwd" title="<?= e(t('site.files.up')) ?>"><?= icon('arrow-up', 'text-sm') ?></button>
      <nav class="flex items-center gap-1 text-sm text-zinc-500 min-w-0 overflow-x-auto whitespace-nowrap fm-scroll">
        <button type="button" class="hover:text-ink shrink-0 flex items-center" @click="load('')" aria-label="root"><?= icon('home', 'text-sm') ?></button>
        <template x-for="(c, i) in breadcrumb" :key="i">
          <span class="flex items-center gap-1 shrink-0">
            <span class="text-zinc-300">/</span>
            <button type="button" class="hover:text-ink" @click="load(c.path)" x-text="c.name"></button>
          </span>
        </template>
      </nav>
    </div>
    <div class="flex items-center gap-1.5 shrink-0">
      <div class="flex items-center rounded-md border border-zinc-200 overflow-hidden">
        <button type="button" @click="setView('list')" :class="view==='list' ? 'bg-zinc-100 text-ink' : 'text-zinc-400 hover:text-zinc-600'" class="w-7 h-7 flex items-center justify-center" title="List view"><?= icon('list', 'text-sm') ?></button>
        <button type="button" @click="setView('grid')" :class="view==='grid' ? 'bg-zinc-100 text-ink' : 'text-zinc-400 hover:text-zinc-600'" class="w-7 h-7 flex items-center justify-center" title="Icon view"><?= icon('layout-grid', 'text-sm') ?></button>
      </div>
      <button type="button" class="w-7 h-7 rounded-md hover:bg-zinc-100 text-zinc-500 flex items-center justify-center"
              :class="loading && 'animate-spin'" @click="load(cwd)" title="<?= e(t('site.files.refresh')) ?>"><?= icon('refresh', 'text-sm') ?></button>
      <div class="relative" x-data="{ m:false }" @click.outside="m=false">
        <button type="button" class="btn btn-primary btn-sm" @click="m=!m"><?= icon('plus', 'text-sm') ?> <?= e(t('site.files.add_new')) ?> <?= icon('chevron-down', 'text-xs') ?></button>
        <div x-show="m" x-cloak x-transition.opacity class="absolute right-0 mt-1 w-44 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-30 text-left">
          <button type="button" class="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-50" @click="m=false; askNewFile()"><?= icon('file-plus', 'text-sm text-zinc-400') ?> <?= e(t('site.files.new_file')) ?></button>
          <button type="button" class="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-50" @click="m=false; askNewFolder()"><?= icon('folder-plus', 'text-sm text-zinc-400') ?> <?= e(t('site.files.new_folder')) ?></button>
          <button type="button" class="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-50" @click="m=false; $refs.upl.click()"><?= icon('upload', 'text-sm text-zinc-400') ?> <?= e(t('site.files.upload')) ?></button>
        </div>
      </div>
      <input type="file" multiple class="hidden" x-ref="upl" @change="upload($event.target.files); $event.target.value=''">
    </div>
  </div>

  <!-- body: tree sidebar + listing -->
  <div class="flex">
    <aside x-show="sidebarOpen" @contextmenu.prevent="openMenu($event, null)" class="w-56 shrink-0 border-r border-zinc-200 overflow-auto fm-scroll bg-zinc-50/40 h-[calc(100vh_-_300px)] min-h-[520px]">
      <div class="py-1.5 text-[13px]">
        <template x-for="node in flatTree" :key="node.path">
          <div class="flex items-center gap-1 pr-2 py-1 cursor-pointer hover:bg-zinc-100/70"
               :class="node.path===cwd && 'bg-indigo-50 text-ink font-medium'"
               :style="`padding-left:${node.depth*14 + 8}px`" @click="treeClick(node)" @contextmenu.prevent.stop="treeClick(node); openMenu($event, null)">
            <button type="button" class="w-4 h-4 shrink-0 flex items-center justify-center text-zinc-400 hover:text-zinc-700"
                    @click.stop="toggleNode(node)">
              <span x-show="node.hasChildren" x-html="node.open ? CHEV_DOWN : CHEV_RIGHT"></span>
            </button>
            <span class="shrink-0 text-amber-500" x-html="FILE_ICONS.folder"></span>
            <span class="truncate" x-text="node.name"></span>
          </div>
        </template>
        <p x-show="!tree.length" class="px-3 py-2 text-xs text-zinc-400">No subfolders.</p>
      </div>
    </aside>

    <div class="flex-1 min-w-0 flex flex-col h-[calc(100vh_-_300px)] min-h-[520px]">
      <div class="flex-1 overflow-auto fm-scroll select-none relative" @contextmenu.prevent="openMenu($event, null)"
           @dragover.prevent="dragging = true"
           @dragleave.prevent="if (!$event.currentTarget.contains($event.relatedTarget)) dragging = false"
           @drop.prevent="dragging = false; upload($event.dataTransfer.files)">
        <div x-show="dragging" x-cloak class="absolute inset-0 z-30 m-2 rounded-xl border-2 border-dashed border-indigo-400 bg-indigo-50/80 flex items-center justify-center pointer-events-none">
          <p class="text-sm font-medium text-indigo-600 flex items-center gap-2"><?= icon('upload', 'text-indigo-500') ?> <?= e(t('site.files.drop_hint')) ?></p>
        </div>
        <table x-show="view==='list'" class="w-full text-sm border-collapse">
          <thead class="sticky top-0 z-10 bg-zinc-50">
            <tr class="text-[10px] uppercase tracking-wide text-zinc-500 border-b border-zinc-200">
              <th class="text-left font-semibold pl-4 pr-2 py-2"><?= e(t('site.files.col_name')) ?></th>
              <th class="text-left font-semibold px-2 py-2 w-20"><?= e(t('site.files.col_size')) ?></th>
              <th class="text-left font-semibold px-2 py-2 w-36"><?= e(t('site.files.col_modified')) ?></th>
              <th class="text-left font-semibold px-2 py-2 w-24"><?= e(t('site.files.col_owner')) ?></th>
              <th class="text-left font-semibold px-2 py-2 w-16"><?= e(t('site.files.col_perms')) ?></th>
            </tr>
          </thead>
          <tbody>
            <template x-for="en in entries" :key="en.name">
              <tr class="border-b border-zinc-200/70 cursor-default"
                  :class="selected.has(en.name) ? 'bg-indigo-100' : 'hover:bg-zinc-50'"
                  @click="select(en, $event)" @dblclick="openEntry(en)"
                  @contextmenu.prevent.stop="rowMenu(en, $event)">
                <td class="pl-4 pr-2 py-1.5">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="shrink-0" :class="iconColor(en)" x-html="iconFor(en)"></span>
                    <span class="truncate" :class="en.blocked && 'text-zinc-400 italic'" x-text="en.name"></span>
                    <span x-show="en.type==='symlink'" class="text-[10px] text-zinc-400 shrink-0" title="symlink">↳</span>
                  </div>
                </td>
                <td class="px-2 py-1.5 text-zinc-500 text-xs mono whitespace-nowrap" x-text="en.type==='dir' ? '—' : human(en.size)"></td>
                <td class="px-2 py-1.5 text-zinc-500 text-xs whitespace-nowrap" x-text="fmtDate(en.mtime)"></td>
                <td class="px-2 py-1.5 text-zinc-500 text-xs whitespace-nowrap" x-text="en.owner"></td>
                <td class="px-2 py-1.5 text-zinc-400 text-xs mono whitespace-nowrap" x-text="en.perms_octal"></td>
              </tr>
            </template>
          </tbody>
        </table>
        <div x-show="view==='grid'" class="grid gap-3 p-3" style="grid-template-columns:repeat(auto-fill,minmax(92px,1fr))">
          <template x-for="en in entries" :key="en.name">
            <div class="flex flex-col items-center gap-1.5 p-2 rounded-lg cursor-default text-center"
                 :class="selected.has(en.name) ? 'bg-indigo-100 ring-1 ring-indigo-300' : 'hover:bg-zinc-100'"
                 @click="select(en, $event)" @dblclick="openEntry(en)" @contextmenu.prevent.stop="rowMenu(en, $event)">
              <span class="text-4xl" :class="iconColor(en)" x-html="iconFor(en)"></span>
              <span class="text-xs text-zinc-700 break-all line-clamp-2 leading-tight w-full" :class="en.blocked && 'text-zinc-400 italic'" x-text="en.name"></span>
            </div>
          </template>
        </div>
        <div x-show="!entries.length && !loading" class="flex flex-col items-center justify-center gap-2 py-20 text-sm text-zinc-400 select-none">
          <span class="text-zinc-300 text-4xl" x-html="FILE_ICONS.folder"></span>
          <p><?= e(t('site.files.empty_hint')) ?></p>
        </div>
        <div x-show="loading" class="py-20 text-center text-sm text-zinc-400"><?= e(t('site.files.loading')) ?></div>
      </div>
    </div>
  </div>

  <!-- context menu -->
  <div x-show="menu.open" x-cloak @click.outside="menu.open=false" @contextmenu.outside="menu.open=false"
       class="fixed w-52 bg-white border border-zinc-200 rounded-lg shadow-lg py-1 z-50 text-sm" :style="`left:${menu.x}px; top:${menu.y}px`">
    <template x-if="menu.target && menu.target.type==='file' && !menu.target.blocked && selected.size<=1">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="openEntry(menu.target); menu.open=false"><?= icon('edit', 'text-sm text-zinc-400') ?> <?= e(t('site.files.edit')) ?></button>
    </template>
    <template x-if="menu.target && menu.target.type==='dir' && selected.size<=1">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="openEntry(menu.target)"><?= icon('folder', 'text-sm text-zinc-400') ?> <?= e(t('site.files.open')) ?></button>
    </template>
    <template x-if="menu.target && !menu.target.blocked">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="downloadTargets()"><?= icon('download', 'text-sm text-zinc-400') ?> <?= e(t('site.files.download')) ?></button>
    </template>
    <template x-if="menu.target && menu.target.type==='file' && /\.zip$/i.test(menu.target.name) && !menu.target.blocked">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="extract(menu.target)"><?= icon('archive', 'text-sm text-zinc-400') ?> <?= e(t('site.files.extract')) ?></button>
    </template>
    <template x-if="menu.target && selected.size<=1">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="askRename(menu.target)"><?= icon('pencil', 'text-sm text-zinc-400') ?> <?= e(t('site.files.rename')) ?><span class="ml-auto text-[10px] text-zinc-400">F2</span></button>
    </template>
    <template x-if="menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="cut()"><?= icon('scissors', 'text-sm text-zinc-400') ?> <?= e(t('site.files.cut')) ?><span class="ml-auto text-[10px] text-zinc-400">Ctrl+X</span></button>
    </template>
    <template x-if="menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="copy()"><?= icon('copy', 'text-sm text-zinc-400') ?> <?= e(t('site.files.copy')) ?><span class="ml-auto text-[10px] text-zinc-400">Ctrl+C</span></button>
    </template>
    <template x-if="clipboard.paths.length">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="paste(menu.target && menu.target.type==='dir' ? join(menu.target.name) : null)"><?= icon('clipboard', 'text-sm text-zinc-400') ?> <?= e(t('site.files.paste')) ?> <span class="text-[10px] text-zinc-400" x-text="'('+clipboard.paths.length+')'"></span><span class="ml-auto text-[10px] text-zinc-400">Ctrl+V</span></button>
    </template>
    <template x-if="menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="askChmod()"><?= icon('lock', 'text-sm text-zinc-400') ?> <?= e(t('site.files.permissions')) ?></button>
    </template>
    <template x-if="menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="askZip()"><?= icon('file-zip', 'text-sm text-zinc-400') ?> <?= e(t('site.files.compress')) ?></button>
    </template>
    <template x-if="!menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="menu.open=false; askNewFile()"><?= icon('file-plus', 'text-sm text-zinc-400') ?> <?= e(t('site.files.new_file')) ?></button>
    </template>
    <template x-if="!menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="menu.open=false; askNewFolder()"><?= icon('folder-plus', 'text-sm text-zinc-400') ?> <?= e(t('site.files.new_folder')) ?></button>
    </template>
    <template x-if="!menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-zinc-50 text-left" @click="menu.open=false; $refs.upl.click()"><?= icon('upload', 'text-sm text-zinc-400') ?> <?= e(t('site.files.upload')) ?></button>
    </template>
    <template x-if="menu.target">
      <button type="button" class="w-full flex items-center gap-2 px-3 py-2 text-red-600 hover:bg-red-50 text-left border-t border-zinc-100 mt-1 pt-2" @click="menu.open=false; askDelete()"><?= icon('trash', 'text-sm') ?> <?= e(t('site.files.delete')) ?><span class="ml-auto text-[10px] text-red-400">Del</span></button>
    </template>
  </div>

  <!-- modal: new file / new folder -->
  <template x-if="modal==='newFile' || modal==='newFolder'">
    <div x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200">
          <h3 class="card-title">
            <span x-show="modal==='newFile'"><?= icon('file-plus', 'text-zinc-400') ?></span>
            <span x-show="modal==='newFolder'"><?= icon('folder-plus', 'text-zinc-400') ?></span>
            <span x-text="modal==='newFile' ? <?= json_encode(t('site.files.new_file'), JSON_HEX_TAG) ?> : <?= json_encode(t('site.files.new_folder'), JSON_HEX_TAG) ?>"></span>
          </h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form @submit.prevent="modal==='newFile' ? doNewFile() : doNewFolder()" class="p-4 space-y-3">
          <input x-ref="modalInput" x-model="form.name" type="text"
                 :placeholder="modal==='newFile' ? 'filename.php' : 'folder-name'"
                 class="inp w-full">
          <div class="flex justify-end gap-2">
            <button type="button" @click="modal=null" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Create</button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <!-- modal: rename -->
  <template x-if="modal==='rename'">
    <div x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200">
          <h3 class="card-title"><?= icon('pencil', 'text-zinc-400') ?> <?= e(t('site.files.rename')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form @submit.prevent="doRename()" class="p-4 space-y-3">
          <input x-ref="modalInput" x-model="form.name" type="text" class="inp w-full">
          <div class="flex justify-end gap-2">
            <button type="button" @click="modal=null" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm"><?= e(t('site.files.rename')) ?></button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <!-- modal: permissions (chmod) -->
  <template x-if="modal==='chmod'">
    <div x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200">
          <h3 class="card-title"><?= icon('lock', 'text-zinc-400') ?> <?= e(t('site.files.permissions')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form @submit.prevent="doChmod()" class="p-4 space-y-3">
          <select x-model="form.mode" class="inp w-full mono">
            <option value="0644">0644 — file (owner rw, others r)</option>
            <option value="0664">0664 — file (group writable)</option>
            <option value="0600">0600 — file (private)</option>
            <option value="0755">0755 — folder / executable</option>
            <option value="0775">0775 — folder (group writable)</option>
            <option value="0700">0700 — folder (private)</option>
          </select>
          <label class="flex items-center gap-2 text-sm text-zinc-600">
            <input type="checkbox" x-model="form.recursive" class="rounded border-zinc-300"> Apply recursively to all contents
          </label>
          <div class="flex justify-end gap-2">
            <button type="button" @click="modal=null" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <!-- modal: compress (zip) -->
  <template x-if="modal==='zip'">
    <div x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200">
          <h3 class="card-title"><?= icon('file-zip', 'text-zinc-400') ?> <?= e(t('site.files.compress')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <form @submit.prevent="doZip()" class="p-4 space-y-3">
          <input x-ref="modalInput" x-model="form.name" type="text" placeholder="archive.zip" class="inp w-full">
          <p class="text-xs text-zinc-400" x-text="zipPaths.length + ' item(s) selected'"></p>
          <div class="flex justify-end gap-2">
            <button type="button" @click="modal=null" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm"><?= e(t('site.files.compress')) ?></button>
          </div>
        </form>
      </div>
    </div>
  </template>

  <!-- modal: delete confirm -->
  <template x-if="modal==='delete'">
    <div x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-zinc-900/40" @click="modal=null"></div>
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200">
          <h3 class="card-title text-red-600"><?= icon('trash', 'text-red-500') ?> <?= e(t('site.files.delete')) ?></h3>
          <button type="button" @click="modal=null" class="text-zinc-400 hover:text-zinc-700"><?= icon('x') ?></button>
        </div>
        <div class="p-4">
          <p class="text-sm text-zinc-600"><?= e(t('site.files.confirm_delete')) ?></p>
          <ul class="mt-2 max-h-28 overflow-auto fm-scroll text-xs text-zinc-500 mono space-y-0.5">
            <template x-for="n in del.names" :key="n"><li class="truncate" x-text="n"></li></template>
          </ul>
          <div class="flex justify-end gap-2 mt-4">
            <button type="button" @click="modal=null" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="button" @click="doDelete()" class="btn btn-danger btn-sm"><?= icon('trash', 'text-sm') ?> <?= e(t('site.files.delete')) ?></button>
          </div>
        </div>
      </div>
    </div>
  </template>

  <!-- editor modal (CodeMirror) -->
  <div x-show="editor.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-zinc-900/50" @click="tryClose()"></div>
    <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl flex flex-col h-[80vh]">
      <div class="flex items-center justify-between gap-4 px-4 py-3 border-b border-zinc-200">
        <div class="flex items-center gap-2 min-w-0">
          <h3 class="font-head font-semibold text-sm text-zinc-800 mono truncate" x-text="editor.path"></h3>
          <span class="badge badge-muted text-[10px] uppercase shrink-0" x-show="editor.modeName" x-text="editor.modeName"></span>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <template x-if="!editor.confirmClose">
            <div class="flex items-center gap-3">
              <span x-show="editor.saving" class="text-xs text-zinc-400">Saving…</span>
              <button type="button" class="btn btn-primary btn-sm" @click="saveEditor()" :disabled="editor.saving"><?= icon('device-floppy', 'text-sm') ?> <?= e(t('site.files.save')) ?></button>
              <button type="button" class="btn btn-secondary btn-sm" @click="tryClose()"><?= icon('x', 'text-sm') ?> Close</button>
            </div>
          </template>
          <template x-if="editor.confirmClose">
            <div class="flex items-center gap-2">
              <span class="text-xs text-amber-600">Discard changes?</span>
              <button type="button" class="btn btn-danger btn-sm" @click="forceClose()">Discard</button>
              <button type="button" class="btn btn-secondary btn-sm" @click="editor.confirmClose=false">Keep editing</button>
            </div>
          </template>
        </div>
      </div>
      <div class="flex-1 min-h-0 overflow-hidden fm-cm-host" x-ref="cmHost"></div>
    </div>
  </div>
</div>

<script>
(function () {
  const CM_JS  = <?= json_encode('/assets/vendor/codemirror.js?v=' . $cmVer, JSON_HEX_TAG) ?>;
  const CM_CSS = <?= json_encode('/assets/vendor/codemirror.css?v=' . $cmVer, JSON_HEX_TAG) ?>;
  const FILE_ICONS = {
    folder: <?= json_encode(icon('folder'), JSON_HEX_TAG) ?>,
    file:   <?= json_encode(icon('file'), JSON_HEX_TAG) ?>,
    code:   <?= json_encode(icon('file-code'), JSON_HEX_TAG) ?>,
    text:   <?= json_encode(icon('file-text'), JSON_HEX_TAG) ?>,
    cfg:    <?= json_encode(icon('file-settings'), JSON_HEX_TAG) ?>,
    img:    <?= json_encode(icon('photo'), JSON_HEX_TAG) ?>,
    zip:    <?= json_encode(icon('file-zip'), JSON_HEX_TAG) ?>,
    sheet:  <?= json_encode(icon('file-spreadsheet'), JSON_HEX_TAG) ?>,
    db:     <?= json_encode(icon('database'), JSON_HEX_TAG) ?>,
    link:   <?= json_encode(icon('link'), JSON_HEX_TAG) ?>,
  };
  const CHEV_RIGHT = <?= json_encode(icon('chevron-right', 'text-xs'), JSON_HEX_TAG) ?>;
  const CHEV_DOWN  = <?= json_encode(icon('chevron-down', 'text-xs'), JSON_HEX_TAG) ?>;
  const EXT_MAP = {
    php:'code', js:'code', mjs:'code', cjs:'code', ts:'code', jsx:'code', tsx:'code', vue:'code', py:'code', rb:'code', go:'code', rs:'code', java:'code', c:'code', cpp:'code', h:'code', sh:'code', bash:'code', html:'code', htm:'code', css:'code', scss:'code', sass:'code', less:'code',
    json:'cfg', yml:'cfg', yaml:'cfg', xml:'cfg', toml:'cfg', ini:'cfg', conf:'cfg', env:'cfg', htaccess:'cfg', lock:'cfg',
    md:'text', markdown:'text', txt:'text', log:'text', csv:'sheet', tsv:'sheet',
    jpg:'img', jpeg:'img', png:'img', gif:'img', webp:'img', svg:'img', ico:'img', bmp:'img',
    zip:'zip', gz:'zip', tar:'zip', tgz:'zip', rar:'zip', '7z':'zip', bz2:'zip',
    sql:'db',
  };

  window.fileManager = function (domain) {
    const base = '/sites/' + domain + '/files';
    let cm = null;             // CodeMirror instance, kept OUT of Alpine's reactive proxy
    let cmLoading = null;
    let curXhr = null;         // in-flight upload XHR (for cancel); kept out of the reactive proxy
    let cancelled = false;     // set by cancelUpload() to stop the chunk loop

    function ensureCM() {
      if (window.CodeMirror) return Promise.resolve();
      if (cmLoading) return cmLoading;
      cmLoading = new Promise(function (resolve, reject) {
        // Wait for the stylesheet too — creating the editor before its CSS lands
        // is what causes the blank/colourless/overlapping-gutter render.
        const cssReady = new Promise(function (res) {
          if (document.getElementById('cm-css')) return res();
          const l = document.createElement('link');
          l.id = 'cm-css'; l.rel = 'stylesheet'; l.href = CM_CSS;
          l.onload = res; l.onerror = res;
          document.head.appendChild(l);
        });
        const s = document.createElement('script');
        s.src = CM_JS;
        s.onload = function () { cssReady.then(resolve); };
        s.onerror = function () { reject(new Error('CodeMirror failed to load')); };
        document.head.appendChild(s);
      });
      return cmLoading;
    }

    // True only for a mode/MIME that's actually loaded in this bundle.
    function modeRegistered(m) {
      const C = window.CodeMirror;
      return !!(C && ((C.mimeModes && C.mimeModes[m]) || (C.modes && C.modes[m])));
    }
    // Direct extension → registered mode-NAME map. Used before CM's meta lookup so
    // highlighting never depends on findModeByFileName behaving in the browser.
    // Every value here is a mode bundled by build/vendor-assets.mjs.
    const EXT_MODE = {
      php: 'php', phtml: 'php', php3: 'php', php4: 'php', php5: 'php', php7: 'php',
      js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript', ts: 'javascript', tsx: 'javascript', json: 'javascript',
      css: 'css', scss: 'css', sass: 'css', less: 'css',
      html: 'htmlmixed', htm: 'htmlmixed', vue: 'htmlmixed', twig: 'htmlmixed', blade: 'htmlmixed',
      xml: 'xml', svg: 'xml', xsl: 'xml', rss: 'xml',
      yml: 'yaml', yaml: 'yaml', md: 'markdown', markdown: 'markdown', sql: 'sql',
      sh: 'shell', bash: 'shell', zsh: 'shell', py: 'python',
      c: 'clike', h: 'clike', cpp: 'clike', cc: 'clike', hpp: 'clike', java: 'clike', cs: 'clike',
      conf: 'nginx', nginx: 'nginx', ini: 'properties', toml: 'properties', env: 'properties', dockerfile: 'dockerfile',
    };
    function pickMode(name) {
      const lower = name.toLowerCase();
      if (lower === '.env' || lower.startsWith('.env.')) return 'properties';
      const byName = {
        '.profile': 'shell', '.bashrc': 'shell', '.bash_profile': 'shell', '.bash_logout': 'shell',
        '.zshrc': 'shell', '.zprofile': 'shell', '.zshenv': 'shell', '.kshrc': 'shell',
        '.gitconfig': 'properties', '.editorconfig': 'properties', '.npmrc': 'properties', 'dockerfile': 'dockerfile',
      };
      if (byName[lower]) return byName[lower];
      const ext = lower.indexOf('.') >= 0 ? lower.split('.').pop() : '';
      if (EXT_MODE[ext] && modeRegistered(EXT_MODE[ext])) return EXT_MODE[ext];
      // Fallback: CM meta (try every candidate, use the first that's registered).
      const info = window.CodeMirror.findModeByFileName ? window.CodeMirror.findModeByFileName(name) : null;
      if (info) {
        const cands = (info.mimes || []).concat(info.mime ? [info.mime] : [], info.mode ? [info.mode] : []);
        for (let i = 0; i < cands.length; i++) if (modeRegistered(cands[i])) return cands[i];
      }
      return 'text/plain';
    }

    return {
      cwd: '', entries: [], breadcrumb: [], selected: new Set(), loading: false,
      sidebarOpen: true, tree: [], view: 'list', uploading: false, uploadPct: 0, dragging: false, lastIndex: -1,
      up: { name: '', idx: 0, count: 0 },
      menu: { open: false, x: 0, y: 0, target: null },
      modal: null, form: { name: '', mode: '0644', recursive: false }, del: { names: [] },
      clipboard: { mode: null, paths: [] }, renameTarget: '', chmodTargets: [], zipPaths: [],
      editor: { open: false, path: '', sha: '', dirty: false, saving: false, confirmClose: false, modeName: '' },
      FILE_ICONS: FILE_ICONS, CHEV_RIGHT: CHEV_RIGHT, CHEV_DOWN: CHEV_DOWN,

      async init() { try { this.view = localStorage.getItem('fm_view') || 'list'; } catch (e) {} await this.load(''); await this.loadTreeRoot(); },

      // ---- listing ----
      async load(path) {
        this.loading = true; this.menu.open = false; this.selected = new Set(); this.lastIndex = -1;
        const j = await window.api(base + '/list?path=' + encodeURIComponent(path || '.'));
        this.loading = false;
        if (!j || !j.success) { window.AidiToast('error', (j && j.message) || 'Failed to list folder.'); return; }
        this.cwd = j.data.path; this.entries = j.data.entries; this.breadcrumb = j.data.breadcrumb;
        this.revealInTree(this.cwd);
      },
      join(name) { return this.cwd ? this.cwd + '/' + name : name; },
      goUp() { if (!this.cwd) return; const p = this.cwd.split('/'); p.pop(); this.load(p.join('/')); },
      select(en, ev) {
        const idx = this.entries.findIndex((e) => e.name === en.name);
        if (ev.shiftKey && this.lastIndex >= 0) {
          const a = Math.min(this.lastIndex, idx), b = Math.max(this.lastIndex, idx);
          const s = (ev.ctrlKey || ev.metaKey) ? new Set(this.selected) : new Set();
          for (let i = a; i <= b; i++) { if (this.entries[i]) s.add(this.entries[i].name); }
          this.selected = s;
          return;
        }
        const s = (ev.ctrlKey || ev.metaKey) ? new Set(this.selected) : new Set();
        s.has(en.name) ? s.delete(en.name) : s.add(en.name);
        this.selected = s;
        this.lastIndex = idx;
      },
      openEntry(en) { if (en.blocked) return; en.type === 'dir' ? this.load(this.join(en.name)) : this.openEditor(en); },
      fileType(en) {
        if (en.type === 'dir') return 'dir';
        if (en.type === 'symlink') return 'link';
        const ext = (en.name.indexOf('.') >= 0 ? en.name.split('.').pop() : '').toLowerCase();
        return EXT_MAP[ext] || 'file';
      },
      iconFor(en) { const t = this.fileType(en); return t === 'dir' ? FILE_ICONS.folder : (FILE_ICONS[t] || FILE_ICONS.file); },
      iconColor(en) {
        return ({ dir: 'text-amber-500', code: 'text-indigo-500', img: 'text-emerald-500', zip: 'text-orange-500',
          cfg: 'text-cyan-600', db: 'text-fuchsia-500', sheet: 'text-green-600', text: 'text-zinc-500', link: 'text-sky-500' })[this.fileType(en)] || 'text-zinc-400';
      },
      human(n) { if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(1) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; },
      fmtDate(ts) { if (!ts) return '—'; const d = new Date(ts * 1000), p = function (n) { return String(n).padStart(2, '0'); }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); },

      // ---- tree sidebar ----
      mkNode(name, path, depth) { return { name: name, path: path, depth: depth, open: false, loaded: false, hasChildren: true, children: [] }; },
      async listDirs(path) {
        const j = await window.api(base + '/list?path=' + encodeURIComponent(path || '.'));
        if (!j || !j.success) return [];
        return j.data.entries.filter(function (e) { return e.type === 'dir' && !e.blocked; });
      },
      async loadTreeRoot() { const dirs = await this.listDirs(''); this.tree = dirs.map((d) => this.mkNode(d.name, d.name, 0)); },
      async toggleNode(node) {
        if (!node.loaded) {
          const dirs = await this.listDirs(node.path);
          node.children = dirs.map((d) => this.mkNode(d.name, node.path + '/' + d.name, node.depth + 1));
          node.loaded = true; node.hasChildren = node.children.length > 0;
        }
        node.open = !node.open;
      },
      treeClick(node) { this.load(node.path); },
      // Expand + highlight the current folder in the tree (keeps it in sync with the main view).
      async revealInTree(path) {
        if (!path) return;
        const segs = path.split('/');
        let nodes = this.tree, acc = '';
        for (let i = 0; i < segs.length; i++) {
          acc = acc ? acc + '/' + segs[i] : segs[i];
          const node = nodes.find((n) => n.path === acc);
          if (!node) return;
          if (!node.loaded) {
            const dirs = await this.listDirs(node.path);
            node.children = dirs.map((d) => this.mkNode(d.name, node.path + '/' + d.name, node.depth + 1));
            node.loaded = true; node.hasChildren = node.children.length > 0;
          }
          node.open = true;
          nodes = node.children;
        }
      },
      get flatTree() { const out = []; (function walk(ns) { for (const n of ns) { out.push(n); if (n.open && n.children.length) walk(n.children); } })(this.tree); return out; },

      // ---- context menu ----
      openMenu(ev, target) { this.menu = { open: true, x: ev.clientX, y: ev.clientY, target: target }; },
      rowMenu(en, ev) { if (!this.selected.has(en.name)) { this.selected = new Set([en.name]); } this.openMenu(ev, en); },
      setView(v) { this.view = v; try { localStorage.setItem('fm_view', v); } catch (e) {} },

      // ---- create / delete (modals) ----
      askNewFile() { this.form.name = ''; this.modal = 'newFile'; this.$nextTick(() => this.$refs.modalInput && this.$refs.modalInput.focus()); },
      askNewFolder() { this.form.name = ''; this.modal = 'newFolder'; this.$nextTick(() => this.$refs.modalInput && this.$refs.modalInput.focus()); },
      async doNewFile() { const n = this.form.name.trim(); if (!n) return; const j = await window.api(base + '/save', 'POST', { path: this.join(n), content: '', new: '1' }); this.modal = null; this.after(j, 'File created.'); },
      async doNewFolder() { const n = this.form.name.trim(); if (!n) return; const j = await window.api(base + '/mkdir', 'POST', { path: this.join(n) }); this.modal = null; this.after(j, 'Folder created.'); },
      askDelete() { const names = this.selected.size ? [...this.selected] : (this.menu.target ? [this.menu.target.name] : []); if (!names.length) return; this.del.names = names; this.menu.open = false; this.modal = 'delete'; },
      async doDelete() { const j = await window.api(base + '/delete', 'POST', { paths: this.del.names.map((n) => this.join(n)) }); this.modal = null; this.after(j, 'Deleted.'); },

      // ---- upload / download ----
      // Chunked upload: slice each file into ~8 MB parts sent sequentially, each
      // tagged with its byte offset so a retried chunk is idempotent server-side —
      // a network blip retries just that chunk instead of restarting the whole
      // (possibly multi-GB) file. Small requests, so nginx/PHP limits never bind.
      async upload(fileList) {
        const files = fileList ? Array.from(fileList) : [];
        if (!files.length) return;
        const CHUNK = 8 * 1024 * 1024, RETRIES = 4;
        const dest = this.cwd || '.';
        const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        const total = files.reduce((s, f) => s + f.size, 0) || 1;
        let sent = 0, lastId = '';
        cancelled = false;
        this.uploading = true; this.uploadPct = 0;
        this.up = { name: '', idx: 0, count: files.length };
        try {
          for (let fi = 0; fi < files.length; fi++) {
            const f = files[fi];
            const id = this.uploadId(); lastId = id;
            this.up = { name: f.name, idx: fi + 1, count: files.length };
            const nchunks = Math.max(1, Math.ceil(f.size / CHUNK));
            for (let i = 0; i < nchunks; i++) {
              if (cancelled) throw new Error('cancelled');
              const slice = f.slice(i * CHUNK, Math.min(f.size, (i + 1) * CHUNK));
              const sentBefore = sent;
              let j = null;
              for (let attempt = 0; attempt < RETRIES; attempt++) {
                if (cancelled) throw new Error('cancelled');
                const fd = new FormData();
                fd.append('dest', dest); fd.append('name', f.name); fd.append('id', id);
                fd.append('offset', String(i * CHUNK)); fd.append('total', String(f.size));
                fd.append('final', i === nchunks - 1 ? '1' : '0');
                fd.append('chunk', slice, 'chunk');
                j = await this.sendChunk(fd, csrf, (loaded) => { this.uploadPct = Math.min(100, Math.round(((sentBefore + loaded) / total) * 100)); });
                if (j && j.success) break;
                if (j && j.message) throw new Error(j.message);          // server rejected (exists / no disk) — don't retry
                if (attempt < RETRIES - 1) await this.sleep(600 * (attempt + 1));  // network hiccup — back off + retry
              }
              if (!j || !j.success) throw new Error('Network error uploading ' + f.name);
              sent += slice.size; this.uploadPct = Math.min(100, Math.round((sent / total) * 100));
            }
          }
          this.uploading = false;
          window.AidiToast('success', 'Uploaded ' + files.length + ' file(s).');
          this.loadTreeRoot().then(() => this.load(this.cwd));
        } catch (e) {
          this.uploading = false;
          if (lastId) this.cancelPart(dest, lastId);                    // drop the half-written part
          const cx = (e && e.message) === 'cancelled';
          window.AidiToast(cx ? 'info' : 'error', cx ? 'Upload cancelled.' : ((e && e.message) || 'Upload failed.'));
          this.load(this.cwd);
        }
      },
      uploadId() { let s = ''; const c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; for (let i = 0; i < 24; i++) s += c.charAt(Math.floor(Math.random() * c.length)); return s; },
      sleep(ms) { return new Promise((r) => setTimeout(r, ms)); },
      cancelUpload() { cancelled = true; if (curXhr) { try { curXhr.abort(); } catch (e) {} } },
      cancelPart(dest, id) {
        const fd = new FormData(); fd.append('dest', dest); fd.append('id', id);
        const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        fetch(base + '/upload-cancel', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).catch(() => {});
      },
      sendChunk(fd, csrf, onProgress) {
        return new Promise((resolve) => {
          const xhr = new XMLHttpRequest();
          curXhr = xhr;
          xhr.open('POST', base + '/upload-chunk');
          xhr.setRequestHeader('X-CSRF-Token', csrf);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded); };
          xhr.onload = () => { curXhr = null; let j = null; try { j = JSON.parse(xhr.responseText); } catch (err) {} resolve(j); };
          xhr.onerror = () => { curXhr = null; resolve(null); };
          xhr.onabort = () => { curXhr = null; resolve(null); };
          xhr.send(fd);
        });
      },
      // A single file streams directly; a folder or a multi-selection is zipped
      // server-side (one folder) and streamed as one archive.
      download(en) { window.location = base + '/download?path=' + encodeURIComponent(this.join(en.name)); },
      downloadMany(names) {
        const dir = this.cwd || '';
        const qs = (dir ? 'dir=' + encodeURIComponent(dir) + '&' : '') + names.map((n) => 'names[]=' + encodeURIComponent(n)).join('&');
        window.location = base + '/download-many?' + qs;
      },
      downloadTargets() {
        const names = this.targetNames(); if (!names.length) return;
        this.menu.open = false;
        if (names.length === 1) {
          const en = this.entries.find((e) => e.name === names[0]);
          if (en && en.type === 'file' && !en.blocked) { this.download(en); return; }
        }
        if (names.length > 200) { window.AidiToast('error', 'Too many items selected — download the folder instead.'); return; }
        this.downloadMany(names);
      },

      after(j, okMsg) {
        if (!j || !j.success) { window.AidiToast('error', (j && j.message) || 'Action failed.'); return; }
        if (okMsg) window.AidiToast('success', okMsg);
        this.loadTreeRoot().then(() => this.load(this.cwd));
      },

      // ---- v1B: clipboard, rename, permissions, compress/extract ----
      targetNames() { return this.selected.size ? [...this.selected] : (this.menu.target ? [this.menu.target.name] : []); },
      targetPaths() { return this.targetNames().map((n) => this.join(n)); },
      singleSel() { return this.selected.size === 1 ? this.entries.find((e) => this.selected.has(e.name)) : (this.menu.target || null); },
      copy() { const p = this.targetPaths(); if (p.length) this.clipboard = { mode: 'copy', paths: p }; this.menu.open = false; },
      cut() { const p = this.targetPaths(); if (p.length) this.clipboard = { mode: 'cut', paths: p }; this.menu.open = false; },
      async paste(destDir) {
        if (!this.clipboard.paths.length) return;
        const action = this.clipboard.mode === 'cut' ? 'move' : 'copy';
        const dest = (destDir != null) ? destDir : (this.cwd || '.');
        const j = await window.api(base + '/' + action, 'POST', { dest: dest, paths: this.clipboard.paths });
        if (j && j.success && this.clipboard.mode === 'cut') this.clipboard = { mode: null, paths: [] };
        this.menu.open = false; this.after(j, action === 'move' ? 'Moved.' : 'Pasted.');
      },
      askRename(t) { t = t || this.menu.target; if (!t) return; this.renameTarget = t.name; this.form.name = t.name; this.menu.open = false; this.modal = 'rename'; this.$nextTick(() => { const el = this.$refs.modalInput; if (el) { el.focus(); el.select(); } }); },
      async doRename() { const n = this.form.name.trim(); if (!n) return; const j = await window.api(base + '/rename', 'POST', { path: this.join(this.renameTarget), newName: n }); this.modal = null; this.after(j, 'Renamed.'); },
      askChmod() { this.chmodTargets = this.targetNames(); if (!this.chmodTargets.length) return; this.form.mode = '0644'; this.form.recursive = false; this.menu.open = false; this.modal = 'chmod'; },
      async doChmod() { const j = await window.api(base + '/chmod', 'POST', { paths: this.chmodTargets.map((n) => this.join(n)), mode: this.form.mode, recursive: this.form.recursive ? '1' : '0' }); this.modal = null; this.after(j, 'Permissions updated.'); },
      askZip() { this.zipPaths = this.targetNames(); if (!this.zipPaths.length) return; this.form.name = 'archive.zip'; this.menu.open = false; this.modal = 'zip'; this.$nextTick(() => { const el = this.$refs.modalInput; if (el) el.focus(); }); },
      async doZip() { const n = this.form.name.trim(); if (!n) return; const j = await window.api(base + '/zip', 'POST', { dir: this.cwd || '.', name: n, paths: this.zipPaths.map((x) => this.join(x)) }); this.modal = null; this.after(j, 'Compressed.'); },
      async extract(en) {
        const t = en || this.menu.target; if (!t) return; this.menu.open = false;
        const stem = t.name.replace(/\.zip$/i, '') || 'extracted';
        const existing = new Set(this.entries.map((e) => e.name));
        let name = stem, i = 1; while (existing.has(name)) { name = stem + '-' + (i++); }
        const dest = this.join(name);
        const mk = await window.api(base + '/mkdir', 'POST', { path: dest });
        if (!mk || !mk.success) { window.AidiToast('error', (mk && mk.message) || 'Could not create folder.'); return; }
        const j = await window.api(base + '/unzip', 'POST', { path: this.join(t.name), dest: dest });
        this.after(j, 'Extracted to ' + name + '/');
      },

      // ---- editor ----
      async openEditor(en) {
        // Open the modal instantly with a placeholder (perceived responsiveness),
        // then fetch the file + lazy-load CodeMirror.
        this.editor = { open: true, path: this.join(en.name), sha: '', dirty: false, saving: false, confirmClose: false, modeName: '' };
        if (this.$refs.cmHost) this.$refs.cmHost.innerHTML = '<div class="p-4 text-sm text-zinc-400">Loading…</div>';
        const j = await window.api(base + '/read?path=' + encodeURIComponent(this.join(en.name)));
        if (j && (j.error === 'too_large' || j.error === 'binary')) { this.editor.open = false; window.AidiToast('error', 'Cannot open (' + j.error + '). Download instead.'); return; }
        if (!j || !j.success) { this.editor.open = false; window.AidiToast('error', (j && j.message) || 'Could not open file.'); return; }
        this.editor.sha = j.data.sha256;
        const content = j.data.content;
        try { await ensureCM(); } catch (e) { this.editor.open = false; window.AidiToast('error', 'Editor failed to load.'); return; }
        this.$nextTick(() => {
          this.$refs.cmHost.innerHTML = '';
          cm = window.CodeMirror(this.$refs.cmHost, {
            value: content, mode: pickMode(en.name), lineNumbers: true,
            lineWrapping: false, indentUnit: 2, tabSize: 2, autofocus: true, theme: 'dracula',
          });
          cm.setSize('100%', '100%');
          this.editor.modeName = (cm.getMode && cm.getMode().name) || 'text';
          cm.on('change', () => { this.editor.dirty = true; });
          // Reflow once the modal/flex layout has settled (fixes blank-until-click).
          setTimeout(function () { if (cm) cm.refresh(); }, 60);
        });
      },
      async saveEditor() {
        if (!cm) return;
        this.editor.saving = true;
        const j = await window.api(base + '/save', 'POST', { path: this.editor.path, content: cm.getValue(), expect_sha256: this.editor.sha });
        this.editor.saving = false;
        if (j && j.error === 'conflict') { window.AidiToast('error', <?= json_encode(t('site.files.conflict'), JSON_HEX_TAG) ?>); return; }
        if (!j || !j.success) { window.AidiToast('error', (j && j.message) || 'Save failed.'); return; }
        window.AidiToast('success', 'Saved.'); this.forceClose(); this.load(this.cwd);
      },
      tryClose() { if (this.editor.dirty) { this.editor.confirmClose = true; } else { this.forceClose(); } },
      forceClose() { cm = null; if (this.$refs.cmHost) this.$refs.cmHost.innerHTML = ''; this.editor.open = false; this.editor.confirmClose = false; },

      // ---- keyboard ----
      onKey(ev) {
        if (this.editor.open || this.modal) return;
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName)) return;
        const meta = ev.ctrlKey || ev.metaKey;
        if (meta && (ev.key === 'c' || ev.key === 'C')) { ev.preventDefault(); this.copy(); }
        else if (meta && (ev.key === 'x' || ev.key === 'X')) { ev.preventDefault(); this.cut(); }
        else if (meta && (ev.key === 'v' || ev.key === 'V')) { ev.preventDefault(); this.paste(); }
        else if (meta && (ev.key === 'a' || ev.key === 'A')) { ev.preventDefault(); this.selected = new Set(this.entries.map((e) => e.name)); }
        else if (ev.key === 'F2') { ev.preventDefault(); this.askRename(this.singleSel()); }
        else if (ev.key === 'Enter') { const e = this.singleSel(); if (e) { ev.preventDefault(); this.openEntry(e); } }
        else if (ev.key === 'Delete') { ev.preventDefault(); this.askDelete(); }
        else if (ev.key === 'Escape') { this.menu.open = false; this.selected = new Set(); }
      },
    };
  };
})();
</script>
<?php endif; ?>
