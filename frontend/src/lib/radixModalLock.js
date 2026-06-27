/** Clear Radix Dialog/Sheet scroll lock when route changes or nav is clicked mid-modal. */
export function resetRadixModalLock() {
  if (typeof document === 'undefined') return
  document.body.style.pointerEvents = ''
  document.body.style.overflow = ''
  document.body.style.paddingRight = ''
  document.body.removeAttribute('data-scroll-locked')
  document.body.removeAttribute('data-aria-hidden')
  document.body.removeAttribute('aria-hidden')
  document.body.removeAttribute('inert')
  document.documentElement.style.pointerEvents = ''
  document.documentElement.style.overflow = ''
  document.documentElement.style.paddingRight = ''
  document.documentElement.removeAttribute('data-scroll-locked')
  document.documentElement.removeAttribute('data-aria-hidden')
  document.documentElement.removeAttribute('aria-hidden')
  document.documentElement.removeAttribute('inert')

  // ponytail: hideOthers() can leave stale aria-hidden on app shell after route unmount mid-dialog
  document.querySelectorAll('[data-aria-hidden="true"]').forEach((node) => {
    if (node === document.body || node === document.documentElement) return
    node.removeAttribute('data-aria-hidden')
    if (node.getAttribute('aria-hidden') === 'true') node.removeAttribute('aria-hidden')
  })
}

/** ponytail: do not .remove() Radix portal nodes — races dialog/sheet close and causes removeChild errors. */
export function clearStaleOverlays() {
  // Intentionally empty; resetRadixModalLock() handles scroll-lock cleanup.
}

/** Clear scroll lock and drop stale closed overlays (safe during React unmount). */
export function clearBlockingOverlays() {
  if (typeof document === 'undefined') return
  resetRadixModalLock()
  clearStaleOverlays()
}

/** Sidebar rescue: strip open overlays blocking nav clicks (call before navigate). */
export function forceClearBlockingOverlays() {
  if (typeof document === 'undefined') return
  resetRadixModalLock()
  clearStaleOverlays()
  document.querySelectorAll(
    '[data-slot="sheet-overlay"], [data-slot="dialog-overlay"], [data-slot="sheet-content"], [data-slot="dialog-content"]',
  ).forEach((el) => {
    el.remove()
  })
}

/** Ask open HR panel pages to close sheets/dialogs before the router switches routes. */
export function dispatchDismissOverlays() {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent('hr:dismiss-overlays'))
}

/** Run reset after Radix DismissableLayer cleanup on route transitions. */
export function scheduleRadixModalLockReset() {
  if (typeof window === 'undefined') return
  resetRadixModalLock()
  window.requestAnimationFrame(() => {
    clearStaleOverlays()
    window.requestAnimationFrame(clearStaleOverlays)
  })
}

/** Close overlays then navigate — avoids removeChild races when leaving a dialog/sheet. */
export function navigateAfterOverlayDismiss(navigate, to, options) {
  dispatchDismissOverlays()
  resetRadixModalLock()
  queueMicrotask(() => {
    navigate(to, options)
  })
}
