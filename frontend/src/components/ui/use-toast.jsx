import { useCallback } from 'react'
import { toast as sonnerToast } from 'sonner'

/**
 * Adapter so existing `useToast().toast({ title, description, variant })`
 * calls route through Sonner.
 *
 * Variants:
 * - 'success'       → green success toast
 * - 'error'|'destructive' → red error toast
 * - 'warning'       → amber warning toast (fallback when warning helper missing)
 * - 'default'       → neutral toast
 *
 * `toast` is stable across renders so `useCallback(..., [toast])` in consumers does not thrash.
 */
export function useToast() {
  const toast = useCallback(({ title, description, variant = 'default', ...rest } = {}) => {
    const message = title || description || 'Notification'
    const options = {
      description: title && description ? description : undefined,
      ...rest,
    }

    switch (variant) {
      case 'success':
        sonnerToast.success(message, options)
        break
      case 'error':
      case 'destructive':
        sonnerToast.error(message, options)
        break
      case 'warning':
        if (typeof sonnerToast.warning === 'function') {
          sonnerToast.warning(message, options)
        } else {
          sonnerToast(message, {
            ...options,
            className: [options.className, 'bg-amber-50 text-amber-900'].filter(Boolean).join(' '),
          })
        }
        break
      default:
        sonnerToast(message, options)
        break
    }
  }, [])

  return { toast }
}

