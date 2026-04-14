import { useState } from 'react'
import { Calendar, Loader2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { ScheduleWeeklyGrid } from '@/components/schedules/ScheduleWeeklyGrid'
import { ScheduleComplianceBar } from '@/components/schedules/ScheduleComplianceBar'
import {
  hasWeeklyRestDay,
  otRiskLevel,
  weeklyNdHours,
  weeklyScheduledHours,
} from '@/lib/scheduleLib'
import { toHhMm } from '@/lib/timeFormat'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'

const DAY_OPTIONS = [
  { key: 'mon', label: 'M', full: 'Monday' },
  { key: 'tue', label: 'T', full: 'Tuesday' },
  { key: 'wed', label: 'W', full: 'Wednesday' },
  { key: 'thu', label: 'Th', full: 'Thursday' },
  { key: 'fri', label: 'F', full: 'Friday' },
  { key: 'sat', label: 'S', full: 'Saturday' },
  { key: 'sun', label: 'Su', full: 'Sunday' },
]

function toggleRestDay(restDays, dayKey) {
  const current = new Set(restDays || [])
  if (current.has(dayKey)) current.delete(dayKey)
  else current.add(dayKey)
  return Array.from(current)
}

export function ScheduleEditorDialog({
  open,
  onOpenChange,
  editingSchedule,
  editForm,
  setEditForm,
  onSubmit,
  submitting,
  error,
}) {
  const [ndPreview, setNdPreview] = useState(true)

  function handleOpenChange(nextOpen) {
    if (!nextOpen) setNdPreview(true)
    onOpenChange(nextOpen)
  }

  const preview = {
    time_in: toHhMm(editForm.time_in) || editForm.time_in,
    time_out: toHhMm(editForm.time_out) || editForm.time_out,
    break_start: editForm.break_start ? toHhMm(editForm.break_start) : null,
    break_end: editForm.break_end ? toHhMm(editForm.break_end) : null,
    rest_days: editForm.rest_days || [],
  }

  const wh = weeklyScheduledHours(preview)
  const ndh = ndPreview ? weeklyNdHours(preview) : 0
  const risk = otRiskLevel(preview)
  const restOk = hasWeeklyRestDay(preview)

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className={cn(
          'flex max-h-[min(92dvh,900px)] min-h-0 w-full max-w-full flex-col gap-0 overflow-hidden border-border/60 bg-card p-0 shadow-xl dark:border-border/50',
          'sm:mx-auto sm:max-w-5xl'
        )}
      >
        <form
          className="flex min-h-0 w-full min-w-0 flex-1 flex-col overflow-hidden"
          onSubmit={(e) => {
            e.preventDefault()
            onSubmit(e)
          }}
        >
          {/* pr-12 / pr-14 clears the dialog close (X) */}
          <DialogHeader className="shrink-0 border-b border-border/60 bg-muted/30 px-4 py-4 pr-12 text-left @sm:px-6 @sm:py-5 @sm:pr-14">
            <DialogTitle className="flex items-start gap-2.5 text-lg font-semibold tracking-tight @sm:items-center @sm:text-xl">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Calendar className="size-5" aria-hidden />
              </span>
              <span className="min-w-0 leading-snug">
                {editingSchedule ? 'Edit work schedule' : 'New work schedule'}
              </span>
            </DialogTitle>
            <DialogDescription className="max-w-3xl text-xs leading-relaxed text-muted-foreground @sm:text-sm">
              Fixed shift template for PH teams. Night shift: set time out earlier than time in (e.g. 22:00 → 06:00).
              Night differential preview uses the DOLE 22:00–06:00 window; payroll still follows your active policy.
            </DialogDescription>
          </DialogHeader>

          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6">
          <div className="grid gap-6 @xl:grid-cols-[minmax(280px,360px)_1fr]">
            <div className="space-y-5 rounded-xl border border-border/60 bg-muted/25 p-4 dark:bg-muted/15">
              <div className="space-y-1.5">
                <Label htmlFor="schedule-name">Schedule name</Label>
                <Input
                  id="schedule-name"
                  value={editForm.name}
                  onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                  placeholder="e.g. Night Shift – Production"
                  className="h-11 min-h-11"
                  required
                />
              </div>
              <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="time-in">Time in</Label>
                  <Input
                    id="time-in"
                    type="time"
                    value={editForm.time_in}
                    onChange={(e) => setEditForm((f) => ({ ...f, time_in: e.target.value }))}
                    className="h-11 min-h-11"
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="time-out">Time out</Label>
                  <Input
                    id="time-out"
                    type="time"
                    value={editForm.time_out}
                    onChange={(e) => setEditForm((f) => ({ ...f, time_out: e.target.value }))}
                    className="h-11 min-h-11"
                    required
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label htmlFor="break-start">Break start</Label>
                  <Input
                    id="break-start"
                    type="time"
                    value={editForm.break_start}
                    onChange={(e) => setEditForm((f) => ({ ...f, break_start: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="break-end">Break end</Label>
                  <Input
                    id="break-end"
                    type="time"
                    value={editForm.break_end}
                    onChange={(e) => setEditForm((f) => ({ ...f, break_end: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
              </div>
              <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="grace">Grace period (min)</Label>
                  <Input
                    id="grace"
                    type="number"
                    min={0}
                    max={240}
                    value={editForm.grace_period_minutes}
                    onChange={(e) => setEditForm((f) => ({ ...f, grace_period_minutes: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="early-timein">Early time-in (min)</Label>
                  <Input
                    id="early-timein"
                    type="number"
                    min={0}
                    max={480}
                    value={editForm.early_timein_minutes}
                    onChange={(e) => setEditForm((f) => ({ ...f, early_timein_minutes: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="overtime-buffer">Overtime buffer (min)</Label>
                <Input
                  id="overtime-buffer"
                  type="number"
                  min={0}
                  max={480}
                  value={editForm.overtime_buffer_minutes}
                  onChange={(e) => setEditForm((f) => ({ ...f, overtime_buffer_minutes: e.target.value }))}
                  className="h-11 min-h-11"
                />
              </div>
              <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="late-allowance">Late allowance (min)</Label>
                  <Input
                    id="late-allowance"
                    type="number"
                    min={0}
                    max={240}
                    placeholder="Optional"
                    value={editForm.late_allowance_minutes}
                    onChange={(e) => setEditForm((f) => ({ ...f, late_allowance_minutes: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="early-timeout">Early time-out (min)</Label>
                  <Input
                    id="early-timeout"
                    type="number"
                    min={0}
                    max={240}
                    placeholder="Optional"
                    value={editForm.early_timeout_minutes}
                    onChange={(e) => setEditForm((f) => ({ ...f, early_timeout_minutes: e.target.value }))}
                    className="h-11 min-h-11"
                  />
                </div>
              </div>
              <div className="flex flex-col gap-3 rounded-lg border border-border/50 bg-background/80 px-3 py-3 min-[400px]:flex-row min-[400px]:items-center min-[400px]:justify-between dark:bg-background/40">
                <div className="min-w-0">
                  <p className="text-sm font-medium">Night differential preview</p>
                  <p className="text-xs text-muted-foreground">Highlight DOLE 22:00–06:00 exposure in the summary bar.</p>
                </div>
                <Checkbox
                  checked={ndPreview}
                  onCheckedChange={(c) => setNdPreview(c === true)}
                  aria-label="Toggle ND preview"
                  className="size-5"
                />
              </div>
              <div className="space-y-2">
                <div>
                  <Label className="text-sm font-medium">Days off (weekly)</Label>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Highlighted = no shift that day. Unhighlighted = working day. Default is{' '}
                    <span className="font-medium text-foreground">Sunday only</span> (add Sat or others if needed).
                  </p>
                </div>
                <div className="flex flex-wrap gap-2" role="group" aria-label="Days off each week">
                  {DAY_OPTIONS.map((d) => {
                    const isOff = editForm.rest_days?.includes(d.key)
                    return (
                      <button
                        key={d.key}
                        type="button"
                        onClick={() =>
                          setEditForm((f) => ({ ...f, rest_days: toggleRestDay(f.rest_days, d.key) }))
                        }
                        className={cn(
                          'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full border text-sm font-medium transition-colors',
                          isOff
                            ? 'border-primary bg-primary/15 text-foreground shadow-sm'
                            : 'border-border bg-background text-muted-foreground hover:bg-muted/60'
                        )}
                        title={`${d.full}: ${isOff ? 'day off (no shift)' : 'working day'}`}
                        aria-pressed={isOff}
                      >
                        {d.label}
                      </button>
                    )
                  })}
                </div>
              </div>
              <div className="rounded-lg border border-dashed border-border/60 bg-muted/20 px-3 py-2 text-[11px] text-muted-foreground">
                <p className="font-medium text-foreground">Keyboard tips</p>
                <ul className="mt-1 list-inside list-disc space-y-0.5">
                  <li>Paint shift: drag on the grid (work days only)</li>
                  <li>Clear column: Shift + X (coming soon)</li>
                </ul>
              </div>
            </div>

            <div className="space-y-4 min-w-0">
              <ScheduleWeeklyGrid
                timeIn={editForm.time_in}
                timeOut={editForm.time_out}
                restDays={editForm.rest_days}
                breakStart={editForm.break_start}
                breakEnd={editForm.break_end}
                onShiftChange={(tin, tout) => {
                  setEditForm((f) => ({ ...f, time_in: tin, time_out: tout }))
                }}
              />
              <ScheduleComplianceBar
                weeklyHours={wh}
                ndHoursPerWeek={ndh}
                otRisk={risk}
                restOk={restOk}
                onValidate={() => {
                  toast.message('Preview OK', {
                    description: restOk
                      ? 'Weekly rest rule and hours look reasonable.'
                      : 'Add at least one rest day per week (DOLE practice).',
                  })
                }}
              />
            </div>
          </div>

          {error && (
            <div className="mt-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
              {error}
            </div>
          )}
          </div>

          {/* flex-col until md: avoids clipping when Cancel + Create sit side-by-side on narrow widths */}
          <div className="flex w-full min-w-0 shrink-0 flex-col gap-2 border-t border-border/60 bg-muted/30 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] @sm:px-6 md:flex-row md:justify-end md:gap-3">
            <Button
              type="button"
              variant="outline"
              className="h-11 w-full min-w-0 md:w-auto"
              onClick={() => onOpenChange(false)}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              className="h-11 w-full min-w-0 md:w-auto"
              disabled={submitting}
            >
              {submitting ? (
                <Loader2 className="size-4 shrink-0 animate-spin" />
              ) : editingSchedule ? (
                'Save schedule'
              ) : (
                'Create schedule'
              )}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
