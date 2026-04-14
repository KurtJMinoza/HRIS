import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { fadeUpFrom, fadeUpTo } from '@/lib/gsapPresets'

gsap.registerPlugin(ScrollTrigger)

/**
 * Section that animates into view on scroll.
 * Uses opacity + y transform for performance (no layout shift).
 * Respects prefers-reduced-motion.
 */
export function AnimatedSection({
  children,
  className = '',
  as: Component = 'section',
  delay = 0,
  duration = 0.6,
  staggerChildren = 0,
  start = 'top 85%',
  once = true,
  childSelector = '> *',
}) {
  const ref = useRef(null)
  const innerRef = useRef(null)

  useEffect(() => {
    if (prefersReducedMotion() || !ref.current) return
    const el = ref.current

    if (staggerChildren > 0) {
      const container = innerRef.current
      const childrenEls = childSelector === '> *'
        ? Array.from(container.children)
        : container.querySelectorAll(childSelector)
      if (!childrenEls.length) return
      gsap.fromTo(
        childrenEls,
        fadeUpFrom,
        {
          ...fadeUpTo,
          duration,
          delay,
          stagger: staggerChildren,
          ease: 'power3.out',
          scrollTrigger: { trigger: el, start, once },
        }
      )
    } else {
      gsap.fromTo(
        el,
        fadeUpFrom,
        {
          ...fadeUpTo,
          duration,
          delay,
          ease: 'power3.out',
          scrollTrigger: { trigger: el, start, once },
        }
      )
    }

    return () => {
      ScrollTrigger.getAll().forEach((t) => t.trigger === el && t.kill())
    }
  }, [delay, duration, staggerChildren, start, once, childSelector])

  return (
    <Component ref={ref} className={className}>
      {staggerChildren ? (
        <div ref={innerRef} className="contents">
          {children}
        </div>
      ) : (
        children
      )}
    </Component>
  )
}
