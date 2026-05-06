import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { motion as Motion } from 'framer-motion'
import { exportRowsToXlsx } from '@/lib/excelExport'
import {
  Loader2,
  RefreshCw,
  Plus,
  Download,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  Search,
  HelpCircle,
  Clock,
  ArrowRight,
  ChevronDown,
  ChevronRight,
  Inbox,
  Sparkles,
  LogIn,
  LogOut,
  FileSpreadsheet,
  LayoutList,
  CalendarDays,
  Printer,
  Trash2,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { useToast } from '@/components/ui/use-toast'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  getAdminPresenceFilings,
  getMyPresenceFilings,
  approvePresenceFiling,
  rejectPresenceFiling,
  submitPresenceFiling,
  addPresenceFilingHrNote,
  deleteAdminPresenceFiling,
  deleteMyPresenceFiling,
} from '@/api'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_MAX_W_LG,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  ADMIN_VIEW_REQUEST_DIALOG_MAX,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'
import { ApprovalChainDetailView } from '@/components/approval/ApprovalChainDetailView'
import {
  issueLabel,
  remarksUserText,
  reviewStatusSortValue,
  formatTimeOnly,
  reviewStatusLabel,
} from '@/lib/presenceFilingTable'
import {
  EmployeeAvatarNameRoleCell,
  ReviewStatusTableBadge,
  RemarksPreviewCell,
  IssueTypeCell,
  TimeCell,
} from '@/components/presenceFiling/CorrectionTableCells'

const APPROVAL_INFO_SHORT =
  'Multi-step approval: managers first, then HR finalizes and updates attendance.'

const APPROVAL_INFO =
  'Approval chain depends on your role: Employee → Department Head → Admin HR | Department Head → Branch Head → Admin HR | Branch Head → Company Head → Admin HR | Company Head → Admin HR. Each approver can add remarks. HR gives final approval and updates attendance records.'

const ISSUE_KIND_OPTIONS = [
  { value: 'missing_in', label: 'Missing Clock In' },
  { value: 'missing_out', label: 'Missing Clock Out' },
  { value: 'both', label: 'Both (Clock In and Clock Out)' },
]

/** Map API display_status to badge styles (readable, consistent). */
function statusBadgeClass(displayStatus) {
  if (!displayStatus) return 'bg-muted text-muted-foreground shadow-sm'
  if (displayStatus === 'HR Approved') {
    return 'bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/25 ring-1 ring-emerald-200/90 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15'
  }
  if (displayStatus === 'Rejected') {
    return 'bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-red-500/20 ring-1 ring-red-200/80 dark:from-red-950/45 dark:to-red-950/25 dark:text-red-100 dark:ring-red-500/30'
  }
  if (displayStatus === 'Draft') {
    return 'bg-muted text-muted-foreground shadow-sm'
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

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

/** Compact date for dense tables (no time). */
function formatTableDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateRangeLabel(from, to) {
  if (!from && !to) return 'Any date'
  const fmt = (d) => {
    if (!d) return null
    try {
      return new Date(`${d}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
    } catch {
      return d
    }
  }
  const a = fmt(from)
  const b = fmt(to)
  if (a && b && from === to) return a
  if (a && b) return `${a} – ${b}`
  return a || b || '—'
}

function AttendanceTimesHero({ timeIn, timeOut }) {
  const inLabel = timeIn
    ? new Date(timeIn).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
    : '—'
  const outLabel = timeOut
    ? new Date(timeOut).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
    : '—'
  return (
    <div className="grid grid-cols-1 gap-3 @sm:grid-cols-2">
      <div className="rounded-lg border border-border/60 bg-background p-4 shadow-sm dark:border-border/50">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Time in</p>
            <p className="mt-1 font-mono text-2xl font-semibold tabular-nums tracking-tight text-foreground @sm:text-3xl">
              {inLabel}
            </p>
          </div>
          <div className="flex size-10 items-center justify-center rounded-md bg-muted text-muted-foreground ring-1 ring-border/50">
            <LogIn className="size-5" aria-hidden />
          </div>
        </div>
      </div>
      <div className="rounded-lg border border-border/60 bg-background p-4 shadow-sm dark:border-border/50">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Time out</p>
            <p className="mt-1 font-mono text-2xl font-semibold tabular-nums tracking-tight text-foreground @sm:text-3xl">
              {outLabel}
            </p>
          </div>
          <div className="flex size-10 items-center justify-center rounded-md bg-muted text-muted-foreground ring-1 ring-border/50">
            <LogOut className="size-5" aria-hidden />
          </div>
        </div>
      </div>
    </div>
  )
}

const STATUS_CHIPS = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

export default function AttendanceCorrections() {
  const { toast } = useToast()
  const { user } = useAuth()
  const hrBase = useHrBasePath()
  const perms = new Set(user?.permissions ?? [])
  const canSeeAll = perms.has('attendance.corrections.approve')
  const canViewEmployeeProfile = perms.has('employees.view')

  const [tab, setTab] = useState('mine')

  const [mineItems, setMineItems] = useState([])
  const [allItems, setAllItems] = useState([])
  const [loadingMine, setLoadingMine] = useState(true)
  const [loadingAll, setLoadingAll] = useState(false)

  const [mineSearch, setMineSearch] = useState('')
  const [allStatus, setAllStatus] = useState('all')
  const [allFrom, setAllFrom] = useState('')
  const [allTo, setAllTo] = useState('')
  const [allIssue, setAllIssue] = useState('all')
  const [allQInput, setAllQInput] = useState('')
  const [debouncedAllQ, setDebouncedAllQ] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDebouncedAllQ(allQInput.trim()), 300)
    return () => clearTimeout(t)
  }, [allQInput])

  const [currentPage, setCurrentPage] = useState(1)
  const itemsPerPage = 10

  const [sortKey, setSortKey] = useState('filed_at')
  const [sortDir, setSortDir] = useState('desc')

  const [viewOpen, setViewOpen] = useState(false)
  const [approveOpen, setApproveOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [fileOpen, setFileOpen] = useState(false)
  const [selectedItem, setSelectedItem] = useState(null)
  const [approveNotes, setApproveNotes] = useState('')
  const [rejectionNote, setRejectionNote] = useState('')
  const [hrNoteText, setHrNoteText] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [hrNoteSubmitting, setHrNoteSubmitting] = useState(false)
  const [deleteDialog, setDeleteDialog] = useState({ open: false, item: null })
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [fileDate, setFileDate] = useState('')
  const [fileIssueKind, setFileIssueKind] = useState('missing_in')
  const [fileTimeIn, setFileTimeIn] = useState('')
  const [fileTimeOut, setFileTimeOut] = useState('')
  const [fileRemarks, setFileRemarks] = useState('')
  const showFileTimeIn = fileIssueKind === 'missing_in' || fileIssueKind === 'both'
  const showFileTimeOut = fileIssueKind === 'missing_out' || fileIssueKind === 'both'

  const searchInputRef = useRef(null)
  const [tableDensity, setTableDensity] = useState('comfortable')

  const loadMine = useCallback(async () => {
    setLoadingMine(true)
    try {
      const res = await getMyPresenceFilings()
      setMineItems(res?.presence_filings ?? [])
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setMineItems([])
    } finally {
      setLoadingMine(false)
    }
  }, [toast])

  const loadAll = useCallback(async () => {
    setLoadingAll(true)
    try {
      const res = await getAdminPresenceFilings({
        status: allStatus,
        from_date: allFrom || undefined,
        to_date: allTo || undefined,
        issue_type: allIssue,
        q: debouncedAllQ || undefined,
      })
      setAllItems(res?.presence_filings ?? [])
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message, variant: 'error' })
      setAllItems([])
    } finally {
      setLoadingAll(false)
    }
  }, [toast, allStatus, allFrom, allTo, allIssue, debouncedAllQ])

  useEffect(() => {
    loadMine()
  }, [loadMine])

  useEffect(() => {
    if (tab === 'all' && canSeeAll) {
      loadAll()
    }
  }, [tab, canSeeAll, loadAll])

  const activeItems = tab === 'mine' ? mineItems : allItems
  const loading = tab === 'mine' ? loadingMine : loadingAll

  const filteredSorted = useMemo(() => {
    let list = [...activeItems]

    if (tab === 'mine' && mineSearch.trim()) {
      const term = mineSearch.toLowerCase()
      list = list.filter(
        (item) =>
          String(item.id).includes(term) ||
          (item.date || '').toLowerCase().includes(term) ||
          remarksUserText(item.remarks || '').toLowerCase().includes(term) ||
          (item.display_status || '').toLowerCase().includes(term) ||
          (item.last_action_label || '').toLowerCase().includes(term) ||
          issueLabel(item.issue_type).toLowerCase().includes(term) ||
          (item.requested_by_name || '').toLowerCase().includes(term) ||
          (item.requested_by_position || '').toLowerCase().includes(term) ||
          (item.requested_by_role_label || '').toLowerCase().includes(term) ||
          (item.employee_name || '').toLowerCase().includes(term) ||
          (item.employee_code || '').toLowerCase().includes(term) ||
          (item.employee_position || '').toLowerCase().includes(term) ||
          (item.employee_role_label || '').toLowerCase().includes(term)
      )
    }

    const dir = sortDir === 'asc' ? 1 : -1
    const key = sortKey
    list.sort((a, b) => {
      let va
      let vb
      switch (key) {
        case 'id':
          va = a.id
          vb = b.id
          break
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
        case 'display_status':
          va = a.display_status || ''
          vb = b.display_status || ''
          break
        case 'review_status':
          va = reviewStatusSortValue(a)
          vb = reviewStatusSortValue(b)
          break
        case 'requested_by_name':
          va = (a.requested_by_name || '').toLowerCase()
          vb = (b.requested_by_name || '').toLowerCase()
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
          va = (a.remarks || '').toLowerCase()
          vb = (b.remarks || '').toLowerCase()
          break
        case 'last_action_label':
          va = (a.last_action_label || '').toLowerCase()
          vb = (b.last_action_label || '').toLowerCase()
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
  }, [activeItems, tab, mineSearch, sortKey, sortDir])

  useEffect(() => {
    setCurrentPage(1)
  }, [mineSearch, tab, allStatus, allFrom, allTo, allIssue, debouncedAllQ, sortKey, sortDir])

  useEffect(() => {
    function onKeyDown(e) {
      const el = e.target
      const tag = el?.tagName
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el?.isContentEditable) return
      if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault()
        searchInputRef.current?.focus()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const paginatedItems = filteredSorted.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage
  )
  const totalPages = Math.max(1, Math.ceil(filteredSorted.length / itemsPerPage))

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
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="-ml-2 h-9 gap-1.5 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:bg-muted/80 hover:text-foreground"
        onClick={() => toggleSort(col)}
      >
        {label}
        <Icon className={cn('size-3.5', active ? 'text-primary opacity-100' : 'opacity-50')} />
      </Button>
    )
  }

  function openView(item) {
    setSelectedItem(item)
    setHrNoteText('')
    setViewOpen(true)
  }

  function openApprove(item) {
    setSelectedItem(item)
    setApproveNotes('')
    setApproveOpen(true)
  }

  function openReject(item) {
    setSelectedItem(item)
    setRejectionNote('')
    setRejectOpen(true)
  }

  function openFile() {
    const today = new Date().toISOString().split('T')[0]
    setFileDate(today)
    setFileIssueKind('missing_in')
    setFileTimeIn('')
    setFileTimeOut('')
    setFileRemarks('')
    setFileOpen(true)
  }

  function handleFileIssueKindChange(next) {
    setFileIssueKind(next)
    if (next === 'missing_in') setFileTimeOut('')
    else if (next === 'missing_out') setFileTimeIn('')
  }

  async function submitApprove() {
    try {
      setSubmitting(true)
      await approvePresenceFiling(selectedItem.id, { notes: approveNotes.trim() || undefined })
      toast({ title: 'Approved', description: 'The correction request was updated.', variant: 'success' })
      setApproveOpen(false)
      await loadAll()
      await loadMine()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setSubmitting(false)
    }
  }

  async function submitHrNote() {
    if (!selectedItem?.id || !hrNoteText.trim()) {
      toast({ title: 'Enter a remark', variant: 'error' })
      return
    }
    try {
      setHrNoteSubmitting(true)
      const data = await addPresenceFilingHrNote(selectedItem.id, hrNoteText.trim())
      const updated = data?.presence_filing
      if (updated) setSelectedItem(updated)
      setHrNoteText('')
      toast({ title: 'Remark saved', description: 'Added to the audit trail.', variant: 'success' })
      await loadAll()
      await loadMine()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setHrNoteSubmitting(false)
    }
  }

  async function submitReject() {
    if (!rejectionNote.trim()) {
      toast({ title: 'Reason required', variant: 'error' })
      return
    }
    try {
      setSubmitting(true)
      await rejectPresenceFiling(selectedItem.id, rejectionNote)
      toast({ title: 'Rejected', description: 'The request was rejected.', variant: 'success' })
      setRejectOpen(false)
      await loadAll()
      await loadMine()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setSubmitting(false)
    }
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
      setSubmitting(true)
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
      await loadMine()
      if (canSeeAll) await loadAll()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setSubmitting(false)
    }
  }

  async function submitDelete() {
    if (!deleteDialog.item) return
    setDeleteSubmitting(true)
    try {
      if (tab === 'all') {
        await deleteAdminPresenceFiling(deleteDialog.item.id)
      } else {
        await deleteMyPresenceFiling(deleteDialog.item.id)
      }
      toast({ title: 'Deleted', description: 'The correction request was deleted.', variant: 'success' })
      setDeleteDialog({ open: false, item: null })
      if (selectedItem?.id && Number(selectedItem.id) === Number(deleteDialog.item.id)) {
        setViewOpen(false)
        setSelectedItem(null)
      }
      await loadMine()
      if (canSeeAll) await loadAll()
    } catch (e) {
      toast({ title: 'Failed', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  async function refresh() {
    if (tab === 'mine') await loadMine()
    else await loadAll()
  }

  function buildExportMatrix(list) {
    const headers = [
      'Employee',
      'Org role',
      'Attendance date',
      'Issue type',
      'Reason',
      'Time in',
      'Time out',
      'Status',
      'Remarks',
      'Date filed',
    ]
    const rows = list.map((item) => {
      const tIn = item.requested_time_in ?? item.time_in
      const tOut = item.requested_time_out ?? item.time_out
      return [
        item.employee_name || item.requested_by_name || '',
        item.employee_role_label ?? item.requested_by_role_label ?? '',
        item.date || '',
        issueLabel(item.issue_type),
        item.reason_code || '',
        tIn ? formatTimeOnly(tIn) : '',
        tOut ? formatTimeOnly(tOut) : '',
        item.display_status || reviewStatusLabel(item),
        remarksUserText(item.remarks || '') || '',
        item.filed_at ? new Date(item.filed_at).toLocaleString('en-PH') : '',
      ]
    })
    return { headers, rows }
  }

  function exportToCSV(list) {
    const { headers, rows } = buildExportMatrix(list)
    const csv = [headers, ...rows]
      .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
      .join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `correction-requests-${tab}-${new Date().toISOString().split('T')[0]}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function exportToExcel(list) {
    const { headers, rows } = buildExportMatrix(list)
    await exportRowsToXlsx(
      headers,
      rows,
      `correction-requests-${tab}-${new Date().toISOString().split('T')[0]}.xlsx`,
      'Corrections',
    )
  }

  const cellPad = tableDensity === 'compact' ? '!p-2' : '!p-3.5'

  return (
    <Motion.div
      className="min-h-0 min-w-0 max-w-full space-y-4 overflow-x-hidden @sm:space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="flex w-full min-w-0 flex-col gap-5 border-b border-border/60 pb-6 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="max-w-2xl space-y-3">
          <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
            Corrections
          </p>
          <h1 className="hr-page-title text-slate-900 dark:text-slate-50">
            Correction Requests
          </h1>
          <p className="text-base leading-relaxed text-slate-600 dark:text-slate-400">
            Review filings, approve in sequence, and keep attendance accurate — without the spreadsheet fatigue.
          </p>
          <div className="flex flex-wrap items-center gap-2 pt-1">
            <p className="text-sm text-muted-foreground">{APPROVAL_INFO_SHORT}</p>
            <Popover>
              <PopoverTrigger asChild>
                <button
                  type="button"
                  className="inline-flex items-center gap-1 rounded-full border border-border/70 bg-background px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
                >
                  <HelpCircle className="size-3.5" aria-hidden />
                  How it works
                </button>
              </PopoverTrigger>
              <PopoverContent className="max-w-md text-sm leading-relaxed" align="start">
                <p className="text-foreground">{APPROVAL_INFO}</p>
              </PopoverContent>
            </Popover>
          </div>
        </div>
        <Button
          type="button"
          size="lg"
          className="group h-12 shrink-0 gap-2 self-start rounded-xl bg-black px-6 text-white shadow-lg shadow-black/25 ring-1 ring-black/20 transition-all hover:bg-black/90 hover:shadow-black/20 dark:bg-black dark:text-white dark:ring-white/15 dark:hover:bg-black/90"
          onClick={openFile}
        >
          <Plus className="size-5 transition-transform group-hover:scale-110" />
          File Correction
        </Button>
      </div>

      <Card className="w-full min-w-0 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 ring-1 ring-slate-100/80 dark:border-slate-800 dark:bg-slate-950/40 dark:shadow-black/40 dark:ring-slate-800/50">
        <CardHeader className="space-y-1 border-b border-slate-100 bg-gradient-to-r from-slate-50/90 to-white px-5 py-5 dark:border-slate-800 dark:from-slate-900/40 dark:to-slate-950/20 @md:px-6 @md:py-6">
          <CardTitle className="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
            Request list
          </CardTitle>
          <CardDescription className="text-sm text-slate-600 dark:text-slate-400">
            {tab === 'mine' ? 'Your correction filings' : 'Requests in your approval scope'}
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {loading && (tab === 'mine' ? mineItems.length === 0 : allItems.length === 0) ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <AnimatedSection staggerChildren={0.04} duration={0.45}>
              <div className="border-b border-slate-100 bg-muted/15 px-4 py-5 dark:border-slate-800 dark:bg-muted/10 @sm:px-6 md:px-8">
                <div className="flex flex-col gap-5">
                  {canSeeAll && (
                    <div className="flex w-full min-w-0 justify-center @sm:justify-start">
                      <div
                        className="inline-flex rounded-xl border border-border/60 bg-muted/40 p-1 shadow-inner dark:bg-muted/30"
                        role="tablist"
                        aria-label="List scope"
                      >
                        <button
                          type="button"
                          role="tab"
                          aria-selected={tab === 'mine'}
                          onClick={() => setTab('mine')}
                          className={cn(
                            'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all @sm:px-5',
                            tab === 'mine'
                              ? 'bg-card text-foreground shadow-sm ring-1 ring-border/60'
                              : 'text-muted-foreground hover:text-foreground'
                          )}
                        >
                          My Filings
                        </button>
                        <button
                          type="button"
                          role="tab"
                          aria-selected={tab === 'all'}
                          onClick={() => setTab('all')}
                          className={cn(
                            'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all @sm:px-5',
                            tab === 'all'
                              ? 'bg-card text-foreground shadow-sm ring-1 ring-border/60'
                              : 'text-muted-foreground hover:text-foreground'
                          )}
                        >
                          All Requests
                        </button>
                      </div>
                    </div>
                  )}

                  <div className="relative w-full min-w-0 max-w-full">
                    <Search
                      className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      ref={searchInputRef}
                      type="search"
                      value={tab === 'mine' ? mineSearch : allQInput}
                      onChange={(e) => (tab === 'mine' ? setMineSearch(e.target.value) : setAllQInput(e.target.value))}
                      placeholder={
                        tab === 'mine'
                          ? 'Search employee, date, status, issue type, remarks…'
                          : 'Search employee name, code, or request ID…'
                      }
                      className="h-12 w-full min-w-0 rounded-xl border-slate-200/90 bg-white pl-10 pr-36 text-[15px] shadow-[0_1px_2px_rgba(15,23,42,0.06)] dark:border-slate-700 dark:bg-slate-950/45"
                      aria-label={tab === 'mine' ? 'Search my filings' : 'Search requested by'}
                    />
                    <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1.5">
                      <kbd className="hidden rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground @sm:inline-block">
                        /
                      </kbd>
                      <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        className="size-9 rounded-lg"
                        onClick={() => refresh()}
                        disabled={loading}
                        aria-label="Refresh list"
                      >
                        {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                      </Button>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {tab === 'mine'
                      ? 'Tip: filter includes employee fields, date, issue type, status, and remarks.'
                      : 'Server search matches name, employee code, and request ID. Use column headers to sort.'}
                  </p>

                  {tab === 'all' && (
                    <div className="flex flex-col gap-5 @xl:flex-row @xl:flex-wrap @xl:items-end @xl:justify-between">
                      <div
                        className="inline-flex min-w-0 flex-wrap gap-2 rounded-2xl border border-slate-200/80 bg-white p-1 shadow-inner dark:border-slate-700 dark:bg-slate-900/40"
                        role="group"
                        aria-label="Filter by status"
                      >
                        {STATUS_CHIPS.map(({ value, label }) => (
                          <button
                            key={value}
                            type="button"
                            onClick={() => setAllStatus(value)}
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
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Date range
                          </span>
                          <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950/45">
                            <CalendarDays className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                            <span className="min-w-0 flex-1 text-sm font-medium text-foreground">
                              {formatDateRangeLabel(allFrom, allTo)}
                            </span>
                          </div>
                          <div className="flex flex-wrap items-center gap-2">
                            <Input
                              type="date"
                              value={allFrom}
                              onChange={(e) => setAllFrom(e.target.value)}
                              className="h-10 min-w-0 flex-1 rounded-lg text-[15px] @md:max-w-[11rem]"
                            />
                            <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                            <Input
                              type="date"
                              value={allTo}
                              onChange={(e) => setAllTo(e.target.value)}
                              className="h-10 min-w-0 flex-1 rounded-lg text-[15px] @md:max-w-[11rem]"
                            />
                          </div>
                        </div>
                        <div className="w-full min-w-[10rem] @md:w-48">
                          <Label className="mb-1.5 block text-xs font-medium text-muted-foreground">Issue type</Label>
                          <Select value={allIssue} onValueChange={setAllIssue}>
                            <SelectTrigger className="h-10 rounded-lg text-[15px]">
                              <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="all">All issues</SelectItem>
                              <SelectItem value="missing_in">Missing Clock In</SelectItem>
                              <SelectItem value="missing_out">Missing Clock Out</SelectItem>
                              <SelectItem value="both">Both (Clock In and Clock Out)</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                    </div>
                  )}

                  <div className="flex w-full min-w-0 flex-col gap-3 border-t border-border/40 pt-4 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-between">
                    <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                      <p>
                        <span className="font-semibold tabular-nums text-foreground">{filteredSorted.length}</span>
                        {filteredSorted.length === 1 ? ' request' : ' requests'}
                        {tab === 'mine' && mineSearch.trim() ? ' · filtered' : ''}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <div className="flex w-fit rounded-lg border border-border/70 bg-muted/30 p-0.5 dark:bg-muted/20">
                        <Button
                          type="button"
                          variant={tableDensity === 'comfortable' ? 'secondary' : 'ghost'}
                          size="sm"
                          className="h-8 gap-1.5 rounded-md px-2.5 text-xs"
                          onClick={() => setTableDensity('comfortable')}
                          title="Comfortable row height"
                        >
                          <LayoutList className="size-3.5" />
                          Comfortable
                        </Button>
                        <Button
                          type="button"
                          variant={tableDensity === 'compact' ? 'secondary' : 'ghost'}
                          size="sm"
                          className="h-8 gap-1.5 rounded-md px-2.5 text-xs"
                          onClick={() => setTableDensity('compact')}
                          title="Compact"
                        >
                          Compact
                        </Button>
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button type="button" variant="outline" size="sm" className="h-10 gap-2 rounded-lg">
                            <Download className="size-4" />
                            Export
                            <ChevronDown className="size-3.5 opacity-60" aria-hidden />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                          <DropdownMenuItem
                            onClick={() => exportToCSV(filteredSorted)}
                            className="gap-2"
                          >
                            <Download className="size-4" />
                            Export CSV
                          </DropdownMenuItem>
                          <DropdownMenuItem onClick={() => exportToExcel(filteredSorted)} className="gap-2">
                            <FileSpreadsheet className="size-4" />
                            Export Excel
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => {
                              toast({
                                title: 'Print',
                                description: 'Open a request’s details, then use your browser print dialog (Ctrl+P).',
                              })
                            }}
                            className="gap-2"
                          >
                            <Printer className="size-4" />
                            Print tips
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
                </div>
              </div>

              {filteredSorted.length === 0 && !loading ? (
                <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                  <div className="mb-6 flex size-24 items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40">
                    <Inbox className="size-12 text-slate-400" aria-hidden />
                  </div>
                  <div className="flex items-center gap-2 text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                    <Sparkles className="size-6 text-amber-500" aria-hidden />
                    No requests match
                  </div>
                  <p className="mt-3 max-w-md text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                    {tab === 'mine'
                      ? 'You have no correction requests yet. File one when you need to fix a missed punch.'
                      : 'Try widening the date range, clearing filters, or searching with a different keyword.'}
                  </p>
                  {tab === 'mine' && (
                    <Button
                      type="button"
                      className="mt-8 rounded-xl bg-black px-6 text-white shadow-md ring-1 ring-black/20 hover:bg-black/90 dark:bg-black dark:text-white dark:ring-white/15 dark:hover:bg-black/90"
                      onClick={openFile}
                    >
                      <Plus className="size-4" />
                      File correction
                    </Button>
                  )}
                </div>
              ) : (
                <>
                  <div className="w-full min-w-0 space-y-3 bg-card px-4 pb-6 pt-2 sm:px-6 md:px-8 lg:hidden">
                    {paginatedItems.map((item) => {
                      const employeeProfileTo =
                        canViewEmployeeProfile && item.user_id
                          ? hrPanelPath(hrBase, `employees/${item.user_id}`)
                          : null
                      const empName = item.employee_name || item.requested_by_name || '—'
                      const empImg = item.employee_profile_image_url || item.requested_by_profile_image_url
                      const empRoleLabel = item.employee_role_label ?? item.requested_by_role_label
                      const empHrRole = item.employee_hr_role ?? item.requested_by_hr_role
                      const tIn = item.requested_time_in ?? item.time_in
                      const tOut = item.requested_time_out ?? item.time_out
                      return (
                        <div
                          key={item.id}
                          className="rounded-2xl border border-border/80 bg-card p-4 shadow-sm"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                              <p className="font-mono text-xs font-semibold text-muted-foreground">#{item.id}</p>
                              <p className="mt-1 text-base font-semibold text-foreground">
                                {item.date ? formatTableDate(`${item.date}T12:00:00`) : '—'}
                              </p>
                              <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                Filed {item.filed_at ? formatDateTime(item.filed_at) : '—'}
                              </p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-2">
                              <ReviewStatusTableBadge item={item} />
                              <ChevronRight className="size-5 text-muted-foreground" aria-hidden />
                            </div>
                          </div>
                          <div className="mt-3">
                            <EmployeeAvatarNameRoleCell
                              name={empName}
                              imageUrl={empImg}
                              profileTo={employeeProfileTo}
                              compact
                              roleLabel={empRoleLabel}
                              hrRole={empHrRole}
                            />
                          </div>
                          <div className="mt-3">
                            <IssueTypeCell issueType={item.issue_type} reasonCode={item.reason_code} />
                          </div>
                          <div className="mt-3 grid grid-cols-2 gap-3 text-xs">
                            <div className="min-w-0">
                              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time in</p>
                              <div className="mt-0.5 break-words">
                                <TimeCell iso={tIn} />
                              </div>
                            </div>
                            <div className="min-w-0">
                              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time out</p>
                              <div className="mt-0.5 break-words">
                                <TimeCell iso={tOut} />
                              </div>
                            </div>
                          </div>
                          <div className="mt-3 border-t border-border/60 pt-3">
                            <RemarksPreviewCell text={item.remarks} />
                          </div>
                          <div className="mt-4">
                            <AdminDataTableActions
                              className="w-full flex-wrap justify-stretch gap-2 sm:flex-nowrap sm:justify-end sm:gap-1.5"
                              onView={() => openView(item)}
                              showApprove={tab === 'all' && item.actor_can_approve}
                              onApprove={() => openApprove(item)}
                              showReject={tab === 'all' && item.actor_can_reject}
                              onReject={() => openReject(item)}
                              showDelete={Boolean(item.actor_can_delete)}
                              onDelete={() => setDeleteDialog({ open: true, item })}
                            />
                          </div>
                        </div>
                      )
                    })}
                  </div>

                  <div className="hidden w-full min-w-0 touch-pan-x overflow-x-auto bg-card px-4 pb-8 pt-2 sm:px-6 md:px-8 lg:block">
                    <Table className="w-full min-w-[720px] xl:min-w-[980px]">
                      <TableHeader>
                        <TableRow className="border-b border-border/60 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25 dark:hover:bg-muted/25">
                          <TableHead className="min-w-[200px] py-3.5 pl-2 sm:pl-3 xl:min-w-[220px]">
                            <SortHead col="employee_name" label="Employee" />
                          </TableHead>
                          <TableHead className="min-w-[7.5rem] py-3.5">
                            <SortHead col="date" label="Date" />
                          </TableHead>
                          <TableHead className="min-w-[9rem] py-3.5 xl:min-w-[10rem]">
                            <SortHead col="issue_type" label="Issue type" />
                          </TableHead>
                          <TableHead className="min-w-[5.5rem] py-3.5">
                            <SortHead col="time_in" label="Time in" />
                          </TableHead>
                          <TableHead className="min-w-[5.5rem] py-3.5">
                            <SortHead col="time_out" label="Time out" />
                          </TableHead>
                          <TableHead className="min-w-[10rem] py-3.5">
                            <SortHead col="review_status" label="Status" />
                          </TableHead>
                          <TableHead className="hidden min-w-[12rem] py-3.5 xl:table-cell">
                            <SortHead col="remarks" label="Remarks" />
                          </TableHead>
                          <TableHead className="hidden min-w-[9rem] py-3.5 xl:table-cell">
                            <SortHead col="filed_at" label="Date filed" />
                          </TableHead>
                          <TableHead className="w-[13rem] min-w-[13rem] py-3.5 pr-2 text-right sm:pr-3 xl:w-[14rem] xl:min-w-[14rem]">
                            <span className="pr-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              Actions
                            </span>
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedItems.map((item, rowIdx) => {
                          const employeeProfileTo =
                            canViewEmployeeProfile && item.user_id
                              ? hrPanelPath(hrBase, `employees/${item.user_id}`)
                              : null
                          const empName = item.employee_name || item.requested_by_name || '—'
                          const empImg = item.employee_profile_image_url || item.requested_by_profile_image_url
                          const empRoleLabel = item.employee_role_label ?? item.requested_by_role_label
                          const empHrRole = item.employee_hr_role ?? item.requested_by_hr_role
                          const tIn = item.requested_time_in ?? item.time_in
                          const tOut = item.requested_time_out ?? item.time_out
                          return (
                            <TableRow
                              key={item.id}
                              className={cn(
                                'border-b border-border/50 text-[15px] leading-snug transition-colors',
                                'hover:bg-muted/25',
                                rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/20 dark:bg-muted/10'
                              )}
                            >
                              <TableCell className={cn('align-top', cellPad)}>
                                <EmployeeAvatarNameRoleCell
                                  name={empName}
                                  imageUrl={empImg}
                                  profileTo={employeeProfileTo}
                                  compact={tableDensity === 'compact'}
                                  roleLabel={empRoleLabel}
                                  hrRole={empHrRole}
                                />
                              </TableCell>
                              <TableCell className={cn('align-middle tabular-nums text-foreground', cellPad)}>
                                {item.date ? formatTableDate(`${item.date}T12:00:00`) : '—'}
                              </TableCell>
                              <TableCell className={cn('align-top', cellPad)}>
                                <IssueTypeCell issueType={item.issue_type} reasonCode={item.reason_code} />
                              </TableCell>
                              <TableCell className={cn('align-middle', cellPad)}>
                                <TimeCell iso={tIn} />
                              </TableCell>
                              <TableCell className={cn('align-middle', cellPad)}>
                                <TimeCell iso={tOut} />
                              </TableCell>
                              <TableCell className={cn('max-w-[14rem] align-top', cellPad)}>
                                <ReviewStatusTableBadge item={item} />
                              </TableCell>
                              <TableCell
                                className={cn('hidden max-w-[14rem] align-top xl:table-cell', cellPad)}
                                onClick={(e) => e.stopPropagation()}
                              >
                                <RemarksPreviewCell text={item.remarks} />
                              </TableCell>
                              <TableCell
                                className={cn(
                                  'hidden align-middle text-sm tabular-nums text-foreground xl:table-cell',
                                  cellPad
                                )}
                              >
                                {item.filed_at ? formatDateTime(item.filed_at) : '—'}
                              </TableCell>
                              <TableCell className={cn('text-right align-middle', cellPad)}>
                                <AdminDataTableActions
                                  onView={() => openView(item)}
                                  showApprove={tab === 'all' && item.actor_can_approve}
                                  onApprove={() => openApprove(item)}
                                  showReject={tab === 'all' && item.actor_can_reject}
                                  onReject={() => openReject(item)}
                                  showDelete={Boolean(item.actor_can_delete)}
                                  onDelete={() => setDeleteDialog({ open: true, item })}
                                />
                              </TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>
                  {totalPages > 1 && (
                    <div className="flex items-center justify-between border-t border-border/40 px-4 pb-6 pt-5 @sm:px-6 md:px-8">
                      <div className="text-sm tabular-nums text-muted-foreground">
                        Page {currentPage} of {totalPages}
                      </div>
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="rounded-lg"
                          onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                          disabled={currentPage === 1}
                        >
                          Previous
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="rounded-lg"
                          onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                          disabled={currentPage === totalPages}
                        >
                          Next
                        </Button>
                      </div>
                    </div>
                  )}
                </>
              )}
            </AnimatedSection>
          )}
        </CardContent>
      </Card>

      <Dialog open={viewOpen} onOpenChange={setViewOpen}>
        <DialogContent className={adminFormDialogContentClass(ADMIN_VIEW_REQUEST_DIALOG_MAX)}>
          <DialogHeader className="sr-only">
            <DialogTitle>Correction request details</DialogTitle>
            <DialogDescription>Approval chain, attendance, and approval history.</DialogDescription>
          </DialogHeader>
          {selectedItem && (
            <>
              <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
                <div className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'space-y-3')}>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                    Correction request
                  </p>
                  <div className="flex flex-wrap items-center gap-3">
                    <span className="font-mono text-2xl font-bold tracking-tight text-foreground @sm:text-3xl">
                      #{selectedItem.id}
                    </span>
                    <Badge
                      className={cn(
                        'rounded-full px-3 py-1 text-sm font-semibold',
                        statusBadgeClass(selectedItem.display_status)
                      )}
                    >
                      {selectedItem.display_status || '—'}
                    </Badge>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    Review summary, attendance times, approval chain, and history below.
                  </p>
                </div>
              </div>
              <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-5 text-sm')}>
                  <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                    <h3 className="mb-4 flex items-center gap-2 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                      <CalendarDays className="size-4 shrink-0" aria-hidden />
                      Summary
                    </h3>
                    <div className="grid grid-cols-1 gap-x-8 gap-y-3 @lg:grid-cols-2">
                      <div className="grid grid-cols-[minmax(0,11rem)_1fr] gap-x-3 gap-y-2.5 text-sm">
                        <span className="text-muted-foreground">Attendance date</span>
                        <span className="font-medium tabular-nums text-foreground">{selectedItem.date || '—'}</span>
                        <span className="text-muted-foreground">Issue type</span>
                        <span>
                          <Badge variant="outline" className="font-normal">
                            {issueLabel(selectedItem.issue_type)}
                          </Badge>
                        </span>
                        <span className="text-muted-foreground">Requested time start</span>
                        <span className="font-mono font-medium tabular-nums text-foreground">
                          {formatTimeOnly(selectedItem.requested_time_in ?? selectedItem.time_in)}
                        </span>
                        <span className="text-muted-foreground">Requested time end</span>
                        <span className="font-mono font-medium tabular-nums text-foreground">
                          {formatTimeOnly(selectedItem.requested_time_out ?? selectedItem.time_out)}
                        </span>
                        <span className="text-muted-foreground">Last action</span>
                        <span className="text-foreground">{selectedItem.last_action_label || '—'}</span>
                        {tab === 'all' ? (
                          <>
                            <span className="text-muted-foreground">Requested by</span>
                            <span className="font-medium text-foreground">{selectedItem.requested_by_name || '—'}</span>
                          </>
                        ) : null}
                        <span className="text-muted-foreground">Filed</span>
                        <span className="tabular-nums text-foreground">{formatDateTime(selectedItem.filed_at)}</span>
                        <span className="text-muted-foreground">Last updated</span>
                        <span className="tabular-nums text-foreground">{formatDateTime(selectedItem.last_updated)}</span>
                      </div>
                    </div>
                  </section>

                  {selectedItem.attendance_logs_synced_at ? (
                    <div
                      role="status"
                      className="rounded-xl border border-emerald-200/80 bg-emerald-50/90 px-4 py-3 text-sm dark:border-emerald-800 dark:bg-emerald-950/35"
                    >
                      <p className="font-semibold text-emerald-950 dark:text-emerald-50">Applied to attendance</p>
                      <p className="mt-1 text-xs leading-relaxed text-emerald-900/95 dark:text-emerald-100/85">
                        Corrected times were written to this employee&apos;s attendance logs on{' '}
                        {formatDateTime(selectedItem.attendance_logs_synced_at)}
                        {selectedItem.attendance_logs_synced_by_name
                          ? ` by ${selectedItem.attendance_logs_synced_by_name}`
                          : ''}
                        . The Attendance module reflects these punches.
                      </p>
                    </div>
                  ) : null}

                  <section className="rounded-lg border border-border/60 bg-muted/20 p-5 dark:border-border/50 dark:bg-muted/15">
                    <h3 className="mb-1 flex items-center gap-2 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                      <Clock className="size-4 shrink-0" aria-hidden />
                      Attendance times
                    </h3>
                    <p className="mb-4 text-xs leading-relaxed text-muted-foreground">
                      {selectedItem.display_status === 'HR Approved'
                        ? 'Final approved correction times for this request (only requested missing punches are applied to attendance).'
                        : 'Filed correction times on this request (only requested missing punches will be applied after final approval).'}
                    </p>
                    <AttendanceTimesHero timeIn={selectedItem.time_in} timeOut={selectedItem.time_out} />
                  </section>

                  {selectedItem.remarks ? (
                    <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                      <h3 className="mb-3 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                        Requester remarks
                      </h3>
                      <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{selectedItem.remarks}</p>
                    </section>
                  ) : null}

                  {selectedItem.hr_wait_message ? (
                    <div
                      role="status"
                      className="rounded-xl border border-amber-500/45 bg-amber-500/[0.12] px-4 py-3 text-sm text-amber-950 dark:border-amber-500/35 dark:bg-amber-950/25 dark:text-amber-100"
                    >
                      {selectedItem.hr_wait_message}
                    </div>
                  ) : null}

                  {selectedItem.actor_can_add_hr_note ? (
                    <section className="rounded-xl border border-border/60 bg-card p-4 shadow-sm">
                      <h3 className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-muted-foreground">
                        HR internal remark
                      </h3>
                      <p className="mb-3 text-xs text-muted-foreground">
                        Does not change workflow — stored in the audit trail only.
                      </p>
                      <Textarea
                        value={hrNoteText}
                        onChange={(e) => setHrNoteText(e.target.value)}
                        rows={3}
                        placeholder="Optional note while waiting on prior approvers…"
                        className="mb-2"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        disabled={hrNoteSubmitting || !hrNoteText.trim()}
                        onClick={submitHrNote}
                      >
                        {hrNoteSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save remark'}
                      </Button>
                    </section>
                  ) : null}

                  {Array.isArray(selectedItem.approval_progress) && selectedItem.approval_progress.length > 0 ? (
                    <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                      <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                        Approval chain
                      </h3>
                      <ApprovalChainDetailView steps={selectedItem.approval_progress} />
                    </section>
                  ) : null}

                  {Array.isArray(selectedItem.approval_history) && selectedItem.approval_history.length > 0 ? (
                    <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                      <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                        Approval history
                      </h3>
                      <ol className="relative ml-0.5 border-l-2 border-border pl-6">
                        {[...selectedItem.approval_history]
                          .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
                          .map((h, idx) => {
                            const actionLabel =
                              h.action === 'hr_remark'
                                ? 'HR internal remark'
                                : h.action === 'approve_first'
                                  ? 'Line manager approval'
                                  : h.action === 'approve_final'
                                    ? 'HR final approval'
                                    : h.action === 'reject'
                                      ? 'Rejected'
                                      : h.action === 'file'
                                        ? 'Request filed'
                                        : h.action || 'Action'
                            const headline = [h.actor_name, h.approver_role || actionLabel].filter(Boolean).join(' · ')
                            return (
                              <li key={`${h.at}-${idx}-${h.action}`} className="relative pb-6 last:pb-0">
                                <span className="absolute -left-[1.35rem] top-1 size-2.5 rounded-full border-2 border-background bg-primary ring-2 ring-background" />
                                <div className="rounded-lg border border-border/50 bg-muted/30 px-3 py-2.5 dark:bg-white/5">
                                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="text-sm font-semibold text-foreground">{headline || actionLabel}</p>
                                    <time className="text-xs tabular-nums text-muted-foreground" dateTime={h.at || undefined}>
                                      {h.at ? formatDateTime(h.at) : '—'}
                                    </time>
                                  </div>
                                  {sanitizeApprovalDisplayText(h?.details) ? (
                                    <p className="mt-2 whitespace-pre-wrap text-xs leading-relaxed text-muted-foreground">
                                      {sanitizeApprovalDisplayText(h.details)}
                                    </p>
                                  ) : null}
                                </div>
                              </li>
                            )
                          })}
                      </ol>
                    </section>
                  ) : null}

                  {selectedItem.rejection_note ? (
                    <section className="rounded-lg border border-destructive/30 bg-destructive/[0.06] p-5 dark:border-destructive/25 dark:bg-destructive/10">
                      <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-destructive">
                        Rejection reason
                      </h3>
                      <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{selectedItem.rejection_note}</p>
                    </section>
                  ) : null}
              </div>
              <div className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
                <p className="text-xs text-muted-foreground">
                  <kbd className="rounded border border-border bg-background px-1 font-mono text-[10px]">Esc</kbd> to close
                </p>
                {selectedItem.actor_can_delete ? (
                  <Button type="button" variant="destructive" onClick={() => setDeleteDialog({ open: true, item: selectedItem })}>
                    <Trash2 className="size-4" />
                    Delete
                  </Button>
                ) : null}
                <Button type="button" variant="outline" onClick={() => setViewOpen(false)}>
                  Close
                </Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Approve request</DialogTitle>
            <DialogDescription>Optional remarks are stored in the audit trail.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="approve-remarks">Remarks</Label>
              <Textarea
                id="approve-remarks"
                value={approveNotes}
                onChange={(e) => setApproveNotes(e.target.value)}
                rows={3}
                placeholder="Notes for auditors…"
              />
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => setApproveOpen(false)}>
              Cancel
            </Button>
            <Button type="button" onClick={submitApprove} disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Approve'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Reject request</DialogTitle>
            <DialogDescription>Provide a reason for rejection (required).</DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="reject-note">Rejection remarks *</Label>
              <Textarea
                id="reject-note"
                value={rejectionNote}
                onChange={(e) => setRejectionNote(e.target.value)}
                rows={3}
                placeholder="Explain why this request is rejected…"
              />
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => setRejectOpen(false)}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" onClick={submitReject} disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Reject'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={fileOpen} onOpenChange={setFileOpen}>
        <DialogContent className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <div className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>File correction request</DialogTitle>
              <DialogDescription className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Choose the date and issue type. Only the time fields that apply to your issue are shown. Remarks are
                required.
              </DialogDescription>
            </div>
          </DialogHeader>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="file-date">Date *</Label>
                <Input
                  id="file-date"
                  type="date"
                  className="h-11 rounded-lg"
                  value={fileDate}
                  onChange={(e) => setFileDate(e.target.value)}
                  max={new Date().toISOString().split('T')[0]}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="file-issue">Issue type *</Label>
                <Select value={fileIssueKind} onValueChange={handleFileIssueKindChange}>
                  <SelectTrigger id="file-issue" className="h-11 rounded-lg">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {ISSUE_KIND_OPTIONS.map((o) => (
                      <SelectItem key={o.value} value={o.value}>
                        {o.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
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
                    key="file-time-in"
                    initial={{ opacity: 0, y: -6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2 }}
                    className="space-y-2"
                  >
                    <Label htmlFor="file-time-in">Actual Clock In Time *</Label>
                    <Input
                      id="file-time-in"
                      type="time"
                      step={60}
                      className="h-11 rounded-lg"
                      value={fileTimeIn}
                      onChange={(e) => setFileTimeIn(e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">Enter the actual time you clocked in.</p>
                  </Motion.div>
                )}
                {showFileTimeOut && (
                  <Motion.div
                    key="file-time-out"
                    initial={{ opacity: 0, y: -6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2 }}
                    className="space-y-2"
                  >
                    <Label htmlFor="file-time-out">Actual Clock Out Time *</Label>
                    <Input
                      id="file-time-out"
                      type="time"
                      step={60}
                      className="h-11 rounded-lg"
                      value={fileTimeOut}
                      onChange={(e) => setFileTimeOut(e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">Enter the actual time you clocked out.</p>
                  </Motion.div>
                )}
              </Motion.div>
              <div className="space-y-2">
                <Label htmlFor="file-remarks">Remarks *</Label>
                <Textarea
                  id="file-remarks"
                  value={fileRemarks}
                  onChange={(e) => setFileRemarks(e.target.value)}
                  rows={4}
                  className="min-h-[100px] resize-y rounded-lg"
                  placeholder="Describe what happened and what correction you need…"
                  required
                />
              </div>
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setFileOpen(false)}>
              Cancel
            </Button>
            <Button type="button" className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS} onClick={submitFile} disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Submit correction request'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={deleteDialog.open} onOpenChange={(open) => !open && setDeleteDialog({ open: false, item: null })}>
        <DialogContent className={adminFormDialogContentClass('max-w-md')}>
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete correction request</DialogTitle>
              <p className={ADMIN_FORM_DIALOG_DESC_CLASS}>Are you sure you want to delete this request?</p>
            </DialogHeader>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
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
