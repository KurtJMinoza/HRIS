import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Loader2,
  CheckCircle2,
  XCircle,
  Plus,
  FileText,
  RefreshCw,
  TrendingUp,
  Calendar,
  Clock,
  AlertTriangle,
  Paperclip,
  Eye,
  Trash2,
  X,
  UploadCloud,
  ArrowRight,
} from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { isRosterStaffMember } from '@/lib/rosterStaff'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  getLeaveRequests,
  getMyLeaveSummary,
  getAdminLeaveByRequestId,
  createLeaveRequest,
  approveLeaveRequest,
  bulkApproveLeaveRequests,
  bulkApproveLeavePreview,
  rejectLeaveRequest,
  getEmployees,
  updateLeaveNotes,
  uploadAdminLeaveDocument,
  deleteAdminLeaveRequest,
  deleteMyLeaveRequest,
  profileImageUrl,
  validateAdminLeaveDateRange,
} from '@/api'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import {
  requestModuleActionsTdClass,
  requestModuleActionsWrapRowClass,
  requestModuleCompactButtonClass,
  requestModuleHeadRowClass,
  requestModuleRowClass,
  leaveAdminTableClass,
  leaveEmployeeTableClass,
  requestModuleTdClass,
  requestModuleTdMutedClass,
  requestModuleThClass,
  requestModuleThRightClass,
} from '@/lib/requestModuleTable'
import {
  FIELD_TEXTAREA_CLASS,
  FIELD_TEXTAREA_CLASS_SM,
} from '@/lib/fieldClasses'
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
import { LeaveRequestDetailModal } from '@/components/leave/LeaveRequestDetailModal'
import LeaveStatusPill from '@/components/leave/LeaveStatusPill'
import { earliestLeaveStartYmd } from '@/lib/attendanceDates'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { BulkApprovalSummaryDialog } from '@/components/admin/BulkApprovalSummaryDialog'
import { BulkApproveToolbar } from '@/components/admin/BulkApproveToolbar'
import { BulkApproveConfirmDialog } from '@/components/admin/BulkApproveConfirmDialog'
import { useBulkApprovalSelection } from '@/hooks/useBulkApprovalSelection'
import { notifyPendingApprovalsChanged } from '@/lib/hrPendingApprovalsEvents'

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

const LEAVE_TYPES = [
  { value: 'vacation', label: 'Vacation' },
  { value: 'sick', label: 'Sick' },
  { value: 'emergency', label: 'Emergency' },
  // Undertime is time-based; admin leave form does not capture time,
  // so we do not expose it here to avoid inaccurate computations.
  { value: 'half_day', label: 'Half Day' },
  { value: 'other', label: 'Other' },
]

function formatDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateRange(start, end) {
  if (!start || !end) return '—'
  if (start === end) return formatDate(start)
  return `${formatDate(start)} – ${formatDate(end)}`
}

function formatType(type) {
  if (!type) return '—'
  const found = LEAVE_TYPES.find((t) => t.value === type)
  return found ? found.label : type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ')
}

const ORG_HEAD_HR_ROLES = new Set(['department_head', 'branch_head', 'company_head'])

const REST_DAY_BYPASS_REASON_MIN = 10

const MAX_LEAVE_SUPPORTING_FILES = 5
const MAX_LEAVE_FILE_BYTES = 10 * 1024 * 1024
const adminLeaveCardClass =
  'rounded-[18px] border border-border/70 bg-card shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const adminLeavePrimaryButtonClass =
  'h-11 gap-2 rounded-lg bg-brand px-5 text-sm font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const adminLeaveOutlineButtonClass =
  'h-11 gap-2 rounded-lg border-border/80 bg-card px-5 text-sm font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'
const adminLeaveModalFieldClass =
  'h-14 rounded-xl border-border/80 bg-background px-4 text-base font-medium text-foreground shadow-sm transition focus-visible:border-brand focus-visible:ring-brand/25 dark:border-white/12 dark:bg-background/40 dark:focus-visible:border-brand/70'
const adminLeaveModalSelectClass =
  'h-14 w-full rounded-xl border border-brand bg-background px-5 text-lg font-semibold text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-background/40 dark:focus:ring-brand/20'
const adminLeaveModalLabelClass = 'text-base font-semibold tracking-tight text-foreground'
const adminLeaveModalHintClass = 'text-[13px] leading-relaxed text-muted-foreground'

function supportingDocUrls(leave) {
  if (!leave) return []
  if (Array.isArray(leave.document_urls) && leave.document_urls.length) return leave.document_urls
  if (leave.document_url) return [leave.document_url]
  return []
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

export default function AdminLeave() {
  const { toast } = useToast()
  const [searchParams, setSearchParams] = useSearchParams()
  const { user, refreshUser } = useAuth()
  const perms = new Set(user?.permissions ?? [])
  const canApproveLeave = perms.has('leave.approve')
  const canLeaveNotes = perms.has('leave.notes')
  /** Pure HR only (`can_file_leave_for_others`); never for assigned org heads (also blocked by `hr_role` as a safeguard). */
  const isOrgHeadHrRole = ORG_HEAD_HR_ROLES.has(user?.hr_role ?? '')
  const showEmployeePicker =
    user?.can_file_leave_for_others === true && !isOrgHeadHrRole
  const isAdminHr = user?.hr_role === 'admin_hr'
  const [leaveRequests, setLeaveRequests] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [statusFilter, setStatusFilter] = useState('')
  const [filterFrom, setFilterFrom] = useState('')
  const [filterTo, setFilterTo] = useState('')
  const [appliedFrom, setAppliedFrom] = useState('')
  const [appliedTo, setAppliedTo] = useState('')

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectLeave, setRejectLeave] = useState(null)
  const [rejectReason, setRejectReason] = useState('')
  const [rejectSubmitting, setRejectSubmitting] = useState(false)

  const [actionLoadingId, setActionLoadingId] = useState(null)

  const [addOpen, setAddOpen] = useState(false)
  const [employees, setEmployees] = useState([])
  const [addForm, setAddForm] = useState({
    user_id: '',
    type: 'vacation',
    start_date: '',
    end_date: '',
    half_type: '',
    notes: '',
    supportingFiles: [],
  })
  const [addSubmitting, setAddSubmitting] = useState(false)
  const addLeaveSubmitLock = useRef(false)

  const minLeaveDate = useMemo(() => earliestLeaveStartYmd(), [addOpen])
  const minEndDate =
    addForm.start_date && addForm.start_date >= minLeaveDate ? addForm.start_date : minLeaveDate

  const [notesOpen, setNotesOpen] = useState(false)
  const [notesLeave, setNotesLeave] = useState(null)
  const [notesValue, setNotesValue] = useState('')
  const [notesSubmitting, setNotesSubmitting] = useState(false)

  const [approveOpen, setApproveOpen] = useState(false)
  const [approveLeave, setApproveLeave] = useState(null)
  const [approveNotes, setApproveNotes] = useState('')
  const [approveSubmitting, setApproveSubmitting] = useState(false)
  const [bulkApproveRemarks, setBulkApproveRemarks] = useState('')
  const [totalMatchingApprovable, setTotalMatchingApprovable] = useState(0)
  const [bulkApproving, setBulkApproving] = useState(false)
  const [bulkConfirmOpen, setBulkConfirmOpen] = useState(false)
  const [bulkSummaryOpen, setBulkSummaryOpen] = useState(false)
  const [bulkSummary, setBulkSummary] = useState(null)
  const [approveForceInsufficientCredits, setApproveForceInsufficientCredits] = useState(false)
  const [addBypassLeaveCredits, setAddBypassLeaveCredits] = useState(false)
  const [addRangeRestDay, setAddRangeRestDay] = useState(null)
  const [addRangeValidating, setAddRangeValidating] = useState(false)
  const [addBypassRestDays, setAddBypassRestDays] = useState(false)
  const [addRestDayBypassReason, setAddRestDayBypassReason] = useState('')
  const [approveRangeSummary, setApproveRangeSummary] = useState(null)
  const [approveRangeValidating, setApproveRangeValidating] = useState(false)
  const [approveBypassRestDays, setApproveBypassRestDays] = useState(false)
  const [approveRestDayBypassReason, setApproveRestDayBypassReason] = useState('')
  const [deleteDialog, setDeleteDialog] = useState({ open: false, leave: null })
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLeave, setDetailLeave] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const tabInitialized = useRef(false)
  const [tab, setTab] = useState('all')
  const [myLeaveRequests, setMyLeaveRequests] = useState([])
  const [loadingMine, setLoadingMine] = useState(false)
  const [mineError, setMineError] = useState(null)
  const [allPage, setAllPage] = useState(1)
  const [minePage, setMinePage] = useState(1)
  const [allPagination, setAllPagination] = useState(null)
  const [minePagination, setMinePagination] = useState(null)
  const leavePerPage = 10

  const fetchLeaves = useCallback(async () => {
    setError(null)
    try {
      const data = await getLeaveRequests({
        status: statusFilter || undefined,
        from_date: appliedFrom || undefined,
        to_date: appliedTo || undefined,
        page: allPage,
        per_page: leavePerPage,
      })
      setLeaveRequests(data.leave_requests || [])
      setAllPagination(data.pagination || null)
    } catch (e) {
      setError(e.message)
      setLeaveRequests([])
    } finally {
      setLoading(false)
    }
  }, [statusFilter, appliedFrom, appliedTo, allPage])

  const fetchMineLeaves = useCallback(async () => {
    setMineError(null)
    try {
      const data = await getMyLeaveSummary({
        status: statusFilter || undefined,
        from_date: appliedFrom || undefined,
        to_date: appliedTo || undefined,
        page: minePage,
        per_page: leavePerPage,
      })
      setMyLeaveRequests(Array.isArray(data.leave_requests) ? data.leave_requests : [])
      setMinePagination(data.pagination || null)
    } catch (e) {
      setMineError(e.message)
      setMyLeaveRequests([])
    } finally {
      setLoadingMine(false)
    }
  }, [statusFilter, appliedFrom, appliedTo, minePage])

  useEffect(() => {
    setAllPage(1)
    setMinePage(1)
  }, [statusFilter, appliedFrom, appliedTo])

  useEffect(() => {
    if (tabInitialized.current || !user?.id) return
    tabInitialized.current = true
    if (!showEmployeePicker) setTab('mine')
  }, [user?.id, showEmployeePicker])

  useEffect(() => {
    setLoading(true)
    fetchLeaves()
  }, [fetchLeaves])

  useEffect(() => {
    if (tab !== 'mine') return
    setLoadingMine(true)
    fetchMineLeaves()
  }, [tab, fetchMineLeaves])

  useEffect(() => {
    if (addOpen) {
      setAddBypassLeaveCredits(false)
      setAddBypassRestDays(false)
      setAddRestDayBypassReason('')
      setAddRangeRestDay(null)
      setAddRangeValidating(false)
    }
  }, [addOpen])

  const addSubjectUid = showEmployeePicker ? addForm.user_id : user?.id ? String(user.id) : ''
  const addRangeEndYmd =
    addForm.type === 'half_day' ? addForm.start_date : addForm.end_date

  useEffect(() => {
    if (!addOpen || !addSubjectUid || !addForm.start_date || !addRangeEndYmd) {
      setAddRangeRestDay(null)
      setAddRangeValidating(false)
      return
    }
    if (addForm.start_date < minLeaveDate || addRangeEndYmd < addForm.start_date) {
      setAddRangeRestDay(null)
      setAddRangeValidating(false)
      return
    }
    let cancelled = false
    setAddRangeValidating(true)
    const t = setTimeout(async () => {
      try {
        const data = await validateAdminLeaveDateRange({
          user_id: addSubjectUid,
          start_date: addForm.start_date,
          end_date: addRangeEndYmd,
        })
        if (!cancelled) {
          setAddRangeRestDay(data)
          setAddRangeValidating(false)
        }
      } catch (e) {
        if (!cancelled) {
          setAddRangeRestDay({
            valid: false,
            message: e.message,
            has_schedule: false,
          })
          setAddRangeValidating(false)
        }
      }
    }, 350)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [
    addOpen,
    addSubjectUid,
    addForm.start_date,
    addRangeEndYmd,
    addForm.type,
    minLeaveDate,
    showEmployeePicker,
    user?.id,
  ])

  useEffect(() => {
    if (!approveOpen || !approveLeave) {
      setApproveRangeSummary(null)
      setApproveRangeValidating(false)
      return
    }
    const uid = approveLeave.employee_id ?? approveLeave.user_id
    if (!uid || !approveLeave.start_date) {
      setApproveRangeSummary(null)
      return
    }
    const end = approveLeave.end_date || approveLeave.start_date
    let cancelled = false
    setApproveRangeValidating(true)
    validateAdminLeaveDateRange({
      user_id: uid,
      start_date: approveLeave.start_date,
      end_date: end,
    })
      .then((d) => {
        if (!cancelled) {
          setApproveRangeSummary(d)
          setApproveRangeValidating(false)
        }
      })
      .catch(() => {
        if (!cancelled) {
          setApproveRangeSummary(null)
          setApproveRangeValidating(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [approveOpen, approveLeave?.id, approveLeave?.employee_id, approveLeave?.start_date, approveLeave?.end_date])

  useEffect(() => {
    if (!addOpen) return
    if (user?.can_file_leave_for_others === undefined && user?.hr_role === 'admin_hr') {
      refreshUser().catch(() => {})
    }
    if (showEmployeePicker) {
      getEmployees({ per_page: 200 }).then((d) => setEmployees(d.employees || [])).catch(() => setEmployees([]))
    } else if (user?.id) {
      setAddForm((f) => ({ ...f, user_id: String(user.id) }))
    }
  }, [addOpen, showEmployeePicker, user?.id, user?.can_file_leave_for_others, user?.hr_role, refreshUser])

  function addSupportingFilesFromInput(e) {
    const picked = Array.from(e.target.files || [])
    const input = e.target
    if (input) input.value = ''
    if (!picked.length) return
    setAddForm((prev) => ({
      ...prev,
      supportingFiles: [...(prev.supportingFiles ?? []), ...picked].slice(0, MAX_LEAVE_SUPPORTING_FILES),
    }))
  }

  function removeSupportingFile(index) {
    setAddForm((prev) => ({
      ...prev,
      supportingFiles: (prev.supportingFiles ?? []).filter((_, i) => i !== index),
    }))
  }

  const addRestDayBypassOk =
    isAdminHr &&
    addBypassRestDays &&
    addRestDayBypassReason.trim().length >= REST_DAY_BYPASS_REASON_MIN
  const addFormRestDayBlocksSubmit =
    addRangeValidating ||
    Boolean(addRangeRestDay && !addRangeRestDay.valid && !addRestDayBypassOk)

  const approveRestDayBypassOk =
    isAdminHr &&
    approveBypassRestDays &&
    approveRestDayBypassReason.trim().length >= REST_DAY_BYPASS_REASON_MIN
  const approveFormRestDayBlocksSubmit =
    approveRangeValidating ||
    Boolean(approveRangeSummary && !approveRangeSummary.valid && !approveRestDayBypassOk)

  useEffect(() => {
    if (addRangeRestDay?.valid) {
      setAddBypassRestDays(false)
      setAddRestDayBypassReason('')
    }
  }, [addRangeRestDay?.valid])

  useEffect(() => {
    if (approveRangeSummary?.valid) {
      setApproveBypassRestDays(false)
      setApproveRestDayBypassReason('')
    }
  }, [approveRangeSummary?.valid])

  const handleAddLeave = async (e) => {
    e.preventDefault()
    if (addSubmitting || addLeaveSubmitLock.current) return
    addLeaveSubmitLock.current = true
    const uid = showEmployeePicker
      ? (addForm.user_id ? parseInt(addForm.user_id, 10) : null)
      : user?.id
        ? parseInt(String(user.id), 10)
        : null
    if (!uid || !addForm.start_date || !addForm.end_date) {
      addLeaveSubmitLock.current = false
      return
    }

    if (addForm.start_date < minLeaveDate) {
      setError('Leave can only be filed for future dates. The earliest start date is tomorrow.')
      addLeaveSubmitLock.current = false
      return
    }
    if (addForm.end_date < addForm.start_date) {
      setError('End date must be on or after the start date.')
      addLeaveSubmitLock.current = false
      return
    }

    if (addForm.type === 'half_day' && !addForm.half_type) {
      setError('Please select whether the half day is AM or PM.')
      addLeaveSubmitLock.current = false
      return
    }
    if (addFormRestDayBlocksSubmit) {
      setError(
        addRangeRestDay?.message ||
          'You cannot file leave on your rest day. Please select working days only.'
      )
      toast({
        title: 'Invalid date range',
        description:
          addRangeRestDay?.message ||
          'You cannot file leave on your rest day. Please select working days only.',
        variant: 'error',
      })
      addLeaveSubmitLock.current = false
      return
    }
    for (const f of addForm.supportingFiles ?? []) {
      if (f.size > MAX_LEAVE_FILE_BYTES) {
        setError(`Each file must be at most 10MB (${f.name}).`)
        toast({ title: 'File too large', description: `Max 10MB per file (${f.name}).`, variant: 'error' })
        addLeaveSubmitLock.current = false
        return
      }
    }
    setAddSubmitting(true)
    setError(null)
    try {
      const created = await createLeaveRequest({
        user_id: uid,
        type: addForm.type,
        start_date: addForm.start_date,
        end_date: addForm.end_date,
        ...(addForm.type === 'half_day' ? { half_type: addForm.half_type } : {}),
        ...(addForm.notes.trim() ? { notes: addForm.notes.trim() } : {}),
        ...(user?.is_super_admin && addBypassLeaveCredits ? { bypass_leave_credit_check: true } : {}),
        ...(addRestDayBypassOk
          ? {
              bypass_rest_days: true,
              rest_day_bypass_reason: addRestDayBypassReason.trim(),
            }
          : {}),
      })
      const newId = created?.leave_request?.id
      const files = addForm.supportingFiles ?? []
      if (newId && files.length) {
        for (const file of files) {
          await uploadAdminLeaveDocument(newId, file)
        }
      }
      const empName = showEmployeePicker
        ? employees.find((e) => String(e.id) === String(addForm.user_id))?.name || 'Employee'
        : user?.name || 'You'
      setAddOpen(false)
      setAddForm({
        user_id: showEmployeePicker ? '' : String(user?.id ?? ''),
        type: 'vacation',
        start_date: '',
        end_date: '',
        half_type: '',
        notes: '',
        supportingFiles: [],
      })
      await Promise.all([fetchLeaves(), fetchMineLeaves()])
      if (!showEmployeePicker || String(uid) === String(user?.id)) {
        setTab('mine')
      }
      toast({
        title: `Leave request submitted${showEmployeePicker ? ` for ${empName}` : ''}`,
        description: 'Pending review in your approval chain.',
        variant: 'success',
      })
    } catch (err) {
      setError(err.message)
      toast({ title: 'Failed to add leave request', description: err.message, variant: 'error' })
    } finally {
      addLeaveSubmitLock.current = false
      setAddSubmitting(false)
    }
  }

  function openDetailDialog(leave) {
    setDetailLeave(leave || null)
    setDetailOpen(true)
    const id = leave?.id
    if (!id) return
    setDetailLoading(true)
    getAdminLeaveByRequestId(id)
      .then((data) => {
        const next = data.leave_request || data.leave_requests?.[0]
        if (next) setDetailLeave(next)
      })
      .catch((e) => {
        toast({ title: 'Failed to load leave details', description: e.message, variant: 'error' })
      })
      .finally(() => setDetailLoading(false))
  }

  const leaveRequestIdFromUrl = searchParams.get('request_id')
  useEffect(() => {
    if (!leaveRequestIdFromUrl) return

    let cancelled = false
    ;(async () => {
      try {
        setDetailOpen(true)
        setDetailLeave(null)
        setDetailLoading(true)
        const data = await getAdminLeaveByRequestId(leaveRequestIdFromUrl)
        const leave = data.leave_request || data.leave_requests?.[0]
        if (cancelled) return
        if (!leave) {
          toast({
            title: 'Leave request not found',
            description: 'It may be outside your scope or was removed.',
            variant: 'error',
          })
          return
        }
        setDetailLeave(leave)
        setSearchParams(
          (prev) => {
            const next = new URLSearchParams(prev)
            next.delete('request_id')
            return next
          },
          { replace: true },
        )
      } catch (e) {
        if (!cancelled) {
          toast({ title: 'Failed to load leave request', description: e.message, variant: 'error' })
        }
      } finally {
        if (!cancelled) setDetailLoading(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [leaveRequestIdFromUrl, setSearchParams, toast])

  const openApproveDialog = (leave) => {
    setApproveLeave(leave)
    setApproveNotes(leave.notes || '')
    setApproveForceInsufficientCredits(false)
    setApproveBypassRestDays(false)
    setApproveRestDayBypassReason('')
    setApproveOpen(true)
  }

  const handleConfirmApprove = async (e) => {
    e.preventDefault()
    if (!approveLeave) return
    if (approveFormRestDayBlocksSubmit) {
      toast({
        title: 'Cannot approve',
        description:
          approveRangeSummary?.message ||
          'This range includes a rest day. Adjust dates or use an HR override with a documented reason.',
        variant: 'error',
      })
      return
    }
    setApproveSubmitting(true)
    setError(null)
    setActionLoadingId(approveLeave.id)
    try {
      const data = await approveLeaveRequest(approveLeave.id, approveNotes.trim(), {
        forceInsufficientCredits: Boolean(user?.is_super_admin && approveForceInsufficientCredits),
        ...(approveRestDayBypassOk
          ? {
              bypassRestDays: true,
              restDayBypassReason: approveRestDayBypassReason.trim(),
            }
          : {}),
      })
      setApproveOpen(false)
      setApproveLeave(null)
      setApproveNotes('')
      await fetchLeaves()
      notifyPendingApprovalsChanged()
      toast({ title: data.message || 'Leave approved', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to approve leave', description: e.message, variant: 'error' })
    } finally {
      setApproveSubmitting(false)
      setActionLoadingId(null)
    }
  }

  function exportLeaveCsv() {
    const headers = [
      'Employee',
      'Leave type',
      'Start date',
      'End date',
      'Duration',
      'Status',
      'Reason / remarks',
    ]
    const rows = leaveRequests.map((leave) => [
      leave.employee_name || '',
      formatType(leave.type),
      leave.start_date || '',
      leave.end_date || '',
      formatDuration(leave),
      leave.status || '',
      [leave.notes, leave.rejection_note].filter(Boolean).join(' | ') || '',
    ])
    const csv = [headers, ...rows]
      .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
      .join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `leave-requests-${appliedFrom || 'all'}-${appliedTo || 'all'}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function handleBulkApprove() {
    if (bulkSelection.effectiveSelectedCount === 0) return
    setBulkConfirmOpen(false)
    setBulkApproving(true)
    setError(null)
    try {
      const payload = bulkSelection.buildBulkApprovePayload(bulkApproveRemarks)
      const res = await bulkApproveLeaveRequests(payload)
      const approved = Number(res?.approved_count || 0)
      const skipped = Number(res?.skipped_count || 0)
      const failed = Number(res?.failed_count || 0)
      const failedItems = Array.isArray(res?.failed_items) ? res.failed_items : []
      toast({
        title: 'Bulk approval complete',
        description: `${approved} approved${skipped || failed ? `, ${skipped + failed} skipped/failed` : ''}.`,
        variant: approved > 0 ? 'success' : 'default',
      })
      setBulkSummary({
        approved_count: approved,
        skipped_count: skipped,
        failed_count: failed,
        failed_items: failedItems,
      })
      if (failedItems.length > 0) setBulkSummaryOpen(true)
      if (approved > 0) notifyPendingApprovalsChanged()
      bulkSelection.clearSelection()
      await fetchLeaves()
    } catch (e) {
      setError(e.message)
      toast({ title: 'Bulk approval failed', description: e.message, variant: 'error' })
    } finally {
      setBulkApproving(false)
    }
  }

  const openRejectDialog = (leave) => {
    setRejectLeave(leave)
    setRejectReason('')
    setRejectOpen(true)
  }

  const handleConfirmReject = async (e) => {
    e.preventDefault()
    if (!rejectLeave) return
    setRejectSubmitting(true)
    setError(null)
    try {
      await rejectLeaveRequest(rejectLeave.id, rejectReason)
      setRejectOpen(false)
      setRejectLeave(null)
      await fetchLeaves()
      notifyPendingApprovalsChanged()
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to reject leave', description: e.message, variant: 'error' })
    } finally {
      setRejectSubmitting(false)
    }
  }

  async function handleDelete() {
    if (!deleteDialog.leave) return
    setDeleteSubmitting(true)
    try {
      if (tab === 'mine') {
        await deleteMyLeaveRequest(deleteDialog.leave.id)
      } else {
        await deleteAdminLeaveRequest(deleteDialog.leave.id)
      }
      toast({ title: 'Leave deleted', variant: 'success' })
      setDeleteDialog({ open: false, leave: null })
      await Promise.all([fetchLeaves(), fetchMineLeaves()])
    } catch (e) {
      toast({ title: 'Failed to delete leave', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const openNotesDialog = (leave) => {
    setNotesLeave(leave)
    setNotesValue(leave.notes || '')
    setNotesOpen(true)
  }

  const handleSaveNotes = async (e) => {
    e.preventDefault()
    if (!notesLeave) return
    setNotesSubmitting(true)
    setError(null)
    try {
      await updateLeaveNotes(notesLeave.id, notesValue.trim())
      await fetchLeaves()
      setNotesOpen(false)
      setNotesLeave(null)
      toast({ title: 'Notes updated', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to update notes', description: e.message, variant: 'error' })
    } finally {
      setNotesSubmitting(false)
    }
  }

  const isMineTab = tab === 'mine'
  const activeLeaveRequests = isMineTab ? myLeaveRequests : leaveRequests
  const activeLoading = isMineTab ? loadingMine : loading
  const activeError = isMineTab ? mineError : error
  const activePagination = isMineTab ? minePagination : allPagination
  const totalCount = activeLeaveRequests.length
  const pendingCount = activeLeaveRequests.filter((l) => l.status === 'pending').length
  const approvedCount = activeLeaveRequests.filter((l) => l.status === 'approved').length
  const rejectedCount = activeLeaveRequests.filter((l) => l.status === 'rejected').length

  const statusCounts = {
    '': isMineTab ? myLeaveRequests.length : leaveRequests.length,
    pending: isMineTab
      ? myLeaveRequests.filter((l) => l.status === 'pending').length
      : leaveRequests.filter((l) => l.status === 'pending').length,
    approved: isMineTab
      ? myLeaveRequests.filter((l) => l.status === 'approved').length
      : leaveRequests.filter((l) => l.status === 'approved').length,
    rejected: isMineTab
      ? myLeaveRequests.filter((l) => l.status === 'rejected').length
      : leaveRequests.filter((l) => l.status === 'rejected').length,
  }
  const bulkApprovalFilters = useMemo(
    () => ({
      date_from: appliedFrom || undefined,
      date_to: appliedTo || undefined,
      status: statusFilter || undefined,
    }),
    [appliedFrom, appliedTo, statusFilter],
  )
  const bulkFiltersKey = useMemo(() => JSON.stringify(bulkApprovalFilters), [bulkApprovalFilters])

  useEffect(() => {
    if (!canApproveLeave) {
      setTotalMatchingApprovable(0)
      return undefined
    }
    let cancelled = false
    bulkApproveLeavePreview(bulkApprovalFilters)
      .then((res) => {
        if (!cancelled) setTotalMatchingApprovable(Number(res?.approvable_count) || 0)
      })
      .catch(() => {
        if (!cancelled) setTotalMatchingApprovable(0)
      })
    return () => {
      cancelled = true
    }
  }, [bulkApprovalFilters, bulkFiltersKey, canApproveLeave])

  const pageBulkRows = useMemo(
    () =>
      canApproveLeave
        ? leaveRequests.filter((leave) => leave?.status === 'pending' && leave?.actor_can_approve)
        : [],
    [canApproveLeave, leaveRequests],
  )

  const bulkSelection = useBulkApprovalSelection({
    pageRows: pageBulkRows,
    totalMatchingCount: totalMatchingApprovable,
    bulkFilters: bulkApprovalFilters,
    filtersKey: bulkFiltersKey,
  })

  const today = new Date()
  const currentMonth = today.getMonth()
  const currentYear = today.getFullYear()
  const monthlyLeaves = (isMineTab ? myLeaveRequests : leaveRequests).filter((leave) => {
    const basis = leave.start_date || leave.created_at
    if (!basis) return false
    const d = new Date(basis)
    if (Number.isNaN(d.getTime())) return false
    return d.getMonth() === currentMonth && d.getFullYear() === currentYear
  })

  const monthlyTotal = monthlyLeaves.length
  const monthlyPending = monthlyLeaves.filter((l) => l.status === 'pending').length
  const monthlyApproved = monthlyLeaves.filter((l) => l.status === 'approved').length
  const monthlyRejected = monthlyLeaves.filter((l) => l.status === 'rejected').length
  const monthLabel = today.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' })

  function formatDuration(leave) {
    const { type, start_date, end_date, half_type } = leave
    if (!start_date || !end_date) return '—'
    if (type === 'half_day') {
      const label = half_type === 'am' ? 'AM' : half_type === 'pm' ? 'PM' : ''
      return `0.5 day${label ? ` (${label})` : ''}`
    }
    const start = new Date(start_date)
    const end = new Date(end_date)
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '—'
    const diffMs = end.getTime() - start.getTime()
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24)) + 1
    if (diffDays <= 0) return '1 day'
    return `${diffDays} day${diffDays > 1 ? 's' : ''}`
  }

  return (
    <div className="flex w-full min-w-0 flex-col gap-6 @md:gap-8">
      <div className="flex w-full flex-col gap-5 pb-1 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand">Leave management</p>
          <h1 className="mt-3 text-3xl font-bold tracking-tight text-foreground @sm:text-4xl">Leave</h1>
          <p className="mt-2 text-[15px] leading-relaxed text-muted-foreground">
            {isMineTab
              ? 'Track leave you filed and follow each step in the approval chain.'
              : canApproveLeave || canLeaveNotes
                ? 'View and manage employee leave requests in your scope. Approval, rejection, and notes require the matching permissions.'
                : 'View employee leave requests in your scope (read-only).'}
          </p>
          {totalCount > 0 && (
            <p className="mt-1 text-xs text-muted-foreground">
              {pendingCount > 0
                ? `${pendingCount} pending, ${approvedCount} approved, ${rejectedCount} rejected.`
                : `No pending leave. ${approvedCount} approved, ${rejectedCount} rejected.`}
            </p>
          )}
        </div>
        <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
          <Button
            type="button"
            variant="outline"
            className={cn(adminLeaveOutlineButtonClass, 'flex-1 @lg:flex-initial')}
            onClick={() => {
              if (isMineTab) {
                setLoadingMine(true)
                fetchMineLeaves()
              } else {
                fetchLeaves()
              }
            }}
            disabled={activeLoading}
          >
            {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
            Refresh
          </Button>
          {canApproveLeave && (
          <Button
            className={cn(adminLeavePrimaryButtonClass, 'flex-1 @lg:flex-initial')}
            onClick={() => {
              setAddOpen(true)
              setAddForm({
                user_id: showEmployeePicker ? '' : String(user?.id ?? ''),
                type: 'vacation',
                start_date: '',
                end_date: '',
                half_type: '',
                notes: '',
                supportingFiles: [],
              })
              setError(null)
            }}
          >
            <Plus className="size-4" />
            File new leave
          </Button>
          )}
        </div>
      </div>

      {totalCount > 0 && (
        <div className="grid w-full gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
          {/* Total */}
          <Card className={cn(adminLeaveCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">{monthLabel}</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-foreground">{monthlyTotal}</p>
                  <p className="mt-1 text-xs text-muted-foreground">Total requests</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-blue-500/15 dark:bg-blue-500/20">
                  <Calendar className="size-5 text-blue-600 dark:text-blue-400" />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Pending */}
          <Card className={cn(adminLeaveCardClass, 'overflow-hidden transition-all', monthlyPending > 0 && 'border-amber-400/60 shadow-[0_0_18px_rgba(245,158,11,0.12)] dark:border-amber-500/40')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Pending review</p>
                  <p className={`mt-1 text-4xl font-black tracking-tight ${monthlyPending > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'}`}>
                    {monthlyPending}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {monthlyPending > 0 ? 'Need action' : 'All cleared'}
                  </p>
                </div>
                <div className={`flex size-10 items-center justify-center rounded-xl ${monthlyPending > 0 ? 'bg-amber-500/20 animate-pulse' : 'bg-amber-500/10'}`}>
                  <Clock className={`size-5 ${monthlyPending > 0 ? 'text-amber-500 dark:text-amber-400' : 'text-amber-500/50'}`} />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Approved */}
          <Card className={cn(adminLeaveCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Approved</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">{monthlyApproved}</p>
                  <p className="mt-1 text-xs text-muted-foreground">This month</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/15 dark:bg-emerald-500/20">
                  <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Rejected */}
          <Card className={cn(adminLeaveCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Rejected</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-rose-600 dark:text-rose-400">{monthlyRejected}</p>
                  <p className="mt-1 text-xs text-muted-foreground">This month</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-rose-500/15 dark:bg-rose-500/20">
                  <XCircle className="size-5 text-rose-600 dark:text-rose-400" />
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {pendingCount > 0 && !isMineTab && (
        <div className="flex flex-col @sm:flex-row items-start @sm:items-center justify-between gap-3 rounded-xl border border-amber-400/50 bg-amber-500/10 px-4 py-3.5 dark:border-amber-500/40 dark:bg-amber-500/8">
          <div className="flex items-center gap-3">
            <AlertTriangle className="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
              <p className="font-semibold text-amber-800 dark:text-amber-200">
                {pendingCount} leave request{pendingCount > 1 ? 's' : ''} need{pendingCount === 1 ? 's' : ''} your approval
              </p>
              <p className="text-xs text-amber-700/70 dark:text-amber-300/60">
                Review and respond before the leave date to avoid scheduling disruptions.
              </p>
            </div>
          </div>
          <Button
            size="sm"
            className="shrink-0 bg-amber-600 text-white hover:bg-amber-500 dark:bg-amber-600 dark:hover:bg-amber-500"
            onClick={() => setStatusFilter('pending')}
          >
            Review Now
          </Button>
        </div>
      )}

      {(activeError || error) && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {activeError || error}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        <div
          className="inline-flex min-w-0 flex-wrap gap-2 rounded-2xl border border-border/70 bg-muted/30 p-1 shadow-inner"
          role="tablist"
          aria-label="Leave views"
        >
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'all'}
            onClick={() => setTab('all')}
            className={cn(
              'rounded-xl px-5 py-2.5 text-sm font-semibold transition-all',
              tab === 'all'
                ? 'bg-card text-foreground shadow-sm ring-1 ring-border/70'
                : 'text-muted-foreground hover:bg-background hover:text-foreground',
            )}
          >
            All Requests
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'mine'}
            onClick={() => setTab('mine')}
            className={cn(
              'rounded-xl px-5 py-2.5 text-sm font-semibold transition-all',
              tab === 'mine'
                ? 'bg-card text-foreground shadow-sm ring-1 ring-border/70'
                : 'text-muted-foreground hover:bg-background hover:text-foreground',
            )}
          >
            My Requests
          </button>
        </div>
      </div>

      <Card className={cn(adminLeaveCardClass, 'w-full min-w-0 overflow-hidden')}>
        <CardHeader className="flex flex-col gap-4 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
          <div className="min-w-0">
            <CardTitle className="text-lg font-semibold @md:text-xl">
              {isMineTab ? 'My leave requests' : 'Leave requests'}
            </CardTitle>
            <CardDescription className="text-sm @md:text-[15px]">
              {isMineTab
                ? 'Leave you submitted and its approval progress.'
                : 'Filter by status'}
            </CardDescription>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {STATUS_OPTIONS.map((opt) => {
              const active = statusFilter === opt.value
              const count = statusCounts[opt.value]
              const activeStyles = {
                '': 'bg-foreground text-background border-foreground',
                'pending': 'bg-amber-500 text-white border-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.3)]',
                'approved': 'bg-emerald-600 text-white border-emerald-600',
                'rejected': 'bg-rose-600 text-white border-rose-600',
              }
              const inactiveStyles = {
                '': 'border-border/60 text-muted-foreground hover:text-foreground hover:border-foreground/40',
                'pending': 'border-border/60 text-muted-foreground hover:text-amber-600 hover:border-amber-400/60 dark:hover:text-amber-400',
                'approved': 'border-border/60 text-muted-foreground hover:text-emerald-600 hover:border-emerald-400/60 dark:hover:text-emerald-400',
                'rejected': 'border-border/60 text-muted-foreground hover:text-rose-600 hover:border-rose-400/60 dark:hover:text-rose-400',
              }
              return (
                <button
                  key={opt.value || 'all'}
                  type="button"
                  onClick={() => setStatusFilter(opt.value)}
                  className={[
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all',
                    active ? (activeStyles[opt.value] || activeStyles['']) : (inactiveStyles[opt.value] || inactiveStyles['']),
                  ].join(' ')}
                >
                  {opt.label}
                  <span className={`inline-flex min-w-[18px] items-center justify-center rounded-full px-1 py-0.5 text-[10px] font-bold tabular-nums ${active ? 'bg-white/25' : 'bg-muted'}`}>
                    {count}
                  </span>
                </button>
              )
            })}
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {!isMineTab ? (
          <div className="border-b border-border/40 px-4 py-4 @sm:px-6">
            <BulkApproveToolbar
              idPrefix="leave-bulk"
              dateFrom={filterFrom}
              dateTo={filterTo}
              onDateFromChange={setFilterFrom}
              onDateToChange={setFilterTo}
              onApplyFilters={() => {
                setAppliedFrom(filterFrom)
                setAppliedTo(filterTo)
              }}
              applyingFilters={loading}
              onExportCsv={exportLeaveCsv}
              exportDisabled={leaveRequests.length === 0}
              showBulkActions={canApproveLeave}
              remarks={bulkApproveRemarks}
              onRemarksChange={setBulkApproveRemarks}
              selectedCount={bulkSelection.effectiveSelectedCount}
              selectAllMatching={bulkSelection.selectAllMatching}
              pageSelectableCount={bulkSelection.pageCount}
              totalMatchingCount={bulkSelection.totalCount}
              showPageSelectAllBanner={bulkSelection.showPageSelectAllBanner}
              onSelectAllMatching={bulkSelection.selectAllMatchingRecords}
              onClearSelection={bulkSelection.clearSelection}
              entityLabel="requests"
              onApproveClick={() => setBulkConfirmOpen(true)}
              approving={bulkApproving}
            />
          </div>
          ) : null}
          {activeLoading ? (
            <div className="min-h-[min(42vh,400px)] overflow-x-auto px-2 @sm:px-0">
              <table className="w-full min-w-[min(100%,720px)] text-sm">
                <tbody>
                  <TableBodySkeleton rows={6} cols={10} />
                </tbody>
              </table>
            </div>
          ) : activeLeaveRequests.length === 0 ? (
            <div className="flex min-h-[min(58vh,620px)] flex-col items-center justify-center px-6 py-16 text-center @md:py-24">
              <div className="relative mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                <FileText className="size-11" strokeWidth={1.85} aria-hidden />
                <span className="absolute -left-1 top-2 text-lg font-semibold text-brand" aria-hidden>
                  +
                </span>
                <span className="absolute -right-2 bottom-3 text-lg font-semibold text-brand/70" aria-hidden>
                  +
                </span>
              </div>
              <p className="max-w-md text-xl font-semibold tracking-tight text-foreground">
                {statusFilter
                  ? `No ${statusFilter} leave requests.`
                  : isMineTab
                    ? 'No leave requests yet.'
                    : 'No leave requests yet.'}
              </p>
              <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                {isMineTab
                  ? 'You have not filed leave yet. Use File New Leave to submit one.'
                  : 'When employees submit leave, they will appear here for review.'}
              </p>
              {canApproveLeave ? (
                <Button
                  type="button"
                  className={cn(adminLeavePrimaryButtonClass, 'mt-7 px-6')}
                  onClick={() => {
                    setAddOpen(true)
                    setAddForm({
                      user_id: showEmployeePicker ? '' : String(user?.id ?? ''),
                      type: 'vacation',
                      start_date: '',
                      end_date: '',
                      half_type: '',
                      notes: '',
                      supportingFiles: [],
                    })
                    setError(null)
                  }}
                >
                  <Plus className="size-4" />
                  File new leave
                </Button>
              ) : null}
            </div>
          ) : isMineTab ? (
            <div className="flex-1 overflow-x-auto">
              <table className={leaveEmployeeTableClass}>
                <colgroup>
                  <col className="w-[9rem]" />
                  <col className="w-[12rem]" />
                  <col className="w-[7rem]" />
                  <col className="w-[11rem]" />
                  <col className="w-[14rem]" />
                  <col className="w-[10rem]" />
                  <col className="w-[9.5rem]" />
                  <col className="w-[9rem]" />
                </colgroup>
                <thead>
                  <tr className={requestModuleHeadRowClass}>
                    <th className={requestModuleThClass}>Leave type</th>
                    <th className={requestModuleThClass}>Date / range</th>
                    <th className={requestModuleThClass}>Duration</th>
                    <th className={requestModuleThClass}>Supporting documents</th>
                    <th className={requestModuleThClass}>Reason / remarks</th>
                    <th className={requestModuleThClass}>Status</th>
                    <th className={requestModuleThRightClass}>Date filed</th>
                    <th className={requestModuleThRightClass}>Actions</th>
                  </tr>
                </thead>
                <tbody className="text-[13px]">
                  {activeLeaveRequests.map((leave, idx) => {
                    const isUndertimeRow = leave.type === 'undertime'
                    const isHalfDayRow = leave.type === 'half_day'
                    const undertimeMinutes = typeof leave.undertime_minutes === 'number' ? leave.undertime_minutes : null
                    const remarksPreview = [leave.notes, leave.rejection_note].filter(Boolean).join('\n\n') || ''
                    return (
                      <tr key={leave.id} className={requestModuleRowClass(idx)}>
                        <td className={cn(requestModuleTdClass, 'text-muted-foreground')}>{formatType(leave.type)}</td>
                        <td className={cn(requestModuleTdClass, 'font-medium leading-snug')}>
                          {formatDateRange(leave.start_date, leave.end_date)}
                        </td>
                        <td className={requestModuleTdMutedClass}>
                          {isUndertimeRow ? (
                            undertimeMinutes !== null ? `${undertimeMinutes} min` : '—'
                          ) : isHalfDayRow ? (
                            leave.half_type === 'am' ? 'Half day (AM)' : leave.half_type === 'pm' ? 'Half day (PM)' : 'Half day'
                          ) : (
                            formatDuration(leave)
                          )}
                        </td>
                        <td className={requestModuleTdClass}>
                          {(() => {
                            const urls = supportingDocUrls(leave)
                            if (!urls.length) return <span className="text-muted-foreground">No</span>
                            return (
                              <div className="flex flex-col gap-1">
                                {urls.map((url, i) => (
                                  <a
                                    key={`${url}-${i}`}
                                    href={profileImageUrl(url)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                  >
                                    <Paperclip className="size-3.5 shrink-0" aria-hidden />
                                    View{urls.length > 1 ? ` (${i + 1})` : ''}
                                  </a>
                                ))}
                              </div>
                            )
                          })()}
                        </td>
                        <td className={requestModuleTdClass}>
                          {remarksPreview ? (
                            <p className="line-clamp-2 text-[13px] leading-snug text-foreground/90" title={remarksPreview}>
                              {remarksPreview}
                            </p>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </td>
                        <td className={requestModuleTdClass}>
                          <LeaveStatusPill status={leave.status} displayStatus={leave.display_status} />
                        </td>
                        <td className={cn(requestModuleTdMutedClass, 'text-right')}>
                          {leave.created_at ? formatDate(leave.created_at) : '—'}
                        </td>
                        <td className={requestModuleActionsTdClass}>
                          <div className={requestModuleActionsWrapRowClass}>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className={cn(requestModuleCompactButtonClass, 'text-foreground hover:bg-brand/10 hover:text-brand')}
                              onClick={() => openDetailDialog(leave)}
                            >
                              <Eye className="size-3.5" />
                              View details
                            </Button>
                            {leave.actor_can_delete ? (
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className={cn(requestModuleCompactButtonClass, 'text-destructive hover:bg-destructive/10')}
                                onClick={() => setDeleteDialog({ open: true, leave })}
                              >
                                <Trash2 className="size-3.5" />
                                Delete
                              </Button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="flex-1 overflow-x-auto">
              <table className={leaveAdminTableClass}>
                <colgroup>
                  {canApproveLeave ? <col className="w-10" /> : null}
                  <col className="w-[12rem]" />
                  <col className="w-[8rem]" />
                  <col className="w-[11rem]" />
                  <col className="w-[7rem]" />
                  <col className="w-[11rem]" />
                  <col className="w-[14rem]" />
                  <col className="w-[14rem]" />
                  <col className="w-[9rem]" />
                  <col className="w-[15rem]" />
                </colgroup>
                <thead>
                  <tr className={requestModuleHeadRowClass}>
                    {canApproveLeave && (
                    <th className={cn(requestModuleThClass, 'w-10 px-3')}>
                      <Checkbox
                        checked={
                          bulkSelection.headerCheckboxIndeterminate
                            ? 'indeterminate'
                            : bulkSelection.headerCheckboxChecked
                        }
                        disabled={pageBulkRows.length === 0 || bulkApproving}
                        onCheckedChange={bulkSelection.togglePageSelectAll}
                        aria-label="Select all pending leave requests on this page"
                      />
                    </th>
                    )}
                    <th className={requestModuleThClass}>Employee</th>
                    <th className={requestModuleThClass}>Leave type</th>
                    <th className={requestModuleThClass}>Date / range</th>
                    <th className={requestModuleThClass}>Duration</th>
                    <th className={requestModuleThClass}>Supporting documents</th>
                    <th className={requestModuleThClass}>Reason / remarks</th>
                    <th className={requestModuleThClass}>Status</th>
                    <th className={requestModuleThRightClass}>Date filed</th>
                    <th className={requestModuleThRightClass}>Actions</th>
                  </tr>
                </thead>
                <tbody className="text-[13px]">
                  {leaveRequests.map((leave, idx) => {
                    const name = leave.employee_name || '—'
                    const initials =
                      name
                        .trim()
                        .split(/\s+/)
                        .map((n) => n[0])
                        .join('')
                        .toUpperCase()
                        .slice(0, 2) || '?'

                    const isPending = leave.status === 'pending'

                    const rowClassName = cn(
                      requestModuleRowClass(idx, 'group transition-all hover:bg-muted/20 dark:hover:bg-muted/40 dark:hover:shadow-[inset_3px_0_0_rgba(20,184,166,0.45)]'),
                      isPending && '!bg-amber-50/30 dark:!bg-amber-950/15',
                    )

                    const isUndertimeRow = leave.type === 'undertime'
                    const isHalfDayRow = leave.type === 'half_day'
                    const undertimeMinutes = typeof leave.undertime_minutes === 'number' ? leave.undertime_minutes : null
                    const remarksPreview = [leave.notes, leave.rejection_note].filter(Boolean).join('\n\n') || ''

                    return (
                      <tr key={leave.id} className={rowClassName}>
                      {canApproveLeave && (
                      <td className={cn(requestModuleTdClass, 'w-10 px-3')}>
                        <Checkbox
                          checked={bulkSelection.isRowSelected(leave)}
                          disabled={leave.status !== 'pending' || !leave.actor_can_approve || bulkApproving}
                          onCheckedChange={() => bulkSelection.toggleRow(leave)}
                          aria-label={`Select leave request #${leave.id}`}
                        />
                      </td>
                      )}
                      <td className={requestModuleTdClass}>
                        <div className="flex min-w-0 items-center gap-3">
                          <Avatar className="size-9 shrink-0 rounded-full">
                            <AvatarImage src={leave.employee_profile_image} alt="" className="object-cover" />
                            <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                              {initials}
                            </AvatarFallback>
                          </Avatar>
                          <span className="line-clamp-2 font-semibold text-[13px] leading-snug text-foreground" title={name}>
                            {name}
                          </span>
                        </div>
                      </td>
                      <td className={cn(requestModuleTdClass, 'text-muted-foreground')}>{formatType(leave.type)}</td>
                      <td className={cn(requestModuleTdClass, 'text-muted-foreground')}>
                        {formatDateRange(leave.start_date, leave.end_date)}
                      </td>
                      <td className={requestModuleTdMutedClass}>
                        {isUndertimeRow ? (
                          undertimeMinutes !== null ? (
                            <span className="flex flex-col gap-0.5">
                              <span className="font-medium text-foreground">
                                {undertimeMinutes} min
                              </span>
                              <span className="text-[11px] text-muted-foreground">
                                {(undertimeMinutes / 60).toFixed(2)} hours
                              </span>
                            </span>
                          ) : (
                            '—'
                          )
                        ) : isHalfDayRow ? (
                          <span className="text-xs text-muted-foreground">
                            {leave.half_type === 'am'
                              ? 'Half day (AM)'
                              : leave.half_type === 'pm'
                              ? 'Half day (PM)'
                              : 'Half day'}
                          </span>
                        ) : (
                          formatDuration(leave)
                        )}
                      </td>
                      <td className={requestModuleTdClass}>
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
                                  className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                >
                                  <Paperclip className="size-3.5 shrink-0" aria-hidden />
                                  View{urls.length > 1 ? ` (${i + 1})` : ''}
                                </a>
                              ))}
                            </div>
                          )
                        })()}
                      </td>
                      <td className={requestModuleTdClass}>
                        {remarksPreview ? (
                          <p className="line-clamp-2 text-[13px] leading-snug text-foreground/90" title={remarksPreview}>
                            {remarksPreview}
                          </p>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className={requestModuleTdClass}>
                        <LeaveStatusPill
                          status={leave.status}
                          displayStatus={leave.display_status}
                          hrWaitMessage={
                            leave.status === 'pending' && !leave.actor_can_approve
                              ? leave.hr_wait_message ||
                                'You are not the approver for this request at this stage.'
                              : null
                          }
                        />
                      </td>
                      <td className={cn(requestModuleTdMutedClass, 'text-right')}>
                        {leave.created_at ? formatDate(leave.created_at) : '—'}
                      </td>
                      <td className={requestModuleActionsTdClass}>
                        <div className={requestModuleActionsWrapRowClass}>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className={cn(requestModuleCompactButtonClass, 'border-border/80 bg-card hover:bg-brand/10 hover:text-brand')}
                            onClick={() => openDetailDialog(leave)}
                          >
                            <Eye className="size-3.5" aria-hidden />
                            View details
                          </Button>
                          {leave.actor_can_delete ? (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className={cn(requestModuleCompactButtonClass, 'text-destructive hover:bg-destructive/10')}
                              onClick={() => setDeleteDialog({ open: true, leave })}
                            >
                              <Trash2 className="size-3.5" aria-hidden />
                              Delete
                            </Button>
                          ) : null}
                          {canApproveLeave && leave.status === 'pending' && leave.actor_can_approve ? (
                            <>
                              <Button
                                variant="default"
                                size="sm"
                                className={cn(requestModuleCompactButtonClass, 'bg-emerald-600 text-white hover:bg-emerald-700')}
                                onClick={() => openApproveDialog(leave)}
                                disabled={actionLoadingId === leave.id}
                              >
                                {actionLoadingId === leave.id ? (
                                  <Loader2 className="size-3.5 animate-spin" />
                                ) : (
                                  <CheckCircle2 className="size-3.5" />
                                )}
                                Approve
                              </Button>
                              <Button
                                variant="outline"
                                size="sm"
                                className={cn(requestModuleCompactButtonClass, 'border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300')}
                                onClick={() => openRejectDialog(leave)}
                                disabled={actionLoadingId === leave.id || leave.actor_can_reject === false}
                              >
                                <XCircle className="size-3.5" />
                                Reject
                              </Button>
                            </>
                          ) : null}
                          {canLeaveNotes ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              className={requestModuleCompactButtonClass}
                              onClick={() => openNotesDialog(leave)}
                            >
                              <FileText className="size-3.5" />
                              {leave.notes ? 'Edit note' : 'Add note'}
                            </Button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          {activePagination && activePagination.last_page > 1 ? (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/50 px-4 py-3 text-sm text-muted-foreground @sm:px-6">
              <span>
                Page {activePagination.current_page} of {activePagination.last_page} · {activePagination.total} total
              </span>
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={activeLoading || activePagination.current_page <= 1}
                  onClick={() => (isMineTab ? setMinePage((p) => Math.max(1, p - 1)) : setAllPage((p) => Math.max(1, p - 1)))}
                >
                  Previous
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={activeLoading || activePagination.current_page >= activePagination.last_page}
                  onClick={() => (isMineTab ? setMinePage((p) => p + 1) : setAllPage((p) => p + 1))}
                >
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <BulkApproveConfirmDialog
        open={bulkConfirmOpen}
        onOpenChange={setBulkConfirmOpen}
        selectedCount={bulkSelection.effectiveSelectedCount}
        selectAllMatching={bulkSelection.selectAllMatching}
        remarks={bulkApproveRemarks}
        onConfirm={handleBulkApprove}
        loading={bulkApproving}
        entityLabel="leave requests"
      />

      <BulkApprovalSummaryDialog
        open={bulkSummaryOpen}
        onOpenChange={setBulkSummaryOpen}
        title="Bulk leave approval"
        summary={bulkSummary}
      />

      {/* Approve with optional notes */}
      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass()}
          aria-describedby="leave-approve-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Approve leave request</DialogTitle>
              <p id="leave-approve-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Review supporting documents if any, and add an optional remark before approving.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleConfirmApprove} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            {approveLeave && (
              <div className="space-y-1 text-xs text-muted-foreground">
                <div>
                  <span className="font-medium text-foreground">Employee: </span>
                  {approveLeave.employee_name}
                </div>
                <div>
                  <span className="font-medium text-foreground">Type: </span>
                  {formatType(approveLeave.type)}
                </div>
                <div>
                  <span className="font-medium text-foreground">Dates: </span>
                  {formatDateRange(approveLeave.start_date, approveLeave.end_date)}
                </div>
                <div>
                  <span className="font-medium text-foreground">Supporting documents: </span>
                  {supportingDocUrls(approveLeave).length ? (
                    <span className="inline-flex flex-wrap gap-x-2 gap-y-1">
                      {supportingDocUrls(approveLeave).map((url, i) => (
                        <a
                          key={`${url}-${i}`}
                          href={profileImageUrl(url)}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                        >
                          View{supportingDocUrls(approveLeave).length > 1 ? ` (${i + 1})` : ''}
                        </a>
                      ))}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">None attached</span>
                  )}
                </div>
              </div>
            )}
            <div className="space-y-2">
              <Label htmlFor="approve-notes">Admin remark (optional)</Label>
              <textarea
                id="approve-notes"
                value={approveNotes}
                onChange={(e) => setApproveNotes(e.target.value)}
                rows={3}
                className={FIELD_TEXTAREA_CLASS}
              />
              <p className="text-[11px] text-muted-foreground">
                This remark will be visible to the employee on their leave history.
              </p>
            </div>
            {user?.is_super_admin ? (
              <div className="flex items-start gap-2 rounded-lg border border-amber-200/80 bg-amber-50/50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
                <Checkbox
                  id="approve-force-credits"
                  checked={approveForceInsufficientCredits}
                  onCheckedChange={(c) => setApproveForceInsufficientCredits(c === true)}
                  className="mt-0.5"
                />
                <Label htmlFor="approve-force-credits" className="cursor-pointer text-xs font-normal leading-snug text-amber-950 dark:text-amber-100">
                  Super admin: approve final step even if deducting credits would exceed the employee&apos;s remaining
                  balance (balance will not go below zero).
                </Label>
              </div>
            ) : null}
            {approveRangeValidating ? (
              <p className="flex items-center gap-2 text-[11px] text-muted-foreground">
                <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
                Checking this request against the employee&apos;s work schedule…
              </p>
            ) : null}
            {approveRangeSummary && !approveRangeSummary.valid ? (
              <div className="rounded-lg border border-amber-200/80 bg-amber-50/60 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/25">
                <p className="flex items-start gap-2 font-medium text-amber-950 dark:text-amber-100">
                  <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                  <span>
                    {approveRangeSummary.message ||
                      'This range includes a scheduled rest day. Non-HR approvers cannot approve it.'}
                  </span>
                </p>
                {isAdminHr ? (
                  <div className="mt-3 space-y-2 border-t border-amber-200/60 pt-3 dark:border-amber-900/40">
                    <div className="flex items-start gap-2">
                      <Checkbox
                        id="approve-bypass-rest-days"
                        checked={approveBypassRestDays}
                        onCheckedChange={(c) => setApproveBypassRestDays(c === true)}
                        className="mt-0.5"
                      />
                      <Label
                        htmlFor="approve-bypass-rest-days"
                        className="cursor-pointer text-xs font-normal leading-snug text-amber-950 dark:text-amber-100"
                      >
                        HR admin: approve anyway with a documented reason (audited on the request).
                      </Label>
                    </div>
                    {approveBypassRestDays ? (
                      <div className="space-y-1">
                        <Label htmlFor="approve-rest-day-bypass-reason" className="text-[11px]">
                          Override reason (min. {REST_DAY_BYPASS_REASON_MIN} characters)
                        </Label>
                        <textarea
                          id="approve-rest-day-bypass-reason"
                          value={approveRestDayBypassReason}
                          onChange={(e) => setApproveRestDayBypassReason(e.target.value)}
                          rows={3}
                          className={FIELD_TEXTAREA_CLASS_SM}
                        />
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </div>
            ) : null}
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setApproveOpen(false)}
                disabled={approveSubmitting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                size="sm"
                className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
                disabled={approveSubmitting || approveFormRestDayBlocksSubmit}
              >
                {approveSubmitting && <Loader2 className="mr-2 size-3.5 animate-spin" />}
                Confirm approve
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* File new leave (HR may pick employee; managers file for self only) */}
      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-7 top-7 size-14 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[92vh] max-w-[min(94vw,68rem)] rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
          aria-describedby="leave-add-desc"
        >
          <div className="min-h-0 flex-1 overflow-y-auto">
            <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-8 pb-6 pt-8 text-left dark:to-brand/10 @md:px-12">
              <AgcBrandLogo className="mb-7 h-9 @md:h-10" />
              <div className="relative z-10 max-w-[43rem] space-y-3 pr-14 @md:pr-0">
                <DialogTitle className="text-2xl font-bold tracking-tight text-foreground @md:text-3xl">
                  File new leave
                </DialogTitle>
                <DialogDescription id="leave-add-desc" className="max-w-[42rem] text-base leading-relaxed text-muted-foreground @md:text-lg">
                  {showEmployeePicker
                    ? 'Select an employee in your scope, then choose dates and leave type.'
                    : 'Choose your leave type and dates. This request is for your own leave only.'}{' '}
                  The earliest start date is tomorrow. Dates with complete attendance and overlapping pending or approved leave cannot be used.
                </DialogDescription>
              </div>
              <LeaveModalCalendarArt />
            </DialogHeader>

            <div className="px-8 py-7 @md:px-12">
              <form id="admin-leave-file-form" className="space-y-6" onSubmit={handleAddLeave}>
                {!showEmployeePicker && (
                  <div className="rounded-xl border border-brand/25 bg-brand/[0.045] px-5 py-4 shadow-sm dark:border-brand/25 dark:bg-brand/10">
                    <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand">Filing leave as</p>
                    <p className="mt-1.5 text-base font-semibold leading-snug text-foreground">
                      {user?.name ?? '?'}
                      {user?.hr_role_label ? <span className="font-normal text-muted-foreground"> ? {user.hr_role_label}</span> : null}
                    </p>
                  </div>
                )}

                {showEmployeePicker ? (
                  <div className="space-y-3">
                    <Label htmlFor="add-employee" className={adminLeaveModalLabelClass}>Employee</Label>
                    <select
                      id="add-employee"
                      required
                      value={addForm.user_id}
                      onChange={(e) => setAddForm((f) => ({ ...f, user_id: e.target.value }))}
                      className={adminLeaveModalSelectClass}
                    >
                      <option value="">Select employee</option>
                      {employees.filter((e) => isRosterStaffMember(e)).map((emp) => (
                        <option key={emp.id} value={emp.id}>{emp.name}</option>
                      ))}
                    </select>
                  </div>
                ) : null}

                <div className="space-y-3">
                  <Label htmlFor="add-type" className={adminLeaveModalLabelClass}>Leave type</Label>
                  <select
                    id="add-type"
                    value={addForm.type}
                    onChange={(e) => setAddForm((f) => ({ ...f, type: e.target.value }))}
                    className={adminLeaveModalSelectClass}
                  >
                    {LEAVE_TYPES.map((t) => (
                      <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                  </select>
                </div>

                {addForm.type === 'half_day' ? (
                  <div className="space-y-3">
                    <Label htmlFor="add-half-type" className={adminLeaveModalLabelClass}>Half day type</Label>
                    <select
                      id="add-half-type"
                      value={addForm.half_type}
                      onChange={(e) => setAddForm((f) => ({ ...f, half_type: e.target.value }))}
                      className={cn(adminLeaveModalFieldClass, 'w-full')}
                      required
                    >
                      <option value="">Select option</option>
                      <option value="am">AM Half Day (work morning, leave afternoon)</option>
                      <option value="pm">PM Half Day (leave morning, work afternoon)</option>
                    </select>
                  </div>
                ) : null}

                <div className="space-y-3">
                  <Label className={adminLeaveModalLabelClass}>Date range</Label>
                  <div className="grid grid-cols-1 gap-4 @md:grid-cols-2">
                    <div className="relative">
                      <span className="pointer-events-none absolute left-4 top-2 text-sm font-medium text-muted-foreground">From</span>
                      <Input
                        id="add-start"
                        type="date"
                        required
                        min={minLeaveDate}
                        value={addForm.start_date}
                        onChange={(e) => setAddForm((f) => ({ ...f, start_date: e.target.value }))}
                        className={cn(adminLeaveModalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7 [color-scheme:light] dark:[color-scheme:dark]')}
                      />
                    </div>
                    <div className="relative">
                      <span className="pointer-events-none absolute left-4 top-2 text-sm font-medium text-muted-foreground">To</span>
                      <Input
                        id="add-end"
                        type="date"
                        required
                        value={addForm.end_date}
                        min={minEndDate}
                        onChange={(e) => setAddForm((f) => ({ ...f, end_date: e.target.value }))}
                        className={cn(adminLeaveModalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7 [color-scheme:light] dark:[color-scheme:dark]')}
                      />
                    </div>
                  </div>
                </div>

                {addRangeValidating && addSubjectUid && addForm.start_date && addRangeEndYmd ? (
                  <p className="mt-1.5 flex items-center gap-2 text-[12px] text-muted-foreground">
                    <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
                    Checking dates against the employee&apos;s work schedule...
                  </p>
                ) : null}
                {addRangeRestDay && !addRangeRestDay.valid ? (
                  <div className="rounded-xl border border-amber-200/80 bg-amber-50/60 px-4 py-3 text-sm dark:border-amber-900/50 dark:bg-amber-950/25">
                    <p className="flex items-start gap-2 font-medium text-amber-950 dark:text-amber-100">
                      <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                      <span>{addRangeRestDay.message}</span>
                    </p>
                    {isAdminHr ? (
                      <div className="mt-3 space-y-3 border-t border-amber-200/60 pt-3 dark:border-amber-900/40">
                        <div className="flex items-start gap-2">
                          <Checkbox
                            id="add-bypass-rest-days"
                            checked={addBypassRestDays}
                            onCheckedChange={(c) => setAddBypassRestDays(c === true)}
                            className="mt-0.5"
                          />
                          <Label htmlFor="add-bypass-rest-days" className="cursor-pointer text-sm font-normal leading-snug text-amber-950 dark:text-amber-100">
                            HR admin: file on rest days with a documented reason.
                          </Label>
                        </div>
                        {addBypassRestDays ? (
                          <div className="space-y-2">
                            <Label htmlFor="add-rest-day-bypass-reason" className="text-xs font-semibold">
                              Override reason (min. {REST_DAY_BYPASS_REASON_MIN} characters)
                            </Label>
                            <textarea
                              id="add-rest-day-bypass-reason"
                              value={addRestDayBypassReason}
                              onChange={(e) => setAddRestDayBypassReason(e.target.value)}
                              rows={3}
                              className="min-h-24 w-full resize-none rounded-xl border border-border/80 bg-background px-4 py-3 text-sm shadow-sm focus-visible:border-brand focus-visible:ring-brand/25 dark:border-white/12 dark:bg-background/40"
                            />
                          </div>
                        ) : null}
                      </div>
                    ) : null}
                  </div>
                ) : null}
                {addRangeRestDay?.valid && addRangeRestDay?.using_default_schedule && addRangeRestDay?.schedule_warning ? (
                  <p className="mt-1.5 flex items-start gap-2 text-[12px] leading-snug text-muted-foreground">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-brand" aria-hidden />
                    <span>{addRangeRestDay.schedule_warning}</span>
                  </p>
                ) : null}

                <div className="space-y-3">
                  <Label htmlFor="add-notes" className={adminLeaveModalLabelClass}>
                    Reason / remarks <span className="font-normal text-muted-foreground">(optional)</span>
                  </Label>
                  <div className="relative">
                    <textarea
                      id="add-notes"
                      value={addForm.notes}
                      onChange={(e) => setAddForm((f) => ({ ...f, notes: e.target.value }))}
                      rows={4}
                      maxLength={500}
                      placeholder="Optional context for approvers..."
                      className="min-h-28 w-full resize-none rounded-xl border border-border/80 bg-background px-4 pb-9 pt-4 text-base shadow-sm focus-visible:border-brand focus-visible:ring-brand/25 dark:border-white/12 dark:bg-background/40"
                    />
                    <span className="pointer-events-none absolute bottom-4 right-4 text-sm tabular-nums text-muted-foreground">
                      {addForm.notes.length} / 500
                    </span>
                  </div>
                  <p className={adminLeaveModalHintClass}>This will be saved as the admin note on the request.</p>
                </div>

                {user?.is_super_admin ? (
                  <div className="flex items-start gap-3 rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                    <Checkbox
                      id="add-bypass-credits"
                      checked={addBypassLeaveCredits}
                      onCheckedChange={(c) => setAddBypassLeaveCredits(c === true)}
                      className="mt-0.5"
                    />
                    <Label htmlFor="add-bypass-credits" className="cursor-pointer text-sm font-normal leading-snug text-amber-950 dark:text-amber-100">
                      Super admin: file this request even if the employee has insufficient leave credits.
                    </Label>
                  </div>
                ) : null}

                <div className="space-y-3">
                  <Label className={adminLeaveModalLabelClass}>Supporting documents (optional)</Label>
                  <div className="rounded-xl border border-dashed border-border bg-muted/15 px-5 py-6 dark:border-white/15 dark:bg-white/[0.03]">
                    <div className="flex flex-col items-center justify-center gap-3 text-center">
                      <label className="flex w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg px-4 py-2 text-muted-foreground transition hover:text-foreground">
                        <UploadCloud className="size-9 text-foreground" strokeWidth={1.7} aria-hidden />
                        <span className="text-base font-medium text-muted-foreground">Drag and drop files here or click to upload</span>
                        <span className={adminLeaveModalHintClass}>
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
                    {(addForm.supportingFiles ?? []).length > 0 ? (
                      <ul className="mt-3 space-y-2">
                        {(addForm.supportingFiles ?? []).map((f, i) => (
                          <li key={`${f.name}-${i}-${f.size}`} className="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm">
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
              disabled={addSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              form="admin-leave-file-form"
              className="h-14 min-w-72 gap-4 rounded-xl bg-brand px-9 text-lg font-semibold text-brand-foreground shadow-[0_14px_28px_-18px_rgba(234,88,12,0.95)] hover:bg-brand-strong dark:shadow-[0_14px_30px_-20px_rgba(251,146,60,0.8)]"
              disabled={
                addSubmitting ||
                addFormRestDayBlocksSubmit ||
                (showEmployeePicker && !addForm.user_id) ||
                (!showEmployeePicker && !user?.id) ||
                !addForm.start_date ||
                !addForm.end_date
              }
            >
              {addSubmitting && <Loader2 className="size-4 animate-spin" />}
              Submit request
              <ArrowRight className="size-5" aria-hidden />
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reject leave with remarks */}
      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-7 top-7 size-14 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[92vh] max-w-[min(94vw,42rem)] rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
          aria-describedby="leave-reject-desc"
        >
          <form onSubmit={handleConfirmReject} className="flex min-h-0 flex-1 flex-col">
            <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-destructive/5 px-8 pb-6 pt-8 text-left dark:to-destructive/10 @md:px-10">
              <div className="relative z-10 max-w-[34rem] space-y-3 pr-14 @md:pr-0">
                <p className="text-[11px] font-black uppercase tracking-[0.22em] text-destructive">Leave request</p>
                <DialogTitle className="flex items-center gap-3 text-2xl font-bold tracking-tight text-foreground @md:text-3xl">
                  <span className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-destructive/25 bg-destructive/10 text-destructive">
                    <XCircle className="size-5" aria-hidden />
                  </span>
                  Reject leave request
                </DialogTitle>
                <DialogDescription id="leave-reject-desc" className="max-w-[34rem] text-base leading-relaxed text-muted-foreground">
                  {rejectLeave ? (
                    <>
                      Provide a clear remark for <span className="font-semibold text-foreground">{rejectLeave.employee_name}</span> ({formatType(rejectLeave.type)}, {formatDateRange(rejectLeave.start_date, rejectLeave.end_date)}).
                    </>
                  ) : (
                    'Provide a clear remark for this leave request.'
                  )}
                </DialogDescription>
              </div>
            </DialogHeader>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-8 py-7 @md:px-10">
              {rejectLeave ? (
                <div className="rounded-xl border border-border/70 bg-background/70 px-5 py-4 shadow-sm dark:border-white/10 dark:bg-background/35">
                  <dl className="grid grid-cols-[minmax(0,8rem)_1fr] gap-x-4 gap-y-3 text-sm">
                    <dt className="text-muted-foreground">Employee</dt>
                    <dd className="font-bold text-foreground">{rejectLeave.employee_name || '?'}</dd>
                    <dt className="text-muted-foreground">Leave type</dt>
                    <dd className="font-bold text-foreground">{formatType(rejectLeave.type)}</dd>
                    <dt className="text-muted-foreground">Date range</dt>
                    <dd className="font-medium tabular-nums text-foreground">{formatDateRange(rejectLeave.start_date, rejectLeave.end_date)}</dd>
                  </dl>
                </div>
              ) : null}

              <div className="space-y-3">
                <Label htmlFor="reject-reason" className={adminLeaveModalLabelClass}>Remarks</Label>
                <div className="relative">
                  <textarea
                    id="reject-reason"
                    value={rejectReason}
                    onChange={(e) => setRejectReason(e.target.value)}
                    placeholder="e.g. Overlapping with approved leave, insufficient balance, incomplete supporting details..."
                    rows={5}
                    maxLength={500}
                    className="min-h-36 w-full resize-none rounded-xl border border-border/80 bg-background px-4 pb-9 pt-4 text-base shadow-sm focus-visible:border-destructive focus-visible:ring-destructive/25 dark:border-white/12 dark:bg-background/40"
                  />
                  <span className="pointer-events-none absolute bottom-4 right-4 text-sm tabular-nums text-muted-foreground">
                    {rejectReason.length} / 500
                  </span>
                </div>
                <p className={adminLeaveModalHintClass}>This remark will be visible in the approval history.</p>
              </div>
            </div>

            <DialogFooter className="shrink-0 border-t border-border/70 bg-card px-8 py-5 @md:px-10">
              <Button
                type="button"
                variant="outline"
                className="h-14 min-w-36 rounded-xl border-border/80 bg-card px-8 text-lg font-semibold text-foreground hover:bg-muted dark:border-white/10"
                onClick={() => setRejectOpen(false)}
                disabled={rejectSubmitting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                className="h-14 min-w-56 gap-3 rounded-xl bg-destructive px-8 text-lg font-semibold text-destructive-foreground shadow-[0_14px_28px_-18px_rgba(220,38,38,0.95)] hover:bg-destructive/90"
                disabled={rejectSubmitting || !rejectReason.trim()}
              >
                {rejectSubmitting && <Loader2 className="size-4 animate-spin" />}
                Confirm reject
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Add / edit notes */}
      <Dialog open={notesOpen} onOpenChange={setNotesOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass()}
          aria-describedby="leave-notes-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <FileText className="size-5 text-primary" />
                {notesLeave?.notes ? 'Edit note' : 'Add note'}
              </DialogTitle>
              <p id="leave-notes-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {notesLeave && (
                  <>
                    Add an internal note for{' '}
                    <span className="font-medium">{notesLeave.employee_name}</span> (
                    {formatType(notesLeave.type)},{' '}
                    {formatDateRange(notesLeave.start_date, notesLeave.end_date)}).
                  </>
                )}
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleSaveNotes} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label htmlFor="notes-text">Note</Label>
              <textarea
                id="notes-text"
                value={notesValue}
                onChange={(e) => setNotesValue(e.target.value)}
                rows={3}
                className={FIELD_TEXTAREA_CLASS_SM}
                placeholder="e.g. Approved despite overlap; manager confirmed coverage."
              />
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button
                type="button"
                variant="outline"
                onClick={() => setNotesOpen(false)}
                disabled={notesSubmitting}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={notesSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {notesSubmitting && <Loader2 className="size-4 animate-spin mr-1.5" />}
                Save note
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <LeaveRequestDetailModal
        open={detailOpen}
        onOpenChange={(open) => {
          setDetailOpen(open)
          if (!open) {
            setDetailLeave(null)
            setDetailLoading(false)
          }
        }}
        leave={detailLeave}
        showEmployeeName
        resolveDocUrl={profileImageUrl}
        loading={detailLoading}
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
            <Button type="button" variant="destructive" onClick={handleDelete} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
