/** Radix dialogs/sheets can leave scroll-lock and full-screen overlays after route changes. */
export function releaseRouteUiLocks() {
  if (typeof document === 'undefined') return
  document.body.style.pointerEvents = ''
  document.body.style.overflow = ''
  document.body.removeAttribute('data-scroll-locked')
  document.querySelectorAll('[data-slot="sheet-overlay"], [data-slot="dialog-overlay"]').forEach((el) => {
    el.remove()
  })
}
