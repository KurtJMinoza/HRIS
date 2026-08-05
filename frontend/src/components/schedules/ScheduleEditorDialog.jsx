import { useState } from 'react'
import { AlarmClock, ArrowRight, Calendar, CalendarDays, ChevronDown, Clock3, ExternalLink, Loader2, Plus, Trash2, Info } from 'lucide-react'
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
  FlexibleScheduleTable,
  flexibleDaysToRestDays,
  flexiblePreviewSchedule,
} from '@/components/schedules/FlexibleScheduleTable'
import { createDefaultFlexibleDays } from '@/lib/workScheduleForm'
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

const SCHEDULE_EDITOR_SHIFT_TYPES = SHIFT_TYPES.filter((type) => (
  type.value === 'fixed' || type.value === 'flexible'
))

function toggleRestDay(restDays, dayKey) {
  const current = new Set(restDays || [])
  if (current.has(dayKey)) current.delete(dayKey)
  else current.add(dayKey)
  return Array.from(current)
}

function formatSummaryHours(hours) {
  const minutes = Math.round((Number(hours) || 0) * 60)
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${h}h ${String(m).padStart(2, '0')}m`
}

function formatClockPadded(value) {
  const hhmm = toHhMm(value)
  if (!hhmm) return ''
  const [hRaw, m] = hhmm.split(':')
  const hour24 = Number(hRaw)
  const period = hour24 >= 12 ? 'PM' : 'AM'
  const hour12 = hour24 % 12 || 12
  return `${String(hour12).padStart(2, '0')}:${m} ${period}`
}

function minutesBetween(start, end) {
  const a = toHhMm(start)
  const b = toHhMm(end)
  if (!a || !b) return 0
  const [ah, am] = a.split(':').map(Number)
  const [bh, bm] = b.split(':').map(Number)
  let diff = (bh * 60 + bm) - (ah * 60 + am)
  if (diff < 0) diff += 1440
  return diff
}

function formatMinutesPadded(minutes) {
  const total = Math.max(0, Math.round(Number(minutes) || 0))
  const h = Math.floor(total / 60)
  const m = total % 60
  return `${h}h ${String(m).padStart(2, '0')}m`
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
  const isFixedWizard = !isFlexible

  const flexibleRestDays = isFlexible ? flexibleDaysToRestDays(editForm.days) : (editForm.rest_days || [])
  const previewSource = isFlexible
    ? flexiblePreviewSchedule(editForm.days, editForm)
    : editForm

  const preview = {
    time_in: toHhMm(previewSource.time_in) || previewSource.time_in,
    time_out: toHhMm(previewSource.time_out) || previewSource.time_out,
    break_start: previewSource.break_start ? toHhMm(previewSource.break_start) : null,
    break_end: previewSource.break_end ? toHhMm(previewSource.break_end) : null,
    breaks: previewSource.breaks || [],
    work_blocks: previewSource.work_blocks || [],
    rest_days: flexibleRestDays,
    shift_type: shiftType,
    expected_paid_minutes: previewSource.expected_paid_minutes,
    half_day_threshold_minutes: previewSource.half_day_threshold_minutes,
    flexible_required_minutes: previewSource.flexible_required_minutes,
  }

  const flexibleDaySchedules = isFlexible
    ? Object.fromEntries(
      (editForm.days || [])
        .filter((day) => day.is_working_day)
        .map((day) => [day.day_of_week, {
          time_in: day.time_in,
          time_out: day.time_out,
          break_start: day.break_start,
          break_end: day.break_end,
        }]),
    )
    : null

  const paidMinutes = computePaidMinutes(preview)
  const halfDayThresh = halfDayThresholdMinutes(preview)
  const wh = weeklyScheduledHours(preview)
  const ndh = ndPreview ? weeklyNdHours(preview) : 0
  const risk = otRiskLevel(preview)
  const restOk = hasWeeklyRestDay(preview)
  const crossesMidnight = detectCrossesMidnight(editForm.time_in, editForm.time_out) || isOvernight
  const flexibleWorkingDayCount = isFlexible
    ? (editForm.days || []).filter((day) => day.is_working_day).length
    : 0
  const breakDurationMinutes = minutesBetween(editForm.break_start, editForm.break_end)

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

  const showTimeInOut = !isSplit && !isFlexible
  const showBreakStartEnd = !isSplit && !isFlexible
  const showWorkBlocks = isSplit

  function handleShiftTypeChange(nextType) {
    setEditForm((f) => {
      const next = { ...f, shift_type: nextType }
      if (nextType === 'flexible' && (!Array.isArray(f.days) || f.days.length === 0)) {
        next.days = createDefaultFlexibleDays(Number(f.grace_period_minutes) || 5)
      }
      return next
    })
  }

  const fixedWizardContent = (
    <div className="grid min-h-full grid-cols-[294px_1fr] bg-[#f8f9fb]">
      <aside className="border-r border-[#e0e3e8] bg-white px-6 py-8">
        {[
          ['1', 'Schedule details', 'Basic information', true],
          ['2', 'Time settings', 'Shift and break time', false],
          ['3', 'Weekly schedule', 'Set working days', false],
          ['4', 'Advanced options', 'Overtime and more', false],
          ['5', 'Review & summary', 'Confirm schedule', false],
        ].map(([step, stepTitle, stepCaption, active], index, steps) => (
          <div key={step} className="relative flex gap-4 pb-8 last:pb-0">
            {index < steps.length - 1 && (
              <span className="absolute left-[15px] top-8 h-[calc(100%-2rem)] w-px bg-[#d9dee7]" />
            )}
            <span
              className={cn(
                'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border text-[14px] font-semibold',
                active
                  ? 'border-[#ff5a10] bg-[#ff5a10] text-white shadow-[0_0_0_12px_rgba(255,90,16,0.08)]'
                  : 'border-[#9aa3b2] bg-white text-[#344052]'
              )}
            >
              {step}
            </span>
            <div className={cn('min-w-0 rounded-md px-3 py-1.5', active && 'bg-[#fff7f1]')}>
              <p className={cn('text-[14px] font-semibold', active ? 'text-[#ff5a10]' : 'text-[#2f3746]')}>{stepTitle}</p>
              <p className="mt-1 text-[13px] text-[#596273]">{stepCaption}</p>
            </div>
            {active && <span className="absolute -left-6 top-0 h-[74px] w-1 rounded-r bg-[#ff5a10]" />}
            {active && <span className="absolute right-[-25px] top-0 h-[74px] w-1 rounded-l bg-[#ff5a10]" />}
          </div>
        ))}
      </aside>

      <main className="space-y-5 px-6 py-7">
        <section className="rounded-lg border border-[#e0e3e8] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.03)]">
          <h3 className="text-[20px] font-semibold text-[#111827]">Schedule details</h3>
          <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_1.1fr_1px_1fr]">
            <label className="space-y-3">
              <span className="text-[14px] font-medium text-[#111827]">Schedule name <span className="text-[#ff5a10]">*</span></span>
              <Input
                id="schedule-name"
                value={editForm.name}
                onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                placeholder="Night Shift - Production"
                className="h-12 rounded-md border-[#d7dce4] bg-white px-4 text-[14px] shadow-none"
                required
                readOnly={readOnly}
                disabled={readOnly}
              />
            </label>
            <label className="space-y-3">
              <span className="text-[14px] font-medium text-[#111827]">Shift type <span className="text-[#ff5a10]">*</span></span>
              <div className="grid h-12 grid-cols-2 overflow-hidden rounded-md border border-[#d7dce4] bg-white">
                <button
                  type="button"
                  onClick={() => handleShiftTypeChange('fixed')}
                  disabled={readOnly}
                  className="flex items-center justify-center gap-3 border-r border-[#ffd0b8] bg-[#fff2ea] text-[14px] font-semibold text-[#ff5a10] shadow-[inset_0_0_0_1px_#ff9a66]"
                >
                  <Calendar className="size-4" />
                  Fixed Shift
                </button>
                <button
                  type="button"
                  onClick={() => handleShiftTypeChange('flexible')}
                  disabled={readOnly}
                  className="flex items-center justify-center gap-3 text-[14px] font-semibold text-[#596273] hover:bg-[#fafafa]"
                >
                  <Clock3 className="size-4 text-[#6f7785]" />
                  Flexible Shift
                </button>
              </div>
            </label>
            <div className="hidden bg-[#dfe3e8] lg:block" />
            <label className="space-y-3">
              <span className="text-[14px] font-medium text-[#111827]">Schedule code <span className="font-normal text-[#667085]">(optional)</span></span>
              <Input
                id="schedule-code"
                value={editForm.schedule_code || ''}
                onChange={(e) => setEditForm((f) => ({ ...f, schedule_code: e.target.value }))}
                placeholder="NS-01"
                className="h-12 rounded-md border-[#d7dce4] bg-white px-4 text-[14px] shadow-none"
                maxLength={32}
                readOnly={readOnly}
                disabled={readOnly}
              />
              <p className="text-[13px] text-[#596273]">A unique code to easily identify this schedule.</p>
            </label>
          </div>
        </section>

        <section className="rounded-lg border border-[#e0e3e8] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.03)]">
          <h3 className="text-[20px] font-semibold text-[#111827]">Time settings</h3>
          <div className="mt-6 grid gap-5 xl:grid-cols-[1fr_1fr_1.12fr]">
            <label className="space-y-2">
              <span className="text-[14px] font-medium text-[#111827]">Time in <span className="text-[#ff5a10]">*</span></span>
              <div className="relative">
                <Input id="time-in" type="time" value={editForm.time_in} onChange={(e) => setEditForm((f) => ({ ...f, time_in: e.target.value }))} className="h-12 rounded-md border-[#d7dce4] bg-white px-4 pr-10 text-[14px] shadow-none [color-scheme:light]" required readOnly={readOnly} disabled={readOnly} />
                <Clock3 className="pointer-events-none absolute right-4 top-1/2 size-4 -translate-y-1/2 text-[#111827]" />
              </div>
            </label>
            <label className="space-y-2">
              <span className="text-[14px] font-medium text-[#111827]">Time out <span className="text-[#ff5a10]">*</span></span>
              <div className="relative">
                <Input id="time-out" type="time" value={editForm.time_out} onChange={(e) => setEditForm((f) => ({ ...f, time_out: e.target.value }))} className="h-12 rounded-md border-[#d7dce4] bg-white px-4 pr-10 text-[14px] shadow-none [color-scheme:light]" required readOnly={readOnly} disabled={readOnly} />
                <Clock3 className="pointer-events-none absolute right-4 top-1/2 size-4 -translate-y-1/2 text-[#111827]" />
              </div>
            </label>
            <div className="flex h-[72px] items-center gap-4 self-end rounded-lg border border-[#ffd7bd] bg-[#fff9f4] px-5">
              <span className="flex size-11 items-center justify-center rounded-full bg-[#fff0e7] text-[#ff5a10]">
                <Clock3 className="size-6" />
              </span>
              <div>
                <p className="text-[14px] font-medium text-[#344052]">Paid hours</p>
                <p className="text-[20px] font-semibold text-[#111827]">{formatMinutesPadded(paidMinutes)}</p>
              </div>
            </div>
            <label className="space-y-2">
              <span className="text-[14px] font-medium text-[#111827]">Break start</span>
              <div className="relative">
                <Input id="break-start" type="time" value={editForm.break_start} onChange={(e) => setEditForm((f) => ({ ...f, break_start: e.target.value }))} className="h-12 rounded-md border-[#d7dce4] bg-white px-4 pr-10 text-[14px] shadow-none [color-scheme:light]" readOnly={readOnly} disabled={readOnly} />
                <Clock3 className="pointer-events-none absolute right-4 top-1/2 size-4 -translate-y-1/2 text-[#111827]" />
              </div>
            </label>
            <label className="space-y-2">
              <span className="text-[14px] font-medium text-[#111827]">Break end</span>
              <div className="relative">
                <Input id="break-end" type="time" value={editForm.break_end} onChange={(e) => setEditForm((f) => ({ ...f, break_end: e.target.value }))} className="h-12 rounded-md border-[#d7dce4] bg-white px-4 pr-10 text-[14px] shadow-none [color-scheme:light]" readOnly={readOnly} disabled={readOnly} />
                <Clock3 className="pointer-events-none absolute right-4 top-1/2 size-4 -translate-y-1/2 text-[#111827]" />
              </div>
            </label>
            <label className="space-y-2">
              <span className="text-[14px] font-medium text-[#111827]">Break duration</span>
              <div className="flex h-12 items-center rounded-md border border-[#d7dce4] bg-[#f9fafb] px-4 text-[14px] text-[#344052]">{formatMinutesPadded(breakDurationMinutes)}</div>
            </label>
          </div>

          <div className="mt-5 rounded-lg border border-[#e0e3e8] bg-white p-3">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-[15px] font-semibold text-[#111827]">Additional breaks</p>
                <p className="mt-1 text-[13px] text-[#596273]">Add one or more breaks if applicable.</p>
              </div>
              <Button type="button" variant="outline" className="h-9 gap-2 rounded-md border-[#ff9a66] bg-white px-4 text-[13px] font-semibold text-[#ff5a10]" onClick={addBreak} disabled={readOnly}>
                <Plus className="size-4" />
                Add break
              </Button>
            </div>
            <div className="mt-4 overflow-hidden rounded-lg border border-[#e6e9ef]">
              <div className="grid grid-cols-[1.4fr_1fr_1fr_1fr_1fr_.8fr] bg-[#fbfcfd] px-4 py-3 text-[13px] font-semibold text-[#344052]">
                <span>Break name</span>
                <span>Start time</span>
                <span>End time</span>
                <span>Duration</span>
                <span>Paid/Unpaid</span>
                <span>Actions</span>
              </div>
              <div className="grid grid-cols-[1.4fr_1fr_1fr_1fr_1fr_.8fr] items-center border-t border-[#e6e9ef] px-4 py-2 text-[14px] text-[#111827]">
                <span>Meal Break</span>
                <div className="relative w-[150px]">
                  <Input type="time" value={editForm.break_start} onChange={(e) => setEditForm((f) => ({ ...f, break_start: e.target.value }))} className="h-9 rounded-md border-[#d7dce4] bg-white pr-8 text-[13px] shadow-none [color-scheme:light]" readOnly={readOnly} disabled={readOnly} />
                  <Clock3 className="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2" />
                </div>
                <div className="relative w-[150px]">
                  <Input type="time" value={editForm.break_end} onChange={(e) => setEditForm((f) => ({ ...f, break_end: e.target.value }))} className="h-9 rounded-md border-[#d7dce4] bg-white pr-8 text-[13px] shadow-none [color-scheme:light]" readOnly={readOnly} disabled={readOnly} />
                  <Clock3 className="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2" />
                </div>
                <span>{formatMinutesPadded(breakDurationMinutes)}</span>
                <span className="w-fit rounded-full bg-[#dff7eb] px-4 py-1 text-[12px] font-semibold text-[#14945b]">Paid</span>
                <div className="flex items-center gap-5">
                  <ChevronDown className="size-4 text-[#111827]" />
                  <Trash2 className="size-4 text-[#ff2f45]" />
                </div>
              </div>
            </div>
          </div>
        </section>

        <div className="grid gap-5 xl:grid-cols-[.92fr_1fr]">
          <section className="rounded-lg border border-[#e0e3e8] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.03)]">
            <h3 className="text-[20px] font-semibold text-[#111827]">Week settings</h3>
            <div className="mt-6">
              <p className="text-[14px] font-medium text-[#111827]">Days off (weekly)</p>
              <p className="mt-1 text-[13px] text-[#596273]">Select the days that are non-working days.</p>
              <div className="mt-5 flex flex-wrap gap-3">
                {DAY_OPTIONS.map((d) => {
                  const isOff = editForm.rest_days?.includes(d.key)
                  return (
                    <button
                      key={d.key}
                      type="button"
                      disabled={readOnly}
                      onClick={() => setEditForm((f) => ({ ...f, rest_days: toggleRestDay(f.rest_days, d.key) }))}
                      className={cn(
                        'flex h-11 min-w-14 items-center justify-center rounded-md border px-4 text-[14px] font-medium transition-colors',
                        isOff
                          ? 'border-[#ff8a4a] bg-[#fff2ea] text-[#ff5a10]'
                          : 'border-[#d7dce4] bg-white text-[#344052] hover:bg-[#fafafa]'
                      )}
                    >
                      {d.full.slice(0, 3)}
                    </button>
                  )
                })}
              </div>
            </div>
          </section>

          <section className="rounded-lg border border-[#e0e3e8] bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.03)]">
            <h3 className="text-[20px] font-semibold text-[#111827]">Summary</h3>
            <div className="mt-4 grid gap-5 lg:grid-cols-2">
              <div className="space-y-4">
                {[
                  ['Shift type', 'Fixed Shift'],
                  ['Paid hours', formatMinutesPadded(paidMinutes)],
                  ['Half-day threshold', formatMinutesPadded(halfDayThresh)],
                ].map(([label, value]) => (
                  <div key={label} className="flex items-center gap-4">
                    <span className="flex size-10 items-center justify-center rounded-md border border-[#e0e3e8] bg-white text-[#344052]">
                      <CalendarDays className="size-4" />
                    </span>
                    <div>
                      <p className="text-[13px] text-[#596273]">{label}</p>
                      <p className="text-[14px] font-semibold text-[#111827]">{value}</p>
                    </div>
                  </div>
                ))}
              </div>
              <div className="space-y-4 border-t border-[#e0e3e8] pt-4 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                {[
                  ['Time in', formatClockPadded(editForm.time_in)],
                  ['Time out', formatClockPadded(editForm.time_out)],
                  ['Break duration', formatMinutesPadded(breakDurationMinutes)],
                ].map(([label, value]) => (
                  <div key={label} className="flex items-center gap-4">
                    <span className="flex size-10 items-center justify-center rounded-md border border-[#e0e3e8] bg-white text-[#344052]">
                      <Clock3 className="size-4" />
                    </span>
                    <div>
                      <p className="text-[13px] text-[#596273]">{label}</p>
                      <p className="text-[14px] font-semibold text-[#111827]">{value || '--'}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
  )

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className={cn(
          'flex min-h-0 w-full max-w-full flex-col gap-0 overflow-hidden p-0 shadow-xl',
          isFlexible
            ? 'max-h-[min(94dvh,1060px)] rounded-lg border-[#d9dde5] bg-[#f8f9fb] sm:max-w-[min(89vw,1340px)]'
            : 'max-h-[min(94dvh,1080px)] rounded-lg border-[#d9dde5] bg-[#f8f9fb] sm:max-w-[min(97vw,1510px)]'
        )}
        innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0!"
        closeButtonClassName={cn(
          (isFlexible || isFixedWizard) && 'right-7 top-7 size-12 rounded-md border-[#d9dde5] bg-white text-[#111827] shadow-sm hover:bg-[#f6f7f9]'
        )}
      >
        <form
          className="flex min-h-0 w-full min-w-0 flex-1 flex-col overflow-hidden"
          onSubmit={(e) => {
            e.preventDefault()
            onSubmit(e)
          }}
        >
          <DialogHeader className={cn(
            'shrink-0 border-b text-left',
            isFlexible
              ? 'border-[#e0e3e8] bg-white px-7 py-5 pr-20 @sm:px-8 @sm:py-6'
              : 'border-[#e0e3e8] bg-white px-7 py-5 pr-24 @sm:px-8 @sm:py-6'
          )}>
            <DialogTitle className={cn(
              'flex items-start gap-2.5 font-semibold tracking-tight @sm:items-center',
              isFlexible || isFixedWizard ? 'text-[24px] text-[#0f1115]' : 'text-lg @sm:text-xl'
            )}>
              <span className={cn(
                'flex shrink-0 items-center justify-center',
                isFlexible || isFixedWizard ? 'size-16 rounded-xl bg-[#ff5a10] text-white shadow-sm' : 'size-9 rounded-lg bg-primary/10 text-primary'
              )}>
                <Calendar className={cn(isFlexible || isFixedWizard ? 'size-8' : 'size-5')} aria-hidden />
              </span>
              <span className="min-w-0 leading-snug">
                {title ?? (editingSchedule ? 'Edit work schedule' : 'New work schedule')}
              </span>
            </DialogTitle>
            <DialogDescription className={cn(
              'max-w-3xl leading-relaxed',
              isFlexible || isFixedWizard ? 'ml-[5rem] -mt-5 text-[15px] text-[#344052]' : 'text-xs text-muted-foreground @sm:text-sm'
            )}>
              {description ?? (
                <>
                  Create a new schedule template to standardize shifts and working hours.
                </>
              )}
            </DialogDescription>
            {headerExtra}
          </DialogHeader>

          <div className={cn(
            'min-h-0 flex-1 overflow-y-auto overscroll-contain',
            isFlexible || isFixedWizard ? 'px-0 py-0' : 'px-6 py-6'
          )}>
          {isFlexible ? (
            <div>
              <div className="grid gap-8 border-b border-[#e0e3e8] bg-white px-10 py-8 lg:grid-cols-[1fr_330px_1px_1.12fr]">
                <div className="space-y-3">
                  <Label htmlFor="schedule-name" className="text-[14px] font-semibold text-[#111827]">Schedule name <span className="text-[#f45113]">*</span></Label>
                  <Input
                    id="schedule-name"
                    value={editForm.name}
                    onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                    placeholder="e.g. Flexible Office Schedule"
                    className="h-12 rounded-md border-[#d7dce4] bg-white px-4 text-[14px] shadow-none placeholder:text-[#8a92a1]"
                    required
                    readOnly={readOnly}
                    disabled={readOnly}
                  />
                </div>
                <div className="space-y-3">
                  <Label className="text-[14px] font-semibold text-[#111827]">Shift type <span className="text-[#f45113]">*</span></Label>
                  <div className="grid h-12 grid-cols-2 overflow-hidden rounded-md border border-[#d7dce4] bg-white">
                    <button
                      type="button"
                      onClick={() => handleShiftTypeChange('flexible')}
                      disabled={readOnly}
                      className={cn(
                        'flex items-center justify-center gap-3 border-r border-[#e0e3e8] text-[14px] font-semibold transition-colors',
                        shiftType === 'flexible'
                          ? 'border-[#ffb38c] bg-[#fff2ea] text-[#111827] shadow-[inset_0_0_0_1px_#ffb38c]'
                          : 'text-[#555e6d] hover:bg-[#fafafa]'
                      )}
                    >
                      <Calendar className={cn('size-4', shiftType === 'flexible' ? 'text-[#f45113]' : 'text-[#8a92a1]')} />
                      Flexible Shift
                    </button>
                    <button
                      type="button"
                      onClick={() => handleShiftTypeChange('fixed')}
                      disabled={readOnly}
                      className={cn(
                        'flex items-center justify-center gap-3 text-[14px] font-semibold transition-colors',
                        shiftType === 'fixed'
                          ? 'border-[#ffb38c] bg-[#fff2ea] text-[#111827] shadow-[inset_0_0_0_1px_#ffb38c]'
                          : 'text-[#555e6d] hover:bg-[#fafafa]'
                      )}
                    >
                      <Clock3 className={cn('size-4', shiftType === 'fixed' ? 'text-[#f45113]' : 'text-[#8a92a1]')} />
                      Fixed Shift
                    </button>
                  </div>
                </div>
                <div className="hidden bg-[#dfe3e8] lg:block" />
                <div className="space-y-3">
                  <Label htmlFor="schedule-code" className="text-[14px] font-semibold text-[#111827]">
                    Schedule code <span className="font-normal text-[#6b7280]">(optional)</span>
                  </Label>
                  <Input
                    id="schedule-code"
                    value={editForm.schedule_code || ''}
                    onChange={(e) => setEditForm((f) => ({ ...f, schedule_code: e.target.value }))}
                    placeholder="e.g. FX-01"
                    className="h-12 rounded-md border-[#d7dce4] bg-white px-4 text-[14px] shadow-none placeholder:text-[#8a92a1]"
                    maxLength={32}
                    readOnly={readOnly}
                    disabled={readOnly}
                  />
                  <p className="text-[13px] text-[#6b7280]">A unique code to easily identify this schedule</p>
                </div>
              </div>

              <div className="bg-[#f8f9fb] px-10 py-6">
                <div className="mb-5 flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-[16px] font-semibold text-[#111827]">Weekly schedule</h3>
                    <p className="mt-1 text-[13px] text-[#596273]">Set up each day of the week. You can customize shift times, breaks, and other settings.</p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    className="h-10 gap-3 rounded-md border-[#d7dce4] bg-white px-5 text-[13px] font-semibold text-[#1f2329] shadow-sm"
                  >
                    Apply template
                    <ChevronDown className="size-4" />
                  </Button>
                </div>
                <div className="mb-3 h-px bg-[#dfe3e8]" />
                <FlexibleScheduleTable
                  days={editForm.days || []}
                  setDays={(updater) => setEditForm((f) => ({
                    ...f,
                    days: typeof updater === 'function' ? updater(f.days || []) : updater,
                  }))}
                  readOnly={readOnly}
                />
              </div>
            </div>
          ) : fixedWizardContent || (
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
                  onChange={(e) => handleShiftTypeChange(e.target.value)}
                  className="flex h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  disabled={readOnly}
                >
                  {SCHEDULE_EDITOR_SHIFT_TYPES.map((st) => (
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

              {/* Multiple breaks section (fixed only) */}
              {!isFlexible && (
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
              )}

              {/* Paid hours summary (same for fixed + flexible) */}
              <div className="flex items-center gap-3 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5 dark:bg-primary/10">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium">Paid hours: <span className="text-primary">{formatPaidHours(paidMinutes)}</span></p>
                  <p className="text-xs text-muted-foreground">Half-day threshold: {formatPaidHours(halfDayThresh)}</p>
                </div>
              </div>

              {/* Expected paid minutes / half-day (same for fixed + flexible) */}
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

              {/* Grace / early time-in (same for fixed + flexible) */}
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

              {/* Overtime buffer (same for fixed + flexible) */}
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

              {/* Days off (fixed only) */}
              {!isFlexible && (
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
              )}
            </div>

            <div className="space-y-4 min-w-0">
              <ScheduleWeeklyGrid
                timeIn={preview.time_in}
                timeOut={preview.time_out}
                restDays={editForm.rest_days}
                breakStart={preview.break_start}
                breakEnd={preview.break_end}
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
          )}

          {error && (
            <div className="mt-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
              {error}
            </div>
          )}
          </div>

          {isFlexible && (
            <div className="grid shrink-0 border-t border-[#e0e3e8] bg-white px-10 py-5 lg:grid-cols-3">
              <div className="flex items-center gap-4">
                <span className="flex size-12 items-center justify-center rounded-xl bg-[#fff1eb] text-[#f45113]">
                  <Clock3 className="size-6" />
                </span>
                <div>
                  <p className="text-[14px] font-semibold text-[#1f2329]">Weekly summary</p>
                  <p className="mt-1 text-[13px] text-[#596273]">{flexibleWorkingDayCount} working days</p>
                  <p className="text-[13px] text-[#596273]">{formatSummaryHours(wh)} expected hours</p>
                </div>
              </div>
              <div className="flex items-center gap-4 border-t border-[#e0e3e8] pt-4 lg:border-l lg:border-t-0 lg:px-8 lg:pt-0">
                <span className="flex size-12 items-center justify-center rounded-full bg-[#fff4dc] text-[#f59e0b]">
                  <AlarmClock className="size-6" />
                </span>
                <div>
                  <p className="text-[14px] font-semibold text-[#1f2329]">Overtime settings</p>
                  <p className="mt-1 text-[13px] text-[#596273]">OT after: {formatClockPadded(preview.time_out) || '05:00 PM'}</p>
                  <p className="text-[13px] text-[#596273]">OT buffer: {editForm.overtime_buffer_minutes || 15} minutes</p>
                </div>
              </div>
              <div className="flex items-center gap-4 border-t border-[#e0e3e8] pt-4 lg:border-l lg:border-t-0 lg:px-8 lg:pt-0">
                <span className="flex size-12 items-center justify-center rounded-full bg-[#eef0ff] text-[#5b5ff0]">
                  <CalendarDays className="size-6" />
                </span>
                <div>
                  <p className="text-[14px] font-semibold text-[#1f2329]">Schedule preview</p>
                  <button type="button" className="mt-1 inline-flex items-center gap-2 text-[13px] font-semibold text-[#f45113]">
                    View full weekly calendar
                    <ExternalLink className="size-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )}

          <div className={cn(
            'flex w-full min-w-0 shrink-0 flex-col gap-4 border-t px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] @sm:px-6 md:flex-row md:flex-wrap md:items-center md:gap-3',
            isFlexible ? 'justify-end border-[#e0e3e8] bg-white px-10' : 'justify-between border-[#e0e3e8] bg-white px-9'
          )}>
            {isFixedWizard && (
              <div className="flex max-w-[510px] items-center gap-4 rounded-lg border border-[#ffe1ce] bg-[#fffaf7] px-4 py-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#fff0e7] text-[#ff5a10]">
                  <Clock3 className="size-5" />
                </span>
                <div>
                  <p className="text-[14px] font-semibold text-[#111827]">Note</p>
                  <p className="mt-1 text-[13px] text-[#596273]">You can review all settings in the next step before creating the schedule.</p>
                </div>
              </div>
            )}
            <div className="flex flex-col gap-2 md:flex-row md:justify-end md:gap-3">
              <Button
                type="button"
                variant="outline"
                className={cn(
                  'h-11 w-full min-w-0 md:w-auto',
                  (isFlexible || isFixedWizard) && 'rounded-md border-[#aeb6c2] bg-white px-7 text-[15px] font-semibold text-[#111827] shadow-sm hover:bg-[#f6f7f9]'
                )}
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
                className={cn(
                  'h-11 w-full min-w-0 md:w-auto',
                  (isFlexible || isFixedWizard) && 'rounded-md bg-[#ff5a10] px-7 text-[15px] font-semibold text-white shadow-sm hover:bg-[#e94e0e]'
                )}
                disabled={submitting}
              >
                {submitting ? (
                  <Loader2 className="size-4 shrink-0 animate-spin" />
                ) : isFixedWizard ? (
                  <>
                    Next
                    <ArrowRight className="size-5" />
                  </>
                ) : (
                  submitLabel ?? (editingSchedule ? 'Save schedule' : 'Create schedule')
                )}
              </Button>
            </div>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
