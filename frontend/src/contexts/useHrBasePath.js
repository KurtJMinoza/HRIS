import { useContext } from 'react'
import { useLocation } from 'react-router-dom'
import { HrAppPathContext } from './hr-app-path-context-store'

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
