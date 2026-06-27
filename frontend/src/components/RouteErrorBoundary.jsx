import { useLocation } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'

/** Resets route error state when navigation changes (sibling HR panel routes share the same boundary shape). */
export function RouteErrorBoundary({ children }) {
  const location = useLocation()
  const routeKey = `${location.pathname}${location.search}${location.key}`
  return (
    <ErrorBoundary key={routeKey}>
      {children}
    </ErrorBoundary>
  )
}
