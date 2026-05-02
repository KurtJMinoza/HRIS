/**
 * Served from `frontend/public/` → dev/build URLs relative to `import.meta.env.BASE_URL`.
 * Light sidebar: `dist/logo/AGC_DARK.png` — dark sidebar: `dist/dist/logo/AGC_WHITE.png`.
 */
export function agcLogoPathForTheme(theme) {
  const base =
    typeof import.meta !== 'undefined' && import.meta.env?.BASE_URL != null
      ? String(import.meta.env.BASE_URL).replace(/\/?$/, '')
      : ''
  if (theme === 'dark') {
    return `${base}/dist/dist/logo/AGC_WHITE.png`
  }
  return `${base}/dist/logo/AGC_DARK.png`
}
