import { useCallback, useMemo, useState } from 'react'
import { ToastContext } from './use-toast'

let idCounter = 0

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const removeToast = useCallback((id) => {
    setToasts((current) => current.filter((t) => t.id !== id))
  }, [])

  const showToast = useCallback((toast) => {
    const id = ++idCounter
    const duration = toast.duration ?? 4000
    const payload = {
      id,
      variant: toast.variant || 'success',
      title: toast.title || '',
      description: toast.description || '',
    }
    setToasts((current) => [...current, payload])
    if (duration > 0) {
      setTimeout(() => removeToast(id), duration)
    }
  }, [removeToast])

  const value = useMemo(
    () => ({
      toast: showToast,
    }),
    [showToast]
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-50 flex max-w-sm flex-col gap-2">
        {toasts.map((t) => (
          <div
            key={t.id}
            className={[
              'pointer-events-auto flex flex-col gap-1 rounded-md border px-3 py-2 text-sm shadow-lg',
              t.variant === 'error'
                ? 'border-destructive/60 bg-destructive/10 text-destructive'
                : 'border-emerald-500/60 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            ].join(' ')}
          >
            {t.title && <div className="font-semibold">{t.title}</div>}
            {t.description && <div className="text-xs text-muted-foreground">{t.description}</div>}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

