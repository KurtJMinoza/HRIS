import { createElement, useCallback, useEffect, useMemo, useState } from 'react'
import {
  CalendarDays,
  CalendarRange,
  CheckCircle2,
  Heart,
  Landmark,
  Loader2,
  Mail,
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/components/ui/use-toast'
import { getAdminNextDeductionDates, getDeductionScheduleSettings, updateDeductionScheduleSettingsBatch } from '@/api'
import { cn } from '@/lib/utils'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  APP_MODAL_PRIMARY_BUTTON_CLASS,
  APP_MODAL_TITLE_CLASS,
  appModalDialogContentClass,
} from '@/lib/appModalStyles'

const SCHEDULE_OPTIONS_FALLBACK = [
  { value: '15th', label: 'First semi-monthly run', helper: 'Apply on the first payroll run.' },
  { value: '30th', label: 'End of month', helper: 'Apply on the month-end payroll run.' },
  { value: 'both', label: '50/50 split', helper: 'Split the amount across both payroll runs.' },
]

const TAB_TRIGGER_CLASS =
  'relative h-12 min-w-0 rounded-md border-0 bg-transparent px-3 text-sm font-semibold text-foreground shadow-none transition after:absolute after:bottom-0 after:left-1/2 after:h-0.5 after:w-32 after:max-w-[calc(100%-1.25rem)] after:-translate-x-1/2 after:rounded-full after:bg-transparent hover:bg-muted/60 hover:text-brand data-[state=active]:bg-brand/10 data-[state=active]:text-brand data-[state=active]:shadow-none data-[state=active]:after:bg-brand dark:hover:bg-muted/40 dark:data-[state=active]:bg-brand/15 sm:px-4 lg:after:w-40'

function isBasicSalaryScheduleRow(row) {
  const code = String(row?.code ?? '').trim().toUpperCase()
  if (code === 'BASIC_SALARY') return true
  const name = String(row?.name ?? '').trim().toLowerCase()
  return name === 'basic salary'
}

function pad2(value) {
  return String(Math.max(0, Number(value) || 0)).padStart(2, '0')
}

function getManilaTodayParts() {
  try {
    const raw = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date())
    const [year, month, day] = raw.split('-').map(Number)
    if (year && month && day) return { year, month, day }
  } catch {
    /* fall through */
  }
  const now = new Date()
  return { year: now.getFullYear(), month: now.getMonth() + 1, day: now.getDate() }
}

function nextPayrollDisplayDate() {
  const { year, month, day } = getManilaTodayParts()
  const lastDayOfMonth = new Date(year, month, 0).getDate()
  const target =
    day < 15
      ? new Date(year, month - 1, 15)
      : day < lastDayOfMonth
        ? new Date(year, month - 1, lastDayOfMonth)
        : new Date(year, month, 15)

  return target.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

function formatPreviewDate(value) {
  if (!value) return '-'
  const parsed = new Date(String(value))
  if (Number.isNaN(parsed.getTime())) return String(value)
  return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatScheduleLabel(value) {
  const schedule = String(value || '').trim()
  if (schedule === '15th') return 'First semi-monthly run'
  if (schedule === '30th') return 'End of month'
  if (schedule === 'both') return '50/50 split'
  return schedule || '-'
}

function formatScheduleNextLine(schedule, nextDatesBySchedule) {
  const dates = nextDatesBySchedule[schedule] || []
  if (schedule === 'both') {
    const nextTwo = dates.slice(0, 2).map(formatPreviewDate).filter((d) => d && d !== '-')
    return `Next: ${nextTwo.length ? nextTwo.join(' and ') : '-'}`
  }
  return `Next: ${formatPreviewDate(dates[0])}`
}

function rowDescription(row) {
  const description = String(row?.description || '').trim()
  if (description) return description.replace(/[^\w\s:().,/&-]+/g, ' - ').replace(/\s+-\s+/g, ' - ')
  const category = String(row?.category || '').trim()
  if (category) return category
  const code = String(row?.code || '').trim()
  return code || null
}

function mapTypeForColumn(row) {
  const type = String(row?.type || '').trim()
  if (type === 'Government') return 'Government'
  if (type === 'Earning') return 'Earning'
  const category = String(row?.category || '')
  if (/loan|repayment/i.test(category)) return 'Loan'
  if (/hmo|union|benefit/i.test(category)) return 'Benefit'
  return 'Loan / Benefit'
}

function iconForRow(row) {
  const key = String(row?.deduction_key || '').toUpperCase()
  const name = String(row?.name || '').toUpperCase()
  if (String(row?.type || '') === 'Earning') return Sparkles
  if (key.includes('SSS') || name.includes('SSS')) return Landmark
  if (key.includes('PHILHEALTH') || name.includes('PHILHEALTH')) return Heart
  if (key.includes('PAGIBIG') || key.includes('PAG-IBIG') || name.includes('PAG-IBIG')) return PiggyBank
  if (key.includes('WITHHOLDING') || name.includes('WITHHOLDING')) return Scale
  return WalletCards
}

function ScheduleRowIcon({ row }) {
  return createElement(iconForRow(row), { className: 'size-5 text-brand', 'aria-hidden': true })
}

function MetricCard({ label, value, footer, icon: Icon, loading }) {
  return (
    <Card className="min-h-32 overflow-hidden rounded-lg border-border bg-card shadow-sm">
      <CardContent className="flex h-full min-h-32 flex-col justify-between p-4">
        <div className="flex items-start justify-between gap-3">
          <p className="max-w-[10rem] text-[11px] font-semibold uppercase leading-5 tracking-wide text-muted-foreground">
            {label}
          </p>
          <div className="shrink-0 text-brand">
            {createElement(Icon, { className: 'size-5', 'aria-hidden': true })}
          </div>
        </div>
        <div className="mt-4">
          {loading ? (
            <Skeleton className="h-8 w-20" />
          ) : (
            <p className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">{value}</p>
          )}
          {footer ? (
            <p className="mt-3 text-xs leading-5 text-muted-foreground">{footer}</p>
          ) : null}
        </div>
      </CardContent>
    </Card>
  )
}

function ScheduleBadge({ scheduleType, pending = false }) {
  return (
    <span
      className={cn(
        'inline-flex min-h-8 items-center rounded-lg border border-border/70 bg-background px-3 text-sm font-semibold text-foreground shadow-sm dark:border-border/60 dark:bg-muted/20',
        pending && 'border-brand/45 bg-brand/10 text-brand dark:bg-brand/15',
      )}
    >
      {formatScheduleLabel(scheduleType)}
    </span>
  )
}

function TypeBadge({ row }) {
  const type = mapTypeForColumn(row)
  const className =
    type === 'Earning'
      ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-200'
      : type === 'Loan'
        ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-200'
        : 'border-border/70 bg-background text-foreground dark:border-border/60 dark:bg-muted/20'

  return (
    <Badge variant="outline" className={cn('rounded-lg px-3 py-1 text-xs font-medium', className)}>
      {type}
    </Badge>
  )
}

function TableSkeleton() {
  return (
    <div className="rounded-xl border border-border/70 bg-card p-4 dark:border-border/50">
      {Array.from({ length: 4 }).map((_, index) => (
        <div key={index} className="flex items-center gap-5 border-b border-border/60 py-5 last:border-0">
          <Skeleton className="size-14 rounded-xl" />
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-4 w-56" />
          </div>
          <Skeleton className="hidden h-8 w-28 sm:block" />
          <Skeleton className="hidden h-8 w-36 sm:block" />
        </div>
      ))}
    </div>
  )
}

export default function AdminDeductionScheduleSettingsPage() {
  const location = useLocation()
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [savingAll, setSavingAll] = useState(false)
  const [rows, setRows] = useState([])
  const [scheduleOptions, setScheduleOptions] = useState([])
  const [pendingEdits, setPendingEdits] = useState({})
  const [editOpen, setEditOpen] = useState(false)
  const [activeRow, setActiveRow] = useState(null)
  const [draftSchedule, setDraftSchedule] = useState('both')
  const [activeTab, setActiveTab] = useState('government')
  const [nextDatesBySchedule, setNextDatesBySchedule] = useState({})

  useEffect(() => {
    const hash = String(location.hash || '').trim().toLowerCase()
    if (hash === '#earnings' || hash === '#earning') setActiveTab('earnings')
    if (hash === '#other' || hash === '#loans') setActiveTab('other')
  }, [location.hash])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getDeductionScheduleSettings()
      setRows(Array.isArray(data?.settings) ? data.settings : [])
      setScheduleOptions(Array.isArray(data?.schedule_options) ? data.schedule_options : [])
    } catch (error) {
      toast({
        title: 'Deduction schedule settings',
        description: error.message || 'Failed to load deduction schedule settings.',
        variant: 'destructive',
      })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const effectiveSchedule = useCallback(
    (row) => {
      const key = row?.deduction_key
      if (!key) return row?.schedule_type || 'both'
      return pendingEdits[key] || row.schedule_type || 'both'
    },
    [pendingEdits],
  )

  useEffect(() => {
    let cancelled = false
    const schedules = Array.from(
      new Set(
        rows
          .map((row) => effectiveSchedule(row))
          .filter((value) => value === '15th' || value === '30th' || value === 'both'),
      ),
    )

    if (schedules.length === 0) return () => {}

    ;(async () => {
      const updates = {}
      await Promise.all(
        schedules.map(async (schedule) => {
          try {
            const response = await getAdminNextDeductionDates({ schedule_type: schedule })
            updates[schedule] = Array.isArray(response?.next_dates) ? response.next_dates : []
          } catch {
            updates[schedule] = []
          }
        }),
      )
      if (!cancelled) {
        setNextDatesBySchedule((current) => ({ ...current, ...updates }))
      }
    })()

    return () => {
      cancelled = true
    }
  }, [effectiveSchedule, pendingEdits, rows])

  const governmentRows = useMemo(
    () => rows.filter((row) => row.type === 'Government' && !isBasicSalaryScheduleRow(row)),
    [rows],
  )
  const otherRows = useMemo(
    () => rows.filter((row) => row.type === 'Loan / deduction' && !isBasicSalaryScheduleRow(row)),
    [rows],
  )
  const earningRows = useMemo(
    () => rows.filter((row) => row.type === 'Earning' && !isBasicSalaryScheduleRow(row)),
    [rows],
  )

  const hasPending = Object.keys(pendingEdits).length > 0
  const nextPayrollLabel = useMemo(() => nextPayrollDisplayDate(), [])
  const options = scheduleOptions.length
    ? scheduleOptions.map((option) => ({
        ...option,
        helper: SCHEDULE_OPTIONS_FALLBACK.find((fallback) => fallback.value === option.value)?.helper,
      }))
    : SCHEDULE_OPTIONS_FALLBACK

  function openEdit(row) {
    setActiveRow(row)
    setDraftSchedule(effectiveSchedule(row))
    setEditOpen(true)
  }

  function handleEditOpenChange(open) {
    setEditOpen(open)
    if (!open) {
      setActiveRow(null)
      setDraftSchedule('both')
    }
  }

  function applyEditFromModal() {
    if (!activeRow?.deduction_key) return
    const key = activeRow.deduction_key
    const serverSchedule = rows.find((row) => row.deduction_key === key)?.schedule_type || 'both'
    setPendingEdits((current) => {
      const next = { ...current }
      if (draftSchedule === serverSchedule) {
        delete next[key]
      } else {
        next[key] = draftSchedule
      }
      return next
    })
    handleEditOpenChange(false)
  }

  function discardPending() {
    setPendingEdits({})
  }

  async function saveAll() {
    const settings = Object.entries(pendingEdits).map(([deduction_key, schedule_type]) => ({
      deduction_key,
      schedule_type,
    }))
    if (settings.length === 0) return

    setSavingAll(true)
    try {
      const response = await updateDeductionScheduleSettingsBatch({ settings })
      toast({
        title: 'Default schedules saved',
        description:
          response?.message ||
          'These defaults apply to employees without custom schedule overrides on the next payroll run.',
      })
      setPendingEdits({})
      await load()
      window.dispatchEvent(new CustomEvent('hr:deduction-schedule-changed'))
    } catch (error) {
      toast({
        title: 'Save failed',
        description: error.message || 'Could not save deduction schedules.',
        variant: 'destructive',
      })
    } finally {
      setSavingAll(false)
    }
  }

  function renderRows(list, emptyTitle, emptyDescription) {
    if (loading) return <TableSkeleton />

    if (list.length === 0) {
      return (
        <div className="rounded-xl border border-dashed border-border/70 bg-card px-5 py-14 text-center dark:border-border/50">
          <div className="mx-auto flex size-14 items-center justify-center rounded-xl border border-border/70 bg-background text-brand dark:border-border/50 dark:bg-muted/20">
            <CalendarRange className="size-6" aria-hidden />
          </div>
          <p className="mt-4 text-sm font-semibold text-foreground">{emptyTitle}</p>
          <p className="mx-auto mt-1 max-w-md text-sm leading-6 text-muted-foreground">{emptyDescription}</p>
        </div>
      )
    }

    return (
      <div className="overflow-hidden rounded-lg border border-border bg-card shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left">
            <thead className="bg-background dark:bg-card">
              <tr className="border-b border-border/70 text-xs font-bold uppercase text-foreground dark:border-border/50">
                <th className="px-4 py-4">Deduction</th>
                <th className="px-4 py-4">Type</th>
                <th className="px-4 py-4">Current schedule</th>
                <th className="px-4 py-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {list.map((row) => {
                const schedule = effectiveSchedule(row)
                const isPending = Object.prototype.hasOwnProperty.call(pendingEdits, row.deduction_key)
                return (
                  <tr
                    key={row.deduction_key}
                    className="group border-b border-border/70 transition hover:bg-muted/30 last:border-b-0 dark:border-border/50 dark:hover:bg-muted/15"
                  >
                    <td className="px-4 py-4">
                      <div className="flex items-center gap-3">
                        <div className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-border bg-background shadow-sm dark:bg-muted/20">
                          <ScheduleRowIcon row={row} />
                        </div>
                        <div className="min-w-0">
                          <p className="text-sm font-bold leading-snug text-foreground">{row.name}</p>
                          {rowDescription(row) ? (
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">{rowDescription(row)}</p>
                          ) : null}
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-4 align-middle">
                      <TypeBadge row={row} />
                    </td>
                    <td className="px-4 py-4 align-middle">
                      <div className="space-y-2">
                        <ScheduleBadge scheduleType={schedule} pending={isPending} />
                        <p className="text-xs text-muted-foreground">{formatScheduleNextLine(schedule, nextDatesBySchedule)}</p>
                      </div>
                    </td>
                    <td className="px-4 py-4 text-right align-middle">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-9 rounded-md text-foreground hover:bg-brand/10 hover:text-brand dark:hover:bg-brand/15"
                        onClick={() => openEdit(row)}
                        aria-label={`Edit schedule for ${row.name}`}
                      >
                        <Pencil className="size-4" aria-hidden />
                      </Button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    )
  }

  return (
    <div className="w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5 3xl:px-10">
      <div className="mx-auto max-w-[112rem] space-y-5">
        <section className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0 flex-1">
            <Badge
              variant="outline"
              className="rounded-md border-0 bg-brand/10 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-brand hover:bg-brand/10"
            >
              Compensation
            </Badge>
            <h1 className="mt-4 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
              <span className="text-brand">Deduction</span> schedule settings
            </h1>
            <p className="mt-2 max-w-4xl text-sm leading-relaxed text-muted-foreground">
              Set default schedules for when statutory deductions, loans, and recurring earnings or allowances are paid
              (15th, month-end, or split). These are company-wide defaults-individual employees can override them in
              Employee Compensation.
            </p>
          </div>
          <div className="flex size-14 shrink-0 items-center justify-center rounded-lg border border-border bg-card text-brand shadow-sm">
            <CalendarRange className="size-6" aria-hidden />
          </div>
        </section>

        <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <MetricCard
            label="Government deductions"
            value={pad2(governmentRows.length)}
            icon={Landmark}
            loading={loading}
            footer={
              <span className="inline-flex items-center gap-2">
                <CheckCircle2 className="size-4 text-brand" aria-hidden />
                Active
              </span>
            }
          />
          <MetricCard
            label="Other loans / deductions"
            value={pad2(otherRows.length)}
            icon={Mail}
            loading={loading}
            footer="Pay component deductions in this schedule"
          />
          <MetricCard
            label="Earnings & allowances"
            value={pad2(earningRows.length)}
            icon={Sparkles}
            loading={loading}
            footer="Pay component earnings in this schedule"
          />
          <MetricCard
            label="Next payroll date"
            value={nextPayrollLabel}
            icon={CalendarDays}
            loading={loading}
            footer="Semi-monthly anchor (PH)"
          />
        </section>

        <Card className="w-full overflow-hidden rounded-lg border-border bg-card shadow-sm">
          <CardHeader className="border-b border-border p-4 sm:p-5">
            <CardTitle className="text-2xl font-bold tracking-tight text-foreground">Schedules</CardTitle>
            <CardDescription className="mt-1 max-w-5xl text-sm leading-relaxed text-muted-foreground">
              Set the default schedule for when deductions and earnings are applied. Individual employees can override
              these defaults in Employee Compensation - Pay Components.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
              <div className="border-b border-border p-3">
                <TabsList className="grid h-auto w-full grid-cols-1 gap-2 rounded-lg border-0 bg-muted/35 p-1 sm:grid-cols-3">
                  <TabsTrigger value="government" className={TAB_TRIGGER_CLASS}>
                    Government deductions
                  </TabsTrigger>
                  <TabsTrigger value="other" className={TAB_TRIGGER_CLASS}>
                    Other loans / deductions
                  </TabsTrigger>
                  <TabsTrigger value="earnings" className={TAB_TRIGGER_CLASS}>
                    Earnings & allowances
                  </TabsTrigger>
                </TabsList>
              </div>

              <TabsContent value="government" className="m-0 p-4 focus-visible:outline-none sm:p-5">
                {renderRows(
                  governmentRows,
                  'No government deductions found',
                  'Statutory deductions will appear here once the payroll catalog is available.',
                )}
              </TabsContent>
              <TabsContent value="other" className="m-0 p-4 focus-visible:outline-none sm:p-5">
                {renderRows(
                  otherRows,
                  'No loan or deduction components yet',
                  'Create active deduction-type pay components and they will be available for scheduling here.',
                )}
              </TabsContent>
              <TabsContent value="earnings" className="m-0 p-4 focus-visible:outline-none sm:p-5">
                {renderRows(
                  earningRows,
                  'No earning or allowance components yet',
                  'Create active earning-type pay components and they will be available for scheduling here.',
                )}
              </TabsContent>
            </Tabs>
          </CardContent>
        </Card>

        <div className="flex flex-col items-stretch justify-end gap-3 pb-2 sm:flex-row sm:items-center">
          {hasPending ? (
            <Button
              type="button"
              variant="ghost"
              className="h-11 rounded-md px-4 font-bold text-foreground hover:bg-muted"
              onClick={discardPending}
              disabled={savingAll}
            >
              Discard
            </Button>
          ) : null}
          <Button
            type="button"
            disabled={!hasPending || savingAll}
            onClick={saveAll}
            className="h-11 min-w-[150px] rounded-md bg-brand px-4 font-bold text-brand-foreground shadow-sm hover:bg-brand-strong disabled:cursor-not-allowed disabled:opacity-55"
          >
            {savingAll ? (
              <>
                <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
                Saving...
              </>
            ) : (
              'Save changes'
            )}
          </Button>
        </div>
      </div>

      <Dialog open={editOpen} onOpenChange={handleEditOpenChange}>
        <DialogContent className={appModalDialogContentClass({ size: 'sm', className: 'sm:max-w-lg' })}>
          <DialogHeader className="space-y-2">
            <DialogTitle className={APP_MODAL_TITLE_CLASS}>Edit schedule</DialogTitle>
            <DialogDescription className={APP_MODAL_DESCRIPTION_CLASS}>
              {activeRow ? (
                <>
                  <span className="font-semibold text-foreground">{activeRow.name}</span>
                  <span className="mt-1 block">
                    Choose the company default schedule. Employee-level overrides still take priority where they are set.
                  </span>
                </>
              ) : null}
            </DialogDescription>
          </DialogHeader>

          <RadioGroup value={draftSchedule} onValueChange={setDraftSchedule} className="gap-3 py-2">
            {options.map((option) => {
              const checked = draftSchedule === option.value
              const Icon =
                option.value === '15th' ? CalendarDays : option.value === '30th' ? CalendarRange : Sparkles
              return (
                <label
                  key={option.value}
                  htmlFor={`schedule-${option.value}`}
                  className={cn(
                    'flex cursor-pointer items-start gap-4 rounded-xl border border-border/70 bg-background p-4 transition hover:border-brand/40 hover:bg-brand/5 dark:border-border/50 dark:bg-muted/10 dark:hover:bg-brand/10',
                    checked && 'border-brand/55 bg-brand/10 ring-2 ring-brand/15 dark:bg-brand/15',
                  )}
                >
                  <RadioGroupItem id={`schedule-${option.value}`} value={option.value} className="mt-1 text-brand" />
                  <div className="flex min-w-0 flex-1 items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand dark:bg-brand/15">
                      {createElement(Icon, { className: 'size-5', 'aria-hidden': true })}
                    </span>
                    <span className="min-w-0">
                      <Label
                        htmlFor={`schedule-${option.value}`}
                        className="cursor-pointer text-sm font-bold text-foreground"
                      >
                        {option.label}
                      </Label>
                      {option.helper ? (
                        <span className="mt-1 block text-xs leading-5 text-muted-foreground">{option.helper}</span>
                      ) : null}
                    </span>
                  </div>
                </label>
              )
            })}
          </RadioGroup>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              className={APP_MODAL_OUTLINE_BUTTON_CLASS}
              onClick={() => handleEditOpenChange(false)}
            >
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
