import { createElement, useEffect, useState, useCallback, useMemo } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Loader2,
  RefreshCw,
  Plus,
  CheckCircle2,
  XCircle,
  Clock,
  Circle,
  Minus,
  User,
  CalendarDays,
  LogIn,
  LogOut,
  Printer,
  ChevronRight,
  Inbox,
  Sparkles,
  Search,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  FileText,
  Send,
  Trash2,
  Info,
  MessageSquareText,
  UsersRound,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/components/ui/use-toast'
import {
  deleteMyPresenceFiling,
  getMyPresenceFilingAttendanceDetail,
  getMyPresenceFilings,
  submitPresenceFiling,
} from '@/api'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  issueLabel,
  remarksUserText,
  reviewStatusKey,
  reviewStatusSortValue,
  formatTimeOnly,
} from '@/lib/presenceFilingTable'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import {
  EmployeeAvatarNameRoleCell,
  ReviewStatusTableBadge,
  RemarksPreviewCell,
  IssueTypeCell,
  TimeCell,
  getInitials,
} from '@/components/presenceFiling/CorrectionTableCells'
import { formatDayName } from '@/components/attendance/attendanceRecordUtils'

const ISSUE_KIND_OPTIONS = [
  { value: 'missing_in', label: 'Missing Clock In' },
  { value: 'missing_out', label: 'Missing Clock Out' },
  { value: 'both', label: 'Both (Clock In and Clock Out)' },
]

function getLocalDateStr() {
  const n = new Date()
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`
}

/** e.g. Wed, Mar 24, 2026 */
function formatAttendanceDate(isoDate) {
  if (!isoDate) return '—'
  try {
    const d = new Date(`${isoDate}T12:00:00`)
    return d.toLocaleDateString('en-PH', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return isoDate
  }
}

function formatDetailDate(date) {
  if (!date) return '—'
  const d = new Date(`${date}T12:00:00`)
  if (Number.isNaN(d.getTime())) return date
  return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })
}

/** Short date for dense tables (list view). */
function formatTableDateShort(isoDate) {
  if (!isoDate) return '—'
  try {
    const d = new Date(`${isoDate}T12:00:00`)
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return isoDate
  }
}

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatAttendanceDetailTime(value) {
  if (!value) return 'Missing'
  if (/^\d{2}:\d{2}/.test(String(value))) {
    const [hour, minute] = String(value).split(':')
    const d = new Date()
    d.setHours(Number(hour), Number(minute), 0, 0)
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
  }
  return new Date(value).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
}

function AttendanceDetailNotice({ detail, loading, error }) {
  if (loading) {
    return (
      <div className="rounded-xl border border-border bg-muted/25 px-4 py-3 text-sm text-muted-foreground">
        <span className="inline-flex items-center gap-2 font-medium">
          <Loader2 className="size-4 animate-spin" />
          Loading attendance detail...
        </span>
      </div>
    )
  }
  if (error) {
    return (
      <div className="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
        {error}
      </div>
    )
  }
  if (!detail) return null

  const toneClass =
    detail.detail_tone === 'info'
      ? 'border-sky-500/35 bg-sky-500/10 text-sky-950 dark:text-sky-100'
      : detail.detail_tone === 'neutral-warning'
        ? 'border-amber-500/35 bg-amber-500/10 text-amber-950 dark:text-amber-100'
        : 'border-orange-500/40 bg-orange-500/10 text-orange-950 dark:text-orange-100'

  return (
    <div className={cn('rounded-xl border px-4 py-3 text-sm shadow-sm', toneClass)}>
      <p className="text-xs font-black uppercase tracking-[0.14em] opacity-80">Attendance detail</p>
      <p className="mt-2 font-bold leading-relaxed">{detail.message}</p>
      <dl className="mt-3 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5 text-xs leading-relaxed">
        <dt className="font-semibold opacity-80">Clock In</dt>
        <dd>{formatAttendanceDetailTime(detail.clock_in)}</dd>
        <dt className="font-semibold opacity-80">Clock Out</dt>
        <dd>{formatAttendanceDetailTime(detail.clock_out)}</dd>
        <dt className="font-semibold opacity-80">Schedule</dt>
        <dd>
          {detail.schedule_start || detail.schedule_end
            ? `${formatAttendanceDetailTime(detail.schedule_start)} - ${formatAttendanceDetailTime(detail.schedule_end)}`
            : 'No schedule found'}
        </dd>
        <dt className="font-semibold opacity-80">Status</dt>
        <dd>{detail.attendance_status || '—'}</dd>
      </dl>
      {Array.isArray(detail.notes) && detail.notes.length > 0 ? (
        <div className="mt-3 space-y-1 border-t border-current/15 pt-3 text-xs leading-relaxed">
          {detail.notes.map((note) => (
            <p key={note}>{note}</p>
          ))}
        </div>
      ) : null}
    </div>
  )
}

function formatApproverLine(step) {
  if (!step || typeof step !== 'object') return '—'
  if (step.key === 'submitted') {
    const n = step.submitter_name
    return n ? `${n} · Requester` : '—'
  }
  const name = step.approver_name
  const role = step.approver_role_label
  if (name && role) return `${name} · ${role}`
  if (role) return role
  if (name) return name
  return '—'
}

function humanStepStatus(status) {
  switch (status) {
    case 'completed':
      return 'Completed'
    case 'current':
      return 'In progress'
    case 'pending':
      return 'Pending'
    case 'rejected':
      return 'Rejected'
    case 'skipped':
      return 'Skipped'
    default:
      return status ? String(status) : '—'
  }
}

/** Main list card — uses `index.css` :root / .dark tokens (`--card`, `--border`). */
const brandCardClass =
  'rounded-2xl border border-border bg-card text-card-foreground shadow-sm dark:shadow-[0_18px_50px_-36px_rgba(0,0,0,0.45)]'

/** Stat icon rings use `--chart-*` / `--destructive` from `index.css` @theme inline. */
function RequestStatCard({ icon, value, label, hint, tone = 'orange' }) {
  const tones = {
    orange: {
      shell:
        'bg-chart-5/15 text-chart-5 ring-chart-5/25 dark:bg-chart-5/20 dark:text-chart-5 dark:ring-chart-5/35',
      value: 'text-chart-5',
    },
    blue: {
      shell:
        'bg-chart-1/15 text-chart-1 ring-chart-1/25 dark:bg-chart-1/20 dark:text-chart-1 dark:ring-chart-1/35',
      value: 'text-chart-1',
    },
    green: {
      shell:
        'bg-chart-2/15 text-chart-2 ring-chart-2/25 dark:bg-chart-2/20 dark:text-chart-2 dark:ring-chart-2/35',
      value: 'text-chart-2',
    },
    red: {
      shell:
        'bg-destructive/10 text-destructive ring-destructive/25 dark:bg-destructive/15 dark:text-destructive dark:ring-destructive/30',
      value: 'text-destructive',
    },
  }
  const t = tones[tone] ?? tones.orange

  return (
    <Card className={cn(brandCardClass, 'overflow-hidden')}>
      <CardContent className="flex items-center gap-5 p-5 @md:p-6">
        <div className={cn('flex size-16 shrink-0 items-center justify-center rounded-full ring-1', t.shell)}>
          {createElement(icon, { className: 'size-7', 'aria-hidden': true })}
        </div>
        <div className="min-w-0">
          <p className={cn('text-3xl font-extrabold leading-none tabular-nums tracking-tight', t.value)}>
            {value}
          </p>
          <p className="mt-2 text-base font-semibold text-foreground">{label}</p>
          <p className="mt-1 text-sm text-muted-foreground">{hint}</p>
        </div>
      </CardContent>
    </Card>
  )
}

/** Large status pill with icon — employee-facing */
function EmployeeStatusPill({ displayStatus, status }) {
  const ds = displayStatus || ''
  const isRejected = status === 'rejected' || ds === 'Rejected'
  const isApproved = status === 'approved' || ds === 'HR Approved'

  if (isRejected) {
    return (
      <span
        className="inline-flex items-center gap-2 rounded-full border border-red-200/90 bg-gradient-to-br from-red-50 to-rose-50 px-3.5 py-1.5 text-sm font-semibold text-red-900 shadow-sm ring-1 ring-red-100 dark:border-red-900/50 dark:from-red-950/40 dark:to-rose-950/30 dark:text-red-100 dark:ring-red-900/40"
      >
        <XCircle className="size-4 shrink-0" aria-hidden />
        Rejected
      </span>
    )
  }
  if (isApproved) {
    return (
      <span
        className="inline-flex items-center gap-2 rounded-full border border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 px-3.5 py-1.5 text-sm font-semibold text-emerald-950 shadow-sm ring-1 ring-emerald-100 dark:border-emerald-900/40 dark:from-emerald-950/45 dark:to-teal-950/25 dark:text-emerald-50 dark:ring-emerald-900/30"
      >
        <CheckCircle2 className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
        Approved
      </span>
    )
  }
  return (
    <span
      className="inline-flex max-w-[min(100%,14rem)] items-center gap-2 rounded-full border border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50/80 px-3.5 py-1.5 text-sm font-semibold text-amber-950 shadow-sm ring-1 ring-amber-100 dark:border-amber-900/50 dark:from-amber-950/40 dark:to-orange-950/20 dark:text-amber-50 dark:ring-amber-900/40"
    >
      <Clock className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
      <span className="line-clamp-2 leading-tight">{ds || 'Pending'}</span>
    </span>
  )
}

function ApprovalTimeline({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null
  return (
    <ol className="relative ml-0.5 space-y-0 border-l-2 border-emerald-200/70 pl-6 dark:border-emerald-900/50">
      {steps.map((s, idx) => {
        const isLast = idx === steps.length - 1
        const icon =
          s.status === 'completed' ? (
            <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden />
          ) : s.status === 'rejected' ? (
            <XCircle className="size-4 text-destructive" aria-hidden />
          ) : s.status === 'current' ? (
            <Clock className="size-4 text-amber-600 dark:text-amber-400" aria-hidden />
          ) : s.status === 'skipped' ? (
            <Minus className="size-4 text-muted-foreground" aria-hidden />
          ) : (
            <Circle className="size-3.5 text-muted-foreground" aria-hidden />
          )
        return (
          <li key={s.key || `step-${idx}`} className={cn('relative pb-5', isLast && 'pb-0')}>
            <span className="absolute -left-[1.4rem] top-0 flex size-8 items-center justify-center rounded-full border-2 border-background bg-card shadow-md ring-2 ring-emerald-500/15">
              {icon}
            </span>
            <div className="rounded-2xl border border-border bg-card px-4 py-3 shadow-sm">
              <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">{s.label}</p>
              <p className="mt-1 flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                <User className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                {formatApproverLine(s)}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">{humanStepStatus(s.status)}</p>
              {s.acted_at ? (
                <time className="mt-1 block text-xs tabular-nums text-muted-foreground" dateTime={s.acted_at}>
                  {formatDateTime(s.acted_at)}
                </time>
              ) : null}
              {sanitizeApprovalDisplayText(s?.remarks) ? (
                <p className="mt-2 rounded-lg border border-border/50 bg-muted/40 px-3 py-2 text-xs leading-relaxed dark:bg-white/5">
                  {sanitizeApprovalDisplayText(s.remarks)}
                </p>
              ) : null}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

function AttendanceTimesBlock({ timeIn, timeOut }) {
  const inLabel = formatTimeOnly(timeIn)
  const outLabel = formatTimeOnly(timeOut)
  return (
    <div className="divide-y divide-border/70 dark:divide-white/10">
      <div className="flex min-h-14 items-center justify-between gap-4 py-2">
        <p className="text-[15px] font-bold text-foreground">Time In</p>
        <div className="flex items-center gap-4">
          <p className="font-mono text-xl font-black tabular-nums tracking-tight text-foreground">{inLabel}</p>
          <div className="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand ring-1 ring-brand/15 dark:bg-brand/15 dark:ring-brand/25">
            <LogIn className="size-5" aria-hidden />
          </div>
        </div>
      </div>
      <div className="flex min-h-14 items-center justify-between gap-4 py-4">
        <p className="text-[15px] font-bold text-foreground">Time Out</p>
        <div className="flex items-center gap-4">
          <p className="font-mono text-xl font-black tabular-nums tracking-tight text-foreground">{outLabel}</p>
          <div className="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand ring-1 ring-brand/15 dark:bg-brand/15 dark:ring-brand/25">
            <LogOut className="size-5" aria-hidden />
          </div>
        </div>
      </div>
    </div>
  )
}

function CorrectionDetailSection({ icon: Icon, title, children, className }) {
  return (
    <section
      className={cn(
        'rounded-xl border border-border/70 bg-card p-5 shadow-[0_10px_28px_-22px_rgba(15,23,42,0.65),0_2px_8px_-6px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_42px_-28px_rgba(0,0,0,0.8)]',
        className
      )}
    >
      <h3 className="mb-4 flex items-center gap-3 border-b border-border/70 pb-3 text-[11px] font-black uppercase tracking-[0.2em] text-brand dark:border-white/10">
        {Icon ? <Icon className="size-5 shrink-0" aria-hidden /> : null}
        {title}
      </h3>
      {children}
    </section>
  )
}

function CorrectionInitialsAvatar({ name, className }) {
  return (
    <div
      className={cn(
        'flex size-12 shrink-0 items-center justify-center rounded-full border border-brand/15 bg-brand/10 text-sm font-black uppercase text-brand shadow-sm dark:border-brand/25 dark:bg-brand/15',
        className
      )}
      aria-hidden
    >
      {getInitials(name)}
    </div>
  )
}

function approvalStepName(step) {
  if (step?.key === 'submitted') return step.submitter_name || '?'
  return step?.approver_name || step?.approver_role_label || '?'
}

function approvalStepRole(step) {
  if (step?.key === 'submitted') return 'Requester'
  return step?.approver_role_label || step?.approver_role || ''
}

function CorrectionApprovalChain({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <CorrectionDetailSection icon={UsersRound} title="Approval chain">
      <ol className="space-y-4">
        {steps.map((step, idx) => {
          const name = approvalStepName(step)
          const role = approvalStepRole(step)
          const statusLabel = humanStepStatus(step.status)
          const statusLine =
            step.acted_at != null && step.acted_at !== ''
              ? `${statusLabel} ? ${formatDateTime(step.acted_at)}`
              : statusLabel
          const remarks = sanitizeApprovalDisplayText(step?.remarks)

          return (
            <li key={step.key || `approval-step-${idx}`}>
              <p className="mb-3 text-[11px] font-black uppercase tracking-[0.18em] text-brand">
                <span className="tabular-nums">{idx + 1}. </span>
                {step.label}
              </p>
              <div
                className={cn(
                  'rounded-xl border border-border/70 bg-background/70 p-4 shadow-sm dark:border-white/10 dark:bg-background/35',
                  step.status === 'current' &&
                    'border-amber-400/70 bg-amber-50/80 ring-2 ring-amber-500/20 dark:border-amber-400/35 dark:bg-amber-950/25'
                )}
              >
                <div className="flex gap-4">
                  <CorrectionInitialsAvatar name={name} />
                  <div className="min-w-0 flex-1">
                    <p className="text-[15px] font-bold leading-snug text-foreground">{name}</p>
                    {role ? <p className="mt-1 text-sm font-medium text-foreground/85">{role}</p> : null}
                    {step?.is_self_approval ? (
                      <span className="mt-2 inline-flex w-fit rounded-full border border-amber-300/70 bg-amber-50 px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.14em] text-amber-800 dark:border-amber-400/40 dark:bg-amber-950/35 dark:text-amber-200">
                        Self Approval
                      </span>
                    ) : null}
                    <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{statusLine}</p>
                    {remarks ? (
                      <p className="mt-3 text-sm leading-relaxed text-foreground">
                        <span className="font-semibold">Remarks: </span>
                        {remarks}
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>
            </li>
          )
        })}
      </ol>
    </CorrectionDetailSection>
  )
}

function correctionHistoryActionLabel(action) {
  switch (action) {
    case 'hr_remark':
      return 'HR internal remark'
    case 'approve_first':
      return 'Line manager approval'
    case 'approve_final':
      return 'HR final approval'
    case 'reject':
      return 'Rejected'
    case 'file':
      return 'Request filed'
    case 'attendance_sync':
      return 'Attendance synchronized'
    default:
      return action || 'Action'
  }
}

function CorrectionHistoryTimeline({ history }) {
  if (!Array.isArray(history) || history.length === 0) return null

  return (
    <CorrectionDetailSection icon={Clock} title="Approval history">
      <ol className="relative ml-1 border-l-2 border-border/70 pl-6 dark:border-white/10">
        {[...history]
          .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
          .map((item, idx) => {
            const actionLabel = correctionHistoryActionLabel(item.action)
            const details = sanitizeApprovalDisplayText(item?.details)
            const headline = [item.actor_name, item.approver_role].filter(Boolean).join(' ? ') || actionLabel
            return (
              <li key={`${item.at}-${idx}-${item.action}`} className="relative pb-7 last:pb-0">
                <span className="absolute -left-[1.95rem] top-3 size-2.5 rounded-full bg-brand ring-4 ring-card dark:ring-card" />
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4 shadow-sm dark:border-white/10 dark:bg-background/35">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <p className="text-sm font-bold text-foreground">{headline}</p>
                    <time className="text-xs tabular-nums text-foreground/85" dateTime={item.at || undefined}>
                      {item.at ? formatDateTime(item.at) : '?'}
                    </time>
                  </div>
                  {details ? (
                    <p className="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-foreground/90">{details}</p>
                  ) : null}
                </div>
              </li>
            )
          })}
      </ol>
    </CorrectionDetailSection>
  )
}

export default function EmployeeCorrectionRequests() {
  const { toast } = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [detailOpen, setDetailOpen] = useState(false)
  const [selected, setSelected] = useState(null)

  const [fileOpen, setFileOpen] = useState(false)
  const [fileDate, setFileDate] = useState(() => getLocalDateStr())
  const [fileIssueKind, setFileIssueKind] = useState('missing_in')
  const [fileTimeIn, setFileTimeIn] = useState('')
  const [fileTimeOut, setFileTimeOut] = useState('')
  const [fileRemarks, setFileRemarks] = useState('')
  const [fileSubmitting, setFileSubmitting] = useState(false)
  const [attendanceDetail, setAttendanceDetail] = useState(null)
  const [attendanceDetailLoading, setAttendanceDetailLoading] = useState(false)
  const [attendanceDetailError, setAttendanceDetailError] = useState('')
  const [deleteDialog, setDeleteDialog] = useState({ open: false, item: null })
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [listSearch, setListSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [sortKey, setSortKey] = useState('filed_at')
  const [sortDir, setSortDir] = useState('desc')

  const showFileTimeIn = fileIssueKind === 'missing_in' || fileIssueKind === 'both'
  const showFileTimeOut = fileIssueKind === 'missing_out' || fileIssueKind === 'both'

  useEffect(() => {
    if (!fileOpen || !fileDate || !fileIssueKind) {
      setAttendanceDetail(null)
      setAttendanceDetailError('')
      return undefined
    }

    let cancelled = false
    setAttendanceDetailLoading(true)
    setAttendanceDetailError('')
    getMyPresenceFilingAttendanceDetail({ date: fileDate, issue_type: fileIssueKind })
      .then((data) => {
        if (!cancelled) setAttendanceDetail(data)
      })
      .catch((e) => {
        if (!cancelled) {
          setAttendanceDetail(null)
          setAttendanceDetailError(e.message || 'Failed to load attendance detail')
        }
      })
      .finally(() => {
        if (!cancelled) setAttendanceDetailLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [fileOpen, fileDate, fileIssueKind])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getMyPresenceFilings()
      setItems(Array.isArray(res?.presence_filings) ? res.presence_filings : [])
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const filteredSorted = useMemo(() => {
    let list = [...items]
    const q = listSearch.trim().toLowerCase()
    if (q) {
      list = list.filter(
        (row) =>
          String(row.id).includes(q) ||
          (row.date || '').toLowerCase().includes(q) ||
          remarksUserText(row.remarks || '').toLowerCase().includes(q) ||
          (row.display_status || '').toLowerCase().includes(q) ||
          issueLabel(row.issue_type).toLowerCase().includes(q) ||
          (row.employee_name || '').toLowerCase().includes(q) ||
          (row.employee_position || '').toLowerCase().includes(q) ||
          (row.employee_role_label || '').toLowerCase().includes(q)
      )
    }
    if (statusFilter !== 'all') {
      list = list.filter((row) => reviewStatusKey(row) === statusFilter)
    }
    if (fromDate) {
      list = list.filter((row) => !row.date || row.date >= fromDate)
    }
    if (toDate) {
      list = list.filter((row) => !row.date || row.date <= toDate)
    }
    const dir = sortDir === 'asc' ? 1 : -1
    const key = sortKey
    list.sort((a, b) => {
      let va
      let vb
      switch (key) {
        case 'employee_name':
          va = (a.employee_name || '').toLowerCase()
          vb = (b.employee_name || '').toLowerCase()
          break
        case 'date':
          va = a.date || ''
          vb = b.date || ''
          break
        case 'issue_type':
          va = a.issue_type || ''
          vb = b.issue_type || ''
          break
        case 'review_status':
          va = reviewStatusSortValue(a)
          vb = reviewStatusSortValue(b)
          break
        case 'time_in': {
          const ta = a.requested_time_in ?? a.time_in
          const tb = b.requested_time_in ?? b.time_in
          va = ta ? new Date(ta).getTime() : 0
          vb = tb ? new Date(tb).getTime() : 0
          break
        }
        case 'time_out': {
          const ta = a.requested_time_out ?? a.time_out
          const tb = b.requested_time_out ?? b.time_out
          va = ta ? new Date(ta).getTime() : 0
          vb = tb ? new Date(tb).getTime() : 0
          break
        }
        case 'filed_at':
          va = a.filed_at ? new Date(a.filed_at).getTime() : 0
          vb = b.filed_at ? new Date(b.filed_at).getTime() : 0
          break
        case 'remarks':
          va = (remarksUserText(a.remarks || '') || '').toLowerCase()
          vb = (remarksUserText(b.remarks || '') || '').toLowerCase()
          break
        default:
          va = 0
          vb = 0
      }
      if (va < vb) return -1 * dir
      if (va > vb) return 1 * dir
      return 0
    })
    return list
  }, [items, listSearch, statusFilter, fromDate, toDate, sortKey, sortDir])

  const requestStats = useMemo(() => {
    const out = { total: items.length, pending: 0, approved: 0, rejected: 0 }
    for (const item of items) {
      const key = reviewStatusKey(item)
      if (key === 'rejected') out.rejected += 1
      else if (key === 'hr_approved') out.approved += 1
      else out.pending += 1
    }
    return out
  }, [items])

  const hasActiveFilters = Boolean(listSearch.trim() || statusFilter !== 'all' || fromDate || toDate)

  function toggleSort(key) {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortKey(key)
      setSortDir('asc')
    }
  }

  function SortHead({ col, label }) {
    const active = sortKey === col
    const Icon = !active ? ArrowUpDown : sortDir === 'asc' ? ArrowUp : ArrowDown
    return (
      <button
        type="button"
        className="-ml-2 inline-flex h-9 items-center gap-1.5 rounded-lg px-2 text-xs font-extrabold uppercase tracking-wide text-muted-foreground transition hover:bg-muted hover:text-foreground"
        onClick={() => toggleSort(col)}
      >
        {label}
        <Icon className={cn('size-3.5', active ? 'text-primary opacity-100' : 'opacity-50')} />
      </button>
    )
  }

  function openDetail(row) {
    setSelected(row)
    setDetailOpen(true)
  }

  function openFileDialog() {
    setFileDate(getLocalDateStr())
    setFileIssueKind('missing_in')
    setFileTimeIn('')
    setFileTimeOut('')
    setFileRemarks('')
    setAttendanceDetail(null)
    setAttendanceDetailError('')
    setFileOpen(true)
  }

  function handleFileIssueKindChange(next) {
    setFileIssueKind(next)
    if (next === 'missing_in') setFileTimeOut('')
    else if (next === 'missing_out') setFileTimeIn('')
  }

  async function submitFile() {
    if (!fileDate) {
      toast({ title: 'Date required', description: 'Select the attendance date.', variant: 'error' })
      return
    }
    const ti = String(fileTimeIn || '').trim()
    const to = String(fileTimeOut || '').trim()
    const needIn = fileIssueKind === 'missing_in' || fileIssueKind === 'both'
    const needOut = fileIssueKind === 'missing_out' || fileIssueKind === 'both'
    if (needIn && !ti) {
      toast({
        title: 'Time required',
        description: 'Enter your actual clock in time.',
        variant: 'error',
      })
      return
    }
    if (needOut && !to) {
      toast({
        title: 'Time required',
        description: 'Enter your actual clock out time.',
        variant: 'error',
      })
      return
    }
    if (needIn && !/^\d{2}:\d{2}$/.test(ti)) {
      toast({ title: 'Invalid time', description: 'Use a valid clock in time.', variant: 'error' })
      return
    }
    if (needOut && !/^\d{2}:\d{2}$/.test(to)) {
      toast({ title: 'Invalid time', description: 'Use a valid clock out time.', variant: 'error' })
      return
    }
    if (!fileRemarks.trim()) {
      toast({ title: 'Remarks required', description: 'Explain why you need this correction.', variant: 'error' })
      return
    }
    try {
      setFileSubmitting(true)
      await submitPresenceFiling({
        date: fileDate,
        issue_kind: fileIssueKind,
        time_in: needIn ? ti : undefined,
        time_out: needOut ? to : undefined,
        remarks: fileRemarks.trim(),
      })
      toast({
        title: 'Request submitted',
        description: 'Your correction request is pending approval.',
        variant: 'success',
      })
      setFileOpen(false)
      await load()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setFileSubmitting(false)
    }
  }

  async function submitDelete() {
    if (!deleteDialog.item) return
    setDeleteSubmitting(true)
    try {
      await deleteMyPresenceFiling(deleteDialog.item.id)
      toast({ title: 'Deleted', description: 'The correction request was deleted.', variant: 'success' })
      setDeleteDialog({ open: false, item: null })
      if (selected?.id && Number(selected.id) === Number(deleteDialog.item.id)) {
        setDetailOpen(false)
        setSelected(null)
      }
      await load()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  return (
    <Motion.div
      className="min-h-[calc(100vh-6rem)] min-w-0 max-w-full overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="mx-auto w-full max-w-full space-y-7 px-1 @sm:px-0">
        {/* Hero */}
        <header className="flex flex-col gap-6 pb-2 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="max-w-2xl space-y-3">
            <p className="text-sm font-extrabold uppercase tracking-[0.14em] text-chart-5">
              My Requests
            </p>
            <h1 className="text-3xl font-extrabold tracking-tight text-foreground @md:text-4xl">
              My Correction Requests
            </h1>
            <p className="text-base leading-relaxed text-muted-foreground">
              Track all your attendance correction requests and their approval status.
          </p>
        </div>
          <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-12 flex-1 gap-2 rounded-xl border-border bg-background px-5 text-base font-semibold text-foreground shadow-sm hover:bg-muted @lg:flex-initial"
              onClick={() => load()}
              disabled={loading}
            >
            {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
            Refresh
          </Button>
            <Button
              type="button"
              className="h-12 flex-1 gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 text-base font-bold text-white shadow-[0_18px_32px_-20px_rgba(234,88,12,0.95)] ring-1 ring-orange-500/20 hover:from-orange-600 hover:to-orange-700 @lg:flex-initial"
              onClick={openFileDialog}
            >
              <Plus className="size-4" />
              File new correction
          </Button>
        </div>
        </header>

        <section className="grid gap-5 @md:grid-cols-2 @xl:grid-cols-4">
          <RequestStatCard
            icon={FileText}
            value={requestStats.total}
            label="Total Requests"
            hint="All correction requests"
            tone="orange"
          />
          <RequestStatCard
            icon={Clock}
            value={requestStats.pending}
            label="Pending"
            hint="Awaiting approval"
            tone="blue"
          />
          <RequestStatCard
            icon={CheckCircle2}
            value={requestStats.approved}
            label="Approved"
            hint="Successfully approved"
            tone="green"
          />
          <RequestStatCard
            icon={XCircle}
            value={requestStats.rejected}
            label="Rejected"
            hint="Not approved"
            tone="red"
          />
        </section>

        {/* Main card */}
        <Card className={cn(brandCardClass, 'overflow-hidden')}>
          <CardHeader className="border-b border-border bg-card px-6 py-6">
            <CardTitle className="text-xl font-semibold tracking-tight text-foreground">
              Your filings
            </CardTitle>
            <CardDescription className="text-base text-muted-foreground">
              Each row is one request. Open a row to see the full approval timeline and remarks.
          </CardDescription>
        </CardHeader>
          <CardContent className="p-0">
          {loading ? (
              <div className="flex flex-col items-center justify-center gap-3 py-20 text-muted-foreground">
                <Loader2 className="size-10 animate-spin text-primary" />
                <p className="text-sm font-medium text-foreground">Loading your requests…</p>
            </div>
          ) : items.length === 0 ? (
              <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                <div className="mb-6 flex size-24 items-center justify-center rounded-3xl border border-dashed border-border bg-muted/30">
                  <Inbox className="size-12 text-muted-foreground" aria-hidden />
                </div>
                <div className="flex items-center gap-2 text-xl font-semibold text-foreground">
                  <Sparkles className="size-6 text-chart-5" aria-hidden />
                  Nothing here yet
                </div>
                <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                  You haven&apos;t filed any corrections yet. Tap <strong className="text-foreground">File correction</strong>{' '}
                  below to submit a request for a past date.
                </p>
                <Button
                  type="button"
                  className="mt-8 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 font-bold text-white shadow-sm ring-1 ring-orange-500/20 hover:from-orange-600 hover:to-orange-700"
                  onClick={openFileDialog}
                >
                  <Plus className="size-4" />
                  File correction
                </Button>
              </div>
            ) : filteredSorted.length === 0 && hasActiveFilters ? (
              <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                <p className="text-lg font-semibold text-foreground">No matching requests</p>
                <p className="mt-2 max-w-md text-sm text-muted-foreground">
                  Try a different keyword, status, or date range.
                </p>
                <Button
                  type="button"
                  variant="outline"
                  className="mt-6 rounded-xl"
                  onClick={() => {
                    setListSearch('')
                    setStatusFilter('all')
                    setFromDate('')
                    setToDate('')
                  }}
                >
                  Clear filters
                </Button>
              </div>
            ) : (
              <AnimatedSection staggerChildren={0.03} duration={0.4}>
                <div className="flex flex-col gap-4 border-b border-border bg-muted/10 px-4 py-5 @lg:flex-row @lg:items-center @lg:justify-between @sm:px-6">
                  <div className="min-w-0 flex-1">
                    <div className="relative max-w-2xl">
                      <Search
                        className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                      />
                      <Input
                        type="search"
                        value={listSearch}
                        onChange={(e) => setListSearch(e.target.value)}
                        placeholder="Search employee, date, status, issue type, remarks..."
                        className="h-12 rounded-xl border-input bg-background pl-12 pr-4 text-base text-foreground shadow-sm"
                        aria-label="Search correction requests"
                      />
                    </div>
                    <p className="mt-3 text-sm text-muted-foreground">
                      Showing{' '}
                      <span className="font-bold tabular-nums text-foreground">
                        {filteredSorted.length}
                      </span>
                      {filteredSorted.length === 1 ? ' request' : ' requests'}
                      {hasActiveFilters ? ' - filtered' : ''}. Use column headers to sort.
                    </p>
                  </div>
                  <div className="grid gap-3 @sm:grid-cols-[10rem_10rem_12rem] @lg:shrink-0">
                    <div className="relative">
                      <CalendarDays className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="date"
                        value={fromDate}
                        onChange={(e) => setFromDate(e.target.value)}
                        className="h-12 rounded-xl border-input bg-background pl-9 text-sm font-semibold text-foreground shadow-sm scheme-light dark:scheme-dark"
                        aria-label="Filter from date"
                      />
                    </div>
                    <div className="relative">
                      <CalendarDays className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        type="date"
                        value={toDate}
                        onChange={(e) => setToDate(e.target.value)}
                        className="h-12 rounded-xl border-input bg-background pl-9 text-sm font-semibold text-foreground shadow-sm scheme-light dark:scheme-dark"
                        aria-label="Filter to date"
                      />
                    </div>
                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                      <SelectTrigger className="h-12 rounded-xl border-input bg-background text-sm font-semibold text-foreground shadow-sm">
                        <SelectValue placeholder="All status" />
                      </SelectTrigger>
                      <SelectContent className="border-border bg-popover text-popover-foreground">
                        <SelectItem value="all">All status</SelectItem>
                        <SelectItem value="pending">Pending</SelectItem>
                        <SelectItem value="department_approved">Department Approved</SelectItem>
                        <SelectItem value="hr_approved">Approved</SelectItem>
                        <SelectItem value="rejected">Rejected</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* Desktop / tablet: scrollable table; Remarks + Date filed only at xl+ */}
                <div className="hidden w-full min-w-0 touch-pan-x overflow-x-auto border-t border-border bg-card lg:block">
                  <Table className="w-full min-w-[820px] xl:min-w-[1060px]">
                    <TableHeader className="[&_tr]:border-b-0">
                      <TableRow className="border-0 bg-muted/30">
                        <TableHead className="min-w-[200px] py-3.5 pl-5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="employee_name" label="Employee" />
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="date" label="Date" />
                        </TableHead>
                        <TableHead className="min-w-[7rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          Day
                        </TableHead>
                        <TableHead className="min-w-[9rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="issue_type" label="Issue type" />
                        </TableHead>
                        <TableHead className="min-w-[5.5rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="time_in" label="Time in" />
                        </TableHead>
                        <TableHead className="min-w-[5.5rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="time_out" label="Time out" />
                        </TableHead>
                        <TableHead className="min-w-[10rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                          <SortHead col="review_status" label="Status" />
                        </TableHead>
                        <TableHead className="hidden min-w-[12rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground xl:table-cell">
                          <SortHead col="remarks" label="Remarks" />
                        </TableHead>
                        <TableHead className="hidden min-w-[9rem] py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground xl:table-cell">
                          <SortHead col="filed_at" label="Date filed" />
                        </TableHead>
                        <TableHead className="w-[7.5rem] min-w-[7.5rem] py-3.5 pr-5 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground xl:w-[8rem] xl:min-w-[8rem]">
                          Actions
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {filteredSorted.map((row, rowIdx) => {
                        const empName = row.employee_name || row.requested_by_name || 'You'
                        const empImg = row.employee_profile_image_url || row.requested_by_profile_image_url
                        const empRoleLabel = row.employee_role_label ?? row.requested_by_role_label
                        const empHrRole = row.employee_hr_role ?? row.requested_by_hr_role
                        const tIn = row.requested_time_in ?? row.time_in
                        const tOut = row.requested_time_out ?? row.time_out
                        return (
                          <TableRow
                            key={row.id}
                            role="button"
                            tabIndex={0}
                            onClick={() => openDetail(row)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault()
                                openDetail(row)
                              }
                            }}
                            className={cn(
                              'cursor-pointer border-border/80 transition-colors hover:bg-muted/50',
                              rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/15'
                            )}
                          >
                            <TableCell className="pl-5 align-top">
                              <EmployeeAvatarNameRoleCell
                                name={empName}
                                imageUrl={empImg}
                                profileTo={null}
                                compact={false}
                                roleLabel={empRoleLabel}
                                hrRole={empHrRole}
                              />
                            </TableCell>
                            <TableCell className="align-middle tabular-nums text-foreground">
                              {row.date ? formatTableDateShort(row.date) : '—'}
                            </TableCell>
                            <TableCell className="align-middle text-foreground">
                              {formatDayName(row.date, row.day_name)}
                            </TableCell>
                            <TableCell className="align-top">
                              <IssueTypeCell issueType={row.issue_type} reasonCode={row.reason_code} />
                            </TableCell>
                            <TableCell className="align-middle">
                              <TimeCell iso={tIn} />
                            </TableCell>
                            <TableCell className="align-middle">
                              <TimeCell iso={tOut} />
                            </TableCell>
                            <TableCell className="align-top">
                              <ReviewStatusTableBadge item={row} />
                            </TableCell>
                            <TableCell
                              className="hidden max-w-[14rem] align-top xl:table-cell"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <RemarksPreviewCell text={row.remarks} />
                            </TableCell>
                            <TableCell className="hidden align-middle text-sm tabular-nums text-foreground xl:table-cell">
                              {row.filed_at ? formatDateTime(row.filed_at) : '—'}
                            </TableCell>
                            <TableCell className="pr-5 text-right align-middle" onClick={(e) => e.stopPropagation()}>
                              <AdminDataTableActions
                                onView={() => openDetail(row)}
                                viewAriaLabel="View correction request details"
                                showDelete={Boolean(row.actor_can_delete)}
                                onDelete={() => setDeleteDialog({ open: true, item: row })}
                              />
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>

                {/* Mobile + tablet: card layout (full table from lg) */}
                <div className="space-y-4 p-4 @sm:px-6 lg:hidden">
                  {filteredSorted.map((row) => {
                    const tIn = row.requested_time_in ?? row.time_in
                    const tOut = row.requested_time_out ?? row.time_out
                    return (
                      <div key={row.id} className="space-y-2">
                      <button
                        type="button"
                        onClick={() => openDetail(row)}
                        className="w-full rounded-2xl border border-border bg-card p-4 text-left text-card-foreground shadow-sm transition hover:border-primary/25 hover:bg-muted/20 hover:shadow-md active:scale-[0.99]"
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="font-mono text-xs font-semibold text-muted-foreground">#{row.id}</p>
                            <p className="mt-1 text-base font-semibold text-foreground">
                              {formatAttendanceDate(row.date)}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                              Filed {row.filed_at ? formatDateTime(row.filed_at) : '—'}
                            </p>
                          </div>
                          <div className="flex shrink-0 items-start gap-2">
                            <ReviewStatusTableBadge item={row} />
                            <ChevronRight className="size-5 text-muted-foreground" aria-hidden />
                          </div>
                        </div>
                        <div className="mt-3">
                          <EmployeeAvatarNameRoleCell
                            name={row.employee_name || 'You'}
                            imageUrl={row.employee_profile_image_url}
                            profileTo={null}
                            compact
                            roleLabel={row.employee_role_label ?? row.requested_by_role_label}
                            hrRole={row.employee_hr_role ?? row.requested_by_hr_role}
                          />
                        </div>
                        <div className="mt-3">
                          <IssueTypeCell issueType={row.issue_type} reasonCode={row.reason_code} />
                        </div>
                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                          <div>
                            <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time in</p>
                            <div className="mt-0.5">
                              <TimeCell iso={tIn} />
                            </div>
                          </div>
                          <div>
                            <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time out</p>
                            <div className="mt-0.5">
                              <TimeCell iso={tOut} />
                            </div>
                          </div>
                        </div>
                        <div className="mt-3 border-t border-border/60 pt-3">
                          <RemarksPreviewCell text={row.remarks} />
                        </div>
                      </button>
                      {row.actor_can_delete ? (
                        <Button
                          type="button"
                          variant="outline"
                          className="w-full gap-2 rounded-xl border-destructive/40 text-destructive hover:bg-destructive/10"
                          onClick={() => setDeleteDialog({ open: true, item: row })}
                        >
                          <Trash2 className="size-4" />
                          Delete
                        </Button>
                      ) : null}
                      </div>
                    )
                  })}
                </div>
              </AnimatedSection>
          )}
        </CardContent>
      </Card>
    </div>

      <Dialog open={fileOpen} onOpenChange={setFileOpen}>
        <DialogContent
          showCloseButton
          closeButtonClassName="border-border bg-card/95 text-foreground shadow-sm hover:bg-muted"
          innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0 pb-0 pl-0 pr-14 pt-0"
          className="max-h-[92vh] flex flex-col overflow-hidden rounded-3xl border border-border bg-card p-0 text-card-foreground shadow-[0_28px_80px_-38px_rgba(15,23,42,0.9)] scheme-light sm:max-w-[40rem] dark:shadow-[0_28px_80px_-38px_rgba(0,0,0,0.55)] dark:scheme-dark"
        >
          <DialogHeader className="shrink-0 border-b border-border bg-card px-7 pb-5 pt-7 text-left">
            <div className="flex items-start gap-4 pr-2">
              <div className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-muted ring-1 ring-border">
                <FileText className="size-8 text-primary" aria-hidden />
              </div>
              <div className="min-w-0 pt-1">
                <DialogTitle className="text-2xl font-extrabold tracking-tight text-foreground">
                  File correction request
                </DialogTitle>
                <DialogDescription className="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground">
                  Select the attendance issue first. Only the required time fields will appear, and remarks are required
                  for approval.
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>
          <div className="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain bg-card px-7 py-6">
            <div className="space-y-5">
              <div className="space-y-2">
                <Label htmlFor="emp-corr-date" className="text-sm font-bold text-foreground">
                  Attendance date <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="emp-corr-date"
                  type="date"
                  value={fileDate}
                  onChange={(e) => setFileDate(e.target.value)}
                  className="h-[3.25rem] rounded-xl border-input bg-background px-4 text-base text-foreground shadow-sm"
                />
                <p className="text-xs leading-relaxed text-muted-foreground">
                  Select the date of the attendance record you want to correct.
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="emp-corr-issue" className="text-sm font-bold text-foreground">
                  Issue type <span className="text-destructive">*</span>
                </Label>
                <Select value={fileIssueKind} onValueChange={handleFileIssueKindChange}>
                  <SelectTrigger
                    id="emp-corr-issue"
                    className="h-[3.25rem] w-full rounded-xl border-input bg-background px-4 text-base text-foreground shadow-sm"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <Clock className="size-5 shrink-0 text-primary" aria-hidden />
                      <SelectValue placeholder="Select issue" />
                    </div>
                  </SelectTrigger>
                  <SelectContent className="border-border bg-popover text-popover-foreground">
                    {ISSUE_KIND_OPTIONS.map((o) => (
                      <SelectItem key={o.value} value={o.value}>
                        {o.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs leading-relaxed text-muted-foreground">
                  Choose the type of correction you&apos;re requesting.
                </p>
              </div>
              <AttendanceDetailNotice
                detail={attendanceDetail}
                loading={attendanceDetailLoading}
                error={attendanceDetailError}
              />
              <Motion.div
                layout
                className={cn(
                  'grid gap-4',
                  showFileTimeIn && showFileTimeOut ? 'grid-cols-1 @sm:grid-cols-2' : 'grid-cols-1'
                )}
                transition={{ duration: 0.2, ease: [0.23, 1, 0.32, 1] }}
              >
                {showFileTimeIn && (
                  <Motion.div
                    key="emp-corr-time-in"
                    initial={{ opacity: 0, y: -6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2 }}
                    className="space-y-2"
                  >
                    <Label htmlFor="emp-corr-time-in" className="text-sm font-bold text-foreground">
                      Actual clock in time <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="emp-corr-time-in"
                      type="time"
                      step={60}
                      className="h-[3.25rem] rounded-xl border-input bg-background px-4 text-base text-foreground shadow-sm"
                      value={fileTimeIn}
                      onChange={(e) => setFileTimeIn(e.target.value)}
                    />
                    <p className="text-xs leading-relaxed text-muted-foreground">
                      Enter the actual time you clocked in.
                    </p>
                  </Motion.div>
                )}
                {showFileTimeOut && (
                  <Motion.div
                    key="emp-corr-time-out"
                    initial={{ opacity: 0, y: -6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2 }}
                    className="space-y-2"
                  >
                    <Label htmlFor="emp-corr-time-out" className="text-sm font-bold text-foreground">
                      Actual clock out time <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="emp-corr-time-out"
                      type="time"
                      step={60}
                      className="h-[3.25rem] rounded-xl border-input bg-background px-4 text-base text-foreground shadow-sm"
                      value={fileTimeOut}
                      onChange={(e) => setFileTimeOut(e.target.value)}
                    />
                    <p className="text-xs leading-relaxed text-muted-foreground">
                      Enter the actual time you clocked out.
                    </p>
                  </Motion.div>
                )}
              </Motion.div>
              <div className="space-y-2">
                <Label htmlFor="emp-corr-remarks" className="text-sm font-bold text-foreground">
                  Remarks <span className="text-destructive">*</span>
                </Label>
                <Textarea
                  id="emp-corr-remarks"
                  value={fileRemarks}
                  onChange={(e) => setFileRemarks(e.target.value.slice(0, 500))}
                  rows={5}
                  maxLength={500}
                  placeholder="Explain what needs to be corrected and why..."
                  className="min-h-[8.5rem] resize-y rounded-xl border-input bg-background p-4 pb-9 text-base text-foreground shadow-sm placeholder:text-muted-foreground"
                  required
                />
                <div className="flex items-start justify-between gap-3">
                  <p className="text-xs leading-relaxed text-muted-foreground">
                    Provide details to help your approver understand the request.
                  </p>
                  <span className="shrink-0 text-xs font-medium tabular-nums text-muted-foreground">
                    {fileRemarks.length} / 500
                  </span>
                </div>
              </div>
            </div>
          </div>
          <DialogFooter className="mt-auto flex shrink-0 flex-col-reverse gap-3 border-t border-border bg-muted/15 px-7 py-5 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-12 rounded-xl border-border bg-background px-6 text-base font-semibold text-foreground shadow-sm hover:bg-muted"
              onClick={() => setFileOpen(false)}
              disabled={fileSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="h-12 gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-7 text-base font-bold text-white shadow-[0_18px_32px_-20px_rgba(234,88,12,0.95)] ring-1 ring-orange-500/20 hover:from-orange-600 hover:to-orange-700"
              onClick={submitFile}
              disabled={fileSubmitting}
            >
              {fileSubmitting ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <>
                  Submit request
                  <Send className="size-4" aria-hidden />
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Details */}
      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent
          showCloseButton
          closeButtonClassName="right-4 top-4 size-10 rounded-lg border-border/80 bg-card/95 text-foreground shadow-md hover:bg-muted dark:border-white/10 dark:bg-card"
          innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0 pb-0 pl-0 pr-14 pt-0"
          className="max-h-[92vh] max-w-[min(100vw-1rem,38rem)] flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] scheme-light dark:border-white/10 dark:bg-card dark:scheme-dark"
        >
          <DialogHeader className="sr-only">
            <DialogTitle>Correction request details</DialogTitle>
            <DialogDescription>Approval chain, attendance, and approval history.</DialogDescription>
          </DialogHeader>
          {selected && (
            <>
              <div className="shrink-0 border-b border-border/70 bg-card px-7 pb-7 pt-8 text-left dark:border-white/10">
                <div className="space-y-5">
                  <p className="text-[11px] font-black uppercase tracking-[0.22em] text-brand">Correction request</p>
                  <div className="flex flex-wrap items-center gap-3">
                    <span className="font-mono text-4xl font-black leading-none tracking-tight text-foreground">
                      #{selected.id}
                    </span>
                    <EmployeeStatusPill displayStatus={selected.display_status} status={selected.status} />
                  </div>
                  <p className="text-sm leading-relaxed text-muted-foreground">
                    Review summary, attendance times, approval chain, and history below.
                  </p>
                </div>
              </div>

              <div className="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain bg-card px-7 py-6 text-sm dark:bg-card">
                <CorrectionDetailSection icon={CalendarDays} title="Summary">
                  <dl className="grid grid-cols-[minmax(0,12.5rem)_1fr] gap-x-4 gap-y-4 text-sm">
                    <dt className="text-muted-foreground">Attendance date</dt>
                    <dd className="font-bold tabular-nums text-foreground">{formatDetailDate(selected.date)}</dd>
                    <dt className="text-muted-foreground">Day</dt>
                    <dd className="font-bold text-foreground">{formatDayName(selected.date, selected.day_name)}</dd>
                    <dt className="text-muted-foreground">Issue type</dt>
                    <dd>
                      <Badge
                        variant="outline"
                        className="rounded-full border-brand/25 bg-brand/10 px-3 py-1 font-bold text-brand dark:border-brand/30 dark:bg-brand/15"
                      >
                        {issueLabel(selected.issue_type)}
                      </Badge>
                    </dd>
                    <dt className="text-muted-foreground">Requested time start</dt>
                    <dd className="font-mono font-bold tabular-nums text-foreground">
                      {formatTimeOnly(selected.requested_time_in ?? selected.time_in)}
                    </dd>
                    <dt className="text-muted-foreground">Requested time end</dt>
                    <dd className="font-mono font-bold tabular-nums text-foreground">
                      {formatTimeOnly(selected.requested_time_out ?? selected.time_out)}
                    </dd>
                    <dt className="text-muted-foreground">Filed</dt>
                    <dd className="tabular-nums text-foreground">{formatDateTime(selected.filed_at)}</dd>
                    <dt className="text-muted-foreground">Last updated</dt>
                    <dd className="tabular-nums text-foreground">{formatDateTime(selected.last_updated)}</dd>
                  </dl>
                </CorrectionDetailSection>

                {selected.attendance_logs_synced_at ? (
                  <div
                    role="status"
                    className="rounded-xl border border-brand/20 bg-brand/10 px-5 py-4 text-sm text-foreground shadow-sm dark:border-brand/25 dark:bg-brand/15"
                  >
                    <p className="flex items-center gap-2 font-black text-brand">
                      <Info className="size-5" aria-hidden />
                      Applied to your attendance
                    </p>
                    <p className="mt-2 leading-relaxed">
                      The approved correction times were saved to your official attendance record on{' '}
                      {formatDateTime(selected.attendance_logs_synced_at)}
                      {selected.attendance_logs_synced_by_name ? ` by ${selected.attendance_logs_synced_by_name}` : ''}.
                      The Attendance module now shows these times.
                    </p>
                  </div>
                ) : null}

                <CorrectionDetailSection icon={Clock} title="Attendance times">
                  <AttendanceTimesBlock timeIn={selected.time_in} timeOut={selected.time_out} />
                </CorrectionDetailSection>

                {selected.remarks ? (
                  <CorrectionDetailSection icon={MessageSquareText} title="Your remarks">
                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{selected.remarks}</p>
                  </CorrectionDetailSection>
                ) : null}

                <CorrectionApprovalChain steps={selected.approval_progress} />
                <CorrectionHistoryTimeline history={selected.approval_history} />

                {selected.rejection_note ? (
                  <section className="rounded-xl border border-destructive/30 bg-destructive/[0.06] p-5 dark:border-destructive/25 dark:bg-destructive/10">
                    <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-destructive">
                      Rejection reason
                    </h3>
                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{selected.rejection_note}</p>
                  </section>
                ) : null}
              </div>

              <div className="mt-auto flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border/70 bg-card px-7 py-5 dark:border-white/10">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="gap-2 rounded-lg border-border bg-card text-foreground hover:bg-muted dark:border-white/10 dark:bg-card"
                  onClick={() => {
                    toast({ title: 'Print', description: 'Use Ctrl+P (or Cmd+P) while this window is open.' })
                    window.print()
                  }}
                >
                  <Printer className="size-4" />
                  Print
                </Button>
                <div className="flex flex-wrap justify-end gap-2">
                  {selected.actor_can_delete ? (
                    <Button
                      type="button"
                      variant="destructive"
                      className="gap-2 rounded-lg"
                      onClick={() => setDeleteDialog({ open: true, item: selected })}
                    >
                      <Trash2 className="size-4" />
                      Delete
                    </Button>
                  ) : null}
                  <Button
                    type="button"
                    variant="outline"
                    className="min-w-24 rounded-lg border-brand/70 bg-card px-6 font-bold text-brand hover:bg-brand/10 hover:text-brand dark:border-brand/55 dark:bg-card"
                    onClick={() => setDetailOpen(false)}
                  >
                    Close
                  </Button>
                </div>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={deleteDialog.open} onOpenChange={(open) => !open && setDeleteDialog({ open: false, item: null })}>
        <DialogContent className="max-w-md rounded-2xl border-border bg-card">
          <DialogHeader>
            <DialogTitle>Delete correction request</DialogTitle>
            <DialogDescription>Are you sure you want to delete this request?</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDeleteDialog({ open: false, item: null })} disabled={deleteSubmitting}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" onClick={submitDelete} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
