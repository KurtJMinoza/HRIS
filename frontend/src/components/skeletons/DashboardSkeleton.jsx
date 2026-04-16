import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TableSkeleton } from './TableSkeleton'

/**
 * Skeleton for the Admin Dashboard: title, KPI cards, chart area, and today's table.
 */
export function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <div className="-mb-3 space-y-1">
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* KPI cards row */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[1, 2, 3, 4].map((i) => (
          <Card key={i} className="border-border/80 shadow-sm">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-0">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="size-9 rounded-lg" />
            </CardHeader>
            <CardContent>
              <Skeleton className="h-9 w-16" />
              <Skeleton className="mt-5 h-8 w-full" />
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts row */}
      <div className="grid gap-8 md:grid-cols-2">
        <Card className="border-border/80 shadow-sm">
          <CardHeader>
            <Skeleton className="h-5 w-36" />
            <Skeleton className="h-4 w-52" />
          </CardHeader>
          <CardContent>
            <Skeleton className="h-[240px] w-full rounded-lg" />
          </CardContent>
        </Card>
        <Card className="border-border/80 shadow-sm">
          <CardHeader>
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-4 w-48" />
          </CardHeader>
          <CardContent>
            <Skeleton className="h-[240px] w-full rounded-lg" />
          </CardContent>
        </Card>
      </div>

      {/* Today's attendance table */}
      <Card className="border-border/80 shadow-sm">
        <CardHeader className="flex flex-row items-center justify-between">
          <div>
            <Skeleton className="h-6 w-40" />
            <Skeleton className="mt-1 h-4 w-56" />
          </div>
          <Skeleton className="h-9 w-24" />
        </CardHeader>
        <CardContent>
          <TableSkeleton rows={6} cols={5} />
        </CardContent>
      </Card>
    </div>
  )
}
