import { useState, useMemo } from 'react'
import { Calendar, Loader2, Plus, Trash2, Info } from 'lucide-react'
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
  SHIFT_TYPES,
  hasWeeklyRestDay,
  otRiskLevel,
  weeklyNdHours,
  weeklyScheduledHours,
  computePaidMinutes,
  halfDayThresholdMinutes,
  formatPaidHours,
  detectCrossesMidnight,
} from '@/lib/scheduleLib'
import { formatShiftRange12h, toHhMm } from '@/lib/timeFormat'
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
  title,
  description,
  submitLabel,
  headerExtra,
  readOnly = false,
  secondaryAction,
}) {
  const [ndPreview, setNdPreview] = useState(true)
  const [showAdvanced, setShowAdvanced] = useState(false)

  function handleOpenChange(nextOpen) {
    if (!nextOpen) {
      setNdPreview(true)
      setShowAdvanced(false)
    }
    onOpenChange(nextOpen)
  }

  const shiftType = editForm.shift_type || 'fixed'
  const isFlexible = shiftType === 'flexible'
  const isSplit = shiftType === 'split'
  const isOvernight = shiftType === 'overnight'

  const preview = {
    time_in: toHhMm(editForm.time_in) || editForm.time_in,
    time_out: toHhMm(editForm.time_out) || editForm.time_out,
    break_start: editForm.break_start ? toHhMm(editForm.break_start) : null,
    break_end: editForm.break_end ? toHhMm(editForm.break_end) : null,
    breaks: editForm.breaks || [],
    work_blocks: editForm.work_blocks || [],
    rest_days: editForm.rest_days || [],
    shift_type: shiftType,
    expected_paid_minutes: editForm.expected_paid_minutes,
    half_day_threshold_minutes: editForm.half_day_threshold_minutes,
    flexible_required_minutes: editForm.flexible_required_minutes,
  }

  const paidMinutes = computePaidMinutes(preview)
  const halfDayThresh = halfDayThresholdMinutes(preview)
  const wh = weeklyScheduledHours(preview)
  const ndh = ndPreview ? weeklyNdHours(preview) : 0
  const risk = otRiskLevel(preview)
  const restOk = hasWeeklyRestDay(preview)
  const crossesMidnight = detectCrossesMidnight(editForm.time_in, editForm.time_out) || isOvernight

  function addBreak() {
    setEditForm((f) => ({
      ...f,
      breaks: [...(f.breaks || []), { start: '', end: '', is_paid: false }],
    }))
  }

  function updateBreak(idx, field, value) {
    setEditForm((f) => {
      const breaks = [...(f.breaks || [])]
      breaks[idx] = { ...breaks[idx], [field]: value }
      return { ...f, breaks }
    })
  }

  function removeBreak(idx) {
    setEditForm((f) => ({
      ...f,
      breaks: (f.breaks || []).filter((_, i) => i !== idx),
    }))
  }

  function addWorkBlock() {
    setEditForm((f) => ({
      ...f,
      work_blocks: [...(f.work_blocks || []), { start: '', end: '' }],
    }))
  }

  function updateWorkBlock(idx, field, value) {
    setEditForm((f) => {
      const blocks = [...(f.work_blocks || [])]
      blocks[idx] = { ...blocks[idx], [field]: value }
      return { ...f, work_blocks: blocks }
    })
  }

  function removeWorkBlock(idx) {
    setEditForm((f) => ({
      ...f,
      work_blocks: (f.work_blocks || []).filter((_, i) => i !== idx),
    }))
  }

  const showTimeInOut = !isSplit
  const showBreakStartEnd = !isSplit && !isFlexible
  const showWorkBlocks = isSplit
  const showFlexibleFields = isFlexible

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
          <DialogHeader className="shrink-0 border-b border-border/60 bg-muted/30 px-4 py-4 pr-12 text-left @sm:px-6 @sm:py-5 @sm:pr-14">
            <DialogTitle className="flex items-start gap-2.5 text-lg font-semibold tracking-tight @sm:items-center @sm:text-xl">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Calendar className="size-5" aria-hidden />
              </span>
              <span className="min-w-0 leading-snug">
                {title ?? (editingSchedule ? 'Edit work schedule' : 'New work schedule')}
              </span>
            </DialogTitle>
            <DialogDescription className="max-w-3xl text-xs leading-relaxed text-muted-foreground @sm:text-sm">
              {description ?? (
                <>
                  Flexible schedule template. Supports fixed, overnight, split, flexible, rotating, and compressed shifts
                  with multiple breaks and custom paid hours.
                </>
              )}
            </DialogDescription>
            {headerExtra}
          </DialogHeader>

          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6">
          <div className="grid gap-6 @xl:grid-cols-[minmax(280px,400px)_1fr]">
            <div className="space-y-5 rounded-xl border border-border/60 bg-muted/25 p-4 dark:bg-muted/15">
              {/* Schedule name */}
              <div className="space-y-1.5">
                <Label htmlFor="schedule-name">Schedule name</Label>
                <Input
                  id="schedule-name"
                  value={editForm.name}
                  onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                  placeholder="e.g. Night Shift – Production"
                  className="h-11 min-h-11"
                  required
                  readOnly={readOnly}
                  disabled={readOnly}
                />
              </div>

              {/* Shift type selector */}
              <div className="space-y-1.5">
                <Label htmlFor="shift-type">Shift type</Label>
                <select
                  id="shift-type"
                  value={shiftType}
                  onChange={(e) => setEditForm((f) => ({ ...f, shift_type: e.target.value }))}
                  className="flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  disabled={readOnly}
                >
                  {SHIFT_TYPES.map((st) => (
                    <option key={st.value} value={st.value}>{st.label}</option>
                  ))}
                </select>
              </div>

              {/* Schedule code */}
              <div className="space-y-1.5">
                <Label htmlFor="schedule-code">Schedule code <span className="text-muted-foreground text-xs">(optional)</span></Label>
                <Input
                  id="schedule-code"
                  value={editForm.schedule_code || ''}
                  onChange={(e) => setEditForm((f) => ({ ...f, schedule_code: e.target.value }))}
                  placeholder="e.g. NS-01"
                  className="h-11 min-h-11"
                  maxLength={32}
                  readOnly={readOnly}
                  disabled={readOnly}
                />
              </div>

              {/* Time in / out (not for split) */}
              {showTimeInOut && (
                <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="time-in">{isFlexible ? 'Earliest clock-in' : 'Time in'}</Label>
                    <Input
                      id="time-in"
                      type="time"
                      value={editForm.time_in}
                      onChange={(e) => setEditForm((f) => ({ ...f, time_in: e.target.value }))}
                      className="h-11 min-h-11"
                      required={!isSplit}
                      readOnly={readOnly}
                      disabled={readOnly}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="time-out">{isFlexible ? 'Latest clock-out' : 'Time out'}</Label>
                    <Input
                      id="time-out"
                      type="time"
                      value={editForm.time_out}
                      onChange={(e) => setEditForm((f) => ({ ...f, time_out: e.target.value }))}
                      className="h-11 min-h-11"
                      required={!isSplit}
                      readOnly={readOnly}
                      disabled={readOnly}
                    />
                  </div>
                </div>
              )}

              {/* Crosses midnight indicator */}
              {crossesMidnight && !isSplit && (
                <div className="flex items-center gap-2 rounded-md border border-amber-300/50 bg-amber-50/80 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300">
                  <Info className="size-4 shrink-0" />
                  <span>Overnight shift detected — shift crosses midnight</span>
                </div>
              )}

              {/* Flexible shift fields */}
              {showFlexibleFields && (
                <div className="space-y-3 rounded-lg border border-border/50 bg-background/60 p-3 dark:bg-background/30">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Flexible shift settings</p>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                      <Label htmlFor="flex-required">Required hours/day</Label>
                      <Input
                        id="flex-required"
                        type="number"
                        min={0}
                        max={24}
                        step={0.5}
                        value={editForm.flexible_required_minutes ? (Number(editForm.flexible_required_minutes) / 60) : ''}
                        onChange={(e) => setEditForm((f) => ({ ...f, flexible_required_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))}
                        placeholder="8"
                        className="h-11 min-h-11"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label>Core hours <span className="text-muted-foreground text-xs">(optional)</span></Label>
                      <div className="flex gap-1.5">
                        <Input
                          type="time"
                          value={editForm.core_hours_start || ''}
                          onChange={(e) => setEditForm((f) => ({ ...f, core_hours_start: e.target.value }))}
                          className="h-11 min-h-11 flex-1"
                          placeholder="10:00"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                        <Input
                          type="time"
                          value={editForm.core_hours_end || ''}
                          onChange={(e) => setEditForm((f) => ({ ...f, core_hours_end: e.target.value }))}
                          className="h-11 min-h-11 flex-1"
                          placeholder="15:00"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* Work blocks for split shift */}
              {showWorkBlocks && (
                <div className="space-y-3 rounded-lg border border-border/50 bg-background/60 p-3 dark:bg-background/30">
                  <div className="flex items-center justify-between">
                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Work blocks</p>
                    {!readOnly && (
                      <Button type="button" variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={addWorkBlock}>
                        <Plus className="size-3" /> Add block
                      </Button>
                    )}
                  </div>
                  {(editForm.work_blocks || []).length === 0 && (
                    <p className="text-xs text-muted-foreground italic">No work blocks defined. Add at least two blocks for a split shift.</p>
                  )}
                  {(editForm.work_blocks || []).map((block, i) => (
                    <div key={i} className="flex items-end gap-2">
                      <div className="flex-1 space-y-1">
                        <Label className="text-xs">Block {i + 1} start</Label>
                        <Input
                          type="time"
                          value={block.start || ''}
                          onChange={(e) => updateWorkBlock(i, 'start', e.target.value)}
                          className="h-10 min-h-10"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                      </div>
                      <div className="flex-1 space-y-1">
                        <Label className="text-xs">Block {i + 1} end</Label>
                        <Input
                          type="time"
                          value={block.end || ''}
                          onChange={(e) => updateWorkBlock(i, 'end', e.target.value)}
                          className="h-10 min-h-10"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                      </div>
                      {!readOnly && (
                        <Button type="button" variant="ghost" size="icon" className="size-10 text-destructive/70 hover:text-destructive" onClick={() => removeWorkBlock(i)}>
                          <Trash2 className="size-4" />
                        </Button>
                      )}
                    </div>
                  ))}
                </div>
              )}

              {/* Legacy break start/end (for fixed, overnight, compressed, rotating) */}
              {showBreakStartEnd && (
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="break-start">Break start</Label>
                    <Input
                      id="break-start"
                      type="time"
                      value={editForm.break_start}
                      onChange={(e) => setEditForm((f) => ({ ...f, break_start: e.target.value }))}
                      className="h-11 min-h-11"
                      readOnly={readOnly}
                      disabled={readOnly}
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
                      readOnly={readOnly}
                      disabled={readOnly}
                    />
                  </div>
                </div>
              )}

              {/* Multiple breaks section */}
              <div className="space-y-3 rounded-lg border border-border/50 bg-background/60 p-3 dark:bg-background/30">
                <div className="flex items-center justify-between">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Additional breaks</p>
                  {!readOnly && (
                    <Button type="button" variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={addBreak}>
                      <Plus className="size-3" /> Add break
                    </Button>
                  )}
                </div>
                {(editForm.breaks || []).length === 0 && (
                  <p className="text-xs text-muted-foreground italic">No additional breaks. Use the fields above for a single break, or add multiple breaks here.</p>
                )}
                {(editForm.breaks || []).map((br, i) => (
                  <div key={i} className="flex items-end gap-2">
                    <div className="flex-1 space-y-1">
                      <Label className="text-xs">Start</Label>
                      <Input
                        type="time"
                        value={br.start || ''}
                        onChange={(e) => updateBreak(i, 'start', e.target.value)}
                        className="h-10 min-h-10"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    </div>
                    <div className="flex-1 space-y-1">
                      <Label className="text-xs">End</Label>
                      <Input
                        type="time"
                        value={br.end || ''}
                        onChange={(e) => updateBreak(i, 'end', e.target.value)}
                        className="h-10 min-h-10"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    </div>
                    <div className="flex items-center gap-1.5 pb-1">
                      <Checkbox
                        checked={!!br.is_paid}
                        onCheckedChange={(c) => updateBreak(i, 'is_paid', c === true)}
                        disabled={readOnly}
                        className="size-4"
                      />
                      <Label className="text-xs text-muted-foreground">Paid</Label>
                    </div>
                    {!readOnly && (
                      <Button type="button" variant="ghost" size="icon" className="size-10 text-destructive/70 hover:text-destructive" onClick={() => removeBreak(i)}>
                        <Trash2 className="size-4" />
                      </Button>
                    )}
                  </div>
                ))}
              </div>

              {/* Paid hours summary */}
              <div className="flex items-center gap-3 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5 dark:bg-primary/10">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium">Paid hours: <span className="text-primary">{formatPaidHours(paidMinutes)}</span></p>
                  <p className="text-xs text-muted-foreground">Half-day threshold: {formatPaidHours(halfDayThresh)}</p>
                </div>
              </div>

              {/* Expected paid minutes override */}
              <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="expected-paid">Expected paid hours <span className="text-muted-foreground text-xs">(override)</span></Label>
                  <Input
                    id="expected-paid"
                    type="number"
                    min={0}
                    max={24}
                    step={0.5}
                    value={editForm.expected_paid_minutes ? (Number(editForm.expected_paid_minutes) / 60) : ''}
                    onChange={(e) => setEditForm((f) => ({ ...f, expected_paid_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))}
                    placeholder="Auto"
                    className="h-11 min-h-11"
                    readOnly={readOnly}
                    disabled={readOnly}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="half-day-thresh">Half-day threshold <span className="text-muted-foreground text-xs">(hours)</span></Label>
                  <Input
                    id="half-day-thresh"
                    type="number"
                    min={0}
                    max={12}
                    step={0.25}
                    value={editForm.half_day_threshold_minutes ? (Number(editForm.half_day_threshold_minutes) / 60) : ''}
                    onChange={(e) => setEditForm((f) => ({ ...f, half_day_threshold_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))}
                    placeholder="Auto (50%)"
                    className="h-11 min-h-11"
                    readOnly={readOnly}
                    disabled={readOnly}
                  />
                </div>
              </div>

              {/* Grace / early time-in */}
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
                    readOnly={readOnly}
                    disabled={readOnly}
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
                    readOnly={readOnly}
                    disabled={readOnly}
                  />
                </div>
              </div>

              {/* Overtime buffer */}
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
                  readOnly={readOnly}
                  disabled={readOnly}
                />
              </div>

              {/* Advanced fields toggle */}
              <button
                type="button"
                className="text-xs text-primary underline decoration-primary/30 hover:decoration-primary"
                onClick={() => setShowAdvanced(!showAdvanced)}
              >
                {showAdvanced ? 'Hide advanced settings' : 'Show advanced settings'}
              </button>

              {showAdvanced && (
                <div className="space-y-3 rounded-lg border border-dashed border-border/60 bg-muted/10 p-3">
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
                        readOnly={readOnly}
                        disabled={readOnly}
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
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    </div>
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="description">Description</Label>
                    <textarea
                      id="description"
                      value={editForm.description || ''}
                      onChange={(e) => setEditForm((f) => ({ ...f, description: e.target.value }))}
                      placeholder="Optional notes about this schedule template"
                      className="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                      maxLength={1000}
                      readOnly={readOnly}
                      disabled={readOnly}
                    />
                  </div>
                </div>
              )}

              {/* ND preview checkbox */}
              <div className="flex flex-col gap-3 rounded-lg border border-border/50 bg-background/80 px-3 py-3 min-[400px]:flex-row min-[400px]:items-center min-[400px]:justify-between dark:bg-background/40">
                <div className="min-w-0">
                  <p className="text-sm font-medium">Night differential preview</p>
                  <p className="text-xs text-muted-foreground">Highlight DOLE {formatShiftRange12h('22:00', '06:00')} exposure in the summary bar.</p>
                </div>
                <Checkbox
                  checked={ndPreview}
                  onCheckedChange={(c) => setNdPreview(c === true)}
                  aria-label="Toggle ND preview"
                  className="size-5"
                  disabled={readOnly}
                />
              </div>

              {/* Days off */}
              <div className="space-y-2">
                <div>
                  <Label className="text-sm font-medium">Days off (weekly)</Label>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Highlighted = no shift that day. Default is{' '}
                    <span className="font-medium text-foreground">Sunday only</span>.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2" role="group" aria-label="Days off each week">
                  {DAY_OPTIONS.map((d) => {
                    const isOff = editForm.rest_days?.includes(d.key)
                    return (
                      <button
                        key={d.key}
                        type="button"
                        disabled={readOnly}
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
            </div>

            <div className="space-y-4 min-w-0">
              <ScheduleWeeklyGrid
                timeIn={editForm.time_in}
                timeOut={editForm.time_out}
                restDays={editForm.rest_days}
                breakStart={editForm.break_start}
                breakEnd={editForm.break_end}
                onShiftChange={
                  readOnly
                    ? undefined
                    : (tin, tout) => {
                        setEditForm((f) => ({ ...f, time_in: tin, time_out: tout }))
                      }
                }
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

          <div className="flex w-full min-w-0 shrink-0 flex-col gap-2 border-t border-border/60 bg-muted/30 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] @sm:px-6 md:flex-row md:flex-wrap md:justify-end md:gap-3">
            <Button
              type="button"
              variant="outline"
              className="h-11 w-full min-w-0 md:w-auto"
              onClick={() => onOpenChange(false)}
            >
              Cancel
            </Button>
            {secondaryAction ? (
              <Button
                type="button"
                variant={secondaryAction.variant || 'outline'}
                className={cn('h-11 w-full min-w-0 md:w-auto', secondaryAction.className)}
                disabled={secondaryAction.disabled || submitting}
                onClick={secondaryAction.onClick}
              >
                {secondaryAction.label}
              </Button>
            ) : null}
            <Button
              type="submit"
              className="h-11 w-full min-w-0 md:w-auto"
              disabled={submitting}
            >
              {submitting ? (
                <Loader2 className="size-4 shrink-0 animate-spin" />
              ) : (
                submitLabel ?? (editingSchedule ? 'Save schedule' : 'Create schedule')
              )}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
