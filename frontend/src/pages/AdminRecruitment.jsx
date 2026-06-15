import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  BadgeCheck,
  BriefcaseBusiness,
  CalendarDays,
  ChevronRight,
  CircleUserRound,
  ClipboardList,
  Clock,
  Download,
  Eye,
  FileCheck2,
  FileText,
  Filter,
  GraduationCap,
  Mail,
  MoreHorizontal,
  Pencil,
  Phone,
  Plus,
  RefreshCw,
  Save,
  Search,
  Send,
  Sparkles,
  Star,
  Trash2,
  UserCheck,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import {
  deleteRecruitmentApplicant,
  deleteRecruitmentExamTemplate,
  downloadRecruitmentDocument,
  getRecruitmentApplicant,
  getRecruitmentExamAssignments,
  getRecruitmentExamTemplates,
  getRecruitmentMeta,
  recruitmentHiringAction,
  recruitmentStageAction,
  saveRecruitmentApplicant,
  saveRecruitmentExamTemplate,
  saveRecruitmentInterview,
  uploadRecruitmentDocument,
} from '@/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  TAB_API_NAMES,
  applicantBelongsToTab,
  recruitmentListQueryKey,
} from '@/lib/recruitmentTabCache'
import { useRecruitmentListCache, useRecruitmentTabList } from '@/hooks/useRecruitmentTabQueries'

const TABS = [
  ['applicants', 'Applicants', Users],
  ['initial', 'Initial Interviews', ClipboardList],
  ['exams', 'Exams', GraduationCap],
  ['final', 'Final Interviews', UserCheck],
  ['requirements', 'Requirements', FileCheck2],
  ['hiring', 'Hiring Decision', BadgeCheck],
  ['hired', 'Hired', UserPlus],
  ['rejected', 'Rejected', AlertTriangle],
]

const TAB_ROUTES = {
  applicants: '/admin/recruitment/applicants',
  initial: '/admin/recruitment/initial-interviews',
  exams: '/admin/recruitment/exams',
  final: '/admin/recruitment/final-interviews',
  requirements: '/admin/recruitment/requirements',
  hiring: '/admin/recruitment/hiring-decision',
  hired: '/admin/recruitment/hired',
  rejected: '/admin/recruitment/rejected',
}

const WORKFLOW_TABS = ['initial', 'final', 'exams', 'requirements', 'hiring', 'hired', 'rejected']

const NEXT_PREFETCH_TAB = {
  applicants: 'initial',
  initial: 'exams',
  exams: 'final',
  final: 'requirements',
  requirements: 'hiring',
  hiring: 'hired',
  hired: 'rejected',
}

/** Applicants shown in each workflow tab sidebar, keyed by tab. */
const STAGE_FILTERS = {
  initial: {
    title: 'Initial interview queue',
    description: 'Applicants ready for or currently in initial interview.',
    statuses: ['New', 'For Initial Interview'],
    emptyMessage: 'No applicants in the initial interview stage yet.',
  },
  final: {
    title: 'Final interview queue',
    description: 'Applicants ready for or currently in final interview.',
    statuses: ['For Final Interview', 'Final Interview Passed'],
    emptyMessage: 'No applicants in the final interview stage yet.',
  },
  exams: {
    title: 'Exam queue',
    description: 'Applicants scheduled for or completing the exam stage.',
    statuses: ['For Exam', 'Exam Passed'],
    emptyMessage: 'No applicants in the exam stage yet.',
  },
  requirements: {
    title: 'Requirements queue',
    description: 'Applicants completing document requirements.',
    statuses: ['For Requirements'],
    emptyMessage: 'No applicants in the requirements stage yet.',
  },
  hiring: {
    title: 'Hiring approval queue',
    description: 'Applicants awaiting hiring approval.',
    statuses: ['For Hiring Approval'],
    emptyMessage: 'No applicants awaiting hiring approval yet.',
  },
  hired: {
    title: 'Hired applicants',
    description: 'Applicants successfully hired from recruitment.',
    statuses: ['Hired'],
    emptyMessage: 'No hired applicants yet.',
  },
  rejected: {
    title: 'Rejected applicants',
    description: 'Applicants who did not continue in the recruitment workflow.',
    statuses: ['Rejected'],
    emptyMessage: 'No rejected applicants yet.',
  },
}

function tabFromPath(pathname) {
  const match = Object.entries(TAB_ROUTES).find(([, route]) => pathname === route)
  return match?.[0] || 'applicants'
}

const APPLICANT_STATUSES = [
  'New',
  'For Initial Interview',
  'Initial Interview Passed',
  'For Exam',
  'Exam Passed',
  'For Final Interview',
  'Final Interview Passed',
  'For Requirements',
  'Hired',
  'Rejected',
]

const DEFAULT_META = {
  statuses: APPLICANT_STATUSES,
  document_types: ['Resume', 'Portfolio', 'NBI Clearance', 'Government ID', 'Diploma / TOR', 'Certificates', 'Birth Certificate', 'Medical', 'Other Documents'],
  document_statuses: ['Pending', 'Verified', 'Rejected'],
  interview_modes: ['Onsite', 'Online', 'Phone'],
  initial_results: ['Pending', 'Scheduled', 'Passed', 'Failed', 'Reschedule'],
  final_results: ['Passed', 'Failed', 'Hold'],
  question_types: ['Multiple Choice', 'True / False', 'Short Answer', 'Essay', 'File Upload'],
  departments: [],
  interviewers: [],
}

function blankApplicant() {
  return {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    applied_position_id: '',
    applied_position: '',
    department_id: '',
    source: '',
    status: 'New',
    date_applied: new Date().toISOString().slice(0, 10),
  }
}

function SelectBox({ className = '', children, ...props }) {
  return (
    <select
      className={cn('h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs text-slate-800 shadow-sm outline-none focus:border-orange-500 dark:border-border dark:bg-background dark:text-foreground', className)}
      {...props}
    >
      {children}
    </select>
  )
}

function statusTone(status) {
  if (status === 'Hired' || status === 'Verified' || status === 'Passed') return 'bg-emerald-100 text-emerald-700 hover:bg-emerald-100'
  if (status === 'Rejected' || status === 'Failed') return 'bg-red-100 text-red-700 hover:bg-red-100'
  if (/Pending|Review|Requirements|Exam|Interview|New/i.test(status || '')) return 'bg-amber-100 text-amber-700 hover:bg-amber-100'
  return 'bg-slate-100 text-slate-700 hover:bg-slate-100'
}

function formatDate(value) {
  if (!value) return '-'
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString('en-PH')
}

function localDateTimeParts(value) {
  if (!value) return null
  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/)
  if (!match) return null
  return {
    year: match[1],
    month: match[2],
    day: match[3],
    hour: match[4] || '',
    minute: match[5] || '',
  }
}

function todayLocalDate() {
  const pad = (part) => String(part).padStart(2, '0')
  const now = new Date()
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

function formatDateTime(value) {
  if (!value) return '-'
  const local = localDateTimeParts(value)
  if (local?.hour) {
    const date = new Date(Number(local.year), Number(local.month) - 1, Number(local.day), Number(local.hour), Number(local.minute))
    return date.toLocaleString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    })
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function toDateTimeLocalValue(value) {
  if (!value) return ''
  const local = localDateTimeParts(value)
  if (local?.hour) {
    return `${local.year}-${local.month}-${local.day}T${local.hour}:${local.minute}`
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (part) => String(part).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function formatExamAnswerValue(value) {
  if (value == null || value === '') return '-'
  if (Array.isArray(value)) return value.join(', ')
  if (typeof value !== 'string') return String(value)
  try {
    const parsed = JSON.parse(value)
    if (Array.isArray(parsed)) return parsed.join(', ')
    if (parsed && typeof parsed === 'object') return Object.values(parsed).filter(Boolean).join(', ')
    return parsed == null || parsed === '' ? '-' : String(parsed)
  } catch {
    return value
  }
}

function normalizeExamChoiceInput(value) {
  return String(value || '')
    .split(/\r?\n|,(?=\s*(?:[a-z]\s*[:.)-]|\S))/i)
    .map((choice) => choice.trim())
    .filter(Boolean)
    .map((choice, index) => {
      const match = choice.match(/^([a-z])\s*[:.)-]\s*(.+)$/i)
      if (match) {
        return `${match[1].toUpperCase()}: ${match[2].trim()}`
      }

      return `${String.fromCharCode(65 + index)}: ${choice}`
    })
}

function parseExamChoiceDraft(value) {
  const text = String(value || '')
  if (/,\s*[a-z]\s*[:.)-]/i.test(text) || /\r?\n/.test(text)) {
    return normalizeExamChoiceInput(text)
  }

  return text
    .split(/\r?\n/)
    .map((choice) => choice.trim())
    .filter(Boolean)
}

function questionChoiceText(question) {
  if (question.choice_text != null) return question.choice_text
  return (question.choices || []).join('\n')
}

function questionTypeUsesChoices(type) {
  return ['Multiple Choice', 'Checkbox'].includes(type)
}

function questionTypeUsesFixedChoices(type) {
  return type === 'True / False'
}

function questionTypeNeedsManualReview(type) {
  return ['Essay', 'File Upload'].includes(type)
}

function defaultChoicesForQuestionType(type) {
  if (type === 'True / False') return ['True', 'False']
  if (type === 'Multiple Choice' || type === 'Checkbox') return ['A: ', 'B: ', 'C: ', 'D: ']
  return []
}

function normalizeQuestionForType(question, nextType) {
  const choices = defaultChoicesForQuestionType(nextType)
  const nextQuestion = {
    ...question,
    question_type: nextType,
  }

  if (questionTypeUsesFixedChoices(nextType)) {
    return {
      ...nextQuestion,
      choices,
      correct_answer: ['True', 'False'].includes(question.correct_answer) ? question.correct_answer : '',
    }
  }

  if (questionTypeUsesChoices(nextType)) {
    return {
      ...nextQuestion,
      choices: question.choices?.length ? question.choices : choices,
    }
  }

  if (questionTypeNeedsManualReview(nextType)) {
    return {
      ...nextQuestion,
      choices: [],
      correct_answer: '',
    }
  }

  return {
    ...nextQuestion,
    choices: [],
  }
}

function examStatusLabel(applicant, assignment) {
  if (assignment?.result === 'Passed') return 'Passed'
  if (assignment?.result === 'Failed') return 'Failed'
  if (assignment?.status === 'Submitted' || assignment?.status === 'Checked') return 'Completed'
  if (assignment?.status === 'Assigned' || assignment?.status === 'In Progress') return 'Scheduled'
  if (applicant?.status === 'For Exam') return 'For Exam'
  return applicant?.exam_status || 'For Exam'
}

function examStatusTone(status) {
  if (status === 'Passed' || status === 'Completed') return 'bg-emerald-50 text-emerald-700 ring-emerald-100'
  if (status === 'Failed') return 'bg-rose-50 text-rose-700 ring-rose-100'
  if (status === 'Scheduled') return 'bg-blue-50 text-blue-700 ring-blue-100'
  return 'bg-amber-50 text-amber-700 ring-amber-100'
}

function workflowTabForStatus(status) {
  if (['New', 'For Initial Interview'].includes(status)) return 'initial'
  if (['For Exam', 'Exam Passed'].includes(status)) return 'exams'
  if (['For Final Interview', 'Final Interview Passed'].includes(status)) return 'final'
  if (['For Requirements'].includes(status)) return 'requirements'
  if (['For Hiring Approval'].includes(status)) return 'hiring'
  if (status === 'Hired') return 'hired'
  if (status === 'Rejected') return 'rejected'
  return 'applicants'
}

function filterApplicantsForStage(applicants, stage) {
  const config = STAGE_FILTERS[stage]
  if (!config) return applicants
  const allowed = new Set(config.statuses)
  return applicants.filter((applicant) => allowed.has(applicant.status))
}

function applicantHasWorkflowDetail(applicant) {
  return Boolean(applicant?.id && Array.isArray(applicant.interviews))
}

const INITIAL_INTERVIEW_EVALUATION_FIELDS = [
  ['Communication', 'Communication'],
  ['Work Experience', 'Work Experience'],
  ['Attitude', 'Attitude'],
  ['Problem Solving', 'Problem Solving'],
  ['Technical Skills', 'Technical Skills'],
  ['Overall Recommendation', 'Overall Recommendation'],
]

const INITIAL_NEXT_STEPS = ['Schedule Exam', 'Schedule Final Interview', 'Move to Requirements', 'Reject Applicant', 'Hold']

function normalizeInitialInterviewStatus(applicant) {
  const raw = applicant?.initial_interview_status || applicant?.recruitment_status || applicant?.status || 'New'
  if (/Passed|Failed/i.test(raw)) return 'Completed'
  if (/no_show/i.test(raw)) return 'No Show'
  if (/Scheduled|Reschedule/i.test(raw)) return 'Scheduled'
  if (/New/i.test(raw)) return 'New'
  return 'Pending'
}

function initialInterviewStatusTone(status) {
  if (status === 'Completed') return 'bg-blue-50 text-blue-600'
  if (status === 'No Show') return 'bg-rose-50 text-rose-600'
  if (status === 'Scheduled') return 'bg-sky-50 text-sky-600'
  if (status === 'New') return 'bg-emerald-50 text-emerald-600'
  return 'bg-amber-50 text-amber-600'
}

function initials(applicant) {
  const nameParts = String(applicant?.full_name || '').trim().split(/\s+/).filter(Boolean)
  const first = applicant?.first_name?.[0] || nameParts[0]?.[0] || ''
  const last = applicant?.last_name?.[0] || nameParts.at(-1)?.[0] || ''
  return `${first}${last}`.toUpperCase() || 'AP'
}

function splitDateTime(value) {
  if (!value) return { date: '', time: '' }
  const local = localDateTimeParts(value)
  if (local) {
    return { date: `${local.year}-${local.month}-${local.day}`, time: local.hour ? `${local.hour}:${local.minute}` : '' }
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    const [rawDate = '', rawTime = ''] = String(value).split(/[T ]/)
    return { date: rawDate, time: rawTime.slice(0, 5) }
  }

  const pad = (part) => String(part).padStart(2, '0')
  return {
    date: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
    time: `${pad(date.getHours())}:${pad(date.getMinutes())}`,
  }
}

function joinDateTime(date, time) {
  if (!date) return ''
  return `${date} ${time || '09:00'}:00`
}

function localDateTimeFromParts(date, time) {
  if (!date || !time) return null
  const parsed = new Date(`${date}T${time}`)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

function validateInterviewSchedule(form) {
  const errors = {}
  if (!form.interview_date) {
    errors.interview_date = 'Interview date is required.'
  }
  if (!form.interview_time) {
    errors.interview_time = 'Interview time is required.'
  }

  if (form.interview_date && form.interview_time) {
    const scheduledAt = localDateTimeFromParts(form.interview_date, form.interview_time)
    if (!scheduledAt) {
      errors.interview_date = 'Enter a valid interview date and time.'
    }
  }

  if (form.score !== '' && form.score != null) {
    const score = Number(form.score)
    if (Number.isNaN(score) || score < 0 || score > 100) {
      errors.score = 'Score must be between 0 and 100.'
    }
  }

  return errors
}

function validateExamSchedule(scheduleAt, expiresAt) {
  const errors = {}
  const scheduledDate = scheduleAt ? new Date(scheduleAt) : null
  const expiryDate = expiresAt ? new Date(expiresAt) : null

  if (scheduleAt && (!scheduledDate || Number.isNaN(scheduledDate.getTime()))) {
    errors.scheduleAt = 'Enter a valid exam date and time.'
  }
  if (expiresAt && (!expiryDate || Number.isNaN(expiryDate.getTime()))) {
    errors.expiresAt = 'Enter a valid link expiry date and time.'
  }
  if (scheduledDate && !Number.isNaN(scheduledDate.getTime()) && scheduledDate.getTime() < Date.now() - 60_000) {
    errors.scheduleAt = 'Exam date and time cannot be in the past.'
  }
  if (
    scheduledDate
    && expiryDate
    && !Number.isNaN(scheduledDate.getTime())
    && !Number.isNaN(expiryDate.getTime())
    && expiryDate.getTime() <= scheduledDate.getTime()
  ) {
    errors.expiresAt = 'Link expiry must be later than the exam schedule.'
  }

  return errors
}

function validateExamTemplateForm(form) {
  const errors = {}
  if (!String(form.title || '').trim()) {
    errors.title = 'Exam name is required.'
  }
  if (!Number(form.duration_minutes) || Number(form.duration_minutes) < 1) {
    errors.duration_minutes = 'Duration must be at least 1 minute.'
  }
  if (Number(form.passing_score) < 0 || Number(form.passing_score) > 100) {
    errors.passing_score = 'Passing score must be between 0 and 100.'
  }
  if (!form.questions?.length) {
    errors.questions = 'Add at least one question.'
  } else {
    const invalidQuestionIndex = form.questions.findIndex((question) => !String(question.question || '').trim())
    if (invalidQuestionIndex !== -1) {
      errors.questions = `Question ${invalidQuestionIndex + 1} needs question text.`
    }
  }

  return errors
}

function FieldError({ children }) {
  if (!children) return null
  return <p className="mt-1 text-[11px] font-semibold text-red-600">{children}</p>
}

function normalizeDateTimeLocalForApi(value) {
  if (!value) return ''
  return String(value).replace('T', ' ') + (String(value).length === 16 ? ':00' : '')
}

export default function AdminRecruitment() {
  const { toast } = useToast()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const listCache = useRecruitmentListCache()
  const [activeTab, setActiveTab] = useState(() => tabFromPath(location.pathname))
  const [meta, setMeta] = useState(DEFAULT_META)
  const [selectedApplicant, setSelectedApplicant] = useState(null)
  const [form, setForm] = useState(blankApplicant)
  const [applicantModalOpen, setApplicantModalOpen] = useState(false)
  const [editingApplicant, setEditingApplicant] = useState(null)
  const [documentModalOpen, setDocumentModalOpen] = useState(false)
  const [documentApplicant, setDocumentApplicant] = useState(null)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [applicantToDelete, setApplicantToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [saving, setSaving] = useState(false)
  const [templates, setTemplates] = useState([])
  const [assignments, setAssignments] = useState([])
  const [selectingApplicantId, setSelectingApplicantId] = useState(null)
  const [initialQueueState, setInitialQueueState] = useState({ search: '', filter: 'All' })
  const [initialized, setInitialized] = useState(false)
  const activeTabRef = useRef(activeTab)
  const tabScrollPositionsRef = useRef(new Map())
  const prefetchTimerRef = useRef(null)
  const applicantDetailCacheRef = useRef(new Map())
  const metaLoadedRef = useRef(false)
  const interviewerMetaLoadedRef = useRef(false)
  const examDataLoadedRef = useRef(false)

  const listFiltersForTab = useCallback((tab) => ({
    tab: TAB_API_NAMES[tab] || tab,
    page: 1,
    per_page: 100,
    ...(tab === 'applicants' ? { q: search, status: statusFilter } : {}),
  }), [search, statusFilter])

  const activeListFilters = useMemo(
    () => listFiltersForTab(activeTab),
    [activeTab, listFiltersForTab],
  )

  const activeListQuery = useRecruitmentTabList(activeTab, activeListFilters, {
    enabled: initialized,
  })

  const applicants = useMemo(
    () => activeListQuery.data?.applicants ?? [],
    [activeListQuery.data],
  )
  const refreshing = activeListQuery.isLoading && applicants.length === 0
  const backgroundRefreshing = activeListQuery.isFetching && applicants.length > 0

  const getTabApplicants = useCallback((tab) => {
    if (tab === activeTab) return applicants
    return listCache.getTabRows(tab, listFiltersForTab(tab))
  }, [activeTab, applicants, listCache, listFiltersForTab])

  const selectedInitial = useMemo(
    () => selectedApplicant?.interviews?.find((item) => item.interview_type === 'initial') || null,
    [selectedApplicant],
  )
  const selectedFinal = useMemo(
    () => selectedApplicant?.interviews?.find((item) => item.interview_type === 'final') || null,
    [selectedApplicant],
  )

  function cacheApplicantDetail(applicant) {
    if (!applicant?.id) return
    applicantDetailCacheRef.current.set(applicant.id, applicant)
  }

  function mergeApplicant(applicant, prepend = false) {
    if (!applicant?.id) return
    cacheApplicantDetail(applicant)
    const tab = activeTabRef.current
    listCache.setTabRows(tab, listFiltersForTab(tab), (rows) => {
      const existingIndex = rows.findIndex((row) => row.id === applicant.id)
      if (existingIndex === -1) return prepend ? [applicant, ...rows] : rows
      return rows.map((row) => (row.id === applicant.id ? { ...row, ...applicant } : row))
    })
  }

  function applyApplicant(applicant, prepend = false) {
    if (!applicant) return
    cacheApplicantDetail(applicant)
    setSelectedApplicant(applicant)
    setForm(formFromApplicant(applicant))
    mergeApplicant(applicant, prepend)
    if (documentApplicant?.id === applicant.id) {
      setDocumentApplicant(applicant)
    }
  }

  function applyStageTransition(data, fromTab) {
    const listRow = data.list_row
    if (!listRow?.id) return

    listCache.patchApplicantAcrossTabs({
      listRow,
      fromTab,
      affectedTabs: data.affected_tabs,
      listFiltersForTab,
    })

    applyApplicant(data.applicant)
    const nextTab = workflowTabForStatus(listRow.status)
    if (['hired', 'rejected'].includes(nextTab) && activeTabRef.current === fromTab) {
      openTab(nextTab)
      return
    }

    if (!applicantBelongsToTab(data.applicant, fromTab) && selectedApplicant?.id === listRow.id) {
      setSelectedApplicant(null)
    }
  }

  async function runStageAction(applicantId, payload) {
    const fromTab = activeTabRef.current
    const data = await recruitmentStageAction(applicantId, payload)
    applyStageTransition(data, fromTab)
    return data
  }

  async function loadMeta({ includeInterviewers = false } = {}) {
    const metaData = await getRecruitmentMeta(includeInterviewers ? { include_interviewers: 1 } : {})
    setMeta((current) => ({ ...DEFAULT_META, ...current, ...metaData }))
    metaLoadedRef.current = true
    if (includeInterviewers) interviewerMetaLoadedRef.current = true
  }

  async function loadExamData() {
    const [templateData, assignmentData] = await Promise.all([
      getRecruitmentExamTemplates(),
      getRecruitmentExamAssignments(),
    ])
    setTemplates(templateData.templates || [])
    setAssignments(assignmentData.assignments || [])
    examDataLoadedRef.current = true
  }

  async function ensureTabDependencies(tab, { force = false } = {}) {
    const tasks = []
    if (tab === 'applicants' && (!metaLoadedRef.current || force)) {
      tasks.push(loadMeta())
    }
    if ((tab === 'initial' || tab === 'final') && (!interviewerMetaLoadedRef.current || force)) {
      tasks.push(loadMeta({ includeInterviewers: true }))
    }
    if (tab === 'exams' && (!examDataLoadedRef.current || force)) {
      tasks.push(loadExamData())
    }
    if (tasks.length === 0) return
    await Promise.all(tasks)
  }

  async function refreshSelectedApplicant() {
    if (!selectedApplicant?.id) return
    const fresh = await getRecruitmentApplicant(selectedApplicant.id)
    applyApplicant(fresh.applicant)
    return fresh.applicant
  }

  async function refreshActiveTab({ force = false } = {}) {
    const tab = activeTabRef.current
    await ensureTabDependencies(tab, { force })
    await queryClient.refetchQueries({
      queryKey: recruitmentListQueryKey(tab, listFiltersForTab(tab)),
    })
  }

  async function load({ force = true } = {}) {
    try {
      const tasks = [refreshActiveTab({ force })]
      if (selectedApplicant?.id) tasks.push(refreshSelectedApplicant())
      await Promise.all(tasks)
    } catch (error) {
      toast({ title: 'Failed to refresh recruitment', description: error.message, variant: 'error' })
    }
  }

  useEffect(() => {
    const initialTab = tabFromPath(location.pathname)
    activeTabRef.current = initialTab
    setActiveTab(initialTab)
    Promise.all([
      loadMeta({ includeInterviewers: initialTab === 'initial' || initialTab === 'final' }),
      initialTab === 'exams' ? loadExamData() : Promise.resolve(),
    ])
      .catch((error) => toast({ title: 'Failed to load recruitment', description: error.message, variant: 'error' }))
      .finally(() => setInitialized(true))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const nextTab = tabFromPath(location.pathname)
    activeTabRef.current = nextTab
    setActiveTab(nextTab)
    if (!initialized) return
    ensureTabDependencies(nextTab)
      .catch((error) => toast({ title: 'Failed to load recruitment tab', description: error.message, variant: 'error' }))
    window.setTimeout(() => {
      window.scrollTo({ top: tabScrollPositionsRef.current.get(nextTab) || 0 })
    }, 0)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, initialized])

  useEffect(() => {
    if (!initialized || activeTabRef.current !== 'applicants') return undefined
    const timer = window.setTimeout(() => {
      listCache.invalidateTabs(['applicants'])
    }, 300)

    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, statusFilter])

  useEffect(() => {
    if (!initialized) return undefined
    const nextTab = NEXT_PREFETCH_TAB[activeTab]
    if (!nextTab) return undefined

    if (prefetchTimerRef.current) {
      window.clearTimeout(prefetchTimerRef.current)
    }
    prefetchTimerRef.current = window.setTimeout(() => {
      listCache.prefetchTab(nextTab, listFiltersForTab(nextTab))
        .catch(() => {
          // Prefetch is opportunistic; active-tab loading handles user-visible errors.
        })
    }, 800)

    return () => {
      if (prefetchTimerRef.current) {
        window.clearTimeout(prefetchTimerRef.current)
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab, initialized])

  function openTab(key) {
    tabScrollPositionsRef.current.set(activeTabRef.current, window.scrollY)
    activeTabRef.current = key
    setActiveTab(key)
    navigate(TAB_ROUTES[key] || TAB_ROUTES.applicants)
  }

  function formFromApplicant(applicant) {
    return {
      first_name: applicant?.first_name || '',
      last_name: applicant?.last_name || '',
      email: applicant?.email || '',
      phone: applicant?.phone || '',
      applied_position_id: applicant?.applied_position_id || '',
      applied_position: applicant?.applied_position || '',
      department_id: applicant?.department_id || '',
      source: applicant?.source || '',
      status: applicant?.status || 'New',
      date_applied: applicant?.date_applied || new Date().toISOString().slice(0, 10),
    }
  }

  async function handleApplicantSaved(applicant) {
    listCache.invalidateTabs([activeTabRef.current, workflowTabForStatus(applicant.status)])
    await selectApplicant(applicant, { force: true })
  }

  async function openApplicantWorkflow(applicant) {
    const tab = workflowTabForStatus(applicant.status)
    openTab(tab)
    await selectApplicant(applicant)
  }

  async function selectApplicant(applicant, { force = false } = {}) {
    if (!applicant?.id) return

    const cached = applicantDetailCacheRef.current.get(applicant.id)
    if (!force && applicantHasWorkflowDetail(applicant)) {
      applyApplicant(applicant)
      return
    }
    if (!force && cached && applicantHasWorkflowDetail(cached)) {
      applyApplicant(cached)
      return
    }

    applyApplicant(applicant)
    setSelectingApplicantId(applicant.id)
    try {
      const data = await getRecruitmentApplicant(applicant.id)
      applyApplicant(data.applicant)
    } catch (error) {
      toast({ title: 'Failed to open applicant', description: error.message, variant: 'error' })
    } finally {
      setSelectingApplicantId(null)
    }
  }

  async function saveApplicant() {
    setSaving(true)
    try {
      const data = await saveRecruitmentApplicant(form, editingApplicant?.id || null)
      applyApplicant(data.applicant, !editingApplicant?.id)
      listCache.invalidateTabs(['applicants'])
      setEditingApplicant(data.applicant)
      setApplicantModalOpen(false)
      toast({ title: editingApplicant?.id ? 'Applicant updated' : 'Applicant created', variant: 'success' })
    } catch (error) {
      toast({ title: 'Save failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  function removeApplicant(applicant) {
    setApplicantToDelete(applicant)
    setDeleteModalOpen(true)
  }

  async function confirmDeleteApplicant() {
    if (!applicantToDelete) return
    setDeleting(true)
    try {
      await deleteRecruitmentApplicant(applicantToDelete.id)
      if (selectedApplicant?.id === applicantToDelete.id) {
        setSelectedApplicant(null)
        setForm(blankApplicant())
      }
      listCache.setTabRows('applicants', listFiltersForTab('applicants'), (rows) => rows.filter((row) => row.id !== applicantToDelete.id))
      applicantDetailCacheRef.current.delete(applicantToDelete.id)
      listCache.invalidateTabs(['applicants'])
      setDeleteModalOpen(false)
      setApplicantToDelete(null)
      toast({ title: 'Applicant deleted', variant: 'success' })
    } catch (error) {
      toast({ title: 'Delete failed', description: error.message, variant: 'error' })
    } finally {
      setDeleting(false)
    }
  }

  function startNewApplicant() {
    setEditingApplicant(null)
    setForm(blankApplicant())
    setApplicantModalOpen(true)
    openTab('applicants')
  }

  function editApplicant(applicant) {
    setEditingApplicant(applicant)
    setForm(formFromApplicant(applicant))
    setApplicantModalOpen(true)
  }

  async function openDocumentModal(applicant) {
    try {
      const data = await getRecruitmentApplicant(applicant.id)
      applyApplicant(data.applicant)
      setDocumentApplicant(data.applicant)
      setDocumentModalOpen(true)
    } catch (error) {
      toast({ title: 'Failed to open documents', description: error.message, variant: 'error' })
    }
  }

  async function refreshDocumentApplicant(applicant) {
    const data = await getRecruitmentApplicant(applicant.id)
    setDocumentApplicant(data.applicant)
    applyApplicant(data.applicant)
  }

  return (
    <div className="space-y-4 text-slate-950 dark:text-foreground">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="flex items-center gap-3 text-[22px] font-extrabold tracking-tight">
            <span className="flex size-9 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-500/10">
              <BriefcaseBusiness className="size-5" strokeWidth={2.2} />
            </span>
            Recruitment {TABS.find(([key]) => key === activeTab)?.[1]}
          </h1>
          <p className="mt-1 pl-12 text-xs text-slate-500 dark:text-muted-foreground">
            Review and manage applicants across every stage of the recruitment workflow.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" className="h-9 gap-2 border-slate-200 bg-white px-4 text-xs shadow-sm" onClick={() => load({ force: true })} disabled={refreshing}>
            <RefreshCw className={cn('size-4', (refreshing || backgroundRefreshing) && 'animate-spin')} />
            Refresh
          </Button>
          <Button className="h-9 gap-2 bg-linear-to-r from-orange-600 to-orange-500 px-4 text-xs text-white shadow-[0_6px_16px_-7px_rgba(234,88,12,0.8)] hover:from-orange-700 hover:to-orange-600" onClick={startNewApplicant}>
            <Plus className="size-4" />
            New Applicant
          </Button>
        </div>
      </div>

      <div className="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm dark:border-border dark:bg-card">
        {TABS.map(([key, label, icon]) => (
          <button
            key={key}
            type="button"
            onClick={() => openTab(key)}
            className={cn(
              'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-orange-50 hover:text-orange-700 dark:text-muted-foreground dark:hover:bg-orange-500/10',
              activeTab === key && 'bg-orange-50 text-orange-600 shadow-sm dark:bg-orange-500/15 dark:text-orange-300',
            )}
          >
            {createElement(icon, { className: 'size-4' })}
            {label}
          </button>
        ))}
      </div>

      <div className="grid gap-4">
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
          <div className={activeTab === 'applicants' ? '' : 'hidden'}>
            <ApplicantsPanel
              applicants={getTabApplicants('applicants')}
              meta={meta}
              search={search}
              setSearch={setSearch}
              statusFilter={statusFilter}
              setStatusFilter={setStatusFilter}
              onSearch={() => load({ force: true })}
              onSelect={openApplicantWorkflow}
              onEdit={editApplicant}
              onDocuments={openDocumentModal}
              onDelete={removeApplicant}
              onNew={startNewApplicant}
            />
          </div>
          <div className={activeTab === 'initial' ? '' : 'hidden'}>
            <InitialInterviewModule
              applicants={getTabApplicants('initial')}
              selectedApplicant={selectedApplicant}
              selectedInterview={selectedInitial}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'initial' && refreshing}
              backgroundRefreshing={activeTab === 'initial' && backgroundRefreshing}
              meta={meta}
              queueState={initialQueueState}
              setQueueState={setInitialQueueState}
              onSelect={selectApplicant}
              onSaved={handleApplicantSaved}
              onStageAction={runStageAction}
              onViewProfile={editApplicant}
            />
          </div>
          <div className={activeTab === 'exams' ? '' : 'hidden'}>
            <ExamsPanel
              applicants={getTabApplicants('exams')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'exams' && refreshing}
              meta={meta}
              templates={templates}
              assignments={assignments}
              onSelect={selectApplicant}
              onReload={() => loadExamData()}
              onApplicantUpdated={applyApplicant}
              onStageAction={runStageAction}
            />
          </div>
          <div className={activeTab === 'final' ? '' : 'hidden'}>
            <WorkflowStageLayout
              stage="final"
              applicants={getTabApplicants('final')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'final' && refreshing}
              onSelect={selectApplicant}
            >
              <FinalInterviewModule
                applicants={getTabApplicants('final')}
                selectedApplicant={selectedApplicant}
                selectedInterview={selectedFinal}
                meta={meta}
                onStageAction={runStageAction}
                onSaved={handleApplicantSaved}
                onViewProfile={editApplicant}
              />
            </WorkflowStageLayout>
          </div>
          <div className={activeTab === 'requirements' ? '' : 'hidden'}>
            <WorkflowStageLayout
              stage="requirements"
              applicants={getTabApplicants('requirements')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'requirements' && refreshing}
              onSelect={selectApplicant}
            >
              <RequirementsPanel applicant={selectedApplicant} onUpdated={handleApplicantSaved} onStageAction={runStageAction} />
            </WorkflowStageLayout>
          </div>
          <div className={activeTab === 'hiring' ? '' : 'hidden'}>
            <WorkflowStageLayout
              stage="hiring"
              applicants={getTabApplicants('hiring')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'hiring' && refreshing}
              onSelect={selectApplicant}
            >
              <HiringPanel applicant={selectedApplicant} onUpdated={handleApplicantSaved} onStageAction={runStageAction} />
            </WorkflowStageLayout>
          </div>
          <div className={activeTab === 'hired' ? '' : 'hidden'}>
            <WorkflowStageLayout
              stage="hired"
              applicants={getTabApplicants('hired')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'hired' && refreshing}
              onSelect={selectApplicant}
            >
              <RecruitmentOutcomePanel applicant={selectedApplicant} outcome="hired" onViewProfile={editApplicant} />
            </WorkflowStageLayout>
          </div>
          <div className={activeTab === 'rejected' ? '' : 'hidden'}>
            <WorkflowStageLayout
              stage="rejected"
              applicants={getTabApplicants('rejected')}
              selectedApplicant={selectedApplicant}
              selectingApplicantId={selectingApplicantId}
              refreshing={activeTab === 'rejected' && refreshing}
              onSelect={selectApplicant}
            >
              <RecruitmentOutcomePanel applicant={selectedApplicant} outcome="rejected" onViewProfile={editApplicant} />
            </WorkflowStageLayout>
          </div>
        </section>
      </div>
      <ApplicantModal
        open={applicantModalOpen}
        onOpenChange={setApplicantModalOpen}
        form={form}
        setForm={setForm}
        meta={meta}
        saving={saving}
        editingApplicant={editingApplicant}
        onSave={saveApplicant}
      />
      <DocumentModal
        open={documentModalOpen}
        onOpenChange={setDocumentModalOpen}
        applicant={documentApplicant}
        meta={meta}
        onUploaded={refreshDocumentApplicant}
      />
      <DeleteApplicantModal
        open={deleteModalOpen}
        onOpenChange={(nextOpen) => {
          if (deleting) return
          setDeleteModalOpen(nextOpen)
          if (!nextOpen) setApplicantToDelete(null)
        }}
        applicant={applicantToDelete}
        deleting={deleting}
        onConfirm={confirmDeleteApplicant}
      />
    </div>
  )
}

function DeleteApplicantModal({ open, onOpenChange, applicant, deleting, onConfirm }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-w-md rounded-[22px] border-slate-200 bg-white shadow-[0_24px_70px_-24px_rgba(15,23,42,0.45)] dark:border-border dark:bg-card"
        innerClassName="gap-0 p-0 pr-0"
        showCloseButton={!deleting}
        closeButtonClassName="right-5 top-5 size-9 rounded-xl border-slate-200 bg-white text-slate-500 hover:bg-slate-100 dark:bg-card"
        overlayClassName="bg-slate-950/55 backdrop-blur-[3px]"
      >
        <div className="px-6 pb-5 pt-6 pr-20">
          <span className="flex size-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 ring-1 ring-red-100 dark:bg-red-500/10 dark:ring-red-500/20">
            <AlertTriangle className="size-5.5" />
          </span>
          <DialogHeader className="mt-4">
            <DialogTitle className="text-xl font-extrabold text-slate-950 dark:text-foreground">Delete applicant?</DialogTitle>
            <DialogDescription className="mt-1 text-sm leading-6">
              This permanently deletes <span className="font-bold text-slate-800 dark:text-foreground">{applicant?.full_name || 'this applicant'}</span> and their recruitment records.
            </DialogDescription>
          </DialogHeader>
          {applicant ? (
            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-border dark:bg-muted/20">
              <p className="text-xs font-bold text-slate-900 dark:text-foreground">{applicant.applicant_no || 'Applicant record'}</p>
              <p className="mt-1 text-[11px] text-slate-500">{applicant.applied_position || 'No applied position'}</p>
            </div>
          ) : null}
        </div>
        <DialogFooter className="border-t border-slate-100 bg-slate-50/80 px-6 py-4 dark:border-border dark:bg-muted/20">
          <Button type="button" variant="outline" className="h-10 rounded-xl px-5 shadow-none" onClick={() => onOpenChange(false)} disabled={deleting}>
            Cancel
          </Button>
          <Button type="button" variant="destructive" className="h-10 gap-2 rounded-xl px-5" onClick={onConfirm} disabled={deleting}>
            {deleting ? <RefreshCw className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
            {deleting ? 'Deleting...' : 'Delete applicant'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ApplicantModal({ open, onOpenChange, form, setForm, meta, saving, editingApplicant, onSave }) {
  const fieldClass = 'h-11 rounded-xl border-slate-200 bg-slate-50/60 pl-10 text-sm shadow-none transition placeholder:text-slate-400 hover:border-slate-300 focus-visible:border-orange-500 focus-visible:bg-white focus-visible:ring-orange-500/15 dark:border-border dark:bg-input/20 dark:focus-visible:border-orange-400 dark:focus-visible:bg-background'
  const selectClass = 'h-11 appearance-none rounded-xl border-slate-200 bg-slate-50/60 pl-10 pr-10 text-sm shadow-none transition hover:border-slate-300 focus:border-orange-500 focus:bg-white focus:ring-4 focus:ring-orange-500/10 dark:border-border dark:bg-input/20 dark:focus:border-orange-400 dark:focus:bg-background'

  function submit(event) {
    event.preventDefault()
    onSave()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[96dvh] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.4)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-200 bg-white text-slate-500 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card"
        overlayClassName="bg-slate-950/50 backdrop-blur-[3px]"
        surfaceStyle={{
          width: 'min(1360px, calc(100vw - 24px))',
          height: 'min(900px, calc(100dvh - 24px))',
          maxWidth: 'calc(100vw - 24px)',
        }}
      >
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
          <DialogHeader className="relative border-b border-slate-200 bg-white px-8 py-6 pr-16 dark:border-border dark:bg-card">
            <div className="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex items-start gap-4">
                <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-orange-600 text-white shadow-lg shadow-orange-500/20">
                  <UserPlus className="size-6" />
                </span>
                <div className="min-w-0">
                  <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.16em] text-orange-600">
                    Recruitment intake
                  </div>
                  <DialogTitle className="text-2xl font-black tracking-tight text-slate-950 dark:text-foreground">
                    {editingApplicant ? 'Edit applicant' : 'Add new applicant'}
                  </DialogTitle>
                  <DialogDescription className="mt-2 max-w-2xl text-xs leading-5 sm:text-sm">
                    Capture the candidate profile and application details before moving them through the recruitment workflow.
                  </DialogDescription>
                </div>
              </div>
              <span className="inline-flex w-fit items-center rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-[11px] font-bold text-slate-600 dark:border-border dark:bg-muted/20 dark:text-muted-foreground">
                Candidate profile
              </span>
            </div>
          </DialogHeader>

          <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50/60 px-8 py-6 dark:bg-background/40">
            <div className="grid gap-5 lg:grid-cols-[330px_minmax(0,1fr)]">
              <aside className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
                <span className="flex size-11 items-center justify-center rounded-2xl bg-slate-950 text-white dark:bg-orange-600">
                  <ClipboardList className="size-5" />
                </span>
                <h3 className="mt-5 text-sm font-black text-slate-950 dark:text-foreground">Guide details</h3>
                <p className="mt-2 text-xs leading-5 text-slate-500">
                  Use this form to create the candidate&apos;s recruitment record. Only the core profile is needed here; interviews, exams, and documents are handled after saving.
                </p>

                <div className="mt-6 space-y-4 border-t border-slate-100 pt-5 dark:border-border">
                  {[
                    ['1', 'Candidate identity', 'Enter the applicant name and contact information.'],
                    ['2', 'Applied role', 'Set the position and source for reporting.'],
                    ['3', 'Pipeline status', 'Keep the initial status accurate before saving.'],
                  ].map(([number, title, description]) => (
                    <div key={title} className="flex gap-3">
                      <span className="flex size-6 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-[10px] font-black text-slate-600 dark:border-border dark:bg-muted/20 dark:text-muted-foreground">
                        {number}
                      </span>
                      <div>
                        <p className="text-xs font-extrabold text-slate-800 dark:text-foreground">{title}</p>
                        <p className="mt-1 text-[11px] leading-4 text-slate-500">{description}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </aside>

              <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
                <div className="mb-6 flex items-start justify-between gap-4 border-b border-slate-100 pb-5 dark:border-border">
                  <div>
                    <h3 className="text-sm font-black text-slate-950 dark:text-foreground">Applicant information</h3>
                    <p className="mt-1 text-[11px] leading-5 text-slate-500">Candidate profile, applied role, and workflow status.</p>
                  </div>
                  <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[10px] font-extrabold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
                    <span className="text-orange-600">*</span> Required
                  </span>
                </div>

                <div className="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                  <ApplicantField label="First name" required icon={CircleUserRound}>
                    <Input id="applicant-first-name" className={fieldClass} placeholder="e.g. Maria" autoComplete="given-name" required value={form.first_name} onChange={(e) => setForm((s) => ({ ...s, first_name: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Last name" required icon={CircleUserRound}>
                    <Input id="applicant-last-name" className={fieldClass} placeholder="e.g. Santos" autoComplete="family-name" required value={form.last_name} onChange={(e) => setForm((s) => ({ ...s, last_name: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Email address" required icon={Mail}>
                    <Input id="applicant-email" className={fieldClass} type="email" placeholder="name@example.com" autoComplete="email" required value={form.email} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Phone number" required icon={Phone}>
                    <Input id="applicant-phone" className={fieldClass} type="tel" placeholder="+63 9XX XXX XXXX" autoComplete="tel" required value={form.phone} onChange={(e) => setForm((s) => ({ ...s, phone: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Applied position" required icon={BriefcaseBusiness}>
                    <Input id="applicant-position" className={fieldClass} placeholder="e.g. Software Developer" required value={form.applied_position} onChange={(e) => setForm((s) => ({ ...s, applied_position: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Application source" icon={Send}>
                    <SelectBox id="applicant-source" className={selectClass} value={form.source} onChange={(e) => setForm((s) => ({ ...s, source: e.target.value }))}>
                      <option value="">Select source</option>
                      <option value="Job Portal">Job Portal</option>
                      <option value="Referral">Referral</option>
                      <option value="Company Website">Company Website</option>
                      <option value="LinkedIn">LinkedIn</option>
                      <option value="Walk-in">Walk-in</option>
                      <option value="Other">Other</option>
                    </SelectBox>
                  </ApplicantField>
                  <ApplicantField label="Date applied" icon={CalendarDays}>
                    <Input id="applicant-date" className={fieldClass} type="date" value={form.date_applied} onChange={(e) => setForm((s) => ({ ...s, date_applied: e.target.value }))} />
                  </ApplicantField>
                  <ApplicantField label="Pipeline status" icon={BadgeCheck}>
                    <SelectBox id="applicant-status" className={selectClass} value={form.status} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value }))}>
                      {meta.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
                    </SelectBox>
                  </ApplicantField>
                </div>
              </section>
            </div>
          </div>

          <DialogFooter className="items-center border-t border-slate-100 bg-slate-50/80 px-6 py-4 sm:px-8 dark:border-border dark:bg-muted/20">
            <p className="mr-auto hidden text-[11px] text-slate-500 sm:block">You can edit these details at any time.</p>
            <Button type="button" variant="outline" className="h-10 rounded-xl border-slate-300 px-5 shadow-none" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" className="h-10 gap-2 rounded-xl bg-linear-to-r from-orange-600 to-orange-500 px-5 text-white shadow-[0_8px_18px_-9px_rgba(234,88,12,0.9)] hover:from-orange-700 hover:to-orange-600" disabled={saving}>
              {saving ? <RefreshCw className="size-4 animate-spin" /> : <Save className="size-4" />}
              {saving ? 'Saving applicant...' : editingApplicant ? 'Save changes' : 'Create applicant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function ApplicantField({ label, required = false, icon, children }) {
  return (
    <Label className="block space-y-1.5 text-xs font-bold text-slate-700 dark:text-foreground">
      <span>
        {label}
        {required ? <span className="ml-0.5 text-orange-600">*</span> : null}
      </span>
      <span className="relative block">
        <span className="pointer-events-none absolute left-3.5 top-1/2 z-10 -translate-y-1/2 text-slate-400">
          {createElement(icon, { className: 'size-4' })}
        </span>
        {children}
      </span>
    </Label>
  )
}

function ApplicantsPanel({ applicants, meta, search, setSearch, statusFilter, setStatusFilter, onSearch, onSelect, onEdit, onDocuments, onDelete, onNew }) {
  return (
    <div className="space-y-4">
      <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-3 dark:border-border dark:bg-muted/20 sm:p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="h-11 rounded-xl border-slate-200 bg-white pl-10 shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') onSearch()
              }}
              placeholder="Search applicant number, name, email, phone, or position..."
            />
          </div>
          <SelectBox className="h-11 rounded-xl border-slate-200 bg-white lg:w-64 dark:bg-background" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">All pipeline statuses</option>
            {meta.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
          </SelectBox>
          <Button className="h-11 rounded-xl bg-slate-950 px-6 text-white shadow-sm hover:bg-black dark:bg-orange-600 dark:hover:bg-orange-700" onClick={onSearch}>
            Search
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_8px_24px_-20px_rgba(15,23,42,0.45)] dark:border-border dark:bg-card">
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-border">
          <div>
            <h2 className="text-base font-extrabold text-slate-950 dark:text-foreground">Applicant directory</h2>
            <p className="mt-1 text-xs text-slate-500">Track candidates, review progress, and manage recruitment records.</p>
          </div>
          <div className="flex items-center gap-2">
            <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
              {applicants.length} {applicants.length === 1 ? 'applicant' : 'applicants'}
            </span>
            <Button size="sm" className="h-8 rounded-lg bg-orange-600 text-white hover:bg-orange-700" onClick={onNew}>
              <Plus className="size-3.5" /> Add applicant
            </Button>
          </div>
        </div>
        <div className="overflow-x-auto">
        <table className="w-full min-w-[1240px] table-fixed text-left text-xs">
          <thead className="border-b border-slate-100 bg-slate-50/90 text-[10px] uppercase tracking-[0.06em] text-slate-500 dark:border-border dark:bg-muted/40 dark:text-muted-foreground">
            <tr>
              <th className="w-[11%] px-5 py-3 font-bold">Applicant No.</th>
              <th className="w-[20%] px-4 py-3 font-bold">Applicant</th>
              <th className="w-[14%] px-4 py-3 font-bold">Contact</th>
              <th className="w-[14%] px-4 py-3 font-bold">Applied Role</th>
              <th className="w-[9%] px-4 py-3 font-bold">Status</th>
              <th className="w-[19%] px-4 py-3 font-bold">Recruitment Progress</th>
              <th className="w-[8%] px-4 py-3 font-bold">Applied</th>
              <th className="w-[5%] px-4 py-3 text-center font-bold">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-border">
            {applicants.map((applicant, index) => (
              <tr key={applicant.id} className="group transition-colors hover:bg-orange-50/40 dark:hover:bg-orange-500/5">
                <td className="px-5 py-4 align-middle">
                  <div className="font-mono text-[11px] font-bold text-slate-700 dark:text-foreground">{applicant.applicant_no}</div>
                  <div className="mt-1 text-[10px] text-slate-400">Candidate record</div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="flex items-center gap-3">
                    <span className={cn(
                      'flex size-9 shrink-0 items-center justify-center rounded-full text-[11px] font-extrabold text-white shadow-sm',
                      ['bg-slate-700', 'bg-orange-600', 'bg-blue-600', 'bg-violet-600', 'bg-emerald-600'][index % 5],
                    )}>
                      {`${applicant.first_name?.[0] || ''}${applicant.last_name?.[0] || ''}`.toUpperCase() || 'AP'}
                    </span>
                    <div className="min-w-0">
                      <div className="truncate text-[12px] font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</div>
                      <div className="mt-1 truncate text-[10px] text-slate-500">{applicant.source || 'Source not specified'}</div>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="truncate text-[11px] font-semibold text-slate-700 dark:text-foreground">{applicant.email || 'No email'}</div>
                  <div className="mt-1 truncate text-[10px] text-slate-500">{applicant.phone || 'No phone number'}</div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="text-[11px] font-bold text-slate-900 dark:text-foreground">{applicant.applied_position || 'Unassigned role'}</div>
                  <div className="mt-1 text-[10px] text-slate-500">{applicant.department_name || 'No department'}</div>
                </td>
                <td className="px-4 py-4 align-middle"><Badge className={cn('rounded-full px-2.5 py-1 text-[10px] font-bold shadow-none', statusTone(applicant.status))}>{applicant.status}</Badge></td>
                <td className="px-4 py-4 align-middle">
                  <div className="grid grid-cols-2 gap-1.5">
                    <ApplicantProgress label="Initial" value={applicant.initial_interview_status} />
                    <ApplicantProgress label="Exam" value={applicant.exam_status} />
                    <ApplicantProgress label="Final" value={applicant.final_interview_status} />
                    <ApplicantProgress label="Requirements" value={applicant.requirements_status} />
                  </div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="text-[11px] font-bold text-slate-700 dark:text-foreground">{formatDate(applicant.date_applied)}</div>
                </td>
                <td className="px-4 py-4 text-center align-middle">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="outline" size="icon-sm" className="rounded-lg border-slate-200 bg-white text-slate-500 shadow-none hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card" aria-label={`Actions for ${applicant.full_name}`}>
                        <MoreHorizontal className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52 rounded-xl p-1.5 shadow-xl">
                      <DropdownMenuLabel className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Applicant actions</DropdownMenuLabel>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onSelect(applicant)}>
                        <Eye className="size-4 text-slate-500" /> Open workflow
                      </DropdownMenuItem>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onDocuments(applicant)}>
                        <FileText className="size-4 text-orange-600" /> Manage documents
                      </DropdownMenuItem>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onEdit(applicant)}>
                        <Pencil className="size-4 text-blue-600" /> Edit applicant
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" className="rounded-lg py-2 text-xs" onClick={() => onDelete(applicant)}>
                        <Trash2 className="size-4" /> Delete applicant
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        {applicants.length === 0 ? (
          <div className="rounded-md border border-dashed border-slate-200 p-10 text-center text-sm text-slate-500">
            <div className="mx-auto mb-3 flex size-16 items-center justify-center rounded-xl bg-orange-50 text-orange-600">
              <BriefcaseBusiness className="size-8" />
            </div>
            <p className="font-bold text-slate-950">No applicants yet.</p>
            <p className="mt-1">Start by creating a new applicant.</p>
            <Button variant="outline" className="mt-4 border-orange-200 text-orange-700 hover:bg-orange-50" onClick={onNew}>
              <Plus className="mr-2 size-4" />
              New Applicant
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function ApplicantProgress({ label, value }) {
  const displayValue = value || 'Pending'
  const complete = /Passed|Completed|Verified|Hired/i.test(displayValue)
  const failed = /Failed|Rejected/i.test(displayValue)
  return (
    <div className={cn(
      'min-w-0 rounded-lg border px-2 py-1.5',
      complete && 'border-emerald-100 bg-emerald-50/80 dark:border-emerald-500/20 dark:bg-emerald-500/10',
      failed && 'border-red-100 bg-red-50/80 dark:border-red-500/20 dark:bg-red-500/10',
      !complete && !failed && 'border-slate-100 bg-slate-50 dark:border-border dark:bg-muted/30',
    )}>
      <div className="truncate text-[8px] font-bold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={cn(
        'mt-0.5 truncate text-[9px] font-bold',
        complete && 'text-emerald-700 dark:text-emerald-300',
        failed && 'text-red-700 dark:text-red-300',
        !complete && !failed && 'text-slate-600 dark:text-muted-foreground',
      )}>{displayValue}</div>
    </div>
  )
}

function DocumentModal({ open, onOpenChange, applicant, meta, onUploaded }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[96dvh] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.4)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-200 bg-white text-slate-500 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card"
        overlayClassName="bg-slate-950/50 backdrop-blur-[3px]"
        containerClassName="p-2 sm:p-3"
        surfaceStyle={{
          width: 'min(1360px, calc(100vw - 24px))',
          height: 'min(900px, calc(100dvh - 24px))',
          maxWidth: 'calc(100vw - 24px)',
        }}
      >
        <DialogHeader className="shrink-0 border-b border-slate-100 px-5 py-5 pr-20 sm:px-8 sm:py-6 dark:border-border">
          <div className="flex items-start gap-4">
            <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-orange-500 to-orange-600 text-white shadow-[0_10px_24px_-10px_rgba(234,88,12,0.75)]">
              <FileText className="size-5.5" />
            </span>
            <div>
              <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-orange-600">Applicant record</div>
              <DialogTitle className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-foreground">
                Applicant Documents
              </DialogTitle>
              <DialogDescription className="mt-1 max-w-3xl text-xs leading-5 sm:text-sm">
                Upload, review, verify, and download the recruitment requirements submitted by {applicant?.full_name || 'this applicant'}.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-8 sm:py-6">
          <DocumentsPanel applicant={applicant} meta={meta} onUploaded={onUploaded} />
        </div>
      </DialogContent>
    </Dialog>
  )
}

function DocumentsPanel({ applicant, meta, onUploaded }) {
  const { toast } = useToast()
  const [documentType, setDocumentType] = useState('Resume')
  const [status, setStatus] = useState('Pending')
  const [remarks, setRemarks] = useState('')
  const [file, setFile] = useState(null)
  const [downloadingId, setDownloadingId] = useState(null)

  if (!applicant) return <EmptySelectApplicant />

  async function upload(documentId = null) {
    try {
      await uploadRecruitmentDocument(applicant.id, { document_type: documentType, status, remarks, file }, documentId)
      setFile(null)
      setRemarks('')
      toast({ title: documentId ? 'Document replaced' : 'Document uploaded', variant: 'success' })
      await onUploaded(applicant)
    } catch (error) {
      toast({ title: 'Document failed', description: error.message, variant: 'error' })
    }
  }

  async function openDocument(document) {
    setDownloadingId(document.id)
    try {
      const blob = await downloadRecruitmentDocument(applicant.id, document.id)
      const objectUrl = URL.createObjectURL(blob)
      const link = window.document.createElement('a')
      link.href = objectUrl
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      link.download = document.file_name || 'applicant-document'
      window.document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000)
    } catch (error) {
      toast({ title: 'Download failed', description: error.message, variant: 'error' })
    } finally {
      setDownloadingId(null)
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</h2>
          <p className="mt-1 text-xs text-slate-500">{applicant.applied_position || 'Position not specified'}</p>
        </div>
        <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
          {(applicant.documents || []).length} {(applicant.documents || []).length === 1 ? 'document' : 'documents'}
        </span>
      </div>

      <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-border dark:bg-muted/20 sm:p-6">
        <div className="mb-4 border-b border-slate-200/80 pb-3 dark:border-border">
          <h3 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Upload a document</h3>
          <p className="mt-1 text-[11px] text-slate-500">Choose the requirement type, set its review status, and attach the applicant&apos;s file.</p>
        </div>
        <div className="grid gap-x-5 gap-y-4 md:grid-cols-2 xl:grid-cols-12">
          <div className="min-w-0 xl:col-span-4">
            <Label htmlFor="recruitment-document-type" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Document type</Label>
            <SelectBox id="recruitment-document-type" className="h-11 rounded-xl bg-white px-3 dark:bg-background" value={documentType} onChange={(e) => setDocumentType(e.target.value)}>
              {meta.document_types.map((type) => <option key={type}>{type}</option>)}
            </SelectBox>
          </div>
          <div className="min-w-0 xl:col-span-3">
            <Label htmlFor="recruitment-document-status" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Review status</Label>
            <SelectBox id="recruitment-document-status" className="h-11 rounded-xl bg-white px-3 dark:bg-background" value={status} onChange={(e) => setStatus(e.target.value)}>
              {meta.document_statuses.map((value) => <option key={value}>{value}</option>)}
            </SelectBox>
          </div>
          <div className="min-w-0 md:col-span-2 xl:col-span-5">
            <Label htmlFor="recruitment-document-remarks" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Remarks</Label>
            <Input id="recruitment-document-remarks" className="h-11 rounded-xl bg-white shadow-none dark:bg-background" placeholder="Optional review notes" value={remarks} onChange={(e) => setRemarks(e.target.value)} />
          </div>
          <div className="min-w-0 md:col-span-2 xl:col-span-9">
            <Label htmlFor="recruitment-document-file" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Select file</Label>
            <Input id="recruitment-document-file" className="h-11 rounded-xl bg-white py-1.5 shadow-none file:mr-3 file:rounded-lg file:bg-orange-50 file:px-3 file:text-orange-700 dark:bg-background" type="file" onChange={(e) => setFile(e.target.files?.[0] || null)} />
          </div>
          <div className="flex items-end md:col-span-2 xl:col-span-3">
            <Button className="h-11 w-full gap-2 rounded-xl bg-linear-to-r from-orange-600 to-orange-500 px-5 text-white shadow-[0_8px_18px_-9px_rgba(234,88,12,0.9)] hover:from-orange-700 hover:to-orange-600" onClick={() => upload()} disabled={!file}>
              <FileText className="size-4" /> Upload document
            </Button>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-border dark:bg-card">
        <div className="border-b border-slate-100 px-4 py-3 dark:border-border">
          <h3 className="text-sm font-extrabold">Submitted documents</h3>
          <p className="mt-0.5 text-[11px] text-slate-500">All files currently attached to this applicant.</p>
        </div>
        <div className="hidden max-h-[42vh] overflow-auto xl:block">
          <table className="w-full table-fixed text-left text-xs">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] uppercase tracking-wide text-slate-600 dark:bg-muted">
              <tr>
                <th className="w-[15%] px-4 py-3 font-bold">Document Type</th>
                <th className="w-[20%] px-4 py-3 font-bold">File Name</th>
                <th className="w-[14%] px-4 py-3 font-bold">Uploaded By</th>
                <th className="w-[13%] px-4 py-3 font-bold">Uploaded Date</th>
                <th className="w-[10%] px-4 py-3 font-bold">Status</th>
                <th className="w-[15%] px-4 py-3 font-bold">Remarks</th>
                <th className="w-[13%] px-4 py-3 font-bold">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-border">
              {(applicant.documents || []).map((doc) => (
                <tr key={doc.id} className="hover:bg-orange-50/30 dark:hover:bg-orange-500/5">
                  <td className="px-4 py-3 font-bold text-slate-900 dark:text-foreground">{doc.document_type}</td>
                  <td className="max-w-60 truncate px-4 py-3">{doc.file_name}</td>
                  <td className="px-4 py-3">{doc.uploaded_by || '-'}</td>
                  <td className="whitespace-nowrap px-4 py-3">{formatDate(doc.uploaded_date)}</td>
                  <td className="px-4 py-3"><Badge className={statusTone(doc.status)}>{doc.status}</Badge></td>
                  <td className="max-w-64 truncate px-4 py-3">{doc.remarks || '-'}</td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <button type="button" className="inline-flex items-center gap-1.5 font-semibold text-orange-700 hover:underline disabled:cursor-wait disabled:opacity-60" onClick={() => openDocument(doc)} disabled={downloadingId === doc.id}>
                      {downloadingId === doc.id ? <RefreshCw className="size-3.5 animate-spin" /> : <Download className="size-3.5" />}
                      {downloadingId === doc.id ? 'Opening...' : 'View / Download'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="divide-y divide-slate-100 xl:hidden dark:divide-border">
          {(applicant.documents || []).map((doc) => (
            <div key={doc.id} className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-xs font-extrabold text-slate-900 dark:text-foreground">{doc.document_type}</p>
                  <p className="mt-1 truncate text-[11px] text-slate-500">{doc.file_name}</p>
                </div>
                <Badge className={statusTone(doc.status)}>{doc.status}</Badge>
              </div>
              <div className="grid grid-cols-2 gap-3 text-[11px]">
                <div><span className="block text-slate-400">Uploaded by</span><span className="mt-0.5 block font-semibold">{doc.uploaded_by || '-'}</span></div>
                <div><span className="block text-slate-400">Uploaded date</span><span className="mt-0.5 block font-semibold">{formatDate(doc.uploaded_date)}</span></div>
              </div>
              {doc.remarks ? <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-600 dark:bg-muted/30 dark:text-muted-foreground">{doc.remarks}</p> : null}
              <button type="button" className="inline-flex items-center gap-1.5 text-xs font-bold text-orange-700 hover:underline disabled:cursor-wait disabled:opacity-60" onClick={() => openDocument(doc)} disabled={downloadingId === doc.id}>
                {downloadingId === doc.id ? <RefreshCw className="size-3.5 animate-spin" /> : <Download className="size-3.5" />}
                {downloadingId === doc.id ? 'Opening...' : 'View / Download'}
              </button>
            </div>
          ))}
        </div>
        {(applicant.documents || []).length === 0 ? (
          <div className="px-6 py-12 text-center text-sm text-slate-500">No documents have been uploaded for this applicant.</div>
        ) : null}
      </div>
    </div>
  )
}

function InterviewQuickResultDialog({ open, onOpenChange, stage, meta, form, setForm, saving, onSubmit }) {
  const results = stage === 'final'
    ? ['Passed', 'Failed', 'No Show', 'Rescheduled']
    : ['Passed', 'Failed', 'No Show', 'Rescheduled']
  const errors = validateInterviewSchedule(form)
  const hasErrors = Object.keys(errors).length > 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Interview result</DialogTitle>
          <DialogDescription>Record the interview outcome and schedule details.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-3">
          <Label className="text-xs font-semibold">
            Interview result
            <SelectBox className="mt-1" value={form.result} onChange={(e) => setForm((state) => ({ ...state, result: e.target.value }))}>
              {results.map((result) => <option key={result}>{result}</option>)}
            </SelectBox>
          </Label>
          <Label className="text-xs font-semibold">
            Interview score
            <Input className="mt-1 h-9" type="number" value={form.score} onChange={(e) => setForm((state) => ({ ...state, score: e.target.value }))} />
            <FieldError>{errors.score}</FieldError>
          </Label>
          <div className="grid gap-3 md:grid-cols-2">
            <Label className="text-xs font-semibold">
              Interview date
              <Input className="mt-1 h-9" type="date" value={form.interview_date} onChange={(e) => setForm((state) => ({ ...state, interview_date: e.target.value }))} />
              <FieldError>{errors.interview_date}</FieldError>
            </Label>
            <Label className="text-xs font-semibold">
              Interview time
              <Input className="mt-1 h-9" type="time" value={form.interview_time} onChange={(e) => setForm((state) => ({ ...state, interview_time: e.target.value }))} />
              <FieldError>{errors.interview_time}</FieldError>
            </Label>
          </div>
          <Label className="text-xs font-semibold">
            Interviewer
            <SelectBox className="mt-1" value={form.interviewer_id} onChange={(e) => setForm((state) => ({ ...state, interviewer_id: e.target.value }))}>
              <option value="">Select HR interviewer</option>
              {meta.interviewers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
            </SelectBox>
          </Label>
          <Label className="text-xs font-semibold">
            Remarks
            <Textarea className="mt-1" value={form.notes} onChange={(e) => setForm((state) => ({ ...state, notes: e.target.value }))} />
          </Label>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
          <Button className="bg-orange-600 text-white hover:bg-orange-700" onClick={onSubmit} disabled={saving || hasErrors}>
            {saving ? 'Saving...' : 'Save result'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function InterviewWorkflowActions({ stage, saving, validationDisabled = false, onQuickAction, onMarkDone }) {
  const actions = stage === 'final'
    ? [
      ['passed', 'Passed → Requirements', 'bg-emerald-600 hover:bg-emerald-700 text-white'],
      ['failed', 'Failed → Reject', 'bg-rose-600 hover:bg-rose-700 text-white'],
      ['no_show', 'No Show', 'border-slate-200 bg-white'],
      ['reschedule', 'Reschedule', 'border-slate-200 bg-white'],
    ]
    : [
      ['passed', 'Passed → Exam', 'bg-emerald-600 hover:bg-emerald-700 text-white'],
      ['failed', 'Failed → Reject', 'bg-rose-600 hover:bg-rose-700 text-white'],
      ['no_show', 'No Show', 'border-slate-200 bg-white'],
      ['reschedule', 'Reschedule', 'border-slate-200 bg-white'],
    ]

  return (
    <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3 dark:border-border">
      <Button type="button" className="h-9 gap-2 bg-orange-600 text-xs text-white hover:bg-orange-700" onClick={onMarkDone} disabled={saving}>
        Mark as Done
      </Button>
      {actions.map(([action, label, className]) => (
        <Button
          key={action}
          type="button"
          variant={className.includes('bg-white') ? 'outline' : undefined}
          className={cn('h-9 text-xs', className)}
          onClick={() => onQuickAction(action)}
          disabled={saving || validationDisabled}
        >
          {label}
        </Button>
      ))}
    </div>
  )
}

function InitialInterviewModule({ applicants, selectedApplicant, selectedInterview, selectingApplicantId, refreshing, backgroundRefreshing, meta, queueState, setQueueState, onSelect, onSaved, onStageAction, onViewProfile }) {
  const { toast } = useToast()
  const [saving, setSaving] = useState(false)
  const [resultModalOpen, setResultModalOpen] = useState(false)
  const [resultForm, setResultForm] = useState(() => initialInterviewForm(selectedInterview))
  const [form, setForm] = useState(() => initialInterviewForm(selectedInterview))
  const formErrors = validateInterviewSchedule(form)
  const resultFormErrors = validateInterviewSchedule(resultForm)
  const hasFormErrors = Object.keys(formErrors).length > 0
  const hasResultFormErrors = Object.keys(resultFormErrors).length > 0
  const initialApplicants = useMemo(() => filterApplicantsForStage(applicants, 'initial'), [applicants])
  const filteredApplicants = useMemo(() => {
    const query = queueState.search.trim().toLowerCase()
    return initialApplicants.filter((applicant) => {
      const status = normalizeInitialInterviewStatus(applicant)
      if (!query) return true
      return [
        applicant.full_name,
        applicant.applied_position,
        applicant.applicant_no,
        applicant.email,
        status,
      ].filter(Boolean).join(' ').toLowerCase().includes(query)
    })
  }, [initialApplicants, queueState.search])

  useEffect(() => {
    setForm(initialInterviewForm(selectedInterview))
    setResultForm(initialInterviewForm(selectedInterview))
  }, [selectedApplicant?.id, selectedInterview])

  async function submitStageAction(action, extra = {}) {
    if (!selectedApplicant?.id) return
    const errors = Object.keys(extra).length > 0 ? resultFormErrors : formErrors
    if (Object.keys(errors).length > 0) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    setSaving(true)
    try {
      const payload = {
        stage: 'initial',
        action,
        interviewer_id: form.interviewer_id || resultForm.interviewer_id || undefined,
        interview_date: joinDateTime(form.interview_date || resultForm.interview_date, form.interview_time || resultForm.interview_time),
        mode: form.mode,
        score: form.score || resultForm.score || undefined,
        notes: form.notes || resultForm.notes || undefined,
        result: extra.result,
        interview_id: selectedInterview?.id || undefined,
        evaluation: form.evaluation,
        ...extra,
      }
      await onStageAction(selectedApplicant.id, payload)
      toast({ title: 'Initial interview updated', variant: 'success' })
      setResultModalOpen(false)
    } catch (error) {
      toast({ title: 'Interview action failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  async function saveResultModal() {
    if (hasResultFormErrors) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    const action = {
      Passed: 'passed',
      Failed: 'failed',
      'No Show': 'no_show',
      Rescheduled: 'reschedule',
    }[resultForm.result] || 'mark_done'
    await submitStageAction(action, {
      result: resultForm.result === 'Rescheduled' ? 'Reschedule' : resultForm.result,
      score: resultForm.score,
      notes: resultForm.notes,
      interviewer_id: resultForm.interviewer_id,
      interview_date: joinDateTime(resultForm.interview_date, resultForm.interview_time),
    })
  }

  async function save() {
    if (!selectedApplicant?.id) return
    if (hasFormErrors) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    setSaving(true)
    try {
      const scoreValues = INITIAL_INTERVIEW_EVALUATION_FIELDS
        .filter(([key]) => key !== 'Overall Recommendation')
        .map(([key]) => Number(form.evaluation?.[key] || 0))
        .filter((value) => value > 0)
      const averageScore = scoreValues.length
        ? Math.round((scoreValues.reduce((sum, value) => sum + value, 0) / scoreValues.length) * 20)
        : ''

      const data = await saveRecruitmentInterview(selectedApplicant.id, {
        interview_type: 'initial',
        interviewer_id: form.interviewer_id,
        interview_date: joinDateTime(form.interview_date, form.interview_time),
        mode: form.mode,
        score: averageScore,
        result: form.result,
        next_step: form.next_step,
        notes: form.notes,
        evaluation: form.evaluation,
      }, selectedInterview?.id || null)
      toast({ title: 'Initial interview saved', variant: 'success' })
      await onSaved(data.applicant)
    } catch (error) {
      toast({ title: 'Interview failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="grid gap-4 xl:grid-cols-[330px_minmax(0,1fr)]">
      <aside className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        <div className="border-b border-slate-100 px-4 py-4 dark:border-border">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Initial Interview Queue</h2>
              <p className="mt-1 text-[11px] text-slate-500">Applicants for initial interview.</p>
            </div>
            {refreshing && applicants.length === 0 ? <RefreshCw className="size-4 animate-spin text-orange-500" /> : null}
            {backgroundRefreshing ? <span className="text-[10px] text-orange-500">Syncing</span> : null}
          </div>
          <div className="mt-4 flex gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-slate-400" />
              <Input
                className="h-9 rounded-md border-slate-200 bg-white pl-8 text-[11px] shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
                value={queueState.search}
                onChange={(event) => setQueueState((state) => ({ ...state, search: event.target.value }))}
                placeholder="Search applicant..."
              />
            </div>
            <Button type="button" variant="outline" size="icon-sm" className="h-9 w-9 rounded-md border-slate-200 bg-white shadow-none">
              <Filter className="size-3.5" />
            </Button>
          </div>
          <div className="mt-3 border-b border-slate-100 pb-0 dark:border-border">
            <div className="relative inline-flex px-2 pb-2 text-[11px] font-semibold text-orange-600">
              All <span className="ml-1 font-bold">{initialApplicants.length}</span>
              <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-orange-500" />
            </div>
          </div>
        </div>

        <div className="max-h-[620px] space-y-2 overflow-y-auto bg-slate-50/50 p-3 dark:bg-muted/10">
          {filteredApplicants.map((applicant, index) => {
            const selected = selectedApplicant?.id === applicant.id
            const loading = selectingApplicantId === applicant.id
            const status = normalizeInitialInterviewStatus(applicant)
            return (
              <button
                key={applicant.id}
                type="button"
                onClick={() => onSelect(applicant)}
                disabled={loading}
                className={cn(
                  'group flex w-full items-center gap-3 rounded-lg border bg-white p-3 text-left shadow-sm transition dark:bg-card',
                  selected
                    ? 'border-orange-200 bg-orange-50/70 ring-1 ring-orange-100 dark:border-orange-500/30 dark:bg-orange-500/10 dark:ring-orange-500/10'
                    : 'border-slate-100 hover:border-orange-200 hover:bg-orange-50/40 dark:border-border dark:hover:bg-orange-500/5',
                )}
              >
                <span className={cn(
                  'flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-extrabold text-white',
                  ['bg-slate-700', 'bg-orange-500', 'bg-pink-500', 'bg-blue-500', 'bg-emerald-500'][index % 5],
                )}>
                  {initials(applicant)}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-xs font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</span>
                  <span className="mt-0.5 block truncate text-[10px] text-slate-500">{applicant.applied_position || 'No position'}</span>
                  <span className="mt-0.5 block font-mono text-[9px] text-slate-400">{applicant.applicant_no}</span>
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  <span className={cn('rounded-md px-2 py-1 text-[9px] font-bold', initialInterviewStatusTone(status))}>{status}</span>
                  {loading ? <RefreshCw className="size-3.5 animate-spin text-orange-600" /> : <ChevronRight className="size-4 text-slate-400 transition group-hover:text-orange-500" />}
                </span>
              </button>
            )
          })}
          {filteredApplicants.length === 0 ? (
            <div className="rounded-lg border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-xs text-slate-500 dark:border-border dark:bg-card">
              No applicants match this queue.
            </div>
          ) : null}
        </div>
      </aside>

      <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        {selectedApplicant ? (
          <div className="flex min-h-[650px] flex-col">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-border">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="truncate text-lg font-extrabold tracking-tight text-slate-950 dark:text-foreground">
                    Initial Interview - {selectedApplicant.full_name}
                  </h2>
                  <span className={cn('rounded-md px-2 py-1 text-[10px] font-bold', initialInterviewStatusTone(normalizeInitialInterviewStatus(selectedApplicant)))}>
                    {normalizeInitialInterviewStatus(selectedApplicant)}
                  </span>
                </div>
                <p className="mt-1 text-xs text-slate-500">{selectedApplicant.applied_position || 'No position'} · {selectedApplicant.applicant_no}</p>
              </div>
              <Button type="button" variant="outline" className="h-9 gap-2 rounded-md border-slate-200 bg-white px-3 text-xs shadow-none" onClick={() => onViewProfile(selectedApplicant)}>
                <Eye className="size-3.5" />
                View Applicant Profile
              </Button>
            </div>

            <InterviewWorkflowActions
              stage="initial"
              saving={saving}
              validationDisabled={hasFormErrors}
              onMarkDone={() => {
                setResultForm(initialInterviewForm(selectedInterview))
                setResultModalOpen(true)
              }}
              onQuickAction={(action) => submitStageAction(action)}
            />

            <div className="flex-1 space-y-5 px-5 py-5">
              <div className="grid gap-4 xl:grid-cols-4">
                <InitialInterviewField label="Interview Date" required>
                  <div className="relative">
                    <Input
                      className="h-11 rounded-lg border-slate-200 bg-white pr-10 text-xs shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
                      type="date"
                      value={form.interview_date}
                      onChange={(event) => setForm((state) => ({ ...state, interview_date: event.target.value }))}
                    />
                    <CalendarDays className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                  </div>
                  <FieldError>{formErrors.interview_date}</FieldError>
                </InitialInterviewField>
                <InitialInterviewField label="Time">
                  <div className="relative">
                    <Input
                      className="h-11 rounded-lg border-slate-200 bg-white pr-10 text-xs shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
                      type="time"
                      value={form.interview_time}
                      onChange={(event) => setForm((state) => ({ ...state, interview_time: event.target.value }))}
                    />
                    <Clock className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                  </div>
                  <FieldError>{formErrors.interview_time}</FieldError>
                </InitialInterviewField>
                <InitialInterviewField label="Interviewer" required>
                  <SelectBox className="h-11 rounded-lg border-slate-200 bg-white text-xs shadow-none dark:bg-background" value={form.interviewer_id} onChange={(event) => setForm((state) => ({ ...state, interviewer_id: event.target.value }))}>
                    <option value="">Select HR interviewer</option>
                    {meta.interviewers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                  </SelectBox>
                </InitialInterviewField>
                <InitialInterviewField label="Mode">
                  <SelectBox className="h-11 rounded-lg border-slate-200 bg-white text-xs shadow-none dark:bg-background" value={form.mode} onChange={(event) => setForm((state) => ({ ...state, mode: event.target.value }))}>
                    {meta.interview_modes.map((mode) => <option key={mode}>{mode}</option>)}
                  </SelectBox>
                </InitialInterviewField>
              </div>

              <div className="grid gap-4 lg:grid-cols-2">
                <InitialInterviewField label="Result" required>
                  <SelectBox className="h-11 rounded-lg border-slate-200 bg-white text-xs shadow-none dark:bg-background" value={form.result} onChange={(event) => setForm((state) => ({ ...state, result: event.target.value }))}>
                    {meta.initial_results.map((result) => <option key={result}>{result}</option>)}
                  </SelectBox>
                </InitialInterviewField>
                <InitialInterviewField label="Next Step">
                  <SelectBox className="h-11 rounded-lg border-slate-200 bg-white text-xs shadow-none dark:bg-background" value={form.next_step} onChange={(event) => setForm((state) => ({ ...state, next_step: event.target.value }))}>
                    <option value="">Select next step</option>
                    {INITIAL_NEXT_STEPS.map((step) => <option key={step}>{step}</option>)}
                  </SelectBox>
                </InitialInterviewField>
              </div>

              <InitialInterviewField label="Notes">
                <Textarea
                  className="min-h-24 rounded-lg border-slate-200 bg-white text-xs shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
                  value={form.notes}
                  onChange={(event) => setForm((state) => ({ ...state, notes: event.target.value }))}
                  placeholder="Enter notes about the interview..."
                />
              </InitialInterviewField>

              <section className="rounded-lg border border-slate-100 bg-white p-4 dark:border-border dark:bg-card">
                <div className="mb-4">
                  <h3 className="text-sm font-extrabold text-slate-950 dark:text-foreground">
                    Interview Evaluation <span className="font-medium text-slate-400">(Optional)</span>
                  </h3>
                </div>
                <div className="grid gap-x-10 gap-y-5 lg:grid-cols-2">
                  {INITIAL_INTERVIEW_EVALUATION_FIELDS.map(([key, label]) => (
                    key === 'Overall Recommendation' ? (
                      <InitialInterviewField key={key} label={label}>
                        <SelectBox className="h-10 rounded-lg border-slate-200 bg-white text-xs shadow-none dark:bg-background" value={form.evaluation?.[key] || ''} onChange={(event) => setForm((state) => ({ ...state, evaluation: { ...(state.evaluation || {}), [key]: event.target.value } }))}>
                          <option value="">Select recommendation</option>
                          <option value="Highly Recommended">Highly Recommended</option>
                          <option value="Recommended">Recommended</option>
                          <option value="For Consideration">For Consideration</option>
                          <option value="Not Recommended">Not Recommended</option>
                        </SelectBox>
                      </InitialInterviewField>
                    ) : (
                      <StarRatingField
                        key={key}
                        label={label}
                        value={Number(form.evaluation?.[key] || 0)}
                        onChange={(value) => setForm((state) => ({ ...state, evaluation: { ...(state.evaluation || {}), [key]: value } }))}
                      />
                    )
                  ))}
                </div>
              </section>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/80 px-5 py-4 dark:border-border dark:bg-muted/20">
              <Button type="button" variant="outline" className="h-10 rounded-md border-slate-200 bg-white px-5 text-xs shadow-none" onClick={() => setForm(initialInterviewForm(selectedInterview))} disabled={saving}>
                Cancel
              </Button>
              <Button type="button" className="h-10 gap-2 rounded-md bg-orange-600 px-5 text-xs font-bold text-white shadow-[0_8px_20px_-10px_rgba(234,88,12,0.8)] hover:bg-orange-700" onClick={save} disabled={saving || hasFormErrors}>
                {saving ? <RefreshCw className="size-4 animate-spin" /> : <Save className="size-4" />}
                {saving ? 'Saving Interview...' : 'Save Interview'}
              </Button>
            </div>
          </div>
        ) : (
          <EmptySelectApplicant stage="initial" />
        )}
      </section>
      <InterviewQuickResultDialog
        open={resultModalOpen}
        onOpenChange={setResultModalOpen}
        stage="initial"
        meta={meta}
        form={resultForm}
        setForm={setResultForm}
        saving={saving}
        onSubmit={saveResultModal}
      />
    </div>
  )
}

function FinalInterviewModule({ selectedApplicant, selectedInterview, meta, onStageAction, onViewProfile }) {
  const { toast } = useToast()
  const [saving, setSaving] = useState(false)
  const [resultModalOpen, setResultModalOpen] = useState(false)
  const [resultForm, setResultForm] = useState(() => initialInterviewForm(selectedInterview))
  const [form, setForm] = useState(() => initialInterviewForm(selectedInterview))
  const formErrors = validateInterviewSchedule(form)
  const resultFormErrors = validateInterviewSchedule(resultForm)
  const hasFormErrors = Object.keys(formErrors).length > 0
  const hasResultFormErrors = Object.keys(resultFormErrors).length > 0

  useEffect(() => {
    setForm(initialInterviewForm(selectedInterview))
    setResultForm(initialInterviewForm(selectedInterview))
  }, [selectedApplicant?.id, selectedInterview])

  if (!selectedApplicant) return <EmptySelectApplicant stage="final" />

  async function submitStageAction(action, extra = {}) {
    const errors = Object.keys(extra).length > 0 ? resultFormErrors : formErrors
    if (Object.keys(errors).length > 0) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    setSaving(true)
    try {
      await onStageAction(selectedApplicant.id, {
        stage: 'final',
        action,
        interviewer_id: form.interviewer_id || resultForm.interviewer_id || undefined,
        interview_date: joinDateTime(form.interview_date || resultForm.interview_date, form.interview_time || resultForm.interview_time),
        mode: form.mode,
        score: form.score || resultForm.score || undefined,
        notes: form.notes || resultForm.notes || undefined,
        interview_id: selectedInterview?.id || undefined,
        ...extra,
      })
      toast({ title: 'Final interview updated', variant: 'success' })
      setResultModalOpen(false)
    } catch (error) {
      toast({ title: 'Interview action failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  async function saveResultModal() {
    if (hasResultFormErrors) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    const action = {
      Passed: 'passed',
      Failed: 'failed',
      'No Show': 'no_show',
      Rescheduled: 'reschedule',
    }[resultForm.result] || 'mark_done'
    await submitStageAction(action, {
      result: resultForm.result === 'Rescheduled' ? 'Reschedule' : resultForm.result,
      score: resultForm.score,
      notes: resultForm.notes,
      interviewer_id: resultForm.interviewer_id,
      interview_date: joinDateTime(resultForm.interview_date, resultForm.interview_time),
    })
  }

  return (
    <div className="space-y-0">
      <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-border">
        <div>
          <h2 className="text-lg font-extrabold">Final Interview - {selectedApplicant.full_name}</h2>
          <p className="mt-1 text-xs text-slate-500">{selectedApplicant.applied_position || 'No position'} · {selectedApplicant.applicant_no}</p>
        </div>
        <Button type="button" variant="outline" className="h-9 gap-2" onClick={() => onViewProfile(selectedApplicant)}>
          <Eye className="size-3.5" />
          View Applicant Profile
        </Button>
      </div>
      <InterviewWorkflowActions
        stage="final"
        saving={saving}
        validationDisabled={hasFormErrors}
        onMarkDone={() => {
          setResultForm(initialInterviewForm(selectedInterview))
          setResultModalOpen(true)
        }}
        onQuickAction={(action) => submitStageAction(action)}
      />
      <div className="space-y-4 px-5 py-5">
        <div className="grid gap-3 md:grid-cols-3">
          <InitialInterviewField label="Interview Date">
            <Input type="date" className="h-10" value={form.interview_date} onChange={(e) => setForm((state) => ({ ...state, interview_date: e.target.value }))} />
            <FieldError>{formErrors.interview_date}</FieldError>
          </InitialInterviewField>
          <InitialInterviewField label="Time">
            <Input type="time" className="h-10" value={form.interview_time} onChange={(e) => setForm((state) => ({ ...state, interview_time: e.target.value }))} />
            <FieldError>{formErrors.interview_time}</FieldError>
          </InitialInterviewField>
          <InitialInterviewField label="Interviewer">
            <SelectBox className="h-10" value={form.interviewer_id} onChange={(e) => setForm((state) => ({ ...state, interviewer_id: e.target.value }))}>
              <option value="">Select HR interviewer</option>
              {meta.interviewers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
            </SelectBox>
          </InitialInterviewField>
        </div>
        <InitialInterviewField label="Final interview notes">
          <Textarea value={form.notes} onChange={(e) => setForm((state) => ({ ...state, notes: e.target.value }))} />
        </InitialInterviewField>
      </div>
      <InterviewQuickResultDialog
        open={resultModalOpen}
        onOpenChange={setResultModalOpen}
        stage="final"
        meta={meta}
        form={resultForm}
        setForm={setResultForm}
        saving={saving}
        onSubmit={saveResultModal}
      />
    </div>
  )
}

function initialInterviewForm(interview) {
  const split = splitDateTime(interview?.interview_date)
  return {
    interviewer_id: interview?.interviewer_id || '',
    interview_date: split.date || todayLocalDate(),
    interview_time: split.time || '09:00',
    mode: interview?.mode || 'Onsite',
    result: interview?.result || 'Pending',
    next_step: interview?.next_step || '',
    notes: interview?.notes || '',
    score: interview?.score ?? '',
    evaluation: interview?.evaluation || {},
  }
}

function InitialInterviewField({ label, required = false, children }) {
  return (
    <Label className="block space-y-1.5 text-xs font-bold text-slate-700 dark:text-foreground">
      <span>
        {label}
        {required ? <span className="ml-1 text-orange-600">*</span> : null}
      </span>
      {children}
    </Label>
  )
}

function StarRatingField({ label, value, onChange }) {
  return (
    <div>
      <p className="mb-2 text-xs font-semibold text-slate-700 dark:text-foreground">{label}</p>
      <div className="flex gap-2">
        {[1, 2, 3, 4, 5].map((rating) => (
          <button
            key={rating}
            type="button"
            onClick={() => onChange(value === rating ? 0 : rating)}
            className="rounded p-0.5 text-slate-400 transition hover:text-orange-500"
            aria-label={`${label} ${rating} star`}
          >
            <Star className={cn('size-4', value >= rating && 'fill-orange-500 text-orange-500')} />
          </button>
        ))}
      </div>
    </div>
  )
}

function InterviewPanel({ applicant, interview, meta, type, onSaved }) {
  const { toast } = useToast()
  const isFinal = type === 'final'
  const [form, setForm] = useState(() => ({
    interview_type: type,
    interviewer_id: interview?.interviewer_id || '',
    interview_date: toDateTimeLocalValue(interview?.interview_date),
    mode: interview?.mode || 'Onsite',
    score: interview?.score ?? '',
    notes: interview?.notes || '',
    result: interview?.result || '',
    next_step: interview?.next_step || '',
    evaluation: interview?.evaluation || {},
  }))
  const splitInterviewDate = splitDateTime(form.interview_date)
  const panelValidationForm = {
    ...form,
    interview_date: splitInterviewDate.date,
    interview_time: splitInterviewDate.time,
  }
  const formErrors = validateInterviewSchedule(panelValidationForm)
  const hasFormErrors = Object.keys(formErrors).length > 0

  if (!applicant) return <EmptySelectApplicant />

  const evaluationFields = isFinal
    ? ['Technical Fit', 'Culture Fit', 'Role Understanding', 'Compensation Alignment', 'Availability', 'Final Decision']
    : ['Communication', 'Work Experience', 'Attitude', 'Availability', 'Salary Expectation', 'Overall Recommendation']

  async function save() {
    if (hasFormErrors) {
      toast({ title: 'Fix interview validation errors first', variant: 'error' })
      return
    }
    try {
      const data = await saveRecruitmentInterview(applicant.id, {
        ...form,
        interview_date: normalizeDateTimeLocalForApi(form.interview_date),
      }, interview?.id || null)
      toast({ title: `${isFinal ? 'Final' : 'Initial'} interview saved`, variant: 'success' })
      await onSaved(data.applicant)
    } catch (error) {
      toast({ title: 'Interview failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">{isFinal ? 'Final Interview' : 'Initial Interview'} - {applicant.full_name}</h2>
      <div className="grid gap-3 md:grid-cols-3">
        <Label className="text-xs font-semibold">Interview Date<Input className="mt-1 h-9" type="datetime-local" value={form.interview_date} onChange={(e) => setForm((s) => ({ ...s, interview_date: e.target.value }))} /><FieldError>{formErrors.interview_date || formErrors.interview_time}</FieldError></Label>
        <Label className="text-xs font-semibold">Interviewer<SelectBox className="mt-1" value={form.interviewer_id} onChange={(e) => setForm((s) => ({ ...s, interviewer_id: e.target.value }))}><option value="">Select HR interviewer</option>{meta.interviewers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Mode<SelectBox className="mt-1" value={form.mode} onChange={(e) => setForm((s) => ({ ...s, mode: e.target.value }))}>{meta.interview_modes.map((mode) => <option key={mode}>{mode}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Score<Input className="mt-1 h-9" type="number" value={form.score} onChange={(e) => setForm((s) => ({ ...s, score: e.target.value }))} /></Label>
        <Label className="text-xs font-semibold">Result<SelectBox className="mt-1" value={form.result} onChange={(e) => setForm((s) => ({ ...s, result: e.target.value }))}><option value="">Pending</option>{(isFinal ? meta.final_results : meta.initial_results).map((result) => <option key={result}>{result}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Next Step<Input className="mt-1 h-9" value={form.next_step} onChange={(e) => setForm((s) => ({ ...s, next_step: e.target.value }))} /></Label>
        <Label className="text-xs font-semibold md:col-span-3">Notes<Textarea className="mt-1" value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} /></Label>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {evaluationFields.map((field) => (
          <Label key={field} className="text-xs font-semibold">
            {field}
            <Input className="mt-1 h-9" value={form.evaluation?.[field] || ''} onChange={(e) => setForm((s) => ({ ...s, evaluation: { ...(s.evaluation || {}), [field]: e.target.value } }))} />
          </Label>
        ))}
      </div>
      <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={save} disabled={hasFormErrors}><Save className="size-4" /> Save Interview</Button>
    </div>
  )
}

function ExamsPanel({ applicants, selectedApplicant, selectingApplicantId, refreshing, meta, templates, assignments, onSelect, onReload, onApplicantUpdated, onStageAction }) {
  const { toast } = useToast()
  const [acting, setActing] = useState(false)
  const [queueSearch, setQueueSearch] = useState('')
  const [templateModalOpen, setTemplateModalOpen] = useState(false)
  const [actionsModalOpen, setActionsModalOpen] = useState(false)
  const [answersModalOpen, setAnswersModalOpen] = useState(false)
  const [deleteTemplateId, setDeleteTemplateId] = useState('')
  const [deleteTemplateModalOpen, setDeleteTemplateModalOpen] = useState(false)
  const [deletingTemplate, setDeletingTemplate] = useState(false)
  const [assignTemplateId, setAssignTemplateId] = useState('')
  const [scheduleAt, setScheduleAt] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const examScheduleErrors = validateExamSchedule(scheduleAt, expiresAt)
  const hasExamScheduleErrors = Object.keys(examScheduleErrors).length > 0
  const [templateForm, setTemplateForm] = useState({
    title: '',
    category: 'Technical Assessment',
    department_id: '',
    position_id: '',
    duration_minutes: 60,
    passing_score: 70,
    instructions: '',
    settings: {
      show_correct_answers: 'Never',
      randomize_questions: false,
      randomize_choices: false,
      allow_retake: false,
      maximum_attempts: 1,
      auto_grade: true,
      manual_review_required: false,
    },
    status: 'Active',
    questions: [],
  })
  const templateFormErrors = validateExamTemplateForm(templateForm)
  const hasTemplateFormErrors = Object.keys(templateFormErrors).length > 0

  const assignmentByApplicant = useMemo(() => {
    const map = new Map()
    assignments.forEach((assignment) => {
      if (!map.has(assignment.applicant_id)) {
        map.set(assignment.applicant_id, assignment)
      }
    })
    return map
  }, [assignments])

  const stageApplicants = useMemo(() => filterApplicantsForStage(applicants, 'exams'), [applicants])
  const filteredApplicants = useMemo(() => {
    const query = queueSearch.trim().toLowerCase()
    if (!query) return stageApplicants
    return stageApplicants.filter((applicant) => [
      applicant.applicant_no,
      applicant.full_name,
      applicant.email,
      applicant.applied_position,
      applicant.status,
      examStatusLabel(applicant, assignmentByApplicant.get(applicant.id)),
    ].filter(Boolean).join(' ').toLowerCase().includes(query))
  }, [assignmentByApplicant, queueSearch, stageApplicants])

  const activeApplicant = selectedApplicant || filteredApplicants[0] || null
  const selectedAssignment = activeApplicant ? assignmentByApplicant.get(activeApplicant.id) || activeApplicant.exam_assignments?.[0] || null : null
  const scheduledCount = stageApplicants.filter((row) => ['Scheduled', 'For Exam'].includes(examStatusLabel(row, assignmentByApplicant.get(row.id)))).length
  const completedCount = stageApplicants.filter((row) => ['Completed', 'Passed', 'Failed'].includes(examStatusLabel(row, assignmentByApplicant.get(row.id)))).length
  const activeStatus = examStatusLabel(activeApplicant, selectedAssignment)
  const examAnswers = selectedAssignment?.answers || []
  const answeredCount = examAnswers.length
  const possiblePoints = examAnswers.reduce((sum, answer) => sum + Number(answer.points || 0), 0)
  const earnedPoints = examAnswers.reduce((sum, answer) => sum + Number(answer.score || 0), 0)
  const correctAnswers = examAnswers.filter((answer) => Number(answer.points || 0) > 0 && Number(answer.score || 0) >= Number(answer.points || 0)).length
  const computedPercentage = possiblePoints > 0 ? Math.round((earnedPoints / possiblePoints) * 100) : null
  const resultScore = selectedAssignment?.score != null ? Number(selectedAssignment.score) : computedPercentage
  const resultStatus = selectedAssignment?.result || (selectedAssignment?.submitted_at ? 'Pending Review' : '-')
  const totalQuestions = selectedAssignment?.questions_count || answeredCount || 0
  const examLink = selectedAssignment?.exam_link_token
    ? `${window.location.origin}/recruitment/exam/${selectedAssignment.exam_link_token}`
    : ''

  useEffect(() => {
    setScheduleAt(toDateTimeLocalValue(selectedAssignment?.scheduled_at || selectedAssignment?.expires_at || ''))
    setExpiresAt(toDateTimeLocalValue(selectedAssignment?.expires_at || ''))
  }, [selectedAssignment?.expires_at, selectedAssignment?.scheduled_at])

  async function runExamAction(action, applicantId = activeApplicant?.id, extra = {}) {
    if (!applicantId) {
      toast({ title: 'Select an applicant first', variant: 'error' })
      return
    }
    setActing(true)
    try {
      const data = await onStageAction(applicantId, { stage: 'exam', action, ...extra })
      onApplicantUpdated(data.applicant)
      toast({ title: 'Exam action saved', variant: 'success' })
      await onReload()
    } catch (error) {
      toast({ title: 'Exam action failed', description: error.message, variant: 'error' })
    } finally {
      setActing(false)
    }
  }

  async function rescheduleExam() {
    if (hasExamScheduleErrors) {
      toast({ title: 'Fix exam schedule validation errors first', variant: 'error' })
      return
    }
    await runExamAction('reschedule', activeApplicant?.id, {
      scheduled_at: scheduleAt ? new Date(scheduleAt).toISOString() : undefined,
      expires_at: expiresAt ? new Date(expiresAt).toISOString() : undefined,
    })
  }

  async function createExam() {
    if (hasTemplateFormErrors) {
      toast({ title: 'Fix exam template validation errors first', variant: 'error' })
      return
    }
    try {
      const payload = {
        ...templateForm,
        questions: templateForm.questions.map((question) => {
          if (questionTypeUsesFixedChoices(question.question_type)) {
            return { ...question, choices: ['True', 'False'] }
          }

          if (questionTypeUsesChoices(question.question_type)) {
            return { ...question, choices: normalizeExamChoiceInput((question.choices || []).join('\n')) }
          }

          return {
            ...question,
            choices: [],
            correct_answer: questionTypeNeedsManualReview(question.question_type) ? '' : question.correct_answer,
          }
        }),
      }
      await saveRecruitmentExamTemplate(payload)
      setTemplateForm({
        title: '',
        category: 'Technical Assessment',
        department_id: '',
        position_id: '',
        duration_minutes: 60,
        passing_score: 70,
        instructions: '',
        settings: {
          show_correct_answers: 'Never',
          randomize_questions: false,
          randomize_choices: false,
          allow_retake: false,
          maximum_attempts: 1,
          auto_grade: true,
          manual_review_required: false,
        },
        status: 'Active',
        questions: [],
      })
      setTemplateModalOpen(false)
      toast({ title: 'Exam created', variant: 'success' })
      await onReload()
    } catch (error) {
      toast({ title: 'Create exam failed', description: error.message, variant: 'error' })
    }
  }

  function openDeleteTemplateModal() {
    if (!templateToDelete) {
      toast({ title: 'Select an exam template first', variant: 'error' })
      return
    }

    setDeleteTemplateModalOpen(true)
  }

  async function confirmDeleteExamTemplate() {
    if (!templateToDelete) return
    setDeletingTemplate(true)
    try {
      const data = await deleteRecruitmentExamTemplate(templateToDelete.id)
      toast({
        title: 'Exam template deleted',
        description: data.deleted_assignments ? `${data.deleted_assignments} assigned exam record(s) were removed.` : undefined,
        variant: 'success',
      })
      setDeleteTemplateId('')
      setDeleteTemplateModalOpen(false)
      if (String(assignTemplateId) === String(templateToDelete.id)) {
        setAssignTemplateId('')
      }
      await onReload()
    } catch (error) {
      toast({ title: 'Delete exam failed', description: error.message, variant: 'error' })
    } finally {
      setDeletingTemplate(false)
    }
  }

  function addTemplateQuestion() {
    setTemplateForm((state) => ({
      ...state,
      questions: [
        ...state.questions,
        { question_type: 'Multiple Choice', question: '', choices: [], choice_text: '', correct_answer: '', points: 1, difficulty: 'Medium', category: 'Custom' },
      ],
    }))
  }

  function updateTemplateQuestion(index, patch) {
    setTemplateForm((state) => ({
      ...state,
      questions: state.questions.map((question, questionIndex) => (
        questionIndex === index
          ? patch.question_type
            ? normalizeQuestionForType(question, patch.question_type)
            : { ...question, ...patch }
          : question
      )),
    }))
  }

  function removeTemplateQuestion(index) {
    setTemplateForm((state) => ({
      ...state,
      questions: state.questions.filter((_, questionIndex) => questionIndex !== index),
    }))
  }

  function updateExamSetting(key, value) {
    setTemplateForm((state) => ({ ...state, settings: { ...(state.settings || {}), [key]: value } }))
  }

  async function copyExamLink() {
    if (!examLink) return
    await navigator.clipboard?.writeText(examLink)
    toast({ title: 'Exam link copied', variant: 'success' })
  }

  const templateToDelete = templates.find((template) => String(template.id) === String(deleteTemplateId))
  const selectedTemplateTitle = templates.find((template) => String(template.id) === String(assignTemplateId || selectedAssignment?.exam_template_id))?.title
    || selectedAssignment?.exam_title
    || 'Applicant Exam'
  const actionCards = [
    {
      title: 'Send Exam Link',
      description: 'Send the exam link to the applicant via email.',
      icon: Send,
      tone: 'orange',
      action: () => runExamAction('start'),
      disabled: !selectedAssignment,
    },
    {
      title: 'Mark as Completed',
      description: 'Manually mark the exam as completed.',
      icon: BadgeCheck,
      tone: 'yellow',
      action: () => runExamAction('complete'),
      disabled: !selectedAssignment,
    },
    {
      title: 'Passed',
      description: 'Mark the applicant as passed.',
      icon: BadgeCheck,
      tone: 'green',
      action: () => runExamAction('passed'),
      disabled: !selectedAssignment,
    },
    {
      title: 'Move to Final Interview',
      description: 'Move this applicant to the final interview stage.',
      icon: UserCheck,
      tone: 'emerald',
      action: () => runExamAction('passed'),
      disabled: !selectedAssignment,
    },
    {
      title: 'Failed / Reject Applicant',
      description: 'Mark as failed and reject this applicant.',
      icon: AlertTriangle,
      tone: 'red',
      action: () => runExamAction('failed'),
      disabled: !selectedAssignment,
    },
  ]

  return (
    <>
    <div className="grid gap-4 xl:grid-cols-[minmax(260px,300px)_minmax(0,1fr)]">
      <aside className="overflow-hidden rounded-[18px] border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        <div className="border-b border-slate-100 px-4 py-4 dark:border-border">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Exam Queue</h2>
              <p className="mt-1 text-[11px] leading-5 text-slate-500">Applicants scheduled for or completing the exam stage.</p>
            </div>
            {refreshing && applicants.length === 0 ? <RefreshCw className="size-4 animate-spin text-orange-500" /> : null}
          </div>
          <div className="mt-4 flex gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-slate-400" />
              <Input
                className="h-9 rounded-md border-slate-200 bg-white pl-8 text-[11px] shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
                value={queueSearch}
                onChange={(event) => setQueueSearch(event.target.value)}
                placeholder="Search this queue..."
              />
            </div>
            <Button type="button" variant="outline" size="icon-sm" className="h-9 w-9 rounded-md border-slate-200 bg-white shadow-none">
              <Filter className="size-3.5" />
            </Button>
          </div>
          <div className="mt-3 grid grid-cols-3 gap-1 border-b border-slate-100 dark:border-border">
            {[
              ['All', stageApplicants.length],
              ['Scheduled', scheduledCount],
              ['Completed', completedCount],
            ].map(([label, count], index) => (
              <div key={label} className={cn('relative px-2 pb-2 text-center text-[11px] font-semibold', index === 0 ? 'text-orange-600' : 'text-slate-500')}>
                {label} <span className="font-bold">{count}</span>
                {index === 0 ? <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-orange-500" /> : null}
              </div>
            ))}
          </div>
        </div>

        <div className="max-h-[650px] space-y-2 overflow-y-auto bg-white p-3 dark:bg-card">
          {filteredApplicants.map((row) => {
            const selected = activeApplicant?.id === row.id
            const loading = selectingApplicantId === row.id
            const assignment = assignmentByApplicant.get(row.id)
            const status = examStatusLabel(row, assignment)
            return (
              <button
                key={row.id}
                type="button"
                onClick={() => onSelect(row)}
                disabled={loading}
                className={cn(
                  'group w-full rounded-xl border p-3 text-left transition dark:bg-card',
                  selected
                    ? 'border-orange-200 bg-orange-50/70 shadow-sm dark:border-orange-500/30 dark:bg-orange-500/10'
                    : 'border-transparent bg-white hover:border-orange-100 hover:bg-orange-50/30 dark:border-border dark:hover:bg-orange-500/5',
                )}
              >
                <div className="flex items-center gap-3">
                  <span className={cn('flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-extrabold', selected ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-500')}>
                    {initials(row)}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-xs font-extrabold text-slate-950 dark:text-foreground">{row.full_name}</span>
                    <span className="mt-0.5 block truncate text-[10px] text-slate-500">{row.applied_position || 'No position'}</span>
                    <span className="mt-0.5 block font-mono text-[9px] text-slate-400">{row.applicant_no}</span>
                  </span>
                  <span className="flex shrink-0 items-center gap-2">
                    <span className={cn('rounded-md px-2 py-1 text-[9px] font-bold ring-1', examStatusTone(status))}>{status}</span>
                    {loading ? <RefreshCw className="size-3.5 animate-spin text-orange-600" /> : <ChevronRight className="size-4 text-slate-400 transition group-hover:text-orange-500" />}
                  </span>
                </div>
              </button>
            )
          })}
          {filteredApplicants.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-xs text-slate-500 dark:border-border dark:bg-card">
              No applicants in the exam queue.
            </div>
          ) : null}
        </div>
        <div className="flex items-center justify-center gap-2 border-t border-slate-100 px-4 py-4 dark:border-border">
          <Button type="button" variant="outline" size="icon-sm" className="size-8 rounded-lg border-slate-200 bg-white text-slate-500">
            <ChevronRight className="size-3 rotate-180" />
          </Button>
          <span className="flex size-8 items-center justify-center rounded-lg bg-orange-600 text-[11px] font-extrabold text-white">1</span>
          <Button type="button" variant="outline" size="icon-sm" className="size-8 rounded-lg border-slate-200 bg-white text-slate-500">
            <ChevronRight className="size-3" />
          </Button>
        </div>
      </aside>

      <section className="overflow-hidden rounded-[18px] border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        {activeApplicant ? (
          <>
            <div className="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-border">
              <div className="flex min-w-0 items-center gap-4">
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-orange-50 text-sm font-extrabold text-orange-700">{initials(activeApplicant)}</span>
                <div className="min-w-0">
                  <h2 className="truncate text-lg font-extrabold text-slate-950 dark:text-foreground">{activeApplicant.full_name}</h2>
                  <p className="mt-1 truncate text-[11px] text-slate-500">{activeApplicant.applied_position || 'No position'} <span className="px-2">/</span> {activeApplicant.applicant_no}</p>
                </div>
              </div>
              <span className={cn('rounded-md px-2.5 py-1 text-[10px] font-bold ring-1', examStatusTone(activeStatus))}>{activeStatus}</span>
            </div>

            <div className="space-y-6 px-5 py-5">
              <section>
                <div className="mb-3 flex items-center justify-between gap-3">
                  <div>
                    <h3 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Exam Overview</h3>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="outline" className="h-9 gap-2 rounded-lg border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50" onClick={() => setActionsModalOpen(true)} disabled={!activeApplicant}>
                      <ClipboardList className="size-3.5" />
                      Exam Actions
                    </Button>
                    <Button variant="outline" className="h-9 gap-2 rounded-lg border-slate-200 bg-white px-3 text-xs" onClick={() => setTemplateModalOpen(true)}>
                      <Plus className="size-3.5" />
                      Create Exam
                    </Button>
                    <Button variant="outline" className="h-9 gap-2 rounded-lg border-slate-200 bg-white px-3 text-xs" onClick={rescheduleExam} disabled={acting || !selectedAssignment}>
                      <CalendarDays className="size-3.5" />
                      Reschedule Exam
                    </Button>
                  </div>
                </div>
                <div className="grid overflow-hidden rounded-xl border border-slate-200 md:grid-cols-3 dark:border-border">
                  {[
                    ['Exam', selectedTemplateTitle.toUpperCase(), FileText],
                    ['Schedule', selectedAssignment?.scheduled_at ? formatDateTime(selectedAssignment.scheduled_at) : '-', CalendarDays],
                    ['Total Questions', `${totalQuestions || 0} Questions`, ClipboardList],
                    ['Duration', `${selectedAssignment?.duration_minutes || 60} minutes`, Clock],
                    ['Passing Score', `${selectedAssignment?.passing_score || 70}%`, BadgeCheck],
                    ['Status', activeStatus, CircleUserRound],
                  ].map(([label, value, Icon]) => (
                    <div key={label} className="flex min-h-24 gap-3 border-b border-r border-slate-100 p-4 last:border-r-0 md:nth-[n+4]:border-b-0 dark:border-border">
                      {createElement(Icon, { className: 'mt-0.5 size-4 shrink-0 text-slate-500' })}
                      <div>
                        <p className="text-[10px] font-extrabold text-slate-500">{label}</p>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-foreground">{value}</p>
                      </div>
                    </div>
                  ))}
                </div>
                <div className="mt-4 grid gap-3 lg:grid-cols-3">
                  <Label className="block text-[11px] font-bold text-slate-700">
                    Select Exam
                    <SelectBox className="mt-1 h-10 rounded-lg shadow-none" value={assignTemplateId} onChange={(event) => setAssignTemplateId(event.target.value)}>
                      <option value="">Select exam to assign</option>
                      {templates.map((template) => <option key={template.id} value={template.id}>{template.title}</option>)}
                    </SelectBox>
                  </Label>
                  <Label className="block text-[11px] font-bold text-slate-700">
                    Exam Date / Time
                    <Input className="mt-1 h-10 rounded-lg border-slate-200 bg-white text-xs shadow-none" type="datetime-local" value={scheduleAt} onChange={(event) => setScheduleAt(event.target.value)} />
                    <FieldError>{examScheduleErrors.scheduleAt}</FieldError>
                  </Label>
                  <Label className="block text-[11px] font-bold text-slate-700">
                    Link Expiry
                    <Input className="mt-1 h-10 rounded-lg border-slate-200 bg-white text-xs shadow-none" type="datetime-local" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
                    <FieldError>{examScheduleErrors.expiresAt}</FieldError>
                  </Label>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  <Button className="h-10 gap-2 rounded-lg bg-orange-600 px-4 text-xs font-bold text-white hover:bg-orange-700" onClick={() => runExamAction(selectedAssignment ? 'reassign' : 'assign', activeApplicant?.id, {
                    exam_template_id: assignTemplateId || selectedAssignment?.exam_template_id,
                    scheduled_at: scheduleAt ? new Date(scheduleAt).toISOString() : undefined,
                    expires_at: expiresAt ? new Date(expiresAt).toISOString() : undefined,
                  })} disabled={acting || hasExamScheduleErrors || (!assignTemplateId && !selectedAssignment)}>
                    <FileText className="size-4" />
                    {selectedAssignment ? 'Update Assignment' : 'Assign Exam'}
                  </Button>
                  <Button variant="outline" className="h-10 rounded-lg border-slate-200 bg-white px-4 text-xs font-bold" onClick={rescheduleExam} disabled={acting || hasExamScheduleErrors || !selectedAssignment}>
                    Save Schedule
                  </Button>
                </div>
                <div className="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
                  <p className="text-[10px] font-bold uppercase tracking-wide text-slate-500">Exam Link</p>
                  <p className="mt-1 text-[11px] text-slate-500">Share this link with the applicant to start the exam.</p>
                  {examLink ? (
                    <div className="mt-2 flex flex-col gap-2 lg:flex-row lg:items-center">
                      <code className="min-w-0 flex-1 truncate rounded-lg bg-slate-50 px-3 py-2 text-[11px] font-bold text-slate-500 ring-1 ring-slate-200">{examLink}</code>
                      <Button variant="outline" className="h-9 rounded-lg bg-white px-3 text-xs" onClick={copyExamLink}>Copy Link</Button>
                      <a className="inline-flex h-9 items-center justify-center rounded-lg bg-orange-600 px-3 text-xs font-bold text-white hover:bg-orange-700" href={examLink} target="_blank" rel="noreferrer">
                        View Exam Link
                      </a>
                    </div>
                  ) : (
                    <p className="mt-2 text-xs text-slate-500">No exam link yet. Assign an exam first to generate the assessment link.</p>
                  )}
                </div>
              </section>

              <section>
                <div className="border-b border-slate-100 dark:border-border">
                  <div className="flex items-center justify-between gap-3">
                    <button type="button" className="relative pb-3 text-[11px] font-extrabold text-orange-600">
                      Exam Results
                      <span className="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500" />
                    </button>
                    <Button variant="outline" className="mb-2 h-8 rounded-lg border-slate-200 bg-white px-3 text-[11px] font-bold" onClick={() => setAnswersModalOpen(true)} disabled={!examAnswers.length}>
                      View Applicant Answers
                    </Button>
                  </div>
                </div>
                <div className="mt-3 grid gap-3 md:grid-cols-4">
                  {[
                    ['Score', resultScore != null ? `${resultScore}` : '-', '/100'],
                    ['Correct Answers', examAnswers.length ? `${correctAnswers}` : '-', `/${totalQuestions || 0}`],
                    ['Percentage', resultScore != null ? `${resultScore}` : '-', '%'],
                    ['Result', resultStatus, ''],
                  ].map(([label, value, suffix]) => (
                    <div key={label} className="rounded-xl border border-slate-200 p-4 dark:border-border">
                      <p className="text-[10px] font-bold text-slate-500">{label}</p>
                      <p className="mt-4 text-xl font-extrabold text-slate-950 dark:text-foreground">{value}</p>
                      {suffix ? <p className="mt-1 text-[11px] font-semibold text-slate-500">{suffix}</p> : null}
                    </div>
                  ))}
                </div>
              </section>
            </div>
          </>
        ) : (
          <EmptySelectApplicant stage="exams" />
        )}
      </section>

    </div>
    <Dialog open={actionsModalOpen} onOpenChange={setActionsModalOpen}>
      <DialogContent
        className="max-h-[92vh] max-w-[1100px] rounded-[22px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.35)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        closeButtonClassName="right-5 top-5 rounded-xl"
        surfaceStyle={{
          width: 'min(1100px, calc(100vw - 32px))',
          height: 'min(820px, calc(100dvh - 32px))',
          maxWidth: 'calc(100vw - 24px)',
        }}
      >
        <div className="border-b border-slate-200 bg-white px-6 py-5 pr-16 dark:border-border dark:bg-card">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg font-extrabold tracking-tight text-slate-950 dark:text-foreground">
              <ClipboardList className="size-5 text-slate-600" />
              Exam Actions
            </DialogTitle>
            <DialogDescription>
              {activeApplicant ? `${activeApplicant.full_name} / ${activeApplicant.applicant_no}` : 'Select an applicant to manage exam actions.'}
            </DialogDescription>
          </DialogHeader>
          {activeApplicant ? (
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
              {[
                ['Applicant', activeApplicant.full_name],
                ['Exam', selectedTemplateTitle],
                ['Status', activeStatus],
              ].map(([label, value]) => (
                <div key={label} className="rounded-2xl border border-white/80 bg-white/85 px-4 py-3 shadow-sm dark:border-border dark:bg-card/80">
                  <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">{label}</p>
                  <p className="mt-1 truncate text-xs font-black text-slate-950 dark:text-foreground">{value || '-'}</p>
                </div>
              ))}
            </div>
          ) : null}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50/70 px-6 py-5 dark:bg-background/40">
          <section className="grid gap-3 md:grid-cols-2">
            {actionCards.map((card) => {
              const Icon = card.icon
              const iconToneClass = {
                orange: 'bg-slate-100 text-slate-600',
                yellow: 'bg-slate-100 text-slate-600',
                green: 'bg-emerald-50 text-emerald-700',
                emerald: 'bg-emerald-50 text-emerald-700',
                red: 'bg-red-50 text-red-700',
              }[card.tone]
              return (
                <button
                  key={card.title}
                  type="button"
                  disabled={acting || card.disabled}
                  onClick={card.action}
                  className={cn(
                    'group flex min-h-[92px] w-full items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-border dark:bg-card dark:hover:bg-muted/20',
                  )}
                >
                  <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-lg', iconToneClass)}>
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-xs font-extrabold text-slate-900 dark:text-foreground">{card.title}</span>
                    <span className="mt-1 block text-[10px] leading-4 text-slate-500">{card.description}</span>
                  </span>
                  <ChevronRight className="size-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5" />
                </button>
              )
            })}
          </section>
          <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-border dark:bg-card">
            <div className="flex flex-col gap-3 xl:flex-row xl:items-end">
              <div className="min-w-0 flex-1">
                <h4 className="text-sm font-black text-slate-950 dark:text-foreground">Template Library</h4>
                <Label className="mt-3 block text-[11px] font-bold text-slate-700">
                  Select Template
                  <SelectBox className="mt-1 h-10 rounded-lg border-slate-200 bg-white text-xs shadow-none focus:border-red-300" value={deleteTemplateId} onChange={(event) => setDeleteTemplateId(event.target.value)}>
                    <option value="">Select template to manage</option>
                    {templates.map((template) => (
                      <option key={template.id} value={template.id}>
                        {template.title}{template.assigned_applicants_count ? ` (${template.assigned_applicants_count} assigned)` : ''}
                      </option>
                    ))}
                  </SelectBox>
                </Label>
                <p className="mt-2 text-[11px] leading-5 text-slate-500">
                  Delete exam templates from here. Assigned templates can be deleted, but their related applicant exam records will also be removed.
                </p>
                {templateToDelete?.assigned_applicants_count ? (
                  <p className="mt-1 text-[11px] font-bold text-red-600">
                    This template has {templateToDelete.assigned_applicants_count} assigned applicant(s); deletion will remove those exam records too.
                  </p>
                ) : null}
              </div>
              <Button
                type="button"
                variant="outline"
                className="h-10 gap-2 rounded-lg border-red-200 bg-white px-4 text-xs font-bold text-red-700 hover:bg-red-50"
                onClick={openDeleteTemplateModal}
                disabled={!deleteTemplateId || deletingTemplate}
              >
                {deletingTemplate ? <RefreshCw className="size-3.5 animate-spin" /> : <Trash2 className="size-3.5" />}
                Delete Template
              </Button>
            </div>
          </section>
        </div>
        <DialogFooter className="border-t border-slate-100 bg-white px-6 py-4 dark:border-border dark:bg-card">
          <Button variant="outline" className="rounded-lg border-slate-200 bg-white px-5 text-xs" onClick={() => setActionsModalOpen(false)}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
    <Dialog
      open={deleteTemplateModalOpen}
      onOpenChange={(nextOpen) => {
        if (deletingTemplate) return
        setDeleteTemplateModalOpen(nextOpen)
      }}
    >
      <DialogContent
        className="max-w-md rounded-[22px] border-slate-200 bg-white shadow-[0_24px_70px_-24px_rgba(15,23,42,0.45)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0"
      >
        <div className="border-b border-red-100 bg-red-50/70 px-6 py-5 dark:border-red-500/20 dark:bg-red-500/10">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg font-extrabold text-red-700 dark:text-red-300">
              <AlertTriangle className="size-5" />
              Delete Exam Template
            </DialogTitle>
            <DialogDescription>
              This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
        </div>
        <div className="space-y-4 px-6 py-5">
          <p className="text-sm leading-6 text-slate-600 dark:text-muted-foreground">
            Are you sure you want to delete <b className="text-slate-950 dark:text-foreground">{templateToDelete?.title || 'this template'}</b>?
            This will remove the template and all of its questions.
          </p>
          {templateToDelete?.assigned_applicants_count ? (
            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs leading-5 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
              This template has <b>{templateToDelete.assigned_applicants_count}</b> assigned applicant(s). Deleting it will also remove their exam assignment records and answers for this template.
            </div>
          ) : null}
        </div>
        <DialogFooter className="border-t border-slate-100 bg-slate-50/80 px-6 py-4 dark:border-border dark:bg-muted/20">
          <Button variant="outline" className="rounded-lg border-slate-200 bg-white px-4 text-xs font-bold" onClick={() => setDeleteTemplateModalOpen(false)} disabled={deletingTemplate}>
            Cancel
          </Button>
          <Button className="gap-2 rounded-lg bg-red-600 px-4 text-xs font-extrabold text-white hover:bg-red-700" onClick={confirmDeleteExamTemplate} disabled={deletingTemplate}>
            {deletingTemplate ? <RefreshCw className="size-3.5 animate-spin" /> : <Trash2 className="size-3.5" />}
            Delete Template
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
    <Dialog open={answersModalOpen} onOpenChange={setAnswersModalOpen}>
      <DialogContent
        className="max-h-[92vh] max-w-[880px] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.38)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
      >
        <div className="border-b border-slate-100 px-6 py-5 pr-16 dark:border-border">
          <DialogHeader>
            <DialogTitle className="text-lg font-extrabold tracking-tight text-slate-950 dark:text-foreground">Applicant Exam Answers</DialogTitle>
            <DialogDescription>
              {activeApplicant?.full_name || 'Applicant'} / {selectedAssignment?.exam_title || selectedTemplateTitle}
            </DialogDescription>
          </DialogHeader>
        </div>
        <div className="max-h-[calc(92vh-145px)] overflow-y-auto bg-slate-50/70 px-6 py-5 dark:bg-background/40">
          <div className="mb-4 grid gap-3 sm:grid-cols-4">
            {[
              ['Score', resultScore != null ? `${resultScore}/100` : '-'],
              ['Correct', examAnswers.length ? `${correctAnswers}/${totalQuestions || 0}` : '-'],
              ['Answered', `${answeredCount}/${totalQuestions || answeredCount || 0}`],
              ['Result', resultStatus],
            ].map(([label, value]) => (
              <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
                <p className="text-[10px] font-bold uppercase tracking-wide text-slate-400">{label}</p>
                <p className="mt-2 text-sm font-extrabold text-slate-950 dark:text-foreground">{value}</p>
              </div>
            ))}
          </div>
          <div className="space-y-3">
            {examAnswers.map((answer, index) => {
              const earned = Number(answer.score || 0)
              const points = Number(answer.points || 0)
              const isCorrect = points > 0 && earned >= points
              return (
                <div key={answer.id || `${answer.question_id}-${index}`} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
                  <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-border">
                    <div className="min-w-0">
                      <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">Question {index + 1} / {answer.question_type || 'Question'}</p>
                      <p className="mt-1 text-sm font-bold leading-6 text-slate-950 dark:text-foreground">{answer.question || 'Question unavailable'}</p>
                    </div>
                    <span className={cn('rounded-full px-3 py-1 text-[10px] font-extrabold ring-1', isCorrect ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200')}>
                      {earned}/{points || 0} pts
                    </span>
                  </div>
                  <div className="grid gap-3 p-4 md:grid-cols-2">
                    <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-3 dark:border-border dark:bg-muted/20">
                      <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">Applicant Answer</p>
                      <p className="mt-2 whitespace-pre-wrap text-xs font-bold leading-5 text-slate-900 dark:text-foreground">{formatExamAnswerValue(answer.answer)}</p>
                    </div>
                    <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-3 dark:border-border dark:bg-muted/20">
                      <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">Correct Answer</p>
                      <p className="mt-2 whitespace-pre-wrap text-xs font-bold leading-5 text-slate-900 dark:text-foreground">{formatExamAnswerValue(answer.correct_answer)}</p>
                    </div>
                  </div>
                </div>
              )
            })}
            {examAnswers.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-xs text-slate-500 dark:border-border dark:bg-card">
                No submitted answers yet.
              </div>
            ) : null}
          </div>
        </div>
        <DialogFooter className="border-t border-slate-100 bg-white px-6 py-4 dark:border-border dark:bg-card">
          <Button variant="outline" className="rounded-lg border-slate-200 bg-white px-5 text-xs" onClick={() => setAnswersModalOpen(false)}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
    <Dialog open={templateModalOpen} onOpenChange={setTemplateModalOpen}>
      <DialogContent
        className="max-h-[96dvh] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.4)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-200 bg-white text-slate-500 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card"
        overlayClassName="bg-slate-950/50 backdrop-blur-[3px]"
        surfaceStyle={{
          width: 'min(1360px, calc(100vw - 24px))',
          height: 'min(900px, calc(100dvh - 24px))',
          maxWidth: 'calc(100vw - 24px)',
        }}
      >
        <div className="relative overflow-hidden border-b border-orange-100 bg-linear-to-r from-orange-50 via-white to-slate-50 px-8 py-6 pr-16 dark:border-border dark:from-orange-500/10 dark:via-card dark:to-card">
          <div className="absolute right-8 top-4 size-32 rounded-full bg-orange-200/30 blur-2xl" />
          <div className="relative flex items-start gap-4">
            <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-orange-600 text-white shadow-lg shadow-orange-500/25">
              <GraduationCap className="size-5" />
            </span>
            <DialogHeader className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <DialogTitle className="text-2xl font-black tracking-tight text-slate-950 dark:text-foreground">Create Recruitment Exam</DialogTitle>
                <span className="rounded-full border border-orange-200 bg-orange-100 px-3 py-1 text-[10px] font-black uppercase tracking-wide text-orange-700">Expanded Builder</span>
              </div>
              <DialogDescription className="max-w-3xl text-xs leading-5">
                Build an AGCTek recruitment assessment template with timing, passing rules, applicant instructions, and question bank items.
              </DialogDescription>
            </DialogHeader>
          </div>
          <div className="relative mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {[
              ['Questions', templateForm.questions.length],
              ['Total Points', templateForm.questions.reduce((sum, question) => sum + Number(question.points || 0), 0)],
              ['Passing Score', `${templateForm.passing_score || 0}%`],
              ['Duration', `${templateForm.duration_minutes || 0} min`],
            ].map(([label, value]) => (
              <div key={label} className="rounded-2xl border border-white/80 bg-white/85 px-5 py-3.5 shadow-sm backdrop-blur dark:border-border dark:bg-card/80">
                <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">{label}</p>
                <p className="mt-1 text-lg font-black text-slate-950 dark:text-foreground">{value}</p>
              </div>
            ))}
          </div>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50/60 px-8 py-6 dark:bg-background/40">
          <div className="grid gap-5 2xl:grid-cols-[minmax(0,1.35fr)_minmax(380px,0.65fr)]">
          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h4 className="text-sm font-black text-slate-950 dark:text-foreground">Exam Information</h4>
                <p className="mt-1 text-[11px] leading-5 text-slate-500">Define where this assessment belongs in recruitment.</p>
              </div>
              <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[10px] font-extrabold text-orange-700">Template</span>
            </div>
            <div className="mt-5 grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
              <Label className="block text-[11px] font-bold text-slate-700">
                Exam Name
                <Input className="mt-1 h-11 rounded-xl border-slate-200 bg-white text-xs shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15" placeholder="e.g. Junior Web Developer Assessment" value={templateForm.title} onChange={(event) => setTemplateForm((state) => ({ ...state, title: event.target.value }))} />
                <FieldError>{templateFormErrors.title}</FieldError>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700">
                Exam Category
                <SelectBox className="mt-1 h-11 rounded-xl shadow-none focus:border-orange-500" value={templateForm.category} onChange={(event) => setTemplateForm((state) => ({ ...state, category: event.target.value }))}>
                  {(meta.exam_categories || ['IQ Assessment', 'Technical Assessment', 'Accounting Assessment', 'HR Assessment', 'Sales Assessment', 'Management Assessment', 'Custom']).map((category) => <option key={category}>{category}</option>)}
                </SelectBox>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700">
                Department
                <SelectBox className="mt-1 h-11 rounded-xl shadow-none" value={templateForm.department_id} onChange={(event) => setTemplateForm((state) => ({ ...state, department_id: event.target.value }))}>
                  <option value="">Select department</option>
                  {meta.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                </SelectBox>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700">
                Position
                <SelectBox className="mt-1 h-11 rounded-xl shadow-none" value={templateForm.position_id} onChange={(event) => setTemplateForm((state) => ({ ...state, position_id: event.target.value }))}>
                  <option value="">Select position</option>
                  {meta.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                </SelectBox>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700">
                Duration
                <div className="relative mt-1">
                  <Clock className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                  <Input className="h-11 rounded-xl border-slate-200 bg-white pl-9 pr-16 text-xs shadow-none" type="number" min="1" value={templateForm.duration_minutes} onChange={(event) => setTemplateForm((state) => ({ ...state, duration_minutes: Number(event.target.value) }))} />
                  <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400">minutes</span>
                </div>
                <FieldError>{templateFormErrors.duration_minutes}</FieldError>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700">
                Passing Score
                <div className="relative mt-1">
                  <BadgeCheck className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                  <Input className="h-11 rounded-xl border-slate-200 bg-white pl-9 pr-10 text-xs shadow-none" type="number" min="0" max="100" value={templateForm.passing_score} onChange={(event) => setTemplateForm((state) => ({ ...state, passing_score: Number(event.target.value) }))} />
                  <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400">%</span>
                </div>
                <FieldError>{templateFormErrors.passing_score}</FieldError>
              </Label>
              <Label className="block text-[11px] font-bold text-slate-700 lg:col-span-2 2xl:col-span-3">
                Applicant Instructions
                <Textarea className="mt-1 min-h-28 rounded-xl border-slate-200 bg-white text-xs leading-5 shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15" placeholder="Write clear instructions shown before the applicant starts the exam." value={templateForm.instructions} onChange={(event) => setTemplateForm((state) => ({ ...state, instructions: event.target.value }))} />
              </Label>
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h4 className="text-sm font-black text-slate-950 dark:text-foreground">Assessment Rules</h4>
                <p className="mt-1 text-[11px] leading-5 text-slate-500">Control answer visibility, randomization, retakes, and review behavior.</p>
              </div>
              <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[10px] font-extrabold text-slate-500">Exam Settings</span>
            </div>
            <div className="mt-5 grid gap-4">
              <div className="grid gap-3">
                {[
                  ['randomize_questions', 'Randomize Questions', 'Shuffle question order for every applicant.'],
                  ['randomize_choices', 'Randomize Choices', 'Shuffle answer choices where applicable.'],
                  ['allow_retake', 'Allow Retake', 'Permit another attempt if configured.'],
                  ['auto_grade', 'Auto Grade', 'Automatically score objective questions.'],
                  ['manual_review_required', 'Manual Review Required', 'Route results for recruiter checking.'],
                ].map(([key, label, description]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => updateExamSetting(key, !templateForm.settings[key])}
                    className={cn(
                      'flex items-start gap-3 rounded-2xl border p-4 text-left transition',
                      templateForm.settings[key]
                        ? 'border-orange-200 bg-orange-50 text-orange-700'
                        : 'border-slate-200 bg-white text-slate-700 hover:border-orange-100 hover:bg-orange-50/40',
                    )}
                  >
                    <span className={cn('mt-0.5 flex size-5 items-center justify-center rounded-full border text-[10px] font-black', templateForm.settings[key] ? 'border-orange-500 bg-orange-500 text-white' : 'border-slate-300 text-transparent')}>
                      ✓
                    </span>
                    <span>
                      <span className="block text-xs font-extrabold">{label}</span>
                      <span className="mt-1 block text-[10px] leading-4 text-slate-500">{description}</span>
                    </span>
                  </button>
                ))}
              </div>
              <div className="space-y-3 rounded-2xl border border-slate-100 bg-slate-50/70 p-4 dark:border-border dark:bg-muted/20">
                <Label className="block text-[11px] font-bold text-slate-700">
                  Show Correct Answers
                  <SelectBox className="mt-1 h-10 rounded-xl bg-white shadow-none" value={templateForm.settings.show_correct_answers} onChange={(event) => updateExamSetting('show_correct_answers', event.target.value)}>
                    {(meta.exam_correct_answer_visibility || ['Never', 'Immediately After Submission', 'After Exam Closed', 'After Recruiter Approval']).map((option) => <option key={option}>{option}</option>)}
                  </SelectBox>
                </Label>
                <Label className="block text-[11px] font-bold text-slate-700">
                  Maximum Attempts
                  <Input className="mt-1 h-10 rounded-xl border-slate-200 bg-white text-xs shadow-none" type="number" min="1" max="10" value={templateForm.settings.maximum_attempts} onChange={(event) => updateExamSetting('maximum_attempts', Number(event.target.value))} />
                </Label>
              </div>
            </div>
          </section>
          </div>

          <section className="mt-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 className="text-sm font-black text-slate-950 dark:text-foreground">Question Builder</h4>
                <p className="mt-1 text-[11px] leading-5 text-slate-500">Add scored questions for the applicant assessment page.</p>
              </div>
              <Button type="button" variant="outline" className="h-9 gap-2 rounded-xl border-orange-200 bg-orange-50 px-3 text-xs font-extrabold text-orange-700 hover:bg-orange-100" onClick={addTemplateQuestion}>
                <Plus className="size-3.5" />
                Add Question
              </Button>
            </div>
            <div className="mt-4 space-y-4">
              {templateForm.questions.map((question, index) => {
                const usesChoices = questionTypeUsesChoices(question.question_type)
                const usesFixedChoices = questionTypeUsesFixedChoices(question.question_type)
                const manualReview = questionTypeNeedsManualReview(question.question_type)
                const answerChoices = usesFixedChoices ? ['True', 'False'] : question.choices || []

                return (
                <div key={index} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
                  <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/80 px-4 py-3 dark:border-border dark:bg-muted/20">
                    <div className="flex items-center gap-3">
                      <span className="flex size-8 items-center justify-center rounded-xl bg-orange-600 text-xs font-black text-white">{index + 1}</span>
                      <div>
                        <p className="text-xs font-black text-slate-950 dark:text-foreground">Question {index + 1}</p>
                        <p className="text-[10px] text-slate-500">{question.question_type} / {question.difficulty || 'Medium'} / {question.points || 1} point(s)</p>
                      </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" className="h-8 gap-2 rounded-lg border-red-100 bg-white px-3 text-[11px] font-bold text-red-600 hover:bg-red-50" onClick={() => removeTemplateQuestion(index)}>
                      <Trash2 className="size-3.5" />
                      Remove
                    </Button>
                  </div>
                  <div className="space-y-3 p-4">
                    <div className="grid gap-3 lg:grid-cols-[180px_150px_150px_120px_minmax(260px,1fr)]">
                      <Label className="block text-[10px] font-bold text-slate-500">
                        Type
                        <SelectBox className="mt-1 h-10 rounded-xl text-xs" value={question.question_type} onChange={(event) => updateTemplateQuestion(index, { question_type: event.target.value })}>
                          {meta.question_types.map((type) => <option key={type}>{type}</option>)}
                        </SelectBox>
                      </Label>
                      <Label className="block text-[10px] font-bold text-slate-500">
                        Difficulty
                        <SelectBox className="mt-1 h-10 rounded-xl text-xs" value={question.difficulty || 'Medium'} onChange={(event) => updateTemplateQuestion(index, { difficulty: event.target.value })}>
                          {(meta.question_difficulties || ['Easy', 'Medium', 'Hard']).map((difficulty) => <option key={difficulty}>{difficulty}</option>)}
                        </SelectBox>
                      </Label>
                      <Label className="block text-[10px] font-bold text-slate-500">
                        Category
                        <SelectBox className="mt-1 h-10 rounded-xl text-xs" value={question.category || 'Custom'} onChange={(event) => updateTemplateQuestion(index, { category: event.target.value })}>
                          {(meta.question_categories || ['Accounting', 'IT', 'HR', 'Sales', 'Management', 'Custom']).map((category) => <option key={category}>{category}</option>)}
                        </SelectBox>
                      </Label>
                      <Label className="block text-[10px] font-bold text-slate-500">
                        Points
                        <Input className="mt-1 h-10 rounded-xl border-slate-200 bg-white text-xs shadow-none" type="number" min="0" value={question.points || 1} onChange={(event) => updateTemplateQuestion(index, { points: Number(event.target.value) })} />
                      </Label>
                      {manualReview ? (
                        <div className="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-[10px] leading-4 text-amber-700">
                          <p className="font-extrabold">Manual Review</p>
                          <p>Essay and file upload questions are checked by the recruiter.</p>
                        </div>
                      ) : usesFixedChoices || usesChoices ? (
                        <Label className="block text-[10px] font-bold text-slate-500">
                          Correct Answer
                          {question.question_type === 'Checkbox' ? (
                            <Input className="mt-1 h-10 rounded-xl border-slate-200 bg-white text-xs shadow-none" placeholder="A: choice, C: choice" value={question.correct_answer} onChange={(event) => updateTemplateQuestion(index, { correct_answer: event.target.value })} />
                          ) : (
                            <SelectBox className="mt-1 h-10 rounded-xl text-xs" value={question.correct_answer || ''} onChange={(event) => updateTemplateQuestion(index, { correct_answer: event.target.value })}>
                              <option value="">Select answer</option>
                              {answerChoices.map((choice) => <option key={choice} value={choice}>{choice}</option>)}
                            </SelectBox>
                          )}
                        </Label>
                      ) : (
                        <Label className="block text-[10px] font-bold text-slate-500">
                          Correct Answer
                          <Input className="mt-1 h-10 rounded-xl border-slate-200 bg-white text-xs shadow-none" placeholder={question.question_type === 'Identification' ? 'Exact answer key' : 'Suggested answer key'} value={question.correct_answer} onChange={(event) => updateTemplateQuestion(index, { correct_answer: event.target.value })} />
                        </Label>
                      )}
                    </div>
                    {usesChoices ? (
                      <Label className="block text-[10px] font-bold text-slate-500">
                        Choices
                        <Textarea
                          className="mt-1 min-h-24 rounded-xl border-slate-200 bg-white text-xs leading-5 shadow-none"
                          placeholder={'A: test, B: dog\nor\nA: test\nB: dog'}
                          value={questionChoiceText(question)}
                          onChange={(event) => updateTemplateQuestion(index, {
                            choice_text: event.target.value,
                            choices: parseExamChoiceDraft(event.target.value),
                          })}
                          onBlur={(event) => {
                            const choices = normalizeExamChoiceInput(event.target.value)
                            updateTemplateQuestion(index, {
                              choice_text: choices.join('\n'),
                              choices,
                            })
                          }}
                        />
                        <span className="mt-1 block text-[10px] font-medium text-slate-400">Use comma-separated labels like A: test, B: dog, or one choice per line.</span>
                      </Label>
                    ) : usesFixedChoices ? (
                      <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs text-slate-600">
                        <p className="text-[10px] font-extrabold uppercase tracking-wide text-slate-400">Choices</p>
                        <p className="mt-2 font-bold">True / False choices are generated automatically.</p>
                      </div>
                    ) : null}
                    <Label className="block text-[10px] font-bold text-slate-500">
                      Question
                      <Textarea className="mt-1 min-h-24 rounded-xl border-slate-200 bg-white text-xs leading-5 shadow-none" placeholder="Type the question exactly as the applicant should see it." value={question.question} onChange={(event) => updateTemplateQuestion(index, { question: event.target.value })} />
                    </Label>
                  </div>
                </div>
                )
              })}
              {templateForm.questions.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-6 py-10 text-center dark:border-border dark:bg-muted/20">
                  <ClipboardList className="mx-auto size-8 text-slate-300" />
                  <p className="mt-3 text-sm font-black text-slate-900 dark:text-foreground">No questions yet</p>
                  <p className="mt-1 text-xs text-slate-500">Add at least one question to build the assessment.</p>
                  <FieldError>{templateFormErrors.questions}</FieldError>
                  <Button type="button" className="mt-4 gap-2 rounded-xl bg-orange-600 px-4 text-xs font-bold text-white hover:bg-orange-700" onClick={addTemplateQuestion}>
                    <Plus className="size-3.5" />
                    Add First Question
                  </Button>
                </div>
              ) : null}
            </div>
          </section>
        </div>
        <DialogFooter className="border-t border-slate-200 bg-white px-6 py-4 dark:border-border dark:bg-card">
          <div className="mr-auto hidden text-[11px] text-slate-500 sm:block">
            {templateForm.questions.length} question(s), {templateForm.questions.reduce((sum, question) => sum + Number(question.points || 0), 0)} total point(s)
          </div>
          <Button variant="outline" className="rounded-xl border-slate-200 bg-white px-5 text-xs font-bold" onClick={() => setTemplateModalOpen(false)}>Cancel</Button>
          <Button variant="outline" className="gap-2 rounded-xl border-orange-200 bg-orange-50 px-5 text-xs font-extrabold text-orange-700 hover:bg-orange-100" onClick={addTemplateQuestion}>
            <Plus className="size-3.5" />
            Add Question
          </Button>
          {templateFormErrors.questions && templateForm.questions.length > 0 ? (
            <div className="mr-auto text-[11px] font-semibold text-red-600">{templateFormErrors.questions}</div>
          ) : null}
          <Button className="gap-2 rounded-xl bg-orange-600 px-5 text-xs font-extrabold text-white hover:bg-orange-700" onClick={createExam} disabled={hasTemplateFormErrors}>
            <Save className="size-3.5" />
            Create Exam
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
    </>
  )
}

function RequirementsPanel({ applicant, onUpdated, onStageAction }) {
  const { toast } = useToast()
  if (!applicant) return <EmptySelectApplicant />

  async function moveToHiringApproval() {
    try {
      const data = await onStageAction(applicant.id, { stage: 'requirements', action: 'move_hiring_approval' })
      toast({ title: 'Applicant moved to hiring approval', variant: 'success' })
      await onUpdated(data.applicant)
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">Requirements</h2>
      <p className="text-sm text-slate-500">Verify uploaded requirements from the applicant&apos;s Documents modal in the Applicants tab. Current requirements status: <b>{applicant.requirements_status || 'Pending'}</b></p>
      <div className="flex flex-wrap gap-2">
        <Button onClick={moveToHiringApproval}>Move to Hiring Approval</Button>
      </div>
    </div>
  )
}

function HiringPanel({ applicant, onUpdated, onStageAction }) {
  const { toast } = useToast()
  if (!applicant) return <EmptySelectApplicant />

  async function action(kind) {
    try {
      if (kind === 'create_employee') {
        const data = await recruitmentHiringAction(applicant.id, kind)
        toast({
          title: 'Employee record created',
          description: data.employee?.temporary_password ? `Temporary password: ${data.employee.temporary_password}` : undefined,
          variant: 'success',
        })
        await onUpdated(data.applicant)
        return
      }

      const stageAction = kind === 'mark_hired' ? 'approve' : 'reject'
      const data = await onStageAction(applicant.id, { stage: 'hiring', action: stageAction })
      toast({ title: kind === 'reject' ? 'Applicant rejected' : 'Hiring approved', variant: 'success' })
      await onUpdated(data.applicant)
    } catch (error) {
      toast({ title: 'Hiring action failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">Hiring Decision</h2>
      <p className="text-sm text-slate-500">After final interview, choose the next action. Create Employee Record transfers name, email, phone, position, department, and uploaded requirements.</p>
      <div className="flex flex-wrap gap-2">
        <Button className="gap-2 bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => action('mark_hired')}>Mark as Hired</Button>
        <Button variant="destructive" onClick={() => action('reject')}>Reject Applicant</Button>
        <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={() => action('create_employee')} disabled={Boolean(applicant.created_employee_id)}>
          <UserPlus className="size-4" />
          Create Employee Record
        </Button>
      </div>
    </div>
  )
}

function RecruitmentOutcomePanel({ applicant, outcome, onViewProfile }) {
  if (!applicant) return <EmptySelectApplicant />

  const isRejected = outcome === 'rejected'
  const Icon = isRejected ? AlertTriangle : BadgeCheck

  return (
    <div className="space-y-4">
      <div className={cn(
        'rounded-2xl border p-5',
        isRejected
          ? 'border-red-100 bg-red-50/70 dark:border-red-500/20 dark:bg-red-500/10'
          : 'border-emerald-100 bg-emerald-50/70 dark:border-emerald-500/20 dark:bg-emerald-500/10',
      )}>
        <div className="flex items-start gap-3">
          <span className={cn(
            'flex size-11 shrink-0 items-center justify-center rounded-xl text-white',
            isRejected ? 'bg-red-600' : 'bg-emerald-600',
          )}>
            <Icon className="size-5" />
          </span>
          <div className="min-w-0">
            <h2 className="text-lg font-extrabold text-slate-950 dark:text-foreground">
              {isRejected ? 'Rejected Applicant' : 'Hired Applicant'}
            </h2>
            <p className="mt-1 text-sm leading-6 text-slate-600 dark:text-muted-foreground">
              {applicant.full_name} remains saved in the recruitment module with status <b>{applicant.status}</b>.
            </p>
          </div>
        </div>
      </div>
      <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-border dark:bg-card">
        <div className="grid gap-3 sm:grid-cols-2">
          {[
            ['Applicant No.', applicant.applicant_no],
            ['Position', applicant.applied_position || applicant.position_applied],
            ['Email', applicant.email],
            ['Last Activity', formatDateTime(applicant.last_activity || applicant.last_activity_at || applicant.updated_at)],
          ].map(([label, value]) => (
            <div key={label} className="rounded-xl border border-slate-100 bg-slate-50/70 p-3 dark:border-border dark:bg-muted/20">
              <p className="text-[10px] font-bold uppercase tracking-wide text-slate-400">{label}</p>
              <p className="mt-1 text-xs font-extrabold text-slate-900 dark:text-foreground">{value || '-'}</p>
            </div>
          ))}
        </div>
        <Button className="mt-4 gap-2 rounded-xl bg-slate-950 px-4 text-xs font-bold text-white hover:bg-black" onClick={() => onViewProfile(applicant)}>
          <Eye className="size-4" />
          View Applicant Profile
        </Button>
      </div>
    </div>
  )
}

function WorkflowStageLayout({ stage, applicants, selectedApplicant, selectingApplicantId, refreshing, onSelect, children }) {
  const config = STAGE_FILTERS[stage]
  const [queueSearch, setQueueSearch] = useState('')
  const stageApplicants = useMemo(() => filterApplicantsForStage(applicants, stage), [applicants, stage])
  const filteredApplicants = useMemo(() => {
    const query = queueSearch.trim().toLowerCase()
    if (!query) return stageApplicants
    return stageApplicants.filter((applicant) => {
      const haystack = [
        applicant.applicant_no,
        applicant.full_name,
        applicant.email,
        applicant.applied_position,
        applicant.status,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(query)
    })
  }, [queueSearch, stageApplicants])

  return (
    <div className="grid gap-4 xl:grid-cols-[minmax(280px,320px)_minmax(0,1fr)]">
      <aside className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 dark:border-border dark:bg-muted/20">
        <div className="mb-3">
          <h2 className="text-sm font-extrabold text-slate-950 dark:text-foreground">{config.title}</h2>
          <p className="mt-1 text-[11px] leading-5 text-slate-500">{config.description}</p>
        </div>
        <div className="relative mb-3">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <Input
            className="h-10 rounded-xl border-slate-200 bg-white pl-9 text-xs shadow-none dark:bg-background"
            value={queueSearch}
            onChange={(e) => setQueueSearch(e.target.value)}
            placeholder="Search this queue..."
          />
        </div>
        <div className="mb-3 flex items-center justify-between gap-2">
          <span className="text-[10px] font-bold uppercase tracking-wide text-slate-400">Applicants</span>
          <div className="flex items-center gap-2">
            {refreshing && applicants.length === 0 ? <RefreshCw className="size-3 animate-spin text-orange-500" /> : null}
            <span className="rounded-full border border-orange-100 bg-orange-50 px-2.5 py-0.5 text-[10px] font-bold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
              {filteredApplicants.length}
            </span>
          </div>
        </div>
        <div className="max-h-[min(68vh,720px)] space-y-2 overflow-y-auto pr-1">
          {filteredApplicants.map((applicant) => {
            const selected = selectedApplicant?.id === applicant.id
            const loading = selectingApplicantId === applicant.id
            return (
              <button
                key={applicant.id}
                type="button"
                onClick={() => onSelect(applicant)}
                disabled={loading}
                className={cn(
                  'w-full rounded-xl border px-3 py-3 text-left transition',
                  selected
                    ? 'border-orange-300 bg-orange-50 shadow-sm dark:border-orange-500/30 dark:bg-orange-500/10'
                    : 'border-slate-200 bg-white hover:border-orange-200 hover:bg-orange-50/40 dark:border-border dark:bg-card dark:hover:bg-orange-500/5',
                )}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-xs font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</p>
                    <p className="mt-0.5 truncate text-[10px] text-slate-500">{applicant.applied_position || 'No position'}</p>
                  </div>
                  {loading ? <RefreshCw className="size-4 shrink-0 animate-spin text-orange-600" /> : null}
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <Badge className={cn('rounded-full px-2 py-0.5 text-[9px] font-bold shadow-none', statusTone(applicant.status))}>
                    {applicant.status}
                  </Badge>
                  <span className="font-mono text-[9px] text-slate-400">{applicant.applicant_no}</span>
                </div>
              </button>
            )
          })}
          {filteredApplicants.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 px-3 py-8 text-center text-xs text-slate-500 dark:border-border">
              {config.emptyMessage}
            </div>
          ) : null}
        </div>
      </aside>
      <div className="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 dark:border-border dark:bg-card">
        {selectedApplicant ? children : <EmptySelectApplicant stage={stage} />}
      </div>
    </div>
  )
}

function EmptySelectApplicant({ stage = null }) {
  const hint = stage
    ? 'Select an applicant from the queue on the left to continue this workflow step.'
    : 'Select an applicant from the Applicants tab first.'
  return (
    <div className="flex min-h-[320px] flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 px-6 py-10 text-center dark:border-border">
      <Users className="mb-3 size-10 text-slate-300" />
      <p className="text-sm font-semibold text-slate-700 dark:text-foreground">No applicant selected</p>
      <p className="mt-1 max-w-sm text-xs text-slate-500">{hint}</p>
    </div>
  )
}
