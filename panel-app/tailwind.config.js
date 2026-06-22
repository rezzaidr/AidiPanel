/**
 * AidiPanel — Tailwind build config (local production CSS).
 *
 * Mirrors the theme that used to live inline next to the Tailwind Play CDN, so
 * the generated utility classes match exactly what the PHP views already use.
 * Output: public/assets/tailwind.css (minified). The hand-written component
 * layer in public/assets/app.css stays separate and loads after this file.
 *
 * Content scans every PHP file (views + controllers) so any class string that
 * is chosen dynamically in PHP — e.g. a `$badge ? 'badge-ok' : 'badge-muted'`
 * ternary, or the cache/SSL status colours mapped in <script> blocks — is seen
 * by the scanner. No safelist is needed: every class used at runtime appears as
 * a literal token somewhere in these files.
 */
module.exports = {
  content: ['./app/**/*.php', './public/**/*.php'],
  theme: {
    extend: {
      colors: {
        ink:   { DEFAULT: '#322C7A', light: '#4A42A8', pale: '#ECEBF7' },
        speed: { DEFAULT: '#0891B2', light: '#06B6D4', pale: '#E0F7FB' },
        // legacy alias → ink, kept so pre-Fase2 markup keeps its colour
        brand: { DEFAULT: '#322C7A', light: '#4A42A8', pale: '#ECEBF7' },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
        head: ['"Space Grotesk"', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
};
