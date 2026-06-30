<?php
$pageTitle = t('admin.backups.title');
$backup = is_array($remoteBackup ?? null) ? $remoteBackup : [];
$configured = !empty($backup['configured']);
$demo = demo_mode();
$last = is_array($backup['last_run'] ?? null) ? $backup['last_run'] : null;
$provider = (string) (($backup['provider'] ?? '') ?: 'AWS');
$region = (string) (($backup['region'] ?? '') ?: 'us-east-1');
$endpoint = (string) ($backup['endpoint'] ?? '');
$bucket = (string) ($backup['bucket'] ?? '');
$folder = (string) (($backup['folder'] ?? '') ?: 'aidipanel');
$frequency = (string) (($backup['frequency'] ?? '') ?: 'daily');
$weekday = (string) (($backup['weekday'] ?? '') ?: '1');
$btime = (string) (($backup['time'] ?? '') ?: '03:00');
$exclude = (string) ($backup['exclude'] ?? '');
$excludeLines = str_replace(',', "\n", $exclude);
$credentialsSaved = !empty($backup['credentials_saved']);
$lastVerified = (string) ($backup['last_verified_at'] ?? '');
$providerLabels = [
    'AWS' => 'Amazon S3',
    'Wasabi' => 'Wasabi',
    'DigitalOcean' => 'DigitalOcean Spaces',
];
$providerLabel = $providerLabels[$provider] ?? $provider;
$destination = $configured ? 's3://' . $bucket . '/' . trim($folder, '/') . '/' : '—';
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$lastOk = (int) ($last['sites_ok'] ?? 0);
$lastFailed = (int) ($last['sites_failed'] ?? 0);
$lastTotal = $lastOk + $lastFailed;
?>

<div x-data="{
  configured: <?= $configured ? 'true' : 'false' ?>,
  setupOpen: <?= $configured ? 'false' : 'true' ?>,
  destinationSaving: false,
  policySaving: false,
  submittingTest: false,
  testPassed: false,
  testResult: null,
  testError: '',
  changeCredentials: <?= $credentialsSaved ? 'false' : 'true' ?>,
  frequency: <?= e(json_encode($frequency)) ?>,
  initial: {
    provider: <?= e(json_encode($provider)) ?>,
    region: <?= e(json_encode($region)) ?>,
    endpoint: <?= e(json_encode($endpoint)) ?>,
    bucket: <?= e(json_encode($bucket)) ?>,
    folder: <?= e(json_encode($folder)) ?>
  },
  draft: {
    provider: <?= e(json_encode($provider)) ?>,
    region: <?= e(json_encode($region)) ?>,
    endpoint: <?= e(json_encode($endpoint)) ?>,
    bucket: <?= e(json_encode($bucket)) ?>,
    folder: <?= e(json_encode($folder)) ?>
  },
  destinationDirty() {
    return this.changeCredentials || JSON.stringify(this.draft) !== JSON.stringify(this.initial);
  },
  invalidateTest() {
    this.testPassed = false;
    this.testResult = null;
    this.testError = '';
  },
  normalizedFolder() {
    return this.draft.folder.trim().replace(/^\/+|\/+$/g, '');
  },
  destinationPreview() {
    const bucket = this.draft.bucket.trim();
    const folder = this.normalizedFolder();
    return 's3://' + bucket + (folder ? '/' + folder : '') + '/';
  },
  accessKeyPlaceholder() {
    if (this.draft.provider === 'Wasabi') return '3E7H7DRC0I6Q4JGK6FGC';
    if (this.draft.provider === 'DigitalOcean') return 'DOACCESSKEY';
    return 'AKIAIOSFODNN7EXAMPLE';
  },
  secretKeyPlaceholder() {
    if (this.draft.provider === 'Wasabi') return 'ZqempHGBL5ZXmRyXGzF8DXd5nbj6n5GPX0gG9wv2+l3ych5dWhyKiM7EIkHOhzE=';
    return 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
  },
  regionDocs() {
    if (this.draft.provider === 'Wasabi') return 'https://docs.wasabi.com/docs/what-are-the-service-urls-for-wasabi-s-different-storage-regions';
    if (this.draft.provider === 'DigitalOcean') return 'https://docs.digitalocean.com/products/spaces/details/availability/';
    return 'https://docs.aws.amazon.com/general/latest/gr/s3.html';
  },
  endpointSuggestion() {
    if (this.draft.provider === 'Wasabi') return 'https://s3.' + (this.draft.region || 'us-east-1') + '.wasabisys.com';
    if (this.draft.provider === 'DigitalOcean') return 'https://' + (this.draft.region || 'nyc3') + '.digitaloceanspaces.com';
    return '';
  },
  useSuggestedEndpoint() {
    this.draft.endpoint = this.endpointSuggestion();
    this.invalidateTest();
  },
  async testConnection(form) {
    this.submittingTest = true;
    this.testError = '';
    this.testResult = null;
    const payload = Object.fromEntries(new FormData(form).entries());
    try {
      const response = await fetch('/admin/backups/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || <?= e(json_encode(t('remote_backup.test_failed'))) ?>);
      this.testPassed = true;
      this.testResult = data.checks;
    } catch (error) {
      this.testPassed = false;
      this.testError = error instanceof Error ? error.message : <?= e(json_encode(t('remote_backup.test_failed'))) ?>;
    } finally {
      this.submittingTest = false;
    }
  }
}" class="space-y-5">
  <div>
    <h1 class="font-head font-bold text-[22px] text-zinc-900 leading-none"><?= e(t('admin.backups.title')) ?></h1>
    <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('admin.backups.desc')) ?></p>
  </div>

  <?php if ($demo): ?>
  <div class="card p-5 flex items-start gap-3">
    <?= icon('info-circle', 'text-indigo-500 text-lg mt-0.5') ?>
    <div>
      <p class="text-sm font-semibold text-zinc-800"><?= e(t('remote_backup.demo_title')) ?></p>
      <p class="text-xs text-zinc-500 mt-1"><?= e(t('remote_backup.demo_desc')) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <section class="card overflow-hidden">
    <div class="card-head flex items-center justify-between gap-4">
      <div>
        <h2 class="card-title"><?= icon('cloud', 'text-zinc-400') ?> <?= e(t('remote_backup.active_destination')) ?></h2>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t('remote_backup.active_destination_desc')) ?></p>
      </div>
      <?php if ($configured): ?>
      <span class="badge <?= $lastVerified !== '' ? 'badge-ok' : 'badge-warn' ?>">
        <?= e(t($lastVerified !== '' ? 'remote_backup.verified' : 'remote_backup.needs_verification')) ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($configured): ?>
    <div class="p-5 space-y-5">
      <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-5">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <p class="font-head font-bold text-lg text-zinc-900"><?= e($providerLabel) ?></p>
            <span class="tag tag-muted mono"><?= e($region) ?></span>
          </div>
          <p class="mono text-sm font-medium text-zinc-800 mt-1.5 break-all"><?= e($destination) ?></p>
          <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <div>
              <dt class="text-xs text-zinc-400"><?= e(t('remote_backup.endpoint')) ?></dt>
              <dd class="mono text-xs text-zinc-700 mt-1 break-all"><?= e($endpoint !== '' ? $endpoint : t('remote_backup.endpoint_default')) ?></dd>
            </div>
            <div>
              <dt class="text-xs text-zinc-400"><?= e(t('remote_backup.last_verified')) ?></dt>
              <dd class="text-xs text-zinc-700 mt-1"><?= e($lastVerified !== '' ? fmt_dt($lastVerified) : t('remote_backup.not_available')) ?></dd>
            </div>
          </dl>
        </div>

        <div class="shrink-0 flex flex-col sm:flex-row xl:flex-col gap-2 w-full sm:w-auto">
          <?php if (!$demo): ?>
          <form method="POST" action="/admin/backups/run" data-op-stream x-data="{ submitting: false }" class="w-full sm:w-auto xl:w-64">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
            <div data-op-fields>
              <button type="submit" class="btn btn-primary w-full justify-center" :disabled="submitting">
                <?= icon('upload', 'text-sm') ?> <?= e(t('remote_backup.now')) ?>
              </button>
            </div>
            <?php include APP_ROOT . '/Views/partials/op-progress.php'; ?>
          </form>
          <button type="button" class="btn btn-secondary w-full sm:w-auto xl:w-64 justify-center" @click="setupOpen = true">
            <?= icon('edit', 'text-sm') ?> <?= e(t('remote_backup.change_destination')) ?>
          </button>
          <?php endif; ?>
        </div>
      </div>

      <p x-show="destinationDirty()" x-cloak class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
        <?= e(t('remote_backup.run_uses_saved')) ?>
      </p>

      <div class="border-t border-zinc-100 pt-4">
        <p class="text-xs text-zinc-400"><?= e(t('remote_backup.last_backup')) ?></p>
        <?php if ($last): ?>
        <div class="flex items-center gap-2 flex-wrap mt-1.5">
          <?php $lastSuccess = (($last['result'] ?? '') === 'success'); ?>
          <span class="badge <?= $lastSuccess ? 'badge-ok' : 'badge-warn' ?>"><?= e(ucfirst((string) ($last['result'] ?? 'unknown'))) ?></span>
          <span class="text-xs text-zinc-600"><?= e(fmt_dt((string) ($last['finished_at'] ?? ''))) ?></span>
          <span class="text-xs text-zinc-400">·</span>
          <span class="text-xs text-zinc-600"><?= $lastTotal ?> <?= e(t('remote_backup.sites')) ?></span>
          <?php if ($lastFailed > 0): ?>
          <span class="text-xs text-rose-600"><?= $lastFailed ?> <?= e(t('remote_backup.failed')) ?></span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="text-sm text-zinc-400 mt-1.5"><?= e(t('remote_backup.never_run')) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <p class="text-sm font-semibold text-zinc-800"><?= e(t('remote_backup.no_destination')) ?></p>
        <p class="text-xs text-zinc-500 mt-1"><?= e(t('remote_backup.needs_destination')) ?></p>
      </div>
      <button type="button" class="btn btn-secondary shrink-0" disabled>
        <?= icon('upload', 'text-sm') ?> <?= e(t('remote_backup.now')) ?>
      </button>
    </div>
    <?php endif; ?>
  </section>

  <section class="card overflow-hidden">
    <button type="button" class="card-head w-full flex items-center justify-between gap-4 text-left" @click="setupOpen = !setupOpen">
      <div>
        <h2 class="card-title"><?= icon('settings', 'text-zinc-400') ?> <?= e(t('remote_backup.destination_setup')) ?></h2>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t('remote_backup.destination_setup_desc')) ?></p>
      </div>
      <span class="text-zinc-400 transition-transform" :class="setupOpen ? 'rotate-180' : ''"><?= icon('chevron-down', 'text-lg') ?></span>
    </button>

    <div x-show="setupOpen" x-cloak>
      <div x-show="configured && destinationDirty()" x-cloak class="mx-5 mt-5 flex items-start gap-2.5 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3.5 py-3">
        <?= icon('alert-triangle', 'text-base shrink-0 mt-0.5') ?>
        <span><?= e(t('remote_backup.editing_banner')) ?></span>
      </div>

      <form method="POST" action="/admin/backups/destination" @submit="destinationSaving = true" class="p-5">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
        <input type="hidden" name="use_saved_credentials" :value="changeCredentials ? '0' : '1'">
        <fieldset class="grid grid-cols-1 md:grid-cols-2 gap-4" <?= $demo ? 'disabled' : '' ?>>
          <div class="md:col-span-2">
            <label class="lbl" for="rb-provider"><?= e(t('remote_backup.provider')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <select id="rb-provider" name="provider" class="inp w-full" x-model="draft.provider" @change="invalidateTest()" required>
              <option value="AWS">Amazon S3</option>
              <option value="Wasabi">Wasabi</option>
              <option value="DigitalOcean">DigitalOcean Spaces</option>
            </select>
          </div>

          <div class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
            <?php if ($credentialsSaved): ?>
            <div x-show="!changeCredentials" class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-2.5">
                <?= icon('key', 'text-emerald-600 text-lg') ?>
                <div>
                  <p class="text-sm font-semibold text-zinc-800"><?= e(t('remote_backup.credentials_saved')) ?></p>
                  <p class="text-xs text-zinc-500 mt-0.5"><?= e(t('remote_backup.credentials_saved_desc')) ?></p>
                </div>
              </div>
              <button type="button" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700" @click="changeCredentials = true; invalidateTest()">
                <?= e(t('remote_backup.change_credentials')) ?>
              </button>
            </div>
            <?php endif; ?>

            <div x-show="changeCredentials" <?= $credentialsSaved ? 'x-cloak' : '' ?> class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="lbl" for="rb-access"><?= e(t('remote_backup.access_key')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
                <input id="rb-access" name="access_key_id" class="inp w-full mono" autocomplete="off" spellcheck="false"
                       :disabled="!changeCredentials" :required="changeCredentials" :placeholder="accessKeyPlaceholder()" @input="invalidateTest()">
              </div>
              <div>
                <label class="lbl" for="rb-secret"><?= e(t('remote_backup.secret_key')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
                <input id="rb-secret" type="password" name="secret_access_key" class="inp w-full mono" autocomplete="new-password" spellcheck="false"
                       :disabled="!changeCredentials" :required="changeCredentials" :placeholder="secretKeyPlaceholder()" @input="invalidateTest()">
              </div>
            </div>
          </div>

          <div>
            <label class="lbl" for="rb-region"><?= e(t('remote_backup.region')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <input id="rb-region" name="region" class="inp w-full mono" x-model="draft.region" @input="invalidateTest()" required spellcheck="false"
                   :placeholder="draft.provider === 'DigitalOcean' ? 'sgp1' : 'us-east-1'">
            <div data-region-help class="text-[11px] mt-1">
              <a :href="regionDocs()" target="_blank" rel="noopener noreferrer" class="font-semibold text-indigo-600 hover:text-indigo-700">
                <?= e(t('remote_backup.region_docs')) ?>
              </a>
            </div>
          </div>
          <div>
            <label class="lbl" for="rb-endpoint"><?= e(t('remote_backup.endpoint')) ?> <span x-show="draft.provider !== 'AWS'" aria-hidden="true" class="text-rose-500">*</span></label>
            <input id="rb-endpoint" type="url" name="endpoint" class="inp w-full mono" x-model="draft.endpoint" @input="invalidateTest()"
                   :required="draft.provider !== 'AWS'" spellcheck="false" :placeholder="endpointSuggestion() || 'https://s3.us-east-1.amazonaws.com'">
            <div data-endpoint-help class="text-[11px] mt-1">
              <span class="text-zinc-400" x-show="draft.provider === 'AWS'"><?= e(t('remote_backup.endpoint_aws')) ?></span>
              <button type="button" class="font-semibold text-indigo-600 hover:text-indigo-700"
                      x-show="draft.provider !== 'AWS'" x-cloak @click="useSuggestedEndpoint()">
                <?= e(t('remote_backup.endpoint_autofill')) ?>
              </button>
            </div>
          </div>
          <div>
            <label class="lbl" for="rb-bucket"><?= e(t('remote_backup.bucket')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <input id="rb-bucket" name="bucket" class="inp w-full mono" x-model="draft.bucket" @input="invalidateTest()" required spellcheck="false" placeholder="my-backups">
          </div>
          <div>
            <label class="lbl" for="rb-folder"><?= e(t('remote_backup.folder')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <input id="rb-folder" name="folder" class="inp w-full mono" x-model="draft.folder" @input="invalidateTest()" required spellcheck="false" placeholder="aidipanel">
          </div>

          <div class="md:col-span-2 rounded-lg bg-zinc-50 border border-zinc-200 px-4 py-3">
            <p class="text-[11px] uppercase tracking-wide font-semibold text-zinc-500"><?= e(t('remote_backup.final_destination')) ?></p>
            <p class="mono text-sm font-medium text-zinc-800 mt-1 break-all" x-text="destinationPreview()"></p>
          </div>
        </fieldset>

        <div class="mt-5 border-t border-zinc-100 pt-4 space-y-4">
          <div x-show="testPassed && testResult" x-cloak class="rounded-lg border border-emerald-200 bg-emerald-50 p-3.5">
            <p class="text-sm font-semibold text-emerald-800"><?= e(t('remote_backup.test_passed')) ?></p>
            <div class="flex flex-wrap gap-x-5 gap-y-2 mt-2 text-xs text-emerald-700">
              <span><?= icon('check', 'text-sm') ?> <?= e(t('remote_backup.upload_ok')) ?></span>
              <span><?= icon('check', 'text-sm') ?> <?= e(t('remote_backup.list_ok')) ?></span>
              <span><?= icon('check', 'text-sm') ?> <?= e(t('remote_backup.delete_ok')) ?></span>
            </div>
          </div>
          <div x-show="testError" x-cloak class="rounded-lg border border-rose-200 bg-rose-50 p-3.5 text-sm text-rose-700" x-text="testError"></div>

          <?php if (!$demo): ?>
          <div data-destination-actions class="flex flex-col sm:flex-row sm:items-center gap-3">
            <div x-show="!testPassed && destinationDirty() && !testError" x-cloak class="flex items-center gap-2 text-xs text-zinc-500 sm:mr-auto">
              <?= icon('info-circle', 'text-zinc-400 text-base') ?>
              <span><?= e(t('remote_backup.unsaved_test_required')) ?></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2.5 sm:ml-auto">
              <button type="button" class="btn btn-secondary justify-center" @click="testConnection($el.form)" :disabled="submittingTest || destinationSaving">
                <span x-show="!submittingTest"><?= icon('link', 'text-sm') ?> <?= e(t('remote_backup.test_connection')) ?></span>
                <span x-show="submittingTest" x-cloak><?= icon('loader-2', 'spin text-sm') ?> <?= e(t('remote_backup.testing')) ?></span>
              </button>
              <button type="submit" class="btn btn-primary justify-center" :disabled="!testPassed || destinationSaving || submittingTest">
                <?= icon('device-floppy', 'text-sm') ?> <?= e(t('remote_backup.save_destination')) ?>
              </button>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="card overflow-hidden">
    <div class="card-head">
      <h2 class="card-title"><?= icon('clock', 'text-zinc-400') ?> <?= e(t('remote_backup.policy')) ?></h2>
      <p class="text-xs text-zinc-400 mt-1"><?= e(t('remote_backup.policy_desc')) ?></p>
    </div>
    <form method="POST" action="/admin/backups/policy" @submit="policySaving = true">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <fieldset class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4" <?= ($demo || !$configured) ? 'disabled' : '' ?>>
        <div>
          <label class="lbl" for="rb-freq"><?= e(t('remote_backup.frequency')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
          <select id="rb-freq" name="frequency" class="inp w-full" x-model="frequency" required>
            <option value="daily"><?= e(t('remote_backup.frequency_daily')) ?></option>
            <option value="weekly"><?= e(t('remote_backup.frequency_weekly')) ?></option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div x-show="frequency === 'weekly'" x-cloak>
            <label class="lbl" for="rb-wday"><?= e(t('remote_backup.weekday')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <select id="rb-wday" name="weekday" class="inp w-full" :required="frequency === 'weekly'">
              <?php foreach ($days as $i => $day): ?>
              <option value="<?= (int) $i ?>" <?= $weekday === (string) $i ? 'selected' : '' ?>><?= e($day) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div :class="frequency === 'weekly' ? '' : 'col-span-2'">
            <label class="lbl" for="rb-time"><?= e(t('remote_backup.time')) ?> <span aria-hidden="true" class="text-rose-500">*</span></label>
            <input id="rb-time" type="time" name="time" class="inp w-full mono" required value="<?= e($btime) ?>">
          </div>
        </div>
        <div class="md:col-span-2">
          <label class="lbl" for="rb-exclude"><?= e(t('remote_backup.exclude')) ?></label>
          <textarea id="rb-exclude" name="exclude" class="inp w-full mono min-h-32" rows="5" spellcheck="false"
                    placeholder="<?= e(t('remote_backup.exclude_examples')) ?>"><?= e($excludeLines) ?></textarea>
          <p class="text-[11px] text-zinc-400 mt-1"><?= e(t('remote_backup.exclude_hint')) ?></p>
        </div>
        <div class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3">
          <p class="text-xs text-zinc-400"><?= e(t('remote_backup.retention')) ?></p>
          <p class="text-sm font-semibold text-zinc-800 mt-0.5"><?= e(t('remote_backup.keep_five')) ?></p>
        </div>
      </fieldset>
      <?php if (!$demo): ?>
      <div class="flex items-center justify-end px-5 py-3.5 border-t border-zinc-100">
        <button type="submit" class="btn btn-primary" :disabled="policySaving || !configured">
          <?= icon('device-floppy', 'text-sm') ?> <?= e(t('remote_backup.save_policy')) ?>
        </button>
      </div>
      <?php endif; ?>
    </form>
  </section>
</div>
