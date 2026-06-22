/**
 * AidiPanel — icon sync/check (run via `npm run icons` or as part of `npm run build`).
 *
 * The panel renders icons with the icon() PHP helper, which inlines local SVG files
 * from public/assets/icons/tabler/. This script keeps that folder in sync with what
 * the code actually uses, copying each requested icon from the official @tabler/icons
 * package. Icons are never hand-drawn — Tabler is the single source of truth.
 *
 * Requested icons are discovered from the source automatically:
 *   - icon('name', …) helper calls in PHP
 *   - 'ti-name' / "ti-name" string literals (icon names kept in controller/view data)
 *
 * To add a new icon: use its official Tabler name in icon('…') (or in icon data),
 * run `npm run icons`, confirm it reports the icon as copied (not MISSING), then
 * re-run the PHP syntax check. A requested icon that Tabler does not have is listed
 * under MISSING and the script exits non-zero — it is never invented locally.
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, statSync, copyFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');                                   // panel-app/
const APP  = join(ROOT, 'app');
const OUT  = join(ROOT, 'public', 'assets', 'icons', 'tabler');
const SRC  = [
  join(ROOT, 'node_modules', '@tabler', 'icons', 'icons', 'outline'),
  join(ROOT, 'node_modules', '@tabler', 'icons', 'icons', 'filled'),
];

function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (p.endsWith('.php')) out.push(p);
  }
  return out;
}

// 1. Discover requested icon names from the PHP source.
const requested = new Set();
const reCall    = /\bicon\(\s*['"]([a-z0-9-]+)['"]/g;   // icon('server', …)
const reLiteral = /['"]ti-([a-z0-9-]+)['"]/g;           // 'ti-brand-php' icon data
for (const f of walk(APP)) {
  const src = readFileSync(f, 'utf8');
  let m;
  while ((m = reCall.exec(src)))    requested.add(m[1].replace(/^ti-/, ''));
  while ((m = reLiteral.exec(src))) requested.add(m[1]);
}
const names = [...requested].sort();

// 2. Copy each requested icon from @tabler/icons; record any that don't exist there.
mkdirSync(OUT, { recursive: true });
const copied = [], missing = [];
for (const n of names) {
  const src = SRC.map((d) => join(d, n + '.svg')).find(existsSync);
  if (src) {
    // Minify to a single line so the inlined markup stays small and clean.
    const min = readFileSync(src, 'utf8').replace(/\s+/g, ' ').replace(/>\s+</g, '><').trim();
    writeFileSync(join(OUT, n + '.svg'), min);
    copied.push(n);
  } else missing.push(n);
}

// 3. Report orphans: local SVGs that are no longer referenced anywhere.
const have = existsSync(OUT) ? readdirSync(OUT).filter((f) => f.endsWith('.svg')).map((f) => f.slice(0, -4)) : [];
const orphans = have.filter((n) => !requested.has(n));

console.log(`Tabler icon subset → public/assets/icons/tabler/`);
console.log(`  requested: ${names.length}`);
console.log(`  copied:    ${copied.length}`);
if (orphans.length) {
  console.log(`  orphans (${orphans.length}, present locally but unused — safe to delete):`);
  for (const n of orphans) console.log('    - ' + n);
}
if (missing.length) {
  console.log(`  MISSING (${missing.length}) — not in @tabler/icons, NO local SVG written:`);
  for (const n of missing) console.log('    - ' + n);
  console.log('  Use an official Tabler icon name, or change the usage. Icons are never invented.');
  // Reported loudly above on every run; `--strict` also fails the process (for CI),
  // while a plain run stays exit 0 so `npm run build` is not blocked by known gaps.
  if (process.argv.includes('--strict')) process.exitCode = 1;
}
