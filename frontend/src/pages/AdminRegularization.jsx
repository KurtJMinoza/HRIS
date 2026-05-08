import { useCallback, useEffect, useMemo, useState } from 'react'
import { motion as Motion } from 'framer-motion'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Loader2,
  RefreshCw,
  UserCheck,
  CalendarClock,
  AlertCircle,
  Info,
  Search,
  Plus,
  ShieldCheck,
  FileText,
  ChevronLeft,
  ChevronRight,
  CheckCircle2,
  Send,
  Star,
  GraduationCap,
  FileCheck2,
  UserRound,
  CircleCheckBig,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/components/ui/use-toast'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import {
  EmployeeAvatarNameRoleCell,
  EmployeeAvatarNameCell,
  RemarksPreviewCell,
} from '@/components/presenceFiling/CorrectionTableCells'
import { RegularizationStatusBadge } from '@/components/regularization/RegularizationStatusBadge'
import { RegularizationRecommendationViewDialog } from '@/components/regularization/RegularizationRecommendationViewDialog'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import {
  getAdminUpcomingRegularizations,
  getAdminRegularizationRecommendations,
  getRegularizationEligibleEmployees,
  submitRegularizationRecommendation,
  approveRegularizationRecommendation,
  rejectRegularizationRecommendation,
  getRegularizationRequiredActions,
  updateRegularizationRequiredActions,
  profileImageUrl,
} from '@/api'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'

const DAYS_OPTIONS = [
  { value: '14', label: 'Next 14 days' },
  { value: '30', label: 'Next 30 days' },
  { value: '60', label: 'Next 60 days' },
  { value: '90', label: 'Next 90 days' },
]

const STATUS_TABS = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'history', label: 'History' },
]

const REGULARIZATION_CARD_CLASS =
  'rounded-lg border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_36px_rgba(15,23,42,0.06)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.32)]'

const REGULARIZATION_TABLE_HEAD_CLASS =
  'border-b border-border/60 bg-muted/35 hover:bg-muted/35 dark:border-border dark:bg-background/35 dark:hover:bg-background/35'

const REGULARIZATION_TABLE_ROW_CLASS =
  'border-b border-border/45 text-sm leading-snug transition-colors hover:bg-muted/25 dark:border-border/55 dark:hover:bg-muted/30'

const UPCOMING_PAGE_SIZE = 20
const SUBMIT_NOTES_MAX_LENGTH = 500

const REGULARIZATION_ACTION_ITEMS = [
  { key: 'performance_review_completed', label: 'Performance Review Completed', Icon: Star },
  { key: 'training_completed', label: 'Training / Orientation Checklist Completed', Icon: GraduationCap },
  { key: 'documents_submitted', label: 'Documents Submitted (ID, clearances, etc.)', Icon: FileCheck2 },
  { key: 'manager_recommendation_received', label: 'Manager Recommendation Received', Icon: UserRound },
  { key: 'checklist_completed', label: 'Checklist Completion', Icon: CircleCheckBig },
]

// Persisted server-side recommendation types.
const RECOMMENDATION_TYPES = [
  { value: 'probation_to_regular', label: 'Probation to Regular' },
  { value: 'contract_renewal', label: 'Contract Renewal' },
  { value: 'contract_extension', label: 'Contract Extension' },
  { value: 'end_contract', label: 'End Contract' },
  { value: 'project_extension', label: 'Project Extension' },
  { value: 'project_completion', label: 'Project Completion' },
  { value: 'performance_based', label: 'Performance-Based' },
]

// UI-only types (mapped to persisted types on submit).
const UI_RECOMMENDATION_TYPES = [
  { value: 'probation_auto_6mo', label: 'Probation to Regular (after 6 months)' },
  { value: 'probation_early_3mo', label: 'Early Probation to Regular (after 3 months)' },
]

function formatShortDate(iso) {
  if (!iso) return '—'
  try {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function recommendationTypeLabel(value) {
  return RECOMMENDATION_TYPES.find((t) => t.value === value)?.label || value || '—'
}

function normalizeEmploymentStatus(status) {
  const s = String(status || '')
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, '_')
  if (!s) return ''
  if (s.includes('probation')) return 'probationary'
  if (s === 'project_based' || s === 'projectbased' || s === 'project') return 'project_based'
  if (s.includes('project')) return 'project_based'
  if (s.includes('contract')) return 'contractual'
  if (s.includes('separat') || s.includes('resign') || s.includes('terminat')) return 'separated'
  return s
}

function formatDateTimeSubmitted(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return '—'
    return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return '—'
  }
}

function employmentTypeLabel(v) {
  if (!v) return '—'
  return String(v).replace(/_/g, ' ')
}

function employeeInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (parts.length === 0) return '??'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
}

function recommenderRoleBadgeClass(hrRole) {
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

function defaultEffectiveDateForEmployee(emp, recType) {
  const m = emp?.milestones || {}
  if (recType === 'probation_auto_6mo') return m.six_months || ''
  if (recType === 'probation_early_3mo') return m.three_months || ''
  if (recType === 'contract_renewal' || recType === 'contract_extension') return emp?.contract_start_date || emp?.contract_end_date || ''
  if (recType === 'end_contract') return emp?.contract_end_date || ''
  if (recType === 'project_extension') return emp?.contract_start_date || emp?.contract_end_date || ''
  if (recType === 'project_completion') return emp?.contract_end_date || ''
  if (recType === 'probation_to_regular') return m.three_months || ''
  return m.three_months || m.six_months || ''
}

function formatServiceDuration(rec) {
  return formatServiceDurationDetailed(rec.employee_hire_date)
}

/** Human-readable tenure: "5 months 12 days" (from hire date). */
function formatServiceDurationDetailed(hireDateIso) {
  if (!hireDateIso) return '—'
  const hire = new Date(`${hireDateIso}T12:00:00`)
  const now = new Date()
  if (Number.isNaN(hire.getTime()) || hire > now) return '—'
  let anchor = new Date(hire)
  let wholeMonths = 0
  for (let i = 0; i < 600; i += 1) {
    const next = new Date(anchor)
    next.setMonth(next.getMonth() + 1)
    if (next > now) break
    anchor = next
    wholeMonths += 1
  }
  const days = Math.max(0, Math.floor((now.getTime() - anchor.getTime()) / 86400000))
  if (wholeMonths === 0) return `${days} day${days !== 1 ? 's' : ''}`
  const mPart = `${wholeMonths} month${wholeMonths !== 1 ? 's' : ''}`
  return days === 0 ? mPart : `${mPart} ${days} day${days !== 1 ? 's' : ''}`
}

/** Next relevant 3-/5-/6-month milestone date (prefer nearest upcoming, else latest passed). */
function getPrimaryMilestone(row) {
  // Prefer dashboard-style computed milestone fields when present (keeps dashboard + module in sync).
  if (row?.next_milestone && row?.next_milestone_date) {
    return { label: row.next_milestone, iso: row.next_milestone_date }
  }
  const m = row.milestones || {}
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const candidates = [
    { label: '3-month', iso: m.three_months },
    { label: '5-month', iso: m.five_months },
    { label: '6-month', iso: m.six_months },
  ].filter((c) => c.iso)
  const withDates = candidates.map((c) => ({
    ...c,
    d: new Date(`${c.iso}T12:00:00`),
  }))
  const future = withDates.filter((c) => !Number.isNaN(c.d.getTime()) && c.d >= today)
  if (future.length) {
    future.sort((a, b) => a.d - b.d)
    return { label: future[0].label, iso: future[0].iso }
  }
  if (withDates.length) {
    withDates.sort((a, b) => b.d - a.d)
    return { label: withDates[0].label, iso: withDates[0].iso }
  }
  return null
}

function milestoneUrgencyFromDate(iso) {
  if (!iso) return { label: '—', tone: 'muted' }
  const d = new Date(`${iso}T12:00:00`)
  if (Number.isNaN(d.getTime())) return { label: '—', tone: 'muted' }
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target = new Date(d)
  target.setHours(0, 0, 0, 0)
  const diffDays = Math.ceil((target - today) / 86400000)
  if (diffDays < 0) return { label: 'Overdue', tone: 'overdue' }
  if (diffDays <= 30) return { label: 'Due Soon', tone: 'due' }
  return { label: 'On Track', tone: 'on_track' }
}

function milestoneStatusBadgeClass(tone) {
  switch (tone) {
    case 'overdue':
      // Calm “danger” treatment: soft tint + refined border (avoid loud gradients).
      return 'border-rose-500/20 bg-rose-500/10 text-rose-900 ring-1 ring-rose-500/10 shadow-[0_1px_0_rgba(0,0,0,0.04)] dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-100'
    case 'due':
      // Warm “attention” treatment: subtle amber wash.
      return 'border-amber-500/20 bg-amber-500/10 text-amber-950 ring-1 ring-amber-500/10 shadow-[0_1px_0_rgba(0,0,0,0.04)] dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-100'
    case 'on_track':
      // Confident “good” treatment: quiet emerald tint.
      return 'border-emerald-500/20 bg-emerald-500/10 text-emerald-950 ring-1 ring-emerald-500/10 shadow-[0_1px_0_rgba(0,0,0,0.04)] dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-100'
    default:
      return 'border-border/70 bg-muted/40 text-muted-foreground ring-1 ring-border/30'
  }
}

/**
 * Contract expiry badge for dashboard/renewal UI.
 * Logic: we compare end-date (date-only) vs today's date in local time.
 * - endDate < today  => overdue
 * - endDate == today => expired today
 * - endDate > today  => ends in X days
 */
function contractExpiryStatus(endDateIso) {
  if (!endDateIso) return { label: '—', tone: 'muted' }
  const end = new Date(`${endDateIso}T12:00:00`)
  if (Number.isNaN(end.getTime())) return { label: '—', tone: 'muted' }
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const endDay = new Date(end)
  endDay.setHours(0, 0, 0, 0)
  const diffDays = Math.ceil((endDay.getTime() - today.getTime()) / 86400000)
  if (diffDays < 0) return { label: `${Math.abs(diffDays)} days overdue`, tone: 'overdue' }
  if (diffDays === 0) return { label: 'Expired today', tone: 'overdue' }
  if (diffDays <= 30) return { label: `Ends in ${diffDays} days`, tone: 'due' }
  return { label: `Ends in ${diffDays} days`, tone: 'on_track' }
}

function recommendedActionText(row) {
  if (row?.recommended_action) return row.recommended_action
  const am = row.approaching_milestone
  const phase = row.probation_review_phase
  if (am === '6_months' || phase === 'six_month_decision') {
    return 'HR decision: confirm Regular or extended probation'
  }
  if (am === '5_months' || phase === 'five_month_review') {
    return 'Complete 5-month review; schedule 6-month decision'
  }
  if (am === '3_months') {
    return 'Submit early regularization recommendation if appropriate'
  }
  if (row.months_since_hire != null && row.months_since_hire >= 5) {
    return '6-month milestone window — prioritize HR review'
  }
  return 'Track milestones; submit recommendation when eligible'
}

function MilestoneQueueStatusBadge({ tone, label }) {
  return (
    <span
      className={cn(
        'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm',
        milestoneStatusBadgeClass(tone),
      )}
    >
      {label}
    </span>
  )
}

export default function AdminRegularization() {
  const { toast } = useToast()
  const { user } = useAuth()
  const base = useHrBasePath()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const perms = new Set(user?.permissions ?? [])
  const canEditEmployees = perms.has('employees.edit')
  const isHrAdmin = user?.hr_role === 'admin_hr'
  const canManageRegularization = isHrAdmin && canEditEmployees
  /** Department / Branch / Company heads and HR may use this module; only HR (Admin) approves. */
  const canAccessRegularizationModule = ['admin_hr', 'company_head', 'branch_head', 'department_head'].includes(
    user?.hr_role,
  )
  const canSubmitRegularization = canAccessRegularizationModule
  const canApproveRegularization = canManageRegularization
  const canViewEmployeeProfile = perms.has('employees.view')
  const cellPad = '!p-3.5'

  const [upcoming, setUpcoming] = useState([])
  const [upcomingLoading, setUpcomingLoading] = useState(true)
  const [daysAhead, setDaysAhead] = useState('30')
  const [upcomingPage, setUpcomingPage] = useState(1)

  const [recs, setRecs] = useState([])
  const [recsLoading, setRecsLoading] = useState(true)
  const [recFilter, setRecFilter] = useState('pending')

  const [approveOpen, setApproveOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [activeRec, setActiveRec] = useState(null)
  const [approveNotes, setApproveNotes] = useState('')
  const [rejectReason, setRejectReason] = useState('')
  const [actionSubmitting, setActionSubmitting] = useState(false)
  const [approveRequiredActions, setApproveRequiredActions] = useState(null)

  const [submitOpen, setSubmitOpen] = useState(false)
  const [eligibleEmployees, setEligibleEmployees] = useState([])
  const [eligibleLoading, setEligibleLoading] = useState(false)
  const [submitUserId, setSubmitUserId] = useState('')
  const [submitEmpSearch, setSubmitEmpSearch] = useState('')
  const [submitRecType, setSubmitRecType] = useState('probation_to_regular')
  const [submitEffectiveDate, setSubmitEffectiveDate] = useState('')
  const [submitExpirationDate, setSubmitExpirationDate] = useState('')
  const [submitNotes, setSubmitNotes] = useState('')
  const [submitBusy, setSubmitBusy] = useState(false)
  const [submitRequiredActions, setSubmitRequiredActions] = useState(null)
  const [requiredActionsBusy, setRequiredActionsBusy] = useState(false)
  const [viewOpen, setViewOpen] = useState(false)
  const [viewRec, setViewRec] = useState(null)

  const upcomingTotalPages = Math.max(1, Math.ceil(upcoming.length / UPCOMING_PAGE_SIZE))
  const upcomingCurrentPage = Math.min(upcomingPage, upcomingTotalPages)
  const paginatedUpcoming = useMemo(
    () =>
      upcoming.slice(
        (upcomingCurrentPage - 1) * UPCOMING_PAGE_SIZE,
        upcomingCurrentPage * UPCOMING_PAGE_SIZE,
      ),
    [upcoming, upcomingCurrentPage],
  )
  const upcomingRangeStart = upcoming.length === 0 ? 0 : (upcomingCurrentPage - 1) * UPCOMING_PAGE_SIZE + 1
  const upcomingRangeEnd = Math.min(upcomingCurrentPage * UPCOMING_PAGE_SIZE, upcoming.length)

  const selectedSubmitEmployee = useMemo(() => {
    const uid = Number(submitUserId)
    if (!uid) return null
    return eligibleEmployees.find((e) => Number(e.id) === uid) || null
  }, [submitUserId, eligibleEmployees])

  const submitActionsPendingCount = useMemo(() => {
    const a = submitRequiredActions || {}
    return REGULARIZATION_ACTION_ITEMS.reduce((acc, item) => acc + (a[item.key] ? 0 : 1), 0)
  }, [submitRequiredActions])

  const submitEffectiveHint = useMemo(() => {
    const emp = selectedSubmitEmployee
    const m = emp?.milestones || {}
    if (submitRecType === 'probation_auto_6mo') {
      return `Auto path: 6-month target is ${m.six_months || submitEffectiveDate || 'not set'}.`
    }
    if (submitRecType === 'probation_early_3mo') {
      return `Early path: 3-month target is ${m.three_months || submitEffectiveDate || 'not set'} (requires HR approval).`
    }
    if (['contract_renewal', 'contract_extension', 'project_extension'].includes(submitRecType)) {
      return 'Use the start date of the renewed/extended period.'
    }
    return submitEffectiveDate ? `Recommended effective date is ${submitEffectiveDate}.` : 'Adjust if needed.'
  }, [selectedSubmitEmployee, submitEffectiveDate, submitRecType])

  useEffect(() => {
    let cancelled = false
    async function run() {
      const uid = Number(submitUserId)
      if (!submitOpen || !uid || !canSubmitRegularization) {
        setSubmitRequiredActions(null)
        return
      }
      setRequiredActionsBusy(true)
      try {
        const res = await getRegularizationRequiredActions({ user_ids: [uid] })
        const row = Array.isArray(res?.employees) ? res.employees.find((r) => Number(r.user_id) === uid) : null
        if (!cancelled) setSubmitRequiredActions(row?.required_actions || null)
      } catch {
        if (!cancelled) setSubmitRequiredActions(null)
      } finally {
        if (!cancelled) setRequiredActionsBusy(false)
      }
    }
    run()
    return () => {
      cancelled = true
    }
  }, [submitOpen, submitUserId, canSubmitRegularization])

  async function toggleRequiredAction(key, next) {
    const uid = Number(submitUserId)
    if (!uid) return
    setRequiredActionsBusy(true)
    try {
      const res = await updateRegularizationRequiredActions(uid, { [key]: !!next })
      setSubmitRequiredActions(res?.required_actions || null)
      toast({ title: 'Updated', description: 'Required actions were updated.', variant: 'success' })
    } catch (e) {
      toast({ title: 'Update failed', description: e.message, variant: 'error' })
    } finally {
      setRequiredActionsBusy(false)
    }
  }

  useEffect(() => {
    let cancelled = false
    async function run() {
      const uid = Number(activeRec?.employee_id)
      if (!approveOpen || !uid || !canApproveRegularization) {
        setApproveRequiredActions(null)
        return
      }
      setRequiredActionsBusy(true)
      try {
        const res = await getRegularizationRequiredActions({ user_ids: [uid] })
        const row = Array.isArray(res?.employees) ? res.employees.find((r) => Number(r.user_id) === uid) : null
        if (!cancelled) setApproveRequiredActions(row?.required_actions || null)
      } catch {
        if (!cancelled) setApproveRequiredActions(null)
      } finally {
        if (!cancelled) setRequiredActionsBusy(false)
      }
    }
    run()
    return () => {
      cancelled = true
    }
  }, [approveOpen, activeRec, canApproveRegularization])

  useEffect(() => {
    setUpcomingPage(1)
  }, [daysAhead])

  useEffect(() => {
    setUpcomingPage((page) => Math.min(page, upcomingTotalPages))
  }, [upcomingTotalPages])

  async function toggleApproveRequiredAction(key, next) {
    const uid = Number(activeRec?.employee_id)
    if (!uid) return
    setRequiredActionsBusy(true)
    try {
      const res = await updateRegularizationRequiredActions(uid, { [key]: !!next })
      setApproveRequiredActions(res?.required_actions || null)
      toast({ title: 'Updated', description: 'Required actions were updated.', variant: 'success' })
    } catch (e) {
      toast({ title: 'Update failed', description: e.message, variant: 'error' })
    } finally {
      setRequiredActionsBusy(false)
    }
  }

  const eligibleById = useMemo(() => {
    const m = new Map()
    for (const e of eligibleEmployees) {
      m.set(Number(e.id), e)
    }
    return m
  }, [eligibleEmployees])

  const loadUpcoming = useCallback(async () => {
    setUpcomingLoading(true)
    try {
      const data = await getAdminUpcomingRegularizations(Number(daysAhead) || 30)
      setUpcoming(Array.isArray(data?.employees) ? data.employees : [])
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setUpcoming([])
    } finally {
      setUpcomingLoading(false)
    }
  }, [daysAhead, toast])

  const loadRecs = useCallback(async () => {
    if (!canAccessRegularizationModule) {
      setRecs([])
      setRecsLoading(false)
      return
    }
    setRecsLoading(true)
    try {
      const data = await getAdminRegularizationRecommendations(recFilter)
      setRecs(Array.isArray(data?.recommendations) ? data.recommendations : [])
    } catch (e) {
      if (String(e.message || '').includes('403') || String(e.message || '').includes('Only HR')) {
        setRecs([])
      } else {
        toast({ title: 'Failed to load recommendations', description: e.message, variant: 'error' })
        setRecs([])
      }
    } finally {
      setRecsLoading(false)
    }
  }, [recFilter, toast, canAccessRegularizationModule])

  const loadEligible = useCallback(async () => {
    if (!canAccessRegularizationModule) {
      setEligibleEmployees([])
      return
    }
    setEligibleLoading(true)
    try {
      const data = await getRegularizationEligibleEmployees()
      setEligibleEmployees(Array.isArray(data?.employees) ? data.employees : [])
    } catch {
      setEligibleEmployees([])
    } finally {
      setEligibleLoading(false)
    }
  }, [canAccessRegularizationModule])

  useEffect(() => {
    loadUpcoming()
  }, [loadUpcoming])

  useEffect(() => {
    loadRecs()
  }, [loadRecs])

  useEffect(() => {
    if (canAccessRegularizationModule) {
      loadEligible()
    }
  }, [canAccessRegularizationModule, loadEligible])

  // Allow deep-link from dashboard widgets (e.g., Expiring Contracts -> Regularization submit modal).
  useEffect(() => {
    if (!canAccessRegularizationModule) return
    const submitFor = searchParams.get('submit_for')
    if (!submitFor) return
    const id = String(submitFor).trim()
    if (!id) return
    handleOpenSubmitForEmployee(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canAccessRegularizationModule, searchParams])

  useEffect(() => {
    if (!submitUserId || !eligibleEmployees.length) return
    const emp = eligibleEmployees.find((e) => String(e.id) === String(submitUserId))
    if (emp) {
      setSubmitEffectiveDate(defaultEffectiveDateForEmployee(emp, submitRecType))
    }
  }, [submitUserId, submitRecType, eligibleEmployees])

  async function refreshAll() {
    await Promise.all([loadUpcoming(), loadRecs(), canAccessRegularizationModule ? loadEligible() : Promise.resolve()])
  }

  async function handleOpenSubmit() {
    setSubmitNotes('')
    setSubmitUserId('')
    setSubmitEmpSearch('')
    setSubmitRecType('probation_auto_6mo')
    setSubmitEffectiveDate('')
    setSubmitExpirationDate('')
    setSubmitOpen(true)
    await loadEligible()
  }

  async function handleOpenSubmitForEmployee(employeeId) {
    setSubmitNotes('')
    setSubmitEmpSearch('')
    setSubmitRecType('probation_auto_6mo')
    setSubmitUserId(String(employeeId))
    setSubmitEffectiveDate('')
    setSubmitExpirationDate('')
    setSubmitOpen(true)
    await loadEligible()
  }

  function canShowSubmitForMilestone(employeeId) {
    if (!canSubmitRegularization) return false
    // Prevent transient false-positive CTA while eligibility is still loading.
    if (eligibleLoading) return false
    const e = eligibleById.get(Number(employeeId))
    return e?.can_recommend === true
  }

  async function handleSubmitRecommendation() {
    const uid = Number(submitUserId)
    if (!uid) {
      toast({ title: 'Select an employee', variant: 'error' })
      return
    }
    const remarks = submitNotes.trim()
    if (!remarks) {
      toast({ title: 'Remarks required', description: 'Explain the basis for this recommendation.', variant: 'error' })
      return
    }
    const selectedEmp = eligibleEmployees.find((e) => Number(e.id) === uid)
    if (selectedEmp && selectedEmp.can_recommend === false) {
      toast({
        title: 'Recommendation not allowed',
        description: selectedEmp.has_pending_recommendation
          ? 'A pending recommendation already exists for this employee.'
          : 'This employee is currently not eligible for a new recommendation.',
        variant: 'error',
      })
      return
    }
    const selectedStatus = normalizeEmploymentStatus(selectedEmp?.employment_status)

    // Compute payload recommendation type + guardrails.
    const isProbation = selectedStatus === 'probationary'
    const isContractual = selectedStatus === 'contractual'
    const isProject = selectedStatus === 'project_based'

    let payloadType = submitRecType
    if (submitRecType === 'probation_auto_6mo' || submitRecType === 'probation_early_3mo') {
      payloadType = 'probation_to_regular'
    }
    if (submitRecType === 'no_action') {
      toast({ title: 'No action required', description: 'This employee is Regular/Separated.', variant: 'error' })
      return
    }

    // Enforce context: prevent irrelevant submission even if UI state gets stale.
    if (isProbation) {
      if (!['probation_auto_6mo', 'probation_early_3mo', 'probation_to_regular'].includes(submitRecType)) {
        toast({ title: 'Invalid recommendation type', description: 'This employee is probationary.', variant: 'error' })
        return
      }
    } else if (isContractual) {
      if (!['contract_renewal', 'contract_extension', 'end_contract'].includes(submitRecType)) {
        toast({ title: 'Invalid recommendation type', description: 'This employee is contractual.', variant: 'error' })
        return
      }
    } else if (isProject) {
      if (!['project_extension', 'project_completion'].includes(submitRecType)) {
        toast({ title: 'Invalid recommendation type', description: 'This employee is project-based.', variant: 'error' })
        return
      }
    }

    const needsDates = ['contract_renewal', 'contract_extension', 'project_extension'].includes(submitRecType)
    if (needsDates) {
      if (!submitEffectiveDate) {
        toast({ title: 'Effective date required', description: 'Set the start date for the renewal/extension.', variant: 'error' })
        return
      }
      if (!submitExpirationDate) {
        toast({ title: 'Expiration date required', description: 'Set the end date for the renewal/extension.', variant: 'error' })
        return
      }
    }

    if (payloadType === 'probation_to_regular') {
      if (!submitRequiredActions?.all_completed) {
        toast({
          title: 'Cannot submit',
          description: 'Performance review and checklist must be completed before regularization.',
          variant: 'error',
        })
        return
      }
    }

    setSubmitBusy(true)
    try {
      await submitRegularizationRecommendation({
        user_id: uid,
        recommendation_type: payloadType,
        recommendation_notes: remarks,
        effective_date: submitEffectiveDate || undefined,
        expiration_date: submitExpirationDate || undefined,
        auto_complete: isHrAdmin,
      })
      toast({
        title: 'Recommendation submitted',
        description: isHrAdmin
          ? 'The recommendation was recorded and processed per your HR workflow.'
          : 'Your recommendation is pending HR approval.',
        variant: 'success',
      })
      setSubmitOpen(false)
      await Promise.all([loadRecs(), loadEligible(), loadUpcoming()])
    } catch (e) {
      toast({ title: 'Submit failed', description: e.message, variant: 'error' })
    } finally {
      setSubmitBusy(false)
    }
  }

  function openApprove(rec) {
    setActiveRec(rec)
    setApproveNotes('')
    setApproveOpen(true)
  }

  function openReject(rec) {
    setActiveRec(rec)
    setRejectReason('')
    setRejectOpen(true)
  }

  async function submitApprove() {
    if (!activeRec?.id) return
    setActionSubmitting(true)
    try {
      await approveRegularizationRecommendation(activeRec.id, approveNotes.trim() || undefined)
      toast({
        title: 'Recommendation approved',
        description: 'Status will update on the next automation run if eligible.',
        variant: 'success',
      })
      setApproveOpen(false)
      await loadRecs()
    } catch (e) {
      toast({ title: 'Approve failed', description: e.message, variant: 'error' })
    } finally {
      setActionSubmitting(false)
    }
  }

  async function submitReject() {
    if (!activeRec?.id) return
    const reason = rejectReason.trim()
    if (!reason) {
      toast({ title: 'Reason required', description: 'Enter a rejection reason.', variant: 'error' })
      return
    }
    setActionSubmitting(true)
    try {
      await rejectRegularizationRecommendation(activeRec.id, reason)
      toast({ title: 'Recommendation rejected', variant: 'success' })
      setRejectOpen(false)
      await loadRecs()
    } catch (e) {
      toast({ title: 'Reject failed', description: e.message, variant: 'error' })
    } finally {
      setActionSubmitting(false)
    }
  }

  const nearingRegularizationCount = useMemo(() => {
    /**
     * "Nearing regularization (3–6 months)" is intentionally scoped to PROBATIONARY employees only
     * (contract/project rows may appear in the table for renewal/end-contract actions).
     *
     * We keep the KPI aligned with what HR expects:
     * - Count *probationary* employees currently in the milestone queue (including overdue 6-month decisions),
     *   not just those strictly between 3 and <6 months of service.
     *
     * This prevents a confusing "0" when the table has probationary rows (e.g., overdue cases),
     * while still excluding contractual/project-based rows from this probation KPI.
     */
    return upcoming.filter((row) => normalizeEmploymentStatus(row?.employment_status) === 'probationary').length
  }, [upcoming])

  const filteredEligibleModal = useMemo(() => {
    const q = submitEmpSearch.trim().toLowerCase()
    // This modal supports probationary regularization and contract/project actions.
    // Do not filter down to probationary-only.
    const list = eligibleEmployees
    if (!q) return list
    return list.filter(
      (e) =>
        (e.name || '').toLowerCase().includes(q) ||
        (e.employee_code || '').toLowerCase().includes(q),
    )
  }, [eligibleEmployees, submitEmpSearch])

  return (
    <Motion.div
      className="min-h-[calc(100vh-6rem)] min-w-0 max-w-full space-y-7 overflow-x-hidden px-1 py-4 text-foreground @sm:px-0 @sm:py-6"
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.22 }}
    >
      <div className="w-full max-w-none space-y-7 px-1 @sm:px-0">
        <header className="relative">
          <div className="flex flex-col gap-5 @lg:flex-row @lg:items-start @lg:justify-between">
          <div className="space-y-2">
            <p className="text-xs font-extrabold uppercase tracking-[0.28em] text-brand">
              Employee status
            </p>
            <h1 className="text-[30px] font-extrabold leading-tight tracking-tight text-foreground @lg:text-[34px]">Regularization</h1>
            <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
              Monitor hire-date milestones (3-, 5-, and 6-month), review eligible employees, and run the HR-only regularization
              workflow. Use the <strong className="font-medium text-foreground">History</strong> tab for a full audit log (all
              statuses) in your scope. Five-month listing is an alert; six-month requires explicit HR action (no automatic Regular).
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2 self-start @lg:pt-2">
            {canSubmitRegularization ? (
              <Button
                type="button"
                className="h-12 gap-2 rounded-md bg-brand px-5 text-sm font-bold text-brand-foreground shadow-[0_14px_28px_rgba(255,107,0,0.24)] hover:bg-brand-strong dark:shadow-[0_16px_36px_rgba(0,0,0,0.35)]"
                onClick={() => handleOpenSubmit()}
              >
                <Plus className="size-4" />
                Submit New Recommendation
              </Button>
            ) : null}
            <Button
              type="button"
              variant="outline"
              className="h-12 gap-2 rounded-md border-border/70 bg-card px-4 text-sm font-semibold shadow-sm transition hover:bg-muted/50"
              onClick={() => refreshAll()}
              disabled={upcomingLoading && recsLoading}
            >
              {upcomingLoading || recsLoading ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <RefreshCw className="size-4" />
              )}
              Refresh
            </Button>
          </div>
          </div>
        </header>

          <div className="flex items-start gap-3 rounded-lg border border-brand/35 bg-brand/5 px-5 py-4 text-sm leading-relaxed text-foreground shadow-[0_1px_0_rgba(255,107,0,0.08)] dark:border-brand/30 dark:bg-brand/10">
            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
              <Info className="size-4" aria-hidden />
            </div>
            <p>
            <strong className="font-semibold">Roles:</strong> Department Heads, Branch Heads, and Company Heads may{' '}
            <strong className="font-medium">submit</strong> recommendations for employees in their scope.{' '}
            <strong className="font-medium">Only HR (Admin)</strong> may approve or reject. Processing after approval follows the
            3-month hire-date rule (immediate Regular if already eligible, otherwise on the anniversary).
            </p>
          </div>

          <div className="grid gap-5 @lg:grid-cols-2">
            <Card className={REGULARIZATION_CARD_CLASS}>
              <CardHeader className="relative min-h-[176px] px-6 py-6">
                <div className="absolute right-6 top-6 flex size-14 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                  <UserCheck className="size-7" aria-hidden />
                </div>
                <CardTitle className="pr-20 text-lg font-extrabold tracking-tight text-foreground">
                  <span className="sr-only">Regularization queue</span>
                  Nearing regularization (3–6 months)
                </CardTitle>
                <CardDescription className="max-w-sm text-sm">Probationary employees in the key decision window.</CardDescription>
              </CardHeader>
              <CardContent className="px-6 pb-6 pt-0">
                <p className="text-[34px] font-black leading-none tabular-nums text-brand">
                  {nearingRegularizationCount}
                </p>
                <p className="mt-4 max-w-sm text-xs leading-relaxed text-muted-foreground">
                  Based on the upcoming milestones table below. Use it to prioritize reviews and submissions.
                </p>
              </CardContent>
            </Card>
            <Card className={REGULARIZATION_CARD_CLASS}>
              <CardHeader className="relative min-h-[176px] px-6 py-6">
                <div className="flex items-start justify-between gap-4">
                  <div className="space-y-3">
                <CardTitle className="text-lg font-extrabold tracking-tight text-foreground">Stay proactive</CardTitle>
                <CardDescription className="max-w-xl text-sm leading-relaxed">
                  When no items are pending, switch to <strong className="font-bold text-foreground">Approved</strong> or{' '}
                  <strong className="font-bold text-foreground">Rejected</strong> to audit history, or submit a new recommendation for an
                  eligible probationary employee using the button above.
                </CardDescription>
                  </div>
                  <div className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                    <ShieldCheck className="size-7" aria-hidden />
                  </div>
                </div>
              </CardHeader>
            </Card>
          </div>

          <Card className={REGULARIZATION_CARD_CLASS}>
            <CardHeader className="px-6 py-6">
              <div className="flex items-start gap-4">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                  <Info className="size-5" aria-hidden />
                </div>
                <div>
                  <CardTitle className="text-base font-extrabold">How it works</CardTitle>
                  <CardDescription className="mt-4 text-sm leading-relaxed">
                    <strong className="text-foreground">5 months:</strong> employees appear here for review (system alert).
                    <strong className="text-foreground"> 6 months:</strong> HR must confirm Regular or extended probation — status does
                    not flip automatically. <strong className="text-foreground">3 months:</strong> HR may submit a recommendation; upon
                    approval, employment becomes Regular immediately once the 3-month hire-date milestone is reached, or on that
                    anniversary if earlier. Contractual auto-separation and scheduled jobs run on the server.
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
          </Card>

        <AnimatedSection>
          <Card className={cn('overflow-hidden', REGULARIZATION_CARD_CLASS)}>
            <CardHeader className="flex flex-col gap-4 border-b border-border/60 px-6 py-6 @sm:flex-row @sm:items-center @sm:justify-between">
              <div>
                <CardTitle className="flex items-center gap-2 text-lg font-extrabold">
                  <CalendarClock className="size-5 text-brand" aria-hidden />
                  Upcoming milestones
                </CardTitle>
                <CardDescription>
                  Probationary employees with a Hire Date from Employment Details, including their 3-, 5-, and 6-month milestones.
                </CardDescription>
              </div>
              <div className="flex items-center gap-2">
                <Label htmlFor="reg-days" className="sr-only">
                  Window
                </Label>
                <Select value={daysAhead} onValueChange={setDaysAhead}>
                  <SelectTrigger id="reg-days" className="h-11 w-44 rounded-md border-border/70 bg-background">
                    <SelectValue placeholder="Window" />
                  </SelectTrigger>
                  <SelectContent>
                    {DAYS_OPTIONS.map((o) => (
                      <SelectItem key={o.value} value={o.value}>
                        {o.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              {upcomingLoading ? (
                <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
                  <Loader2 className="size-8 animate-spin text-primary" />
                  <p className="text-sm">Loading upcoming milestones…</p>
                </div>
              ) : upcoming.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
                  <AlertCircle className="size-10 text-muted-foreground/60" aria-hidden />
                  <p className="text-sm font-medium text-foreground">No upcoming milestones</p>
                  <p className="max-w-md text-sm text-muted-foreground">
                    No probationary employees with Hire Dates are currently available in your scope. Once Hire Date is set in
                    Employment Details, their 3-, 5-, and 6-month milestones will appear here automatically.
                  </p>
                </div>
              ) : (
                <>
                <div className="w-full min-w-0 touch-pan-x overflow-x-auto">
                  <Table className="w-full min-w-[920px] xl:min-w-[1024px]">
                    <TableHeader>
                      <TableRow className={REGULARIZATION_TABLE_HEAD_CLASS}>
                        <TableHead className="min-w-[200px] py-3.5 pl-2 sm:pl-3 xl:min-w-[220px]">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employee</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[7.5rem] py-3.5 md:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Employment type
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[6.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Hire date</span>
                        </TableHead>
                        <TableHead className="min-w-[9rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Service</span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Next Milestone</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[11rem] max-w-[16rem] py-3.5 lg:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Recommended action
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                        </TableHead>
                        <TableHead className="w-[13rem] min-w-[13rem] py-3.5 pr-2 text-right sm:pr-3 xl:w-[14rem] xl:min-w-[14rem]">
                          <span className="pr-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Actions
                          </span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {paginatedUpcoming.map((row, rowIdx) => {
                        const m = row.milestones || {}
                        const hireIso = m.hire_date || row.hire_date
                        const primary = getPrimaryMilestone(row)
                        const urgency = (() => {
                          if (row?.indicator === 'red') return { label: row.status_label || 'Overdue', tone: 'overdue' }
                          if (row?.indicator === 'orange') return { label: row.status_label || 'Due Soon', tone: 'due' }
                          if (row?.indicator === 'green') return { label: row.status_label || 'On Track', tone: 'on_track' }
                          return primary?.iso ? milestoneUrgencyFromDate(primary.iso) : { label: '—', tone: 'muted' }
                        })()
                        const empProfileTo =
                          canViewEmployeeProfile && row.id ? hrPanelPath(base, `employees/${row.id}`) : null
                        const img = profileImageUrl(row.profile_image_url)
                        return (
                          <TableRow
                            key={row.id}
                            className={cn(
                              REGULARIZATION_TABLE_ROW_CLASS,
                              rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/15 dark:bg-muted/10',
                            )}
                          >
                            <TableCell className={cn('align-top', cellPad)}>
                              <EmployeeAvatarNameRoleCell
                                name={row.name}
                                imageUrl={img}
                                profileTo={empProfileTo}
                                compact
                                roleLabel={row.position?.trim() ? row.position : 'Employee'}
                                hrRole="employee"
                              />
                              {row.employee_code ? (
                                <p className="mt-1 font-mono text-[11px] text-muted-foreground">{row.employee_code}</p>
                              ) : null}
                            </TableCell>
                            <TableCell
                              className={cn(
                                'hidden align-middle text-sm capitalize text-muted-foreground md:table-cell',
                                cellPad,
                              )}
                            >
                              {employmentTypeLabel(row.employment_type)}
                            </TableCell>
                            <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                              {formatShortDate(hireIso)}
                            </TableCell>
                            <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                              {row.service_length_label || formatServiceDurationDetailed(hireIso)}
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              {primary ? (
                                <div className="min-w-0">
                                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {primary.label}
                                  </p>
                                  <p className="mt-0.5 text-sm font-medium tabular-nums text-foreground">
                                    {formatShortDate(primary.iso)}
                                  </p>
                                </div>
                              ) : (
                                <span className="text-sm text-muted-foreground">—</span>
                              )}
                            </TableCell>
                            <TableCell className={cn('hidden max-w-[16rem] align-top lg:table-cell', cellPad)}>
                              <p className="line-clamp-3 text-sm leading-snug text-foreground/90">
                                {recommendedActionText(row)}
                              </p>
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <MilestoneQueueStatusBadge tone={urgency.tone} label={urgency.label} />
                            </TableCell>
                            <TableCell className={cn('text-right align-middle', cellPad)}>
                              <AdminDataTableActions
                                className="w-full flex-wrap justify-stretch gap-2 sm:flex-nowrap sm:justify-end sm:gap-1.5"
                                onView={
                                  canViewEmployeeProfile
                                    ? () => navigate(hrPanelPath(base, `employees/${row.id}`))
                                    : undefined
                                }
                                viewLabel="View"
                                viewAriaLabel="View employee profile"
                                showSubmitRecommendation={canShowSubmitForMilestone(row.id)}
                                onSubmitRecommendation={() => handleOpenSubmitForEmployee(row.id)}
                              />
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
                <div className="flex flex-col gap-3 border-t border-border/60 px-6 py-5 text-sm text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between">
                  <span>
                    Showing {upcomingRangeStart}-{upcomingRangeEnd} of {upcoming.length} employees
                  </span>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 gap-1 rounded-md border-border/70 bg-card px-3"
                      disabled={upcomingCurrentPage <= 1}
                      onClick={() => setUpcomingPage((page) => Math.max(1, page - 1))}
                    >
                      <ChevronLeft className="size-4" />
                      Previous
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 min-w-9 rounded-md border-brand/60 bg-brand/10 px-3 font-bold text-brand hover:bg-brand/15"
                    >
                      {upcomingCurrentPage}
                    </Button>
                    {upcomingTotalPages > 1 ? (
                      <span className="px-1 text-xs text-muted-foreground">/ {upcomingTotalPages}</span>
                    ) : null}
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 gap-1 rounded-md border-border/70 bg-card px-3"
                      disabled={upcomingCurrentPage >= upcomingTotalPages}
                      onClick={() => setUpcomingPage((page) => Math.min(upcomingTotalPages, page + 1))}
                    >
                      Next
                      <ChevronRight className="size-4" />
                    </Button>
                  </div>
                </div>
                </>
              )}
            </CardContent>
          </Card>
        </AnimatedSection>

        <AnimatedSection>
          <Card className={cn('overflow-hidden', REGULARIZATION_CARD_CLASS)}>
            <CardHeader className="flex flex-col gap-5 border-b border-border/60 px-6 py-6">
              <div className="flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between">
                <div>
                  <CardTitle className="flex flex-wrap items-center gap-2 text-lg font-extrabold">
                    <FileText className="size-5 text-brand" aria-hidden />
                    Regularization recommendations
                    {canAccessRegularizationModule && !recsLoading ? (
                      <Badge variant="secondary" className="ml-1 rounded-full capitalize">
                        {recFilter === 'pending' && `${recs.length} pending`}
                        {recFilter === 'approved' && `${recs.length} approved`}
                        {recFilter === 'rejected' && `${recs.length} rejected`}
                        {recFilter === 'history' && `${recs.length} total`}
                      </Badge>
                    ) : null}
                  </CardTitle>
                  <CardDescription>
                    {recFilter === 'history'
                      ? 'Complete audit log of every regularization recommendation in your scope (pending, approved, and rejected). Scoped by role: HR sees all; heads see their organization.'
                      : 'Line managers submit; HR (Admin) approves or rejects. After approval, probationary employees become Regular according to policy and the recommended effective date where applicable.'}
                  </CardDescription>
                </div>
                {canSubmitRegularization ? (
                  <Button
                    type="button"
                    className="h-11 shrink-0 gap-2 rounded-md bg-foreground px-4 font-bold text-background shadow-[0_12px_28px_rgba(0,0,0,0.16)] transition hover:bg-foreground/90 dark:bg-brand dark:text-brand-foreground dark:hover:bg-brand-strong @lg:self-center"
                    onClick={() => handleOpenSubmit()}
                  >
                    <Plus className="size-4" />
                    Submit New Recommendation
                  </Button>
                ) : null}
              </div>
              {canAccessRegularizationModule ? (
                <nav className="flex flex-wrap gap-7 border-t border-border/50 pt-4" aria-label="Filter by status">
                  {STATUS_TABS.map((tab) => (
                    <button
                      key={tab.value}
                      type="button"
                      onClick={() => setRecFilter(tab.value)}
                      className={cn(
                        'border-b-2 px-0 py-2 text-sm font-bold transition-colors',
                        recFilter === tab.value
                          ? 'border-brand text-brand'
                          : 'border-transparent text-muted-foreground hover:text-foreground',
                      )}
                    >
                      {tab.label}
                    </button>
                  ))}
                </nav>
              ) : null}
            </CardHeader>
            <CardContent className="p-0">
              {!canAccessRegularizationModule ? (
                <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                  Recommendation history is available to Department Heads, Branch Heads, Company Heads, and HR. Use the milestone
                  table above for review context.
                </div>
              ) : recsLoading ? (
                <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
                  <Loader2 className="size-8 animate-spin text-primary" />
                  <p className="text-sm">Loading recommendations…</p>
                </div>
              ) : recs.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-5 px-6 py-20 text-center">
                  <div className="flex size-20 items-center justify-center rounded-full border border-brand/20 bg-brand/10 text-brand shadow-sm">
                    <FileText className="size-9" aria-hidden />
                  </div>
                  <div className="space-y-2">
                    <p className="text-base font-semibold text-foreground">No recommendations in this view</p>
                    <p className="mx-auto max-w-lg text-sm leading-relaxed text-muted-foreground">
                      {recFilter === 'history' ? (
                        <>
                          No regularization recommendations appear in your scope yet. When managers submit recommendations and HR
                          acts on them, every record will stay visible here for audit.
                        </>
                      ) : (
                        <>
                          There are no records with{' '}
                          <strong className="font-medium text-foreground">
                            {STATUS_TABS.find((t) => t.value === recFilter)?.label ?? recFilter}
                          </strong>{' '}
                          status. Use the tabs above — including <strong className="font-medium text-foreground">History</strong> — to
                          review all statuses, or submit a new recommendation for an eligible employee.
                        </>
                      )}
                    </p>
                  </div>
                  {canSubmitRegularization ? (
                    <Button
                      type="button"
                      variant="outline"
                      className="h-11 gap-2 rounded-md border-brand/70 px-5 font-bold text-brand hover:bg-brand/10"
                      onClick={() => handleOpenSubmit()}
                    >
                      <Plus className="size-4" />
                      Submit New Recommendation
                    </Button>
                  ) : null}
                </div>
              ) : recFilter === 'history' ? (
                <div className="w-full min-w-0 touch-pan-x overflow-x-auto">
                  <Table className="w-full min-w-[1100px] xl:min-w-[1280px]">
                    <TableHeader>
                      <TableRow className={REGULARIZATION_TABLE_HEAD_CLASS}>
                        <TableHead className="min-w-[4.5rem] py-3.5 pl-2 sm:pl-3">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">ID</span>
                        </TableHead>
                        <TableHead className="min-w-[180px] py-3.5 xl:min-w-[200px]">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employee</span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Submitted</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[8rem] py-3.5 md:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Type</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[10rem] max-w-[13rem] py-3.5 lg:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Basis & notes
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[8rem] py-3.5 xl:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Reviewed by
                          </span>
                        </TableHead>
                        <TableHead className="hidden min-w-[7.5rem] py-3.5 xl:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Reviewed at
                          </span>
                        </TableHead>
                        <TableHead className="hidden min-w-[10rem] max-w-[12rem] py-3.5 xl:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">HR remarks</span>
                        </TableHead>
                        <TableHead className="w-[7rem] min-w-[7rem] py-3.5 pr-2 text-right sm:pr-3">
                          <span className="pr-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Actions
                          </span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {recs.map((rec, rowIdx) => {
                        const empImg = profileImageUrl(rec.employee_profile_image)
                        const empProfileTo =
                          canViewEmployeeProfile && rec.employee_id
                            ? hrPanelPath(base, `employees/${rec.employee_id}`)
                            : null
                        return (
                          <TableRow
                            key={rec.id}
                            className={cn(
                              REGULARIZATION_TABLE_ROW_CLASS,
                              rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/15 dark:bg-muted/10',
                            )}
                          >
                            <TableCell className={cn('align-middle font-mono text-xs font-semibold tabular-nums', cellPad)}>
                              #{rec.id}
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <EmployeeAvatarNameCell
                                name={rec.employee_name}
                                imageUrl={empImg}
                                profileTo={empProfileTo}
                                compact
                              />
                            </TableCell>
                            <TableCell className={cn('align-middle text-xs tabular-nums text-foreground', cellPad)}>
                              {formatDateTimeSubmitted(rec.recommended_at)}
                            </TableCell>
                            <TableCell
                              className={cn('hidden align-top text-sm leading-snug text-foreground md:table-cell', cellPad)}
                            >
                              {recommendationTypeLabel(rec.recommendation_type)}
                            </TableCell>
                            <TableCell
                              className={cn('hidden max-w-[13rem] align-top lg:table-cell', cellPad)}
                              onClick={(e) => e.stopPropagation()}
                            >
                              <RemarksPreviewCell text={rec.recommendation_notes} />
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <RegularizationStatusBadge status={rec.status} processed={rec.processed} />
                            </TableCell>
                            <TableCell
                              className={cn('hidden align-top text-sm xl:table-cell', cellPad)}
                              title={rec.hr_reviewed_by_name || ''}
                            >
                              {rec.status === 'pending' ? (
                                <span className="text-muted-foreground">—</span>
                              ) : (
                                <span className="font-medium text-foreground">{rec.hr_reviewed_by_name || 'HR'}</span>
                              )}
                            </TableCell>
                            <TableCell className={cn('hidden align-middle text-xs tabular-nums text-muted-foreground xl:table-cell', cellPad)}>
                              {rec.status === 'pending' ? '—' : formatDateTimeSubmitted(rec.hr_reviewed_at)}
                            </TableCell>
                            <TableCell
                              className={cn('hidden max-w-[12rem] align-top xl:table-cell', cellPad)}
                              onClick={(e) => e.stopPropagation()}
                            >
                              <RemarksPreviewCell text={rec.hr_notes} />
                            </TableCell>
                            <TableCell className={cn('text-right align-middle', cellPad)}>
                              <AdminDataTableActions
                                className="w-full flex-wrap justify-stretch gap-2 sm:flex-nowrap sm:justify-end sm:gap-1.5"
                                onView={() => {
                                  setViewRec(rec)
                                  setViewOpen(true)
                                }}
                                viewLabel="View details"
                                viewAriaLabel="View regularization request details"
                              />
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <div className="w-full min-w-0 touch-pan-x overflow-x-auto bg-card">
                  <Table className="w-full min-w-[980px] xl:min-w-[1100px]">
                    <TableHeader>
                      <TableRow className={REGULARIZATION_TABLE_HEAD_CLASS}>
                        <TableHead className="min-w-[200px] py-3.5 pl-2 sm:pl-3 xl:min-w-[220px]">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employee</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[7.5rem] py-3.5 md:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Employment type
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[6.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Hire date</span>
                        </TableHead>
                        <TableHead className="min-w-[9rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Service</span>
                        </TableHead>
                        <TableHead className="min-w-[10rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Recommended by
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[8rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Recommendation type
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[7rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Effective date
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[7.5rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                        </TableHead>
                        <TableHead className="hidden min-w-[11rem] max-w-[14rem] py-3.5 lg:table-cell">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Remarks</span>
                        </TableHead>
                        <TableHead className="w-[13rem] min-w-[13rem] py-3.5 pr-2 text-right sm:pr-3 xl:w-[15rem] xl:min-w-[15rem]">
                          <span className="pr-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Actions
                          </span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {recs.map((rec, rowIdx) => {
                        const empImg = profileImageUrl(rec.employee_profile_image)
                        const pending = rec.status === 'pending'
                        const empProfileTo =
                          canViewEmployeeProfile && rec.employee_id
                            ? hrPanelPath(base, `employees/${rec.employee_id}`)
                            : null
                        return (
                          <TableRow
                            key={rec.id}
                            className={cn(
                              REGULARIZATION_TABLE_ROW_CLASS,
                              rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/15 dark:bg-muted/10',
                            )}
                          >
                            <TableCell className={cn('align-top', cellPad)}>
                              <EmployeeAvatarNameRoleCell
                                name={rec.employee_name}
                                imageUrl={empImg}
                                profileTo={empProfileTo}
                                compact
                                roleLabel={rec.employee_position?.trim() ? rec.employee_position : 'Employee'}
                                hrRole="employee"
                              />
                              {rec.employee_code ? (
                                <p className="mt-1 font-mono text-[11px] text-muted-foreground">{rec.employee_code}</p>
                              ) : null}
                            </TableCell>
                            <TableCell
                              className={cn(
                                'hidden align-middle text-sm capitalize text-muted-foreground md:table-cell',
                                cellPad,
                              )}
                            >
                              {employmentTypeLabel(rec.employee_employment_type)}
                            </TableCell>
                            <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                              {rec.employee_hire_date ? formatShortDate(rec.employee_hire_date) : '—'}
                            </TableCell>
                            <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                              {formatServiceDuration(rec)}
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <div className="max-w-[11rem] min-w-0">
                                <p
                                  className="truncate text-sm font-semibold text-foreground"
                                  title={rec.recommended_by_name || ''}
                                >
                                  {rec.recommended_by_name || '—'}
                                </p>
                                {rec.recommended_by_role_label ? (
                                  <Badge
                                    variant="secondary"
                                    className={cn(
                                      'mt-1.5 h-5 w-fit max-w-full truncate rounded-md border px-2 py-0 text-[10px] font-semibold tracking-wide',
                                      recommenderRoleBadgeClass(rec.recommended_by_hr_role),
                                    )}
                                    title={rec.recommended_by_role_label}
                                  >
                                    {rec.recommended_by_role_label}
                                  </Badge>
                                ) : null}
                              </div>
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <span className="text-sm leading-snug text-foreground">
                                {recommendationTypeLabel(rec.recommendation_type)}
                              </span>
                            </TableCell>
                            <TableCell className={cn('align-middle text-sm tabular-nums font-medium text-foreground', cellPad)}>
                              {rec.effective_date ? formatShortDate(rec.effective_date) : '—'}
                            </TableCell>
                            <TableCell className={cn('align-top', cellPad)}>
                              <RegularizationStatusBadge status={rec.status} processed={rec.processed} />
                            </TableCell>
                            <TableCell
                              className={cn('hidden max-w-[14rem] align-top lg:table-cell', cellPad)}
                              onClick={(e) => e.stopPropagation()}
                            >
                              <RemarksPreviewCell text={rec.recommendation_notes} />
                            </TableCell>
                            <TableCell className={cn('text-right align-middle', cellPad)}>
                              <AdminDataTableActions
                                className="w-full flex-wrap justify-stretch gap-2 sm:flex-nowrap sm:justify-end sm:gap-1.5"
                                onView={() => {
                                  setViewRec(rec)
                                  setViewOpen(true)
                                }}
                                viewLabel="View details"
                                viewAriaLabel="View recommendation details"
                                profileHref={empProfileTo ?? undefined}
                                showApprove={canApproveRegularization && pending}
                                onApprove={() => openApprove(rec)}
                                showReject={canApproveRegularization && pending}
                                onReject={() => openReject(rec)}
                              />
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              )}
            </CardContent>
          </Card>
        </AnimatedSection>

        {canAccessRegularizationModule && !canApproveRegularization ? (
          <p className="text-center text-xs text-muted-foreground">
            You can submit recommendations for employees in your scope. <strong className="font-medium">Approve</strong> and{' '}
            <strong className="font-medium">Reject</strong> are available only to HR (Admin) with the{' '}
            <code className="rounded bg-muted px-1">employees.edit</code> permission.
          </p>
        ) : null}
      </div>

      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent showCloseButton className={adminFormDialogContentClass('max-w-md')}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <div className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Approve recommendation</DialogTitle>
              <DialogDescription className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                HR approval records the decision. Eligible probationary employees are set to Regular when the 3-month rule is met
                (immediate or on the next job run). Optional notes are stored on the record.
              </DialogDescription>
            </div>
          </DialogHeader>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            {activeRec?.recommendation_type ? (
              <div className="mb-4 rounded-xl border border-border/70 bg-muted/15 p-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-foreground">Required Actions Before Confirmation</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {requiredActionsBusy
                        ? 'Loading checklist…'
                        : approveRequiredActions?.all_completed
                          ? 'All required actions completed.'
                          : 'Some required actions are still pending.'}
                    </p>
                  </div>
                  {approveRequiredActions?.all_completed ? (
                    <Badge className="h-6 shrink-0 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300" variant="secondary">
                      Completed
                    </Badge>
                  ) : (
                    <Badge className="h-6 shrink-0 bg-rose-500/12 text-rose-700 dark:text-rose-300" variant="secondary">
                      Pending
                    </Badge>
                  )}
                </div>

                <div className="mt-3 grid gap-2">
                  {[
                    { key: 'performance_review_completed', label: 'Performance Review Completed' },
                    { key: 'training_completed', label: 'Training / Orientation Checklist Completed' },
                    { key: 'documents_submitted', label: 'Documents Submitted (ID, clearances, etc.)' },
                    { key: 'manager_recommendation_received', label: 'Manager Recommendation Received' },
                    { key: 'checklist_completed', label: 'Checklist Completion' },
                  ].map((item) => {
                    const done = !!approveRequiredActions?.[item.key]
                    return (
                      <button
                        key={item.key}
                        type="button"
                        disabled={requiredActionsBusy || !canApproveRegularization}
                        onClick={() => toggleApproveRequiredAction(item.key, !done)}
                        className={cn(
                          'flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors',
                          done
                            ? 'border-emerald-500/30 bg-emerald-500/8 hover:bg-emerald-500/10'
                            : 'border-rose-500/25 bg-rose-500/6 hover:bg-rose-500/8',
                          (requiredActionsBusy || !canApproveRegularization) && 'opacity-60',
                        )}
                      >
                        <span className="text-sm text-foreground">{item.label}</span>
                        <span
                          className={cn(
                            'text-xs font-semibold',
                            done ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300',
                          )}
                        >
                          {done ? 'Completed' : 'Pending'}
                        </span>
                      </button>
                    )
                  })}
                </div>
              </div>
            ) : null}

            <div className="space-y-2">
              <Label htmlFor="approve-notes">Notes (optional)</Label>
              <Textarea
                id="approve-notes"
                value={approveNotes}
                onChange={(e) => setApproveNotes(e.target.value)}
                rows={4}
                placeholder="Internal notes…"
                className="min-h-[100px] resize-y rounded-lg"
              />
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setApproveOpen(false)} disabled={actionSubmitting}>
              Cancel
            </Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={submitApprove}
              disabled={actionSubmitting}
            >
              {actionSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Approve'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent showCloseButton className={adminFormDialogContentClass('max-w-md')}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <div className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Reject recommendation</DialogTitle>
              <DialogDescription className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Provide a clear reason for the employee file and future audits.
              </DialogDescription>
            </div>
          </DialogHeader>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            <div className="space-y-2">
              <Label htmlFor="reject-reason">Reason *</Label>
              <Textarea
                id="reject-reason"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                rows={4}
                required
                className="min-h-[100px] resize-y rounded-lg"
              />
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setRejectOpen(false)} disabled={actionSubmitting}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" onClick={submitReject} disabled={actionSubmitting}>
              {actionSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Reject'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={submitOpen} onOpenChange={setSubmitOpen}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/60 backdrop-blur-[3px]"
          innerClassName="gap-0 overflow-hidden p-0"
          closeButtonClassName="right-7 top-7 size-12 rounded-xl border-border/70 bg-background/95 text-foreground hover:bg-muted"
          className="flex max-h-[min(92vh,980px)] w-[min(94vw,900px)]! max-w-[900px]! flex-col gap-0 overflow-hidden rounded-[1.75rem] border border-border/70 bg-card p-0 text-card-foreground shadow-2xl dark:shadow-black/50"
        >
          <DialogHeader className="bg-card px-6 pb-4 pt-8 pr-20 sm:px-12 sm:pt-12">
            <div className="flex max-w-3xl gap-5">
              <div className="flex size-16 shrink-0 items-center justify-center rounded-xl border border-brand/25 bg-brand/10 text-brand shadow-inner sm:size-[4.5rem]">
                <FileText className="size-8" aria-hidden />
              </div>
              <div className="min-w-0">
                <DialogTitle className="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl">
                  Submit New Recommendation
                </DialogTitle>
                <DialogDescription className="mt-3 max-w-2xl text-base leading-relaxed text-muted-foreground">
                  Choose an eligible probationary employee, set the recommendation type and target effective date, and document
                  your rationale. Submission follows your configured HR workflow (including auto-approval when enabled).
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>
          <div className="min-h-0 flex-1 overflow-y-auto bg-card px-6 pb-6 pt-4 sm:px-12">
            <div className="w-full space-y-8">
              <div className="flex w-full items-center gap-4 rounded-lg border border-border/50 bg-muted/30 px-5 py-4 text-sm text-muted-foreground dark:bg-background/35">
                <Info className="size-5 shrink-0 text-brand" aria-hidden />
                <span>{submitEffectiveHint}</span>
              </div>
              <div className="w-full space-y-3">
                <div>
                  <Label htmlFor="reg-emp-search" className="text-lg font-extrabold text-foreground">
                    Employee
                  </Label>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Only employees eligible for regularization (e.g. approaching 3- or 6-month milestones).
                  </p>
                </div>
                <div className="relative w-full">
                  <Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
                  <Input
                    id="reg-emp-search"
                    value={submitEmpSearch}
                    onChange={(e) => setSubmitEmpSearch(e.target.value)}
                    placeholder="Search by name or employee code…"
                    className="h-12 w-full rounded-xl border-border/70 bg-background pl-12 text-base shadow-sm"
                    autoComplete="off"
                  />
                </div>
                {eligibleLoading ? (
                  <div className="flex items-center gap-2 rounded-xl border border-border/70 bg-muted/20 px-5 py-8 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin text-brand" />
                    Loading eligible employees…
                  </div>
                ) : (
                  <div
                    className="max-h-72 w-full overflow-y-auto rounded-xl border border-border/80 bg-card shadow-sm"
                    role="listbox"
                    aria-label="Eligible employees"
                  >
                    {filteredEligibleModal.length === 0 ? (
                      <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                        No employees match your search, or none are eligible right now.
                      </p>
                    ) : (
                      filteredEligibleModal.map((e) => {
                        const selected = String(submitUserId) === String(e.id)
                        const m = e.milestones || {}
                        const target = m.three_months || e.three_month_target_date
                        return (
                          <button
                            key={e.id}
                            type="button"
                            role="option"
                            aria-selected={selected}
                            onClick={() => setSubmitUserId(String(e.id))}
                            className={cn(
                              'group flex w-full items-center gap-4 border-b border-border/60 px-5 py-4 text-left transition-colors last:border-b-0',
                              selected
                                ? 'bg-brand/8 text-foreground ring-1 ring-inset ring-brand/25 dark:bg-brand/12'
                                : 'hover:bg-muted/35',
                            )}
                          >
                            <span
                              className={cn(
                                'flex size-12 shrink-0 items-center justify-center rounded-full border text-base font-bold',
                                selected
                                  ? 'border-brand/30 bg-brand/10 text-brand'
                                  : 'border-border bg-muted/35 text-foreground',
                              )}
                            >
                              {employeeInitials(e.name)}
                            </span>
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-base font-bold text-foreground">{e.name}</span>
                              <span className="mt-1 block truncate text-sm text-muted-foreground">
                                {e.employee_code ? `${e.employee_code} - ` : ''}
                                {e.months_since_hire != null ? `${Number(e.months_since_hire).toFixed(1)} mo - ` : ''}
                                {target ? `3-mo - ${target}` : 'Eligible'}
                              </span>
                            </span>
                            {selected ? (
                              <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground shadow-sm">
                                <CheckCircle2 className="size-5" aria-hidden />
                              </span>
                            ) : null}
                          </button>
                        )
                      })
                    )}
                  </div>
                )}
              </div>
              <div className="w-full space-y-3">
                <Label htmlFor="reg-type" className="text-lg font-extrabold text-foreground">
                  Recommendation type
                </Label>
                <Select value={submitRecType} onValueChange={setSubmitRecType}>
                  <SelectTrigger id="reg-type" className="h-12 w-full rounded-xl border-brand/70 bg-background px-4 text-base shadow-sm focus:ring-brand">
                    <SelectValue placeholder="Type" />
                  </SelectTrigger>
                  <SelectContent>
                    {(() => {
                      const emp = eligibleEmployees.find((e) => String(e.id) === String(submitUserId))
                      const status = normalizeEmploymentStatus(emp?.employment_status)
                      if (status === 'regular' || status === 'separated') {
                        return [{ value: 'no_action', label: 'No Action Required' }]
                      }
                      if (status === 'contractual') {
                        return [
                          { value: 'contract_renewal', label: 'Contract Renewal' },
                          { value: 'contract_extension', label: 'Contract Extension' },
                        ]
                      }
                      if (status === 'project_based') {
                        return [
                          { value: 'project_extension', label: 'Project Extension' },
                          { value: 'project_completion', label: 'Project Completion' },
                        ]
                      }
                      // Default: probationary context
                      return UI_RECOMMENDATION_TYPES
                    })().map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {selectedSubmitEmployee &&
              ['contractual', 'project_based'].includes(normalizeEmploymentStatus(selectedSubmitEmployee?.employment_status)) ? (
                <div className="w-full rounded-xl border border-border/80 bg-card p-6 shadow-sm dark:bg-background/20">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-foreground">Current contract status</p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        Start: {selectedSubmitEmployee?.contract_start_date || '—'} • End:{' '}
                        {selectedSubmitEmployee?.contract_end_date || '—'}
                      </p>
                    </div>
                    {(() => {
                      const s = contractExpiryStatus(selectedSubmitEmployee?.contract_end_date)
                      const tone = s.tone === 'overdue' ? 'overdue' : s.tone === 'due' ? 'due' : s.tone === 'on_track' ? 'on_track' : 'muted'
                      return <MilestoneQueueStatusBadge tone={tone} label={s.label} />
                    })()}
                  </div>
                </div>
              ) : null}
              <div className="w-full space-y-3">
                <Label htmlFor="reg-effective" className="text-lg font-extrabold text-foreground">
                  Recommended effective date
                </Label>
                <Input
                  id="reg-effective"
                  type="date"
                  value={submitEffectiveDate}
                  onChange={(e) => setSubmitEffectiveDate(e.target.value)}
                  className="h-12 w-full rounded-xl border-border/70 bg-background px-4 text-base shadow-sm focus-visible:ring-brand"
                />
                <p className="flex items-start gap-2 text-sm text-muted-foreground">
                  <Info className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
                  {submitEffectiveHint}
                </p>
              </div>

              {selectedSubmitEmployee &&
              ['probationary', 'contractual', 'project_based'].includes(normalizeEmploymentStatus(selectedSubmitEmployee?.employment_status)) ? (
                <div className="w-full rounded-xl border border-border/80 bg-card p-6 shadow-sm dark:bg-background/20">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-lg font-extrabold text-foreground">Required Actions Before Confirmation</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {requiredActionsBusy
                          ? 'Loading checklist…'
                          : submitActionsPendingCount === 0
                            ? 'All required actions completed.'
                            : `${submitActionsPendingCount} items still pending.`}
                      </p>
                    </div>
                    {submitActionsPendingCount === 0 ? (
                      <Badge
                        className="h-9 shrink-0 rounded-full bg-emerald-500/12 px-4 text-sm font-bold text-emerald-700 dark:text-emerald-300"
                        variant="secondary"
                      >
                        Completed
                      </Badge>
                    ) : (
                      <Badge className="h-9 shrink-0 rounded-full bg-brand/10 px-4 text-sm font-bold text-brand" variant="secondary">
                        Pending
                      </Badge>
                    )}
                  </div>

                  <div className="mt-5 divide-y divide-border/70 border-t border-border/70">
                    {REGULARIZATION_ACTION_ITEMS.map((item) => {
                      const done = !!submitRequiredActions?.[item.key]
                      const ActionIcon = item.Icon
                      return (
                        <button
                          key={item.key}
                          type="button"
                          disabled={requiredActionsBusy || !canSubmitRegularization}
                          onClick={() => toggleRequiredAction(item.key, !done)}
                          className={cn(
                            'flex w-full items-center justify-between gap-4 py-4 text-left transition-colors hover:bg-muted/25',
                            done
                              ? 'text-foreground'
                              : 'text-foreground',
                            (requiredActionsBusy || !canSubmitRegularization) && 'opacity-60',
                          )}
                        >
                          <span className="flex min-w-0 items-center gap-4">
                            <span
                              className={cn(
                                'flex size-10 shrink-0 items-center justify-center rounded-full border',
                                done
                                  ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'
                                  : 'border-brand/20 bg-brand/10 text-brand',
                              )}
                            >
                              <ActionIcon className="size-5" aria-hidden />
                            </span>
                            <span className="min-w-0 text-base font-medium text-foreground">{item.label}</span>
                          </span>
                          <span
                            className={cn(
                              'shrink-0 rounded-full px-4 py-2 text-sm font-bold',
                              done
                                ? 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300'
                                : 'bg-brand/10 text-brand',
                            )}
                          >
                            {done ? 'Completed' : 'Pending'}
                          </span>
                        </button>
                      )
                    })}
                  </div>
                </div>
              ) : null}

              {['contract_renewal', 'contract_extension', 'project_extension'].includes(submitRecType) ? (
                <div className="w-full space-y-3">
                  <Label htmlFor="reg-expiration" className="text-lg font-extrabold text-foreground">
                    {submitRecType === 'project_extension' ? 'Project End Date' : 'Contract End Date'}
                  </Label>
                  <Input
                    id="reg-expiration"
                    type="date"
                    value={submitExpirationDate}
                    onChange={(e) => setSubmitExpirationDate(e.target.value)}
                    className="h-12 w-full rounded-xl border-border/70 bg-background px-4 text-base shadow-sm focus-visible:ring-brand"
                  />
                  <p className="text-sm text-muted-foreground">
                    Expiration Date is required for Contractual and Project-based recommendations.
                  </p>
                </div>
              ) : null}
              <div className="w-full space-y-3">
                <Label htmlFor="reg-notes" className="text-lg font-extrabold text-foreground">
                  Reason / remarks <span className="text-brand">*</span>
                </Label>
                <Textarea
                  id="reg-notes"
                  value={submitNotes}
                  onChange={(e) => setSubmitNotes(e.target.value.slice(0, SUBMIT_NOTES_MAX_LENGTH))}
                  rows={4}
                  required
                  placeholder="Document the basis for this recommendation (performance, policy alignment, etc.)."
                  maxLength={SUBMIT_NOTES_MAX_LENGTH}
                  className="min-h-32 w-full resize-y rounded-xl border-border/70 bg-background p-4 text-base shadow-sm focus-visible:ring-brand"
                />
                <p className="text-right text-sm tabular-nums text-muted-foreground">
                  {submitNotes.length} / {SUBMIT_NOTES_MAX_LENGTH}
                </p>
              </div>
            </div>
          </div>
          <DialogFooter className="border-t border-border/60 bg-card px-6 py-6 sm:px-12">
            <Button
              type="button"
              variant="outline"
              className="h-12 w-full rounded-xl border-foreground/80 bg-background px-6 text-base font-semibold text-foreground hover:bg-muted sm:w-auto sm:min-w-32"
              onClick={() => setSubmitOpen(false)}
              disabled={submitBusy}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="h-12 w-full gap-3 rounded-xl bg-brand px-7 text-base font-bold text-brand-foreground shadow-[0_14px_30px_rgba(255,107,0,0.28)] hover:bg-brand-strong sm:w-auto sm:min-w-72"
              onClick={handleSubmitRecommendation}
              disabled={submitBusy}
            >
              {submitBusy ? (
                <Loader2 className="size-5 animate-spin" />
              ) : (
                <>
                  <Send className="size-5" aria-hidden />
                  Submit Recommendation
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <RegularizationRecommendationViewDialog
        open={viewOpen}
        onOpenChange={(open) => {
          setViewOpen(open)
          if (!open) setViewRec(null)
        }}
        rec={viewRec}
        employeeProfileHref={
          viewRec?.employee_id && canViewEmployeeProfile ? hrPanelPath(base, `employees/${viewRec.employee_id}`) : null
        }
      />
    </Motion.div>
  )
}
