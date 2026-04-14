import { createContext, useContext } from 'react'
import { useLocation } from 'react-router-dom'

const HrAppPathContext = createContext(null)

export function HrAppPathProvider({ value, children }) {
  return <HrAppPathContext.Provider value={value}>{children}</HrAppPathContext.Provider>
}

/**
 * Panel base path for in-app links: `/admin`, `/company`, `/branch`, `/department`, or `/employee`.
 * Falls back to parsing the current URL when used outside a provider.
 */
export function useHrBasePath() {
  const ctx = useContext(HrAppPathContext)
  const { pathname } = useLocation()
  if (ctx) return ctx
  const m = pathname.match(/^\/(admin|company|branch|department|employee)(?=\/|$)/)
  return m ? `/${m[1]}` : '/employee'
}
