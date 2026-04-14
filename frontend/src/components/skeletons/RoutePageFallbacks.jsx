import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { ProfilePageSkeleton } from '@/components/skeletons/ProfilePageSkeleton'
import { TableSkeleton } from '@/components/skeletons/TableSkeleton'

export function ProfileRouteFallback() {
  return <ProfilePageSkeleton />
}

export function MyScheduleRouteFallback() {
  return (
    <div className="space-y-6">
      <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
        <CardHeader className="border-b border-border/60 bg-linear-to-br from-emerald-50/40 via-background to-background pb-6 dark:from-emerald-950/20">
          <Skeleton className="h-7 w-56" />
          <Skeleton className="mt-2 h-4 w-96 max-w-full" />
        </CardHeader>
        <CardContent className="space-y-5 p-6">
          <Skeleton className="h-28 w-full rounded-2xl" />
          <div className="grid gap-4 md:grid-cols-3">
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-xl" />
          </div>
        </CardContent>
      </Card>
      <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
        <CardHeader className="border-b border-border/60">
          <Skeleton className="h-6 w-48" />
        </CardHeader>
        <CardContent className="p-6">
          <TableSkeleton rows={5} cols={7} />
        </CardContent>
      </Card>
    </div>
  )
}

export function DataTableRouteFallback({ titleWidth = 'w-56' }) {
  return (
    <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
      <CardHeader className="border-b border-border/60 bg-background">
        <Skeleton className={`h-7 ${titleWidth}`} />
        <Skeleton className="mt-2 h-4 w-80 max-w-full" />
      </CardHeader>
      <CardContent className="p-6">
        <TableSkeleton rows={7} cols={6} />
      </CardContent>
    </Card>
  )
}
