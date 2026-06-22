<?php
/**
 * Reusable streamed-operation progress UI. Include once inside a form/page, then
 * drive it from JS via window.opProgressController(root). Hidden until .show().
 * The bar width reflects real backend stages; long steps hold + shimmer + tick.
 */
?>
<div data-op-progress class="hidden mt-1">
  <div class="rounded-lg border border-zinc-200 bg-zinc-50/70 p-4">
    <div class="flex items-center gap-2 mb-2.5">
      <span data-op-icon><?= icon('loader-2', 'spin text-ink text-base') ?></span>
      <span data-op-label class="text-sm font-medium text-zinc-700"><?= e(t('op.progress.starting')) ?></span>
      <span data-op-elapsed class="text-[11px] text-zinc-400 ml-auto tabular-nums"></span>
    </div>
    <div class="h-2 rounded-full bg-zinc-200 overflow-hidden">
      <div data-op-bar class="h-full rounded-full bg-ink transition-[width] duration-500 ease-out" style="width:0%"></div>
    </div>
    <p data-op-error class="hidden mt-2.5 text-xs text-rose-600 leading-relaxed"></p>
  </div>
</div>
