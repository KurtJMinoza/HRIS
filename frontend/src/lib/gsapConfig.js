/**
 * GSAP + ScrollTrigger config — enterprise HRIS animation system
 * Registers plugins, sets defaults, and provides reduced-motion detection.
 */
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

/** Defaults: subtle, professional, performance-optimized */
gsap.config({
  nullTargetWarn: false,
  trialWarn: false,
})

/** Check if user prefers reduced motion (accessibility) */
export function prefersReducedMotion() {
  if (typeof window === 'undefined') return false
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** Safe animator: returns no-op if reduced motion is preferred */
export function safeAnimate(cb) {
  if (prefersReducedMotion()) return () => {}
  return cb
}

/** Kill all ScrollTrigger instances (e.g. on route change / cleanup) */
export function killScrollTriggers() {
  ScrollTrigger.getAll().forEach((st) => st.kill())
}

export { gsap, ScrollTrigger }
