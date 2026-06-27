import { Suspense } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { RouteErrorBoundary } from '@/components/RouteErrorBoundary'
import { DataTableRouteFallback } from '@/components/skeletons/RoutePageFallbacks.jsx'

/** Force sibling lazy routes (e.g. Organization modules) to remount on every navigation. */
export function PanelOutlet() {
  const location = useLocation()
  const routeKey = `${location.pathname}${location.search}${location.key}`

  return (
    <RouteErrorBoundary>
      <Suspense key={routeKey} fallback={<DataTableRouteFallback titleWidth="w-64" />}>
        <div key={routeKey} className="min-w-0 w-full @container">
          <Outlet />
        </div>
      </Suspense>
    </RouteErrorBoundary>
  )
}
