const RELOAD_MARKER = 'hris_chunk_reload_version'
const CHUNK_ERROR_PATTERN =
  /Failed to fetch dynamically imported module|Importing a module script failed|Loading chunk .* failed|error loading dynamically imported module/i

export function isStaleChunkLoadError(error) {
  const message = String(error?.message || error || '')
  return CHUNK_ERROR_PATTERN.test(message)
}

export function reloadForStaleChunks(appVersion) {
  if (typeof window === 'undefined') {
    return false
  }

  try {
    if (sessionStorage.getItem(RELOAD_MARKER) === appVersion) {
      return false
    }
    sessionStorage.setItem(RELOAD_MARKER, appVersion)
  } catch {
    // ignore storage failures
  }

  try {
    const url = new URL(window.location.href)
    url.searchParams.set('_hris_chunk_reload', String(Date.now()))
    window.location.replace(url.toString())
  } catch {
    window.location.reload()
  }

  return true
}

export function registerChunkReloadHandler(appVersion) {
  if (typeof window === 'undefined' || !appVersion) {
    return
  }

  window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault()
    reloadForStaleChunks(appVersion)
  })

  window.addEventListener('unhandledrejection', (event) => {
    if (!isStaleChunkLoadError(event.reason)) {
      return
    }

    event.preventDefault()
    reloadForStaleChunks(appVersion)
  })
}
