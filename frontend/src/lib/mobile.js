/** True for iPhone/iPad/iPod and typical mobile Android browsers. */
export function isMobileDevice() {
  if (typeof navigator === 'undefined') return false
  return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
}
