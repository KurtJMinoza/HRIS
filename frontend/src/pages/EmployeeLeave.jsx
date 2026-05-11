import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Calendar,
  Loader2,
  Plus,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Eye,
  Clock,
  Inbox,
  ChevronRight,
  Palmtree,
  HeartPulse,
  AlertTriangle,
  Briefcase,
  CalendarClock,
  Paperclip,
  X,
  Info,
  Scale,
  UploadCloud,
  ArrowRight,
  Trash2,
} from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { useToast } from '@/components/ui/use-toast'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { cn } from '@/lib/utils'
import { earliestLeaveStartYmd } from '@/lib/attendanceDates'

const MAX_LEAVE_SUPPORTING_FILES = 5
const MAX_LEAVE_FILE_BYTES = 10 * 1024 * 1024
const EMPLOYEE_LEAVE_STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]
const employeeLeaveCardClass =
  'rounded-[18px] border border-border/70 bg-card shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const employeeLeaveInputClass =
  'h-12 rounded-lg border-border/80 bg-background px-4 text-[15px] font-medium tabular-nums text-foreground shadow-sm dark:border-white/10 dark:bg-background/40'
const employeeLeavePrimaryButtonClass =
  'h-11 gap-2 rounded-lg bg-brand px-5 text-sm font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const employeeLeaveOutlineButtonClass =
  'h-11 gap-2 rounded-lg border-border/80 bg-card px-5 text-sm font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'
const leaveModalFieldClass =
  'h-14 rounded-xl border-border/80 bg-background px-4 text-base font-medium text-foreground shadow-sm transition focus-visible:border-brand focus-visible:ring-brand/25 dark:border-white/12 dark:bg-background/40 dark:focus-visible:border-brand/70'
const leaveModalSelectClass =
  'h-14 w-full rounded-xl border border-brand bg-background px-5 text-lg font-semibold text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-background/40 dark:focus:ring-brand/20'
const leaveModalLabelClass = 'text-base font-semibold tracking-tight text-foreground'
const leaveModalHintClass = 'text-[13px] leading-relaxed text-muted-foreground'

/** @param {{ document_url?: string|null, document_urls?: string[]|null }} leave */
function supportingDocUrls(leave) {
  if (!leave) return []
  if (Array.isArray(leave.document_urls) && leave.document_urls.length) return leave.document_urls
  if (leave.document_url) return [leave.document_url]
  return []
}
import {
  getMyLeaveSummary,
  createMyLeaveRequest,
  deleteMyLeaveRequest,
  uploadMyLeaveDocument,
  getUndertimePreview,
  getPaidLeavePreview,
  profileImageUrl,
  validateMyLeaveDateRange,
} from '@/api'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_MAX_W_MD,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'
import { LeaveRequestDetailModal } from '@/components/leave/LeaveRequestDetailModal'

function formatDateShort(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(`${iso}T12:00:00`)
    return d.toLocaleDateString('en-PH', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function formatDateRangeShort(start, end) {
  if (!start || !end) return '—'
  if (start === end) return formatDateShort(start)
  return `${formatDateShort(start)} – ${formatDateShort(end)}`
}

function formatDateTime(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

/** Normalize API status for comparisons (case-insensitive). */
function normalizeLeaveStatus(status) {
  return String(status ?? '').trim().toLowerCase()
}

function computeLeaveDurationDays(leave) {
  if (!leave?.start_date || !leave?.end_date) return null
  const t = String(leave.type || '').toLowerCase()
  if (t === 'half_day') return 0.5
  if (t === 'undertime') return null
  const s = new Date(`${leave.start_date}T12:00:00`)
  const e = new Date(`${leave.end_date}T12:00:00`)
  const diff = Math.round((e - s) / 86400000) + 1
  return Math.max(0, diff)
}

/** Whole-day credit units consumed (matches backend LeaveCreditService). */
function billableCreditDaysForForm(form) {
  const t = String(form?.type || '').toLowerCase()
  if (t === 'undertime') return 0
  if (t === 'half_day') return 1
  if (!form?.start_date || !form?.end_date) return 0
  const s = new Date(`${form.start_date}T12:00:00`)
  const e = new Date(`${form.end_date}T12:00:00`)
  const diff = Math.round((e - s) / 86400000) + 1
  return Math.max(0, diff)
}

function formConsumesCredits(type) {
  return ['vacation', 'sick', 'emergency', 'other', 'half_day'].includes(String(type || '').toLowerCase())
}

function leaveTypeLabel(type) {
  const t = String(type || '').toLowerCase()
  const map = {
    vacation: 'Vacation',
    sick: 'Sick',
    emergency: 'Emergency',
    undertime: 'Undertime',
    half_day: 'Half day',
    other: 'Other',
  }
  return map[t] || (type ? String(type).replace(/_/g, ' ') : '—')
}

function LeaveTypeBadge({ type }) {
  const t = String(type || '').toLowerCase()
  const map = {
    vacation: 'border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 text-emerald-950 dark:text-emerald-50',
    sick: 'border-rose-200/90 bg-gradient-to-br from-rose-50 to-red-50 text-rose-950 dark:text-rose-100',
    emergency: 'border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50 text-amber-950 dark:text-amber-50',
    undertime: 'border-sky-200/90 bg-gradient-to-br from-sky-50 to-blue-50 text-sky-950 dark:text-sky-50',
    half_day: 'border-violet-200/90 bg-gradient-to-br from-violet-50 to-purple-50 text-violet-950 dark:text-violet-50',
    other: 'border-slate-200/90 bg-gradient-to-br from-slate-50 to-zinc-50 text-slate-900 dark:text-slate-100',
  }
  const Icon =
    t === 'vacation'
      ? Palmtree
      : t === 'sick'
        ? HeartPulse
        : t === 'emergency'
          ? AlertTriangle
          : t === 'undertime'
            ? Clock
            : t === 'half_day'
              ? CalendarClock
              : Briefcase
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold shadow-sm ring-1 ring-black/5 dark:ring-white/10',
        map[t] || map.other
      )}
    >
      <Icon className="size-3.5 shrink-0 opacity-80" aria-hidden />
      {leaveTypeLabel(type)}
    </span>
  )
}

function LeaveStatusPill({ status, displayStatus }) {
  const s = normalizeLeaveStatus(status)
  const label = displayStatus || status || '—'
  if (s === 'rejected') {
    return (
      <span className="inline-flex items-center gap-2 rounded-full border border-red-200/90 bg-gradient-to-br from-red-50 to-rose-50 px-3.5 py-1.5 text-sm font-semibold text-red-900 shadow-sm ring-1 ring-red-100 dark:border-red-900/50 dark:from-red-950/40 dark:to-rose-950/30 dark:text-red-100 dark:ring-red-900/40">
        <XCircle className="size-4 shrink-0" aria-hidden />
        Rejected
      </span>
    )
  }
  if (s === 'approved') {
    return (
      <span className="inline-flex items-center gap-2 rounded-full border border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 px-3.5 py-1.5 text-sm font-semibold text-emerald-950 shadow-sm ring-1 ring-emerald-100 dark:border-emerald-900/40 dark:from-emerald-950/45 dark:to-teal-950/25 dark:text-emerald-50 dark:ring-emerald-900/30">
        <CheckCircle2 className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
        Approved
      </span>
    )
  }
  if (s === 'pending') {
    return (
      <span className="inline-flex max-w-[min(100%,14rem)] items-center gap-2 rounded-full border border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50/80 px-3.5 py-1.5 text-sm font-semibold text-amber-950 shadow-sm ring-1 ring-amber-100 dark:border-amber-900/50 dark:from-amber-950/40 dark:to-orange-950/20 dark:text-amber-50 dark:ring-amber-900/40">
        <Clock className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
        <span className="line-clamp-2 leading-tight">{label}</span>
      </span>
    )
  }
  return (
    <span className="inline-flex max-w-[min(100%,14rem)] items-center gap-2 rounded-full border border-slate-200/90 bg-slate-50 px-3.5 py-1.5 text-sm font-semibold text-slate-800 shadow-sm dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-100">
      <span className="line-clamp-2 leading-tight">{label}</span>
    </span>
  )
}

function RemarksPreviewCell({ text }) {
  const clean = String(text || '').trim()
  if (!clean) {
    return <span className="text-sm text-slate-500 dark:text-slate-400">—</span>
  }
  const short = clean.length > 120 ? `${clean.slice(0, 120)}…` : clean
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          className="group max-w-full text-left text-sm text-slate-800 outline-none transition hover:text-emerald-700 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:text-emerald-300"
        >
          <span className="line-clamp-2 font-normal leading-snug">{short}</span>
          {clean.length > 120 && (
            <span className="mt-1 block text-xs font-semibold text-emerald-600 underline-offset-2 group-hover:underline dark:text-emerald-400">
              View full remarks
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent className="max-h-[min(70vh,24rem)] w-[min(100vw-2rem,28rem)] overflow-y-auto text-sm" align="start">
        <p className="whitespace-pre-wrap leading-relaxed text-foreground">{clean}</p>
      </PopoverContent>
    </Popover>
  )
}

function LeaveCreditsSummaryPanel({ leaveCreditInfo }) {
  if (!leaveCreditInfo) return null

  const remaining = Number(leaveCreditInfo.remaining ?? 0)
  const annual = Number(leaveCreditInfo.annual_allocation ?? 0)
  const effective = Number(leaveCreditInfo.effective_available ?? 0)
  const pending = Number(leaveCreditInfo.pending_reserved_days ?? 0)
  const unpaidNotice =
    !leaveCreditInfo.eligible_for_paid_leave_pool && leaveCreditInfo.unpaid_leave_notice
      ? leaveCreditInfo.unpaid_leave_notice
      : null

  return (
    <section className={cn(employeeLeaveCardClass, 'relative overflow-hidden px-4 py-4 @md:px-5 @md:py-5')}>
      <div className="pointer-events-none absolute -bottom-7 right-1 hidden h-28 w-40 opacity-[0.055] dark:opacity-[0.075] @lg:block" aria-hidden>
        <div className="absolute bottom-0 right-4 h-24 w-24 rounded-lg border-[3px] border-foreground" />
        <div className="absolute bottom-14 right-6 h-2 w-20 rounded-full bg-foreground" />
        <div className="absolute bottom-4 right-11 grid h-14 w-14 grid-cols-2 gap-2">
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
        </div>
        <div className="absolute bottom-7 right-28 h-16 w-7 rounded-full border-2 border-foreground" />
      </div>

      <div className="relative flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-brand/12 text-brand ring-1 ring-brand/20 dark:bg-brand/15">
              <Scale className="size-4" strokeWidth={2.15} aria-hidden />
            </span>
            <h2 className="text-[15px] font-semibold tracking-tight text-foreground">Available credits</h2>
          </div>

          <div className="mt-4 space-y-2 text-sm leading-relaxed text-muted-foreground">
            {leaveCreditInfo.display ? (
              <p className="text-[15px] font-semibold text-foreground">{leaveCreditInfo.display}</p>
            ) : null}
            {leaveCreditInfo.status_summary ? <p>{leaveCreditInfo.status_summary}</p> : null}
            <p>{leaveCreditInfo.recharge_policy || 'Recharge on January 1st every year (full reset; unused credits do not carry over).'}</p>
            <p>
              {pending > 0 ? (
                <>
                  <span className="font-semibold tabular-nums text-foreground">{pending}</span> day{pending === 1 ? '' : 's'} reserved by pending requests.{' '}
                </>
              ) : null}
              Usable for new requests:{' '}
              <span className="font-semibold tabular-nums text-foreground">{Number.isFinite(effective) ? effective : 0}</span>
            </p>
          </div>
        </div>

        <div className="shrink-0 self-start text-left @lg:text-right">
          <p className="text-3xl font-bold tracking-tight text-foreground tabular-nums @md:text-4xl">
            {Number.isFinite(remaining) ? remaining : 0}
            <span className="px-1 text-2xl font-semibold text-muted-foreground @md:text-3xl">/</span>
            <span className="text-xl font-semibold text-foreground @md:text-2xl">{Number.isFinite(annual) ? annual : 0}</span>
          </p>
        </div>
      </div>

      {unpaidNotice ? (
        <div className="relative mt-4 flex items-start gap-2 rounded-lg border border-brand/35 bg-brand/10 px-3.5 py-2.5 text-sm font-medium leading-snug text-foreground dark:bg-brand/12">
          <Info className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
          <span>{unpaidNotice}</span>
        </div>
      ) : null}
    </section>
  )
}

function LeaveEmptyState({ onFileLeave }) {
  return (
    <div className="flex min-h-[330px] flex-col items-center justify-center px-6 py-16 text-center">
      <div className="relative mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
        <Inbox className="size-11" strokeWidth={1.85} aria-hidden />
        <span className="absolute -left-1 top-2 text-lg font-semibold text-brand" aria-hidden>
          +
        </span>
        <span className="absolute -right-2 bottom-3 text-lg font-semibold text-brand/70" aria-hidden>
          +
        </span>
      </div>
      <h3 className="text-xl font-semibold tracking-tight text-foreground">No leave requests</h3>
      <p className="mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground">
        You have no leave requests in the selected date range. File a new leave to get started.
      </p>
      <Button type="button" className={cn(employeeLeavePrimaryButtonClass, 'mt-7 px-6')} onClick={onFileLeave}>
        <Plus className="size-4" />
        File new leave
      </Button>
    </div>
  )
}

function LeaveModalCalendarArt() {
  return (
    <div className="pointer-events-none absolute bottom-0 right-6 hidden h-40 w-72 text-brand opacity-20 dark:opacity-25 @lg:block" aria-hidden>
      <svg viewBox="0 0 280 160" className="h-full w-full" fill="none">
        <path d="M38 152C31 122 36 91 61 62C78 99 69 128 42 152" stroke="currentColor" strokeWidth="2" />
        <path d="M60 63L42 152" stroke="currentColor" strokeWidth="2" />
        <path d="M57 89L43 98M63 108L45 119M51 129L39 137" stroke="currentColor" strokeWidth="2" />
        <path d="M86 152C83 125 93 99 118 75C130 111 119 137 90 152" stroke="currentColor" strokeWidth="2" />
        <path d="M117 76L90 152" stroke="currentColor" strokeWidth="2" />
        <path d="M111 101L96 110M113 122L93 131" stroke="currentColor" strokeWidth="2" />
        <path d="M128 48L260 30L268 152H116L128 48Z" stroke="currentColor" strokeWidth="2" />
        <path d="M125 73L263 55" stroke="currentColor" strokeWidth="2" />
        <path d="M165 36V22C165 16 169 12 174 12C179 12 183 16 183 22V49C183 54 179 58 174 58C170 58 167 56 165 52" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
        <path d="M209 30V16C209 10 213 6 218 6C223 6 227 10 227 16V43C227 48 223 52 218 52C214 52 211 50 209 46" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
        <path d="M247 25V12C247 7 251 3 256 3C261 3 265 7 265 12V38C265 43 261 47 256 47C252 47 249 45 247 41" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
        <path d="M150 88H171V111H150V88ZM190 83H212V106H190V83ZM230 78H252V101H230V78ZM145 124H167V147H145V124ZM187 119H209V142H187V119ZM228 114H250V137H228V114Z" stroke="currentColor" strokeWidth="2" />
      </svg>
    </div>
  )
}

function LeaveModalCreditsCard({
  leaveCreditInfo,
  addForm,
  billableCreditDays,
  paidLeavePreview,
  paidLeavePreviewLoading,
  leaveWillBeFullyPaid,
  leaveWillBeUnpaidNoPool,
  leaveWillBeUnpaidPartial,
  effAvail,
}) {
  if (!leaveCreditInfo) return null

  const consumesCredits = formConsumesCredits(addForm.type)

  return (
    <section className="rounded-xl border border-brand/25 bg-brand/[0.045] px-5 py-5 shadow-sm dark:border-brand/25 dark:bg-brand/10">
      <div className="flex items-start gap-4">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-xl border border-brand/30 bg-brand/10 text-brand dark:bg-brand/15">
          <Calendar className="size-5" aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-2">
          <h3 className="text-lg font-semibold tracking-tight text-foreground">Leave credits</h3>
          {leaveCreditInfo.display ? (
            <p className="text-[15px] font-semibold text-foreground">{leaveCreditInfo.display}</p>
          ) : null}
          {leaveCreditInfo.status_summary ? (
            <p className="text-[15px] leading-relaxed text-muted-foreground">{leaveCreditInfo.status_summary}</p>
          ) : null}
          <p className="text-[15px] leading-relaxed text-muted-foreground">
            <span className="font-semibold tabular-nums text-foreground">
              {leaveCreditInfo.remaining} / {leaveCreditInfo.annual_allocation}
            </span>{' '}
            · This request uses <span className="font-semibold tabular-nums text-foreground">{billableCreditDays}</span>{' '}
            billable credit day{billableCreditDays === 1 ? '' : 's'} (schedule-based).
            {paidLeavePreviewLoading ? <span> Updating...</span> : null}
            <br />
            You have <span className="font-semibold tabular-nums text-foreground">{leaveCreditInfo.effective_available}</span>{' '}
            credits remaining this year (after pending).
          </p>
          {consumesCredits && paidLeavePreview && !paidLeavePreviewLoading ? (
            <>
              {paidLeavePreview.message ? (
                <p
                  className={cn(
                    'text-[15px] font-medium leading-relaxed',
                    leaveWillBeFullyPaid ? 'text-emerald-700 dark:text-emerald-300' : 'text-foreground'
                  )}
                >
                  {paidLeavePreview.message}
                </p>
              ) : null}
              {paidLeavePreview.message_detail ? (
                <p className="text-[13px] leading-relaxed text-muted-foreground">{paidLeavePreview.message_detail}</p>
              ) : null}
            </>
          ) : null}
          {leaveCreditInfo.warning && !(leaveWillBeUnpaidNoPool && consumesCredits) ? (
            <p className="text-[13px] leading-relaxed text-muted-foreground">{leaveCreditInfo.warning}</p>
          ) : null}
          {leaveWillBeUnpaidNoPool && consumesCredits ? (
            <p className="font-semibold leading-relaxed text-brand">
              {leaveCreditInfo.unpaid_leave_notice ||
                leaveCreditInfo.warning ||
                'This leave will be unpaid because you are not yet eligible for paid leave credits.'}
            </p>
          ) : null}
          {leaveWillBeUnpaidPartial && !paidLeavePreview ? (
            <p className="font-semibold leading-relaxed text-brand">
              Only {effAvail} day{effAvail === 1 ? '' : 's'} can be paid from your pool. Extra days will be unpaid if
              approved.
            </p>
          ) : null}
        </div>
      </div>
    </section>
  )
}

export default function EmployeeLeave() {
  const { toast } = useToast()
  const [fromDate, setFromDate] = useState(() => {
    const d = new Date()
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10)
  })
  const [toDate, setToDate] = useState(() => {
    const d = new Date()
    return new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().slice(0, 10)
  })

  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [statusFilter, setStatusFilter] = useState('')
  const [leaveCreditInfo, setLeaveCreditInfo] = useState(null)

  const [undertimePreview, setUndertimePreview] = useState(null)
  const [undertimePreviewLoading, setUndertimePreviewLoading] = useState(false)
  const [undertimePreviewError, setUndertimePreviewError] = useState(null)

  const [restRangeCheck, setRestRangeCheck] = useState(null)
  const [restRangeValidating, setRestRangeValidating] = useState(false)
  const [paidLeavePreview, setPaidLeavePreview] = useState(null)
  const [paidLeavePreviewLoading, setPaidLeavePreviewLoading] = useState(false)

  const [submitting, setSubmitting] = useState(false)
  const leaveSubmitLock = useRef(false)
  const [addError, setAddError] = useState(null)
  const [addOpen, setAddOpen] = useState(false)
  const [leaveDetailOpen, setLeaveDetailOpen] = useState(false)
  const [leaveDetailRow, setLeaveDetailRow] = useState(null)
  const [deleteDialog, setDeleteDialog] = useState({ open: false, leave: null })
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)
  const [addForm, setAddForm] = useState({
    type: 'vacation',
    start_date: '',
    end_date: '',
    undertime_time: '',
    half_type: '',
    reason: '',
    supportingFiles: [],
  })

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await getMyLeaveSummary({ from_date: fromDate, to_date: toDate })
      setRows(Array.isArray(data.leave_requests) ? data.leave_requests : [])
      setLeaveCreditInfo(data.leave_credits && typeof data.leave_credits === 'object' ? data.leave_credits : null)
    } catch (e) {
      setError(e.message)
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [fromDate, toDate])

  useEffect(() => {
    load()
  }, [load])

  function addSupportingFilesFromInput(e) {
    const picked = Array.from(e.target.files || [])
    const input = e.target
    if (input) input.value = ''
    if (!picked.length) return
    setAddForm((prev) => {
      const merged = [...prev.supportingFiles, ...picked].slice(0, MAX_LEAVE_SUPPORTING_FILES)
      return { ...prev, supportingFiles: merged }
    })
  }

  function removeSupportingFile(index) {
    setAddForm((prev) => ({
      ...prev,
      supportingFiles: prev.supportingFiles.filter((_, i) => i !== index),
    }))
  }

  const isUndertime = addForm.type === 'undertime'
  const isHalfDay = addForm.type === 'half_day'
  const isSingleDate = isUndertime || isHalfDay

  const requestedCreditDays = useMemo(() => billableCreditDaysForForm(addForm), [addForm])
  const eligible = Boolean(leaveCreditInfo?.eligible_for_paid_leave_pool)
  const effAvail = Number(leaveCreditInfo?.effective_available ?? 0)
  /** Matches backend schedule-based billable days when preview is loaded. */
  const billableCreditDays =
    paidLeavePreview != null && typeof paidLeavePreview.billable_days === 'number'
      ? paidLeavePreview.billable_days
      : requestedCreditDays
  const leaveWillBeUnpaidPartial =
    addOpen &&
    leaveCreditInfo &&
    formConsumesCredits(addForm.type) &&
    billableCreditDays > 0 &&
    eligible &&
    (paidLeavePreview
      ? paidLeavePreview.paid_days > 0 && paidLeavePreview.unpaid_days > 0
      : requestedCreditDays > effAvail)
  const leaveWillBeUnpaidNoPool =
    addOpen &&
    leaveCreditInfo &&
    formConsumesCredits(addForm.type) &&
    billableCreditDays > 0 &&
    !eligible

  /** Recomputed when the dialog opens so “tomorrow” stays correct if the session crosses midnight. */
  const minLeaveDate = useMemo(() => earliestLeaveStartYmd(), [])
  const minEndDate =
    addForm.start_date && addForm.start_date >= minLeaveDate ? addForm.start_date : minLeaveDate

  const isFormInvalidBasic = (() => {
    if (!addForm.type) return true
    if (!addForm.start_date) return true
    if (!isSingleDate && !addForm.end_date) return true
    if (isUndertime && !addForm.undertime_time) return true
    if (isHalfDay && !addForm.half_type) return true
    return false
  })()

  const rangeEndForRestCheck = isSingleDate ? addForm.start_date : addForm.end_date

  useEffect(() => {
    if (!addOpen || !addForm.start_date || !rangeEndForRestCheck) {
      setRestRangeCheck(null)
      setRestRangeValidating(false)
      return
    }
    if (addForm.start_date < minLeaveDate) {
      setRestRangeCheck(null)
      setRestRangeValidating(false)
      return
    }
    if (!isSingleDate && rangeEndForRestCheck < addForm.start_date) {
      setRestRangeCheck(null)
      setRestRangeValidating(false)
      return
    }
    let cancelled = false
    setRestRangeValidating(true)
    const t = setTimeout(async () => {
      try {
        const data = await validateMyLeaveDateRange({
          start_date: addForm.start_date,
          end_date: rangeEndForRestCheck,
        })
        if (!cancelled) {
          setRestRangeCheck(data)
          setRestRangeValidating(false)
        }
      } catch (e) {
        if (!cancelled) {
          setRestRangeCheck({
            valid: false,
            message: e.message,
            has_schedule: false,
          })
          setRestRangeValidating(false)
        }
      }
    }, 350)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [addOpen, addForm.start_date, rangeEndForRestCheck, minLeaveDate, isSingleDate])

  useEffect(() => {
    if (!addOpen) {
      setPaidLeavePreview(null)
      setPaidLeavePreviewLoading(false)
      return
    }
    if (!formConsumesCredits(addForm.type) || !addForm.start_date) {
      setPaidLeavePreview(null)
      setPaidLeavePreviewLoading(false)
      return
    }
    const end = isSingleDate ? addForm.start_date : addForm.end_date
    if (!end) {
      setPaidLeavePreview(null)
      setPaidLeavePreviewLoading(false)
      return
    }
    if (!isSingleDate && end < addForm.start_date) {
      setPaidLeavePreview(null)
      setPaidLeavePreviewLoading(false)
      return
    }
    let cancelled = false
    setPaidLeavePreviewLoading(true)
    const t = setTimeout(async () => {
      try {
        const data = await getPaidLeavePreview({
          type: addForm.type,
          start_date: addForm.start_date,
          end_date: end,
        })
        if (!cancelled) {
          setPaidLeavePreview(data)
        }
      } catch {
        if (!cancelled) {
          setPaidLeavePreview(null)
        }
      } finally {
        if (!cancelled) {
          setPaidLeavePreviewLoading(false)
        }
      }
    }, 320)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [addOpen, addForm.type, addForm.start_date, addForm.end_date, isSingleDate])

  const restDayBlocksSubmit =
    restRangeValidating || Boolean(restRangeCheck && !restRangeCheck.valid)

  const leaveWillBeFullyPaid =
    addOpen &&
    leaveCreditInfo &&
    formConsumesCredits(addForm.type) &&
    eligible &&
    paidLeavePreview &&
    paidLeavePreview.unpaid_days === 0 &&
    paidLeavePreview.paid_days > 0

  useEffect(() => {
    if (!isUndertime || !addForm.start_date || !addForm.undertime_time) {
      setUndertimePreview(null)
      setUndertimePreviewError(null)
      setUndertimePreviewLoading(false)
      return
    }
    let cancelled = false
    async function loadPreview() {
      setUndertimePreviewLoading(true)
      setUndertimePreviewError(null)
      try {
        const data = await getUndertimePreview({
          date: addForm.start_date,
          undertime_time: addForm.undertime_time,
        })
        if (!cancelled) {
          setUndertimePreview(data)
        }
      } catch (e) {
        if (!cancelled) {
          setUndertimePreview(null)
          setUndertimePreviewError(e.message)
        }
      } finally {
        if (!cancelled) {
          setUndertimePreviewLoading(false)
        }
      }
    }
    loadPreview()
    return () => {
      cancelled = true
    }
  }, [isUndertime, addForm.start_date, addForm.undertime_time])

  async function handleSubmit(e) {
    e.preventDefault()
    setAddError(null)
    const ut = addForm.type === 'undertime'
    const hd = addForm.type === 'half_day'
    const single = ut || hd
    if (!addForm.start_date) {
      setAddError(single ? 'Please select a leave date.' : 'Please select a start date.')
      return
    }
    if (addForm.start_date < minLeaveDate) {
      setAddError('Leave can only be filed for future dates. The earliest start date is tomorrow.')
      return
    }
    if (!single && !addForm.end_date) {
      setAddError('Please select an end date.')
      return
    }
    if (!single && addForm.end_date < addForm.start_date) {
      setAddError('End date must be on or after the start date.')
      return
    }
    if (ut) {
      if (!addForm.undertime_time) {
        setAddError('Approved early-out time is required for undertime leave.')
        return
      }
      if (!addForm.reason || !String(addForm.reason).trim()) {
        setAddError('Reason is required for undertime leave.')
        return
      }
    }
    if (hd) {
      if (!addForm.half_type) {
        setAddError('Please select whether your half day is AM or PM.')
        return
      }
    }
    for (const f of addForm.supportingFiles) {
      if (f.size > MAX_LEAVE_FILE_BYTES) {
        setAddError(`Each file must be at most 10MB (${f.name}).`)
        return
      }
    }
    if (restDayBlocksSubmit) {
      setAddError(
        restRangeCheck?.message ||
          'You cannot file leave on your rest day. Please select working days only.'
      )
      return
    }
    if (submitting || leaveSubmitLock.current) return
    leaveSubmitLock.current = true
    setSubmitting(true)
    try {
      const reasonOpt = addForm.reason.trim()
      const payload = (() => {
        if (ut) {
          return {
            type: addForm.type,
            start_date: addForm.start_date,
            end_date: addForm.start_date,
            undertime_time: addForm.undertime_time,
            reason: addForm.reason,
          }
        }
        if (hd) {
          return {
            type: addForm.type,
            start_date: addForm.start_date,
            end_date: addForm.start_date,
            half_type: addForm.half_type,
            ...(reasonOpt ? { reason: reasonOpt } : {}),
          }
        }
        return {
          type: addForm.type,
          start_date: addForm.start_date,
          end_date: addForm.end_date,
          ...(reasonOpt ? { reason: reasonOpt } : {}),
        }
      })()
      const res = await createMyLeaveRequest(payload)
      const leave = res.leave_request

      if (leave?.id && addForm.supportingFiles.length) {
        for (const file of addForm.supportingFiles) {
          await uploadMyLeaveDocument(leave.id, file)
        }
      }

      setAddForm({
        type: 'vacation',
        start_date: '',
        end_date: '',
        undertime_time: '',
        half_type: '',
        reason: '',
        supportingFiles: [],
      })
      setAddOpen(false)
      await load()
    } catch (err) {
      setAddError(err.message)
    } finally {
      leaveSubmitLock.current = false
      setSubmitting(false)
    }
  }

  function openLeaveDetail(leave) {
    setLeaveDetailRow(leave)
    setLeaveDetailOpen(true)
  }

  async function handleDeleteLeave() {
    if (!deleteDialog.leave) return
    setDeleteSubmitting(true)
    try {
      await deleteMyLeaveRequest(deleteDialog.leave.id)
      toast({ title: 'Leave deleted', description: 'Your pending leave request was deleted.', variant: 'success' })
      setDeleteDialog({ open: false, leave: null })
      if (leaveDetailRow?.id && Number(leaveDetailRow.id) === Number(deleteDialog.leave.id)) {
        setLeaveDetailOpen(false)
        setLeaveDetailRow(null)
      }
      await load()
    } catch (e) {
      toast({ title: 'Failed to delete leave', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  function openFileLeave() {
    setAddError(null)
    setRestRangeCheck(null)
    setRestRangeValidating(false)
    setAddForm({
      type: 'vacation',
      start_date: '',
      end_date: '',
      undertime_time: '',
      half_type: '',
      reason: '',
      supportingFiles: [],
    })
    setAddOpen(true)
  }

  const totalCount = rows.length
  const pendingCount = rows.filter((l) => normalizeLeaveStatus(l.status) === 'pending').length
  const approvedCount = rows.filter((l) => normalizeLeaveStatus(l.status) === 'approved').length
  const rejectedCount = rows.filter((l) => normalizeLeaveStatus(l.status) === 'rejected').length
  const statusCounts = {
    '': totalCount,
    pending: pendingCount,
    approved: approvedCount,
    rejected: rejectedCount,
  }
  const filteredRows = statusFilter
    ? rows.filter((leave) => normalizeLeaveStatus(leave.status) === statusFilter)
    : rows
  const hasTableRows = filteredRows.length > 0

  function renderLeaveTable() {
    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[980px] border-collapse text-[15px]">
          <thead>
            <tr className="border-b border-border/70 bg-card text-left">
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">Leave type</th>
              <th className="min-w-[200px] px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">
                Date / range
              </th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">Duration</th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">
                Supporting documents
              </th>
              <th className="min-w-[180px] px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">
                Reason / remarks
              </th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">Status</th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-foreground">Date filed</th>
              <th className="w-28 px-4 py-4 text-right text-xs font-bold uppercase tracking-wider text-foreground">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <TableBodySkeleton rows={6} cols={8} />
            ) : !hasTableRows ? (
              <tr>
                <td colSpan={8}>
                  {rows.length > 0 ? (
                    <div className="flex min-h-[330px] flex-col items-center justify-center px-6 py-16 text-center">
                      <div className="mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                        <Inbox className="size-11" strokeWidth={1.85} aria-hidden />
                      </div>
                      <h3 className="text-xl font-semibold tracking-tight text-foreground">No {statusFilter} leave requests</h3>
                      <p className="mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground">
                        Choose another status filter or adjust the date range above.
                      </p>
                    </div>
                  ) : (
                    <LeaveEmptyState onFileLeave={openFileLeave} />
                  )}
                </td>
              </tr>
            ) : (
              filteredRows.map((leave, rowIdx) => {
                const dur = computeLeaveDurationDays(leave)
                const durLabel =
                  dur === null
                    ? leave.type === 'undertime'
                      ? '—'
                      : '—'
                    : dur === 0.5
                      ? '0.5 day'
                      : `${dur} day${dur === 1 ? '' : 's'}`
                const remarksPreview = [leave.notes, leave.rejection_note].filter(Boolean).join('\n\n') || ''
                return (
                  <tr
                    key={leave.id}
                    className={cn(
                      'border-b border-border/55 transition-colors duration-150 hover:bg-brand/5 dark:hover:bg-white/[0.045]',
                      rowIdx % 2 === 0 ? 'bg-card' : 'bg-muted/20 dark:bg-white/[0.02]'
                    )}
                  >
                    <td className="px-5 py-4 align-middle">
                      <LeaveTypeBadge type={leave.type} />
                    </td>
                    <td className="px-5 py-4 align-middle text-sm font-medium leading-snug text-foreground">
                      {formatDateRangeShort(leave.start_date, leave.end_date)}
                    </td>
                    <td className="px-5 py-4 align-middle tabular-nums text-muted-foreground">{durLabel}</td>
                    <td className="px-5 py-4 align-middle text-sm">
                      {(() => {
                        const urls = supportingDocUrls(leave)
                        if (!urls.length) {
                          return <span className="text-muted-foreground">No</span>
                        }
                        return (
                          <div className="flex flex-col gap-1">
                            {urls.map((url, i) => (
                              <a
                                key={`${url}-${i}`}
                                href={profileImageUrl(url)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 font-medium text-brand underline-offset-2 hover:underline"
                              >
                                <Paperclip className="size-3.5 shrink-0" aria-hidden />
                                View{urls.length > 1 ? ` (${i + 1})` : ''}
                              </a>
                            ))}
                          </div>
                        )
                      })()}
                    </td>
                    <td className="max-w-[280px] px-5 py-4 align-top">
                      <RemarksPreviewCell text={remarksPreview} />
                    </td>
                    <td className="px-5 py-4 align-middle">
                      <LeaveStatusPill status={leave.status} displayStatus={leave.display_status} />
                    </td>
                    <td className="px-5 py-4 align-middle text-sm tabular-nums text-muted-foreground">
                      {leave.created_at ? formatDateTime(leave.created_at) : '—'}
                    </td>
                    <td className="px-4 py-4 text-right align-middle">
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="gap-1.5 rounded-lg text-foreground hover:bg-brand/10 hover:text-brand dark:hover:bg-brand/12"
                          onClick={() => openLeaveDetail(leave)}
                        >
                          <Eye className="size-4" />
                          View details
                        </Button>
                        {leave.actor_can_delete ? (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="gap-1.5 rounded-lg text-destructive hover:bg-destructive/10"
                            onClick={() => setDeleteDialog({ open: true, leave })}
                          >
                            <Trash2 className="size-4" />
                            Delete
                          </Button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
      </div>
    )
  }

  return (
    <Motion.div
      className="flex min-h-[calc(100vh-6rem)] min-w-0 max-w-full flex-col space-y-6 overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="mx-auto flex w-full max-w-full flex-1 flex-col space-y-7 px-1 @sm:px-0">
        <header className="flex flex-col gap-5 pb-1 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="min-w-0 flex-1">
            <h2 className="text-2xl font-bold tracking-tight text-foreground @md:text-3xl">Leave Management</h2>
            <p className="mt-1 text-sm text-muted-foreground @md:text-[15px]">
              Request leave, attach supporting documents, and track approvals in one place.
            </p>
            {totalCount > 0 ? (
              <p className="mt-1 text-xs text-muted-foreground">
                {pendingCount > 0
                  ? `${pendingCount} pending, ${approvedCount} approved, ${rejectedCount} rejected.`
                  : `No pending leave. ${approvedCount} approved, ${rejectedCount} rejected.`}
              </p>
            ) : null}
          </div>
          <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
            <Button
              type="button"
              variant="outline"
              className={cn(employeeLeaveOutlineButtonClass, 'flex-1 @lg:flex-initial')}
              onClick={() => load()}
              disabled={loading}
            >
              {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
              Refresh
            </Button>
            <Button
              type="button"
              className={cn(employeeLeavePrimaryButtonClass, 'flex-1 @lg:flex-initial')}
              onClick={openFileLeave}
            >
              <Plus className="size-4" />
              File new leave
            </Button>
          </div>
        </header>

        <LeaveCreditsSummaryPanel leaveCreditInfo={leaveCreditInfo} />
        {leaveCreditInfo && Boolean(leaveCreditInfo.__legacy_never_render) && (
          <div className="flex flex-col gap-2 rounded-2xl border border-teal-200/80 bg-gradient-to-br from-teal-50/90 to-emerald-50/50 px-5 py-4 dark:border-teal-900/40 dark:from-teal-950/30 dark:to-emerald-950/20">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <Palmtree className="size-5 text-teal-700 dark:text-teal-400" aria-hidden />
                <span className="text-sm font-semibold text-teal-950 dark:text-teal-100">Available credits</span>
              </div>
              <span className="text-2xl font-bold tabular-nums text-teal-950 dark:text-teal-50">
                {leaveCreditInfo.remaining}{' '}
                <span className="text-base font-semibold text-teal-800/80 dark:text-teal-200/80">/ {leaveCreditInfo.annual_allocation}</span>
              </span>
            </div>
            {leaveCreditInfo.display ? (
              <p className="text-sm font-semibold text-teal-950 dark:text-teal-100">{leaveCreditInfo.display}</p>
            ) : null}
            {leaveCreditInfo.status_summary ? (
              <p className="text-xs text-teal-900/90 dark:text-teal-100/85">{leaveCreditInfo.status_summary}</p>
            ) : null}
            <p className="text-xs text-teal-900/80 dark:text-teal-200/80">
              {leaveCreditInfo.recharge_policy || 'Recharges on January 1st every year.'}
            </p>
            <p className="text-xs text-teal-900/80 dark:text-teal-200/80">
              {leaveCreditInfo.pending_reserved_days
                ? `${leaveCreditInfo.pending_reserved_days} day(s) reserved by pending requests · `
                : ''}
              usable for new requests:{' '}
              <span className="font-semibold">{leaveCreditInfo.effective_available}</span>
            </p>
            {!leaveCreditInfo.eligible_for_paid_leave_pool && leaveCreditInfo.unpaid_leave_notice ? (
              <p className="rounded-lg border border-amber-200/80 bg-amber-50/90 px-3 py-2 text-xs font-medium text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/35 dark:text-amber-100">
                {leaveCreditInfo.unpaid_leave_notice}
              </p>
            ) : null}
          </div>
        )}

        {totalCount > 0 && (
          <div className="grid w-full gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
            <Card className="overflow-hidden border border-border/60 bg-card shadow-md dark:border-white/8">
              <CardContent className="p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Selected period</p>
                    <p className="mt-1 text-4xl font-black tracking-tight text-foreground">{totalCount}</p>
                    <p className="mt-1 text-xs text-muted-foreground">Total requests</p>
                  </div>
                  <div className="flex size-10 items-center justify-center rounded-xl bg-blue-500/15 dark:bg-blue-500/20">
                    <Calendar className="size-5 text-blue-600 dark:text-blue-400" aria-hidden />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className={cn(
              'overflow-hidden border bg-card shadow-md transition-all',
              pendingCount > 0
                ? 'border-amber-400/60 shadow-[0_0_18px_rgba(245,158,11,0.12)] dark:border-amber-500/40'
                : 'border-border/60 dark:border-white/8'
            )}>
              <CardContent className="p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Pending review</p>
                    <p className={cn('mt-1 text-4xl font-black tracking-tight', pendingCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground')}>
                      {pendingCount}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">{pendingCount > 0 ? 'Awaiting action' : 'All cleared'}</p>
                  </div>
                  <div className={cn('flex size-10 items-center justify-center rounded-xl', pendingCount > 0 ? 'bg-amber-500/20' : 'bg-amber-500/10')}>
                    <Clock className={cn('size-5', pendingCount > 0 ? 'text-amber-500 dark:text-amber-400' : 'text-amber-500/50')} aria-hidden />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="overflow-hidden border border-border/60 bg-card shadow-md dark:border-white/8">
              <CardContent className="p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Approved</p>
                    <p className="mt-1 text-4xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">{approvedCount}</p>
                    <p className="mt-1 text-xs text-muted-foreground">Selected period</p>
                  </div>
                  <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/15 dark:bg-emerald-500/20">
                    <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" aria-hidden />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="overflow-hidden border border-border/60 bg-card shadow-md dark:border-white/8">
              <CardContent className="p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">Rejected</p>
                    <p className="mt-1 text-4xl font-black tracking-tight text-rose-600 dark:text-rose-400">{rejectedCount}</p>
                    <p className="mt-1 text-xs text-muted-foreground">Selected period</p>
                  </div>
                  <div className="flex size-10 items-center justify-center rounded-xl bg-rose-500/15 dark:bg-rose-500/20">
                    <XCircle className="size-5 text-rose-600 dark:text-rose-400" aria-hidden />
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {pendingCount > 0 && (
          <div className="flex flex-col items-start justify-between gap-3 rounded-xl border border-amber-400/50 bg-amber-500/10 px-4 py-3.5 dark:border-amber-500/40 dark:bg-amber-500/8 @sm:flex-row @sm:items-center">
            <div className="flex items-center gap-3">
              <AlertTriangle className="size-5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
              <div>
                <p className="font-semibold text-amber-800 dark:text-amber-200">
                  {pendingCount} leave request{pendingCount > 1 ? 's are' : ' is'} waiting for review
                </p>
                <p className="text-xs text-amber-700/70 dark:text-amber-300/60">
                  Open pending requests to track the approval chain and latest remarks.
                </p>
              </div>
            </div>
            <Button
              type="button"
              size="sm"
              className="shrink-0 bg-amber-600 text-white hover:bg-amber-500 dark:bg-amber-600 dark:hover:bg-amber-500"
              onClick={() => setStatusFilter('pending')}
            >
              View Pending
            </Button>
          </div>
        )}

        {/* Period filter */}
        <div className={cn(employeeLeaveCardClass, 'px-4 py-4 @md:px-5')}>
          <div className="flex flex-col gap-4 @lg:flex-row @lg:items-end @lg:justify-between">
            <div className="grid w-full max-w-lg grid-cols-1 gap-3 @sm:grid-cols-2">
              <div className="space-y-2">
                <span className="text-sm font-semibold text-foreground">From</span>
                <Input
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className={employeeLeaveInputClass}
                />
              </div>
              <div className="space-y-2">
                <span className="text-sm font-semibold text-foreground">To</span>
                <Input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className={employeeLeaveInputClass}
                />
              </div>
            </div>
            <p className="max-w-md text-sm leading-relaxed text-muted-foreground @lg:ml-auto @lg:text-right">
              Filter the list below. Filing a new leave is not limited by these dates—you can choose any leave dates in the
              form.
            </p>
          </div>
        </div>

        {error && (
          <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
            {error}
          </div>
        )}

        <Card className={cn(employeeLeaveCardClass, 'w-full min-w-0 flex-1 overflow-hidden')}>
            <CardHeader className="flex flex-col gap-4 border-b border-border/40 bg-muted/10 px-4 py-4 dark:border-border/50 dark:bg-muted/20 @sm:px-6 @sm:py-5">
              <div className="min-w-0">
                <CardTitle className="text-lg font-semibold @md:text-xl">My leave requests</CardTitle>
                <CardDescription className="text-sm @md:text-[15px]">
                  Filter by status. Open details to see the approval chain and remarks.
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {EMPLOYEE_LEAVE_STATUS_OPTIONS.map((opt) => {
                  const active = statusFilter === opt.value
                  const count = statusCounts[opt.value]
                  const activeStyles = {
                    '': 'border-foreground bg-foreground text-background',
                    pending: 'border-amber-500 bg-amber-500 text-white shadow-[0_0_10px_rgba(245,158,11,0.3)]',
                    approved: 'border-emerald-600 bg-emerald-600 text-white',
                    rejected: 'border-rose-600 bg-rose-600 text-white',
                  }
                  const inactiveStyles = {
                    '': 'border-border/60 text-muted-foreground hover:border-foreground/40 hover:text-foreground',
                    pending: 'border-border/60 text-muted-foreground hover:border-amber-400/60 hover:text-amber-600 dark:hover:text-amber-400',
                    approved: 'border-border/60 text-muted-foreground hover:border-emerald-400/60 hover:text-emerald-600 dark:hover:text-emerald-400',
                    rejected: 'border-border/60 text-muted-foreground hover:border-rose-400/60 hover:text-rose-600 dark:hover:text-rose-400',
                  }
                  return (
                    <button
                      key={opt.value || 'all'}
                      type="button"
                      onClick={() => setStatusFilter(opt.value)}
                      className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all',
                        active ? activeStyles[opt.value] || activeStyles[''] : inactiveStyles[opt.value] || inactiveStyles['']
                      )}
                    >
                      {opt.label}
                      <span className={cn(
                        'inline-flex min-w-[18px] items-center justify-center rounded-full px-1 py-0.5 text-[10px] font-bold tabular-nums',
                        active ? 'bg-white/25' : 'bg-muted'
                      )}>
                        {count}
                      </span>
                    </button>
                  )
                })}
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="hidden min-h-[280px] md:block">{renderLeaveTable()}</div>
              <div className="space-y-4 p-4 md:hidden">
                {loading ? (
                  <div className="flex justify-center py-16">
                    <Loader2 className="size-10 animate-spin text-brand" />
                  </div>
                ) : !hasTableRows ? (
                  rows.length > 0 ? (
                    <div className="flex min-h-[260px] flex-col items-center justify-center px-6 py-12 text-center">
                      <div className="mb-5 flex size-20 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                        <Inbox className="size-9" strokeWidth={1.85} aria-hidden />
                      </div>
                      <h3 className="text-lg font-semibold tracking-tight text-foreground">No {statusFilter} leave requests</h3>
                      <p className="mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
                        Choose another status filter or adjust the date range above.
                      </p>
                    </div>
                  ) : (
                    <LeaveEmptyState onFileLeave={openFileLeave} />
                  )
                ) : (
                  <AnimatedSection staggerChildren={0.03} duration={0.4}>
                    {filteredRows.map((leave) => {
                      const dur = computeLeaveDurationDays(leave)
                      const durLabel =
                        dur === null
                          ? '—'
                          : dur === 0.5
                            ? '0.5 day'
                            : `${dur} day${dur === 1 ? '' : 's'}`
                      return (
                        <div key={leave.id} className="space-y-2">
                        <button
                          type="button"
                          onClick={() => openLeaveDetail(leave)}
                          className="w-full rounded-xl border border-border/70 bg-card p-4 text-left shadow-sm transition hover:border-brand/35 hover:bg-brand/5 hover:shadow-md active:scale-[0.99] dark:border-white/10 dark:hover:bg-brand/10"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <LeaveTypeBadge type={leave.type} />
                            <LeaveStatusPill status={leave.status} displayStatus={leave.display_status} />
                          </div>
                          <p className="mt-3 text-sm text-muted-foreground">
                            {formatDateShort(leave.start_date)}
                            {leave.start_date !== leave.end_date && (
                              <>
                                {' '}
                                → {formatDateShort(leave.end_date)}
                              </>
                            )}
                          </p>
                          <p className="mt-1 text-xs font-medium text-muted-foreground">Duration: {durLabel}</p>
                          <p className="mt-1 text-xs text-muted-foreground">
                            Supporting documents:{' '}
                            {(() => {
                              const urls = supportingDocUrls(leave)
                              if (!urls.length) return 'No'
                              return (
                                <span className="inline-flex flex-wrap gap-x-2 gap-y-0.5">
                                  {urls.map((url, i) => (
                                    <a
                                      key={`${url}-${i}`}
                                      href={profileImageUrl(url)}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      onClick={(e) => e.stopPropagation()}
                                      className="font-medium text-brand underline-offset-2 hover:underline"
                                    >
                                      View{urls.length > 1 ? ` ${i + 1}` : ''}
                                    </a>
                                  ))}
                                </span>
                              )
                            })()}
                          </p>
                          <div className="mt-3 flex items-center justify-between border-t border-border/60 pt-3">
                            <span className="text-xs text-muted-foreground">
                              Filed {leave.created_at ? formatDateTime(leave.created_at) : '—'}
                            </span>
                            <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                          </div>
                        </button>
                        {leave.actor_can_delete ? (
                          <Button
                            type="button"
                            variant="outline"
                            className="w-full gap-2 rounded-xl border-destructive/40 text-destructive hover:bg-destructive/10"
                            onClick={() => setDeleteDialog({ open: true, leave })}
                          >
                            <Trash2 className="size-4" />
                            Delete
                          </Button>
                        ) : null}
                        </div>
                      )
                    })}
                  </AnimatedSection>
                )}
              </div>
            </CardContent>
          </Card>

        <p className="text-center text-xs leading-relaxed text-muted-foreground">
          Your request is reviewed by your manager (department, branch, or company head depending on your role), then by HR
          for final approval.
        </p>
      </div>

      {/* File leave dialog */}
      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-7 top-7 size-14 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[92vh] max-w-[min(94vw,68rem)] rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
        >
          <div className="min-h-0 flex-1 overflow-y-auto">
            <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-8 pb-6 pt-8 text-left dark:to-brand/10 @md:px-12">
              <AgcBrandLogo className="mb-7 h-9 @md:h-10" />
              <div className="relative z-10 max-w-[43rem] space-y-3 pr-14 @md:pr-0">
                <DialogTitle className="text-2xl font-bold tracking-tight text-foreground @md:text-3xl">
                  File new leave
                </DialogTitle>
                <DialogDescription className="max-w-[42rem] text-base leading-relaxed text-muted-foreground @md:text-lg">
                  Choose your leave type and dates. The earliest start date is tomorrow. Leave cannot cover dates that
                  already have complete attendance (clock-in and clock-out) for you, and cannot overlap another pending or
                  approved leave. Add optional remarks and supporting documents if needed.
                </DialogDescription>
              </div>
              <LeaveModalCalendarArt />
            </DialogHeader>

            <div className="px-8 py-7 @md:px-12">
            {addError && (
              <div className="mb-5 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive dark:bg-destructive/15">
                {addError}
              </div>
            )}
            <form id="emp-leave-file-form" className="space-y-6" onSubmit={handleSubmit}>
              <div className="space-y-3">
                <Label htmlFor="leave-type" className={leaveModalLabelClass}>
                  Leave type
                </Label>
                <Select value={addForm.type} onValueChange={(value) => setAddForm((prev) => ({ ...prev, type: value }))}>
                  <SelectTrigger id="leave-type" className={leaveModalSelectClass}>
                    <SelectValue>
                      <span className="flex items-center gap-4">
                        <Briefcase className="size-5 text-brand" strokeWidth={2.2} aria-hidden />
                        {leaveTypeLabel(addForm.type)}
                      </span>
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent
                    position="popper"
                    align="start"
                    className="z-[80] rounded-xl border-border/80 bg-popover p-1 text-popover-foreground shadow-xl dark:border-white/10"
                  >
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="vacation">
                      Vacation
                    </SelectItem>
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="sick">
                      Sick
                    </SelectItem>
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="emergency">
                      Emergency
                    </SelectItem>
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="undertime">
                      Undertime
                    </SelectItem>
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="half_day">
                      Half Day
                    </SelectItem>
                    <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="other">
                      Other
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-3">
                <Label className={leaveModalLabelClass}>
                  {addForm.type === 'undertime' || addForm.type === 'half_day' ? 'Leave date' : 'Date range'}
                </Label>
                {addForm.type === 'undertime' || addForm.type === 'half_day' ? (
                  <div className="relative">
                    <Input
                      type="date"
                      min={minLeaveDate}
                      value={addForm.start_date}
                      onChange={(e) => setAddForm((prev) => ({ ...prev, start_date: e.target.value }))}
                      className={leaveModalFieldClass}
                      required
                    />
                  </div>
                ) : (
                  <div className="grid grid-cols-1 gap-4 @md:grid-cols-2">
                    <div className="relative">
                      <span className="pointer-events-none absolute left-4 top-2 text-sm font-medium text-muted-foreground">From</span>
                      <Input
                        type="date"
                        min={minLeaveDate}
                        value={addForm.start_date}
                        onChange={(e) => setAddForm((prev) => ({ ...prev, start_date: e.target.value }))}
                        className={cn(leaveModalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7')}
                        required
                      />
                    </div>
                    <div className="relative">
                      <span className="pointer-events-none absolute left-4 top-2 text-sm font-medium text-muted-foreground">To</span>
                      <Input
                        type="date"
                        min={minEndDate}
                        value={addForm.end_date}
                        onChange={(e) => setAddForm((prev) => ({ ...prev, end_date: e.target.value }))}
                        className={cn(leaveModalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7')}
                        required
                      />
                    </div>
                  </div>
                )}
                {restRangeValidating && addForm.start_date && rangeEndForRestCheck ? (
                  <p className="mt-1.5 flex items-center gap-2 text-[12px] text-muted-foreground">
                    <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
                    Checking dates against your work schedule...
                  </p>
                ) : null}
                {restRangeCheck && !restRangeCheck.valid ? (
                  <p className="mt-1.5 flex items-start gap-2 text-[12px] font-medium text-brand">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    <span>{restRangeCheck.message}</span>
                  </p>
                ) : null}
                {restRangeCheck?.valid && restRangeCheck?.using_default_schedule && restRangeCheck?.schedule_warning ? (
                  <p className="mt-1.5 flex items-start gap-2 text-[12px] leading-snug text-muted-foreground">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-brand" aria-hidden />
                    <span>{restRangeCheck.schedule_warning}</span>
                  </p>
                ) : null}
              </div>

              {addForm.type === 'undertime' ? (
                <div className="space-y-3">
                  <Label htmlFor="undertime-time" className={leaveModalLabelClass}>
                    Approved early-out time
                  </Label>
                  <Input
                    id="undertime-time"
                    type="time"
                    value={addForm.undertime_time}
                    onChange={(e) => setAddForm((prev) => ({ ...prev, undertime_time: e.target.value }))}
                    className={leaveModalFieldClass}
                    required
                  />
                  {undertimePreviewLoading ? (
                    <p className={leaveModalHintClass}>Calculating undertime based on your schedule...</p>
                  ) : null}
                  {undertimePreviewError ? <p className="text-[13px] text-destructive">{undertimePreviewError}</p> : null}
                  {undertimePreview && undertimePreview.undertime_minutes != null ? (
                    <p className="text-[13px] font-medium text-brand">
                      You will incur{' '}
                      <span className="font-semibold">
                        {undertimePreview.undertime_hours != null
                          ? `${Number(undertimePreview.undertime_hours).toFixed(2)} hours`
                          : `${undertimePreview.undertime_minutes} minutes`}
                      </span>{' '}
                      of undertime.
                    </p>
                  ) : null}
                </div>
              ) : null}

              {addForm.type === 'half_day' ? (
                <div className="space-y-3">
                  <Label htmlFor="half-type" className={leaveModalLabelClass}>
                    Half day type
                  </Label>
                  <Select
                    value={addForm.half_type || undefined}
                    onValueChange={(value) =>
                      setAddForm((prev) => ({
                        ...prev,
                        half_type: value,
                      }))
                    }
                    required
                  >
                    <SelectTrigger id="half-type" className={cn(leaveModalFieldClass, 'w-full justify-between')}>
                      <SelectValue placeholder="Select option" />
                    </SelectTrigger>
                    <SelectContent
                      position="popper"
                      align="start"
                      className="z-[80] rounded-xl border-border/80 bg-popover p-1 text-popover-foreground shadow-xl dark:border-white/10"
                    >
                      <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="am">
                        AM Half Day (leave morning, work afternoon)
                      </SelectItem>
                      <SelectItem className="rounded-lg px-4 py-3 text-base focus:bg-brand/10 focus:text-foreground" value="pm">
                        PM Half Day (work morning, leave afternoon)
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              ) : null}

              <div className="space-y-3">
                <Label
                  htmlFor={addForm.type === 'undertime' ? 'undertime-reason' : addForm.type === 'half_day' ? 'half-reason' : 'leave-remarks'}
                  className={leaveModalLabelClass}
                >
                  {addForm.type === 'undertime' ? 'Reason' : 'Reason / remarks'}{' '}
                  {addForm.type === 'undertime' ? null : (
                    <span className="font-normal text-muted-foreground">(optional)</span>
                  )}
                </Label>
                <div className="relative">
                  <Textarea
                    id={addForm.type === 'undertime' ? 'undertime-reason' : addForm.type === 'half_day' ? 'half-reason' : 'leave-remarks'}
                    value={addForm.reason}
                    onChange={(e) => setAddForm((prev) => ({ ...prev, reason: e.target.value }))}
                    rows={4}
                    maxLength={250}
                    required={addForm.type === 'undertime'}
                    placeholder={addForm.type === 'undertime' ? 'Context for approvers...' : 'Optional context for approvers...'}
                    className="min-h-28 resize-none rounded-xl border-border/80 bg-background px-4 pb-9 pt-4 text-base shadow-sm focus-visible:border-brand focus-visible:ring-brand/25 dark:border-white/12 dark:bg-background/40"
                  />
                  <span className="pointer-events-none absolute bottom-4 right-4 text-sm tabular-nums text-muted-foreground">
                    {addForm.reason.length} / 250
                  </span>
                </div>
              </div>

              <LeaveModalCreditsCard
                leaveCreditInfo={leaveCreditInfo}
                addForm={addForm}
                billableCreditDays={billableCreditDays}
                paidLeavePreview={paidLeavePreview}
                paidLeavePreviewLoading={paidLeavePreviewLoading}
                leaveWillBeFullyPaid={leaveWillBeFullyPaid}
                leaveWillBeUnpaidNoPool={leaveWillBeUnpaidNoPool}
                leaveWillBeUnpaidPartial={leaveWillBeUnpaidPartial}
                effAvail={effAvail}
              />

              <div className="space-y-3">
                <Label className={leaveModalLabelClass}>Supporting documents (optional)</Label>
                <div className="rounded-xl border border-dashed border-border bg-muted/15 px-5 py-6 dark:border-white/15 dark:bg-white/[0.03]">
                  <div className="flex flex-col items-center justify-center gap-3 text-center">
                    <label className="flex w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg px-4 py-2 text-muted-foreground transition hover:text-foreground">
                      <UploadCloud className="size-9 text-foreground" strokeWidth={1.7} aria-hidden />
                      <span className="text-base font-medium text-muted-foreground">
                        Drag and drop files here or click to upload
                      </span>
                      <span className={leaveModalHintClass}>
                        Up to {MAX_LEAVE_SUPPORTING_FILES} files. PDF, PNG, JPG, DOC, DOCX up to 10MB each
                      </span>
                      <input
                        type="file"
                        className="sr-only"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        onChange={addSupportingFilesFromInput}
                      />
                    </label>
                  </div>
                  {addForm.supportingFiles.length > 0 ? (
                    <ul className="mt-3 space-y-2">
                      {addForm.supportingFiles.map((f, i) => (
                        <li
                          key={`${f.name}-${i}-${f.size}`}
                          className="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm"
                        >
                          <span className="min-w-0 flex-1 truncate font-medium text-foreground">{f.name}</span>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                            onClick={() => removeSupportingFile(i)}
                            aria-label={`Remove ${f.name}`}
                          >
                            <X className="size-3.5" />
                          </Button>
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </div>
            </form>
            </div>
          </div>

          <DialogFooter className="shrink-0 border-t border-border/70 bg-card px-8 py-5 @md:px-12">
            <Button
              type="button"
              variant="outline"
              className="h-14 min-w-36 rounded-xl border-border/80 bg-card px-8 text-lg font-semibold text-foreground hover:bg-muted dark:border-white/10"
              onClick={() => setAddOpen(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              form="emp-leave-file-form"
              className="h-14 min-w-72 gap-4 rounded-xl bg-brand px-9 text-lg font-semibold text-brand-foreground shadow-[0_14px_28px_-18px_rgba(234,88,12,0.95)] hover:bg-brand-strong dark:shadow-[0_14px_30px_-20px_rgba(251,146,60,0.8)]"
              disabled={submitting || isFormInvalidBasic || restDayBlocksSubmit}
            >
              {submitting && <Loader2 className="size-4 animate-spin" />}
              Submit request
              <ArrowRight className="size-5" aria-hidden />
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={false} onOpenChange={setAddOpen}>
        <DialogContent showCloseButton className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <div className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>File new leave</DialogTitle>
              <DialogDescription className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Choose your leave type and dates. The earliest start date is tomorrow. Leave cannot cover dates that
                already have complete attendance (clock-in and clock-out) for you, and cannot overlap another pending or
                approved leave. Add optional remarks and supporting documents if needed.
              </DialogDescription>
            </div>
          </DialogHeader>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            {addError && (
              <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200">
                {addError}
              </div>
            )}
            <form id="emp-leave-file-form" className="grid gap-4 @md:grid-cols-2" onSubmit={handleSubmit}>
              <div className="space-y-1.5">
                <Label htmlFor="leave-type" className="text-xs">
                  Leave type
                </Label>
                <select
                  id="leave-type"
                  className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1"
                  value={addForm.type}
                  onChange={(e) => setAddForm((prev) => ({ ...prev, type: e.target.value }))}
                >
                  <option value="vacation">Vacation</option>
                  <option value="sick">Sick</option>
                  <option value="emergency">Emergency</option>
                  <option value="undertime">Undertime</option>
                  <option value="half_day">Half Day</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">
                  {addForm.type === 'undertime' || addForm.type === 'half_day' ? 'Leave date' : 'Date range'}
                </Label>
                {addForm.type === 'undertime' || addForm.type === 'half_day' ? (
                  <div className="relative">
                    <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      type="date"
                      min={minLeaveDate}
                      value={addForm.start_date}
                      onChange={(e) => setAddForm((prev) => ({ ...prev, start_date: e.target.value }))}
                      className="h-9 pl-9 text-sm"
                      required
                    />
                  </div>
                ) : (
                  <div className="grid grid-cols-2 gap-2">
                    <div className="relative">
                      <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="date"
                        min={minLeaveDate}
                        value={addForm.start_date}
                        onChange={(e) => setAddForm((prev) => ({ ...prev, start_date: e.target.value }))}
                        className="h-9 pl-9 text-sm"
                        required
                      />
                    </div>
                    <div className="relative">
                      <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="date"
                        min={minEndDate}
                        value={addForm.end_date}
                        onChange={(e) => setAddForm((prev) => ({ ...prev, end_date: e.target.value }))}
                        className="h-9 pl-9 text-sm"
                        required
                      />
                    </div>
                  </div>
                )}
                {restRangeValidating && addForm.start_date && rangeEndForRestCheck ? (
                  <p className="mt-1.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                    <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
                    Checking dates against your work schedule…
                  </p>
                ) : null}
                {restRangeCheck && !restRangeCheck.valid ? (
                  <p className="mt-1.5 flex items-start gap-2 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    <span>{restRangeCheck.message}</span>
                  </p>
                ) : null}
                {restRangeCheck?.valid && restRangeCheck?.using_default_schedule && restRangeCheck?.schedule_warning ? (
                  <p className="mt-1.5 flex items-start gap-2 text-[11px] leading-snug text-sky-900 dark:text-sky-200">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-sky-700 dark:text-sky-300" aria-hidden />
                    <span>{restRangeCheck.schedule_warning}</span>
                  </p>
                ) : null}
              </div>
              {addForm.type === 'undertime' && (
                <>
                  <div className="space-y-1.5">
                    <Label htmlFor="undertime-time" className="text-xs">
                      Approved early-out time
                    </Label>
                    <Input
                      id="undertime-time"
                      type="time"
                      value={addForm.undertime_time}
                      onChange={(e) => setAddForm((prev) => ({ ...prev, undertime_time: e.target.value }))}
                      className="h-9 text-sm"
                      required
                    />
                    {undertimePreviewLoading && (
                      <p className="text-[11px] text-muted-foreground">Calculating undertime based on your schedule…</p>
                    )}
                    {undertimePreviewError && <p className="text-[11px] text-destructive">{undertimePreviewError}</p>}
                    {undertimePreview && undertimePreview.undertime_minutes != null && (
                      <p className="text-[11px] text-amber-700 dark:text-amber-300">
                        You will incur{' '}
                        <span className="font-semibold">
                          {undertimePreview.undertime_hours != null
                            ? `${Number(undertimePreview.undertime_hours).toFixed(2)} hours`
                            : `${undertimePreview.undertime_minutes} minutes`}
                        </span>{' '}
                        of undertime.
                      </p>
                    )}
                  </div>
                  <div className="space-y-1.5 @md:col-span-2">
                    <Label htmlFor="undertime-reason" className="text-xs">
                      Reason
                    </Label>
                    <Textarea
                      id="undertime-reason"
                      value={addForm.reason}
                      onChange={(e) => setAddForm((prev) => ({ ...prev, reason: e.target.value }))}
                      rows={3}
                      required
                      className="min-h-[80px] rounded-lg"
                    />
                  </div>
                </>
              )}
              {addForm.type === 'half_day' && (
                <>
                  <div className="space-y-1.5 @md:col-span-2">
                    <Label htmlFor="half-type" className="text-xs">
                      Half day type
                    </Label>
                    <select
                      id="half-type"
                      className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      value={addForm.half_type}
                      onChange={(e) =>
                        setAddForm((prev) => ({
                          ...prev,
                          half_type: e.target.value,
                        }))
                      }
                      required
                    >
                      <option value="">Select option</option>
                      <option value="am">AM Half Day (leave morning, work afternoon)</option>
                      <option value="pm">PM Half Day (work morning, leave afternoon)</option>
                    </select>
                  </div>
                  <div className="space-y-1.5 @md:col-span-2">
                    <Label htmlFor="half-reason" className="text-xs">
                      Reason / remarks{' '}
                      <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea
                      id="half-reason"
                      value={addForm.reason}
                      onChange={(e) => setAddForm((prev) => ({ ...prev, reason: e.target.value }))}
                      rows={3}
                      placeholder="Optional context for approvers…"
                      className="min-h-[72px] rounded-lg"
                    />
                  </div>
                </>
              )}
              {!isUndertime && !isHalfDay && (
                <div className="space-y-1.5 @md:col-span-2">
                  <Label htmlFor="leave-remarks" className="text-xs">
                    Reason / remarks{' '}
                    <span className="font-normal text-muted-foreground">(optional)</span>
                  </Label>
                  <Textarea
                    id="leave-remarks"
                    value={addForm.reason}
                    onChange={(e) => setAddForm((prev) => ({ ...prev, reason: e.target.value }))}
                    rows={3}
                    placeholder="Optional context for approvers…"
                    className="min-h-[72px] rounded-lg"
                  />
                </div>
              )}
              {leaveCreditInfo && (
                <div className="space-y-2 @md:col-span-2 rounded-xl border border-teal-200/70 bg-teal-50/40 px-3 py-3 text-xs dark:border-teal-900/50 dark:bg-teal-950/25">
                  <p className="font-semibold text-teal-950 dark:text-teal-100">Leave credits</p>
                  {leaveCreditInfo.display ? (
                    <p className="font-semibold text-teal-950 dark:text-teal-100">{leaveCreditInfo.display}</p>
                  ) : null}
                  {leaveCreditInfo.status_summary ? (
                    <p className="text-teal-900/95 dark:text-teal-100/90">{leaveCreditInfo.status_summary}</p>
                  ) : null}
                  <p className="text-teal-900/90 dark:text-teal-100/85">
                    <span className="font-semibold tabular-nums">
                      {leaveCreditInfo.remaining} / {leaveCreditInfo.annual_allocation}
                    </span>{' '}
                    · This request uses{' '}
                    <span className="font-semibold tabular-nums">{billableCreditDays}</span> billable credit day
                    {billableCreditDays === 1 ? '' : 's'} (schedule-based){' '}
                    {paidLeavePreviewLoading ? <span className="text-muted-foreground">· updating…</span> : null} · You have{' '}
                    <span className="font-semibold tabular-nums">{leaveCreditInfo.effective_available}</span> credits
                    remaining this year (after pending).
                  </p>
                  {formConsumesCredits(addForm.type) && paidLeavePreview && !paidLeavePreviewLoading ? (
                    <>
                      {paidLeavePreview.message ? (
                        <p
                          className={
                            leaveWillBeFullyPaid
                              ? 'flex items-start gap-2 font-medium text-emerald-900 dark:text-emerald-100'
                              : 'font-medium text-teal-950 dark:text-teal-100'
                          }
                        >
                          {leaveWillBeFullyPaid ? (
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
                          ) : null}
                          {paidLeavePreview.message}
                        </p>
                      ) : null}
                      {paidLeavePreview.message_detail ? (
                        <p className="text-[11px] leading-snug text-teal-900/85 dark:text-teal-100/80">
                          {paidLeavePreview.message_detail}
                        </p>
                      ) : null}
                    </>
                  ) : null}
                  {leaveCreditInfo.warning &&
                  !(leaveWillBeUnpaidNoPool && formConsumesCredits(addForm.type)) ? (
                    <p className="text-[11px] leading-snug text-muted-foreground">{leaveCreditInfo.warning}</p>
                  ) : null}
                  {leaveWillBeUnpaidNoPool && formConsumesCredits(addForm.type) ? (
                    <p className="flex items-start gap-2 font-medium text-amber-800 dark:text-amber-200">
                      <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                      {leaveCreditInfo.unpaid_leave_notice ||
                        leaveCreditInfo.warning ||
                        'This leave will be unpaid because you are not yet eligible for paid leave credits.'}
                    </p>
                  ) : null}
                  {leaveWillBeUnpaidPartial && !paidLeavePreview ? (
                    <p className="flex items-start gap-2 font-medium text-amber-800 dark:text-amber-200">
                      <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                      Only {effAvail} day{effAvail === 1 ? '' : 's'} can be paid from your pool. Extra days will be unpaid if
                      approved.
                    </p>
                  ) : null}
                </div>
              )}
              <div className="space-y-2 @md:col-span-2">
                <Label className="text-xs">Supporting documents (optional)</Label>
                <div className="rounded-xl border border-dashed border-muted-foreground/35 bg-muted/20 px-4 py-5 dark:border-white/10 dark:bg-white/5">
                  <div className="flex flex-col gap-3 @sm:flex-row @sm:items-center @sm:justify-between">
                    <label className="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium shadow-sm hover:bg-muted/60">
                      <Paperclip className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                      Add files
                      <input
                        type="file"
                        className="sr-only"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        onChange={addSupportingFilesFromInput}
                      />
                    </label>
                    <p className="text-[11px] leading-snug text-muted-foreground">
                      Up to {MAX_LEAVE_SUPPORTING_FILES} files · 10MB each · PDF, JPG, PNG, DOC, DOCX
                    </p>
                  </div>
                  {addForm.supportingFiles.length > 0 ? (
                    <ul className="mt-3 space-y-2">
                      {addForm.supportingFiles.map((f, i) => (
                        <li
                          key={`${f.name}-${i}-${f.size}`}
                          className="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-xs"
                        >
                          <span className="min-w-0 flex-1 truncate font-medium text-foreground">{f.name}</span>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                            onClick={() => removeSupportingFile(i)}
                            aria-label={`Remove ${f.name}`}
                          >
                            <X className="size-3.5" />
                          </Button>
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </div>
            </form>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setAddOpen(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button
              type="submit"
              form="emp-leave-file-form"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              disabled={submitting || isFormInvalidBasic || restDayBlocksSubmit}
            >
              {submitting && <Loader2 className="size-4 animate-spin" />}
              Submit request
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <LeaveRequestDetailModal
        open={leaveDetailOpen}
        onOpenChange={(open) => {
          setLeaveDetailOpen(open)
          if (!open) setLeaveDetailRow(null)
        }}
        leave={leaveDetailRow}
        resolveDocUrl={profileImageUrl}
      />

      <Dialog open={deleteDialog.open} onOpenChange={(open) => !open && setDeleteDialog({ open: false, leave: null })}>
        <DialogContent className={adminFormDialogContentClass('max-w-md')}>
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete leave request</DialogTitle>
              <p className={ADMIN_FORM_DIALOG_DESC_CLASS}>Are you sure you want to delete this request?</p>
            </DialogHeader>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setDeleteDialog({ open: false, leave: null })} disabled={deleteSubmitting}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" onClick={handleDeleteLeave} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
