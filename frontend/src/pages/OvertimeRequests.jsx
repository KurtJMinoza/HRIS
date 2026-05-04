import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  AlertTriangle,
  ArrowRight,
  Calendar,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock,
  ExternalLink,
  Eye,
  FileDown,
  Info,
  Loader2,
  MoreVertical,
  Plus,
  RefreshCw,
  Timer,
  X,
  Inbox,
  Sparkles,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { Link, useSearchParams } from 'react-router-dom'
import { hrPanelPath } from '@/lib/hrRoutes'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import { formatHHmmTo12h, toHhMm } from '@/lib/timeFormat'
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  createMyOvertimeRequest,
  cancelMyOvertimeRequest,
  getMyOvertimeRequestContext,
  getMyOvertimeRequests,
  getMyOvertimeDetail,
  getMyAttendanceSummary,
  updateMyOvertimeRequest,
  getAdminOvertime,
  getAdminOvertimeDetail,
  updateAdminOvertimeStatus,
  exportAdminOvertimeCsv,
} from '@/api'
import { ApprovalChainDetailView } from '@/components/approval/ApprovalChainDetailView'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_MAX_W_LG,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_VIEW_REQUEST_DIALOG_MAX,
} from '@/lib/adminFormDialogStyles'
import { AnimatedSection } from '@/components/ui/AnimatedSection'

const OT_TYPE_LABEL = {
  regular: 'Regular Day OT',
  rest_day: 'Rest Day OT',
  holiday: 'Holiday OT',
  emergency: 'Emergency OT',
  project: 'Project-Based OT',
}

function otTypeLabel(v) {
  return OT_TYPE_LABEL[v] || v || '—'
}

function formatDateShort(dateStr) {
  if (!dateStr) return '—'
  try {
    return new Date(dateStr).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
  } catch {
    return dateStr
  }
}

function formatTableDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatTimeHm(t) {
  if (!t) return '—'
  const s = String(t)
  return s.length >= 5 ? s.slice(0, 5) : s
}

function formatOtRange12h(startHm, endHm) {
  const a = formatHHmmTo12h(toHhMm(startHm))
  const b = formatHHmmTo12h(toHhMm(endHm))
  if (!a || !b) return null
  return `${a} - ${b}`
}

function formatDateMmDdYyyy(dateStr) {
  if (!dateStr) return 'mm/dd/yyyy'
  const [year, month, day] = String(dateStr).split('-')
  if (!year || !month || !day) return dateStr
  return `${month}/${day}/${year}`
}

function formatModalTime(timeStr) {
  return formatHHmmTo12h(toHhMm(timeStr)) || '--:--'
}

function formatPhRuleOption(opt) {
  if (!opt) return 'Ordinary Day - 1st 8h - 1 OT - 1.25'
  return `${opt.day_type_label} - 1st 8h - ${opt.first_8_multiplier} OT - ${opt.ot_multiplier}`
}

function roundHours1(n) {
  const x = typeof n === 'number' && Number.isFinite(n) ? n : 0
  return Math.round(x * 10) / 10
}

function formatUnfiledOtClockSummary12h(preSeg, postSeg, totalHours) {
  const parts = []
  if (preSeg?.start && preSeg?.end) {
    const range = formatOtRange12h(preSeg.start, preSeg.end)
    const h = typeof preSeg.hours === 'number' ? preSeg.hours : (preSeg.minutes || 0) / 60
    if (range) parts.push(`${range} (${roundHours1(h)}h)`)
  }
  if (postSeg?.start && postSeg?.end) {
    const range = formatOtRange12h(postSeg.start, postSeg.end)
    const h = typeof postSeg.hours === 'number' ? postSeg.hours : (postSeg.minutes || 0) / 60
    if (range) parts.push(`${range} (${roundHours1(h)}h)`)
  }
  if (!parts.length) return null
  if (parts.length === 1) return parts[0]
  return `${parts.join(' + ')} = ${roundHours1(totalHours)}h`
}

function segmentUiLabel(key) {
  return key === 'pre_shift' ? 'Pre-shift OT' : 'Post-shift OT'
}

function normalizeSelectableSegments(segments) {
  if (!Array.isArray(segments)) return []
  return segments
    .map((seg) => {
      const key = String(seg?.key || '')
      if (key !== 'pre_shift' && key !== 'post_shift') return null
      const start = String(seg?.start_time || '').trim()
      const end = String(seg?.end_time || '').trim()
      if (!start || !end) return null
      const minutes = Number(seg?.minutes || 0)
      const hours = Number.isFinite(Number(seg?.hours)) ? Number(seg.hours) : (minutes > 0 ? minutes / 60 : 0)
      return {
        key,
        start_time: start.slice(0, 5),
        end_time: end.slice(0, 5),
        minutes: Number.isFinite(minutes) ? minutes : 0,
        hours: roundHours1(hours),
        label: String(seg?.label || ''),
      }
    })
    .filter(Boolean)
}

function timeToMinutes(t) {
  if (!t) return null
  const s = String(t).trim()
  const m = s.match(/^(\d{1,2}):(\d{2})/)
  if (!m) return null
  return parseInt(m[1], 10) * 60 + parseInt(m[2], 10)
}

function segmentCoveredByRequest(seg, request) {
  if (!seg?.start || !seg?.end || !request) return false
  const segStart = timeToMinutes(seg.start)
  const reqStart = timeToMinutes(request.start_time || request.schedule_end)
  const reqEnd = timeToMinutes(request.end_time || request.expected_end_time)
  if (segStart == null || reqStart == null || reqEnd == null) return false
  const segEnd = timeToMinutes(seg.end)
  if (segEnd == null) return false
  const overlapStart = Math.max(segStart, reqStart)
  const overlapEnd = Math.min(segEnd, reqEnd)
  const overlap = overlapEnd - overlapStart
  const segDuration = segEnd - segStart
  return segDuration > 0 && overlap >= segDuration * 0.5
}

function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

function requesterRoleBadgeClass(hrRole) {
  switch (hrRole) {
    case 'admin_hr':
      return 'border-blue-200/80 bg-blue-100 text-blue-950 shadow-sm dark:border-blue-500/35 dark:bg-blue-950/50 dark:text-blue-50'
    case 'department_head':
      return 'border-teal-200/80 bg-teal-100 text-teal-950 shadow-sm dark:border-teal-500/35 dark:bg-teal-950/45 dark:text-teal-50'
    case 'branch_head':
      return 'border-indigo-200/80 bg-indigo-100 text-indigo-950 shadow-sm dark:border-indigo-500/35 dark:bg-indigo-950/45 dark:text-indigo-50'
    case 'company_head':
      return 'border-violet-200/80 bg-violet-100 text-violet-950 shadow-sm dark:border-violet-500/35 dark:bg-violet-950/45 dark:text-violet-50'
    case 'employee':
    default:
      return 'border-border/70 bg-muted text-muted-foreground dark:border-border/60 dark:bg-muted/80'
  }
}

function RequesterCell({ item, profileTo, avatarLinkable, compact = false }) {
  const name = item.requested_by_name || item.employee_name || '—'
  const position = (item.requested_by_position && String(item.requested_by_position).trim()) || ''
  const roleLabel = item.requested_by_role_label || 'Employee'
  const hrRole = item.requested_by_hr_role || 'employee'
  const imgSrc = item.requested_by_profile_image_url || item.employee_profile_image || undefined

  const avatarInner = (
    <Avatar
      className={cn(
        'shrink-0 border-2 border-white shadow-md ring-1 ring-slate-200/80 ring-offset-2 ring-offset-background dark:border-slate-800 dark:ring-slate-700/60',
        compact ? 'size-9' : 'size-11'
      )}
    >
      {imgSrc ? <AvatarImage src={imgSrc} alt="" className="object-cover" /> : null}
      <AvatarFallback className="bg-gradient-to-br from-slate-200 to-slate-300 text-xs font-bold text-slate-800 dark:from-slate-700 dark:to-slate-600 dark:text-slate-100">
        {getInitials(name)}
      </AvatarFallback>
    </Avatar>
  )

  return (
    <div className={cn('flex min-w-0 max-w-[min(100%,20rem)] items-start gap-3', compact && 'gap-2.5')}>
      {avatarLinkable && profileTo ? (
        <Link
          to={profileTo}
          className="shrink-0 rounded-full outline-none transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-emerald-500/50"
          aria-label={`View profile: ${name}`}
        >
          {avatarInner}
        </Link>
      ) : (
        <div className="shrink-0">{avatarInner}</div>
      )}
      <div className="min-w-0 flex flex-1 flex-col gap-0.5">
        <span
          className={cn(
            'truncate font-bold leading-tight tracking-tight text-foreground',
            compact ? 'text-sm' : 'text-[15px]'
          )}
          title={name}
        >
          {name}
        </span>
        {position ? (
          <p className="line-clamp-1 text-xs leading-snug text-muted-foreground" title={position}>
            {position}
          </p>
        ) : null}
        <Badge
          variant="secondary"
          className={cn(
            'h-5 w-fit rounded-md border px-2 py-0 text-[10px] font-semibold tracking-wide',
            requesterRoleBadgeClass(hrRole)
          )}
        >
          {roleLabel}
        </Badge>
      </div>
    </div>
  )
}

function formatOtHoursDisplay(row) {
  if (row.computed_hours != null && row.computed_hours !== '') {
    return `${Number(row.computed_hours).toFixed(2)} h`
  }
  if (row.requested_ot_hours != null && row.requested_ot_hours !== '') {
    return `${Number(row.requested_ot_hours).toFixed(2)} h`
  }
  if (row.computed_hours != null) return `${Number(row.computed_hours).toFixed(2)} h`
  return '—'
}

function statusBadgeClass(displayStatus) {
  if (!displayStatus) return 'bg-muted text-muted-foreground shadow-sm'
  if (displayStatus === 'HR Approved') {
    return 'bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/25 ring-1 ring-emerald-200/90 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15'
  }
  if (displayStatus === 'Rejected') {
    return 'bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-red-500/20 ring-1 ring-red-200/80 dark:from-red-950/45 dark:to-red-950/25 dark:text-red-100 dark:ring-red-500/30'
  }
  // After first-line approval, waiting on HR (final)
  if (displayStatus.includes('· Pending HR') || displayStatus.includes('Pending HR (final)')) {
    return 'bg-violet-100 text-violet-950 shadow-violet-500/15 ring-1 ring-violet-200/70 dark:bg-violet-950/45 dark:text-violet-100'
  }
  if (displayStatus.startsWith('Pending Department')) {
    return 'bg-amber-100 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:bg-amber-950/45 dark:text-amber-100'
  }
  if (displayStatus.startsWith('Pending HR')) {
    return 'bg-violet-100 text-violet-950 shadow-violet-500/15 ring-1 ring-violet-200/70 dark:bg-violet-950/45 dark:text-violet-100'
  }
  if (displayStatus.startsWith('Pending Branch')) {
    return 'bg-sky-100 text-sky-950 shadow-sky-500/15 ring-1 ring-sky-200/70 dark:bg-sky-950/40 dark:text-sky-100'
  }
  if (displayStatus.startsWith('Pending Company')) {
    return 'bg-indigo-100 text-indigo-950 shadow-indigo-500/15 ring-1 ring-indigo-200/70 dark:bg-indigo-950/45 dark:text-indigo-100'
  }
  return 'bg-muted text-muted-foreground shadow-sm'
}

/** One line: step progress + current status (matches Correction Requests clarity). */
function formatOvertimeStatusLine(row) {
  const primary = row.display_status || row.status || '—'
  const summary = approvalStepsSummary(row.approval_progress)
  if (summary && primary && primary !== '—') {
    return `${summary} — ${primary}`
  }
  return primary
}

/** Approve/Reject only when API says the actor is the current approver and the row is still actionable. */
function showOvertimeActions(row, currentUser, hasApprovePermission) {
  if (!hasApprovePermission) return false
  if (!row || row.status !== 'pending') return false
  if (row.pending_approval === false) return false
  if (!row.actor_can_approve || !row.actor_can_reject) return false
  if (currentUser?.id != null && Number(row.employee_id) === Number(currentUser.id)) return false
  return true
}

function approvalStepsSummary(steps) {
  if (!Array.isArray(steps) || steps.length === 0) return null
  const total = steps.length
  const done = steps.filter((s) => s.status === 'completed').length
  if (steps.some((s) => s.status === 'rejected')) return 'Stopped · rejected'
  if (done >= total) return `All ${total} steps done`
  return `${done} of ${total} steps complete`
}

const APPROVAL_INFO =
  'Approval chain depends on your role: Employee → Department Head → Admin (HR); Department Head → Branch Head → Admin (HR); Branch Head → Company Head → Admin (HR); Company Head → Admin (HR). Admin (HR) is always the final approver (except for their own requests, which another HR must finalize).'

const STATUS_CHIPS = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

const employeeOvertimeCardClass =
  'rounded-[18px] border border-border/70 bg-card shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const employeeOvertimePrimaryButtonClass =
  'h-11 gap-2 rounded-lg bg-brand px-5 text-sm font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const employeeOvertimeOutlineButtonClass =
  'h-11 gap-2 rounded-lg border-border/80 bg-card px-5 text-sm font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'

function DetailSection({ title, children, className }) {
  return (
    <section className={cn('rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50', className)}>
      <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
        {title}
      </h3>
      {children}
    </section>
  )
}

/**
 * @param {{ variant?: 'employee' | 'hr' }} props
 */
export default function OvertimeRequests({ variant = 'employee' }) {
  const { toast } = useToast()
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const hrBase = useHrBasePath()
  const perms = new Set(user?.permissions ?? [])
  const isHr = variant === 'hr'
  /** Line approvers (dept/branch/company heads) use the same admin list API when they have overtime.view. */
  const canSeeAllTab = perms.has('overtime.view')
  const canViewEmployeeProfile = perms.has('employees.view')
  const canExport = isHr && perms.has('overtime.export')
  const canApproveOvertime = perms.has('overtime.approve')

  const [monthYear, setMonthYear] = useState(() => new Date().getFullYear())
  const [monthIndex, setMonthIndex] = useState(() => new Date().getMonth()) // 0-based
  const monthFrom = useMemo(() => `${monthYear}-${String(monthIndex + 1).padStart(2, '0')}-01`, [monthYear, monthIndex])
  const monthTo = useMemo(() => {
    const last = new Date(monthYear, monthIndex + 1, 0)
    return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`
  }, [monthYear, monthIndex])

  function goPrevMonth() {
    if (monthIndex === 0) {
      setMonthIndex(11)
      setMonthYear((y) => y - 1)
    } else setMonthIndex((m) => m - 1)
  }

  function goNextMonth() {
    if (monthIndex === 11) {
      setMonthIndex(0)
      setMonthYear((y) => y + 1)
    } else setMonthIndex((m) => m + 1)
  }

  function goThisMonth() {
    const t = new Date()
    setMonthYear(t.getFullYear())
    setMonthIndex(t.getMonth())
  }

  const [tab, setTab] = useState('mine')

  const [mineItems, setMineItems] = useState([])
  const [allItems, setAllItems] = useState([])
  const [loadingMine, setLoadingMine] = useState(true)
  const [loadingAll, setLoadingAll] = useState(false)
  const [unfiledLoading, setUnfiledLoading] = useState(false)
  const [unfiledRows, setUnfiledRows] = useState([])

  const [allStatus, setAllStatus] = useState('all')
  const [allFrom, setAllFrom] = useState('')
  const [allTo, setAllTo] = useState('')
  const [allPage, setAllPage] = useState(1)
  const [allPagination, setAllPagination] = useState(null)

  const [fileOpen, setFileOpen] = useState(false)
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))

  /** Dashboard "File OT" deep-link: /employee/overtime?date=YYYY-MM-DD */
  const dateFromUrl = searchParams.get('date')
  const segmentsFromUrl = searchParams.get('segments')
  useEffect(() => {
    if (isHr || !dateFromUrl || !/^\d{4}-\d{2}-\d{2}$/.test(dateFromUrl)) return
    const urlSegments = String(segmentsFromUrl || '')
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s === 'pre_shift' || s === 'post_shift')
      .slice(0, 1)
      .map((key) => ({ key }))
    openFile({ date: dateFromUrl, segments: urlSegments })
    const next = new URLSearchParams(searchParams.toString())
    next.delete('date')
    next.delete('segments')
    setSearchParams(next, { replace: true })
  }, [isHr, dateFromUrl, segmentsFromUrl, searchParams, setSearchParams])
  const [startTime, setStartTime] = useState('')
  const [endTime, setEndTime] = useState('')
  const [category, setCategory] = useState('regular')
  const [phOtRule, setPhOtRule] = useState('ORD')
  const [reason, setReason] = useState('')
  const [attachment, setAttachment] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState(null)
  const [otContext, setOtContext] = useState(null)
  const [contextLoading, setContextLoading] = useState(false)
  const [contextError, setContextError] = useState(null)
  const [selectedSegments, setSelectedSegments] = useState([])
  const [seedSegments, setSeedSegments] = useState([])
  const [viewOpen, setViewOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detail, setDetail] = useState(null)

  const [approveOpen, setApproveOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [actionRow, setActionRow] = useState(null)
  const [actionRemarks, setActionRemarks] = useState('')
  const [actionSubmitting, setActionSubmitting] = useState(false)

  const [exportingCsv, setExportingCsv] = useState(false)
  const [approvalInfoOpen, setApprovalInfoOpen] = useState(false)

  const viewOpenRef = useRef(false)
  useEffect(() => {
    viewOpenRef.current = viewOpen
  }, [viewOpen])

  const refetchDetailForModal = useCallback(
    async (id) => {
      if (id == null) return
      setDetailLoading(true)
      try {
        const useAdmin = canSeeAllTab && tab === 'all'
        const res = useAdmin ? await getAdminOvertimeDetail(id) : await getMyOvertimeDetail(id)
        const ot = res?.overtime ?? res?.data?.overtime
        if (ot && typeof ot === 'object') {
          setDetail(ot)
        }
      } catch (e) {
        toast({ title: 'Failed to refresh details', description: e.message, variant: 'error' })
      } finally {
        setDetailLoading(false)
      }
    },
    [canSeeAllTab, tab, toast]
  )

  const [editOpen, setEditOpen] = useState(false)
  const [editId, setEditId] = useState(null)
  const [editStartTime, setEditStartTime] = useState('')
  const [editEndTime, setEditEndTime] = useState('')
  const [editCategory, setEditCategory] = useState('regular')
  const [editReason, setEditReason] = useState('')
  const [editPhOtRule, setEditPhOtRule] = useState('ORD')
  const [editPhRuleOptions, setEditPhRuleOptions] = useState([])
  const [editAttachment, setEditAttachment] = useState(null)
  const [editSubmitting, setEditSubmitting] = useState(false)
  const [editError, setEditError] = useState(null)

  const loadMine = useCallback(async () => {
    setLoadingMine(true)
    try {
      const res =
        !isHr
          ? await getMyOvertimeRequests({ per_page: 50, from_date: monthFrom, to_date: monthTo })
          : await getMyOvertimeRequests({ per_page: 50 })
      setMineItems(Array.isArray(res.overtimes) ? res.overtimes : [])
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setMineItems([])
    } finally {
      setLoadingMine(false)
    }
  }, [toast, isHr, monthFrom, monthTo])

  const loadUnfiledForMonth = useCallback(async () => {
    if (isHr) return
    setUnfiledLoading(true)
    try {
      const attendance = await getMyAttendanceSummary({ from_date: monthFrom, to_date: monthTo })
      const days = Array.isArray(attendance?.days) ? attendance.days : []
      const requests = Array.isArray(mineItems) ? mineItems : []

      const requestsByDate = {}
      for (const r of requests) {
        if (!r?.date || (r.status !== 'pending' && r.status !== 'approved')) continue
        if (!requestsByDate[r.date]) requestsByDate[r.date] = []
        requestsByDate[r.date].push(r)
      }

      const rows = []
      for (const d of days) {
        if (!d?.date) continue
        const preSeg = d.raw_pre_ot ?? null
        const postSeg = d.raw_post_ot ?? null
        if (!preSeg && !postSeg) continue

        const dateRequests = requestsByDate[d.date] || []
        const preIsCovered = preSeg && dateRequests.some((r) => segmentCoveredByRequest(preSeg, r))
        const postIsCovered = postSeg && dateRequests.some((r) => segmentCoveredByRequest(postSeg, r))

        const unfiledPre = preSeg && !preIsCovered ? preSeg : null
        const unfiledPost = postSeg && !postIsCovered ? postSeg : null

        if (!unfiledPre && !unfiledPost) continue

        const unfiledH =
          (unfiledPre ? (typeof unfiledPre.hours === 'number' ? unfiledPre.hours : (unfiledPre.minutes || 0) / 60) : 0) +
          (unfiledPost ? (typeof unfiledPost.hours === 'number' ? unfiledPost.hours : (unfiledPost.minutes || 0) / 60) : 0)

        if (unfiledH <= 0.001) continue

        const summary = formatUnfiledOtClockSummary12h(unfiledPre, unfiledPost, unfiledH)
        if (!summary) continue
        rows.push({
          date: d.date,
          rawHours: roundHours1(unfiledH),
          pre: unfiledPre,
          post: unfiledPost,
          summary,
        })
      }
      rows.sort((a, b) => String(b.date).localeCompare(String(a.date)))
      setUnfiledRows(rows)
    } catch {
      setUnfiledRows([])
    } finally {
      setUnfiledLoading(false)
    }
  }, [isHr, monthFrom, monthTo, mineItems])

  const loadAll = useCallback(async () => {
    setLoadingAll(true)
    try {
      const res = await getAdminOvertime({
        from_date: allFrom || undefined,
        to_date: allTo || undefined,
        status: allStatus !== 'all' ? allStatus : undefined,
        page: allPage,
        per_page: 50,
      })
      setAllItems(res.overtimes || [])
      setAllPagination(res.pagination || null)
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setAllItems([])
    } finally {
      setLoadingAll(false)
    }
  }, [toast, allStatus, allFrom, allTo, allPage])

  useEffect(() => {
    loadMine()
  }, [loadMine])

  useEffect(() => {
    void loadUnfiledForMonth()
  }, [loadUnfiledForMonth])

  useEffect(() => {
    if (tab === 'all' && canSeeAllTab) {
      loadAll()
    }
  }, [tab, canSeeAllTab, loadAll])

  useEffect(() => {
    let cancelled = false
    async function loadCtx() {
      if (!fileOpen) return
      setContextError(null)
      setContextLoading(true)
      try {
        const ctx = await getMyOvertimeRequestContext(date)
        if (!cancelled) setOtContext(ctx)
      } catch (err) {
        if (!cancelled) {
          setOtContext(null)
          setContextError(err.message || 'Could not load schedule for this date.')
        }
      } finally {
        if (!cancelled) setContextLoading(false)
      }
    }
    loadCtx()
    return () => {
      cancelled = true
    }
  }, [date, fileOpen])

  useEffect(() => {
    if (!otContext?.default_ph_ot_rule) return
    setPhOtRule(String(otContext.default_ph_ot_rule))
  }, [otContext])

  const phRuleSelectOptions = useMemo(() => {
    const opts = otContext?.ph_ot_rule_options
    if (Array.isArray(opts) && opts.length > 0) return opts
    return [
      {
        code: 'ORD',
        day_type_label: 'Ordinary Day',
        ot_multiplier: 1.25,
        first_8_multiplier: 1,
      },
    ]
  }, [otContext])

  const canSubmitFile = useMemo(() => {
    const hasSegmentSelection = selectedSegments.length > 0
    return (
      date &&
      (hasSegmentSelection || (startTime && endTime)) &&
      reason.trim().length >= 2 &&
      !submitting &&
      !contextLoading
    )
  }, [date, selectedSegments, startTime, endTime, reason, submitting, contextLoading])

  const activeItems = tab === 'mine' ? mineItems : allItems
  const loading = tab === 'mine' ? loadingMine : loadingAll

  const totalAllPages = allPagination?.last_page || 1

  function openFile(opts = {}) {
    const initialDate = opts.date || null
    const initialStart = opts.start_time || ''
    const initialEnd = opts.end_time || ''
    setSubmitError(null)
    setReason('FOR OT')
    setAttachment(null)
    setStartTime(initialStart)
    setEndTime(initialEnd)
    const normalizedSegments = normalizeSelectableSegments(opts.segments || [])
    setSeedSegments(normalizedSegments)
    setSelectedSegments(normalizedSegments.length === 1 ? [normalizedSegments[0].key] : [])
    setDate(initialDate || new Date().toISOString().slice(0, 10))
    setCategory('regular')
    setFileOpen(true)
  }

  async function handleFileSubmit(e) {
    e.preventDefault()
    if (!canSubmitFile) return
    setSubmitError(null)
    setSubmitting(true)
    try {
      await createMyOvertimeRequest({
        date,
        start_time: startTime,
        end_time: endTime,
        category,
        selected_segments: selectedSegments,
        ph_ot_rule: phOtRule,
        reason: reason.trim(),
        attachment: attachment || null,
      })
      toast({ title: 'Overtime requested', description: 'Your request was submitted for approval.', variant: 'success' })
      setFileOpen(false)
      await loadMine()
      if (tab === 'all') await loadAll()
    } catch (err) {
      setSubmitError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  function openView(row) {
    if (!row || row.id == null) return
    setDetail(row)
    setViewOpen(true)
    setDetailLoading(true)
    const useAdmin = canSeeAllTab && tab === 'all'
    const fetcher = useAdmin ? getAdminOvertimeDetail : getMyOvertimeDetail
    fetcher(row.id)
      .then((res) => {
        const ot = res?.overtime ?? res?.data?.overtime
        if (ot && typeof ot === 'object') {
          setDetail(ot)
        } else {
          toast({
            title: 'Could not load full details',
            description: 'The server response was incomplete. Showing list data.',
            variant: 'error',
          })
        }
      })
      .catch((e) => {
        toast({
          title: 'Failed to load details',
          description: e.message || 'You can still see data from the list.',
          variant: 'error',
        })
      })
      .finally(() => setDetailLoading(false))
  }

  function openApprove(row) {
    setActionRow(row)
    setActionRemarks('')
    setApproveOpen(true)
  }

  function openReject(row) {
    setActionRow(row)
    setActionRemarks('')
    setRejectOpen(true)
  }

  async function submitApprove() {
    if (!actionRow) return
    setActionSubmitting(true)
    try {
      await updateAdminOvertimeStatus(actionRow.id, 'approved', actionRemarks)
      toast({ title: 'Approval recorded', variant: 'success' })
      setApproveOpen(false)
      await loadAll()
      await loadMine()
      if (viewOpenRef.current && actionRow?.id != null) {
        await refetchDetailForModal(actionRow.id)
      }
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setActionSubmitting(false)
    }
  }

  async function submitReject() {
    if (!actionRow || !actionRemarks.trim()) return
    setActionSubmitting(true)
    try {
      await updateAdminOvertimeStatus(actionRow.id, 'rejected', actionRemarks)
      toast({ title: 'Request rejected', variant: 'success' })
      setRejectOpen(false)
      await loadAll()
      await loadMine()
      if (viewOpenRef.current && actionRow?.id != null) {
        await refetchDetailForModal(actionRow.id)
      }
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setActionSubmitting(false)
    }
  }

  async function handleExportCsv() {
    setExportingCsv(true)
    try {
      const blob = await exportAdminOvertimeCsv({
        from_date: allFrom || undefined,
        to_date: allTo || undefined,
        status: allStatus !== 'all' ? allStatus : undefined,
      })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `overtime-${allFrom || ''}-${allTo || ''}.csv`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (e) {
      toast({ title: 'Export failed', description: e.message, variant: 'error' })
    } finally {
      setExportingCsv(false)
    }
  }

  function canEditPendingOvertime(row) {
    if (!row || row.status !== 'pending') return false
    const stage = row.approval_stage
    return !stage || stage === 'pending_first'
  }

  async function openEdit(row) {
    if (!canEditPendingOvertime(row)) return
    setEditId(row.id)
    setEditStartTime(formatTimeHm(row.start_time || row.schedule_end))
    setEditEndTime(formatTimeHm(row.end_time || row.expected_end_time))
    setEditCategory(row.ot_type || 'regular')
    setEditReason(row.reason || '')
    setEditPhOtRule(row.ph_ot_rule || 'ORD')
    setEditAttachment(null)
    setEditError(null)
    setEditOpen(true)
    try {
      const ctx = await getMyOvertimeRequestContext(row.date)
      const opts = Array.isArray(ctx.ph_ot_rule_options) ? ctx.ph_ot_rule_options : []
      setEditPhRuleOptions(opts)
      const valid = opts.some((o) => o.code === row.ph_ot_rule)
      if (row.ph_ot_rule && valid) {
        setEditPhOtRule(row.ph_ot_rule)
      } else if (ctx.default_ph_ot_rule) {
        setEditPhOtRule(String(ctx.default_ph_ot_rule))
      }
    } catch {
      setEditPhRuleOptions([])
    }
  }

  async function handleEditSubmit(e) {
    e.preventDefault()
    if (!editId || editReason.trim().length < 10) return
    setEditSubmitting(true)
    setEditError(null)
    try {
      await updateMyOvertimeRequest(editId, {
        start_time: editStartTime,
        end_time: editEndTime,
        category: editCategory,
        ph_ot_rule: editPhOtRule,
        reason: editReason.trim(),
        attachment: editAttachment || null,
      })
      setEditOpen(false)
      await loadMine()
      if (tab === 'all') await loadAll()
    } catch (err) {
      setEditError(err.message)
    } finally {
      setEditSubmitting(false)
    }
  }

  async function handleCancel(row) {
    if (!canEditPendingOvertime(row)) return
    if (!window.confirm('Cancel this pending overtime request? This cannot be undone.')) return
    try {
      await cancelMyOvertimeRequest(row.id)
      await loadMine()
      if (tab === 'all') await loadAll()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    }
  }

  const title = isHr ? 'Overtime' : 'Overtime Requests'
  const subtitle = isHr
    ? 'File, track, and approve overtime requests with the same multi-step flow as correction requests.'
    : 'File advance or post-shift overtime. Requests route through your line manager then Admin (HR) for final approval.'

  return (
    <Motion.div
      className="min-h-[calc(100vh-6rem)] min-w-0 max-w-full space-y-6 overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="mx-auto w-full max-w-full space-y-8 px-1 @sm:px-0">
        <header className="flex flex-col gap-6 border-b border-border/70 pb-8 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="max-w-2xl space-y-3">
            <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand">
              {isHr ? 'HR' : 'My workspace'}
            </p>
            <h1 className="text-3xl font-bold tracking-tight text-foreground @sm:text-4xl">{title}</h1>
            <p className="max-w-2xl text-[15px] leading-relaxed text-muted-foreground">{subtitle}</p>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-fit gap-2 px-0 text-sm font-semibold text-muted-foreground hover:bg-transparent hover:text-brand"
              onClick={() => setApprovalInfoOpen(true)}
              aria-label="View approval chain information"
            >
              <Info className="size-4 text-brand" />
              Approval chain info
              <ChevronRight className="size-4" />
            </Button>
          </div>
          <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
            {!isHr && (
              <div className="flex h-11 w-full items-center justify-center overflow-hidden rounded-lg border border-border/80 bg-card shadow-sm @lg:w-auto">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-11 shrink-0 rounded-none border-r border-border/70 hover:bg-brand/10 hover:text-brand"
                  onClick={goPrevMonth}
                  aria-label="Previous month"
                >
                  <ChevronLeft className="size-4" />
                </Button>
                <button
                  type="button"
                  onClick={goThisMonth}
                  className="inline-flex h-11 min-w-0 flex-1 items-center justify-center gap-2 truncate px-4 text-center text-sm font-semibold tabular-nums tracking-tight text-foreground transition-colors hover:bg-brand/10 hover:text-brand @lg:w-[13rem]"
                  aria-label="Jump to current month"
                >
                  <Calendar className="size-4 shrink-0" />
                  {new Date(`${monthFrom}T12:00:00`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })}
                </button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-11 shrink-0 rounded-none border-l border-border/70 hover:bg-brand/10 hover:text-brand"
                  onClick={goNextMonth}
                  aria-label="Next month"
                >
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            )}
            <Button
              type="button"
              variant="outline"
              className={cn(employeeOvertimeOutlineButtonClass, '@lg:flex-initial')}
              onClick={() => loadMine()}
              disabled={loadingMine}
            >
              {loadingMine ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
              Refresh
            </Button>
            <Button
              type="button"
              className={cn(employeeOvertimePrimaryButtonClass, '@lg:flex-initial')}
              onClick={openFile}
            >
              <Plus className="size-4" />
              File New Overtime
            </Button>
          </div>
        </header>

        {!isHr && tab === 'mine' && (
          <Card className={cn(employeeOvertimeCardClass, 'overflow-hidden')}>
            <CardHeader className="px-4 py-5 @sm:px-5 md:px-6">
              <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                  <Timer className="size-5" aria-hidden />
                </span>
                <div className="min-w-0 space-y-2">
                  <CardTitle className="text-xl font-semibold tracking-tight text-foreground">Unfiled OT (clock)</CardTitle>
                  <CardDescription className="space-y-1 text-sm leading-relaxed text-muted-foreground">
                    <span className="block">Detected from your clock logs for the current month.</span>
                    <span className="block">Use the same windows when filing pre-shift or post-shift overtime.</span>
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-3 px-4 pb-5 @sm:px-5 md:px-6">
              {unfiledLoading ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" />
                  Detecting unfiled OT…
                </div>
              ) : unfiledRows.length === 0 ? (
                  <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                    No unfiled clock OT detected for this month.
                  </div>
                ) : (
                <div className="grid gap-3 @lg:grid-cols-2">
                  {unfiledRows.map((r) => {
                    const segments = []
                    if (r.pre?.start && r.pre?.end) {
                      segments.push({
                        key: 'pre_shift',
                        start_time: r.pre.start,
                        end_time: r.pre.end,
                        minutes: r.pre.minutes || 0,
                        hours: r.pre.hours,
                        label: 'Pre-shift',
                      })
                    }
                    if (r.post?.start && r.post?.end) {
                      segments.push({
                        key: 'post_shift',
                        start_time: r.post.start,
                        end_time: r.post.end,
                        minutes: r.post.minutes || 0,
                        hours: r.post.hours,
                        label: 'Post-shift',
                      })
                    }
                    const preferred = segments.find((s) => s.key === 'post_shift') || segments[0] || null
                    const startPrefill = preferred?.start_time || ''
                    const endPrefill = preferred?.end_time || ''
                    const totalHours = roundHours1(
                      segments.reduce((sum, seg) => sum + (Number(seg.hours) || 0), 0)
                    )
                    return (
                      <div key={r.date} className="rounded-xl border border-border/70 bg-card p-4 shadow-sm dark:border-white/10">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="text-base font-semibold tracking-tight text-foreground">
                              {formatDateShort(`${r.date}T12:00:00`)}
                            </p>
                            <p className="mt-0.5 text-xs uppercase tracking-wide text-muted-foreground">
                              Unfiled OT (clock)
                            </p>
                          </div>
                          <Badge className="shrink-0 rounded-lg border border-brand/15 bg-brand/10 px-2.5 py-1 text-xs font-semibold text-brand shadow-none dark:bg-brand/15">
                            {totalHours}h total
                          </Badge>
                        </div>

                        <div className="mt-3 space-y-2">
                          {segments.map((seg) => {
                            const range = formatOtRange12h(seg.start_time, seg.end_time) || `${seg.start_time} - ${seg.end_time}`
                            return (
                              <div
                                key={`${r.date}-${seg.key}`}
                                className="flex items-center justify-between rounded-lg border border-border/60 bg-background/70 px-3 py-2 shadow-sm dark:bg-background/35"
                              >
                                <div className="min-w-0">
                                  <p className="text-sm font-medium text-foreground">{segmentUiLabel(seg.key)}</p>
                                  <p className="text-xs tabular-nums text-muted-foreground">{range}</p>
                                </div>
                                <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">{seg.hours}h</span>
                              </div>
                            )
                          })}
                        </div>

                        <div className="mt-4 flex flex-wrap gap-3">
                          {segments.length === 1 && (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              className="h-10 flex-1 rounded-lg border-border/80 bg-card font-semibold text-foreground hover:bg-brand/10 hover:text-brand"
                              onClick={() =>
                                openFile({
                                  date: r.date,
                                  start_time: startPrefill,
                                  end_time: endPrefill,
                                  segments,
                                })
                              }
                            >
                              Open form
                            </Button>
                          )}
                          {segments.map((seg) => (
                            <Button
                              key={`${r.date}-file-${seg.key}`}
                              type="button"
                              size="sm"
                              variant={seg.key === 'pre_shift' ? 'outline' : 'default'}
                              className={cn(
                                'h-10 flex-1 rounded-lg font-semibold',
                                seg.key === 'pre_shift'
                                  ? 'border-brand/25 bg-brand/10 text-brand hover:bg-brand/15 dark:bg-brand/15'
                                  : 'bg-brand text-brand-foreground hover:bg-brand-strong'
                              )}
                              onClick={() =>
                                openFile({
                                  date: r.date,
                                  start_time: seg.start_time,
                                  end_time: seg.end_time,
                                  segments: [seg],
                                })
                              }
                            >
                              {seg.key === 'pre_shift' ? 'File pre-shift' : 'File post-shift'}
                            </Button>
                          ))}
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {canSeeAllTab && (
          <div className="flex flex-wrap gap-2">
            <div
              className="inline-flex min-w-0 flex-wrap gap-2 rounded-2xl border border-border/70 bg-muted/30 p-1 shadow-inner"
              role="tablist"
              aria-label="Overtime views"
            >
              <button
                type="button"
                role="tab"
                aria-selected={tab === 'mine'}
                onClick={() => setTab('mine')}
                className={cn(
                  'rounded-xl px-5 py-2.5 text-sm font-semibold transition-all',
                  tab === 'mine'
                    ? 'bg-card text-foreground shadow-sm ring-1 ring-border/70'
                    : 'text-muted-foreground hover:bg-background hover:text-foreground'
                )}
              >
                My Requests
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={tab === 'all'}
                onClick={() => {
                  setTab('all')
                  setAllPage(1)
                }}
                className={cn(
                  'rounded-xl px-5 py-2.5 text-sm font-semibold transition-all',
                  tab === 'all'
                    ? 'bg-card text-foreground shadow-sm ring-1 ring-border/70'
                    : 'text-muted-foreground hover:bg-background hover:text-foreground'
                )}
              >
                All Requests
              </button>
            </div>
          </div>
        )}

        <Card className={cn(employeeOvertimeCardClass, 'overflow-hidden')}>
          <CardHeader className="px-4 py-5 @sm:px-5 md:px-6">
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                <CalendarDays className="size-5" aria-hidden />
              </span>
              <div className="min-w-0 space-y-2">
                <CardTitle className="text-xl font-semibold tracking-tight text-foreground">Requests</CardTitle>
                <CardDescription className="text-sm leading-relaxed text-muted-foreground">
                  {tab === 'mine'
                    ? 'Your overtime filings and their current stage in the approval chain.'
                    : 'All overtime in your organization scope. Approve or reject when you are the current approver.'}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {tab === 'all' && canSeeAllTab && (
              <div className="flex flex-col gap-5 border-b border-border/40 px-4 py-5 @sm:px-6 md:px-8 @xl:flex-row @xl:flex-wrap @xl:items-end @xl:justify-between">
                <div
                  className="inline-flex min-w-0 flex-wrap gap-2 rounded-2xl border border-slate-200/80 bg-white p-1 shadow-inner dark:border-slate-700 dark:bg-slate-900/40"
                  role="group"
                  aria-label="Filter by status"
                >
                  {STATUS_CHIPS.map(({ value, label }) => (
                    <button
                      key={value}
                      type="button"
                      onClick={() => {
                        setAllStatus(value)
                        setAllPage(1)
                      }}
                      className={cn(
                        'rounded-xl px-4 py-2.5 text-sm font-semibold transition-all',
                        allStatus === value
                          ? 'bg-slate-900 text-white shadow-md dark:bg-white dark:text-slate-900'
                          : 'text-muted-foreground hover:bg-slate-50 hover:text-foreground dark:hover:bg-slate-800/80'
                      )}
                    >
                      {label}
                    </button>
                  ))}
                </div>
                <div className="flex w-full min-w-0 flex-col gap-4 @md:flex-row @md:flex-wrap @md:items-end @xl:w-auto @xl:justify-end">
                  <div className="flex min-w-0 flex-1 flex-col gap-1.5 @md:max-w-xl">
                    <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Date range</span>
                    <div className="flex flex-wrap items-center gap-2">
                      <Input
                        type="date"
                        value={allFrom}
                        onChange={(e) => setAllFrom(e.target.value)}
                        className="h-10 min-w-0 flex-1 rounded-lg text-[15px] @md:max-w-[11rem]"
                      />
                      <span className="text-muted-foreground">–</span>
                      <Input
                        type="date"
                        value={allTo}
                        onChange={(e) => setAllTo(e.target.value)}
                        className="h-10 min-w-0 flex-1 rounded-lg text-[15px] @md:max-w-[11rem]"
                      />
                    </div>
                  </div>
                  <Button
                    type="button"
                    variant="secondary"
                    className="h-10 rounded-xl"
                    onClick={() => {
                      setAllPage(1)
                      loadAll()
                    }}
                  >
                    Apply filters
                  </Button>
                  {canExport && (
                    <Button
                      type="button"
                      variant="outline"
                      className="h-10 gap-2 rounded-xl"
                      disabled={exportingCsv || allItems.length === 0}
                      onClick={handleExportCsv}
                    >
                      {exportingCsv ? <Loader2 className="size-4 animate-spin" /> : <FileDown className="size-4" />}
                      Export CSV
                    </Button>
                  )}
                </div>
              </div>
            )}

            {loading && activeItems.length === 0 ? (
              <div className="flex items-center justify-center py-24 text-muted-foreground">
                <Loader2 className="size-8 animate-spin" />
              </div>
            ) : activeItems.length === 0 ? (
              <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                <Motion.div
                  initial={{ scale: 0.92, opacity: 0 }}
                  animate={{ scale: 1, opacity: 1 }}
                  transition={{ type: 'spring', stiffness: 260, damping: 22 }}
                  className="mb-6 flex size-24 items-center justify-center rounded-3xl border border-dashed border-slate-300/90 bg-gradient-to-br from-slate-50 to-white shadow-inner dark:border-slate-600 dark:from-slate-900/50 dark:to-slate-950/30"
                >
                  <Inbox className="size-11 text-slate-400 dark:text-slate-500" aria-hidden />
                </Motion.div>
                <div className="flex items-center gap-2 text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                  <Sparkles className="size-6 text-amber-500" aria-hidden />
                  No overtime requests
                </div>
                <p className="mt-3 max-w-md text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                  {tab === 'mine'
                    ? 'You have not filed overtime yet. Use File New Overtime to submit one.'
                    : 'Adjust filters or date range to see records.'}
                </p>
              </div>
            ) : (
              <AnimatedSection delay={0.05}>
                <div className="overflow-x-auto bg-card px-4 pb-8 pt-2 @sm:px-5 md:px-6">
                  <Table>
                    <TableHeader>
                      <TableRow className="sticky top-0 z-10 border-b border-border/80 bg-card hover:bg-card">
                        <TableHead className="w-[88px] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">ID</span>
                        </TableHead>
                        <TableHead className="min-w-[200px] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Requester</span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">Date</TableHead>
                        <TableHead className="min-w-[6rem] py-3.5">OT hours</TableHead>
                        <TableHead className="min-w-[12rem] py-3.5">Reason / remarks</TableHead>
                        <TableHead className="min-w-[180px] py-3.5">Status</TableHead>
                        <TableHead className="min-w-[7rem] py-3.5">Date requested</TableHead>
                        <TableHead className="w-[140px] py-3.5 text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {activeItems.map((row, rowIdx) => {
                        const statusLine = formatOvertimeStatusLine(row)
                        const profileTo =
                          canViewEmployeeProfile && (row.requested_by_id || row.employee_id)
                            ? hrPanelPath(hrBase, `employees/${row.requested_by_id || row.employee_id}`)
                            : null
                        const filed = row.filed_at || row.created_at
                        return (
                          <TableRow
                            key={row.id}
                            className={cn(
                              'border-b border-border/65 text-[15px] leading-snug transition-colors duration-150 hover:bg-brand/5 dark:hover:bg-white/[0.045]',
                              rowIdx % 2 === 0 ? 'bg-card' : 'bg-muted/20 dark:bg-white/[0.02]'
                            )}
                          >
                            <TableCell className="align-middle font-mono text-sm font-semibold text-foreground">
                              #{row.id}
                            </TableCell>
                            <TableCell className="align-top">
                              <RequesterCell
                                item={row}
                                profileTo={profileTo}
                                avatarLinkable={Boolean(profileTo)}
                              />
                            </TableCell>
                            <TableCell className="align-middle tabular-nums text-foreground">
                              {row.date ? formatTableDate(`${row.date}T12:00:00`) : '—'}
                            </TableCell>
                            <TableCell className="align-middle font-mono text-sm tabular-nums">
                              {formatOtHoursDisplay(row)}
                            </TableCell>
                            <TableCell className="align-top max-w-[min(280px,40vw)]">
                              <p className="line-clamp-2 text-sm text-foreground/95" title={row.reason || ''}>
                                {row.reason || '—'}
                              </p>
                            </TableCell>
                            <TableCell className="align-top max-w-[min(320px,42vw)]">
                              <div className="flex flex-col gap-1.5">
                                <span
                                  className={cn(
                                    'inline-flex w-fit max-w-full items-start gap-1.5 rounded-xl border border-transparent px-2.5 py-1.5 text-xs font-semibold leading-snug shadow-sm',
                                    statusBadgeClass(row.display_status)
                                  )}
                                >
                                  <Timer className="size-3.5 shrink-0 opacity-80 mt-0.5" aria-hidden />
                                  <span className="line-clamp-4 min-w-0">{statusLine}</span>
                                </span>
                                {row.hr_wait_message ? (
                                  <p
                                    className="line-clamp-2 text-[11px] text-amber-800 dark:text-amber-200/90"
                                    title={row.hr_wait_message}
                                  >
                                    {row.hr_wait_message}
                                  </p>
                                ) : null}
                              </div>
                            </TableCell>
                            <TableCell className="align-middle tabular-nums text-foreground">
                              {formatTableDate(filed)}
                            </TableCell>
                            <TableCell className="text-right align-middle">
                              <div className="flex flex-wrap justify-end gap-1">
                                <Button
                                  type="button"
                                  variant="outline"
                                  size="sm"
                                  className="h-9 gap-1.5 rounded-lg border-border/80 bg-card px-3 text-xs font-semibold hover:bg-brand/10 hover:text-brand"
                                  onClick={() => openView(row)}
                                >
                                  <Eye className="size-3.5" />
                                  Details
                                </Button>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  className="size-9 rounded-lg text-muted-foreground hover:bg-brand/10 hover:text-brand"
                                  onClick={() => openView(row)}
                                  aria-label={`More actions for overtime request #${row.id}`}
                                >
                                  <MoreVertical className="size-4" aria-hidden />
                                </Button>
                                {tab === 'mine' && canEditPendingOvertime(row) && (
                                  <>
                                    <Button type="button" variant="ghost" size="sm" className="h-9 text-xs" onClick={() => openEdit(row)}>
                                      Edit
                                    </Button>
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="sm"
                                      className="h-9 text-xs text-destructive"
                                      onClick={() => handleCancel(row)}
                                    >
                                      Cancel
                                    </Button>
                                  </>
                                )}
                              </div>
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
                {tab === 'all' && totalAllPages > 1 && (
                  <div className="flex items-center justify-between border-t border-border/40 px-4 pb-6 pt-5 @sm:px-6 md:px-8">
                    <div className="text-sm tabular-nums text-muted-foreground">
                      Page {allPagination?.current_page || 1} of {totalAllPages}
                    </div>
                    <div className="flex gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="rounded-lg"
                        onClick={() => setAllPage((p) => Math.max(1, p - 1))}
                        disabled={allPage <= 1}
                      >
                        Previous
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="rounded-lg"
                        onClick={() => setAllPage((p) => Math.min(totalAllPages, p + 1))}
                        disabled={allPage >= totalAllPages}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
                {tab === 'mine' && activeItems.length > 0 && (
                  <div className="flex items-center justify-center gap-2 border-t border-border/50 px-4 pb-6 pt-5 @sm:px-6 md:px-8">
                    <Button type="button" variant="outline" size="icon" className="size-9 rounded-lg" disabled>
                      <ChevronLeft className="size-4" aria-hidden />
                    </Button>
                    <span className="flex size-9 items-center justify-center rounded-lg bg-brand text-sm font-bold tabular-nums text-brand-foreground shadow-sm">
                      1
                    </span>
                    <Button type="button" variant="outline" size="icon" className="size-9 rounded-lg" disabled>
                      <ChevronRight className="size-4" aria-hidden />
                    </Button>
                  </div>
                )}
              </AnimatedSection>
            )}
          </CardContent>
        </Card>
      </div>

      {/* File new */}
      <Dialog open={fileOpen} onOpenChange={setFileOpen}>
        <DialogContent
          showCloseButton
          className={cn(
            'max-h-[min(92vh,900px)] max-w-[min(100vw-2rem,64rem)] gap-0 overflow-hidden rounded-[24px] border-border/70 bg-card p-0 text-card-foreground shadow-[0_28px_90px_rgba(15,23,42,0.34)] dark:border-border/60 dark:bg-card dark:shadow-[0_28px_90px_rgba(0,0,0,0.72)]',
            'before:absolute before:inset-y-0 before:left-0 before:z-10 before:w-2.5 before:bg-brand'
          )}
          innerClassName="gap-0 overflow-hidden p-0"
          closeButtonClassName="right-6 top-6 size-14 rounded-xl border-border/70 bg-background/95 text-foreground shadow-[0_8px_18px_rgba(15,23,42,0.14)] hover:bg-muted dark:bg-background/90 dark:shadow-[0_8px_24px_rgba(0,0,0,0.45)] [&_svg]:size-7"
          aria-describedby="ot-file-desc"
        >
          <div className="shrink-0 border-b border-border/60 bg-gradient-to-r from-brand/5 via-card to-card px-8 pb-9 pt-11 dark:from-brand/10 dark:via-card dark:to-card sm:px-12">
            <div className="flex items-start gap-8 pr-16">
              <div className="flex size-28 shrink-0 items-center justify-center rounded-[22px] bg-brand/10 text-brand ring-1 ring-brand/10 dark:bg-brand/15 dark:ring-brand/20">
                <Timer className="size-16" strokeWidth={2.5} aria-hidden />
              </div>
              <DialogHeader className="max-w-3xl space-y-4 text-left">
                <DialogTitle className="text-4xl font-bold tracking-tight text-foreground">
                  File New Overtime
                </DialogTitle>
                <p id="ot-file-desc" className="text-xl leading-relaxed text-muted-foreground">
                  Flexible OT filing. Example format:
                  <span className="block">6:00 AM - 8:00 AM -&gt; FOR OT,</span>
                  <span className="block">5:00 PM - 12:00 MIDNIGHT -&gt; FOR OT.</span>
                </p>
              </DialogHeader>
            </div>
          </div>
          <form onSubmit={handleFileSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 space-y-7 overflow-y-auto px-8 py-8 sm:px-12">
              {submitError && (
                <div className="rounded-xl border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
                  {submitError}
                </div>
              )}
              {contextLoading && (
                <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-muted/25 px-4 py-3 text-sm text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" />
                  Loading schedule...
                </div>
              )}
              {contextError && (
                <div className="rounded-xl border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
                  {contextError}
                </div>
              )}
              <div className="flex items-start gap-5 rounded-xl border border-brand/20 bg-brand/5 px-7 py-6 text-xl leading-relaxed text-foreground shadow-sm dark:border-brand/25 dark:bg-brand/10">
                <Info className="mt-1 size-7 shrink-0 fill-brand text-brand" aria-hidden />
                <p>
                  You can file OT anytime (before, during, or after the date), including rest day, holiday, and night shift.
                </p>
              </div>
              {(() => {
                const selectableSegments = normalizeSelectableSegments(
                  seedSegments.length > 0 ? seedSegments : (otContext?.detected_segments || [])
                )
                if (selectableSegments.length === 0) return null
                const selectedSet = new Set(selectedSegments)
                return (
                  <div className="space-y-5 rounded-xl border border-border/70 bg-card px-7 py-7 shadow-sm dark:bg-card/80">
                    <div className="space-y-2">
                      <p className="text-2xl font-bold tracking-tight text-foreground">Detected OT segments</p>
                      <p className="text-xl text-muted-foreground">Choose only one segment: pre-shift or post-shift.</p>
                    </div>
                    <div className="space-y-3">
                      {selectableSegments.map((seg) => {
                        const isChecked = selectedSet.has(seg.key)
                        const range = formatOtRange12h(seg.start_time, seg.end_time) || `${seg.start_time} - ${seg.end_time}`
                        return (
                          <label
                            key={seg.key}
                            className={cn(
                              'flex cursor-pointer items-center gap-6 rounded-xl border bg-background px-6 py-5 transition-all dark:bg-background/30',
                              isChecked
                                ? 'border-brand text-foreground ring-2 ring-brand/10'
                                : 'border-border/70 hover:border-brand/45 hover:bg-brand/5'
                            )}
                          >
                            <Input
                              type="radio"
                              className="size-7 shrink-0 accent-brand"
                              checked={isChecked}
                              onChange={(e) => {
                                if (e.target.checked) {
                                  setSelectedSegments([seg.key])
                                  setStartTime(seg.start_time)
                                  setEndTime(seg.end_time)
                                }
                              }}
                            />
                            <span className="text-xl leading-tight">
                              <span className="font-bold text-foreground">
                                {seg.key === 'pre_shift' ? 'Pre-shift OT' : 'Post-shift OT'}
                              </span>
                              <span className="mt-1 block text-muted-foreground">
                                {range} ({seg.hours}h)
                              </span>
                            </span>
                          </label>
                        )
                      })}
                    </div>
                  </div>
                )
              })()}
              <div className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
                <div className="space-y-3 sm:col-span-2">
                  <Label htmlFor="otm-date" className="text-xl font-bold text-foreground">Date</Label>
                  <div className="relative flex h-16 items-center rounded-xl border border-input bg-background px-6 text-xl shadow-sm dark:bg-input/20">
                    <Calendar className="mr-5 size-6 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="pointer-events-none flex-1 font-medium text-foreground">{formatDateMmDdYyyy(date)}</span>
                    <Calendar className="pointer-events-none size-6 shrink-0 text-foreground" aria-hidden />
                    <Input
                      id="otm-date"
                      type="date"
                      value={date}
                      onChange={(e) => {
                        setDate(e.target.value)
                        setSeedSegments([])
                        setSelectedSegments([])
                      }}
                      className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                      required
                    />
                  </div>
                </div>
                <div className="space-y-3">
                  <Label htmlFor="otm-start" className="text-xl font-bold text-foreground">Start time</Label>
                  <div className="relative flex h-16 items-center rounded-xl border border-input bg-background px-6 text-xl shadow-sm dark:bg-input/20">
                    <Clock className="mr-5 size-6 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="pointer-events-none flex-1 font-medium text-foreground">{formatModalTime(startTime)}</span>
                    <ChevronRight className="pointer-events-none size-6 shrink-0 rotate-90 text-foreground" aria-hidden />
                    <Input
                      id="otm-start"
                      type="time"
                      value={startTime}
                      onChange={(e) => setStartTime(e.target.value)}
                      className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                      required={selectedSegments.length === 0}
                      disabled={selectedSegments.length > 0}
                    />
                  </div>
                </div>
                <div className="space-y-3">
                  <Label htmlFor="otm-end" className="text-xl font-bold text-foreground">End time</Label>
                  <div className="relative flex h-16 items-center rounded-xl border border-input bg-background px-6 text-xl shadow-sm dark:bg-input/20">
                    <Clock className="mr-5 size-6 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="pointer-events-none flex-1 font-medium text-foreground">{formatModalTime(endTime)}</span>
                    <ChevronRight className="pointer-events-none size-6 shrink-0 rotate-90 text-foreground" aria-hidden />
                    <Input
                      id="otm-end"
                      type="time"
                      value={endTime}
                      onChange={(e) => setEndTime(e.target.value)}
                      className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                      required={selectedSegments.length === 0}
                      disabled={selectedSegments.length > 0}
                    />
                  </div>
                </div>
                <div className="space-y-3 sm:col-span-2">
                  <Label className="text-xl font-bold text-foreground">PH pay condition</Label>
                  <Select value={phOtRule} onValueChange={setPhOtRule}>
                    <SelectTrigger className="h-16 rounded-xl border-input bg-background px-6 text-xl shadow-sm dark:bg-input/20 [&>svg]:size-6 [&>svg]:text-foreground">
                      <div className="flex min-w-0 items-center gap-5">
                        <CalendarDays className="size-6 shrink-0 text-brand" aria-hidden />
                        <SelectValue />
                      </div>
                    </SelectTrigger>
                    <SelectContent>
                      {phRuleSelectOptions.map((opt) => (
                        <SelectItem key={opt.code} value={String(opt.code)}>
                          {formatPhRuleOption(opt)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
            <DialogFooter className="shrink-0 flex-row justify-end gap-6 border-t border-border/60 bg-card/95 px-8 py-6 dark:bg-card/95 sm:px-12">
              <Button
                type="button"
                variant="outline"
                className="h-14 min-w-40 rounded-xl border-border/80 bg-background px-8 text-xl font-semibold text-foreground shadow-sm hover:bg-muted dark:bg-background/70"
                onClick={() => setFileOpen(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                className="h-14 min-w-72 rounded-xl bg-brand px-8 text-xl font-bold text-brand-foreground shadow-[0_12px_24px_rgba(249,115,22,0.24)] hover:bg-brand-strong dark:shadow-[0_12px_26px_rgba(0,0,0,0.4)]"
                disabled={!canSubmitFile}
              >
                {submitting && <Loader2 className="size-4 animate-spin" />}
                {!submitting && <ArrowRight className="size-6" aria-hidden />}
                Submit request
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Details — wide viewer (same shell as correction / leave request modals) */}
      <Dialog
        open={viewOpen}
        onOpenChange={(o) => {
          setViewOpen(o)
          if (!o) setDetail(null)
        }}
      >
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_VIEW_REQUEST_DIALOG_MAX)}
          aria-describedby="ot-detail-desc"
        >
          {detailLoading && !detail && (
            <div className="flex justify-center py-12">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          )}
          {detail && (
            <>
              <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
                <DialogHeader className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'space-y-3 text-left')}>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Overtime request</p>
                  <div className="flex flex-wrap items-center gap-3">
                    <DialogTitle className="font-mono text-2xl font-bold tracking-tight text-foreground @sm:text-3xl">
                      Request #{detail.id}
                    </DialogTitle>
                    <Badge
                      className={cn(
                        'max-w-full whitespace-normal rounded-xl px-3 py-1.5 text-left text-sm font-semibold leading-snug',
                        statusBadgeClass(detail.display_status)
                      )}
                    >
                      {formatOvertimeStatusLine(detail)}
                    </Badge>
                  </div>
                  <DialogDescription id="ot-detail-desc" className="text-sm text-muted-foreground">
                    Review request information, overtime details, and the approval chain. Use the actions below when you are
                    the current approver.
                  </DialogDescription>
                  {detailLoading ? (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                      <Loader2 className="size-3.5 animate-spin" aria-hidden />
                      Refreshing full details…
                    </p>
                  ) : null}
                </DialogHeader>
              </div>
              <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-5 text-sm')}>
                {/* Request Information */}
                <DetailSection title="Request Information">
                  {(detail.requested_by_name || detail.employee_name) && (
                    <div className="mb-4">
                      <RequesterCell
                        item={detail}
                        profileTo={
                          canViewEmployeeProfile && (detail.requested_by_id || detail.employee_id)
                            ? hrPanelPath(hrBase, `employees/${detail.requested_by_id || detail.employee_id}`)
                            : null
                        }
                        avatarLinkable={Boolean(canViewEmployeeProfile && (detail.requested_by_id || detail.employee_id))}
                      />
                    </div>
                  )}
                  <dl className="grid gap-3 @sm:grid-cols-2">
                    <div>
                      <dt className="text-xs font-medium text-muted-foreground">Filed</dt>
                      <dd className="mt-0.5 tabular-nums text-foreground">
                        {detail.filed_at || detail.created_at
                          ? formatTableDate(detail.filed_at || detail.created_at)
                          : '—'}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs font-medium text-muted-foreground">Request ID</dt>
                      <dd className="mt-0.5 font-mono font-semibold tabular-nums text-foreground">#{detail.id}</dd>
                    </div>
                  </dl>
                  {detail.has_attachment && detail.attachment_url ? (
                    <div className="mt-4 border-t border-border/40 pt-4">
                      <p className="mb-2 text-xs font-medium text-muted-foreground">Attachment</p>
                      <a
                        href={detail.attachment_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
                      >
                        <ExternalLink className="size-4" />
                        {detail.attachment_filename || 'Open attachment'}
                      </a>
                    </div>
                  ) : null}
                </DetailSection>

                {/* OT Details */}
                <DetailSection title="OT Details">
                  <div className="grid gap-4 @sm:grid-cols-2">
                    <div className="flex items-start gap-3 rounded-lg border border-border/50 bg-muted/25 px-3 py-3 dark:bg-muted/15">
                      <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-background text-muted-foreground shadow-sm ring-1 ring-border/50">
                        <CalendarDays className="size-5" aria-hidden />
                      </div>
                      <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Date</p>
                        <p className="mt-0.5 text-base font-semibold tabular-nums text-foreground">
                          {detail.date ? formatDateShort(`${detail.date}T12:00:00`) : '—'}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-start gap-3 rounded-lg border border-border/50 bg-muted/25 px-3 py-3 dark:bg-muted/15">
                      <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-background text-muted-foreground shadow-sm ring-1 ring-border/50">
                        <Timer className="size-5" aria-hidden />
                      </div>
                      <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Hours</p>
                        <p className="mt-0.5 font-mono text-base font-semibold text-foreground">{formatOtHoursDisplay(detail)}</p>
                      </div>
                    </div>
                    <div className="@sm:col-span-2">
                      <p className="text-xs font-medium text-muted-foreground">Category</p>
                      <p className="mt-0.5 font-medium text-foreground">{otTypeLabel(detail.ot_type)}</p>
                    </div>
                    {detail.ph_ot_rule_label || detail.ph_ot_rule ? (
                      <div className="@sm:col-span-2">
                        <p className="text-xs font-medium text-muted-foreground">PH pay rule</p>
                        <p className="mt-0.5 text-foreground">
                          {detail.ph_ot_rule_label || detail.ph_ot_rule}
                          {detail.ot_multiplier != null && detail.first_8_multiplier != null ? (
                            <span className="text-muted-foreground">
                              {' '}
                              — 1st 8h ×{detail.first_8_multiplier} · OT ×{detail.ot_multiplier}
                            </span>
                          ) : null}
                        </p>
                      </div>
                    ) : null}
                    <div className="@sm:col-span-2">
                      <p className="text-xs font-medium text-muted-foreground">Requested time range</p>
                      <p className="mt-1 font-mono text-sm font-medium text-foreground">
                        {formatTimeHm(detail.start_time || detail.schedule_end)} – {formatTimeHm(detail.end_time || detail.expected_end_time)}
                      </p>
                    </div>
                    {detail.status === 'approved' && (detail.end_time || detail.expected_end_time) ? (
                      <div className="@sm:col-span-4 rounded-lg border border-emerald-500/30 bg-emerald-50/60 px-4 py-3 dark:bg-emerald-950/20">
                        <p className="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                          Approved OT range
                        </p>
                        <p className="mt-1 text-sm text-emerald-800 dark:text-emerald-300">
                          Approved from{' '}
                          <span className="font-mono font-bold">{formatTimeHm(detail.start_time || detail.schedule_end)}</span>
                          {' '}to{' '}
                          <span className="font-mono font-bold">{formatTimeHm(detail.end_time || detail.expected_end_time)}</span>.
                        </p>
                      </div>
                    ) : null}
                    <div className="@sm:col-span-2">
                      <p className="text-xs font-medium text-muted-foreground">Reason</p>
                      <p className="mt-1.5 whitespace-pre-wrap rounded-md border border-border/50 bg-muted/20 p-3 text-sm leading-relaxed text-foreground">
                        {detail.reason && String(detail.reason).trim() !== '' ? detail.reason : '—'}
                      </p>
                    </div>
                  </div>
                </DetailSection>

                {/* Approval Chain — policy text + stepper */}
                <section className="rounded-lg border border-border/60 bg-gradient-to-br from-indigo-500/[0.06] via-transparent to-teal-500/[0.05] p-5 shadow-sm dark:from-indigo-950/30 dark:to-teal-950/20">
                  <div>
                    <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                      Current request — approval steps
                    </p>
                    {Array.isArray(detail.approval_progress) && detail.approval_progress.length > 0 ? (
                      <ApprovalChainDetailView steps={detail.approval_progress} />
                    ) : (
                      <p className="text-sm text-muted-foreground">No approval step data for this request.</p>
                    )}
                  </div>
                </section>

                {/* Approval History */}
                {Array.isArray(detail.approval_history) && detail.approval_history.length > 0 ? (
                  <DetailSection title="Approval History">
                    <ul className="space-y-2">
                      {detail.approval_history.map((h, i) => (
                        <li key={i} className="rounded-lg border border-border/50 bg-muted/30 px-3 py-2.5 text-xs dark:bg-white/5">
                          <span className="font-semibold text-foreground">{h.actor_name || h.action || '—'}</span>
                          {h.approver_role ? (
                            <span className="text-muted-foreground"> · {h.approver_role}</span>
                          ) : null}
                          {h.at ? (
                            <time
                              className="mt-1 block text-[11px] tabular-nums text-muted-foreground"
                              dateTime={h.at}
                            >
                              {new Date(h.at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}
                            </time>
                          ) : null}
                          {sanitizeApprovalDisplayText(h?.details) ? (
                            <p className="mt-2 whitespace-pre-wrap text-[13px] leading-relaxed text-foreground/90">
                              {sanitizeApprovalDisplayText(h.details)}
                            </p>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  </DetailSection>
                ) : null}

                {/* Full Remarks — consolidated approver / HR / rejection notes */}
                <DetailSection title="Full Remarks">
                  <div className="space-y-3 text-sm">
                    {detail.remarks && String(detail.remarks).trim() !== '' ? (
                      <div>
                        <p className="text-xs font-medium text-muted-foreground">Approver / HR</p>
                        <p className="mt-1 whitespace-pre-wrap rounded-md border border-border/50 bg-muted/20 p-3 leading-relaxed">
                          {detail.remarks}
                        </p>
                      </div>
                    ) : null}
                    {detail.status === 'rejected' && detail.rejection_note && String(detail.rejection_note).trim() !== '' ? (
                      <div>
                        <p className="text-xs font-medium text-destructive">Rejection</p>
                        <p className="mt-1 whitespace-pre-wrap rounded-md border border-destructive/25 bg-destructive/5 p-3 leading-relaxed text-foreground">
                          {detail.rejection_note}
                        </p>
                      </div>
                    ) : null}
                    {!detail.remarks?.trim() &&
                    !(detail.status === 'rejected' && detail.rejection_note && String(detail.rejection_note).trim() !== '') ? (
                      <p className="text-muted-foreground">—</p>
                    ) : null}
                  </div>
                </DetailSection>
              </div>
            </>
          )}
          {!detailLoading && !detail && (
            <p className="px-6 py-8 text-center text-sm text-muted-foreground">No request data to display.</p>
          )}
          <DialogFooter
            className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'flex flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-end')}
          >
            {detail && !detailLoading && showOvertimeActions(detail, user, canApproveOvertime) ? (
              <div className="flex w-full flex-wrap gap-2 @sm:w-auto @sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="min-w-[100px] border-destructive/40 text-destructive hover:bg-destructive/10 dark:border-destructive/50"
                  onClick={() => openReject(detail)}
                >
                  Reject
                </Button>
                <Button
                  type="button"
                  className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'min-w-[100px]')}
                  onClick={() => openApprove(detail)}
                >
                  Approve
                </Button>
              </div>
            ) : null}
            <Button type="button" variant="outline" className="min-w-[100px]" onClick={() => setViewOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Approve */}
      <Dialog open={approvalInfoOpen} onOpenChange={setApprovalInfoOpen}>
        <DialogContent className={adminFormDialogContentClass('max-w-xl')}>
          <DialogHeader>
            <DialogTitle>Approval Chain</DialogTitle>
            <DialogDescription className="leading-relaxed text-muted-foreground">{APPROVAL_INFO}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" onClick={() => setApprovalInfoOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Approve */}
      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent className={adminFormDialogContentClass('max-w-md')}>
          <DialogHeader>
            <DialogTitle>Approve overtime</DialogTitle>
            <DialogDescription>Optional remarks are stored in the audit trail.</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="appr-notes">Remarks (optional)</Label>
            <Textarea id="appr-notes" value={actionRemarks} onChange={(e) => setActionRemarks(e.target.value)} rows={3} />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setApproveOpen(false)} disabled={actionSubmitting}>
              Cancel
            </Button>
            <Button type="button" onClick={submitApprove} disabled={actionSubmitting}>
              {actionSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
              Confirm approve
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reject */}
      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent className={adminFormDialogContentClass('max-w-md')}>
          <DialogHeader>
            <DialogTitle>Reject overtime</DialogTitle>
            <DialogDescription>Provide a reason for the employee.</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="rej-notes">Remarks (required)</Label>
            <Textarea id="rej-notes" value={actionRemarks} onChange={(e) => setActionRemarks(e.target.value)} rows={3} required />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setRejectOpen(false)} disabled={actionSubmitting}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={submitReject}
              disabled={actionSubmitting || !actionRemarks.trim()}
            >
              {actionSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
              Confirm reject
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit (mine pending first) */}
      <Dialog
        open={editOpen}
        onOpenChange={(o) => {
          setEditOpen(o)
          if (!o) {
            setEditId(null)
            setEditError(null)
            setEditAttachment(null)
          }
        }}
      >
        <DialogContent showCloseButton className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)} aria-describedby="ot-edit-desc">
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit pending request</DialogTitle>
              <p id="ot-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Update expected end time, category, PH rule, or reason. Only before first-level approval.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleEditSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
              {editError && (
                <div className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                  {editError}
                </div>
              )}
              <div className="grid gap-4 @md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="ed-st">Start time</Label>
                  <Input
                    id="ed-st"
                    type="time"
                    value={editStartTime}
                    onChange={(e) => setEditStartTime(e.target.value)}
                    className="h-10"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ed-et">End time</Label>
                  <Input
                    id="ed-et"
                    type="time"
                    value={editEndTime}
                    onChange={(e) => setEditEndTime(e.target.value)}
                    className="h-10"
                    required
                  />
                </div>
                <div className="space-y-2 @md:col-span-2">
                  <Label>PH pay condition</Label>
                  <Select value={editPhOtRule} onValueChange={setEditPhOtRule}>
                    <SelectTrigger className="h-10">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(editPhRuleOptions.length > 0
                        ? editPhRuleOptions
                        : [{ code: 'ORD', day_type_label: 'Ordinary Day', ot_multiplier: 1.25, first_8_multiplier: 1 }]
                      ).map((opt) => (
                        <SelectItem key={opt.code} value={opt.code}>
                          {opt.day_type_label} — 1st 8h ×{opt.first_8_multiplier} · OT ×{opt.ot_multiplier}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2 @md:col-span-2">
                  <Label htmlFor="ed-r">Reason</Label>
                  <Textarea
                    id="ed-r"
                    rows={3}
                    value={editReason}
                    onChange={(e) => setEditReason(e.target.value)}
                    className="min-h-[80px] resize-none"
                    required
                    minLength={10}
                  />
                </div>
                <div className="space-y-2">
                  <Label>New attachment (optional)</Label>
                  <Input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setEditAttachment(e.target.files?.[0] || null)}
                    className="cursor-pointer text-sm"
                  />
                </div>
              </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditOpen(false)} disabled={editSubmitting}>
                Close
              </Button>
              <Button
                type="submit"
                className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
                disabled={editSubmitting || editReason.trim().length < 10}
              >
                {editSubmitting && <Loader2 className="size-4 animate-spin" />}
                Save changes
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
