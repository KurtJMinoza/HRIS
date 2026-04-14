import { AlertTriangle, CheckCircle2, Moon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { ShiftPill } from '@/components/schedules/ShiftPill'

/**
 * Floating summary: sample week hours, ND exposure, OT risk, rest-day rule.
 */
export function ScheduleComplianceBar({
  weeklyHours,
  ndHoursPerWeek,
  otRisk,
  restOk,
  className,
  onValidate,
}) {
  const riskLabel =
    otRisk === 'high' ? 'Elevated' : otRisk === 'medium' ? 'Moderate' : 'Low priority'
  const riskClass =
    otRisk === 'high'
      ? 'text-amber-700 dark:text-amber-300'
      : otRisk === 'medium'
        ? 'text-amber-600 dark:text-amber-400/90'
        : 'text-emerald-700 dark:text-emerald-300'

  return (
    <div
      className={cn(
        'flex flex-col gap-3 rounded-xl border border-border/80 bg-card/95 p-4 shadow-md backdrop-blur-sm @lg:flex-row @lg:items-center @lg:justify-between',
        className
      )}
    >
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Computed hours</p>
          <p className="text-base font-semibold tabular-nums text-foreground">{weeklyHours.toFixed(1)} h/week</p>
        </div>
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">OT risk</p>
          <p className={cn('text-sm font-semibold', riskClass)}>{riskLabel}</p>
        </div>
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">ND exposure (22:00–06:00)</p>
          <p className="text-sm font-semibold text-purple-700 dark:text-purple-300 tabular-nums">
            {ndHoursPerWeek.toFixed(1)} h/week
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Moon className="size-4 text-purple-500" aria-hidden />
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Weekly rest</p>
            <div className="flex items-center gap-1.5">
              {restOk ? (
                <>
                  <CheckCircle2 className="size-4 text-emerald-600" aria-hidden />
                  <span className="text-sm font-medium text-emerald-700 dark:text-emerald-300">Compliant</span>
                </>
              ) : (
                <>
                  <AlertTriangle className="size-4 text-amber-600" aria-hidden />
                  <span className="text-sm font-medium text-amber-700 dark:text-amber-300">Review rest days</span>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <ShiftPill variant="nd">DOLE ND window</ShiftPill>
        {onValidate && (
          <Button type="button" size="sm" className="min-h-11 px-4" onClick={onValidate}>
            Validate preview
          </Button>
        )}
      </div>
    </div>
  )
}
