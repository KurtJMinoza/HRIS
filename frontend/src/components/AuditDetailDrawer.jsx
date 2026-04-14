import React, { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import {
  X,
  FileDown,
  RotateCcw,
  RefreshCw,
  AlertTriangle,
  Clock,
  Moon,
  Timer,
  ChevronDown,
  Info,
  Loader2,
  CheckCircle2,
  ClipboardList,
  Palmtree,
  Briefcase,
  Calculator,
} from 'lucide-react'
import { userProfileImageSrc } from '@/api'
import { Sheet, SheetContent } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { formatHHmmTo12h, formatScheduleLabel12h } from '@/lib/timeFormat'
import { toast } from 'sonner'
import { downloadPayrollPolicyManualCsv } from '@/lib/payrollPolicyManualExport'

function parseHrsToDecimal(hrs) {
  if (!hrs) return 0
  const s = String(hrs).trim()
  const [h, m] = s.split(':').map(Number)
  return (h || 0) + (m || 0) / 60
}

function formatMinutesShort(m) {
  if (m == null || !Number.isFinite(m) || m <= 0) return ''
  const h = Math.floor(m / 60)
  const mm = Math.round(m % 60)
  if (h > 0 && mm > 0) return `${h}h ${mm}m`
  if (h > 0) return `${h}h`
  return `${mm}m`
}

function isPresentOnTimeTardinessLabel(label) {
  return label === 'Present' || label === 'Present – Within Grace'
}

/** Badge chrome for OT workflow (matches Admin daily table `ot_status`). */
function otStatusBadgeClass(status) {
  switch (status) {
    case 'approved':
      return 'border-emerald-300/80 bg-emerald-100 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/45 dark:text-emerald-100'
    case 'not_filed':
      return 'border-slate-400/70 bg-slate-200/90 text-slate-950 dark:border-slate-600/60 dark:bg-slate-800/70 dark:text-slate-100'
    case 'pending_review':
    case 'partial_pending':
      return 'border-amber-300/80 bg-amber-100 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100'
    case 'unapproved':
      return 'border-red-300/80 bg-red-100 text-red-950 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100'
    default:
      return 'border-border bg-muted/80 text-muted-foreground'
  }
}

function otStatusHeadline(status) {
  switch (status) {
    case 'approved':
      return 'Approved'
    case 'not_filed':
      return 'No OT filed'
    case 'pending_review':
      return 'Pending review'
    case 'partial_pending':
      return 'Partially pending'
    case 'unapproved':
      return 'Unapproved'
    default:
      return 'No OT'
  }
}

function buildKeyDetailsTardiness(record) {
  const label = record.tardiness_label
  if (!label) return '—'
  const lateM = Number(record.late_deduction_minutes ?? 0)
  if (lateM > 0) {
    return `${label} · −${(lateM / 60).toFixed(2)} hrs regular`
  }
  if (isPresentOnTimeTardinessLabel(label)) {
    const g = Number(record.grace_period_credit_minutes ?? 0)
    if (g > 0) return `${label} · no deduction (grace +${g}m to full net)`
    return `${label} · no deduction`
  }
  return label
}

function buildDayTypeLine(record) {
  const dt = record.dayType
  if (dt === 'REST DAY') return record.is_rest_day ? 'Rest day (scheduled)' : 'Rest day'
  if (dt === 'HOLIDAY') {
    const n = record.holiday_name
    return n ? `${n} — holiday` : 'Holiday (calendar)'
  }
  return 'Ordinary working day'
}

function buildOtKeyLine(record, renderedOtHours) {
  const appr = Number(record.approved_ot_hours ?? 0)
  const pend = Number(record.pending_ot_hours ?? 0)
  const hasReq = record.has_overtime_request === true
  if (renderedOtHours <= 0.001) {
    return 'No rendered OT'
  }
  if (!hasReq) {
    return `${renderedOtHours.toFixed(2)} hrs after shift end · no OT request in Overtime module`
  }
  const fullyApproved = Math.abs(appr - renderedOtHours) < 0.02 && pend < 0.001
  if (fullyApproved) {
    return `${renderedOtHours.toFixed(2)} hrs rendered · fully approved`
  }
  const parts = [`${renderedOtHours.toFixed(2)} hrs rendered`]
  parts.push(`Appr. ${appr.toFixed(2)}h · Pend. ${pend.toFixed(2)}h`)
  return parts.join(' · ')
}

function flagLabels(flags) {
  if (!flags?.length) return null
  return flags.map((f) => f.replace(/_/g, ' ')).join(' · ')
}

/** Payroll reference amounts from employee record / engine (PHP). */
function formatPhpAmount(value) {
  if (value === undefined || value === null || value === '') return '—'
  const n = Number(String(value).replace(/,/g, '').trim())
  if (!Number.isFinite(n)) return '—'
  return `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function parseMoney(value) {
  if (value === undefined || value === null || value === '') return null
  const n = Number(String(value).replace(/,/g, '').trim())
  return Number.isFinite(n) ? n : null
}

/** Backend coalesces monthly_salary with monthly_rate; this matches that and older payloads. */
function resolveMonthlyBaseDisplay(record) {
  const raw = record?.monthly_salary ?? record?.monthly_rate
  return formatPhpAmount(raw)
}

/** Engine daily rate, else stored profile daily, else monthly ÷ schedule divisor when API sends schedule_rate_basis. */
function resolveEffectiveDailyDisplay(record) {
  const fromApi = parseMoney(record?.effective_daily_rate)
  if (fromApi !== null && fromApi >= 0) {
    return formatPhpAmount(fromApi)
  }
  const stored = parseMoney(record?.stored_daily_rate)
  if (stored !== null && stored > 0) {
    return formatPhpAmount(stored)
  }
  const monthly = parseMoney(record?.monthly_salary ?? record?.monthly_rate)
  const divisor = Number(record?.schedule_rate_basis?.working_days_per_month)
  if (monthly !== null && monthly > 0 && Number.isFinite(divisor) && divisor > 0) {
    return formatPhpAmount(monthly / divisor)
  }
  return '—'
}

/** ND add-on rate from policy snapshot (fraction of applicable base), default DOLE-style 10%. */
function ndPremiumFromSnapshot(snapshot) {
  const m = snapshot?.nd?.premium_multiplier
  return typeof m === 'number' && Number.isFinite(m) ? m : 0.1
}

/** Human-readable ND window + rate for audit panel (matches Policy Settings when snapshot present). */
function ndLabelFromSnapshot(snapshot) {
  const nd = snapshot?.nd
  if (!nd) {
    return 'Default (22:00–06:00 +10%) — no policy snapshot on this row; publish an active policy to show custom ND.'
  }
  const mult = (nd.premium_multiplier ?? 0.1) * 100
  const start = nd.start_time ?? `${String(nd.start_hour ?? 22).padStart(2, '0')}:00`
  const end = nd.end_time ?? `${String(nd.end_hour ?? 6).padStart(2, '0')}:00`
  return `${start}–${end} · +${mult.toFixed(0)}% on applicable rate`
}

/** Matches `DAY_TYPE_STYLES` in AdminDailyComputation.jsx for badge consistency. */
const DAY_TYPE_BADGE_CLASS = {
  ORDINARY: 'bg-slate-100 text-slate-700 border-slate-200/80 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600',
  'REST DAY': 'bg-sky-50 text-sky-900 border-sky-200/60 dark:bg-sky-950/50 dark:text-sky-200 dark:border-sky-800',
  HOLIDAY: 'bg-amber-50 text-amber-900 border-amber-200/60 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-800',
}

function otWorkflowLabel(status) {
  switch (status) {
    case 'approved':
      return 'Approved — OT premium applied to approved hours'
    case 'not_filed':
      return 'No OT request for this date — premium not applied (file in Admin → Overtime to pay OT)'
    case 'pending_review':
      return 'Pending — OT premium applies only after approval (not while pending)'
    case 'partial_pending':
      return 'Partial — some rendered OT is not covered by approved + pending requests'
    case 'unapproved':
      return 'Filed but not approved — rendered OT exceeds approved + pending'
    default:
      return 'No rendered OT'
  }
}

/** Short line for the OT hour pill (badge already shows status). */
function formatIsoShort(iso) {
  if (!iso || typeof iso !== 'string') return null
  try {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    return d.toLocaleString('en-PH', { dateStyle: 'short', timeStyle: 'short' })
  } catch {
    return iso
  }
}

/** Correction workflow badge — aligned with Daily Computation table (green / orange; red for rejected). */
function correctionStatusBadgeClass(status) {
  switch (status) {
    case 'approved':
      return 'border-emerald-300/80 bg-emerald-100 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/45 dark:text-emerald-100'
    case 'pending':
      return 'border-amber-300/80 bg-amber-100 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100'
    case 'rejected':
      return 'border-rose-300/80 bg-rose-100 text-rose-950 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100'
    default:
      return 'border-border bg-muted/80 text-muted-foreground'
  }
}

function correctionStatusLabel(status) {
  if (status === 'approved') return 'Approved'
  if (status === 'pending') return 'Pending'
  if (status === 'rejected') return 'Rejected'
  return status || '—'
}

/**
 * Backend: {@see PayrollComputationService::serializeAttendanceCorrectionForDailyComputation}
 * — `corrected_hours` is authoritative; `requested_clock_span_hours` is wall-clock in→out.
 */
function correctionDurationBlock(item) {
  const hrs =
    item?.corrected_hours != null && Number.isFinite(Number(item.corrected_hours)) ? Number(item.corrected_hours) : null
  if (hrs == null || hrs <= 0) return null
  const basis = item?.duration_basis
  const requestedSpan =
    item?.requested_clock_span_hours != null && Number.isFinite(Number(item.requested_clock_span_hours))
      ? Number(item.requested_clock_span_hours)
      : item?.raw_clock_hours != null && Number.isFinite(Number(item.raw_clock_hours))
        ? Number(item.raw_clock_hours)
        : null
  const scheduledNet =
    item?.scheduled_net_hours != null && Number.isFinite(Number(item.scheduled_net_hours))
      ? Number(item.scheduled_net_hours)
      : item?.scheduled_hours != null && Number.isFinite(Number(item.scheduled_hours))
        ? Number(item.scheduled_hours)
        : null

  if (basis === 'schedule') {
    return {
      main: (
        <>
          <span className="font-semibold text-foreground">Duration: {hrs.toFixed(2)}h</span>
          <span className="text-muted-foreground"> — scheduled (net shift after unpaid break)</span>
        </>
      ),
      scheduledLine:
        scheduledNet != null && Math.abs(scheduledNet - hrs) > 0.05 ? (
          <p className="mt-1 text-[10px] text-muted-foreground/85">
            Scheduled net (shift template): <span className="tabular-nums font-medium text-foreground/90">{scheduledNet.toFixed(2)}h</span>
          </p>
        ) : null,
      requestedNote:
        requestedSpan != null && Math.abs(requestedSpan - hrs) > 0.05 ? (
          <p className="mt-1.5 text-[10px] leading-snug text-muted-foreground/55">
            Requested span (wall-clock in→out):{' '}
            <span className="tabular-nums text-muted-foreground/70">{requestedSpan.toFixed(2)}h</span>
            {' · '}
            <span className="italic">Not used as pay duration when it exceeds scheduled net.</span>
          </p>
        ) : null,
    }
  }
  if (basis === 'net_worked') {
    return {
      main: (
        <>
          <span className="font-semibold text-foreground">Duration: {hrs.toFixed(2)}h</span>
          <span className="text-muted-foreground"> — net from corrected times (unpaid break per schedule)</span>
        </>
      ),
      scheduledLine:
        scheduledNet != null ? (
          <p className="mt-1 text-[10px] text-muted-foreground/80">
            Full-day scheduled net would be{' '}
            <span className="tabular-nums font-medium">{scheduledNet.toFixed(2)}h</span>
            {requestedSpan != null && requestedSpan > hrs + 0.05 ? (
              <>
                {' '}
                · requested wall-clock span{' '}
                <span className="tabular-nums">{requestedSpan.toFixed(2)}h</span>
              </>
            ) : null}
          </p>
        ) : null,
      requestedNote:
        requestedSpan != null && Math.abs(requestedSpan - hrs) > 0.05 && !scheduledNet ? (
          <p className="mt-1.5 text-[10px] text-muted-foreground/60 italic">
            Wall-clock span: <span className="tabular-nums not-italic">{requestedSpan.toFixed(2)}h</span>
          </p>
        ) : null,
    }
  }
  return {
    main: (
      <>
        <span className="font-semibold text-foreground">Duration: {hrs.toFixed(2)}h</span>
        <span className="text-muted-foreground"> — clock span (no schedule on file)</span>
      </>
    ),
    scheduledLine: null,
    requestedNote: null,
  }
}

function presenceFilingIssueKindLabel(kind) {
  const k = String(kind ?? '').toLowerCase().trim()
  if (k === 'both') return 'Missing Clock In and Out'
  if (k === 'missing_in') return 'Missing Clock In'
  if (k === 'missing_out') return 'Missing Clock Out'
  return kind ? String(kind) : null
}

/** Leave request status — green / orange / red. */
function leaveStatusBadgeClass(status) {
  const s = String(status ?? '').toLowerCase()
  if (s === 'approved') {
    return 'border-emerald-300/80 bg-emerald-100 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/45 dark:text-emerald-100'
  }
  if (s === 'pending') {
    return 'border-amber-300/80 bg-amber-100 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100'
  }
  if (s === 'rejected') {
    return 'border-rose-300/80 bg-rose-100 text-rose-950 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100'
  }
  return 'border-border bg-muted/80 text-muted-foreground'
}

function overtimeRequestBadgeClass(status) {
  const s = String(status ?? '').toLowerCase()
  if (s === 'approved') {
    return 'border-emerald-300/80 bg-emerald-100 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/45 dark:text-emerald-100'
  }
  if (s === 'pending') {
    return 'border-amber-300/80 bg-amber-100 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100'
  }
  if (s === 'rejected') {
    return 'border-rose-300/80 bg-rose-100 text-rose-950 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100'
  }
  return 'border-border bg-muted/80 text-muted-foreground'
}

const BREAKDOWN_LABELS = {
  paid_leave: 'Paid leave',
  paid_leave_daily_flat: 'Paid leave (flat)',
  unpaid_leave: 'Unpaid leave',
  undertime_deduction: 'Undertime deduction',
  holiday_premium: 'Holiday premium',
  grace_period_regular_credit: 'Grace / regular credit',
  tardiness: 'Tardiness adjustment',
  nd_premium_blocked: 'ND premium (blocked)',
  unworked_regular_holiday: 'Regular holiday (unworked)',
  special_working_not_worked: 'Special working day (unworked)',
}

function humanizeBreakdownComponent(component) {
  if (!component) return 'Adjustment'
  const key = String(component)
  return BREAKDOWN_LABELS[key] ?? key.replace(/_/g, ' ')
}

function otWorkflowLabelCompact(status) {
  switch (status) {
    case 'approved':
      return 'OT premium included in pay (approved hours).'
    case 'not_filed':
      return 'OT premium not applied — no overtime request for this date.'
    case 'pending_review':
      return 'OT premium not applied until approved (pending does not pay OT premium).'
    case 'partial_pending':
      return 'Some rendered OT has no approved request yet.'
    case 'unapproved':
      return 'Rendered OT exceeds approved + pending in Overtime module.'
    default:
      return 'No rendered OT.'
  }
}

/**
 * Computation breakdown — payroll audit side panel.
 * Hierarchy: who/when → key details → hour pills → ledger & pay signal → policy (collapsed) → flags → actions.
 */
export function AuditDetailDrawer({ open, onOpenChange, record, onRefreshRecord, isRefreshing = false }) {
  const location = useLocation()
  const hrPrefix = location.pathname.match(/^\/(admin|company|branch|department)(?=\/|$)/)?.[0] || '/admin'
  const [recomputing, setRecomputing] = useState(false)
  const [recomputeHint, setRecomputeHint] = useState(null)

  if (!record) return null

  const avatarSrc = userProfileImageSrc(record)

  const paidRegMinRaw = record.paid_regular_minutes
  const paidRegMin =
    paidRegMinRaw != null && Number.isFinite(Number(paidRegMinRaw)) ? Math.max(0, Number(paidRegMinRaw)) : null
  const regularDec = paidRegMin != null ? paidRegMin / 60 : parseHrsToDecimal(record.regular) || 0
  const otDec = parseHrsToDecimal(record.ot) || 0
  const otStatusForPremium = record.ot_status ?? 'none'
  const otPremiumH =
    record.ot_premium_applied_hours != null && Number.isFinite(Number(record.ot_premium_applied_hours))
      ? Math.max(0, Number(record.ot_premium_applied_hours))
      : otStatusForPremium === 'approved'
        ? otDec
        : 0
  const ndDec = parseHrsToDecimal(record.nd) || 0
  const ndRegMin =
    record.regular_night_minutes != null && Number.isFinite(Number(record.regular_night_minutes))
      ? Math.max(0, Number(record.regular_night_minutes))
      : null
  const ndOtMin =
    record.ot_night_minutes != null && Number.isFinite(Number(record.ot_night_minutes))
      ? Math.max(0, Number(record.ot_night_minutes))
      : null
  const ndRegH = ndRegMin != null ? ndRegMin / 60 : null
  const ndOtH = ndOtMin != null ? ndOtMin / 60 : null

  const schedule = {
    shift: record.schedule_label ? formatScheduleLabel12h(record.schedule_label) : '—',
    logDate: record.date
      ? new Date(`${record.date}T12:00:00`).toLocaleDateString('en', { month: 'long', day: 'numeric', year: 'numeric' })
      : '—',
  }

  const actualLogs = {
    timeIn: record.time_in ? formatHHmmTo12h(record.time_in) : '—',
    timeOut: record.time_out ? formatHHmmTo12h(record.time_out) : '—',
  }

  const segmentation = { regular: regularDec.toFixed(2), ot: otDec.toFixed(2), nd: ndDec.toFixed(2) }
  const dayTypeLabel = record.dayType === 'REST DAY' ? 'Rest day' : record.dayType === 'HOLIDAY' ? 'Holiday' : 'Ordinary day'
  const mFirst = record.first_8_multiplier != null ? Number(record.first_8_multiplier).toFixed(2) : '1.00'
  const mOt = record.ot_multiplier != null ? Number(record.ot_multiplier).toFixed(2) : '1.25'
  const ndPrem = ndPremiumFromSnapshot(record.policy_snapshot)
  const mNdBase =
    record.conditions?.nd_base != null && Number.isFinite(Number(record.conditions.nd_base))
      ? Number(record.conditions.nd_base)
      : parseFloat(mFirst)
  /** Backend: ND premium pay only if approved OT OR premium day (rest/holiday/non-ORD / first_8 > 1). */
  const allowNdPremium =
    record.nd_premium_applied === true
      ? true
      : record.nd_premium_applied === false
        ? false
        : Number(record.approved_ot_hours ?? 0) > 0.0001 ||
          record.dayType === 'REST DAY' ||
          record.dayType === 'HOLIDAY' ||
          record.is_rest_day === true ||
          (record.first_8_multiplier != null && Number(record.first_8_multiplier) > 1.0001)
  const ndPremiumScale = allowNdPremium ? 1 : 0
  /** Matches engine: ND on regular uses nd_base × ND%; ND on OT uses OT × ND% × (approved OT ratio). */
  const otNdPremiumRatio = otDec > 0.001 ? Math.min(1, otPremiumH / otDec) : 0
  const weightedUnits =
    parseFloat(segmentation.regular) * parseFloat(mFirst) +
    otPremiumH * parseFloat(mOt) +
    (ndRegH != null && ndOtH != null
      ? (ndRegH * mNdBase * ndPrem + ndOtH * parseFloat(mOt) * ndPrem * otNdPremiumRatio) * ndPremiumScale
      : parseFloat(segmentation.nd) * ndPrem * ndPremiumScale)

  const weightedNdBreakdown =
    ndRegH != null && ndOtH != null
      ? {
          regularNight: ndRegH * mNdBase * ndPrem * ndPremiumScale,
          otNight: ndOtH * parseFloat(mOt) * ndPrem * otNdPremiumRatio * ndPremiumScale,
        }
      : null

  const totalPay = record.total_pay != null && Number.isFinite(Number(record.total_pay)) ? Number(record.total_pay) : null

  const otStatus = record.ot_status ?? 'none'
  const otPremiumBlocked = otDec > 0.001 && otPremiumH < otDec - 0.001
  const ndPremiumBlocked = ndDec > 0.001 && !allowNdPremium
  const weightedUnitsPremiumBlocked = otPremiumBlocked || ndPremiumBlocked
  const showOtPayrollWarning =
    otDec > 0.001 &&
    (otStatus === 'not_filed' ||
      otStatus === 'unapproved' ||
      otStatus === 'pending_review' ||
      otStatus === 'partial_pending' ||
      otPremiumBlocked)

  const auditFlags = record.expanded?.flags?.length
    ? record.expanded.flags.map((flag) => {
        if (flag === 'EXCESSIVE_OT') return 'High overtime — confirm scheduling and approvals.'
        if (flag === 'OT_NOT_FILED')
          return 'Time after shift end with no OT request — OT premium not applied; file in Admin → Overtime if payable.'
        if (flag === 'UNAPPROVED_OT') return 'Rendered OT exceeds approved + pending OT — Admin → Overtime.'
        if (flag === 'PENDING_OT_REVIEW') return 'Pending OT request — approve to finalize.'
        if (flag === 'LATE_DEDUCTION')
          return 'Tardiness policy reduced paid regular hours (aligned with clock-in vs schedule).'
        if (flag === 'MANUAL_PUNCH_ADJ') return 'Manual punch adjustment — verify with supervisor.'
        if (flag === 'ND_PREMIUM_BLOCKED')
          return 'ND hours worked (attendance) but night differential premium not applied — ordinary day with no approved OT and no premium day multiplier.'
        if (flag === 'INCOMPLETE_ATTENDANCE')
          return 'Incomplete clock in/out — presence may still count as Present; file a correction from My Attendance. Approvers use Correction Requests.'
        return `${flag.replace(/_/g, ' ')} — review.`
      })
    : []

  const showAttendanceCorrectionHint = record.expanded?.flags?.includes('INCOMPLETE_ATTENDANCE')

  const handleRecompute = () => {
    setRecomputeHint(null)
    setRecomputing(true)
    window.setTimeout(() => {
      setRecomputing(false)
      const msg = 'Figures reflect current rules; refresh the list to pull a full server re-run when available.'
      setRecomputeHint(msg)
      toast.success('Re-compute preview done', { description: msg })
    }, 900)
  }

  const handleExportPolicyRules = () => {
    try {
      downloadPayrollPolicyManualCsv()
      toast.success('Policy export started', {
        description: 'CSV download — policy multipliers and procedures only (no currency samples).',
      })
    } catch (e) {
      toast.error('Export failed', { description: e?.message || 'Could not generate file.' })
    }
  }

  const dayTypeBadgeClass =
    DAY_TYPE_BADGE_CLASS[record.dayType] ?? DAY_TYPE_BADGE_CLASS.ORDINARY

  const ac = record.attendance_corrections
  const correctionItems = Array.isArray(ac?.items) ? ac.items : []
  const corrApproved = Number(ac?.approved_count ?? 0)
  const corrPending = Number(ac?.pending_count ?? 0)
  const corrRejected = Number(ac?.rejected_count ?? 0)
  const corrTotal = Number(ac?.count ?? correctionItems.length ?? 0)

  const leaveItems = Array.isArray(record.leave_records?.items) ? record.leave_records.items : []
  const leaveCount = Number(record.leave_records?.count ?? leaveItems.length ?? 0)
  const otRec = record.overtime_record ?? {}
  const overtimeItems = Array.isArray(otRec.items) ? otRec.items : []
  const otRejectedH = Number(otRec.rejected_hours ?? 0)
  const engineRegularPay = record.regular_pay != null ? Number(record.regular_pay) : null
  const engineOtPay = record.ot_pay != null ? Number(record.ot_pay) : null
  const engineNdPay = record.nd_pay != null ? Number(record.nd_pay) : null
  const engineHolidayPremium = record.holiday_premium_pay != null ? Number(record.holiday_premium_pay) : null
  const breakdownLines = Array.isArray(record.breakdown) ? record.breakdown : []
  const extraBreakdownRows = breakdownLines.filter((line) => {
    const c = line?.component
    return c && !['regular_pay', 'ot_pay', 'nd_pay'].includes(String(c))
  })

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="@container w-full max-w-[min(100vw-1rem,36rem)] sm:max-w-xl p-0 flex flex-col overflow-hidden bg-card border-l border-border text-foreground shadow-2xl"
        showCloseButton={false}
        aria-describedby="computation-breakdown-desc"
      >
        <span id="computation-breakdown-desc" className="sr-only">
          Payroll computation breakdown for {record.name}, {schedule.logDate}. Includes hours, multipliers, and weighted units.
        </span>

        {/* Header — matches Daily computation "Computation" card header */}
        <div className="flex items-start justify-between gap-3 px-5 pt-5 pb-4 shrink-0 border-b border-border/40 bg-muted/20 dark:border-border/50 dark:bg-muted/30">
          <div className="flex items-start gap-3 min-w-0">
            {avatarSrc ? (
              <img
                src={avatarSrc}
                alt=""
                className="size-12 shrink-0 rounded-full object-cover ring-2 ring-border/60 shadow-sm"
                width={48}
                height={48}
              />
            ) : (
              <div
                className={cn(
                  'flex size-12 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white shadow-sm ring-2 ring-border/50',
                  record.avatarColor || 'bg-slate-600'
                )}
                aria-hidden
              >
                {record.initials ?? '?'}
              </div>
            )}
            <div className="min-w-0">
              <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Computation</p>
              <h2 className="text-lg font-semibold leading-tight text-foreground truncate">{record.name ?? 'Employee'}</h2>
              <div className="flex flex-wrap items-center gap-2 mt-1.5">
                <span className="text-xs font-mono tabular-nums text-muted-foreground">{record.employeeId ?? '—'}</span>
                <span className="text-muted-foreground/50">·</span>
                <time className="text-xs text-muted-foreground tabular-nums" dateTime={record.date}>
                  {schedule.logDate}
                </time>
              </div>
              <div className="mt-2 flex flex-wrap gap-2">
                <Badge className={cn('text-xs font-medium border', dayTypeBadgeClass)}>{dayTypeLabel}</Badge>
                <Badge variant="outline" className="text-xs font-mono border-border/80">
                  {record.rule ?? 'ORD'}
                </Badge>
              </div>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-1">
            {typeof onRefreshRecord === 'function' ? (
              <button
                type="button"
                onClick={() => onRefreshRecord()}
                disabled={isRefreshing}
                className="flex size-9 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                aria-label="Refresh computation from server"
                title="Refresh — latest OT approvals and rules"
              >
                <RefreshCw className={cn('size-5', isRefreshing && 'animate-spin')} aria-hidden />
              </button>
            ) : null}
            <button
              type="button"
              onClick={() => onOpenChange?.(false)}
              className="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              aria-label="Close panel"
            >
              <X className="size-5" />
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto px-4 py-4 @sm:px-5 space-y-4 bg-muted/5 text-[#0A0A0A] dark:bg-muted/10 dark:text-foreground">
          {/* Key computation details — collapsible (saves vertical space; full audit on expand) */}
          <details className="group rounded-2xl border border-border/70 bg-card shadow-sm dark:border-border/60 open:shadow-md">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-left [&::-webkit-details-marker]:hidden">
              <div className="min-w-0">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-foreground">Key computation details</h3>
                <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
                  {buildDayTypeLine(record)}
                  <span className="mx-1.5 text-border">·</span>
                  {otDec > 0.001 ? `${segmentation.ot}h OT (${otStatusHeadline(otStatus)})` : 'No OT'}
                </p>
              </div>
              <ChevronDown
                className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                aria-hidden
              />
            </summary>
            <div className="border-t border-border/60 px-4 pb-4 pt-1 dark:border-border/50">
              <dl className="grid grid-cols-1 gap-x-4 gap-y-2.5 text-sm @sm:grid-cols-[minmax(7rem,9.5rem)_1fr] @sm:gap-y-2">
                <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Day type</dt>
                <dd className="min-w-0 text-foreground leading-snug break-words">{buildDayTypeLine(record)}</dd>
                <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Tardiness</dt>
                <dd className="min-w-0 text-foreground leading-snug break-words">{buildKeyDetailsTardiness(record)}</dd>
                <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Night differential</dt>
                <dd className="min-w-0 text-foreground leading-snug break-words">
                  {ndDec > 0.001 ? (
                    <>
                      {segmentation.nd} hrs · {ndLabelFromSnapshot(record.policy_snapshot)}
                    </>
                  ) : (
                    '—'
                  )}
                </dd>
                <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Overtime status</dt>
                <dd className="min-w-0">
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                    <span className="min-w-0 text-foreground leading-snug break-words">{buildOtKeyLine(record, otDec)}</span>
                    <Badge
                      variant="outline"
                      className={cn(
                        'w-fit max-w-full shrink-0 self-start text-[10px] font-semibold leading-tight whitespace-normal sm:max-w-[10rem] sm:text-right',
                        otStatusBadgeClass(otStatus)
                      )}
                    >
                      {otStatusHeadline(otStatus)}
                    </Badge>
                  </div>
                </dd>
                {record.expanded?.flags?.length ? (
                  <>
                    <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Flags</dt>
                    <dd className="text-amber-950 dark:text-amber-100/90 text-[13px] leading-snug break-words">
                      {flagLabels(record.expanded.flags)}
                    </dd>
                  </>
                ) : null}
                <dt className="text-[11px] font-medium text-muted-foreground @sm:pt-0.5">Computation basis</dt>
                <dd className="min-w-0 text-[13px] leading-relaxed text-foreground">
                  <div className="space-y-2 rounded-lg bg-muted/30 px-3 py-2 dark:bg-muted/20">
                    <div>
                      <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Scheduled</p>
                      <p className="mt-0.5 break-words">{schedule.shift}</p>
                    </div>
                    <div className="border-t border-border/50 pt-2 dark:border-border/40">
                      <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Actual in / out</p>
                      <p className="mt-0.5 break-words tabular-nums">
                        {actualLogs.timeIn} → {actualLogs.timeOut}
                      </p>
                    </div>
                  </div>
                </dd>
              </dl>
              {showOtPayrollWarning ? (
                <p
                  className={cn(
                    'mt-3 flex gap-2 rounded-lg border px-3 py-2 text-[12px]',
                    otStatus === 'not_filed'
                      ? 'border-orange-300/80 bg-orange-50/95 text-orange-950 dark:border-orange-800/50 dark:bg-orange-950/25 dark:text-orange-100'
                      : 'border-amber-300/70 bg-amber-50/90 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-100'
                  )}
                  role="status"
                >
                  <AlertTriangle className="size-4 shrink-0 mt-0.5 opacity-90" aria-hidden />
                  <span>
                    {otStatus === 'not_filed'
                      ? 'OT premium is not applied — there is no overtime request for this date in Admin → Overtime. Weighted units use attendance for display only; pay follows approved OT only.'
                      : otStatus === 'pending_review' || otStatus === 'partial_pending'
                        ? 'OT premium applies only to approved hours. Pending requests do not increase pay until approved.'
                        : 'Rendered OT exceeds what is approved or pending in Admin → Overtime — review before final pay.'}
                  </span>
                </p>
              ) : null}
              {ndPremiumBlocked ? (
                <p
                  className="mt-2 flex gap-2 rounded-lg border border-orange-300/80 bg-orange-50/95 px-3 py-2 text-[12px] text-orange-950 dark:border-orange-800/50 dark:bg-orange-950/25 dark:text-orange-100"
                  role="status"
                >
                  <AlertTriangle className="size-4 shrink-0 mt-0.5 opacity-90" aria-hidden />
                  <span>
                    ND premium not applied — ordinary working day with no approved overtime and no premium day multiplier. Night hours still show for attendance; weighted units exclude the +10% ND slice.
                  </span>
                </p>
              ) : null}
            </div>
          </details>

          {/* Leave — same enriched payload as Daily Computation table */}
          <details
            className="group rounded-2xl border border-border/70 bg-card shadow-sm open:shadow-md dark:border-border/60"
            open={leaveCount > 0}
          >
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-left [&::-webkit-details-marker]:hidden">
              <div className="flex min-w-0 items-start gap-3">
                <div
                  className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100"
                  aria-hidden
                >
                  <Palmtree className="size-4 opacity-90" />
                </div>
                <div className="min-w-0">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-[#0A0A0A] dark:text-foreground">
                    Leave &amp; time off
                  </h3>
                  <p className="mt-0.5 text-[11px] text-muted-foreground leading-snug">
                    Requests covering this calendar day (approved and pending). Credits shown when stored on the request.
                  </p>
                </div>
              </div>
              <ChevronDown
                className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                aria-hidden
              />
            </summary>
            <div className="border-t border-border/55 px-4 pb-4 pt-2 dark:border-border/45">
              {leaveCount === 0 ? (
                <p className="text-sm text-muted-foreground">No leave request covers this date.</p>
              ) : (
                <ul className="max-h-[min(36vh,20rem)] space-y-3 overflow-y-auto pr-1">
                  {leaveItems.map((item) => {
                    const st = String(item?.status ?? '').toLowerCase()
                    return (
                      <li
                        key={item.id ?? `${item.type}-${item.start_date}`}
                        className="rounded-xl border border-border/60 bg-muted/10 px-3 py-2.5 dark:border-border/50 dark:bg-muted/20"
                      >
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="outline" className={cn('border px-2 py-0.5 text-[10px] font-bold uppercase', leaveStatusBadgeClass(st))}>
                            {st === 'approved' ? 'Approved' : st === 'pending' ? 'Pending' : st === 'rejected' ? 'Rejected' : item.status ?? '—'}
                          </Badge>
                          <span className="text-sm font-semibold text-[#0A0A0A] dark:text-foreground">{String(item?.type ?? 'leave')}</span>
                          {item?.day_fraction != null && Number(item.day_fraction) < 1 ? (
                            <Badge variant="outline" className="text-[10px]">
                              Half day
                            </Badge>
                          ) : null}
                        </div>
                        <p className="mt-1.5 text-[11px] tabular-nums text-muted-foreground">
                          {item?.start_date} → {item?.end_date}
                          {item?.request_span_days != null ? ` · span ${item.request_span_days} day(s)` : ''}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-[#0A0A0A] dark:text-foreground">
                          {item?.leave_credits_charged != null ? (
                            <span>
                              <span className="text-muted-foreground">Credits charged: </span>
                              <span className="font-medium tabular-nums">{item.leave_credits_charged}</span>
                            </span>
                          ) : null}
                          {item?.leave_unpaid_credit_days != null && item.leave_unpaid_credit_days > 0 ? (
                            <span>
                              <span className="text-muted-foreground">Unpaid credit days: </span>
                              <span className="font-medium tabular-nums">{item.leave_unpaid_credit_days}</span>
                            </span>
                          ) : null}
                        </div>
                        {item?.half_type ? (
                          <p className="mt-1 text-[11px] text-muted-foreground">Half type: {item.half_type}</p>
                        ) : null}
                        {item?.notes ? (
                          <p className="mt-2 text-[12px] leading-snug text-[#0A0A0A] dark:text-foreground break-words">{item.notes}</p>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-x-3 text-[10px] text-muted-foreground">
                          {item?.filed_at ? <span>Filed {formatIsoShort(item.filed_at)}</span> : null}
                          {item?.reviewed_at ? <span>Reviewed {formatIsoShort(item.reviewed_at)}</span> : null}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>
          </details>

          {/* Overtime module — line items + engine OT pay */}
          <details
            className="group rounded-2xl border border-border/70 bg-card shadow-sm open:shadow-md dark:border-border/60"
            open={overtimeItems.length > 0 || Number(otRec.approved_hours ?? 0) > 0.01}
          >
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-left [&::-webkit-details-marker]:hidden">
              <div className="flex min-w-0 items-start gap-3">
                <div
                  className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-950 dark:bg-amber-950/45 dark:text-amber-100"
                  aria-hidden
                >
                  <Briefcase className="size-4 opacity-90" />
                </div>
                <div className="min-w-0">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-[#0A0A0A] dark:text-foreground">Overtime</h3>
                  <p className="mt-0.5 text-[11px] text-muted-foreground leading-snug">
                    Requests in Admin → Overtime for this date. Pay engine uses approved hours × day rule OT multiplier.
                  </p>
                  {overtimeItems.length > 0 ? (
                    <p className="mt-2 text-sm font-medium tabular-nums text-[#0A0A0A] dark:text-foreground">
                      <span className="text-emerald-700 dark:text-emerald-400">{Number(otRec.approved_hours ?? 0).toFixed(2)}h approved</span>
                      <span className="mx-1.5 text-muted-foreground/60">/</span>
                      <span className="text-amber-800 dark:text-amber-300">{Number(otRec.pending_hours ?? 0).toFixed(2)}h pending</span>
                      {otRejectedH > 0.01 ? (
                        <>
                          <span className="mx-1.5 text-muted-foreground/60">/</span>
                          <span className="text-rose-800 dark:text-rose-300">{otRejectedH.toFixed(2)}h rejected</span>
                        </>
                      ) : null}
                    </p>
                  ) : null}
                </div>
              </div>
              <ChevronDown
                className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                aria-hidden
              />
            </summary>
            <div className="border-t border-border/55 px-4 pb-4 pt-2 dark:border-border/45 space-y-3">
              <div className="rounded-xl border border-border/55 bg-muted/15 px-3 py-2.5 dark:border-border/45 dark:bg-muted/25">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Day rule (attendance)</p>
                <p className="mt-1 text-sm font-mono text-[#0A0A0A] dark:text-foreground">
                  First 8h × {mFirst} · OT × {mOt} ({record.rule ?? 'ORD'})
                </p>
                <p className="mt-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">OT pay in engine (this day)</p>
                <p className="mt-0.5 text-base font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                  {engineOtPay != null && Number.isFinite(engineOtPay) ? formatPhpAmount(engineOtPay) : '—'}
                </p>
                <p className="mt-1 text-[10px] leading-snug text-muted-foreground">
                  Estimate per request (below) uses hourly rate = daily rate ÷ 8 × PH rule OT factor; final pay follows segmentation and approvals.
                </p>
              </div>
              {overtimeItems.length === 0 ? (
                <p className="text-sm text-muted-foreground">No overtime requests filed for this date.</p>
              ) : (
                <ul className="max-h-[min(40vh,22rem)] divide-y divide-border/50 overflow-y-auto dark:divide-border/40">
                  {overtimeItems.map((item) => {
                    const st = String(item?.status ?? '').toLowerCase()
                    const h = Number(item?.computed_hours ?? 0)
                    const est = item?.estimated_ot_pay != null ? Number(item.estimated_ot_pay) : null
                    return (
                      <li key={item.id} className="py-3 first:pt-1">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                              <Badge
                                variant="outline"
                                className={cn(
                                  'shrink-0 border px-2 py-0.5 text-[10px] font-bold uppercase',
                                  overtimeRequestBadgeClass(st),
                                )}
                              >
                                {st === 'approved' ? 'Approved' : st === 'pending' ? 'Pending' : st === 'rejected' ? 'Rejected' : item.status ?? '—'}
                              </Badge>
                              {item?.ph_ot_rule ? (
                                <span className="font-mono text-[11px] text-muted-foreground">{item.ph_ot_rule}</span>
                              ) : null}
                            </div>
                            <p className="mt-1 text-[12px] text-[#0A0A0A] dark:text-foreground">
                              <span className="font-semibold tabular-nums">{h.toFixed(2)}h</span>
                              <span className="text-muted-foreground"> · OT multiplier </span>
                              <span className="font-mono font-semibold">{Number(item?.ot_multiplier ?? mOt).toFixed(2)}×</span>
                              {item?.first_8_multiplier != null ? (
                                <>
                                  <span className="text-muted-foreground"> · day first-8 ref </span>
                                  <span className="font-mono font-semibold">{Number(item.first_8_multiplier).toFixed(2)}×</span>
                                </>
                              ) : null}
                            </p>
                            {item?.ot_type ? (
                              <p className="mt-0.5 text-[11px] text-muted-foreground">Type: {item.ot_type}</p>
                            ) : null}
                            {item?.reason ? (
                              <p className="mt-1 text-[12px] leading-snug text-[#0A0A0A]/90 dark:text-foreground/90 break-words">{item.reason}</p>
                            ) : null}
                          </div>
                          <div className="shrink-0 text-right">
                            {est != null && Number.isFinite(est) && st === 'approved' ? (
                              <p className="text-sm font-semibold tabular-nums text-emerald-800 dark:text-emerald-300">{formatPhpAmount(est)}</p>
                            ) : (
                              <p className="text-[11px] text-muted-foreground">{st === 'pending' ? 'Pay after approval' : st === 'rejected' ? 'Not payable' : '-'}</p>
                            )}
                            <p className="text-[10px] text-muted-foreground">Est. from rule × hrs</p>
                          </div>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-x-3 text-[10px] text-muted-foreground">
                          {item?.approved_at ? <span className="text-emerald-800/90 dark:text-emerald-200/90">Approved {formatIsoShort(item.approved_at)}</span> : null}
                          {item?.rejected_at ? <span className="text-rose-800/90">Rejected {formatIsoShort(item.rejected_at)}</span> : null}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>
          </details>

          {/* Attendance corrections — same API payload as main table + per-request list */}
          <section
            aria-labelledby="attendance-corrections-heading"
            className="rounded-2xl border border-border/70 bg-card shadow-sm dark:border-border/60"
          >
            <div className="flex items-start gap-3 border-b border-border/50 px-4 py-3 dark:border-border/45">
              <div
                className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-900 dark:bg-sky-950/50 dark:text-sky-100"
                aria-hidden
              >
                <ClipboardList className="size-4 opacity-90" />
              </div>
              <div className="min-w-0 flex-1">
                <h3 id="attendance-corrections-heading" className="text-xs font-semibold uppercase tracking-wide text-foreground">
                  Attendance corrections
                </h3>
                <p className="mt-1 text-[11px] text-muted-foreground leading-snug">
                  Same summary as the Daily Computation table. Refresh pulls the latest approvals from the server.
                </p>
                {corrTotal > 0 ? (
                  <p className="mt-2 text-sm font-medium tabular-nums text-foreground">
                    <span className="text-emerald-700 dark:text-emerald-400">{corrApproved} approved</span>
                    <span className="mx-1.5 text-muted-foreground/60">/</span>
                    <span className="text-amber-800 dark:text-amber-300">{corrPending} pending</span>
                    {corrRejected > 0 ? (
                      <>
                        <span className="mx-1.5 text-muted-foreground/60">/</span>
                        <span className="text-rose-800 dark:text-rose-300">{corrRejected} rejected</span>
                      </>
                    ) : null}
                  </p>
                ) : (
                  <p className="mt-2 text-sm text-muted-foreground">No correction requests for this date.</p>
                )}
              </div>
            </div>
            {corrTotal > 0 && correctionItems.length > 0 ? (
              <ul className="max-h-[min(40vh,22rem)] divide-y divide-border/50 overflow-y-auto px-3 py-2 dark:divide-border/40">
                {correctionItems.map((item) => {
                  const st = item?.status ?? 'pending'
                  const dur = correctionDurationBlock(item)
                  return (
                    <li key={item.id ?? `${item.status}-${item.reason_code}`} className="py-3 first:pt-2 last:pb-2">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <Badge
                              variant="outline"
                              className={cn(
                                'shrink-0 border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                correctionStatusBadgeClass(st),
                              )}
                            >
                              {correctionStatusLabel(st)}
                            </Badge>
                            {item?.reason_code ? (
                              <span className="truncate font-mono text-[11px] text-muted-foreground" title={item.reason_code}>
                                {item.reason_code}
                              </span>
                            ) : null}
                          </div>
                          {presenceFilingIssueKindLabel(item?.issue_kind) ? (
                            <p className="mt-1 text-[11px] font-medium text-foreground/90">
                              {presenceFilingIssueKindLabel(item.issue_kind)}
                            </p>
                          ) : null}
                          {item?.remarks ? (
                            <p className="mt-1 text-[12px] leading-snug text-foreground break-words">{item.remarks}</p>
                          ) : null}
                        </div>
                        <div className="shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">
                          {item?.time_in || item?.time_out ? (
                            <p className="font-medium text-foreground">
                              {item.time_in ? formatHHmmTo12h(item.time_in) : '—'} →{' '}
                              {item.time_out ? formatHHmmTo12h(item.time_out) : '—'}
                            </p>
                          ) : (
                            <p className="text-muted-foreground">No times on file</p>
                          )}
                          {dur ? (
                            <div className="mt-0.5 max-w-[14rem] text-left text-[10px] text-muted-foreground sm:ml-auto sm:max-w-[16rem] sm:text-right">
                              <p className="text-[11px] text-foreground">{dur.main}</p>
                              {dur.scheduledLine}
                              {dur.requestedNote}
                            </div>
                          ) : null}
                        </div>
                      </div>
                      <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-muted-foreground">
                        {item?.filed_at ? <span>Filed {formatIsoShort(item.filed_at)}</span> : null}
                        {st === 'approved' && item?.approved_at ? (
                          <span className="text-emerald-800/90 dark:text-emerald-200/90">
                            Approved {formatIsoShort(item.approved_at)}
                          </span>
                        ) : null}
                        {st === 'rejected' && item?.rejected_at ? (
                          <span className="text-rose-800/90 dark:text-rose-200/90">
                            Rejected {formatIsoShort(item.rejected_at)}
                          </span>
                        ) : null}
                      </div>
                    </li>
                  )
                })}
              </ul>
            ) : null}
          </section>

          {/* Hour pills — primary signal */}
          <section aria-labelledby="hours-heading">
            <h3 id="hours-heading" className="sr-only">
              Hours breakdown
            </h3>
            <div className="grid grid-cols-1 gap-2 @sm:grid-cols-3 @sm:items-stretch">
              <div className="rounded-2xl border border-border/60 bg-blue-50/90 px-4 py-3 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div className="flex items-center gap-2 text-blue-800 dark:text-blue-200">
                  <Clock className="size-4 shrink-0 opacity-80" aria-hidden />
                  <span className="text-xs font-medium">Regular</span>
                </div>
                <p className="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-blue-950 dark:text-blue-50">
                  {segmentation.regular}
                  <span className="text-sm font-normal text-blue-700/80 dark:text-blue-300/90 ml-1">hrs</span>
                </p>
                <p className="text-[11px] text-blue-800/70 dark:text-blue-300/80 mt-0.5">Before scheduled end × {mFirst}</p>
              </div>
              <div
                className={cn(
                  'flex min-w-0 flex-col gap-2 rounded-2xl border px-3 py-3 @sm:px-4',
                  otDec > 0.001
                    ? 'border-amber-200/80 bg-amber-50/90 dark:border-amber-900/45 dark:bg-amber-950/25'
                    : 'border-border/60 bg-muted/25 dark:bg-muted/30'
                )}
              >
                <div className="flex min-w-0 flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
                  <div className={cn('flex min-w-0 max-w-[min(100%,12rem)] items-center gap-2', otDec > 0.001 ? 'text-amber-900 dark:text-amber-200' : 'text-muted-foreground')}>
                    <Timer className="size-4 shrink-0 opacity-80" aria-hidden />
                    <span className="text-xs font-medium leading-tight">Rendered OT</span>
                  </div>
                  {otDec > 0.001 ? (
                    <Badge
                      variant="outline"
                      className={cn(
                        'ml-auto shrink-0 border px-1.5 py-0.5 text-[10px] font-bold uppercase leading-snug tracking-wide',
                        'max-w-[min(100%,10.5rem)] whitespace-normal break-words text-end',
                        otStatusBadgeClass(otStatus)
                      )}
                    >
                      {otStatusHeadline(otStatus)}
                    </Badge>
                  ) : null}
                </div>
                <div className="flex min-w-0 flex-wrap items-baseline gap-x-1 gap-y-0">
                  <span
                    className={cn(
                      'text-2xl font-semibold tabular-nums tracking-tight',
                      otDec > 0.001 ? 'text-amber-950 dark:text-amber-50' : 'text-foreground/80'
                    )}
                  >
                    {segmentation.ot}
                  </span>
                  <span className="text-sm font-normal opacity-80">hrs</span>
                </div>
                {otDec > 0.001 ? (
                  <div className="space-y-1 border-t border-amber-200/50 pt-2 dark:border-amber-900/35">
                    <p className="text-[10px] leading-snug text-muted-foreground tabular-nums">
                      Appr. {Number(record.approved_ot_hours ?? 0).toFixed(2)}h · Pend.{' '}
                      {Number(record.pending_ot_hours ?? 0).toFixed(2)}h
                    </p>
                    <p className="text-[10px] leading-snug text-muted-foreground break-words" title={otWorkflowLabel(otStatus)}>
                      {otWorkflowLabelCompact(otStatus)}
                    </p>
                  </div>
                ) : (
                  <p className="text-[10px] leading-snug text-muted-foreground">{otWorkflowLabel(otStatus)}</p>
                )}
              </div>
              <div
                className={cn(
                  'rounded-2xl border px-4 py-3',
                  ndPremiumBlocked
                    ? 'border-orange-300/80 bg-orange-50/90 dark:border-orange-800/50 dark:bg-orange-950/25'
                    : ndDec > 0.001
                      ? 'border-violet-200/80 bg-violet-50/90 dark:border-violet-900/45 dark:bg-violet-950/30'
                      : 'border-border/60 bg-muted/25 dark:bg-muted/30'
                )}
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className={cn('flex items-center gap-2', ndDec > 0.001 ? 'text-violet-900 dark:text-violet-200' : 'text-muted-foreground')}>
                    <Moon className="size-4 shrink-0 opacity-80" aria-hidden />
                    <span className="text-xs font-medium">Night diff</span>
                  </div>
                  {ndPremiumBlocked ? (
                    <Badge
                      variant="outline"
                      className="border-orange-400/80 bg-orange-100/90 text-[10px] font-semibold text-orange-950 dark:border-orange-700 dark:bg-orange-950/40 dark:text-orange-100"
                    >
                      Premium blocked
                    </Badge>
                  ) : null}
                </div>
                <p className={cn('mt-1 text-2xl font-semibold tabular-nums tracking-tight', ndDec > 0.001 ? 'text-violet-950 dark:text-violet-50' : 'text-foreground/80')}>
                  {segmentation.nd}
                  <span className="text-sm font-normal opacity-80 ml-1">hrs</span>
                </p>
                <p className="text-[11px] text-muted-foreground mt-0.5">{ndLabelFromSnapshot(record.policy_snapshot)}</p>
                {ndPremiumBlocked ? (
                  <p className="text-[10px] leading-snug text-orange-900/90 dark:text-orange-200/90 mt-1.5">
                    Worked time in the ND window is shown for DTR; +10% is not in pay/weighted units until OT is approved or the day is a premium day.
                  </p>
                ) : null}
              </div>
            </div>
          </section>

          {/* Schedule + actual — same surface as expanded row in computation table */}
          <div className="rounded-2xl border border-border/60 bg-muted/20 p-4 shadow-sm dark:bg-muted/25">
            <div className="grid grid-cols-2 gap-3 @sm:gap-4 text-sm">
              <div>
                <p className="text-[11px] font-medium text-muted-foreground mb-1">Scheduled shift</p>
                <p className="text-foreground">{schedule.shift}</p>
              </div>
              <div>
                <p className="text-[11px] font-medium text-muted-foreground mb-1">Actual in / out</p>
                <p className="text-foreground">
                  {actualLogs.timeIn} → {actualLogs.timeOut}
                </p>
              </div>
            </div>
            {record.tardiness_label && (
              <div
                className={cn(
                  'mt-3 rounded-xl border px-3 py-2.5',
                  isPresentOnTimeTardinessLabel(record.tardiness_label) &&
                    'border-emerald-200/80 bg-emerald-50/90 dark:border-emerald-900/45 dark:bg-emerald-950/30',
                  record.tardiness_label === 'Half Day' &&
                    'border-amber-200/80 bg-amber-50/90 dark:border-amber-900/45 dark:bg-amber-950/35',
                  !isPresentOnTimeTardinessLabel(record.tardiness_label) &&
                    record.tardiness_label !== 'Half Day' &&
                    'border-violet-200/80 bg-violet-50/90 dark:border-violet-900/45 dark:bg-violet-950/35'
                )}
              >
                <p
                  className={cn(
                    'text-[11px] font-medium',
                    isPresentOnTimeTardinessLabel(record.tardiness_label) && 'text-emerald-800/90 dark:text-emerald-200/90',
                    record.tardiness_label === 'Half Day' && 'text-amber-900/90 dark:text-amber-200/90',
                    !isPresentOnTimeTardinessLabel(record.tardiness_label) &&
                      record.tardiness_label !== 'Half Day' &&
                      'text-violet-800/90 dark:text-violet-200/90'
                  )}
                >
                  Tardiness
                </p>
                <p
                  className={cn(
                    'text-sm font-semibold',
                    isPresentOnTimeTardinessLabel(record.tardiness_label) && 'text-emerald-950 dark:text-emerald-50',
                    record.tardiness_label === 'Half Day' && 'text-amber-950 dark:text-amber-50',
                    !isPresentOnTimeTardinessLabel(record.tardiness_label) &&
                      record.tardiness_label !== 'Half Day' &&
                      'text-violet-950 dark:text-violet-50'
                  )}
                >
                  {record.tardiness_label}
                </p>
                {record.late_deduction_minutes > 0 ? (
                  <p
                    className={cn(
                      'mt-1 text-[11px]',
                      isPresentOnTimeTardinessLabel(record.tardiness_label) && 'text-emerald-900/80 dark:text-emerald-200/75',
                      record.tardiness_label === 'Half Day' && 'text-amber-900/80 dark:text-amber-200/75',
                      !isPresentOnTimeTardinessLabel(record.tardiness_label) &&
                        record.tardiness_label !== 'Half Day' &&
                        'text-violet-900/80 dark:text-violet-200/75'
                    )}
                  >
                    Paid regular reduced by {formatMinutesShort(record.late_deduction_minutes)} (
                    −{(record.late_deduction_minutes / 60).toFixed(2)} hrs) vs segmented regular window.
                  </p>
                ) : isPresentOnTimeTardinessLabel(record.tardiness_label) ? (
                  <p className="mt-1 text-[11px] text-emerald-900/75 dark:text-emerald-200/70">
                    {(record.grace_period_credit_minutes ?? 0) > 0 ? (
                      <>
                        Early arrival or within grace — paid regular is bumped to full scheduled net (+{record.grace_period_credit_minutes}{' '}
                        min vs segmented minutes). Weighted units follow paid regular; no tardiness penalty.
                      </>
                    ) : (
                      <>
                        Present — no tardiness deduction. Regular / weighted units reflect net work in the shift (after unpaid break).
                      </>
                    )}
                  </p>
                ) : null}
              </div>
            )}
          </div>

          {/* Engine pay lines, ND, holiday premium, ledger adjustments */}
          <details className="group rounded-2xl border border-border/70 bg-card shadow-sm open:shadow-md dark:border-border/60" open>
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-left [&::-webkit-details-marker]:hidden">
              <div className="flex min-w-0 items-center gap-3">
                <div
                  className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-900 dark:bg-violet-950/50 dark:text-violet-100"
                  aria-hidden
                >
                  <Calculator className="size-4 opacity-90" />
                </div>
                <div className="min-w-0">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-[#0A0A0A] dark:text-foreground">
                    Related payroll &amp; adjustments
                  </h3>
                  <p className="mt-0.5 text-[11px] text-muted-foreground leading-snug">
                    Amounts from the daily engine; night hours and tardiness for this shift.
                  </p>
                </div>
              </div>
              <ChevronDown
                className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                aria-hidden
              />
            </summary>
            <div className="border-t border-border/55 px-4 pb-4 pt-3 dark:border-border/45 space-y-4">
              <div className="grid grid-cols-2 gap-3 @sm:grid-cols-4">
                <div className="rounded-lg border border-border/50 bg-muted/15 px-2.5 py-2 dark:border-border/40 dark:bg-muted/25">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Regular pay</p>
                  <p className="mt-0.5 text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                    {engineRegularPay != null && Number.isFinite(engineRegularPay) ? formatPhpAmount(engineRegularPay) : '—'}
                  </p>
                </div>
                <div className="rounded-lg border border-border/50 bg-muted/15 px-2.5 py-2 dark:border-border/40 dark:bg-muted/25">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">OT pay</p>
                  <p className="mt-0.5 text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                    {engineOtPay != null && Number.isFinite(engineOtPay) ? formatPhpAmount(engineOtPay) : '—'}
                  </p>
                </div>
                <div className="rounded-lg border border-border/50 bg-muted/15 px-2.5 py-2 dark:border-border/40 dark:bg-muted/25">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">ND pay</p>
                  <p className="mt-0.5 text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                    {engineNdPay != null && Number.isFinite(engineNdPay) ? formatPhpAmount(engineNdPay) : '—'}
                  </p>
                </div>
                <div className="rounded-lg border border-border/50 bg-muted/15 px-2.5 py-2 dark:border-border/40 dark:bg-muted/25">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Holiday premium</p>
                  <p className="mt-0.5 text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                    {engineHolidayPremium != null && Number.isFinite(engineHolidayPremium) ? formatPhpAmount(engineHolidayPremium) : '—'}
                  </p>
                </div>
              </div>
              <div className="grid grid-cols-1 gap-3 @sm:grid-cols-2 rounded-xl border border-border/55 bg-muted/10 px-3 py-2.5 text-sm dark:border-border/45 dark:bg-muted/20">
                <div>
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Night differential (hours)</p>
                  <p className="mt-1 font-semibold tabular-nums text-[#0A0A0A] dark:text-foreground">
                    {segmentation.nd} hrs
                    {ndPremiumBlocked ? (
                      <Badge variant="outline" className="ml-2 border-orange-300 text-[10px] text-orange-900 dark:text-orange-100">
                        Premium off
                      </Badge>
                    ) : null}
                  </p>
                  <p className="mt-0.5 text-[11px] text-muted-foreground">{ndLabelFromSnapshot(record.policy_snapshot)}</p>
                </div>
                <div>
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Tardiness / late</p>
                  <p className="mt-1 text-[#0A0A0A] dark:text-foreground">
                    {record.late_deduction_minutes > 0 ? (
                      <>
                        −{formatMinutesShort(record.late_deduction_minutes)} paid regular (
                        <span className="tabular-nums">−{((record.late_deduction_minutes ?? 0) / 60).toFixed(2)} hrs</span>)
                      </>
                    ) : (
                      <span className="text-muted-foreground">No deduction minutes recorded</span>
                    )}
                  </p>
                  {record.tardiness_label ? (
                    <p className="mt-0.5 text-[11px] text-muted-foreground">Status: {record.tardiness_label}</p>
                  ) : null}
                </div>
              </div>
              {record.holiday_name ? (
                <div className="rounded-lg border border-amber-200/70 bg-amber-50/80 px-3 py-2 dark:border-amber-800/45 dark:bg-amber-950/25">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">Holiday (calendar)</p>
                  <p className="mt-0.5 text-sm font-medium text-amber-950 dark:text-amber-50">{record.holiday_name}</p>
                  <p className="text-[11px] text-amber-900/80 dark:text-amber-200/80">
                    Type: {record.holiday_type ?? '—'} · Rule: {record.rule ?? '—'}
                  </p>
                </div>
              ) : null}
              {extraBreakdownRows.length > 0 ? (
                <div>
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">Other ledger lines</p>
                  <ul className="max-h-48 space-y-1.5 overflow-y-auto rounded-lg border border-border/50 bg-muted/10 px-2 py-2 dark:border-border/40">
                    {extraBreakdownRows.map((line, idx) => {
                      const comp = String(line?.component ?? idx)
                      const amt = line?.amount
                      const n = amt != null ? Number(amt) : null
                      return (
                        <li key={`${comp}-${idx}`} className="flex justify-between gap-2 text-[12px]">
                          <span className="min-w-0 break-words text-[#0A0A0A] dark:text-foreground">{humanizeBreakdownComponent(comp)}</span>
                          <span
                            className={cn(
                              'shrink-0 tabular-nums font-medium',
                              n != null && n < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-[#0A0A0A] dark:text-foreground',
                            )}
                          >
                            {n != null && Number.isFinite(n) ? `${n < 0 ? '−' : ''}${formatPhpAmount(Math.abs(n))}` : '—'}
                          </span>
                        </li>
                      )
                    })}
                  </ul>
                </div>
              ) : null}
            </div>
          </details>

          {/* Ledger — single card, pay signal first */}
          <div className="rounded-2xl border border-border/60 bg-muted/20 p-4 shadow-sm dark:bg-muted/25">
            <p className="text-xs font-semibold text-foreground mb-3">Pay calculation</p>
            <div className="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-border/50 bg-card/80 px-3 py-3 @sm:grid-cols-2">
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Monthly base</p>
                <p className="mt-1 text-base font-semibold tabular-nums text-foreground">{resolveMonthlyBaseDisplay(record)}</p>
                <p className="mt-0.5 text-[10px] text-muted-foreground">
                  Basic salary or monthly rate on file (Admin → employee profile → Salary)
                </p>
              </div>
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Daily rate (this run)</p>
                <p className="mt-1 text-base font-semibold tabular-nums text-foreground">{resolveEffectiveDailyDisplay(record)}</p>
                <p className="mt-0.5 text-[10px] text-muted-foreground">
                  {record.schedule_rate_basis?.working_days_in_calendar_month != null ? (
                    <>
                      Stored daily, or derived from monthly ÷{' '}
                      {Number(record.schedule_rate_basis.working_days_per_month).toFixed(2)} schedule workdays (
                      {record.schedule_rate_basis.calendar_month_label ?? 'calendar month'}; rest & holidays excluded). Same
                      as payroll engine.
                    </>
                  ) : (
                    <>Stored daily, or derived from monthly via schedule-based working days — same as payroll engine</>
                  )}
                </p>
              </div>
            </div>
            <p className="text-[11px] text-muted-foreground mb-3">
              Weighted units: regular × day rule + approved OT premium + ND premium only when allowed (approved OT or premium day). Ordinary day + ND only → ND premium is excluded (same as engine).
            </p>
            <div className="space-y-2.5 text-sm">
              <div className="flex justify-between gap-3 items-baseline">
                <span className="text-muted-foreground">{`Regular × ${mFirst}`}</span>
                <span className="font-mono tabular-nums text-foreground">
                  {segmentation.regular} × {mFirst}
                </span>
              </div>
              <div className="flex flex-col gap-1">
                <div className="flex justify-between gap-3 items-baseline">
                  <span className="text-muted-foreground">{`OT premium (approved) × ${mOt}`}</span>
                  <span className="font-mono tabular-nums text-foreground">
                    {otPremiumH.toFixed(2)} × {mOt}
                  </span>
                </div>
                {otDec > 0.001 && Math.abs(otDec - otPremiumH) > 0.001 ? (
                  <p className="text-[11px] leading-snug text-muted-foreground pl-0">
                    Attendance shows {segmentation.ot} h after shift end; only approved hours earn OT premium.
                  </p>
                ) : null}
              </div>
              {weightedNdBreakdown != null ? (
                <>
                  <div className="flex flex-col gap-0.5">
                    <div className="flex justify-between gap-3 items-baseline">
                      <span className="text-muted-foreground shrink-0">
                        {`ND on regular night × ${mNdBase.toFixed(2)} × ${ndPrem.toFixed(2)}`}
                      </span>
                      <span className="font-mono tabular-nums text-foreground text-right">
                        {ndRegH.toFixed(2)} × {mNdBase.toFixed(2)} × {ndPrem.toFixed(2)}
                        {ndPremiumBlocked ? <span className="text-orange-700 dark:text-orange-300"> → 0</span> : null}
                      </span>
                    </div>
                    <p className="text-[10px] text-muted-foreground">Night window inside paid regular hours (not extra clock time).</p>
                  </div>
                  {ndOtH > 0.001 ? (
                    <div className="flex flex-col gap-0.5">
                      <div className="flex justify-between gap-3 items-baseline">
                        <span className="text-muted-foreground shrink-0">
                          {`ND on OT night × ${mOt} × ${ndPrem.toFixed(2)} × approved`}
                        </span>
                        <span className="font-mono tabular-nums text-foreground text-right">
                          {ndOtH.toFixed(2)} × {mOt} × {ndPrem.toFixed(2)} × {otNdPremiumRatio.toFixed(2)}
                          {ndPremiumBlocked ? <span className="text-orange-700 dark:text-orange-300"> → 0</span> : null}
                        </span>
                      </div>
                      <p className="text-[10px] text-muted-foreground">
                        Night hours in the OT segment use the OT rate for ND (linked to approved OT when OT premium is gated).
                      </p>
                    </div>
                  ) : null}
                  {ndPremiumBlocked ? (
                    <p className="text-[11px] text-orange-800 dark:text-orange-200/90 rounded-md border border-orange-300/60 bg-orange-50/80 px-2 py-1.5 dark:border-orange-800/50 dark:bg-orange-950/30">
                      ND premium not applied — no approved OT or premium day. Weighted units use regular + approved OT only.
                    </p>
                  ) : null}
                </>
              ) : (
                <div className="flex flex-col gap-1">
                  <div className="flex justify-between gap-3 items-baseline">
                    <span className="text-muted-foreground">{`ND (combined) × ${ndPrem.toFixed(2)}`}</span>
                    <span className="font-mono tabular-nums text-foreground">
                      {segmentation.nd} × {ndPrem.toFixed(2)}
                      {ndPremiumBlocked ? <span className="text-orange-700 dark:text-orange-300"> → 0</span> : null}
                    </span>
                  </div>
                  {ndPremiumBlocked ? (
                    <p className="text-[11px] text-orange-800 dark:text-orange-200/90">ND premium excluded from weighted units (see rule above).</p>
                  ) : null}
                </div>
              )}
            </div>
            {totalPay != null && (
              <div className="mt-4 pt-4 border-t border-emerald-200/80 dark:border-emerald-900/40 rounded-b-xl">
                <div className="flex justify-between items-baseline gap-2">
                  <span className="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Day pay (₱)</span>
                  <span className="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">
                    ₱{totalPay.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>
                <p className="text-[11px] text-emerald-800/80 dark:text-emerald-300/70 mt-1">From payroll engine for this calendar day</p>
              </div>
            )}
            <div
              className={cn(
                'mt-4 pt-4 border-t rounded-lg px-2 -mx-2 py-2',
                weightedUnitsPremiumBlocked
                  ? 'border-destructive/50 bg-destructive/5 dark:bg-destructive/10'
                  : 'border-border/50'
              )}
            >
              <div className="flex justify-between items-baseline gap-2">
                <span className="text-sm font-semibold text-foreground">Weighted units</span>
                <span
                  className={cn(
                    'text-xl font-bold tabular-nums',
                    weightedUnitsPremiumBlocked ? 'text-destructive dark:text-red-300' : 'text-foreground'
                  )}
                >
                  {weightedUnits.toFixed(2)}
                </span>
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">
                Regular × day multiplier + approved OT premium + ND premium (only if approved OT or premium day). Ordinary day with ND only and no approved OT → 8.00, not 8.10.
              </p>
              {otPremiumBlocked ? (
                <p className="text-[11px] text-destructive/90 dark:text-red-300/90 mt-1.5">
                  OT premium not applied to full attendance OT — no approved overtime request for the unpaid portion.
                </p>
              ) : null}
              {ndPremiumBlocked ? (
                <p className="text-[11px] text-orange-800 dark:text-orange-300/90 mt-1.5">
                  ND premium not applied — no approved OT or premium day; night differential +10% is excluded from pay base and weighted units.
                </p>
              ) : null}
            </div>
          </div>

          {/* Policy — collapsed */}
          <details className="group rounded-2xl border border-border/60 bg-muted/20 open:shadow-sm dark:bg-muted/25">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm font-medium text-foreground hover:bg-muted/40 dark:hover:bg-muted/40 rounded-2xl [&::-webkit-details-marker]:hidden">
              <span className="flex items-center gap-2">
                <Info className="size-4 text-muted-foreground" aria-hidden />
                Policy &amp; rules reference
              </span>
              <ChevronDown className="size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <div className="border-t border-border/40 px-4 pb-4 pt-2 text-sm space-y-3">
              <ul className="space-y-2 text-[13px] text-muted-foreground">
                <li className="flex gap-2">
                  <span className="text-muted-foreground/60">·</span>
                  <span>
                    <strong className="text-foreground">OT basis:</strong> {record.ot_basis === 'eight_hour_net' ? 'net hours − 8h (legacy)' : 'work at/after scheduled shift end'}
                  </span>
                </li>
                <li className="flex gap-2">
                  <span className="text-muted-foreground/60">·</span>
                  <span>
                    <strong className="text-foreground">ND:</strong> 10:00 PM – 6:00 AM (+10% on applicable HWR)
                  </span>
                </li>
                <li className="flex gap-2">
                  <span className="text-muted-foreground/60">·</span>
                  <span>
                    <strong className="text-foreground">Holiday / rest:</strong>{' '}
                    {record.holiday_name || (record.rules_engine_holiday_type && record.rules_engine_holiday_type !== 'none')
                      ? `${record.holiday_name || ''} ${record.rules_engine_holiday_type ?? ''}`.trim()
                      : 'None (ordinary calendar day)'}
                    {record.is_rest_day ? ' · Scheduled rest day' : ''}
                  </span>
                </li>
              </ul>
              <p className="text-[12px] leading-relaxed text-muted-foreground border-t border-border/40 pt-3">
                {record.ruleTooltip || 'PH Labor Code multipliers apply per rule code.'}
              </p>
            </div>
          </details>

          {showAttendanceCorrectionHint ? (
            <div className="rounded-2xl border border-teal-200/90 bg-teal-50/60 p-4 dark:border-teal-500/35 dark:bg-teal-950/25">
              <h3 className="text-sm font-semibold text-teal-900 dark:text-teal-100 mb-2">Attendance correction</h3>
              <p className="text-sm text-teal-950/90 dark:text-teal-100/90 leading-relaxed">
                File missing punches from <strong className="font-semibold">My Attendance</strong>. Approvers review in{' '}
                <Link
                  to={`${hrPrefix}/corrections`}
                  className="font-semibold text-teal-800 underline underline-offset-2 dark:text-teal-200"
                >
                  Correction requests
                </Link>
                .
              </p>
            </div>
          ) : null}

          {/* Audit flags */}
          {auditFlags.length > 0 ? (
            <div className="rounded-2xl border border-amber-200/90 bg-amber-50/50 p-4 dark:border-amber-500/35 dark:bg-amber-950/20">
              <h3 className="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-100 mb-2">
                <AlertTriangle className="size-4 shrink-0" aria-hidden />
                Review flags
              </h3>
              <ul className="space-y-2">
                {auditFlags.map((desc, i) => (
                  <li key={i} className="flex gap-2 text-sm text-amber-950 dark:text-amber-100/90">
                    <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-500" />
                    {desc}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {/* Audit history — collapsed */}
          <details className="rounded-2xl border border-border/60 bg-muted/20 dark:bg-muted/25">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm font-medium text-foreground hover:bg-muted/40 dark:hover:bg-muted/40 rounded-2xl [&::-webkit-details-marker]:hidden">
              Audit trail
              <ChevronDown className="size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <div className="border-t border-border/40 px-4 pb-4 pt-2 text-sm text-muted-foreground">
              No re-compute or correction events recorded for this view yet.
            </div>
          </details>
        </div>

        {/* Footer — matches computation card chrome */}
        <div className="flex flex-col gap-2 px-4 py-4 @sm:px-5 shrink-0 border-t border-border/40 bg-muted/20 dark:border-border/50 dark:bg-muted/30">
          {recomputeHint ? (
            <p className="flex items-start gap-2 text-xs text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/30 rounded-lg px-3 py-2 border border-emerald-200/60 dark:border-emerald-900/50">
              <CheckCircle2 className="size-4 shrink-0 mt-0.5" aria-hidden />
              {recomputeHint}
            </p>
          ) : null}
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              size="sm"
              className="gap-2 rounded-xl font-semibold"
              disabled={recomputing}
              onClick={handleRecompute}
              aria-busy={recomputing}
            >
              {recomputing ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <RotateCcw className="size-4" aria-hidden />}
              {recomputing ? 'Re-computing…' : 'Re-compute'}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-2 rounded-xl border-border"
              onClick={handleExportPolicyRules}
              title="Download Policy & Rules Engine Manual (CSV, no currency examples)"
            >
              <FileDown className="size-4" aria-hidden />
              Export Rules
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
