import { ArrowRight, Clock3 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export function OvertimeRequestsCard({
  pendingCount = 0,
  loading = false,
  onViewAll,
}) {
  const hasPending = Number(pendingCount) > 0

  return (
    <Card
      role="button"
      tabIndex={0}
      onClick={onViewAll}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onViewAll?.()
        }
      }}
      className={cn(
        'admin-dashboard-card h-full gap-0 overflow-hidden py-0 transition-[transform,box-shadow] duration-300 hover:-translate-y-px @xl:h-[330px]',
        'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
      )}
    >
      <CardHeader className="px-5 pb-5 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <CardTitle className="mb-3 flex items-center gap-2 truncate text-base font-extrabold leading-snug tracking-tight text-foreground">
              <Clock3 className="size-4 shrink-0 text-brand" aria-hidden />
              <span className="truncate">Overtime Requests</span>
              {hasPending ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand/12 px-1.5 text-[11px] font-semibold text-brand">
                  {pendingCount}
                </span>
              ) : null}
            </CardTitle>
            <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
              Pending overtime requests from employees.
            </CardDescription>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
              'mt-1 h-8 shrink-0 rounded-md border-border/70 bg-background/70 px-3',
              'text-xs font-medium',
              'shadow-sm shadow-black/5 hover:bg-accent/55 hover:shadow-black/10',
              'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              'transition-[background-color,box-shadow,color] duration-200',
            )}
            onClick={(e) => {
              e.stopPropagation()
              onViewAll?.()
            }}
          >
            View All
            <ArrowRight className="ml-1.5 size-3.5 opacity-70" aria-hidden />
          </Button>
        </div>
      </CardHeader>

      <CardContent className="min-h-0 space-y-4 px-5 pb-5 pt-0">
        {loading ? (
          <div className="rounded-2xl border border-border/70 bg-muted/15 p-5 text-sm font-normal leading-[1.55] text-muted-foreground">
            Loading overtime requests...
          </div>
        ) : !hasPending ? (
          <div className="flex min-h-[172px] flex-col items-center justify-center rounded-lg border border-brand/10 bg-[radial-gradient(circle_at_center,rgba(255,107,0,0.14),rgba(255,107,0,0.04)_58%,transparent)] p-5 text-center dark:border-brand/15">
            <span className="mb-4 flex size-12 items-center justify-center rounded-full border border-brand/25 bg-background text-brand shadow-sm dark:bg-card">
              <Clock3 className="size-6" aria-hidden />
            </span>
            <p className="text-sm font-semibold leading-[1.55] text-foreground">No pending overtime requests.</p>
            <p className="mt-1 text-xs text-muted-foreground">You&apos;re all caught up.</p>
          </div>
        ) : (
          <div className="flex min-h-[172px] items-center justify-center rounded-lg border border-border/70 bg-background/50 p-5 text-center">
            <div>
              <p className="text-3xl font-bold tabular-nums text-brand">{pendingCount}</p>
              <p className="mt-2 text-sm text-muted-foreground">
                pending overtime request{pendingCount > 1 ? 's' : ''} awaiting review
              </p>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
