import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TableSkeleton } from './TableSkeleton'

/**
 * Skeleton for Reports page: filters card + table card with tabs.
 */
export function ReportPageSkeleton() {
  return (
    <div className="space-y-6">
      <div>
        <Skeleton className="mb-2 h-8 w-32" />
        <Skeleton className="h-4 w-96 max-w-full" />
      </div>

      <Card className="border border-primary/15 shadow-sm">
        <CardHeader className="border-b border-primary/10 bg-primary/5 px-4 py-3">
          <Skeleton className="h-4 w-16" />
          <Skeleton className="mt-1 h-3 w-56" />
        </CardHeader>
        <CardContent className="flex flex-col gap-3 bg-primary/5 px-4 py-3 md:flex-row md:items-end md:justify-between">
          <div className="grid w-full grid-cols-2 gap-3 md:max-w-3xl md:grid-cols-4">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="space-y-1.5">
                <Skeleton className="h-3 w-12" />
                <Skeleton className="h-9 w-full" />
              </div>
            ))}
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 w-20" />
            <Skeleton className="h-9 w-24" />
          </div>
        </CardContent>
      </Card>

      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Skeleton className="size-5 rounded" />
          <Skeleton className="h-5 w-28" />
        </div>
        <Card className="border border-border/60 rounded-xl">
          <CardHeader className="flex flex-col gap-3 border-b border-border/40 bg-muted/40 py-4 md:flex-row md:items-center md:justify-between">
            <div>
              <Skeleton className="h-5 w-20" />
              <Skeleton className="mt-1 h-3 w-40" />
            </div>
            <div className="flex gap-2">
              <Skeleton className="h-9 w-24" />
              <Skeleton className="h-9 w-28" />
            </div>
          </CardHeader>
          <CardContent className="pt-4">
            <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="grid grid-cols-1 gap-2 md:grid-cols-3 md:gap-4">
                {[1, 2, 3].map((i) => (
                  <div key={i} className="rounded-md border border-border/40 bg-muted/20 px-3 py-2">
                    <Skeleton className="h-3 w-24" />
                    <Skeleton className="mt-1 h-6 w-12" />
                  </div>
                ))}
              </div>
              <Skeleton className="h-9 w-full md:w-64" />
            </div>
            <Skeleton className="mb-4 h-10 w-full max-w-md rounded-full" />
            <TableSkeleton rows={8} cols={6} />
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
