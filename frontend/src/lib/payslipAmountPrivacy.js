import { useCallback, useEffect, useMemo, useState } from 'react'

export const HIDDEN_PAYSLIP_AMOUNT = '••••••'

const STORAGE_PREFIX = 'hr:payslip-amounts-hidden:'

function readPreference(key) {
  if (typeof window === 'undefined') return false

  try {
    return window.localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

function writePreference(key, hidden) {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(key, hidden ? '1' : '0')
  } catch {
    // Keep the current in-memory preference when browser storage is unavailable.
  }
}

export function usePayslipAmountPrivacy(userId) {
  const storageKey = useMemo(() => {
    const normalizedUserId = String(userId ?? '').trim()
    return `${STORAGE_PREFIX}${normalizedUserId || 'anonymous'}`
  }, [userId])
  const [amountsHidden, setAmountsHidden] = useState(() => readPreference(storageKey))

  useEffect(() => {
    setAmountsHidden(readPreference(storageKey))
  }, [storageKey])

  useEffect(() => {
    if (typeof window === 'undefined') return undefined

    const syncPreference = (event) => {
      if (event.key === storageKey) {
        setAmountsHidden(event.newValue === '1')
      }
    }

    window.addEventListener('storage', syncPreference)
    return () => window.removeEventListener('storage', syncPreference)
  }, [storageKey])

  const toggleAmountsHidden = useCallback(() => {
    setAmountsHidden((current) => {
      const next = !current
      writePreference(storageKey, next)
      return next
    })
  }, [storageKey])

  return { amountsHidden, toggleAmountsHidden }
}
