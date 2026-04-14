import { createContext, useContext, useEffect, useState } from 'react'

const ThemeContext = createContext(null)

const STORAGE_KEY = 'smartdtr_theme'

function getSystemDark() {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches
}

function applyTheme(theme) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  if (theme === 'dark' || (theme === 'system' && getSystemDark())) {
    root.classList.add('dark')
  } else {
    root.classList.remove('dark')
  }
}

function getInitialTheme() {
  if (typeof window === 'undefined') return 'system'
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark' || stored === 'system') return stored
  } catch {
    // ignore
  }
  return 'system'
}

export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(getInitialTheme)

  useEffect(() => {
    try {
      window.localStorage.setItem(STORAGE_KEY, theme)
    } catch {
      // ignore
    }
    applyTheme(theme)
    if (theme === 'system') {
      const mq = window.matchMedia('(prefers-color-scheme: dark)')
      const handler = () => applyTheme('system')
      mq.addEventListener('change', handler)
      return () => mq.removeEventListener('change', handler)
    }
  }, [theme])

  const cycleTheme = () => {
    setTheme((prev) => {
      if (prev === 'system') return 'light'
      if (prev === 'light') return 'dark'
      return 'system'
    })
  }

  return (
    <ThemeContext.Provider
      value={{
        theme,
        setTheme,
        toggleTheme: () => setTheme((prev) => (prev === 'dark' ? 'light' : 'dark')),
        cycleTheme,
      }}
    >
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within a ThemeProvider')
  return ctx
}
