import { useCallback, useEffect, useMemo, useState } from 'react'
import { ThemeContext } from './theme-context-store'
const THEME_STORAGE_KEY = 'hr_theme'
const VALID_THEMES = ['light', 'dark']

function applyTheme(theme) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.classList.remove('light', 'dark')
  root.classList.add(theme)
  root.dataset.theme = theme
}

function normalizeTheme(value) {
  return VALID_THEMES.includes(value) ? value : 'light'
}

function readInitialTheme() {
  if (typeof window === 'undefined') return 'light'
  try {
    return normalizeTheme(window.localStorage.getItem(THEME_STORAGE_KEY))
  } catch {
    return 'light'
  }
}

export function ThemeProvider({ children }) {
  const [theme, setThemeState] = useState(readInitialTheme)

  const setTheme = useCallback((nextTheme) => {
    setThemeState((prev) => {
      const resolved = typeof nextTheme === 'function' ? nextTheme(prev) : nextTheme
      return normalizeTheme(resolved)
    })
  }, [])

  const toggleTheme = useCallback(() => {
    setThemeState((prev) => (prev === 'dark' ? 'light' : 'dark'))
  }, [])

  const cycleTheme = toggleTheme

  useEffect(() => {
    applyTheme(theme)
    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, theme)
    } catch {
      // ignore storage write failures
    }
  }, [theme])

  const value = useMemo(
    () => ({
      theme,
      setTheme,
      toggleTheme,
      cycleTheme,
    }),
    [theme, setTheme, toggleTheme, cycleTheme]
  )

  return (
    <ThemeContext.Provider value={value}>
      {children}
    </ThemeContext.Provider>
  )
}
