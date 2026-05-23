import { createElement, useState } from 'react'
import {
  BadgeCheck,
  Banknote,
  CalendarClock,
  CalendarRange,
  ChevronRight,
  Clock3,
  Landmark,
  PiggyBank,
  Receipt,
  Scale,
  Shield,
  Sparkles,
  Wallet,
} from 'lucide-react'
import { postAdminEmployeeDeductionEarlyPayoff } from '@/api'
import { EarlyLoanPayoffConfirmDialog } from '../loans/EarlyLoanPayoffConfirmDialog.jsx'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  formatDeductionScheduleTypeShort,
  formatPayProfileComponentScheduleCell,
  formatSalaryTabDate,
  formatSalaryTabPhp,
  resolveTinForSalaryDisplay,
  resolveWithholdingStatutoryPresentation,
} from './salaryTabFormatters'

/** Primary foreground for salary cards — must stay readable in dark mode (avoid near-black on dark surfaces). */
const TEXT = 'text-[#0A0A0A] dark:text-slate-100'


function catalogRowTypeLabel(row) {
  if (row?.type === 'Government') return 'Government'
  if (row?.type === 'Earning') return 'Earning'
  const cat = String(row?.category || '')
  if (/loan|repayment/i.test(cat)) return 'Loan'
  if (/hmo|union|benefit/i.test(cat)) return 'Benefit'
  return 'Loan / Benefit'
}

/**
 * Company-wide pay schedule snapshot (from `compensation_summary.deduction_schedule_catalog`).
 *
 * @param {{ catalog: Array<{ name?: string, type?: string, category?: string, schedule_type?: string }>|null|undefined }} props
 */
export function SalaryDeductionScheduleCatalogCard({ catalog }) {
  const rows = Array.isArray(catalog) ? catalog : []
  if (rows.length === 0) return null

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="flex flex-col gap-2 space-y-0 border-b border-slate-100 pb-4 dark:border-white/10 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold', TEXT)}>
            <CalendarRange className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
            Pay schedule (company default)
          </CardTitle>
          <CardDescription className="text-slate-600 dark:text-slate-400">
            When deductions are withheld and when earnings or allowances are paid (15th, end of month, or split). HR sets this under Compensation → Deduction Schedule
            Settings.
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent className="pt-4">
        <div className="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
          <table className="w-full min-w-[520px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:border-white/10 dark:bg-white/5">
                <th className="px-3 py-2.5">Component</th>
                <th className="px-3 py-2.5">Type</th>
                <th className="px-3 py-2.5">Schedule</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.deduction_key || row.name} className="border-b border-slate-100 last:border-0 dark:border-white/10">
                  <td className="px-3 py-2.5">
                    <div className={cn('font-medium', TEXT)}>{row.name || '—'}</div>
                    {row.description ? (
                      <div className="mt-0.5 text-xs leading-snug text-slate-500 dark:text-slate-400">{row.description}</div>
                    ) : null}
                  </td>
                  <td className="px-3 py-2.5 text-slate-600 dark:text-slate-400">{catalogRowTypeLabel(row)}</td>
                  <td className="px-3 py-2.5 tabular-nums text-slate-700 dark:text-slate-300">{formatDeductionScheduleTypeShort(row.schedule_type)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * Loan/benefit assignments driven by HR (`employee_deduction` rows merged into compensation preview).
 *
 * @param {{ compensationSummary?: { deductions?: Array<{ name?: string, computed_amount?: number, employee_deduction_id?: number, metadata?: { source?: string, remaining_balance?: number|null, is_amortized?: boolean } }> }|null|undefined }, employeeUserId?: number, enableEarlyPayoff?: boolean }} props
 */
export function SalaryAutomatedDeductionsCard({ compensationSummary, employeeUserId, enableEarlyPayoff = false }) {
  const { toast } = useToast()
  const [payoffOpen, setPayoffOpen] = useState(false)
  const [payoffDeductionId, setPayoffDeductionId] = useState(null)
  const [payoffLoading, setPayoffLoading] = useState(false)
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduleRows, setScheduleRows] = useState([])
  const [scheduleTitle, setScheduleTitle] = useState('')

  const lines = (compensationSummary?.deductions || []).filter((d) => d?.metadata?.source === 'employee_deduction')
  if (lines.length === 0) return null

  const showEarlyPayoff = Boolean(enableEarlyPayoff && employeeUserId && Number.isFinite(employeeUserId))

  async function confirmEarlyPayoffSalary() {
    if (!employeeUserId || !payoffDeductionId) return
    setPayoffLoading(true)
    try {
      await postAdminEmployeeDeductionEarlyPayoff(employeeUserId, payoffDeductionId)
      toast({ title: 'Loan closed successfully.' })
      setPayoffOpen(false)
      setPayoffDeductionId(null)
      window.dispatchEvent(new CustomEvent('hr:employee-deductions-changed'))
    } catch (e) {
      toast({ title: 'Early payoff failed', description: e.message, variant: 'destructive' })
    } finally {
      setPayoffLoading(false)
    }
  }

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="border-b border-slate-100 pb-4 dark:border-white/10">
        <CardTitle className={cn('text-lg font-semibold', TEXT)}>Deductions & loans</CardTitle>
        <CardDescription className="text-slate-600 dark:text-slate-400">
          Active HR-assigned loans and other recurring deductions included in your net pay preview (per payroll schedule).
        </CardDescription>
        <p className="text-xs text-slate-500 dark:text-slate-400">
          Priority order: Statutory → Withholding tax → Loans/advances → Benefits → Other authorized deductions.
        </p>
      </CardHeader>
      <CardContent className="pt-4">
        <div className="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
          <Table>
            <TableHeader>
              <TableRow className="border-slate-100 dark:border-white/10">
                <TableHead className="text-slate-600 dark:text-slate-400">Name</TableHead>
                <TableHead className="text-slate-600 dark:text-slate-400">Schedule</TableHead>
                <TableHead className="text-slate-600 dark:text-slate-400">Priority</TableHead>
                <TableHead className="text-right text-slate-600 dark:text-slate-400">Monthly</TableHead>
                <TableHead className="text-right text-slate-600 dark:text-slate-400">Balance</TableHead>
                <TableHead className="text-slate-600 dark:text-slate-400">Next due</TableHead>
                {showEarlyPayoff ? (
                  <TableHead className="w-[140px] text-right text-slate-600 dark:text-slate-400">Actions</TableHead>
                ) : null}
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((row) => {
                const edId = row.employee_deduction_id ?? row.metadata?.employee_deduction_id
                const canEarlyPayoff = showEarlyPayoff && row.metadata?.is_amortized && edId != null
                const schedule = Array.isArray(row.metadata?.amortization_schedule) ? row.metadata.amortization_schedule : []
                return (
                  <TableRow
                    key={String(edId ?? row.code ?? row.name)}
                    className="border-slate-100 dark:border-white/10"
                  >
                    <TableCell className={cn('font-medium', TEXT)}>{row.name || '—'}</TableCell>
                    <TableCell className="text-sm text-slate-600 dark:text-slate-400">
                      {row.metadata?.deduction_schedule
                        ? formatDeductionScheduleTypeShort(row.metadata.deduction_schedule)
                        : '—'}
                    </TableCell>
                    <TableCell className="text-xs text-slate-600 dark:text-slate-400">
                      {row.metadata?.priority_order
                        ? `#${row.metadata.priority_order} ${row.metadata?.is_court_ordered_garnishment ? '(Garnishment)' : ''}`
                        : '—'}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-[#0A0A0A] dark:text-slate-100">
                      {formatSalaryTabPhp(row.computed_amount)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-slate-700 dark:text-slate-300">
                      {row.metadata?.remaining_balance != null ? formatSalaryTabPhp(row.metadata.remaining_balance) : '—'}
                    </TableCell>
                    <TableCell className="text-sm text-slate-600 dark:text-slate-400">
                      <div>{row.metadata?.next_due_date ? formatSalaryTabDate(row.metadata.next_due_date) : '—'}</div>
                      {row.metadata?.legal_warning ? (
                        <div className="mt-1 text-[11px] text-red-700 dark:text-red-300">{row.metadata.legal_warning}</div>
                      ) : null}
                    </TableCell>
                    {showEarlyPayoff ? (
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          {schedule.length > 0 ? (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="border-border/80 text-[#0A0A0A] hover:bg-muted/80 dark:text-slate-100"
                              onClick={() => {
                                setScheduleRows(schedule)
                                setScheduleTitle(String(row.name || 'Loan schedule'))
                                setScheduleOpen(true)
                              }}
                            >
                              View schedule
                            </Button>
                          ) : null}
                          {canEarlyPayoff ? (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="border-border/80 text-[#0A0A0A] hover:bg-muted/80 dark:text-slate-100"
                              onClick={() => {
                                setPayoffDeductionId(edId)
                                setPayoffOpen(true)
                              }}
                            >
                              Close loan early
                            </Button>
                          ) : null}
                          {schedule.length === 0 && !canEarlyPayoff ? <span className="text-xs text-slate-400">—</span> : null}
                        </div>
                      </TableCell>
                    ) : null}
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      </CardContent>

      <EarlyLoanPayoffConfirmDialog
        open={payoffOpen}
        onOpenChange={(open) => {
          setPayoffOpen(open)
          if (!open) setPayoffDeductionId(null)
        }}
        onConfirm={confirmEarlyPayoffSalary}
        loading={payoffLoading}
      />
      <Dialog open={scheduleOpen} onOpenChange={setScheduleOpen}>
        <DialogContent className="w-full max-w-3xl">
          <DialogHeader>
            <DialogTitle className={TEXT}>Amortization schedule</DialogTitle>
            <DialogDescription>{scheduleTitle}</DialogDescription>
          </DialogHeader>
          <div className="max-h-[60vh] overflow-auto rounded-xl border border-slate-100 dark:border-white/10">
            <Table>
              <TableHeader>
                <TableRow className="border-slate-100 dark:border-white/10">
                  <TableHead>#</TableHead>
                  <TableHead>Pay date</TableHead>
                  <TableHead className="text-right">Principal</TableHead>
                  <TableHead className="text-right">Interest</TableHead>
                  <TableHead className="text-right">Installment</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {scheduleRows.map((r) => (
                  <TableRow key={String(r.id || `${r.installment_number}-${r.due_date}`)} className="border-slate-100 dark:border-white/10">
                    <TableCell>{r.installment_number}</TableCell>
                    <TableCell>{formatSalaryTabDate(r.pay_date || r.due_date)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatSalaryTabPhp(r.principal)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatSalaryTabPhp(r.interest)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatSalaryTabPhp(r.total_installment)}</TableCell>
                    <TableCell>
                      <span className="capitalize">{String(r.status || 'pending')}</span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </DialogContent>
      </Dialog>
    </Card>
  )
}

function payCycleLabel(preview) {
  if (!preview) return '—'
  const name = preview.name && String(preview.name).trim()
  if (name) return name
  const code = String(preview.code || '')
    .replace(/_/g, ' ')
    .trim()
  return code ? code.replace(/\b\w/g, (c) => c.toUpperCase()) : '—'
}

function nextPreviewWindow(preview) {
  if (!preview?.preview_periods || !Array.isArray(preview.preview_periods) || preview.preview_periods.length === 0) {
    return {
      cutStart: preview?.cut_off_start_date,
      cutEnd: preview?.cut_off_end_date,
      payDate: preview?.pay_date,
    }
  }
  const next = preview.preview_periods[0]
  return {
    cutStart: next.cut_off_start_date,
    cutEnd: next.cut_off_end_date,
    payDate: next.pay_date,
  }
}

function mapPayrollPeriodStatus(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'finalized' || s === 'sent_finalized' || s === 'generated' || s === 'emailed' || s === 'viewed') return 'Finalized'
  if (s === 'locked' || s === 'computed') return 'Disbursed'
  if (s === 'draft') return 'Draft'
  return status ? String(status) : '—'
}

function taxCategoryLabel(summary) {
  const w = summary?.withholding
  const meta = w?.metadata || {}
  const profile = w?.tax_profile_applied
  if (profile?.is_mwe) return 'Minimum wage earner (MWE)'
  const regime = meta.tax_regime || profile?.tax_regime
  if (regime && String(regime).trim()) {
    const r = String(regime).replace(/_/g, ' ')
    return r.charAt(0).toUpperCase() + r.slice(1)
  }
  return 'Compensation income (TRAIN)'
}


/**
 * Pay cycle + base pay + next cut-off (from `payCyclePreview` / `compensation_summary.pay_cycle_preview`, {@see PayCycleService}).
 * Schedule one-liner (`scheduleHint`) must match backend {@see ScheduleRateService} fields on `displayUser`.
 *
 * @param {{
 *   compensationSummary: object | null,
 *   payCyclePreview: object | null,
 *   displayUser: object | null,
 *   basicMonthlyDisplay: string,
 *   lastUpdatedLabel?: string,
 *   viewOnly?: boolean,
 *   payCycleControl?: import('react').ReactNode,
 *   salaryEditSlot?: import('react').ReactNode,
 *   adminToolbar?: import('react').ReactNode,
 *   scheduleHint?: string | null,
 * }} props
 */
export function SalaryCompensationStructureCard({
  compensationSummary,
  payCyclePreview,
  displayUser,
  basicMonthlyDisplay,
  lastUpdatedLabel,
  viewOnly = true,
  payCycleControl,
  salaryEditSlot,
  adminToolbar,
  scheduleHint,
}) {
  const preview = payCyclePreview ?? compensationSummary?.pay_cycle_preview ?? null
  const next = nextPreviewWindow(preview)

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="flex flex-col gap-3 space-y-0 border-b border-slate-100 pb-4 dark:border-white/10 @sm:flex-row @sm:items-start @sm:justify-between">
        <div className="space-y-1">
          <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold tracking-tight', TEXT)}>
            <Sparkles className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
            Compensation Structure
          </CardTitle>
          <CardDescription className="text-slate-600 dark:text-slate-400">
            Pay cycle, base pay, and live schedule preview aligned with payroll rules.
          </CardDescription>
        </div>
        {adminToolbar ? <div className="flex flex-wrap gap-2">{adminToolbar}</div> : null}
      </CardHeader>
      <CardContent className="grid gap-4 pt-4">
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,340px)] lg:gap-5">
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Pay cycle</p>
                <div className="mt-2 flex items-center gap-2">
                  <CalendarClock className="size-4 shrink-0 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
                  {payCycleControl ? (
                    <div className="min-w-0 flex-1">{payCycleControl}</div>
                  ) : (
                    <p className={cn('text-base font-semibold', TEXT)}>{payCycleLabel(preview)}</p>
                  )}
                </div>
                {displayUser?.pay_cycle_inherited_from_company ? (
                  <p className="mt-1 text-xs font-medium text-[#0A0A0A] dark:text-slate-300">Inherited from company</p>
                ) : null}
                {preview?.cycle_label ? (
                  <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{preview.cycle_label}</p>
                ) : null}
              </div>

              <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Basic monthly salary</p>
                {salaryEditSlot ? (
                  <div className="mt-2">{salaryEditSlot}</div>
                ) : (
                  <p className={cn('mt-2 text-2xl font-semibold tabular-nums tracking-tight', TEXT)}>{basicMonthlyDisplay}</p>
                )}
                {viewOnly ? (
                  <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Contact HR to request changes to this rate.</p>
                ) : null}
              </div>
            </div>

            {scheduleHint ? (
              <div className="flex items-start gap-2 rounded-xl border border-dashed border-slate-200/90 bg-white px-3 py-2.5 text-sm text-slate-600 dark:border-white/15 dark:bg-[#151922] dark:text-slate-200">
                <Clock3 className="mt-0.5 size-4 shrink-0 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
                <span>{scheduleHint}</span>
              </div>
            ) : null}

            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
              <span className="font-medium text-slate-600 dark:text-slate-300">Last updated</span>
              <span>{lastUpdatedLabel || '—'}</span>
              {compensationSummary?.as_of_date ? (
                <>
                  <span className="text-slate-300 dark:text-slate-600" aria-hidden>
                    ·
                  </span>
                  <span>Snapshot as of {formatSalaryTabDate(compensationSummary.as_of_date)}</span>
                </>
              ) : null}
            </div>
          </div>

          <div
            className={cn(
              'flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-slate-50/90 p-5 shadow-sm',
              'ring-1 ring-black/5 dark:border-white/10 dark:bg-[#151922]',
            )}
          >
            <div>
              <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Real-time preview</p>
              <p className={cn('mt-1 text-lg font-semibold leading-snug', TEXT)}>Next cut-off &amp; pay date</p>
              <p className="mt-1 text-xs text-slate-600 dark:text-slate-400">
                From your assigned pay cycle (reference {formatSalaryTabDate(preview?.reference_date)})
              </p>
            </div>
            <div className="mt-4 space-y-4">
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Next cut-off</p>
                <p className={cn('mt-1 text-lg font-semibold tabular-nums', TEXT)}>
                  {next.cutStart && next.cutEnd ? `${formatSalaryTabDate(next.cutStart)} – ${formatSalaryTabDate(next.cutEnd)}` : '—'}
                </p>
              </div>
              <Separator className="bg-slate-200 dark:bg-white/15" />
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Next pay date</p>
                <p className={cn('mt-1 text-2xl font-semibold tabular-nums', TEXT)}>{formatSalaryTabDate(next.payDate)}</p>
              </div>
              {preview?.pro_ration_type ? (
                <p className="text-xs text-slate-600 dark:text-slate-400">
                  Proration:{' '}
                  <span className="font-medium capitalize">{String(preview.pro_ration_type).replace(/_/g, ' ')}</span>
                </p>
              ) : null}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * @param {{
 *   periods: any[],
 *   loading?: boolean,
 *   error?: string | null,
 *   onViewAll?: () => void,
 *   canView?: boolean,
 * }} props
 */
export function SalaryPayrollHistoryCard({ periods, loading, error, onViewAll, canView = true }) {
  const rows = Array.isArray(periods) ? periods : []

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="flex flex-col gap-2 space-y-0 border-b border-slate-100 pb-4 dark:border-white/10 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold', TEXT)}>
            <Receipt className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
            Payroll History
          </CardTitle>
          <CardDescription className="text-slate-600 dark:text-slate-400">Recent finalized payslips from completed payroll runs.</CardDescription>
        </div>
        {canView && onViewAll ? (
          <Button type="button" variant="outline" size="sm" className="border-slate-200 text-[#0A0A0A] hover:bg-slate-100 dark:border-white/15 dark:text-slate-100 dark:hover:bg-white/10" onClick={onViewAll}>
            View all
            <ChevronRight className="ml-1 size-4" aria-hidden />
          </Button>
        ) : null}
      </CardHeader>
      <CardContent className="pt-4">
        {loading ? (
          <p className="py-8 text-center text-sm text-slate-500 dark:text-slate-400">Loading payroll history…</p>
        ) : error ? (
          <p className="rounded-xl border border-dashed border-amber-200/80 bg-amber-50/50 px-4 py-6 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100">
            {error}
          </p>
        ) : rows.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-8 text-center text-sm text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
            No finalized payslips yet. History appears after payroll is finalized for this employee.
          </p>
        ) : (
          <ul className="space-y-2">
            {rows.map((p) => (
              <li
                key={p.id}
                className="flex flex-col gap-3 rounded-xl border border-slate-100 bg-slate-50/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/5"
              >
                <div className="min-w-0">
                  <p className={cn('font-medium', TEXT)}>{formatSalaryTabDate(p.pay_date)}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    Period {formatSalaryTabDate(p.from_date)} – {formatSalaryTabDate(p.to_date)}
                    {p.cycle_label ? ` · ${p.cycle_label}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-3">
                  <p className="text-lg font-semibold tabular-nums text-[#0A0A0A] dark:text-slate-100">{formatSalaryTabPhp(p.net_pay)}</p>
                  <Badge className="border-emerald-200 bg-emerald-50 font-medium text-emerald-800 hover:bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-200">
                    {mapPayrollPeriodStatus(p.status)}
                  </Badge>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

function StatutoryMiniCard({ icon, title, amount, subtitle, status = 'matched', footer = 'Employee share (estimated from current rules)' }) {
  const matchedBadge = (
    <Badge className="border-emerald-200 bg-emerald-50 font-medium text-emerald-800 hover:bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-200">
      Matched
    </Badge>
  )
  const pendingBadge = (
    <Badge className="border-amber-200 bg-amber-50 font-medium text-amber-900 hover:bg-amber-50 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100">
      Pending
    </Badge>
  )

  return (
    <div className="flex flex-col rounded-xl border border-slate-100 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-[#151922]">
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2">
          <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0A0A0A]/10 text-[#0A0A0A] dark:bg-white/10 dark:text-slate-100">
            {createElement(icon, { className: 'size-4', 'aria-hidden': true })}
          </div>
          <div className="min-w-0">
            <p className={cn('text-sm font-semibold leading-snug', TEXT)}>{title}</p>
            {subtitle ? <p className="text-xs text-slate-500 dark:text-slate-400">{subtitle}</p> : null}
          </div>
        </div>
        {status === 'matched' ? matchedBadge : pendingBadge}
      </div>
      <p className={cn('mt-4 text-2xl font-semibold tabular-nums', TEXT)}>{amount}</p>
      <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{footer}</p>
    </div>
  )
}

/**
 * @param {{ withholding: object | null | undefined, compensationSummary: object | null | undefined }} args
 */

/**
 * @param {{
 *   statutory: object | null | undefined,
 *   withholding?: object | null,
 *   compensationSummary?: object | null,
 *   onUpdateGovIds?: () => void,
 * }} props
 */
export function SalaryStatutoryDeductionsCard({ statutory, withholding, compensationSummary, onUpdateGovIds }) {
  const sss = statutory?.sss?.employee_amount
  const ph = statutory?.philhealth?.employee_amount
  const pi = statutory?.pagibig?.employee_amount
  const whtPresentation = resolveWithholdingStatutoryPresentation(withholding, compensationSummary)

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="border-b border-slate-100 pb-4 dark:border-white/10">
        <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold', TEXT)}>
          <Landmark className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
          Statutory Deductions
        </CardTitle>
        <CardDescription className="text-slate-600 dark:text-slate-400">
          Government contributions and BIR withholding estimated from your compensation base (preview rates; TRAIN annualized).
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4 pt-6 sm:grid-cols-2 xl:grid-cols-4">
        <StatutoryMiniCard icon={Shield} title="SSS contribution" amount={formatSalaryTabPhp(sss)} subtitle="SSS program" />
        <StatutoryMiniCard icon={Banknote} title="PhilHealth" amount={formatSalaryTabPhp(ph)} subtitle="RA 11223" />
        <StatutoryMiniCard icon={PiggyBank} title="Pag-IBIG" amount={formatSalaryTabPhp(pi)} subtitle="RA 9679" />
        <div className="flex flex-col rounded-xl border border-slate-100 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-[#151922]">
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 items-center gap-2">
              <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0A0A0A]/10 text-[#0A0A0A] dark:bg-white/10 dark:text-slate-100">
                <Scale className="size-4" aria-hidden />
              </div>
              <div className="min-w-0">
                <p className={cn('text-sm font-semibold tracking-wide', TEXT)}>WITHHOLDING TAX</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">BIR · TRAIN</p>
              </div>
            </div>
            {whtPresentation.status === 'matched' ? (
              <Badge className="border-emerald-200 bg-emerald-50 font-medium text-emerald-800 hover:bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-200">
                Matched
              </Badge>
            ) : (
              <Badge className="border-amber-200 bg-amber-50 font-medium text-amber-900 hover:bg-amber-50 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100">
                Pending
              </Badge>
            )}
          </div>
          <p className={cn('mt-4 text-2xl font-semibold tabular-nums', TEXT)}>{whtPresentation.amountLabel}</p>
          <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Estimated monthly withholding (annualized)</p>
          {whtPresentation.showGovIdsLink && onUpdateGovIds ? (
            <Button
              type="button"
              variant="link"
              className="mt-2 h-auto justify-start px-0 py-0 text-xs font-medium text-[#0A0A0A] underline-offset-4 hover:underline dark:text-slate-200"
              onClick={onUpdateGovIds}
            >
              Update in Gov IDs
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * @param {{
 *   compensationSummary: object | null,
 *   tinResolution: ReturnType<typeof resolveTinForSalaryDisplay>,
 *   onUpdateTaxInfo?: () => void,
 *   showUpdateButton?: boolean,
 * }} props
 */
export function SalaryTaxInfoCard({ compensationSummary, tinResolution, onUpdateTaxInfo, showUpdateButton = false }) {
  const category = taxCategoryLabel(compensationSummary)
  const tr =
    tinResolution
    ?? resolveTinForSalaryDisplay([], null, { loading: false })
  const showGovIdsCta =
    showUpdateButton
    && onUpdateTaxInfo
    && tr
    && (tr.variant === 'missing' || tr.variant === 'pending' || tr.variant === 'rejected')

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="flex flex-col gap-2 space-y-0 border-b border-slate-100 pb-4 dark:border-white/10 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold', TEXT)}>
            <Wallet className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
            Tax &amp; statutory IDs
          </CardTitle>
          <CardDescription className="text-slate-600 dark:text-slate-400">{tr?.cardDescription ?? 'Tax withholding and BIR identifiers.'}</CardDescription>
        </div>
        {showGovIdsCta ? (
          <Button type="button" variant="outline" size="sm" className="border-slate-200 text-[#0A0A0A] hover:bg-slate-100 dark:border-white/15 dark:text-slate-100 dark:hover:bg-white/10" onClick={onUpdateTaxInfo}>
            Update tax information
          </Button>
        ) : null}
      </CardHeader>
      <CardContent className="grid gap-4 pt-6 sm:grid-cols-3">
        <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">TIN</p>
            {tr?.showVerifiedBadge ? (
              <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-[10px] font-medium text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-200">
                Verified
              </Badge>
            ) : null}
          </div>
          {tr?.loading ? (
            <p className="mt-2 text-sm font-medium text-slate-500 dark:text-slate-400">Loading…</p>
          ) : (
            <p className={cn('mt-2 font-mono text-sm font-semibold tabular-nums', TEXT)}>{tr?.displayValue ?? '—'}</p>
          )}
          {tr?.tinHelperText ? (
            <p className="mt-2 text-xs leading-snug text-slate-500 dark:text-slate-400">{tr.tinHelperText}</p>
          ) : null}
        </div>
        <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tax category</p>
          <p className={cn('mt-2 text-sm font-semibold leading-snug', TEXT)}>{category}</p>
        </div>
        <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">RDO code</p>
          <p className={cn('mt-2 text-sm font-semibold', TEXT, 'opacity-80')}>Not on file</p>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * @param {{ children: import('react').ReactNode }} props
 */
export function SalaryTabNotice({ children }) {
  return (
    <div className="flex items-start gap-3 rounded-2xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-3 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
      <Wallet className="mt-0.5 size-4 shrink-0 text-slate-500 dark:text-slate-400" aria-hidden />
      <div className="leading-relaxed">{children}</div>
    </div>
  )
}

function formatCompCalculationType(value) {
  const map = {
    fixed_amount: 'Fixed Amount',
    percent_basic: '% of Basic',
    percent_gross: '% of Gross',
    formula: 'Formula',
    hourly: 'Hourly',
    daily_rate: 'Daily rate',
  }
  return map[value] || (value ? String(value).replace(/_/g, ' ') : '—')
}

function formatCompCalculationStandard(value) {
  const s = String(value || 'monthly_standard').toLowerCase()
  if (s === 'payroll_standard') return 'Payroll Standard'
  return 'Monthly Standard'
}

function formatCompCalculationStandardCell(item) {
  if (item?.calculation_standard_source === 'employee_override') {
    return formatCompCalculationStandard(item.resolved_calculation_standard ?? item.calculation_standard)
  }
  const def = item?.default_calculation_standard ?? item?.calculation_standard ?? 'monthly_standard'
  return `Use default: ${formatCompCalculationStandard(def)}`
}

function componentTypeLabel(type) {
  const t = String(type || '').toLowerCase()
  if (t === 'earning') return 'Earning'
  if (t === 'deduction') return 'Deduction'
  return t ? t.charAt(0).toUpperCase() + t.slice(1) : '—'
}

function filterNonZeroAssignedLines(items) {
  if (!Array.isArray(items)) return []
  return items.filter((item) => {
    const n = Number(item?.computed_amount)
    if (!Number.isFinite(n)) return false
    return Math.abs(n) >= 0.005
  })
}

function payComponentsTableHeader() {
  const th = 'text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300'
  return (
    <TableRow className="border-b border-slate-100 bg-slate-50/80 hover:bg-slate-50/80 dark:border-white/10 dark:bg-white/5">
      <TableHead className={th}>Name</TableHead>
      <TableHead className={th}>Category</TableHead>
      <TableHead className={th}>Calculation</TableHead>
      <TableHead className={th}>Standard</TableHead>
      <TableHead className={th}>Type</TableHead>
      <TableHead className={cn('text-right', th)}>Amount</TableHead>
      <TableHead className={th}>Schedule</TableHead>
    </TableRow>
  )
}

/**
 * Earnings · Deductions · Statutory in one card (tabbed).
 *
 * @param {{
 *   earnings?: object[]|null,
 *   deductions?: object[]|null,
 *   statutory?: object | null,
 *   withholding?: object | null,
 *   compensationSummary?: object | null,
 *   onUpdateGovIds?: () => void,
 * }} props
 */
export function SalaryPayComponentsBreakdownCard({
  earnings,
  deductions,
  statutory,
  withholding,
  compensationSummary,
  onUpdateGovIds,
}) {
  const e = filterNonZeroAssignedLines(earnings)
  const d = filterNonZeroAssignedLines(deductions)
  const nonBasic = e.filter((x) => String(x?.code || '').toUpperCase() !== 'BASIC_SALARY')
  const onlyBaseNoExtras = nonBasic.length === 0 && d.length === 0

  const govSched = compensationSummary?.government_pay_schedules || {}
  const snapshotDate = compensationSummary?.as_of_date
  const whtPresentation = resolveWithholdingStatutoryPresentation(withholding, compensationSummary)

  const renderRows = (items, tone) =>
    items.map((item, index) => {
      const schedLabel = formatPayProfileComponentScheduleCell(item)
      const amountClass =
        tone === 'deduction' ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-800 dark:text-emerald-300'
      return (
        <TableRow
          key={`${item.pay_component_id ?? item.code ?? item.name}-${index}`}
          className="border-b border-slate-100 dark:border-white/10"
        >
          <TableCell className={cn('font-medium', TEXT)}>{item.name || item.code || '—'}</TableCell>
          <TableCell className="text-slate-600 dark:text-slate-400">{item.category || '—'}</TableCell>
          <TableCell className="text-slate-600 dark:text-slate-400">{formatCompCalculationType(item.calculation_type)}</TableCell>
          <TableCell className="text-slate-600 dark:text-slate-400">{formatCompCalculationStandardCell(item)}</TableCell>
          <TableCell className="text-slate-600 dark:text-slate-400">{componentTypeLabel(item.type)}</TableCell>
          <TableCell className={cn('text-right tabular-nums font-semibold', amountClass)}>{formatSalaryTabPhp(item.computed_amount)}</TableCell>
          <TableCell className="text-slate-700 dark:text-slate-300">{schedLabel}</TableCell>
        </TableRow>
      )
    })

  const tabTriggerClass =
    'rounded-none border-0 border-b-2 border-transparent bg-transparent px-0 py-2.5 text-sm font-medium text-[#0A0A0A] shadow-none opacity-70 transition-colors hover:opacity-100 data-[state=active]:border-blue-600 data-[state=active]:opacity-100 dark:text-slate-100'

  return (
    <Card className="border border-slate-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
      <CardHeader className="border-b border-slate-100 pb-4 dark:border-white/10">
        <CardTitle className={cn('flex items-center gap-2 text-lg font-semibold', TEXT)}>
          <Banknote className="size-5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
          Pay components &amp; contributions
        </CardTitle>
        <CardDescription className="text-slate-600 dark:text-slate-400">
          Assigned earnings and deductions (non-zero amounts). Statutory rows are mandatory estimates from current rules.
        </CardDescription>
      </CardHeader>
      <CardContent className="pt-4">
        {onlyBaseNoExtras ? (
          <p className="mb-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-600 dark:border-white/15 dark:bg-white/5 dark:text-slate-400">
            No additional pay components assigned yet (besides base pay where applicable).
          </p>
        ) : null}

        <Tabs defaultValue="earnings" className="w-full gap-0">
          <div className="border-b border-slate-100 dark:border-white/10">
            <TabsList className="h-auto w-full justify-start gap-6 rounded-none border-0 bg-transparent p-0">
              <TabsTrigger value="earnings" className={tabTriggerClass}>
                Earnings
              </TabsTrigger>
              <TabsTrigger value="deductions" className={tabTriggerClass}>
                Deductions &amp; loans
              </TabsTrigger>
              <TabsTrigger value="statutory" className={tabTriggerClass}>
                Statutory contributions
              </TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="earnings" className="mt-4 focus-visible:outline-none">
            <div className="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
              <Table className="min-w-[720px] text-sm">
                <TableHeader>{payComponentsTableHeader()}</TableHeader>
                <TableBody>
                  {e.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7} className="py-10 text-center text-slate-500 dark:text-slate-400">
                        No earnings with a payable amount on file.
                      </TableCell>
                    </TableRow>
                  ) : (
                    renderRows(e, 'earning')
                  )}
                </TableBody>
              </Table>
            </div>
          </TabsContent>

          <TabsContent value="deductions" className="mt-4 focus-visible:outline-none">
            <div className="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
              <Table className="min-w-[720px] text-sm">
                <TableHeader>{payComponentsTableHeader()}</TableHeader>
                <TableBody>
                  {d.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7} className="py-10 text-center text-slate-500 dark:text-slate-400">
                        No loan or deduction components assigned with a non-zero amount.
                      </TableCell>
                    </TableRow>
                  ) : (
                    renderRows(d, 'deduction')
                  )}
                </TableBody>
              </Table>
            </div>
          </TabsContent>

          <TabsContent value="statutory" className="mt-4 focus-visible:outline-none">
            <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
              Estimated employee shares; schedules follow company deduction settings. Snapshot:{' '}
              {snapshotDate ? formatSalaryTabDate(snapshotDate) : '—'}.
            </p>
            <div className="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
              <Table className="min-w-[640px] text-sm">
                <TableHeader>
                  <TableRow className="border-b border-slate-100 bg-slate-50/80 hover:bg-slate-50/80 dark:border-white/10 dark:bg-white/5">
                    <TableHead className="text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Contribution</TableHead>
                    <TableHead className="text-right text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Amount</TableHead>
                    <TableHead className="text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Schedule</TableHead>
                    <TableHead className="text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Notes</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow className="border-b border-slate-100 dark:border-white/10">
                    <TableCell className={cn('font-medium', TEXT)}>SSS (employee)</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-[#0A0A0A] dark:text-slate-100">
                      {formatSalaryTabPhp(statutory?.sss?.employee_amount)}
                    </TableCell>
                    <TableCell className="text-slate-700 dark:text-slate-300">
                      {govSched.sss ? formatDeductionScheduleTypeShort(govSched.sss) : '—'}
                    </TableCell>
                    <TableCell className="text-slate-500 dark:text-slate-400">Social Security</TableCell>
                  </TableRow>
                  <TableRow className="border-b border-slate-100 dark:border-white/10">
                    <TableCell className={cn('font-medium', TEXT)}>PhilHealth (employee)</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-[#0A0A0A] dark:text-slate-100">
                      {formatSalaryTabPhp(statutory?.philhealth?.employee_amount)}
                    </TableCell>
                    <TableCell className="text-slate-700 dark:text-slate-300">
                      {govSched.philhealth ? formatDeductionScheduleTypeShort(govSched.philhealth) : '—'}
                    </TableCell>
                    <TableCell className="text-slate-500 dark:text-slate-400">RA 11223</TableCell>
                  </TableRow>
                  <TableRow className="border-b border-slate-100 dark:border-white/10">
                    <TableCell className={cn('font-medium', TEXT)}>Pag-IBIG (employee)</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-[#0A0A0A] dark:text-slate-100">
                      {formatSalaryTabPhp(statutory?.pagibig?.employee_amount)}
                    </TableCell>
                    <TableCell className="text-slate-700 dark:text-slate-300">
                      {govSched.pagibig ? formatDeductionScheduleTypeShort(govSched.pagibig) : '—'}
                    </TableCell>
                    <TableCell className="text-slate-500 dark:text-slate-400">RA 9679</TableCell>
                  </TableRow>
                  <TableRow className="border-b border-slate-100 dark:border-white/10">
                    <TableCell className={cn('font-medium', TEXT)}>Withholding tax</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-[#0A0A0A] dark:text-slate-100">{whtPresentation.amountLabel}</TableCell>
                    <TableCell className="text-slate-700 dark:text-slate-300">
                      {govSched.withholding_tax ? formatDeductionScheduleTypeShort(govSched.withholding_tax) : '—'}
                    </TableCell>
                    <TableCell className="text-slate-500 dark:text-slate-400">
                      BIR · TRAIN (annualized)
                      {whtPresentation.showGovIdsLink && onUpdateGovIds ? (
                        <>
                          {' '}
                          <button
                            type="button"
                            className="font-medium text-[#0A0A0A] underline underline-offset-2 hover:no-underline dark:text-slate-200"
                            onClick={onUpdateGovIds}
                          >
                            Update in Gov IDs
                          </button>
                        </>
                      ) : null}
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  )
}

export function SalaryTabShell({ className, children }) {
  return <div className={cn('space-y-5', className)}>{children}</div>
}
