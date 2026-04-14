/**
 * GSAP animation presets — enterprise-grade, subtle motion
 * Use with gsap.from(), gsap.fromTo(), or ScrollTrigger.
 */
export const DURATION = {
  fast: 0.3,
  normal: 0.5,
  slow: 0.7,
  pageEntry: 0.65,
}

export const EASE = {
  /** power3.out — smooth deceleration, professional feel */
  out: 'power3.out',
  /** power2.inOut — gentle both ends */
  inOut: 'power2.inOut',
}

/** fadeUp: opacity 0→1, y 30→0 — classic section reveal */
export const fadeUp = {
  from: { opacity: 0, y: 30 },
  to: { opacity: 1, y: 0, duration: DURATION.normal, ease: EASE.out },
}

/** fadeUp from-to values for gsap.fromTo */
export const fadeUpFrom = { opacity: 0, y: 30 }
export const fadeUpTo = { opacity: 1, y: 0, duration: DURATION.normal, ease: EASE.out }

/** fadeIn: opacity only — minimal */
export const fadeIn = {
  opacity: 0,
  duration: DURATION.normal,
  ease: EASE.out,
}

export const fadeInFrom = { opacity: 0 }
export const fadeInTo = { opacity: 1, duration: DURATION.normal, ease: EASE.out }

/** scaleIn: slight scale 0.98→1 — cards, modals */
export const scaleIn = {
  opacity: 0,
  scale: 0.98,
  duration: DURATION.fast,
  ease: EASE.out,
}

export const scaleInFrom = { opacity: 0, scale: 0.98 }
export const scaleInTo = { opacity: 1, scale: 1, duration: DURATION.fast, ease: EASE.out }

/** modal/drawer: fade + scale, slightly longer */
export const modalReveal = {
  from: { opacity: 0, scale: 0.95 },
  to: { opacity: 1, scale: 1, duration: 0.35, ease: EASE.out },
}

/** stagger delay between children (e.g. 0.1s for cards) */
export const STAGGER = 0.1
