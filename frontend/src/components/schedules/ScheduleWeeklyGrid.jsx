import { useCallback, useMemo, useState } from 'react'
import { cn } from '@/lib/utils'
import { minutesFromMidnight, minutesToHhMm, ndOverlapMinutes, netShiftMinutes } from '@/lib/scheduleLib'
import { ShiftPill } from '@/components/schedules/ShiftPill'
import { formatHHmmTo12h, toHhMm } from '@/lib/timeFormat'

const DAY_COLS = [
  { key: 'mon', label: 'Mon' },
  { key: 'tue', label: 'Tue' },
  { key: 'wed', label: 'Wed' },
  { key: 'thu', label: 'Thu' },
  { key: 'fri', label: 'Fri' },
  { key: 'sat', label: 'Sat' },
  { key: 'sun', label: 'Sun' },
]

/** 08:00 → 00:00 next row (17 rows × 1h) */
const START_MIN = 8 * 60
const END_MIN = 24 * 60
const STEP = 60
const ROWS = Math.ceil((END_MIN - START_MIN) / STEP)

function blockStyle(startMm, endMm) {
  const top = ((startMm - START_MIN) / (END_MIN - START_MIN)) * 100
  const h = ((endMm - startMm) / (END_MIN - START_MIN)) * 100
  return { top: `${Math.max(0, top)}%`, height: `${Math.max(0.5, h)}%` }
}

/**
 * Weekly grid preview: regular shift + ND overlay. Drag on a working day to set time in/out (same for all work days).
 */
export function ScheduleWeeklyGrid({
  timeIn,
  timeOut,
  restDays = [],
  breakStart,
  breakEnd,
  onShiftChange,
  className,
}) {
  const restSet = useMemo(() => new Set(restDays), [restDays])
  const [drag, setDrag] = useState(null)

  const timeInHhMm = toHhMm(timeIn) || '09:00'
  const timeOutHhMm = toHhMm(timeOut) || '17:00'
  const a = minutesFromMidnight(timeInHhMm)
  const b = minutesFromMidnight(timeOutHhMm)
  const crosses = b <= a
  const endMm = crosses ? b + 24 * 60 : b

  const ndMinutes = ndOverlapMinutes(timeInHhMm, timeOutHhMm)

  const pointerToMinutes = useCallback((clientY, bounds) => {
    const y = clientY - bounds.top
    const ratio = Math.min(1, Math.max(0, y / bounds.height))
    const mm = START_MIN + ratio * (END_MIN - START_MIN)
    return Math.round(mm / 15) * 15
  }, [])

  const onPointerDown = (e, dayKey) => {
    if (restSet.has(dayKey) || !onShiftChange) return
    const el = e.currentTarget?.parentElement
    if (!el?.getBoundingClientRect) return
    e.currentTarget.setPointerCapture(e.pointerId)
    const r = el.getBoundingClientRect()
    const m = pointerToMinutes(e.clientY, r)
    setDrag({ day: dayKey, start: m, current: m, rect: r })
  }

  const onPointerMove = (e) => {
    if (!drag) return
    const m = pointerToMinutes(e.clientY, drag.rect)
    setDrag((d) => (d ? { ...d, current: m } : null))
  }

  const endDrag = () => {
    if (!drag || !onShiftChange) {
      setDrag(null)
      return
    }
    let s = Math.min(drag.start, drag.current)
    let t = Math.max(drag.start, drag.current)
    if (t - s < 30) {
      setDrag(null)
      return
    }
    if (s < START_MIN) s = START_MIN
    if (t > END_MIN) t = END_MIN
    onShiftChange(minutesToHhMm(s % (24 * 60)), minutesToHhMm(t % (24 * 60)))
    setDrag(null)
  }

  const dragPreview =
    drag &&
    (() => {
      const s = Math.min(drag.start, drag.current)
      const t = Math.max(drag.start, drag.current)
      return blockStyle(s, t)
    })()

  return (
    <div className={cn('rounded-xl border border-border/60 bg-card p-3 shadow-sm', className)}>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-medium text-muted-foreground">
          Week view · drag on a work day to set shift (updates time in/out)
        </p>
        <div className="flex flex-wrap gap-1.5">
          <ShiftPill variant="regular">Regular</ShiftPill>
          <ShiftPill variant="nd">Night diff overlap</ShiftPill>
        </div>
      </div>
      <div className="flex gap-0 overflow-x-auto">
        <div className="sticky left-0 z-10 w-12 shrink-0 border-r border-border/50 bg-background/90 pr-1 pt-6 text-[10px] text-muted-foreground">
          {Array.from({ length: ROWS }, (_, i) => {
            const mm = START_MIN + i * STEP
            const h = Math.floor(mm / 60) % 24
            const m = mm % 60
            return (
              <div key={mm} className="h-8 text-right font-mono tabular-nums leading-8">
                {formatHHmmTo12h(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`)}
              </div>
            )
          })}
        </div>
        <div className="grid min-w-[640px] flex-1 grid-cols-7 gap-px">
          {DAY_COLS.map(({ key, label }) => {
            const isRest = restSet.has(key)
            return (
              <div key={key} className="flex min-w-[72px] flex-col">
                <div className="mb-1 text-center text-[11px] font-semibold text-foreground">{label}</div>
                <div
                  data-grid-day
                  className={cn(
                    'relative h-[512px] rounded-md border border-border/40 bg-background/80',
                    isRest && 'bg-muted/50',
                    !isRest && onShiftChange && 'cursor-crosshair touch-none'
                  )}
                  onPointerMove={onPointerMove}
                  onPointerUp={endDrag}
                  onPointerCancel={endDrag}
                >
                  {!isRest && (
                    <>
                      <div
                        className="pointer-events-none absolute inset-x-0.5 rounded-md bg-primary/20 ring-1 ring-primary/30"
                        style={
                          crosses
                            ? blockStyle(a, 24 * 60)
                            : blockStyle(Math.max(a, START_MIN), Math.min(endMm, END_MIN))
                        }
                      />
                      {crosses && (
                        <div
                          className="pointer-events-none absolute inset-x-0.5 rounded-md bg-primary/20 ring-1 ring-primary/30"
                          style={blockStyle(0, b)}
                        />
                      )}
                      {breakStart && breakEnd && (
                        <div
                          className="pointer-events-none absolute inset-x-1 rounded-sm bg-amber-400/20 ring-1 ring-amber-500/30"
                          style={{
                            ...blockStyle(minutesFromMidnight(toHhMm(breakStart)), minutesFromMidnight(toHhMm(breakEnd))),
                          }}
                        />
                      )}
                      {drag && !isRest && (
                        <div
                          className="absolute inset-x-0.5 rounded-md bg-primary/20 ring-2 ring-primary/40"
                          style={dragPreview}
                        />
                      )}
                      <button
                        type="button"
                        tabIndex={-1}
                        aria-label={`Paint shift on ${label}`}
                        className="absolute inset-0 z-1 opacity-0"
                        onPointerDown={(e) => onPointerDown(e, key)}
                      />
                    </>
                  )}
                  {isRest && (
                    <div className="absolute inset-0 flex items-center justify-center p-1 text-center">
                      <span className="text-[10px] font-medium text-muted-foreground">Rest</span>
                    </div>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>
      <p className="mt-2 text-[11px] text-muted-foreground">
        Net shift (excl. break):{' '}
        <span className="font-medium text-foreground">
          {(netShiftMinutes(timeInHhMm, timeOutHhMm, breakStart, breakEnd) / 60).toFixed(2)} h
        </span>
        {ndMinutes > 0 && (
          <span className="text-purple-700 dark:text-purple-300">
            {' '}
            · ND overlap ≈ {(ndMinutes / 60).toFixed(2)} h/day
          </span>
        )}
      </p>
    </div>
  )
}
