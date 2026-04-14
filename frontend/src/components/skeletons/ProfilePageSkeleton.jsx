import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * Skeleton for full-page employee profile views (AdminEmployeeProfile & EmployeeProfile).
 * Mimics: back nav + action buttons, avatar card, tab list, and form fields grid.
 */
export function ProfilePageSkeleton() {
  return (
    <div className="space-y-5">
      {/* Top nav: back button + action buttons */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Skeleton className="h-7 w-32" />
        <div className="flex gap-2">
          <Skeleton className="h-8 w-20" />
          <Skeleton className="h-8 w-28" />
        </div>
      </div>

      {/* Page title + subtitle */}
      <div className="space-y-1.5">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-4 w-80 max-w-full" />
      </div>

      {/* Profile header card: avatar + name + meta info */}
      <Card className="border border-border/60 shadow-sm dark:border-white/8">
        <CardContent className="p-5 sm:p-6">
          <div className="flex flex-wrap items-start gap-5">
            {/* Avatar */}
            <Skeleton className="size-24 shrink-0 rounded-2xl" />

            {/* Name + meta */}
            <div className="min-w-0 flex-1 space-y-3">
              <Skeleton className="h-7 w-48" />
              <Skeleton className="h-4 w-64" />
              <div className="flex flex-wrap gap-2">
                <Skeleton className="h-6 w-16 rounded-full" />
                <Skeleton className="h-6 w-24 rounded-md" />
                <Skeleton className="h-6 w-20 rounded-md" />
              </div>
              <div className="grid grid-cols-1 gap-3 pt-1 sm:grid-cols-2 lg:grid-cols-4">
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} className="space-y-1">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-4 w-28" />
                  </div>
                ))}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Tab list */}
      <div className="flex gap-1 border-b border-border/50 pb-0">
        {['Personal', 'Employment', 'Compensation', 'Benefits', 'Documents', 'Gov IDs', 'Emergency', 'Skills'].map(
          (tab) => (
            <Skeleton key={tab} className="h-8 w-20 rounded-t-md" />
          )
        )}
      </div>

      {/* Tab content: grid of form field skeletons */}
      <Card className="border border-border/60 shadow-sm dark:border-white/8">
        <CardHeader className="pb-2">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-4 w-64" />
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 9 }, (_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-9 w-full rounded-md" />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Second section */}
      <Card className="border border-border/60 shadow-sm dark:border-white/8">
        <CardHeader className="pb-2">
          <Skeleton className="h-5 w-32" />
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            {Array.from({ length: 4 }, (_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-9 w-full rounded-md" />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
