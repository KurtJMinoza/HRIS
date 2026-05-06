import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import {
  Loader2,
  CheckCircle2,
  XCircle,
  Plus,
  FileText,
  TrendingUp,
  Calendar,
  Clock,
  AlertTriangle,
  Paperclip,
  Eye,
  Trash2,
  X,
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
  createLeaveRequest,
  approveLeaveRequest,
  rejectLeaveRequest,
  getEmployees,
  updateLeaveNotes,
  uploadAdminLeaveDocument,
  deleteAdminLeaveRequest,
  profileImageUrl,
  validateAdminLeaveDateRange,
} from '@/api'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import {
  FIELD_SELECT_CLASS_H10,
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
import { earliestLeaveStartYmd } from '@/lib/attendanceDates'

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

function supportingDocUrls(leave) {
  if (!leave) return []
  if (Array.isArray(leave.document_urls) && leave.document_urls.length) return leave.document_urls
  if (leave.document_url) return [leave.document_url]
  return []
}

export default function AdminLeave() {
  const { toast } = useToast()
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

  const fetchLeaves = useCallback(async () => {
    setError(null)
    try {
      const data = await getLeaveRequests(statusFilter || undefined)
      setLeaveRequests(data.leave_requests || [])
    } catch (e) {
      setError(e.message)
      setLeaveRequests([])
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => {
    setLoading(true)
    fetchLeaves()
  }, [fetchLeaves])

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
      await fetchLeaves()
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
    setDetailLeave(leave)
    setDetailOpen(true)
  }

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
      toast({ title: data.message || 'Leave approved', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to approve leave', description: e.message, variant: 'error' })
    } finally {
      setApproveSubmitting(false)
      setActionLoadingId(null)
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
      await deleteAdminLeaveRequest(deleteDialog.leave.id)
      toast({ title: 'Leave deleted', variant: 'success' })
      setDeleteDialog({ open: false, leave: null })
      await fetchLeaves()
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

  const totalCount = leaveRequests.length
  const pendingCount = leaveRequests.filter((l) => l.status === 'pending').length
  const approvedCount = leaveRequests.filter((l) => l.status === 'approved').length
  const rejectedCount = leaveRequests.filter((l) => l.status === 'rejected').length

  const statusCounts = {
    '': totalCount,
    pending: pendingCount,
    approved: approvedCount,
    rejected: rejectedCount,
  }

  const today = new Date()
  const currentMonth = today.getMonth()
  const currentYear = today.getFullYear()
  const monthlyLeaves = leaveRequests.filter((leave) => {
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
      <div className="flex w-full flex-col gap-4 @sm:flex-row @sm:items-start @sm:justify-between">
        <div className="min-w-0 flex-1">
          <h2 className="text-2xl font-bold tracking-tight @md:text-3xl">Leave Management</h2>
          <CardDescription className="text-sm @md:text-[15px]">
            {canApproveLeave || canLeaveNotes
              ? 'View and manage employee leave requests in your scope. Approval, rejection, and notes require the matching permissions.'
              : 'View employee leave requests in your scope (read-only).'}
          </CardDescription>
          {totalCount > 0 && (
            <p className="mt-1 text-xs text-muted-foreground">
              {pendingCount > 0
                ? `${pendingCount} pending, ${approvedCount} approved, ${rejectedCount} rejected.`
                : `No pending leave. ${approvedCount} approved, ${rejectedCount} rejected.`}
            </p>
          )}
        </div>
        {canApproveLeave && (
          <Button
            className="w-full shrink-0 @sm:w-auto"
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
            <Plus className="size-4 mr-2" />
            File new leave
          </Button>
        )}
      </div>

      {totalCount > 0 && (
        <div className="grid w-full gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
          {/* Total */}
          <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card overflow-hidden">
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
          <Card className={`border shadow-md bg-card overflow-hidden transition-all ${monthlyPending > 0 ? 'border-amber-400/60 dark:border-amber-500/40 shadow-[0_0_18px_rgba(245,158,11,0.12)]' : 'border-border/60 dark:border-white/8'}`}>
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
          <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card overflow-hidden">
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
          <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card overflow-hidden">
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

      {pendingCount > 0 && (
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

      {error && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <Card className="w-full min-w-0 border border-border/60 shadow-md dark:border-white/8 bg-card">
        <CardHeader className="flex flex-col gap-4 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
          <div className="min-w-0">
            <CardTitle className="text-lg font-semibold @md:text-xl">Leave requests</CardTitle>
            <CardDescription className="text-sm @md:text-[15px]">Filter by status</CardDescription>
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
          {loading ? (
            <div className="min-h-[min(42vh,400px)] overflow-x-auto px-2 @sm:px-0">
              <table className="w-full min-w-[min(100%,720px)] text-sm">
                <tbody>
                  <TableBodySkeleton rows={6} cols={10} />
                </tbody>
              </table>
            </div>
          ) : leaveRequests.length === 0 ? (
            <div className="flex min-h-[min(58vh,620px)] flex-col items-center justify-center gap-3 px-6 py-16 text-center @md:py-24">
              <div className="flex size-14 items-center justify-center rounded-2xl border border-dashed border-border/60 bg-muted/30 dark:border-white/10">
                <FileText className="size-7 text-muted-foreground/50" aria-hidden />
              </div>
              <p className="max-w-md text-base font-medium text-foreground">
                {statusFilter ? `No ${statusFilter} leave requests.` : 'No leave requests yet.'}
              </p>
              <p className="max-w-md text-sm text-muted-foreground">
                When employees submit leave, they will appear here for review.
              </p>
            </div>
          ) : (
            <div className="flex-1 overflow-x-auto">
              <table className="w-full min-w-[min(100%,820px)] text-sm @md:text-[15px]">
                <thead>
                  <tr className="border-b border-border/40 bg-muted/30 dark:bg-card">
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Employee
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Leave type
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Date / range
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Duration
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Supporting documents
                    </th>
                    <th className="min-w-[10rem] text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Reason / remarks
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Status
                    </th>
                    <th className="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Date filed
                    </th>
                    <th className="text-right px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400">
                      Details
                    </th>
                    {(canApproveLeave || canLeaveNotes) && (
                    <th className="text-right px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-400 min-w-[12rem]">
                      Actions
                    </th>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/40 dark:divide-white/5 text-[13px]">
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

                    const rowClassName = [
                      'group transition-all',
                      'hover:bg-muted/20 dark:hover:bg-muted/40 dark:hover:shadow-[inset_3px_0_0_rgba(20,184,166,0.45)]',
                      isPending
                        ? 'bg-amber-50/30 dark:bg-amber-950/15'
                        : idx % 2 === 0
                          ? 'bg-white dark:bg-card'
                          : 'bg-[#f8fafc] dark:bg-muted/25',
                    ]
                      .filter(Boolean)
                      .join(' ')

                    const isUndertimeRow = leave.type === 'undertime'
                    const isHalfDayRow = leave.type === 'half_day'
                    const undertimeMinutes = typeof leave.undertime_minutes === 'number' ? leave.undertime_minutes : null
                    const remarksPreview = [leave.notes, leave.rejection_note].filter(Boolean).join('\n\n') || ''

                    return (
                      <tr key={leave.id} className={rowClassName}>
                      <td className="px-4 py-4">
                        <div className="flex items-center gap-3">
                          <Avatar className="size-10 shrink-0 rounded-full">
                            <AvatarImage src={leave.employee_profile_image} alt="" className="object-cover" />
                            <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                              {initials}
                            </AvatarFallback>
                          </Avatar>
                          <span className="font-bold text-[13.5px] text-foreground">{name}</span>
                        </div>
                      </td>
                      <td className="px-4 py-4 text-sm text-muted-foreground">{formatType(leave.type)}</td>
                      <td className="px-4 py-4 text-sm text-muted-foreground">
                        {formatDateRange(leave.start_date, leave.end_date)}
                      </td>
                      <td className="px-4 py-4 text-muted-foreground">
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
                      <td className="px-4 py-4 text-sm">
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
                      <td className="max-w-[14rem] px-4 py-4 align-top text-sm text-muted-foreground">
                        {remarksPreview ? (
                          <p className="line-clamp-3 whitespace-pre-wrap break-words text-[13px] leading-snug text-foreground/90">
                            {remarksPreview}
                          </p>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-4 py-4">
                        {leave.status === 'pending' && (
                          <div className="space-y-1.5">
                            <span className="inline-flex max-w-[14rem] items-center gap-1 rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-semibold text-white shadow-sm">
                              <Clock className="size-3 shrink-0" />
                              <span className="line-clamp-2 leading-tight">{leave.display_status || 'Pending'}</span>
                            </span>
                          </div>
                        )}
                        {leave.status === 'approved' && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-0.5 text-xs font-semibold text-white shadow-sm">
                            <CheckCircle2 className="size-3" />
                            Approved
                          </span>
                        )}
                        {leave.status === 'rejected' && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-rose-600 px-2.5 py-0.5 text-xs font-semibold text-white shadow-sm">
                            <XCircle className="size-3" />
                            Rejected
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-4 align-top text-sm tabular-nums text-muted-foreground">
                        {leave.created_at ? formatDate(leave.created_at) : '—'}
                      </td>
                      <td className="px-4 py-4 text-right align-middle">
                        <div className="flex flex-wrap justify-end gap-2">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-9 gap-1.5 rounded-lg px-3 text-xs font-semibold text-foreground hover:bg-slate-100 dark:hover:bg-slate-800"
                            onClick={() => openDetailDialog(leave)}
                          >
                            <Eye className="size-3.5" aria-hidden />
                            Details
                          </Button>
                          {leave.actor_can_delete ? (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-9 gap-1.5 rounded-lg px-3 text-xs font-semibold text-destructive hover:bg-destructive/10"
                              onClick={() => setDeleteDialog({ open: true, leave })}
                            >
                              <Trash2 className="size-3.5" aria-hidden />
                              Delete
                            </Button>
                          ) : null}
                        </div>
                      </td>
                      {(canApproveLeave || canLeaveNotes) && (
                      <td className="px-4 py-4 text-right align-middle">
                        <div className="flex flex-col items-end gap-2 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-end">
                          {canApproveLeave && leave.status === 'pending' && (
                            leave.actor_can_approve ? (
                            <>
                              <Button
                                variant="default"
                                size="sm"
                                className="h-9 min-w-[5.5rem] gap-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white border border-emerald-500/60 shadow-sm"
                                onClick={() => openApproveDialog(leave)}
                                disabled={actionLoadingId === leave.id}
                              >
                                {actionLoadingId === leave.id ? (
                                  <Loader2 className="size-3.5 animate-spin" />
                                ) : (
                                  <CheckCircle2 className="size-3.5" />
                                )}
                                <span>Approve</span>
                              </Button>
                              <Button
                                variant="outline"
                                size="sm"
                                className="h-9 min-w-[5.5rem] gap-1.5 text-xs font-semibold border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-950/40"
                                onClick={() => openRejectDialog(leave)}
                                disabled={actionLoadingId === leave.id || leave.actor_can_reject === false}
                              >
                                <XCircle className="size-3.5" />
                                <span>Reject</span>
                              </Button>
                            </>
                            ) : (
                              <p className="max-w-[14rem] text-right text-[11px] leading-snug text-muted-foreground">
                                {leave.hr_wait_message || 'You are not the approver for this request at this stage.'}
                              </p>
                            )
                          )}
                          {canLeaveNotes && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 gap-1 text-xs"
                                onClick={() => openNotesDialog(leave)}
                          >
                            <FileText className="size-3.5" />
                            <span>{leave.notes ? 'Edit note' : 'Add note'}</span>
                          </Button>
                          )}
                        </div>
                      </td>
                      )}
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

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
          className={adminFormDialogContentClass()}
          aria-describedby="leave-add-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <Calendar className="size-5 text-black dark:text-white" />
                File new leave
              </DialogTitle>
              <p id="leave-add-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {showEmployeePicker
                  ? 'Select an employee in your scope, then choose dates and leave type.'
                  : 'Complete the fields below. This request is for your own leave only.'}
              </p>
              <p className="mt-2 text-[11px] leading-snug text-muted-foreground">
                Applies to every role (employee, department head, branch head, company head, HR): earliest start is
                tomorrow. Dates where the leave subject already has complete attendance (clock-in and clock-out),
                including from approved corrections, cannot be used. New requests cannot overlap an existing pending or
                approved leave for the same person.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleAddLeave} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            {!showEmployeePicker && (
              <div className="rounded-lg border border-border/50 bg-muted/40 px-4 py-3 text-foreground/90 dark:bg-white/5">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  Filing leave as
                </p>
                <p className="mt-1.5 text-sm font-semibold leading-snug text-foreground">
                  {user?.name ?? '—'}
                  {user?.hr_role_label ? (
                    <span className="font-normal text-muted-foreground"> — {user.hr_role_label}</span>
                  ) : null}
                </p>
              </div>
            )}
            {showEmployeePicker ? (
            <div className="space-y-2">
              <Label htmlFor="add-employee">Employee</Label>
              <select
                id="add-employee"
                required
                value={addForm.user_id}
                onChange={(e) => setAddForm((f) => ({ ...f, user_id: e.target.value }))}
                className={FIELD_SELECT_CLASS_H10}
              >
                <option value="">Select employee…</option>
                {employees.filter((e) => isRosterStaffMember(e)).map((emp) => (
                  <option key={emp.id} value={emp.id}>{emp.name}</option>
                ))}
              </select>
            </div>
            ) : null}
            <div className="space-y-2">
              <Label htmlFor="add-type">Leave type</Label>
              <select
                id="add-type"
                value={addForm.type}
                onChange={(e) => setAddForm((f) => ({ ...f, type: e.target.value }))}
                className={FIELD_SELECT_CLASS_H10}
              >
                {LEAVE_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>
            {addForm.type === 'half_day' && (
              <div className="space-y-2">
                <Label htmlFor="add-half-type">Half day type</Label>
                <select
                  id="add-half-type"
                  value={addForm.half_type}
                  onChange={(e) => setAddForm((f) => ({ ...f, half_type: e.target.value }))}
                  className={FIELD_SELECT_CLASS_H10}
                  required
                >
                  <option value="">Select option</option>
                  <option value="am">AM Half Day (work morning, leave afternoon)</option>
                  <option value="pm">PM Half Day (leave morning, work afternoon)</option>
                </select>
              </div>
            )}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="add-start">Start date</Label>
                <Input
                  id="add-start"
                  type="date"
                  required
                  min={minLeaveDate}
                  value={addForm.start_date}
                  onChange={(e) => setAddForm((f) => ({ ...f, start_date: e.target.value }))}
                  className="dark:[color-scheme:dark]"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="add-end">End date</Label>
                <Input
                  id="add-end"
                  type="date"
                  required
                  value={addForm.end_date}
                  min={minEndDate}
                  onChange={(e) => setAddForm((f) => ({ ...f, end_date: e.target.value }))}
                  className="dark:[color-scheme:dark]"
                />
              </div>
            </div>
            {addRangeValidating && addSubjectUid && addForm.start_date && addRangeEndYmd ? (
              <p className="flex items-center gap-2 text-[11px] text-muted-foreground">
                <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
                Checking dates against the employee&apos;s work schedule…
              </p>
            ) : null}
            {addRangeRestDay && !addRangeRestDay.valid ? (
              <div className="rounded-lg border border-amber-200/80 bg-amber-50/60 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/25">
                <p className="flex items-start gap-2 font-medium text-amber-950 dark:text-amber-100">
                  <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                  <span>{addRangeRestDay.message}</span>
                </p>
                {isAdminHr ? (
                  <div className="mt-3 space-y-2 border-t border-amber-200/60 pt-3 dark:border-amber-900/40">
                    <div className="flex items-start gap-2">
                      <Checkbox
                        id="add-bypass-rest-days"
                        checked={addBypassRestDays}
                        onCheckedChange={(c) => setAddBypassRestDays(c === true)}
                        className="mt-0.5"
                      />
                      <Label
                        htmlFor="add-bypass-rest-days"
                        className="cursor-pointer text-xs font-normal leading-snug text-amber-950 dark:text-amber-100"
                      >
                        HR admin: file on rest days with a documented reason (recorded in the audit trail).
                      </Label>
                    </div>
                    {addBypassRestDays ? (
                      <div className="space-y-1">
                        <Label htmlFor="add-rest-day-bypass-reason" className="text-[11px]">
                          Override reason (min. {REST_DAY_BYPASS_REASON_MIN} characters)
                        </Label>
                        <textarea
                          id="add-rest-day-bypass-reason"
                          value={addRestDayBypassReason}
                          onChange={(e) => setAddRestDayBypassReason(e.target.value)}
                          rows={3}
                          className={FIELD_TEXTAREA_CLASS_SM}
                        />
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </div>
            ) : null}
            {addRangeRestDay?.valid &&
            addRangeRestDay?.using_default_schedule &&
            addRangeRestDay?.schedule_warning ? (
              <div className="rounded-lg border border-sky-200/80 bg-sky-50/70 px-3 py-2 text-xs text-sky-950 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100">
                <p className="flex items-start gap-2 leading-snug">
                  <AlertTriangle className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300" aria-hidden />
                  <span>{addRangeRestDay.schedule_warning}</span>
                </p>
              </div>
            ) : null}
            <div className="space-y-2">
              <Label htmlFor="add-notes">
                Reason / Notes <span className="text-muted-foreground font-normal">(optional)</span>
              </Label>
              <textarea
                id="add-notes"
                value={addForm.notes}
                onChange={(e) => setAddForm((f) => ({ ...f, notes: e.target.value }))}
                rows={3}
                placeholder="e.g. Scheduled medical procedure, family emergency…"
                className={FIELD_TEXTAREA_CLASS}
              />
              <p className="text-[11px] text-muted-foreground">This will be saved as the admin note on the request.</p>
            </div>
            {user?.is_super_admin ? (
              <div className="flex items-start gap-2 rounded-lg border border-amber-200/80 bg-amber-50/50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
                <Checkbox
                  id="add-bypass-credits"
                  checked={addBypassLeaveCredits}
                  onCheckedChange={(c) => setAddBypassLeaveCredits(c === true)}
                  className="mt-0.5"
                />
                <Label htmlFor="add-bypass-credits" className="cursor-pointer text-xs font-normal leading-snug text-amber-950 dark:text-amber-100">
                  Super admin: file this request even if the employee has insufficient leave credits (bypass validation).
                </Label>
              </div>
            ) : null}
            <div className="space-y-2">
              <Label className="text-sm">Supporting documents (optional)</Label>
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
                {(addForm.supportingFiles ?? []).length > 0 ? (
                  <ul className="mt-3 space-y-2">
                    {(addForm.supportingFiles ?? []).map((f, i) => (
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
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
                className="dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5"
              >
                Cancel
              </Button>
              <Button
                type="submit"
                className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
                disabled={
                  addSubmitting ||
                  addFormRestDayBlocksSubmit ||
                  (showEmployeePicker && !addForm.user_id) ||
                  (!showEmployeePicker && !user?.id) ||
                  !addForm.start_date ||
                  !addForm.end_date
                }
              >
                {addSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
                Submit request
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Reject leave with remarks */}
      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass()}
          aria-describedby="leave-reject-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <XCircle className="size-5 text-destructive" />
                Reject leave request
              </DialogTitle>
              <p id="leave-reject-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {rejectLeave && (
                  <>
                    Provide a short remark for{' '}
                    <span className="font-medium">{rejectLeave.employee_name}</span> (
                    {formatType(rejectLeave.type)}, {formatDateRange(rejectLeave.start_date, rejectLeave.end_date)}).
                  </>
                )}
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleConfirmReject} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label htmlFor="reject-reason">Remarks</Label>
              <textarea
                id="reject-reason"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder="e.g. Overlapping with approved leave, insufficient balance, etc."
                rows={3}
                className={cn(FIELD_TEXTAREA_CLASS_SM, 'focus-visible:ring-rose-500')}
              />
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button
                type="button"
                variant="outline"
                onClick={() => setRejectOpen(false)}
                disabled={rejectSubmitting}
                className="dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5"
              >
                Cancel
              </Button>
              <Button
                type="submit"
                className="gap-2 bg-rose-600 text-white hover:bg-rose-500 dark:bg-rose-600 dark:hover:bg-rose-500"
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
          if (!open) setDetailLeave(null)
        }}
        leave={detailLeave}
        showEmployeeName
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
