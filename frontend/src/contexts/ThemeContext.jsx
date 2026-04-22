import { createContext, useContext, useEffect } from 'react'

const ThemeContext = createContext(null)

/** Force light mode globally so all modules render white consistently across devices. */
function applyLightTheme() {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.classList.remove('dark')
  root.classList.add('light')
  root.dataset.theme = 'light'
}

export function ThemeProvider({ children }) {
  useEffect(() => {
    applyLightTheme()
  }, [])

  return (
    <ThemeContext.Provider
      value={{
        theme: 'light',
        setTheme: () => {},
        toggleTheme: () => {},
        cycleTheme: () => {},
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
