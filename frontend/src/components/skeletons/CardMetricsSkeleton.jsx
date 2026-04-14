import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * Skeleton for a metrics card (title + value). Use in grids for dashboard/reports.
 */
export function CardMetricSkeleton() {
  return (
    <Card className="border-border/80 shadow-sm">
      <CardHeader className="pb-2">
        <Skeleton className="h-4 w-28" />
      </CardHeader>
      <CardContent>
        <Skeleton className="h-8 w-16" />
      </CardContent>
    </Card>
  )
}

/**
 * Grid of metric card skeletons.
 * @param {object} props
 * @param {number} [props.count=4]
 */
export function CardMetricsSkeleton({ count = 4 }) {
  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {Array.from({ length: count }, (_, i) => (
        <CardMetricSkeleton key={i} />
      ))}
    </div>
  )
}
