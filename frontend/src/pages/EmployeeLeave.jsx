import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Calendar,
  FileText,
  Loader2,
  Plus,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Eye,
  Clock,
  Inbox,
  Sparkles,
  ChevronRight,
  Palmtree,
  HeartPulse,
  AlertTriangle,
  Briefcase,
  CalendarClock,
  Paperclip,
  X,
} from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
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
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { cn } from '@/lib/utils'
import { earliestLeaveStartYmd } from '@/lib/attendanceDates'

const MAX_LEAVE_SUPPORTING_FILES = 5
const MAX_LEAVE_FILE_BYTES = 10 * 1024 * 1024

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

export default function EmployeeLeave() {
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

  const hasTableRows = rows.length > 0

  function renderLeaveTable() {
    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[960px] border-collapse text-[15px]">
          <thead>
            <tr className="border-b border-slate-200 bg-white text-left dark:border-slate-800 dark:bg-card">
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">Leave type</th>
              <th className="min-w-[200px] px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                Date / range
              </th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">Duration</th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                Supporting documents
              </th>
              <th className="min-w-[180px] px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                Reason / remarks
              </th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">Status</th>
              <th className="px-5 py-4 text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">Date filed</th>
              <th className="w-28 px-4 py-4 text-right text-xs font-bold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <TableBodySkeleton rows={6} cols={8} />
            ) : !hasTableRows ? (
              <tr>
                <td colSpan={8} className="px-6 py-20 text-center">
                  <div className="flex flex-col items-center justify-center">
                    <div className="mb-6 flex size-24 items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40">
                      <Inbox className="size-12 text-slate-400" aria-hidden />
                    </div>
                    <div className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-slate-50">
                      <Sparkles className="size-6 text-amber-500" aria-hidden />
                      No leave requests
                    </div>
                    <p className="mt-3 max-w-md text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                      You have no leave requests in the selected date range. File a new leave to get started.
                    </p>
                    <Button
                      type="button"
                      className="mt-8 rounded-xl bg-black px-6 text-white hover:bg-black/90 dark:bg-black"
                      onClick={openFileLeave}
                    >
                      <Plus className="size-4" />
                      File new leave
                    </Button>
                  </div>
                </td>
              </tr>
            ) : (
              rows.map((leave, rowIdx) => {
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
                      'border-b border-slate-100 transition-colors duration-150 hover:bg-emerald-500/[0.06] dark:border-slate-800/80 dark:hover:bg-emerald-500/[0.08]',
                      rowIdx % 2 === 0 ? 'bg-white dark:bg-slate-950/20' : 'bg-slate-50/50 dark:bg-slate-900/15'
                    )}
                  >
                    <td className="px-5 py-4 align-middle">
                      <LeaveTypeBadge type={leave.type} />
                    </td>
                    <td className="px-5 py-4 align-middle text-sm font-medium leading-snug text-slate-900 dark:text-slate-100">
                      {formatDateRangeShort(leave.start_date, leave.end_date)}
                    </td>
                    <td className="px-5 py-4 align-middle tabular-nums text-slate-700 dark:text-slate-300">{durLabel}</td>
                    <td className="px-5 py-4 align-middle text-sm">
                      {(() => {
                        const urls = supportingDocUrls(leave)
                        if (!urls.length) {
                          return <span className="text-slate-500 dark:text-slate-400">No</span>
                        }
                        return (
                          <div className="flex flex-col gap-1">
                            {urls.map((url, i) => (
                              <a
                                key={`${url}-${i}`}
                                href={profileImageUrl(url)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 font-medium text-emerald-700 hover:underline dark:text-emerald-400"
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
                    <td className="px-5 py-4 align-middle text-sm tabular-nums text-slate-600 dark:text-slate-400">
                      {leave.created_at ? formatDateTime(leave.created_at) : '—'}
                    </td>
                    <td className="px-4 py-4 text-right align-middle">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="gap-1.5 rounded-lg text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                        onClick={() => openLeaveDetail(leave)}
                      >
                        <Eye className="size-4" />
                        View details
                      </Button>
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
      <div className="mx-auto flex w-full max-w-full flex-1 flex-col space-y-8 px-1 @sm:px-0">
        <header className="flex flex-col gap-6 border-b border-slate-200/80 pb-8 dark:border-slate-800 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="max-w-2xl space-y-3">
            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">My leave</p>
            <h1 className="text-3xl font-bold tracking-tight text-slate-900 @sm:text-4xl dark:text-slate-50">Leave</h1>
            <p className="text-base leading-relaxed text-slate-600 dark:text-slate-400">
              Request leave, attach supporting documents, and track approvals in one place.
            </p>
          </div>
          <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
            <Button
              type="button"
              variant="outline"
              className="h-11 flex-1 gap-2 rounded-xl border-slate-200 bg-white @lg:flex-initial dark:border-slate-700 dark:bg-slate-950"
              onClick={() => load()}
              disabled={loading}
            >
              {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
              Refresh
            </Button>
            <Button
              type="button"
              className="h-11 flex-1 gap-2 rounded-xl bg-black px-5 text-white shadow-lg shadow-black/20 ring-1 ring-black/20 @lg:flex-initial dark:bg-black dark:text-white dark:ring-white/15"
              onClick={openFileLeave}
            >
              <Plus className="size-4" />
              File new leave
            </Button>
          </div>
        </header>

        {leaveCreditInfo && (
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

        {/* Period filter */}
        <div className="rounded-2xl border border-slate-200/80 bg-slate-50/50 p-4 dark:border-slate-800 dark:bg-slate-900/30">
          <div className="flex flex-col gap-4 @lg:flex-row @lg:items-end @lg:justify-between">
            <div className="grid w-full max-w-lg grid-cols-1 gap-3 @sm:grid-cols-2">
              <div className="space-y-1.5">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">From</span>
                <Input
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className="h-9 rounded-lg border-slate-200 dark:border-slate-700"
                />
              </div>
              <div className="space-y-1.5">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">To</span>
                <Input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="h-9 rounded-lg border-slate-200 dark:border-slate-700"
                />
              </div>
            </div>
            <p className="text-xs text-slate-500 dark:text-slate-400">
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

        <Card className="w-full min-w-0 flex-1 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 ring-1 ring-slate-100/80 dark:border-slate-800 dark:bg-slate-950/40 dark:shadow-black/40 dark:ring-slate-800/50">
            <CardHeader className="border-b border-slate-100 bg-gradient-to-r from-slate-50/90 to-white px-6 py-6 dark:border-slate-800 dark:from-slate-900/40 dark:to-slate-950/20">
              <CardTitle className="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                My leave requests
              </CardTitle>
              <CardDescription className="text-base text-slate-600 dark:text-slate-400">
                Each row is one request. Open details to see the approval chain and remarks.
              </CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              <div className="hidden min-h-[280px] md:block">{renderLeaveTable()}</div>
              <div className="space-y-4 p-4 md:hidden">
                {loading ? (
                  <div className="flex justify-center py-16">
                    <Loader2 className="size-10 animate-spin text-emerald-600" />
                  </div>
                ) : !hasTableRows ? (
                  <div className="flex flex-col items-center px-2 py-12 text-center">
                    <Inbox className="mb-4 size-14 text-slate-400" />
                    <p className="font-semibold text-slate-900 dark:text-slate-50">Nothing to show</p>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                      Adjust the date range or file a new leave request.
                    </p>
                  </div>
                ) : (
                  <AnimatedSection staggerChildren={0.03} duration={0.4}>
                    {rows.map((leave) => {
                      const dur = computeLeaveDurationDays(leave)
                      const durLabel =
                        dur === null
                          ? '—'
                          : dur === 0.5
                            ? '0.5 day'
                            : `${dur} day${dur === 1 ? '' : 's'}`
                      return (
                        <button
                          key={leave.id}
                          type="button"
                          onClick={() => openLeaveDetail(leave)}
                          className="w-full rounded-2xl border border-slate-200/90 bg-white p-4 text-left shadow-sm transition hover:border-emerald-200/80 hover:shadow-md active:scale-[0.99] dark:border-slate-800 dark:bg-slate-950/50"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <LeaveTypeBadge type={leave.type} />
                            <LeaveStatusPill status={leave.status} displayStatus={leave.display_status} />
                          </div>
                          <p className="mt-3 text-sm text-slate-600 dark:text-slate-400">
                            {formatDateShort(leave.start_date)}
                            {leave.start_date !== leave.end_date && (
                              <>
                                {' '}
                                → {formatDateShort(leave.end_date)}
                              </>
                            )}
                          </p>
                          <p className="mt-1 text-xs font-medium text-slate-500">Duration: {durLabel}</p>
                          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
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
                                      className="font-medium text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400"
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
                      )
                    })}
                  </AnimatedSection>
                )}
              </div>
            </CardContent>
          </Card>

        <p className="text-center text-xs leading-relaxed text-slate-500 dark:text-slate-400">
          Your request is reviewed by your manager (department, branch, or company head depending on your role), then by HR
          for final approval.
        </p>
      </div>

      {/* File leave dialog */}
      <Dialog open={addOpen} onOpenChange={setAddOpen}>
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
    </Motion.div>
  )
}
