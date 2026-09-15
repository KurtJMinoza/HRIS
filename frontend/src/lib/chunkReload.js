const RELOAD_MARKER = 'hris_chunk_reload_version'

export function registerChunkReloadHandler(appVersion) {
  if (typeof window === 'undefined' || !appVersion) {
    return
  }

  const reloadOnce = () => {
    try {
      if (sessionStorage.getItem(RELOAD_MARKER) === appVersion) {
        return false
      }
      sessionStorage.setItem(RELOAD_MARKER, appVersion)
    } catch {
      // ignore storage failures
    }

    window.location.reload()
    return true
  }

  window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault()
    reloadOnce()
  })

  window.addEventListener('unhandledrejection', (event) => {
    const message = String(event.reason?.message || event.reason || '')
    if (
      /Failed to fetch dynamically imported module|Importing a module script failed|Loading chunk .* failed|error loading dynamically imported module/i.test(
        message,
      )
    ) {
      event.preventDefault()
      reloadOnce()
    }
  })
}
