import { createElement, useCallback, useEffect, useMemo, useState } from 'react'
import {
  CalendarDays,
  CalendarRange,
  CheckCircle2,
  Heart,
  Landmark,
  Loader2,
  Pencil,
  PiggyBank,
  Scale,
  Sparkles,
  WalletCards,
} from 'lucide-react'
import { useLocation } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/components/ui/use-toast'
import { getAdminNextDeductionDates, getDeductionScheduleSettings, updateDeductionScheduleSettingsBatch } from '@/api'
import { formatDeductionScheduleTypeShort } from '@/components/salary/EmployeeSalaryTab'
import { cn } from '@/lib/utils'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  APP_MODAL_PRIMARY_BUTTON_CLASS,
  APP_MODAL_TITLE_CLASS,
  appModalDialogContentClass,
} from '@/lib/appModalStyles'

const TEXT = 'text-[#0A0A0A] dark:text-slate-100'

/** Basic salary is not configurable here; schedules apply to statutory items, loans, and non-base allowances only. */
function isBasicSalaryScheduleRow(row) {
  const code = String(row?.code ?? '')
    .trim()
    .toUpperCase()
  if (code === 'BASIC_SALARY') return true
  const name = String(row?.name ?? '')
    .trim()
    .toLowerCase()
  return name === 'basic salary'
}

function pad2(n) {
  return String(Math.min(99, Math.max(0, Number(n) || 0))).padStart(2, '0')
}

function nextPayrollDisplayDate() {
  const tz = 'Asia/Manila'
  const raw = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  const [yStr, mStr, dStr] = raw.split('-')
  const y = Number(yStr)
  const m = Number(mStr)
  const day = Number(dStr)
  if (!y || !m || !day) {
    return new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
  }
  const lastDayOfMonth = new Date(y, m, 0).getDate()
  let target
  if (day < 15) {
    target = new Date(y, m - 1, 15)
  } else if (day === 15) {
    target = new Date(y, m - 1, lastDayOfMonth)
  } else if (day < lastDayOfMonth) {
    target = new Date(y, m - 1, lastDayOfMonth)
  } else {
    target = new Date(y, m, 15)
  }
  return target.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

function formatPreviewDate(value) {
  if (!value) return '—'
  const d = new Date(String(value))
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function mapTypeForColumn(row) {
  const t = String(row?.type || '').trim()
  if (t === 'Government') return 'Government'
  if (t === 'Earning') return 'Earning'
  const cat = String(row?.category || '')
  if (/loan|repayment/i.test(cat)) return 'Loan'
  if (/hmo|union|benefit/i.test(cat)) return 'Benefit'
  return 'Loan / Benefit'
}

function rowDescription(row) {
  if (row?.description && String(row.description).trim()) return String(row.description).trim()
  if (row?.category && String(row.category).trim()) return String(row.category).trim()
  if (row?.code && String(row.code).trim()) return String(row.code).trim()
  return null
}

/** Icon for schedule row (government, loan, or earning) — neutral ink only. */
function DeductionRowIcon({ row }) {
  const key = String(row?.deduction_key || '')
  const iconClass = 'size-4 text-[#0A0A0A] dark:text-slate-200'
  if (String(row?.type || '') === 'Earning') {
    return <Sparkles className={iconClass} aria-hidden />
  }
  if (key.includes('SSS') || key.endsWith(':SSS')) {
    return <Landmark className={iconClass} aria-hidden />
  }
  if (key.includes('PHILHEALTH')) {
    return <Heart className={iconClass} aria-hidden />
  }
  if (key.includes('PAGIBIG')) {
    return <PiggyBank className={iconClass} aria-hidden />
  }
  if (key.includes('WITHHOLDING')) {
    return <Scale className={iconClass} aria-hidden />
  }
  return <WalletCards className={iconClass} aria-hidden />
}

/** Matches Pay Cycles `KpiCard` density; icons use neutral surface + #0A0A0A ink. */
function DeductionKpiCard({ label, children, icon, footer }) {
  return (
    <Card className="overflow-hidden border border-border/60 bg-background shadow-sm transition-colors dark:border-border/50">
      <CardContent className="flex items-start justify-between gap-4 p-5 sm:p-6">
        <div className="min-w-0 space-y-2">
          <p className={cn('text-[11px] font-semibold uppercase tracking-[0.18em]', TEXT)}>{label}</p>
          <div className="min-h-10">{children}</div>
          {footer ? <p className={cn('text-sm', TEXT)}>{footer}</p> : null}
        </div>
        <div className="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted text-[#0A0A0A] ring-1 ring-border/50 dark:bg-muted/80 dark:text-slate-100">
          {createElement(icon, { className: 'size-6', 'aria-hidden': true })}
        </div>
      </CardContent>
    </Card>
  )
}

function ScheduleBadge({ scheduleType }) {
  const s = String(scheduleType || 'both')
  const label = formatDeductionScheduleTypeShort(s)
  let variantClass =
    'border-border/70 bg-muted/35 text-[#0A0A0A] dark:border-border dark:bg-muted/50 dark:text-slate-100'
  if (s === '30th') {
    variantClass =
      'border-border/70 bg-muted/45 text-[#0A0A0A] dark:border-border dark:bg-muted/55 dark:text-slate-100'
  }
  if (s === 'both') {
    variantClass =
      'border-border/70 bg-muted/40 text-[#0A0A0A] dark:border-border dark:bg-muted/45 dark:text-slate-100'
  }
  return (
    <Badge variant="outline" className={cn('rounded-md px-2.5 py-0.5 text-xs font-medium tabular-nums', variantClass)}>
      {label}
    </Badge>
  )
}

export default function AdminDeductionScheduleSettingsPage() {
  const location = useLocation()
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [savingAll, setSavingAll] = useState(false)
  const [rows, setRows] = useState([])
  const [scheduleOptions, setScheduleOptions] = useState([])
  const [pendingEdits, setPendingEdits] = useState(() => ({}))
  const [editOpen, setEditOpen] = useState(false)
  const [activeRow, setActiveRow] = useState(null)
  const [draftSchedule, setDraftSchedule] = useState('both')
  const [activeTab, setActiveTab] = useState('government')
  const [nextDatesBySchedule, setNextDatesBySchedule] = useState({})

  useEffect(() => {
    const h = String(location.hash || '').trim()
    if (h === '#earnings' || h === '#earning') {
      setActiveTab('earnings')
    }
  }, [location.hash])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getDeductionScheduleSettings()
      setRows(Array.isArray(data?.settings) ? data.settings : [])
      setScheduleOptions(Array.isArray(data?.schedule_options) ? data.schedule_options : [])
    } catch (e) {
      toast({
        title: 'Deduction schedule settings',
        description: e.message || 'Failed to load settings',
        variant: 'destructive',
      })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    let cancelled = false
    const schedules = Array.from(
      new Set(
        rows
          .map((row) => pendingEdits[row?.deduction_key] ?? row?.schedule_type)
          .filter((v) => v === '15th' || v === '30th' || v === 'both'),
      ),
    )
    if (schedules.length === 0) return () => {}

    ;(async () => {
      const updates = {}
      for (const schedule of schedules) {
        try {
          const resp = await getAdminNextDeductionDates({ schedule_type: schedule })
          updates[schedule] = Array.isArray(resp?.next_dates) ? resp.next_dates : []
        } catch {
          updates[schedule] = []
        }
      }
      if (!cancelled) {
        setNextDatesBySchedule((prev) => ({ ...prev, ...updates }))
      }
    })()

    return () => {
      cancelled = true
    }
  }, [rows, pendingEdits])

  const effectiveSchedule = useCallback(
    (row) => {
      if (!row?.deduction_key) return row?.schedule_type ?? 'both'
      return pendingEdits[row.deduction_key] ?? row.schedule_type ?? 'both'
    },
    [pendingEdits],
  )

  const govRows = useMemo(
    () => rows.filter((r) => r.type === 'Government' && !isBasicSalaryScheduleRow(r)),
    [rows],
  )
  const loanDeductionRows = useMemo(
    () => rows.filter((r) => r.type === 'Loan / deduction' && !isBasicSalaryScheduleRow(r)),
    [rows],
  )
  const earningRows = useMemo(
    () => rows.filter((r) => r.type === 'Earning' && !isBasicSalaryScheduleRow(r)),
    [rows],
  )

  const govCount = govRows.length
  const loanCount = loanDeductionRows.length
  const earningCount = earningRows.length
  const hasPending = Object.keys(pendingEdits).length > 0

  const nextPayrollLabel = useMemo(() => nextPayrollDisplayDate(), [])

  const openEdit = (row) => {
    setActiveRow(row)
    setDraftSchedule(effectiveSchedule(row))
    setEditOpen(true)
  }

  const applyEditFromModal = () => {
    if (!activeRow?.deduction_key) return
    const key = activeRow.deduction_key
    const serverType = rows.find((r) => r.deduction_key === key)?.schedule_type ?? 'both'
    setPendingEdits((prev) => {
      const next = { ...prev }
      if (draftSchedule === serverType) {
        delete next[key]
      } else {
        next[key] = draftSchedule
      }
      return next
    })
    setEditOpen(false)
    setActiveRow(null)
  }

  const discardPending = () => setPendingEdits({})

  const saveAll = async () => {
    const entries = Object.entries(pendingEdits)
    if (entries.length === 0) return
    setSavingAll(true)
    try {
      await updateDeductionScheduleSettingsBatch({
        settings: entries.map(([deduction_key, schedule_type]) => ({ deduction_key, schedule_type })),
      })
      toast({
        title: 'Default schedules saved',
        description: 'These defaults apply to employees without custom schedule overrides. Changes take effect on the next payroll run.',
      })
      setPendingEdits({})
      await load()
      try {
        window.dispatchEvent(new CustomEvent('hr:deduction-schedule-changed'))
      } catch {
        /* ignore */
      }
    } catch (e) {
      toast({ title: 'Save failed', description: e.message || 'Could not save schedules', variant: 'destructive' })
    } finally {
      setSavingAll(false)
    }
  }

  const opts =
    scheduleOptions.length > 0
      ? scheduleOptions
      : [
          { value: '15th', label: 'First semi-monthly run' },
          { value: '30th', label: 'End of month' },
          { value: 'both', label: '50/50 split' },
        ]

  const renderTable = (list, { firstLabel = 'Item', emptyHint = 'Items will appear here when configured.' } = {}) => (
    <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm dark:border-border/50">
      <div className="overflow-x-auto">
        <Table className="w-full min-w-[680px]">
          <TableHeader>
            <TableRow className="border-b border-border/60 bg-muted/40 hover:bg-muted/40 dark:border-border/40 dark:bg-muted/25 dark:hover:bg-muted/25">
              <TableHead className={cn('h-14 px-6 text-[11px] font-semibold uppercase tracking-wide', TEXT)}>{firstLabel}</TableHead>
              <TableHead className={cn('h-14 px-6 text-[11px] font-semibold uppercase tracking-wide', TEXT)}>Type</TableHead>
              <TableHead className={cn('h-14 px-6 text-[11px] font-semibold uppercase tracking-wide', TEXT)}>Current schedule</TableHead>
              <TableHead className={cn('h-14 w-[88px] px-6 text-right text-[11px] font-semibold uppercase tracking-wide', TEXT)}>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="px-6 py-16 text-center">
                  <div className="mx-auto flex max-w-md flex-col items-center gap-3">
                    <div className="flex size-14 items-center justify-center rounded-lg border border-dashed border-border/60 bg-background shadow-sm dark:border-border/50">
                      <CalendarRange className={cn('size-6 opacity-50', TEXT)} aria-hidden />
                    </div>
                    <div>
                      <p className={cn('text-sm font-medium', TEXT)}>Nothing in this tab</p>
                      <p className={cn('mt-1 text-sm', TEXT)}>{emptyHint}</p>
                    </div>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              list.map((row) => {
                const sched = effectiveSchedule(row)
                const gov = mapTypeForColumn(row) === 'Government'
                const earning = String(row?.type || '') === 'Earning'
                return (
                  <TableRow
                    key={row.deduction_key}
                    className="group border-b border-border/60 transition-colors last:border-0 hover:bg-muted/30 dark:border-border/40 dark:hover:bg-muted/20"
                  >
                    <TableCell className="px-6 py-5 align-middle">
                      <div className="flex gap-3">
                        <div className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-background shadow-sm dark:border-border/50 dark:bg-white/5">
                          <DeductionRowIcon row={row} />
                        </div>
                        <div className="min-w-0">
                          <p className={cn('text-sm font-semibold leading-snug', TEXT)}>{row.name}</p>
                          {rowDescription(row) ? (
                            <p className={cn('mt-1 max-w-md text-xs leading-relaxed', TEXT)}>{rowDescription(row)}</p>
                          ) : null}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-6 py-5 align-middle">
                      {gov ? (
                        <Badge
                          variant="secondary"
                          className={cn(
                            'rounded-md border border-border/70 bg-muted/40 px-2.5 py-0.5 text-xs font-medium dark:border-border dark:bg-muted/50',
                            TEXT,
                          )}
                        >
                          Government
                        </Badge>
                      ) : earning ? (
                        <Badge
                          variant="outline"
                          className="rounded-md border-emerald-200/80 bg-emerald-50/90 px-2.5 py-0.5 text-xs font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-100"
                        >
                          Earning
                        </Badge>
                      ) : (
                        <Badge
                          variant="outline"
                          className="rounded-md border-border/70 px-2.5 py-0.5 text-xs font-medium text-[#0A0A0A] dark:text-slate-200"
                        >
                          {mapTypeForColumn(row)}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-6 py-5 align-middle">
                      <div className="space-y-1">
                        <ScheduleBadge scheduleType={sched} />
                        <p className="text-[11px] text-muted-foreground">
                          {sched === 'both'
                            ? `Next: ${(nextDatesBySchedule[sched] || []).slice(0, 2).map(formatPreviewDate).join(' and ') || '—'}`
                            : `Next: ${formatPreviewDate((nextDatesBySchedule[sched] || [])[0])}`}
                        </p>
                      </div>
                    </TableCell>
                    <TableCell className="px-6 py-5 text-right align-middle">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-9 rounded-lg text-[#0A0A0A] opacity-80 transition-opacity hover:bg-muted hover:text-[#0A0A0A] group-hover:opacity-100 dark:text-slate-200 dark:hover:bg-muted dark:hover:text-slate-100"
                        onClick={() => openEdit(row)}
                        aria-label={`Edit schedule for ${row.name}`}
                      >
                        <Pencil className="size-4" aria-hidden />
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  )

  return (
    <div className="w-full min-w-0 space-y-6">
      {/* Header — Pay Cycles / profile style */}
      <div className="rounded-2xl border border-border/60 bg-background p-5 shadow-sm sm:p-6 dark:border-border/50">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="max-w-3xl space-y-3">
            <Badge variant="outline" className={cn('w-fit rounded-full border-border/70 bg-background px-3 py-1 text-[11px] font-medium tracking-wide', TEXT)}>
              Compensation
            </Badge>
            <div className="space-y-2">
              <h1 className={cn('hr-page-title', TEXT)}>Deduction schedule settings</h1>
              <p className={cn('max-w-2xl text-sm leading-relaxed', TEXT)}>
                Set default schedules for when statutory deductions, loans, and recurring earnings or allowances are paid (15th, month-end, or split). These are company-wide defaults—individual employees can override them in Employee Compensation.
              </p>
            </div>
          </div>
          <div className="flex size-12 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/30 shadow-sm dark:border-border/50 dark:bg-white/5">
            <CalendarRange className={cn('size-6', TEXT)} aria-hidden />
          </div>
        </div>
      </div>

      {/* KPI cards — Pay Cycles–style density */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <DeductionKpiCard
          label="Government deductions"
          icon={Landmark}
          footer={
            loading ? null : (
              <span className={cn('inline-flex items-center gap-1.5 font-medium', TEXT)}>
                <CheckCircle2 className="size-3.5 shrink-0 opacity-80" aria-hidden />
                Active
              </span>
            )
          }
        >
          {loading ? <Skeleton className="h-10 w-16" /> : <p className={cn('text-4xl font-semibold tabular-nums tracking-tight', TEXT)}>{pad2(govCount)}</p>}
        </DeductionKpiCard>

        <DeductionKpiCard
          label="Other loans / deductions"
          icon={WalletCards}
          footer={loading ? null : 'Pay component deductions in this schedule'}
        >
          {loading ? <Skeleton className="h-10 w-16" /> : <p className={cn('text-4xl font-semibold tabular-nums tracking-tight', TEXT)}>{pad2(loanCount)}</p>}
        </DeductionKpiCard>

        <DeductionKpiCard
          label="Earnings & allowances"
          icon={Sparkles}
          footer={loading ? null : 'Pay component earnings in this schedule'}
        >
          {loading ? <Skeleton className="h-10 w-16" /> : <p className={cn('text-4xl font-semibold tabular-nums tracking-tight', TEXT)}>{pad2(earningCount)}</p>}
        </DeductionKpiCard>

        <DeductionKpiCard
          label="Next payroll date"
          icon={CalendarDays}
          footer={loading ? null : 'Semi-monthly anchor (PH)'}
        >
          {loading ? (
            <Skeleton className="h-10 w-40" />
          ) : (
            <p className={cn('text-xl font-semibold leading-snug tracking-tight sm:text-2xl', TEXT)}>{nextPayrollLabel}</p>
          )}
        </DeductionKpiCard>
      </div>

      {/* Table card + underline tabs */}
      <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
        <CardHeader className="border-b border-border/60 space-y-1 bg-card pb-5 dark:border-border/50">
          <CardTitle className={cn('text-xl', TEXT)}>Schedules</CardTitle>
          <CardDescription className={cn('text-sm leading-relaxed', TEXT)}>
            Set the default schedule for when deductions and earnings are applied. Individual employees can override these defaults in Employee Compensation → Pay Components.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0 pt-2">
          {loading ? (
            <div className="space-y-3 px-6 py-6">
              <Skeleton className="h-10 w-full max-w-md" />
              <Skeleton className="h-12 w-full" />
              <Skeleton className="h-12 w-full" />
            </div>
          ) : (
            <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full gap-0">
              <div className="border-b border-border/60 px-4 dark:border-border/50 sm:px-6">
                <TabsList className="h-auto w-full justify-start gap-0 rounded-none border-0 bg-transparent p-0">
                  <TabsTrigger
                    value="government"
                    className={cn(
                      'relative rounded-none border-0 border-b-2 border-transparent bg-transparent px-0 py-3 text-sm font-medium shadow-none',
                      'data-[state=active]:shadow-none',
                      TEXT,
                      'opacity-60 transition-colors hover:opacity-100',
                      'data-[state=active]:opacity-100 data-[state=active]:border-blue-600 data-[state=active]:bg-transparent',
                      'mr-8',
                    )}
                  >
                    Government deductions
                  </TabsTrigger>
                  <TabsTrigger
                    value="other"
                    className={cn(
                      'relative rounded-none border-0 border-b-2 border-transparent bg-transparent px-0 py-3 text-sm font-medium shadow-none',
                      'data-[state=active]:shadow-none',
                      TEXT,
                      'opacity-60 transition-colors hover:opacity-100',
                      'data-[state=active]:opacity-100 data-[state=active]:border-blue-600 data-[state=active]:bg-transparent',
                      'mr-8',
                    )}
                  >
                    Other loans / deductions
                  </TabsTrigger>
                  <TabsTrigger
                    value="earnings"
                    className={cn(
                      'relative rounded-none border-0 border-b-2 border-transparent bg-transparent px-0 py-3 text-sm font-medium shadow-none',
                      'data-[state=active]:shadow-none',
                      TEXT,
                      'opacity-60 transition-colors hover:opacity-100',
                      'data-[state=active]:opacity-100 data-[state=active]:border-blue-600 data-[state=active]:bg-transparent',
                    )}
                  >
                    Earnings & allowances
                  </TabsTrigger>
                </TabsList>
              </div>
              <TabsContent value="government" className="mt-0 px-4 pb-6 pt-4 focus-visible:outline-none sm:px-6">
                {renderTable(govRows, {
                  firstLabel: 'Deduction',
                  emptyHint: 'Statutory deductions are always listed for schedule control.',
                })}
              </TabsContent>
              <TabsContent value="other" className="mt-0 px-4 pb-6 pt-4 focus-visible:outline-none sm:px-6">
                {renderTable(loanDeductionRows, {
                  firstLabel: 'Deduction',
                  emptyHint: 'Deduction-type pay components will appear here when configured for your company.',
                })}
              </TabsContent>
              <TabsContent value="earnings" className="mt-0 px-4 pb-6 pt-4 focus-visible:outline-none sm:px-6">
                {renderTable(earningRows, {
                  firstLabel: 'Allowance / earning',
                  emptyHint: 'Earning-type pay components (allowances, bonuses, etc.) will appear here when configured.',
                })}
              </TabsContent>
            </Tabs>
          )}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center justify-end gap-2">
        {hasPending ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn(TEXT, 'h-10')}
            onClick={discardPending}
            disabled={savingAll}
          >
            Discard
          </Button>
        ) : null}
        <Button
          type="button"
          variant="default"
          disabled={!hasPending || savingAll}
          onClick={saveAll}
          className="h-11 min-w-[140px] rounded-lg px-8 font-semibold shadow-sm"
        >
          {savingAll ? (
            <>
              <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
              Saving…
            </>
          ) : (
            'Save changes'
          )}
        </Button>
      </div>

      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className={cn(appModalDialogContentClass, 'sm:max-w-md')}>
          <DialogHeader className="space-y-1">
            <DialogTitle className={APP_MODAL_TITLE_CLASS}>Edit schedule</DialogTitle>
            <DialogDescription className={APP_MODAL_DESCRIPTION_CLASS}>
              {activeRow ? (
                <>
                  <span className="font-medium text-[#0A0A0A] dark:text-slate-100">{activeRow.name}</span>
                  <span className="block pt-1">
                    Choose the default schedule for this {activeRow.type === 'Earning' ? 'earning/allowance' : 'deduction'}. Employees can still use their own schedule override in Employee Compensation.
                  </span>
                </>
              ) : null}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <RadioGroup value={draftSchedule} onValueChange={setDraftSchedule} className="gap-3">
              {opts.map((opt) => (
                <div
                  key={opt.value}
                  className="flex items-center space-x-3 rounded-xl border border-slate-200 px-3 py-2.5 dark:border-white/10"
                >
                  <RadioGroupItem value={opt.value} id={`sched-${opt.value}`} />
                  <Label htmlFor={`sched-${opt.value}`} className={cn('cursor-pointer text-sm font-normal leading-snug', TEXT)}>
                    {opt.label}
                  </Label>
                </div>
              ))}
            </RadioGroup>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setEditOpen(false)}>
              Cancel
            </Button>
            <Button type="button" className={APP_MODAL_PRIMARY_BUTTON_CLASS} onClick={applyEditFromModal}>
              Apply
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
