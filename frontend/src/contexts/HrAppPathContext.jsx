import { HrAppPathContext } from './hr-app-path-context-store'

export function HrAppPathProvider({ value, children }) {
  return <HrAppPathContext.Provider value={value}>{children}</HrAppPathContext.Provider>
}
