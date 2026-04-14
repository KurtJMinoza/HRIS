import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { CardMetricsSkeleton } from './CardMetricsSkeleton'

/**
 * Skeleton for Employee Dashboard: welcome block, status card, metric cards, calendar/leave area.
 */
export function EmployeeDashboardSkeleton() {
  return (
    <div className="space-y-8">
      {/* Welcome + clock */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-2">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-4 w-48" />
          <Skeleton className="h-4 w-80 max-w-full" />
          <div className="inline-flex gap-2 rounded-md border border-border/80 px-3 py-2">
            <Skeleton className="size-2 rounded-full" />
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-40" />
          </div>
        </div>
        <Skeleton className="h-20 w-44 rounded-lg shrink-0" />
      </div>

      {/* Today card (large) */}
      <Card className="overflow-hidden border-primary/40 shadow-lg lg:col-span-2">
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <Skeleton className="h-4 w-16" />
          <Skeleton className="size-9 rounded-lg" />
        </CardHeader>
        <CardContent>
          <div className="flex items-baseline justify-between gap-2">
            <Skeleton className="h-9 w-40" />
            <Skeleton className="h-3 w-24" />
          </div>
          <Skeleton className="mt-2 h-4 w-56" />
        </CardContent>
      </Card>

      {/* Metric cards */}
      <CardMetricsSkeleton count={4} />

      {/* Calendar / Leave section */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card className="border-border/80 shadow-sm">
          <CardHeader>
            <Skeleton className="h-5 w-32" />
            <Skeleton className="h-4 w-48" />
          </CardHeader>
          <CardContent>
            <Skeleton className="h-[280px] w-full rounded-lg" />
          </CardContent>
        </Card>
        <Card className="border-border/80 shadow-sm">
          <CardHeader>
            <Skeleton className="h-5 w-28" />
            <Skeleton className="h-4 w-40" />
          </CardHeader>
          <CardContent className="space-y-2">
            {[1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-14 w-full rounded-md" />
            ))}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
