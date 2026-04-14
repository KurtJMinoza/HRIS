import React from 'react'
import { X, FileDown, RotateCcw, AlertTriangle } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * Parses HH:MM string to decimal hours (e.g. "08:30" -> 8.5).
 */
function parseHrsToDecimal(hrs) {
  if (!hrs) return 0
  const s = String(hrs).trim()
  const [h, m] = s.split(':').map(Number)
  return (h || 0) + (m || 0) / 60
}

/**
 * Computation Breakdown Modal — Audit view for payroll daily time records.
 * Shows schedule context, actual logs, hour segmentation, rule mapping,
 * computation ledger, and audit flags.
 *
 * @param {boolean} open - Modal visibility
 * @param {function} onOpenChange - (open: boolean) => void
 * @param {object} record - Daily computation record (from table row)
 */
export function ComputationBreakdownModal({ open, onOpenChange, record }) {
  if (!record) return null

  const regularDec = parseHrsToDecimal(record.regular) || 0
  const otDec = parseHrsToDecimal(record.ot) || 0
  const ndDec = parseHrsToDecimal(record.nd) || 0

  const schedule = {
    shift: '09:00 AM – 06:00 PM',
    logDate: record.date
      ? new Date(record.date).toLocaleDateString('en', {
          month: 'long',
          day: 'numeric',
          year: 'numeric',
        })
      : '—',
  }

  const actualLogs = {
    timeIn: '08:52 AM',
    timeOut: '07:30 PM',
  }

  const segmentation = {
    regular: regularDec.toFixed(2),
    ot: otDec.toFixed(2),
    nd: ndDec.toFixed(2),
  }

  const dayTypeLabel =
    record.dayType === 'REST DAY'
      ? 'Rest Day'
      : record.dayType === 'HOLIDAY'
        ? 'Holiday'
        : 'Ordinary Day'
  const ruleCode = record.rule ? `${record.rule}_REG_OT` : 'ORD_REG_OT'

  const ledger = [
    { label: 'Regular Hours', hrs: segmentation.regular, rate: '1.00' },
    { label: 'Overtime', hrs: segmentation.ot, rate: '1.25' },
    { label: 'Night Diff', hrs: segmentation.nd, rate: '0.10' },
  ]
  const weightedTotal =
    parseFloat(segmentation.regular) * 1.0 +
    parseFloat(segmentation.ot) * 1.25 +
    parseFloat(segmentation.nd) * 0.1

  const auditFlags = record.expanded?.flags?.length
    ? record.expanded.flags.map((flag) => {
        if (flag === 'EXCESSIVE_OT')
          return 'Computed 2.50 hrs OT but no approved request found in system.'
        if (flag === 'MANUAL_PUNCH_ADJ')
          return 'Log-in at 08:52 AM is within grace period, but exceeds strict 08:30 log policy.'
        return `${flag.replace(/_/g, ' ')} — requires review.`
      })
    : [
        'Computed 2.50 hrs OT but no approved request found in system.',
        'Log-in at 08:52 AM is within grace period, but exceeds strict 08:30 log policy.',
      ]

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton={false}
        className={cn(
          '@container max-w-[min(1600px,98vw)]! w-full min-w-[min(700px,95vw)] max-h-[90vh] overflow-y-auto p-0',
          'rounded-2xl border-border shadow-[0_20px_50px_-12px_rgba(0,0,0,0.15)]'
        )}
      >
        {/* Header */}
        <div className="flex items-start justify-between gap-4 border-b border-border px-8 pt-6 pb-5">
          <DialogHeader className="gap-1 p-0">
            <DialogTitle className="text-xl font-bold tracking-tight">
              Computation Breakdown
            </DialogTitle>
            <div className="flex items-center gap-2 mt-2">
              <button
                type="button"
                className="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
              >
                {record.name ?? 'Employee'}
              </button>
              <Badge
                variant="secondary"
                className="text-xs font-medium text-muted-foreground"
              >
                {record.employeeId ?? 'EMP-0000'}
              </Badge>
            </div>
          </DialogHeader>
          <button
            type="button"
            onClick={() => onOpenChange?.(false)}
            className={cn(
              'flex size-10 shrink-0 items-center justify-center rounded-lg',
              'text-muted-foreground transition-colors hover:bg-muted hover:text-foreground'
            )}
            aria-label="Close"
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="space-y-8 px-8 pb-8">
          {/* Top grid: Schedule Context + Actual Logs */}
          <div className="grid grid-cols-1 @sm:grid-cols-2 gap-6">
            <div className="rounded-xl border border-border bg-slate-50/80 dark:bg-slate-800/50 p-6">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-4">
                Schedule Context
              </p>
              <div className="space-y-4 text-sm">
                <div className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Assigned Shift</span>
                  <span className="font-medium tabular-nums">
                    {schedule.shift}
                  </span>
                </div>
                <div className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Log Date</span>
                  <span className="font-medium">{schedule.logDate}</span>
                </div>
              </div>
            </div>
            <div className="rounded-xl border border-border bg-slate-50/80 dark:bg-slate-800/50 p-6">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-4">
                Actual Logs
              </p>
              <div className="grid grid-cols-2 gap-6">
                <div className="border-r border-border pr-6">
                  <p className="text-xs text-muted-foreground mb-1">Time In</p>
                  <p className="text-lg font-bold tabular-nums">
                    {actualLogs.timeIn}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Time Out</p>
                  <p className="text-lg font-bold tabular-nums">
                    {actualLogs.timeOut}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Hour Segmentation */}
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-sm font-semibold mb-5">Hour Segmentation</h3>
            <div className="grid grid-cols-3 gap-8">
              <div className="border-r border-border pr-6 last:border-r-0 last:pr-0">
                <p className="text-xs text-muted-foreground mb-1">Regular (≤ 8)</p>
                <p className="text-xl font-bold font-mono tabular-nums text-blue-600 dark:text-blue-400">
                  {segmentation.regular}
                  <span className="text-xs font-normal text-muted-foreground ml-1">
                    hrs
                  </span>
                </p>
              </div>
              <div className="border-r border-border pr-6 last:border-r-0 last:pr-0">
                <p className="text-xs text-muted-foreground mb-1">Overtime (&gt; 8)</p>
                <p className="text-xl font-bold font-mono tabular-nums">
                  {segmentation.ot}
                  <span className="text-xs font-normal text-muted-foreground ml-1">
                    hrs
                  </span>
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground mb-1">
                  Night Diff (10PM–6AM)
                </p>
                <p className="text-xl font-bold font-mono tabular-nums">
                  {segmentation.nd}
                  <span className="text-xs font-normal text-muted-foreground ml-1">
                    hrs
                  </span>
                </p>
              </div>
            </div>
          </div>

          {/* Lower grid: Rule Mapping + Computation Ledger */}
          <div className="grid grid-cols-1 @sm:grid-cols-2 gap-6">
            <div className="rounded-xl border border-border bg-slate-50/80 dark:bg-slate-800/50 p-6">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-4">
                Rule Mapping
              </p>
              <div className="space-y-4">
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Day Type</p>
                  <Badge
                    variant="outline"
                    className="text-xs font-medium bg-slate-200/80 dark:bg-slate-700/80"
                  >
                    {dayTypeLabel}
                  </Badge>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground mb-1">Rule Code</p>
                  <p className="font-mono text-sm font-bold">{ruleCode}</p>
                </div>
              </div>
            </div>
            <div className="rounded-xl border border-border bg-slate-800 dark:bg-slate-900 p-6 text-white">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-4">
                Computation Ledger
              </p>
              <div className="space-y-4 text-sm">
                {ledger.map((row) => (
                  <div
                    key={row.label}
                    className="flex justify-between items-baseline gap-6 min-w-0"
                  >
                    <span className="text-slate-300">{row.label}</span>
                    <span className="font-mono tabular-nums">
                      {row.hrs} × {row.rate}
                    </span>
                  </div>
                ))}
                <div className="border-t-2 border-slate-500 pt-5 mt-5">
                  <div className="flex justify-between items-baseline gap-6">
                    <span className="font-semibold text-slate-200">
                      Weighted Total
                    </span>
                    <span className="font-mono text-3xl font-bold tabular-nums text-white tracking-tight">
                      {weightedTotal.toFixed(3)} units
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Audit Flags & Conflicts */}
          <div
            className={cn(
              'rounded-xl border border-red-200 dark:border-red-900/50 p-6',
              'bg-red-50/80 dark:bg-red-950/30'
            )}
          >
            <h3 className="flex items-center gap-2 text-sm font-semibold text-red-800 dark:text-red-300 mb-3">
              <AlertTriangle className="size-4 shrink-0" />
              Audit Flags & Conflicts
            </h3>
            <ul className="space-y-3">
              {auditFlags.map((desc, i) => (
                <li
                  key={i}
                  className="flex gap-2 text-sm text-red-700/90 dark:text-red-300/90"
                >
                  <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-red-500" />
                  <span>{desc}</span>
                </li>
              ))}
            </ul>
          </div>

          {/* Footer */}
          <DialogFooter className="flex flex-row justify-end gap-3 border-t border-border pt-6 pb-0 px-0">
            <Button
              variant="outline"
              size="default"
              className="gap-2 active:scale-[0.98] transition-transform"
              onClick={() => {}}
            >
              <FileDown className="size-4" />
              Download PDF
            </Button>
            <Button
              size="default"
              className="gap-2 bg-blue-600 text-white hover:bg-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400 active:scale-[0.98] transition-transform"
              onClick={() => {}}
            >
              <RotateCcw className="size-4" />
              Re-compute Logs
            </Button>
          </DialogFooter>
        </div>
      </DialogContent>
    </Dialog>
  )
}
