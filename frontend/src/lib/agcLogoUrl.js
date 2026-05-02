/**
 * Served from `frontend/public/` → dev/build URLs relative to `import.meta.env.BASE_URL`.
 * Return multiple candidates because logo locations differ across existing branches/builds.
 */
function basePath() {
  return typeof import.meta !== 'undefined' && import.meta.env?.BASE_URL != null
    ? String(import.meta.env.BASE_URL).replace(/\/?$/, '')
    : ''
}

export function agcLogoCandidatePathsForTheme(theme) {
  const base = basePath()
  if (theme === 'dark') {
    return [
      `${base}/dist/dist/dist/logo/AGC_WHITE.png`,
      `${base}/dist/dist/logo/AGC_WHITE.png`,
      `${base}/dist/logo/AGC_WHITE.png`,
      `${base}/dist/logo/AGC-FOR-DARK-THEME.png`,
      `${base}/dist/logo/AGC-WHITE-THEME.png`,
    ]
  }
  return [
    `${base}/dist/dist/logo/AGC_DARK.png`,
    `${base}/dist/logo/AGC_DARK.png`,
    `${base}/dist/logo/AGC-WHITE-THEME.png`,
  ]
}

export function agcLogoPathForTheme(theme) {
  return agcLogoCandidatePathsForTheme(theme)[0]
}
