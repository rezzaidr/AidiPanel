<?php
/**
 * Add Site — Step 2: data-driven per-type form.
 * $form comes from SiteController::addFormConfig(). Each field has `enabled`
 * (true = backend ready → active input; false = render disabled + "Soon").
 * `creatable` = type can be provisioned today (node/python = false → preview only).
 */
$pageTitle = t($form['title']);
$creatable = (bool) $form['creatable'];

$hasApp = false;
foreach ($form['fields'] as $f) {
    if (($f['input'] ?? '') === 'application') { $hasApp = true; break; }
}

/** Render one field per its config (active vs "Soon"). */
$renderField = function (array $f): void {
    $dis   = empty($f['enabled']);
    $req   = !empty($f['required']) ? ' <span class="text-rose-500">*</span>' : '';
    $soon  = $dis ? ' <span class="tag tag-soon">' . e(t('site.add.soon')) . '</span>' : '';
    $inCls = 'inp' . ($dis ? ' inp-soon' : '') . (!empty($f['mono']) ? ' mono' : '');
    $da    = $dis ? ' disabled' : '';

    echo '<div>';
    echo '<label class="lbl">' . e(t($f['label'])) . $req . $soon . '</label>';

    switch ($f['input']) {
        case 'application':
            echo '<select name="type" class="inp">';
            foreach ($f['active'] as [$val, $label]) {
                echo '<option value="' . e($val) . '">' . e($label) . '</option>';
            }
            echo '<option disabled>──── ' . e(t('site.add.app_soon')) . ' ────</option>';
            foreach ($f['soon'] as $label) {
                echo '<option disabled>' . e($label) . '</option>';
            }
            echo '</select>';
            break;

        case 'select':
            echo '<select name="' . e($f['key']) . '" class="' . $inCls . '"' . $da . '>';
            foreach ($f['options'] as $opt) {
                echo '<option value="' . e($opt) . '">' . e($opt) . '</option>';
            }
            echo '</select>';
            break;

        default: // text | password (shown as visible text)
            $val = isset($f['value'])       ? ' value="' . e($f['value']) . '"'             : '';
            $ph  = isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
            echo '<input type="text" name="' . e($f['key']) . '" class="' . $inCls . '"' . $val . $ph . $da . '>';
            if (!empty($f['generate'])) {
                $gc = $dis ? 'text-zinc-300 cursor-not-allowed' : 'text-ink hover:underline';
                echo '<button type="button" class="text-[11px] ' . $gc . ' mt-1 inline-flex items-center gap-1"' . $da . '>'
                   . '<i class="ti ti-refresh text-xs"></i> ' . e(t('site.add.generate')) . '</button>';
            }
    }

    if (!empty($f['note'])) {
        echo '<p class="hint">' . e(t($f['note'])) . '</p>';
    }
    echo '</div>';
};
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-1.5 text-xs text-zinc-400 mb-3">
    <a href="/sites/add" class="hover:text-ink flex items-center gap-1"><i class="ti ti-arrow-left text-sm"></i> <?= e(t('site.add.back')) ?></a>
    <span>·</span><span class="mono text-zinc-500">/sites/add/<?= e($form['slug']) ?></span>
  </div>

  <div class="card p-6">
    <div class="flex items-center gap-3 mb-5">
      <span class="w-10 h-10 rounded-lg <?= $creatable ? 'bg-ink-pale' : 'bg-zinc-100' ?> flex items-center justify-center">
        <i class="ti <?= e($form['icon']) ?> <?= $creatable ? 'text-ink' : 'text-zinc-400' ?> text-xl"></i>
      </span>
      <div>
        <h2 class="font-head font-bold text-lg <?= $creatable ? 'text-zinc-900' : 'text-zinc-700' ?> leading-none"><?= e(t($form['title'])) ?></h2>
        <p class="text-xs text-zinc-400 mt-1"><?= e(t($form['desc'])) ?></p>
      </div>
    </div>

    <?php if (!empty($form['banner'])): ?>
    <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200/70 rounded-lg px-4 py-2.5 mb-5">
      <i class="ti ti-tools text-amber-500 mt-0.5 text-sm"></i>
      <p class="text-[11px] text-amber-800 leading-relaxed"><?= e(t($form['banner'])) ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="/sites/add">
      <input type="hidden" name="_csrf_token" value="<?= e($_csrf_token) ?>">
      <?php if ($creatable && !$hasApp): ?>
        <input type="hidden" name="type" value="<?= e($form['type']) ?>">
      <?php endif; ?>

      <div class="space-y-4">
        <?php foreach ($form['fields'] as $f) { $renderField($f); } ?>
      </div>

      <div class="flex items-center gap-3 mt-6 pt-5 border-t border-zinc-100">
        <?php if ($creatable): ?>
          <button type="submit" class="btn btn-primary"><i class="ti ti-plus text-sm"></i> <?= e(t('site.add.create')) ?></button>
          <a href="/sites/add" class="text-xs font-medium text-zinc-500 hover:text-zinc-700 px-2"><?= e(t('common.cancel')) ?></a>
        <?php else: ?>
          <button type="button" class="btn btn-secondary" disabled><i class="ti ti-clock text-sm"></i> <?= e(t('site.add.coming_soon')) ?></button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
