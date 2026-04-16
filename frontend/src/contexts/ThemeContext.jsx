import { createContext, useContext, useEffect, useState } from 'react'

const ThemeContext = createContext(null)

const STORAGE_KEY = 'smartdtr_theme'

/** Applies Tailwind `dark` class + explicit light/dark markers. Never reads OS preference. */
function applyTheme(theme) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  const isDark = theme === 'dark'
  if (isDark) {
    root.classList.add('dark')
    root.classList.remove('light')
    root.dataset.theme = 'dark'
  } else {
    root.classList.remove('dark')
    root.classList.add('light')
    root.dataset.theme = 'light'
  }
}

function getInitialTheme() {
  if (typeof window === 'undefined') return 'light'
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    // No longer follow OS: migrate legacy "system" to light
    if (stored === 'system') {
      window.localStorage.setItem(STORAGE_KEY, 'light')
      return 'light'
    }
    if (stored === 'light' || stored === 'dark') return stored
  } catch {
    // ignore
  }
  return 'light'
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
  }, [theme])

  const cycleTheme = () => {
    setTheme((prev) => (prev === 'light' ? 'dark' : 'light'))
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
