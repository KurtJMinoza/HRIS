const SESSION_KEY = 'hris_login_splash_shown'

export function hasLoginSplashBeenShown() {
  try {
    return sessionStorage.getItem(SESSION_KEY) === '1'
  } catch {
    return false
  }
}

export function markLoginSplashShown() {
  try {
    sessionStorage.setItem(SESSION_KEY, '1')
  } catch {
    // ignore storage errors (private mode, etc.)
  }
}

/** Splash only on a cold open of /login — not after logout or in-app navigation. */
export function shouldShowLoginSplash(location) {
  if (location?.state?.skipPreloader) return false
  return !hasLoginSplashBeenShown()
}
