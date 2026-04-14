import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Loader2, User, UserRound, Briefcase, Gift, IdCard, Users, ShieldCheck, MapPin, Calendar, Clock, FileText, Phone, Zap, Plus, Upload, Eye, Pencil, Trash2, CheckCircle2, X, Mail, Flag, Home, Hash, Heart, Folder, FileUp, FileDown, Archive, AlertTriangle, Award, Gavel, HeartPulse, LineChart, Camera, FilePenLine, CircleDollarSign, Wallet, Receipt } from 'lucide-react'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { RoleBadge } from '@/components/RoleBadge'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_SM,
  ADMIN_FORM_DIALOG_MAX_W_MD,
  ADMIN_FORM_DIALOG_MAX_W_LG,
  ADMIN_FORM_DIALOG_MAX_W_XL,
  ADMIN_FORM_DIALOG_MAX_W_DEFAULT,
} from '@/lib/adminFormDialogStyles'
import { Autocomplete } from '@react-google-maps/api'
import { mapPlaceToAddressFields } from '@/lib/googlePlaces'
import { useGoogleMapsLoader } from '@/hooks/useGoogleMapsLoader'
import { cn } from '@/lib/utils'
import { formatScheduleLabel12h } from '@/lib/timeFormat'
import { useAuth } from '@/contexts/AuthContext'
import { ImageCropDialog } from '@/components/ImageCropDialog'
import mammoth from 'mammoth'
import {
  getMyEmployeeProfile,
  getEmployeeProfileSnapshot,
  getPayrollPeriodsForEmployee,
  getMySkills,
  getEmployeeSkills,
  addMySkill,
  updateMySkill,
  removeMySkill,
  getMyCertifications,
  getEmployeeCertifications,
  createMyCertification,
  updateMyCertification,
  deleteMyCertification,
  getSkillSuggestions,
  getMyDocuments,
  getEmployeeDocuments,
  createMyDocument,
  updateMyDocument,
  deleteMyDocument,
  getMyGovernmentIdDocuments,
  getEmployeeGovernmentIdDocuments,
  createMyGovernmentIdDocument,
  updateMyGovernmentIdDocument,
  deleteMyGovernmentIdDocument,
  updateMyPersonalInfo,
  saveMySignature,
  clearMySignature,
  exportMyProfileCsv,
  replaceMyEmergencyContacts,
  uploadProfilePhoto,
  removeProfilePhoto,
  updateProfile,
} from '@/api'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { validateEmail, validatePassword, validateConfirmPassword, validatePhone, sanitizeEmail, sanitizePassword } from '@/validation'
import { FaceRekognitionLiveness } from '@/components/FaceRekognitionLiveness'
import ESignatureCard from '@/components/ESignatureCard'
import SignaturePadDialog from '@/components/SignaturePadDialog'
import { ProfilePageSkeleton } from '@/components/skeletons'
import {
  DocumentCompactDropZone,
  DocumentsFolderEmptyState,
  getDocFolderGuidance,
  getFolderChecklistTeaser,
} from '@/lib/employeeDocumentsUi'
import { EmployeeRegularizationHistoryCard } from '@/components/regularization/EmployeeRegularizationHistoryCard'
import { LeaveCreditsCard } from '@/components/leave/LeaveCreditsCard'
import { formatEmploymentStatusForViewer } from '@/lib/employmentStatus'
import { getHrPanelBasePath, hrPanelPath } from '@/lib/hrRoutes'
import {
  SalaryAutomatedDeductionsCard,
  SalaryCompensationStructureCard,
  SalaryPayComponentsBreakdownCard,
  SalaryPayrollHistoryCard,
  SalaryTaxInfoCard,
  SalaryTabNotice,
  SalaryTabShell,
  resolveTinForSalaryDisplay,
} from '@/components/salary/EmployeeSalaryTab'
import { EmployeePayslipsPanel } from '@/components/payslips/EmployeePayslipsPanel'

function toStr(v) {
  if (v === undefined || v === null) return ''
  return String(v)
}

function formatWorkArrangement(value) {
  if (!value) return '—'
  return (
    {
      full_time: 'Full-time',
      part_time: 'Part-time',
      contract: 'Contract (hours)',
      probationary: 'Probationary (legacy)',
    }[value] || value
  )
}

function formatEmploymentStatusFromUser(u) {
  if (!u) return '—'
  return formatEmploymentStatusForViewer(u.employment_status, u.employment_status_label, false)
}

function probationPhaseLabel(phase) {
  if (!phase) return null
  const map = {
    before_four_months: 'Before 4 months',
    approaching_five_month: 'Approaching 5-month review',
    five_month_review: '5-month review period',
    six_month_decision: '6-month HR decision',
  }
  return map[phase] || phase
}

function createEmptyEmergencyContact() {
  return {
    id: '',
    full_name: '',
    relationship: '',
    phone_number: '',
    address: '',
    is_primary: false,
  }
}

function hasText(value) {
  return String(value || '').trim() !== ''
}

function getDocFileKind(file) {
  const mime = String(file?.mime || '').toLowerCase()
  const path = String(file?.path || file?.url || '').toLowerCase()
  if (mime.includes('pdf') || path.endsWith('.pdf')) return 'pdf'
  if (mime.includes('officedocument.wordprocessingml.document') || path.endsWith('.docx')) return 'docx'
  if (mime.includes('msword') || path.endsWith('.doc')) return 'doc'
  if (mime.includes('spreadsheetml') || mime.includes('ms-excel') || path.endsWith('.xlsx') || path.endsWith('.xls')) return 'xlsx'
  if (mime.includes('image/') || /\.(jpe?g|png|gif|webp|bmp)$/i.test(path)) return 'image'
  return 'file'
}

function formatBytes(bytes) {
  const b = Number(bytes) || 0
  if (b <= 0) return '0 KB'
  const kb = b / 1024
  if (kb < 1024) return `${Math.max(1, Math.round(kb))} KB`
  const mb = kb / 1024
  return `${mb.toFixed(mb >= 10 ? 0 : 1)} MB`
}

function expiryMeta(dateStr) {
  const raw = String(dateStr || '').trim()
  if (!raw) return { label: '—', cls: 'text-muted-foreground' }
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return { label: raw, cls: 'text-muted-foreground' }
  const today = new Date()
  const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const startD = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diffDays = Math.ceil((startD.getTime() - startToday.getTime()) / (1000 * 60 * 60 * 24))
  if (diffDays < 0) return { label: 'Expired', cls: 'text-rose-700' }
  if (diffDays === 0) return { label: 'Expires today', cls: 'text-amber-800' }
  if (diffDays <= 30) return { label: `⚠ Expires in ${diffDays} day${diffDays === 1 ? '' : 's'}`, cls: 'text-amber-800' }
  return { label: formatDate(raw), cls: 'text-muted-foreground' }
}

const ACCEPT_IMAGE = 'image/jpeg,image/jpg,image/png'
const MAX_FILE_MB = 2

function composeHomeAddress(parts) {
  const values = [
    parts?.street_address,
    parts?.barangay,
    parts?.city,
    parts?.province,
    parts?.postal_code,
  ]
    .map((v) => String(v || '').trim())
    .filter(Boolean)
  return values.join(', ')
}

function parseComposedHomeAddress(value) {
  const text = String(value || '').trim()
  if (!text) {
    return { street_address: '', barangay: '', city: '', province: '', postal_code: '' }
  }
  const segments = text.split(',').map((p) => p.trim()).filter(Boolean)
  const [street_address = '', barangay = '', city = '', province = '', postalRaw = ''] = segments
  const postal_code = String(postalRaw || '').replace(/[^\d]/g, '').slice(0, 4)
  return { street_address, barangay, city, province, postal_code }
}

function formatDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatSalaryAmount(value) {
  const raw = value === undefined || value === null ? '' : String(value).trim()
  if (raw === '') return '—'
  const n = Number(raw)
  if (Number.isNaN(n)) return '—'
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** Display salary with peso sign for read-only profile blocks. */
function formatSalaryPhp(value) {
  const s = formatSalaryAmount(value)
  if (s === '—') return '—'
  return `₱${s}`
}

/** Parse numeric salary fields from API (string or number). */
function parseSalaryNumeric(value) {
  if (value === undefined || value === null) return null
  const s = String(value).trim().replace(/,/g, '')
  if (s === '') return null
  const n = Number(s)
  return Number.isFinite(n) ? n : null
}

/** Parse H:i or H:i:s (same idea as Admin salary schedule fields; API may send MySQL time). */
function parseTimeToMinutes(value) {
  const text = String(value || '').trim()
  if (!text) return null
  const m = text.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/)
  if (!m) return null
  const hh = Number(m[1])
  const mm = Number(m[2])
  if (!Number.isFinite(hh) || !Number.isFinite(mm) || hh > 23 || mm > 59) return null
  return hh * 60 + mm
}

/**
 * When schedule_working_hours_per_day is missing or zero, derive hours from working_schedule_time
 * (e.g. "08:00:00 – 17:00:00") minus break — mirrors AdminEmployeeProfile schedule parsing.
 */
function inferWorkingHoursPerDayFromUser(u) {
  const fromApi = parseSalaryNumeric(u?.schedule_working_hours_per_day)
  if (fromApi != null && fromApi > 0) return fromApi

  const timeStr = String(u?.working_schedule_time || '').trim()
  if (!timeStr) return null
  const parts = timeStr.split(/\s*[–—-]\s*/).map((s) => s.trim()).filter(Boolean)
  if (parts.length < 2) return null
  const tIn = parseTimeToMinutes(parts[0])
  const tOut = parseTimeToMinutes(parts[1])
  if (tIn == null || tOut == null) return null
  let minutes = tOut - tIn
  if (minutes <= 0) minutes += 24 * 60

  const breakStart = u?.working_schedule_break_start ? parseTimeToMinutes(u.working_schedule_break_start) : null
  const breakEnd = u?.working_schedule_break_end ? parseTimeToMinutes(u.working_schedule_break_end) : null
  if (breakStart != null && breakEnd != null) {
    let br = breakEnd - breakStart
    if (br <= 0) br += 24 * 60
    minutes -= br
  }

  const hours = Math.max(0, minutes / 60)
  return hours > 0 ? Number(hours.toFixed(2)) : null
}

/**
 * One-line schedule summary — same shape as Admin → Employee Profile → Salary (`scheduleHint`).
 * Uses `schedule_working_*` from {@see AuthController::userResponse} / profile payload.
 */
function buildSalaryScheduleSummaryLine(u) {
  if (!u || typeof u !== 'object') return null
  const nameFromUser = String(u.working_schedule_name || '').trim()
  const scheduleName = nameFromUser || 'Work schedule'
  const divisorDays = parseSalaryNumeric(u.schedule_working_days_per_month)
  const calendarDays = parseSalaryNumeric(u.schedule_working_days_in_calendar_month)
  const divisorSource = String(u.schedule_rate_divisor_source || '')
  // Match admin banner: show calendar-month count when that is what drives the divisor (e.g. 26).
  let days = divisorDays
  if (divisorSource === 'calendar_month_schedule' && calendarDays != null && calendarDays > 0) {
    days = calendarDays
  }
  let hours = parseSalaryNumeric(u.schedule_working_hours_per_day)
  if (hours == null || hours <= 0) {
    hours = inferWorkingHoursPerDayFromUser(u)
  }
  if (days != null && days > 0 && hours != null && hours > 0) {
    const daysLabel = Number.isInteger(days) ? days : Number(days.toFixed(2))
    return `${scheduleName} · ${daysLabel} working days/mo · ${hours} hrs/day`
  }
  const timeStr = String(u.working_schedule_time || '').trim()
  if (nameFromUser && timeStr) return `${nameFromUser} · ${timeStr}`
  return nameFromUser || timeStr || null
}

/**
 * Daily/hourly for profile display: align with backend ScheduleRateService + admin salary form.
 * Prefer schedule_derived_* (computed from current monthly base + schedule), then client
 * recomputation from schedule_working_* × monthly (same formula as ScheduleRateService),
 * then legacy stored daily_rate / hourly_rate (often computed with a fixed 22-day divisor).
 */
function computeRatesFromScheduleFields(u) {
  const monthly = parseSalaryNumeric(u?.monthly_salary ?? u?.monthly_rate)
  const days = parseSalaryNumeric(u?.schedule_working_days_per_month)
  let hours = parseSalaryNumeric(u?.schedule_working_hours_per_day)
  if (hours == null || hours <= 0) {
    hours = inferWorkingHoursPerDayFromUser(u)
  }
  if (monthly == null || monthly <= 0 || days == null || days <= 0 || hours == null || hours <= 0) {
    return { daily: null, hourly: null }
  }
  const daily = monthly / days
  const hourly = daily / hours
  return { daily: Number(daily.toFixed(2)), hourly: Number(hourly.toFixed(2)) }
}

function resolveDisplayDailyRate(u) {
  if (!u) return null
  const derived = parseSalaryNumeric(u.schedule_derived_daily_rate)
  if (derived != null && derived > 0) return derived
  const fromSchedule = computeRatesFromScheduleFields(u).daily
  if (fromSchedule != null && fromSchedule > 0) return fromSchedule
  const stored = parseSalaryNumeric(u.daily_rate)
  if (stored != null && stored > 0) return stored
  return null
}

function resolveDisplayHourlyRate(u) {
  if (!u) return null
  const derived = parseSalaryNumeric(u.schedule_derived_hourly_rate)
  if (derived != null && derived > 0) return derived
  const fromSchedule = computeRatesFromScheduleFields(u).hourly
  if (fromSchedule != null && fromSchedule > 0) return fromSchedule
  const stored = parseSalaryNumeric(u.hourly_rate)
  if (stored != null && stored > 0) return stored
  const daily = resolveDisplayDailyRate(u)
  const hours = inferWorkingHoursPerDayFromUser(u)
  if (daily != null && daily > 0 && hours != null && hours > 0) {
    return Number((daily / hours).toFixed(2))
  }
  return null
}

function formatFileSize(bytes) {
  const n = Number(bytes) || 0
  if (n <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const idx = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)))
  const value = n / 1024 ** idx
  return `${value.toFixed(value >= 10 || idx === 0 ? 0 : 1)} ${units[idx]}`
}

/** Prefer a human-readable document name; if document_name looks like a hash, derive from file path/url. */
function getDocumentDisplayName(doc) {
  const name = String(doc?.document_name || '').trim()
  const looksLikeHash = /^[a-zA-Z0-9_.-]{16,}$/.test(name) && !/\s/.test(name)
  if (looksLikeHash && doc?.file) {
    const url = doc.file.url || doc.file.path || ''
    const segment = url.split('/').filter(Boolean).pop() || ''
    try {
      const decoded = decodeURIComponent(segment)
      if (decoded && decoded !== segment) return decoded
    } catch {
      // use segment as-is
    }
    if (segment) return segment
  }
  return name || 'Document'
}

function FieldHint({ children }) {
  return <p className="text-xs text-muted-foreground">{children}</p>
}

export default function EmployeeProfile() {
  const { user, setUser } = useAuth()
  const queryClient = useQueryClient()
  const location = useLocation()
  const navigate = useNavigate()
  const { employeeId: routeEmployeeId } = useParams()
  const [viewedUser, setViewedUser] = useState(null)
  const [compensationSummary, setCompensationSummary] = useState(null)
  const [payCyclePreview, setPayCyclePreview] = useState(null)
  const [profileGovNumbers, setProfileGovNumbers] = useState(null)
  const [canEditOwnProfileFromApi, setCanEditOwnProfileFromApi] = useState(null)
  const [payrollPeriods, setPayrollPeriods] = useState([])
  const [payrollPeriodsLoading, setPayrollPeriodsLoading] = useState(false)
  const [payrollPeriodsError, setPayrollPeriodsError] = useState('')
  const [activeTab, setActiveTab] = useState('profile')
  const salaryProfileHydratedRef = useRef(false)

  const isReadOnly =
    routeEmployeeId != null &&
    String(routeEmployeeId).trim() !== '' &&
    Number(routeEmployeeId) !== Number(user?.id)
  const effectiveProfileId =
    routeEmployeeId != null && String(routeEmployeeId).trim() !== ''
      ? Number(routeEmployeeId)
      : Number(user?.id)
  const isInvalidRouteProfileId =
    routeEmployeeId != null && String(routeEmployeeId).trim() !== '' && Number.isNaN(Number(routeEmployeeId))
  const canLoadProfile = Boolean(user?.id || routeEmployeeId)
  const displayUser = isReadOnly ? viewedUser : user
  const salaryViewRates = useMemo(
    () => ({
      daily: resolveDisplayDailyRate(displayUser),
      hourly: resolveDisplayHourlyRate(displayUser),
    }),
    [
      displayUser?.id,
      displayUser?.hourly_rate,
      displayUser?.daily_rate,
      displayUser?.schedule_derived_hourly_rate,
      displayUser?.schedule_derived_daily_rate,
      displayUser?.monthly_salary,
      displayUser?.monthly_rate,
      displayUser?.schedule_working_days_per_month,
      displayUser?.schedule_working_days_in_calendar_month,
      displayUser?.schedule_rate_divisor_source,
      displayUser?.schedule_working_hours_per_day,
      displayUser?.working_schedule_time,
      displayUser?.working_schedule_break_start,
      displayUser?.working_schedule_break_end,
      displayUser?.working_schedule_name,
    ]
  )
  const permissionSet = new Set(user?.permissions ?? [])
  const roleValue = String(user?.role || '').toLowerCase()
  const hrRoleValue = String(user?.hr_role || '').toLowerCase()
  const isAdminOrHr = roleValue === 'admin' || hrRoleValue === 'admin' || hrRoleValue.includes('hr')
  const canEdit = !isReadOnly && (
    canEditOwnProfileFromApi === true
    || permissionSet.has('profile.edit')
    || permissionSet.has('edit-own-profile')
    || isAdminOrHr
  )
  const canEditPhoto = !isReadOnly && (permissionSet.has('profile.picture.edit') || canEdit)
  const canViewPayrollHistory = permissionSet.has('payroll.view')

  const effectivePayCyclePreview = useMemo(
    () => payCyclePreview ?? compensationSummary?.pay_cycle_preview ?? null,
    [payCyclePreview, compensationSummary?.pay_cycle_preview],
  )

  const salaryScheduleHint = useMemo(
    () => buildSalaryScheduleSummaryLine(displayUser),
    [
      displayUser?.id,
      displayUser?.working_schedule_name,
      displayUser?.working_schedule_time,
      displayUser?.schedule_working_days_per_month,
      displayUser?.schedule_working_days_in_calendar_month,
      displayUser?.schedule_rate_divisor_source,
      displayUser?.schedule_working_hours_per_day,
      displayUser?.working_schedule_break_start,
      displayUser?.working_schedule_break_end,
    ],
  )

  const salaryBasicValue = useMemo(() => {
    const n = Number(compensationSummary?.basic_salary ?? displayUser?.monthly_salary ?? 0)
    return Number.isFinite(n) ? n : 0
  }, [compensationSummary?.basic_salary, displayUser?.monthly_salary])

  const salaryGrossValue = useMemo(() => {
    const n = Number(compensationSummary?.totals?.gross_earnings ?? 0)
    return Number.isFinite(n) ? n : 0
  }, [compensationSummary?.totals?.gross_earnings])

  const salaryNeedsCompensationAssignment = salaryBasicValue <= 0 && salaryGrossValue <= 0
  const canOpenCompensationAssignment = Boolean(user?.can_access_hr_panel && isAdminOrHr)

  const handleViewAllPayroll = useCallback(() => {
    if (!user?.can_access_hr_panel) {
      toast.info('Full payroll registers are available in the HR panel.')
      return
    }
    navigate(hrPanelPath(getHrPanelBasePath(user), 'daily-computation'))
  }, [user, navigate])

  const handleOpenCompensationAssignment = useCallback(() => {
    if (!canOpenCompensationAssignment) return
    navigate(
      `${hrPanelPath(getHrPanelBasePath(user), 'compensation/employee-compensation')}?employee_id=${encodeURIComponent(String(effectiveProfileId || ''))}`
    )
  }, [canOpenCompensationAssignment, navigate, user, effectiveProfileId])

  const [loadError, setLoadError] = useState('')

  const [skills, setSkills] = useState([]) // [{ id, name }]
  const [skillsLoading, setSkillsLoading] = useState(false)
  const [skillsSaving, setSkillsSaving] = useState(false)
  const [skillAddOpen, setSkillAddOpen] = useState(false)
  const [skillRenameOpen, setSkillRenameOpen] = useState(false)
  const [skillPreviewOpen, setSkillPreviewOpen] = useState(false)
  const [activeSkill, setActiveSkill] = useState(null)
  const [skillDraft, setSkillDraft] = useState('')
  const [skillDraftError, setSkillDraftError] = useState('')
  const [skillSuggestions, setSkillSuggestions] = useState([])
  const [skillSuggestionsLoading, setSkillSuggestionsLoading] = useState(false)
  const skillInputRef = useRef(null)

  const [certifications, setCertifications] = useState([])
  const [certificationsLoading, setCertificationsLoading] = useState(false)
  const [certificationsSaving, setCertificationsSaving] = useState(false)
  const [certModalOpen, setCertModalOpen] = useState(false)
  const [certEditModalOpen, setCertEditModalOpen] = useState(false)
  const [certPreviewOpen, setCertPreviewOpen] = useState(false)
  const [certDeleteOpen, setCertDeleteOpen] = useState(false)
  const [certToDelete, setCertToDelete] = useState(null)
  const [activeCert, setActiveCert] = useState(null)
  const [certForm, setCertForm] = useState({
    certification_name: '',
    issuing_organization: '',
    issue_date: '',
    expiration_date: '',
    credential_id: '',
    credential_url: '',
    certificate_file: null,
  })
  const [certErrors, setCertErrors] = useState({})
  const certFileRef = useRef(null)

  const [govIdDocs, setGovIdDocs] = useState([])
  const [govIdDocsLoading, setGovIdDocsLoading] = useState(false)
  const [govIdDocsSaving, setGovIdDocsSaving] = useState(false)

  const salaryTinResolution = useMemo(
    () => resolveTinForSalaryDisplay(govIdDocs, profileGovNumbers?.tin_number, { loading: govIdDocsLoading }),
    [govIdDocs, profileGovNumbers?.tin_number, govIdDocsLoading],
  )
  const [govAddOpen, setGovAddOpen] = useState(false)
  const [govEditOpen, setGovEditOpen] = useState(false)
  const [govPreviewOpen, setGovPreviewOpen] = useState(false)
  const [govDeleteOpen, setGovDeleteOpen] = useState(false)
  const [govDeleteDoc, setGovDeleteDoc] = useState(null)
  const [activeGovDoc, setActiveGovDoc] = useState(null)
  const [govIdForm, setGovIdForm] = useState({
    id_type: '',
    id_number: '',
    issuing_agency: '',
    expiry_date: '',
    document_file: null,
  })
  const [govIdErrors, setGovIdErrors] = useState({})
  const govFileRef = useRef(null)

  const documentCategories = useMemo(
    () => ([
      'Contracts',
      'IDs',
      'Certifications',
      'Disciplinary Records',
      'Medical Documents',
      'Performance Evaluations',
    ]),
    []
  )
  const [docs, setDocs] = useState([])
  const [docsLoading, setDocsLoading] = useState(false)
  const [docsSaving, setDocsSaving] = useState(false)
  const [docsCategory, setDocsCategory] = useState('Contracts')
  const [docsSearch, setDocsSearch] = useState('')
  const [docsUploading, setDocsUploading] = useState(false)
  const [docsUploadProgress, setDocsUploadProgress] = useState({ total: 0, done: 0 })
  const [docUploadOpen, setDocUploadOpen] = useState(false)
  const [docEditOpen, setDocEditOpen] = useState(false)
  const [docPreviewOpen, setDocPreviewOpen] = useState(false)
  const [docDeleteOpen, setDocDeleteOpen] = useState(false)
  const [activeDoc, setActiveDoc] = useState(null)
  const [docForm, setDocForm] = useState({ category: 'Contracts', document_name: '', version: '', expiry_date: '', file: null })
  const [docErrors, setDocErrors] = useState({})
  const docFileRef = useRef(null)
  const docDropZoneRef = useRef(null)
  const [docDragOver, setDocDragOver] = useState(false)
  const [docxPreviewHtml, setDocxPreviewHtml] = useState('')
  const [docxPreviewLoading, setDocxPreviewLoading] = useState(false)
  const [docxPreviewError, setDocxPreviewError] = useState('')

  const docsByCategory = useMemo(() => {
    const map = {}
    for (const c of documentCategories) map[c] = []
    for (const d of Array.isArray(docs) ? docs : []) {
      const cat = String(d?.category || '').trim()
      if (!cat) continue
      if (!map[cat]) map[cat] = []
      map[cat].push(d)
    }
    return map
  }, [docs, documentCategories])

  const filteredDocs = useMemo(() => {
    const base = docsByCategory[docsCategory] || []
    if (!docsSearch.trim()) return base
    const q = docsSearch.trim().toLowerCase()
    return base.filter((d) => {
      const name = String(d.document_name || d.name || '').toLowerCase()
      const kind = String(getDocFileKind(d?.file)).toLowerCase()
      const date = String(d.created_at || '').toLowerCase()
      return name.includes(q) || kind.includes(q) || date.includes(q)
    })
  }, [docsByCategory, docsCategory, docsSearch])

  const folderRawDocCount = useMemo(
    () => (docsByCategory[docsCategory] || []).length,
    [docsByCategory, docsCategory],
  )
  const showDocumentsEmptyOnboarding =
    !docsLoading && folderRawDocCount === 0 && !docsSearch.trim()

  const docFolderGuidance = useMemo(() => getDocFolderGuidance(docsCategory), [docsCategory])

  const [emergencyContacts, setEmergencyContacts] = useState([])
  const [emergencyForm, setEmergencyForm] = useState(createEmptyEmergencyContact())
  const [editingEmergencyId, setEditingEmergencyId] = useState('')
  const [emergencyErrors, setEmergencyErrors] = useState({})
  const [emergencySaving, setEmergencySaving] = useState(false)

  const [personal, setPersonal] = useState({
    first_name: '',
    middle_name: '',
    last_name: '',
    date_of_birth: '',
    gender: '',
    civil_status: '',
    nationality: '',
    home_address: '',
    street_address: '',
    barangay: '',
    city: '',
    province: '',
    postal_code: '',
  })
  const [personalErrors, setPersonalErrors] = useState({})
  const [personalSaving, setPersonalSaving] = useState(false)
  const [exportingProfileCsv, setExportingProfileCsv] = useState(false)
  /** Optimistic "Saved" on Profile tab before PATCH completes; rolls back label on error. */
  const [personalSaveStatus, setPersonalSaveStatus] = useState('idle')
  const [signatureBusy, setSignatureBusy] = useState(false)
  const [signatureDialogOpen, setSignatureDialogOpen] = useState(false)

  const profileCompletion = useMemo(() => {
    const checks = [
      { label: 'First Name', done: String(personal.first_name || '').trim() !== '' },
      { label: 'Last Name', done: String(personal.last_name || '').trim() !== '' },
      { label: 'Date of Birth', done: String(personal.date_of_birth || '').trim() !== '' },
      { label: 'Gender', done: String(personal.gender || '').trim() !== '' },
      { label: 'Home Address', done: String(personal.home_address || '').trim() !== '' },
      { label: 'Profile Photo', done: !!employeeAvatarSrc(displayUser) },
      { label: 'Phone Number', done: !!(displayUser?.phone_number) },
      { label: 'Skills', done: skills.length > 0 },
      { label: 'Emergency Contact', done: emergencyContacts.length > 0 },
      { label: 'Government ID', done: govIdDocs.length > 0 },
    ]
    const done = checks.filter((c) => c.done).length
    const total = checks.length
    const percent = Math.round((done / total) * 100)
    const missing = checks.filter((c) => !c.done).map((c) => c.label)
    return { percent, done, total, missing }
  }, [personal, displayUser, skills, govIdDocs, emergencyContacts])

  const dobAge = useMemo(() => {
    const raw = String(personal.date_of_birth || '').trim()
    if (!raw) return null
    const d = new Date(raw)
    if (Number.isNaN(d.getTime())) return null
    const today = new Date()
    let age = today.getFullYear() - d.getFullYear()
    const m = today.getMonth() - d.getMonth()
    if (m < 0 || (m === 0 && today.getDate() < d.getDate())) age -= 1
    return age
  }, [personal.date_of_birth])

  const homeAddressAutocompleteRef = useRef(null)
  const streetAddressAutocompleteRef = useRef(null)
  const barangayAutocompleteRef = useRef(null)
  const cityAutocompleteRef = useRef(null)
  const provinceAutocompleteRef = useRef(null)
  const emergencyAddressAutocompleteRef = useRef(null)
  const { isLoaded: isMapsLoaded, loadError: mapsLoadError } = useGoogleMapsLoader()

  const applyMappedHomeAddress = useCallback((place) => {
    const mapped = mapPlaceToAddressFields(place)
    setPersonal((prev) => ({
      ...prev,
      home_address: mapped.full_address || prev.home_address,
      street_address: mapped.street_address || '',
      barangay: mapped.barangay || '',
      city: mapped.city || '',
      province: mapped.province || '',
      postal_code: String(mapped.postal_code || '').replace(/[^\d]/g, '').slice(0, 4),
    }))
  }, [])

  const makeProfilePlaceChangedHandler = useCallback(
    (ref) => () => {
      const instance = ref?.current
      if (!instance || typeof instance.getPlace !== 'function') return
      const place = instance.getPlace()
      if (!place) return
      applyMappedHomeAddress(place)
    },
    [applyMappedHomeAddress]
  )

  const [benefits, setBenefits] = useState([])
  const [leaveCreditsInfo, setLeaveCreditsInfo] = useState(null)
  const [leaveCreditsLoading, setLeaveCreditsLoading] = useState(false)

  useEffect(() => {
    setLeaveCreditsInfo(null)
    setLeaveCreditsLoading(false)
  }, [effectiveProfileId, isReadOnly])

  const [photoLoading, setPhotoLoading] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const photoInputRef = useRef(null)
  const [cropOpen, setCropOpen] = useState(false)
  const [pendingPhotoFile, setPendingPhotoFile] = useState(null)
  const [removePhotoConfirmOpen, setRemovePhotoConfirmOpen] = useState(false)

  const [email, setEmail] = useState('')
  const [emailError, setEmailError] = useState('')
  const [emailLoading, setEmailLoading] = useState(false)

  const [phone, setPhone] = useState('')
  const [phoneError, setPhoneError] = useState('')
  const [phoneLoading, setPhoneLoading] = useState(false)

  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [passwordErrors, setPasswordErrors] = useState({ current: '', new: '', confirm: '' })
  const [passwordLoading, setPasswordLoading] = useState(false)

  const [livenessOpen, setLivenessOpen] = useState(false)
  const [pendingUpdate, setPendingUpdate] = useState(null) // { type, payload }

  const mergeAuthUser = useCallback((nextUser) => {
    if (!nextUser || typeof nextUser !== 'object') return
    setUser((prev) => ({
      ...(prev && typeof prev === 'object' ? prev : {}),
      ...nextUser,
    }))
  }, [setUser])

  const initials = useMemo(() => {
    const name = displayUser?.name ? String(displayUser.name).trim() : ''
    if (!name) return '?'
    return name.split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)
  }, [displayUser?.name])

  const avatarSrc = employeeAvatarSrc(displayUser)

  const applyServerProfile = useCallback((data, options = {}) => {
    const { skipAuthUser = false } = options
    if (!data) return
    setCanEditOwnProfileFromApi(data?.permissions?.can_edit_own_profile === true)
    if (!skipAuthUser && data.user) mergeAuthUser(data.user)
    if (skipAuthUser && data.user) setViewedUser(data.user)
    const u = data.user
    if (!u) return
    const parsedAddress = parseComposedHomeAddress(u?.home_address)
    setPersonal({
      first_name: toStr(u?.first_name),
      middle_name: toStr(u?.middle_name),
      last_name: toStr(u?.last_name),
      date_of_birth: toStr(u?.date_of_birth),
      gender: toStr(u?.gender),
      civil_status: toStr(u?.civil_status),
      nationality: toStr(u?.nationality),
      home_address: toStr(u?.home_address),
      street_address: parsedAddress.street_address,
      barangay: parsedAddress.barangay,
      city: parsedAddress.city,
      province: parsedAddress.province,
      postal_code: parsedAddress.postal_code,
    })
    setPersonalErrors({})
    setEmergencyContacts(Array.isArray(data?.emergency_contacts)
      ? data.emergency_contacts.map((item, index) => ({
          id: item?.id || `emergency-${index}`,
          full_name: item.full_name || '',
          relationship: item.relationship || '',
          phone_number: item.phone_number || '',
          address: item.address || '',
          is_primary: !!item.is_primary,
        }))
      : [])
    setBenefits(Array.isArray(data?.benefits) ? data.benefits : [])
    setCompensationSummary(data?.compensation_summary && typeof data.compensation_summary === 'object' ? data.compensation_summary : null)
    setPayCyclePreview(data?.pay_cycle_preview || data?.compensation_summary?.pay_cycle_preview || null)
    if (data?.government_ids && typeof data.government_ids === 'object' && !Array.isArray(data.government_ids)) {
      setProfileGovNumbers(data.government_ids)
    } else {
      setProfileGovNumbers(null)
    }
    setLeaveCreditsInfo((prev) =>
      data?.leave_credits && typeof data.leave_credits === 'object' ? data.leave_credits : prev
    )
    setEmail(toStr(u?.email))
    setPhone(toStr(u?.phone_number))
  }, [mergeAuthUser, setUser])

  const applyPersonalFieldsFromServerUser = useCallback((u) => {
    if (!u) return
    const parsedAddress = parseComposedHomeAddress(u?.home_address)
    setPersonal((prev) => ({
      ...prev,
      first_name: toStr(u?.first_name),
      middle_name: toStr(u?.middle_name),
      last_name: toStr(u?.last_name),
      date_of_birth: toStr(u?.date_of_birth),
      gender: toStr(u?.gender),
      civil_status: toStr(u?.civil_status),
      nationality: toStr(u?.nationality),
      home_address: toStr(u?.home_address),
      street_address: parsedAddress.street_address,
      barangay: parsedAddress.barangay,
      city: parsedAddress.city,
      province: parsedAddress.province,
      postal_code: parsedAddress.postal_code,
    }))
    setPersonalErrors({})
  }, [])

  const profileSnapshotQuery = useQuery({
    queryKey: ['employee-profile-snapshot', { employeeId: effectiveProfileId, readOnly: isReadOnly, routeKey: location.key }],
    enabled: canLoadProfile && !isInvalidRouteProfileId,
    // Self-service: always treat snapshot as stale so HR edits (and server cache bust) show up on revisit/focus.
    staleTime: isReadOnly ? 20 * 60 * 1000 : 0,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: !isReadOnly,
    queryFn: async () => {
      if (isReadOnly) {
        return getEmployeeProfileSnapshot(effectiveProfileId, {
          include_government_ids: true,
          include_emergency_contacts: true,
          include_benefits: false,
          include_leave_credits: false,
          include_compensation_summary: false,
        })
      }
      return getMyEmployeeProfile({
        include_benefits: false,
        include_leave_credits: false,
        include_compensation_summary: false,
      })
    },
  })

  const loading = profileSnapshotQuery.isLoading

  useEffect(() => {
    const onAdminUpdatedEmployee = (ev) => {
      const tid = ev?.detail?.employeeId
      if (tid == null || Number(tid) !== Number(effectiveProfileId)) return
      void queryClient.invalidateQueries({ queryKey: ['employee-profile-snapshot'] })
    }
    window.addEventListener('hr:admin-updated-employee', onAdminUpdatedEmployee)
    return () => window.removeEventListener('hr:admin-updated-employee', onAdminUpdatedEmployee)
  }, [effectiveProfileId, queryClient])

  useEffect(() => {
    salaryProfileHydratedRef.current = false
  }, [effectiveProfileId, isReadOnly])

  const refreshProfileQuiet = useCallback(async () => {
    if (isReadOnly) return
    try {
      const data = await getMyEmployeeProfile({
        include_benefits: false,
        include_leave_credits: false,
        include_compensation_summary: false,
      })
      applyServerProfile(data)
    } catch (e) {
      toast.error(e?.message || 'Failed to refresh profile')
    }
  }, [applyServerProfile, isReadOnly])

  /** Re-fetch profile when opening Salary so auth user + schedule metrics match server (avoids stale localStorage / 22-day fallback). */
  useEffect(() => {
    if (isReadOnly || activeTab !== 'salary') return
    if (salaryProfileHydratedRef.current) return
    getMyEmployeeProfile({
      include_benefits: false,
      include_leave_credits: false,
      include_leave_credits_history: false,
      include_compensation_summary: true,
    })
      .then((data) => {
        applyServerProfile(data)
        salaryProfileHydratedRef.current = true
      })
      .catch((e) => toast.error(e?.message || 'Failed to refresh salary profile'))
  }, [activeTab, isReadOnly, applyServerProfile])

  useEffect(() => {
    if (activeTab !== 'employment') return
    if (leaveCreditsInfo) return
    let alive = true
    setLeaveCreditsLoading(true)
    const load = isReadOnly && effectiveProfileId
      ? getEmployeeProfileSnapshot(effectiveProfileId, {
        include_government_ids: false,
        include_emergency_contacts: false,
        include_benefits: false,
        include_leave_credits: true,
        include_leave_credits_history: false,
        include_compensation_summary: false,
      })
      : getMyEmployeeProfile({
        include_benefits: false,
        include_leave_credits: true,
        include_leave_credits_history: false,
        include_compensation_summary: false,
      })
    load
      .then((data) => {
        if (!alive) return
        setLeaveCreditsInfo(data?.leave_credits && typeof data.leave_credits === 'object' ? data.leave_credits : null)
      })
      .catch(() => {})
      .finally(() => {
        if (alive) setLeaveCreditsLoading(false)
      })
    return () => {
      alive = false
    }
  }, [activeTab, leaveCreditsInfo, isReadOnly, effectiveProfileId])

  useEffect(() => {
    const onCompensationCatalogChanged = () => {
      if (isReadOnly && effectiveProfileId) {
        getEmployeeProfileSnapshot(effectiveProfileId, {
          include_government_ids: true,
          include_emergency_contacts: true,
          include_benefits: false,
          include_leave_credits: false,
          include_compensation_summary: false,
        })
          .then((data) => applyServerProfile(data, { skipAuthUser: true }))
          .catch(() => {})
        return
      }
      if (!isReadOnly) {
        refreshProfileQuiet()
      }
    }
    window.addEventListener('hr:pay-components-changed', onCompensationCatalogChanged)
    window.addEventListener('hr:deduction-schedule-changed', onCompensationCatalogChanged)
    return () => {
      window.removeEventListener('hr:pay-components-changed', onCompensationCatalogChanged)
      window.removeEventListener('hr:deduction-schedule-changed', onCompensationCatalogChanged)
    }
  }, [isReadOnly, effectiveProfileId, applyServerProfile, refreshProfileQuiet])

  useEffect(() => {
    if (isInvalidRouteProfileId) {
      setLoadError('Invalid profile link.')
      return
    }
    if (profileSnapshotQuery.error) {
      setLoadError(profileSnapshotQuery.error?.message || 'Failed to load profile')
      return
    }
    if (!profileSnapshotQuery.data) return
    setLoadError('')
    applyServerProfile(profileSnapshotQuery.data, { skipAuthUser: isReadOnly })
  }, [isInvalidRouteProfileId, profileSnapshotQuery.data, profileSnapshotQuery.error, applyServerProfile, isReadOnly])

  const skillsQuery = useQuery({
    queryKey: ['employee-profile-skills', { employeeId: effectiveProfileId, readOnly: isReadOnly }],
    enabled: Boolean(effectiveProfileId) && activeTab === 'skills',
    staleTime: 20 * 60 * 1000,
    refetchOnWindowFocus: false,
    queryFn: () => (isReadOnly ? getEmployeeSkills(effectiveProfileId) : getMySkills()),
  })

  const certificationsQuery = useQuery({
    queryKey: ['employee-profile-certifications', { employeeId: effectiveProfileId, readOnly: isReadOnly }],
    enabled: Boolean(effectiveProfileId) && activeTab === 'skills',
    staleTime: 20 * 60 * 1000,
    refetchOnWindowFocus: false,
    queryFn: () => (isReadOnly ? getEmployeeCertifications(effectiveProfileId) : getMyCertifications()),
  })

  const governmentIdsQuery = useQuery({
    queryKey: ['employee-profile-government-ids', { employeeId: effectiveProfileId, readOnly: isReadOnly }],
    /** Salary tab TIN card uses the same documents as Gov IDs — fetch whenever either tab is open. */
    enabled: Boolean(effectiveProfileId) && (activeTab === 'government' || activeTab === 'salary'),
    staleTime: 20 * 60 * 1000,
    refetchOnWindowFocus: false,
    queryFn: () => (isReadOnly ? getEmployeeGovernmentIdDocuments(effectiveProfileId) : getMyGovernmentIdDocuments()),
  })

  const documentsQuery = useQuery({
    queryKey: ['employee-profile-documents', { employeeId: effectiveProfileId, readOnly: isReadOnly }],
    enabled: Boolean(effectiveProfileId) && activeTab === 'documents',
    staleTime: 10 * 60 * 1000,
    refetchOnWindowFocus: false,
    queryFn: () => (isReadOnly ? getEmployeeDocuments(effectiveProfileId) : getMyDocuments()),
  })

  const payrollPeriodsQuery = useQuery({
    queryKey: ['employee-profile-payroll-periods', { employeeId: effectiveProfileId }],
    enabled: Boolean(effectiveProfileId) && activeTab === 'salary' && canViewPayrollHistory,
    staleTime: 10 * 60 * 1000,
    refetchOnWindowFocus: false,
    queryFn: () => getPayrollPeriodsForEmployee(effectiveProfileId, { per_page: 6 }),
  })

  useEffect(() => {
    setSkillsLoading(skillsQuery.isLoading || skillsQuery.isFetching)
    if (skillsQuery.error) {
      setSkills([])
      toast.error(skillsQuery.error?.message || 'Failed to load skills.')
      return
    }
    if (skillsQuery.data) {
      setSkills(Array.isArray(skillsQuery.data?.skills) ? skillsQuery.data.skills : [])
    }
  }, [skillsQuery.data, skillsQuery.error, skillsQuery.isLoading, skillsQuery.isFetching])

  useEffect(() => {
    setCertificationsLoading(certificationsQuery.isLoading || certificationsQuery.isFetching)
    if (certificationsQuery.error) {
      setCertifications([])
      toast.error(certificationsQuery.error?.message || 'Failed to load certifications.')
      return
    }
    if (certificationsQuery.data) {
      setCertifications(Array.isArray(certificationsQuery.data?.certifications) ? certificationsQuery.data.certifications : [])
    }
  }, [certificationsQuery.data, certificationsQuery.error, certificationsQuery.isLoading, certificationsQuery.isFetching])

  useEffect(() => {
    setGovIdDocsLoading(governmentIdsQuery.isLoading || governmentIdsQuery.isFetching)
    if (governmentIdsQuery.error) {
      setGovIdDocs([])
      toast.error(governmentIdsQuery.error?.message || 'Failed to load government IDs.')
      return
    }
    if (governmentIdsQuery.data) {
      setGovIdDocs(Array.isArray(governmentIdsQuery.data?.government_ids) ? governmentIdsQuery.data.government_ids : [])
    }
  }, [governmentIdsQuery.data, governmentIdsQuery.error, governmentIdsQuery.isLoading, governmentIdsQuery.isFetching])

  useEffect(() => {
    setDocsLoading(documentsQuery.isLoading || documentsQuery.isFetching)
    if (documentsQuery.error) {
      setDocs([])
      toast.error(documentsQuery.error?.message || 'Failed to load documents.')
      return
    }
    if (documentsQuery.data) {
      setDocs(Array.isArray(documentsQuery.data?.documents) ? documentsQuery.data.documents : [])
    }
  }, [documentsQuery.data, documentsQuery.error, documentsQuery.isLoading, documentsQuery.isFetching])

  useEffect(() => {
    setPayrollPeriodsLoading(payrollPeriodsQuery.isLoading || payrollPeriodsQuery.isFetching)
    if (payrollPeriodsQuery.error) {
      setPayrollPeriods([])
      setPayrollPeriodsError(payrollPeriodsQuery.error?.message || 'Could not load payroll history.')
      return
    }
    if (payrollPeriodsQuery.data) {
      setPayrollPeriods(Array.isArray(payrollPeriodsQuery.data?.data) ? payrollPeriodsQuery.data.data : [])
      setPayrollPeriodsError('')
    }
  }, [payrollPeriodsQuery.data, payrollPeriodsQuery.error, payrollPeriodsQuery.isLoading, payrollPeriodsQuery.isFetching])

  useEffect(() => {
    let alive = true
    async function run() {
      if (!docPreviewOpen || !activeDoc?.file?.url) return
      const kind = getDocFileKind(activeDoc.file)
      if (kind !== 'docx') {
        setDocxPreviewHtml('')
        setDocxPreviewError('')
        setDocxPreviewLoading(false)
        return
      }

      setDocxPreviewLoading(true)
      setDocxPreviewError('')
      setDocxPreviewHtml('')
      try {
        const res = await fetch(activeDoc.file.url, { credentials: 'include' })
        if (!res.ok) throw new Error('Failed to load DOCX for preview.')
        const arrayBuffer = await res.arrayBuffer()
        if (!alive) return
        const result = await mammoth.convertToHtml({ arrayBuffer }, { includeDefaultStyleMap: true })
        if (!alive) return
        setDocxPreviewHtml(result?.value || '')
      } catch (e) {
        if (!alive) return
        setDocxPreviewError(e?.message || 'DOCX preview failed.')
      } finally {
        if (alive) setDocxPreviewLoading(false)
      }
    }
    run()
    return () => { alive = false }
  }, [docPreviewOpen, activeDoc?.file])

  useEffect(() => {
    if (!skillAddOpen) return
    const q = String(skillDraft || '').trim()
    let alive = true
    setSkillSuggestionsLoading(true)
    const t = setTimeout(() => {
      getSkillSuggestions(q, 8)
        .then((data) => {
          if (!alive) return
          setSkillSuggestions(Array.isArray(data?.suggestions) ? data.suggestions : [])
        })
        .catch(() => {
          if (!alive) return
          setSkillSuggestions([])
        })
        .finally(() => alive && setSkillSuggestionsLoading(false))
    }, 200)
    return () => {
      alive = false
      clearTimeout(t)
    }
  }, [skillAddOpen, skillDraft])

  function openAddSkillModal(seed = '') {
    if (!canEdit) return
    setActiveSkill(null)
    setSkillDraft(seed)
    setSkillDraftError('')
    setSkillSuggestions([])
    setSkillAddOpen(true)
    setTimeout(() => skillInputRef.current?.focus(), 0)
  }

  function openRenameSkillModal(skill) {
    if (!canEdit) return
    setActiveSkill(skill)
    setSkillDraft(String(skill?.name || ''))
    setSkillDraftError('')
    setSkillRenameOpen(true)
    setTimeout(() => skillInputRef.current?.focus(), 0)
  }

  function openSkillPreview(skill) {
    setActiveSkill(skill)
    setSkillPreviewOpen(true)
  }

  async function submitAddSkill() {
    if (!canEdit) return
    const value = String(skillDraft || '').trim()
    if (!value || skillsSaving) return
    if (skills.some((s) => String(s?.name || '').toLowerCase() === value.toLowerCase())) {
      setSkillDraftError('Skill already exists.')
      return
    }
    setSkillDraftError('')
    setSkillsSaving(true)
    try {
      const data = await addMySkill(value)
      const created = data?.skill
      if (created?.id) {
        setSkills((prev) => [...prev, created].sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || ''))))
      } else {
        await skillsQuery.refetch()
      }
      setSkillAddOpen(false)
      setSkillDraft('')
      setSkillSuggestions([])
      toast.success('Skill added.')
    } catch (e) {
      toast.error(e?.message || 'Failed to add skill.')
    } finally {
      setSkillsSaving(false)
    }
  }

  async function submitRenameSkill() {
    if (!canEdit) return
    if (!activeSkill?.id || skillsSaving) return
    const value = String(skillDraft || '').trim()
    if (!value) {
      setSkillDraftError('Skill name is required.')
      return
    }
    if (skills.some((s) => String(s?.id) !== String(activeSkill.id) && String(s?.name || '').toLowerCase() === value.toLowerCase())) {
      setSkillDraftError('Skill already exists.')
      return
    }
    setSkillDraftError('')
    setSkillsSaving(true)
    try {
      const data = await updateMySkill(activeSkill.id, value)
      const updated = data?.skill
      setSkills((prev) =>
        prev
          .map((s) => (String(s?.id) === String(activeSkill.id) ? (updated?.id ? updated : { ...s, name: value }) : s))
          .sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || '')))
      )
      setSkillRenameOpen(false)
      setActiveSkill(null)
      setSkillDraft('')
      toast.success('Skill updated.')
    } catch (e) {
      toast.error(e?.message || 'Failed to update skill.')
    } finally {
      setSkillsSaving(false)
    }
  }

  async function handleRemoveSkill(skill) {
    if (!canEdit) return
    if (!skill?.id || skillsSaving) return
    if (!window.confirm('Remove this skill?')) return
    setSkillsSaving(true)
    try {
      await removeMySkill(skill.id)
      setSkills((prev) => prev.filter((s) => String(s?.id) !== String(skill.id)))
      toast.success('Skill removed.')
    } catch (e) {
      toast.error(e?.message || 'Failed to remove skill.')
    } finally {
      setSkillsSaving(false)
    }
  }

  function resetCertForm(next = {}) {
    setCertErrors({})
    setCertForm({
      certification_name: '',
      issuing_organization: '',
      issue_date: '',
      expiration_date: '',
      credential_id: '',
      credential_url: '',
      certificate_file: null,
      ...next,
    })
  }

  function validateCertificationForm(next) {
    const errs = {}
    if (!String(next.certification_name || '').trim()) errs.certification_name = 'Certification name is required.'
    if (!String(next.issuing_organization || '').trim()) errs.issuing_organization = 'Issuing organization is required.'
    const issue = String(next.issue_date || '').trim()
    if (!/^\d{4}-\d{2}-\d{2}$/.test(issue)) errs.issue_date = 'Issue date is required.'
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const issueDate = issue ? new Date(`${issue}T00:00:00`) : null
    if (issueDate && issueDate.getTime() > today.getTime()) errs.issue_date = 'Issue date cannot be in the future.'
    const exp = String(next.expiration_date || '').trim()
    if (exp && !/^\d{4}-\d{2}-\d{2}$/.test(exp)) errs.expiration_date = 'Use a valid date (YYYY-MM-DD).'
    const expDate = exp ? new Date(`${exp}T00:00:00`) : null
    if (issueDate && expDate && expDate.getTime() <= issueDate.getTime()) errs.expiration_date = 'Expiration date must be after issue date.'
    const file = next.certificate_file
    if (file instanceof File) {
      const type = String(file.type || '').toLowerCase()
      const allowed = ['application/pdf', 'image/jpeg', 'image/png']
      if (!allowed.includes(type)) errs.certificate_file = 'Certificate file must be PDF, JPG, or PNG.'
      const maxBytes = 10 * 1024 * 1024
      if (file.size > maxBytes) errs.certificate_file = 'Certificate file must be 10MB or less.'
    }
    return errs
  }

  function openAddCertificationModal() {
    setActiveCert(null)
    resetCertForm()
    setCertModalOpen(true)
  }

  function openEditCertificationModal(cert) {
    setActiveCert(cert)
    resetCertForm({
      certification_name: cert?.certification_name || '',
      issuing_organization: cert?.issuing_organization || '',
      issue_date: cert?.issue_date || '',
      expiration_date: cert?.expiration_date || '',
      credential_id: cert?.credential_id || '',
      credential_url: cert?.credential_url || '',
      certificate_file: null,
    })
    setCertEditModalOpen(true)
  }

  function openPreviewCertificationModal(cert) {
    setActiveCert(cert)
    setCertPreviewOpen(true)
  }

  async function submitCreateCertification() {
    if (!canEdit) return
    if (certificationsSaving) return
    const errs = validateCertificationForm(certForm)
    setCertErrors(errs)
    if (Object.keys(errs).length) return
    setCertificationsSaving(true)
    try {
      const data = await createMyCertification({
        ...certForm,
        expiration_date: certForm.expiration_date || null,
        credential_id: certForm.credential_id || null,
        credential_url: certForm.credential_url || null,
      })
      const created = data?.certification
      if (created?.id) {
        setCertifications((prev) => [created, ...prev].sort((a, b) => String(b.issue_date || '').localeCompare(String(a.issue_date || ''))))
      } else {
        await certificationsQuery.refetch()
      }
      toast.success('Certification submitted.')
      setCertModalOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to submit certification.')
    } finally {
      setCertificationsSaving(false)
    }
  }

  async function submitUpdateCertification() {
    if (!canEdit) return
    if (!activeCert?.id || certificationsSaving) return
    const errs = validateCertificationForm(certForm)
    setCertErrors(errs)
    if (Object.keys(errs).length) return
    setCertificationsSaving(true)
    try {
      const data = await updateMyCertification(activeCert.id, {
        ...certForm,
        expiration_date: certForm.expiration_date || null,
        credential_id: certForm.credential_id || null,
        credential_url: certForm.credential_url || null,
      })
      const updated = data?.certification
      if (updated?.id) setCertifications((prev) => prev.map((c) => (c.id === activeCert.id ? updated : c)))
      toast.success('Certification updated.')
      setCertEditModalOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to update certification.')
    } finally {
      setCertificationsSaving(false)
    }
  }

  function requestDeleteCertification(cert) {
    if (!cert?.id || certificationsSaving) return
    setCertToDelete(cert)
    setCertDeleteOpen(true)
  }

  async function confirmDeleteCertification() {
    if (!canEdit) return
    if (!certToDelete?.id || certificationsSaving) return
    setCertificationsSaving(true)
    try {
      await deleteMyCertification(certToDelete.id)
      setCertifications((prev) => prev.filter((c) => c.id !== certToDelete.id))
      toast.success('Certification deleted.')
      setCertDeleteOpen(false)
      setCertToDelete(null)
    } catch (e) {
      toast.error(e?.message || 'Failed to delete certification.')
    } finally {
      setCertificationsSaving(false)
    }
  }

  function validatePersonal(next) {
    const errs = {}
    if (!String(next.first_name || '').trim()) errs.first_name = 'First name is required.'
    if (!String(next.last_name || '').trim()) errs.last_name = 'Last name is required.'
    if (String(next.first_name || '').length > 255) errs.first_name = 'First name must be 255 characters or less.'
    if (String(next.middle_name || '').length > 255) errs.middle_name = 'Middle name must be 255 characters or less.'
    if (String(next.last_name || '').length > 255) errs.last_name = 'Last name must be 255 characters or less.'
    if (String(next.nationality || '').length > 100) errs.nationality = 'Nationality must be 100 characters or less.'
    const composed = composeHomeAddress(next)
    if (composed.length > 1000) errs.home_address = 'Home address must be 1000 characters or less.'
    return errs
  }

  function updatePersonalField(field, value) {
    setPersonal((prev) => {
      const next = { ...prev, [field]: value }
      setPersonalErrors(validatePersonal(next))
      return next
    })
  }

  function updatePersonalAddressField(field, value) {
    setPersonal((prev) => {
      const next = { ...prev, [field]: value }
      next.home_address = composeHomeAddress(next) || next.home_address
      setPersonalErrors(validatePersonal(next))
      return next
    })
  }

  async function savePersonal() {
    if (!canEdit) return
    const errs = validatePersonal(personal)
    setPersonalErrors(errs)
    if (Object.keys(errs).length) return
    setPersonalSaving(true)
    setPersonalSaveStatus('saved')
    const composedHomeAddress = composeHomeAddress(personal) || String(personal.home_address ?? '').trim() || null
    const payload = {
      first_name: String(personal.first_name ?? '').trim(),
      middle_name: String(personal.middle_name ?? '').trim() || null,
      last_name: String(personal.last_name ?? '').trim(),
      date_of_birth: personal.date_of_birth ? String(personal.date_of_birth).trim() : null,
      gender: String(personal.gender ?? '').trim() || null,
      civil_status: String(personal.civil_status ?? '').trim() || null,
      nationality: String(personal.nationality ?? '').trim() || null,
      home_address: composedHomeAddress,
    }
    let savedOk = false
    try {
      const data = await updateMyPersonalInfo(payload, { timeoutMs: 45000 })
      if (data?.user) {
        mergeAuthUser(data.user)
        applyPersonalFieldsFromServerUser(data.user)
      }
      void queryClient.invalidateQueries({ queryKey: ['employee-profile-snapshot'] })
      savedOk = true
      toast.success('Profile updated.')
    } catch (e) {
      setPersonalSaveStatus('idle')
      toast.error(e?.message || 'Failed to save profile')
    } finally {
      setPersonalSaving(false)
      if (savedOk) {
        window.setTimeout(() => setPersonalSaveStatus('idle'), 1800)
      }
    }
  }

  async function handleExportMyProfileCsv() {
    setExportingProfileCsv(true)
    try {
      const { blob, filename } = await exportMyProfileCsv()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename || `my_profile_export_${new Date().toISOString().slice(0, 10)}.csv`
      anchor.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
      toast.success('Profile CSV downloaded.')
    } catch (e) {
      toast.error(e?.message || 'Failed to export profile CSV.')
    } finally {
      setExportingProfileCsv(false)
    }
  }

  async function manageSignature() {
    if (!canEdit) return
    setSignatureDialogOpen(true)
  }

  async function saveSignature(dataUrl) {
    if (!canEdit) return
    setSignatureBusy(true)
    try {
      const data = await saveMySignature(dataUrl)
      if (data?.user) mergeAuthUser(data.user)
      toast.success('Signature saved.')
      setSignatureDialogOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to save signature')
    } finally {
      setSignatureBusy(false)
    }
  }

  async function removeSignature() {
    if (!canEdit) return
    setSignatureBusy(true)
    try {
      const data = await clearMySignature()
      if (data?.user) mergeAuthUser(data.user)
      toast.success('Signature removed.')
      setSignatureDialogOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to remove signature')
    } finally {
      setSignatureBusy(false)
    }
  }

  const govIdDefs = useMemo(
    () => {
      const base = {
      'PhilSys National ID': {
        agency: 'PSA (Philippine Statistics Authority)',
        format: 'XXXX-XXXX-XXXX',
        example: '1234-5678-9012',
        pattern: /^\d{4}-\d{4}-\d{4}$/,
        formatter: (digits) => {
          const d = digits.slice(0, 12)
          const p1 = d.slice(0, 4)
          const p2 = d.slice(4, 8)
          const p3 = d.slice(8, 12)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'SSS ID / UMID': {
        agency: 'Social Security System',
        format: 'XX-XXXXXXX-X',
        example: '12-3456789-0',
        pattern: /^\d{2}-\d{7}-\d$/,
        formatter: (digits) => {
          const d = digits.slice(0, 10)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 9)
          const p3 = d.slice(9, 10)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'GSIS ID / UMID': {
        agency: 'GSIS',
        format: 'XX-XXXXXXX-X',
        example: '12-3456789-0',
        pattern: /^\d{2}-\d{7}-\d$/,
        formatter: (digits) => {
          const d = digits.slice(0, 10)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 9)
          const p3 = d.slice(9, 10)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'TIN ID': {
        agency: 'BIR',
        format: 'XXX-XXX-XXX-XXX',
        example: '123-456-789-000',
        pattern: /^\d{3}-\d{3}-\d{3}-\d{3}$/,
        formatter: (digits) => {
          const d = digits.slice(0, 12)
          const p1 = d.slice(0, 3)
          const p2 = d.slice(3, 6)
          const p3 = d.slice(6, 9)
          const p4 = d.slice(9, 12)
          return [p1, p2, p3, p4].filter(Boolean).join('-')
        },
      },
      'PhilHealth ID': {
        agency: 'PhilHealth',
        format: 'XX-XXXXXXXXX-X',
        example: '12-345678901-2',
        pattern: /^\d{2}-\d{9}-\d$/,
        formatter: (digits) => {
          const d = digits.slice(0, 12)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 11)
          const p3 = d.slice(11, 12)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'Pag-IBIG ID (HDMF)': {
        agency: 'Pag-IBIG Fund',
        format: 'XXXX-XXXX-XXXX',
        example: '1234-5678-9012',
        pattern: /^\d{4}-\d{4}-\d{4}$/,
        formatter: (digits) => {
          const d = digits.slice(0, 12)
          const p1 = d.slice(0, 4)
          const p2 = d.slice(4, 8)
          const p3 = d.slice(8, 12)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      "Driver's License": {
        agency: 'LTO',
        format: 'NXX-XX-XXXXXX',
        example: 'N01-23-123456',
        pattern: /^N\d{2}-\d{2}-\d{6}$/,
        formatter: (digits) => {
          const d = digits.slice(0, 10)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 4)
          const p3 = d.slice(4, 10)
          const core = [p1, p2, p3].filter(Boolean).join('-')
          return core ? `N${core}` : 'N'
        },
      },
      'Philippine Passport': {
        agency: 'DFA',
        format: 'A1234567',
        example: 'P1234567',
        pattern: /^[A-Z]\d{7}$/,
        formatter: (raw) => {
          const upper = String(raw || '').toUpperCase()
          const letter = upper.replace(/[^A-Z]/g, '').slice(0, 1)
          const digits = upper.replace(/\D/g, '').slice(0, 7)
          return `${letter}${digits}`.slice(0, 8)
        },
      },
      'Postal ID': {
        agency: 'Philippine Postal Corp',
        format: 'XX-XX-XXXXXX',
        example: '12-34-567890',
        pattern: /^\d{2}-\d{2}-\d{6}$/,
        formatter: (digits) => {
          const d = String(digits || '').replace(/\D/g, '').slice(0, 10)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 4)
          const p3 = d.slice(4, 10)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'PRC ID': {
        agency: 'Professional Regulation Commission',
        format: 'XXXXXXX',
        example: '1234567',
        pattern: /^\d{7}$/,
        formatter: (digits) => String(digits || '').replace(/\D/g, '').slice(0, 7),
      },
      "Voter's ID": {
        agency: 'COMELEC',
        format: 'XXXX-XXXX-XXXX',
        example: '1234-5678-9012',
        pattern: /^\d{4}-\d{4}-\d{4}$/,
        formatter: (digits) => {
          const d = String(digits || '').replace(/\D/g, '').slice(0, 12)
          const p1 = d.slice(0, 4)
          const p2 = d.slice(4, 8)
          const p3 = d.slice(8, 12)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'Senior Citizen ID': {
        agency: 'LGU / OSCA',
        format: 'SC-001234',
        example: 'SC-001234',
        pattern: /^SC-\d{6}$/i,
        formatter: (raw) => {
          const upper = String(raw || '').toUpperCase()
          const digits = upper.replace(/\D/g, '').slice(0, 6)
          return digits ? `SC-${digits}` : 'SC-'
        },
      },
      'PWD ID': {
        agency: 'LGU / NCDA',
        format: 'XX-XXXX-XXXXXXX',
        example: '12-3456-1234567',
        pattern: /^\d{2}-\d{4}-\d{7}$/,
        formatter: (digits) => {
          const d = String(digits || '').replace(/\D/g, '').slice(0, 13)
          const p1 = d.slice(0, 2)
          const p2 = d.slice(2, 6)
          const p3 = d.slice(6, 13)
          return [p1, p2, p3].filter(Boolean).join('-')
        },
      },
      'Barangay ID': {
        agency: 'Barangay',
        format: 'BRGY-001234',
        example: 'BRGY-001234',
        pattern: /^BRGY-\d{6}$/i,
        formatter: (raw) => {
          const upper = String(raw || '').toUpperCase()
          const digits = upper.replace(/\D/g, '').slice(0, 6)
          return digits ? `BRGY-${digits}` : 'BRGY-'
        },
      },
      'Police Clearance ID': {
        agency: 'PNP',
        format: 'XXXXXXX',
        example: '1234567',
        pattern: /^\d{7}$/,
        formatter: (digits) => String(digits || '').replace(/\D/g, '').slice(0, 7),
      },
      'NBI Clearance': {
        agency: 'NBI',
        format: 'XXXXXXX',
        example: '1234567',
        pattern: /^\d{7}$/,
        formatter: (digits) => String(digits || '').replace(/\D/g, '').slice(0, 7),
      },
      'Firearms License': {
        agency: 'PNP-FEO',
        format: 'FEO-123456',
        example: 'FEO-123456',
        pattern: /^FEO-\d{6}$/i,
        formatter: (raw) => {
          const upper = String(raw || '').toUpperCase()
          const digits = upper.replace(/\D/g, '').slice(0, 6)
          return digits ? `FEO-${digits}` : 'FEO-'
        },
      },
      "Seafarer's Book (SID)": {
        agency: 'MARINA',
        format: 'XXXXXXX',
        example: '1234567',
        pattern: /^\d{7}$/,
        formatter: (digits) => String(digits || '').replace(/\D/g, '').slice(0, 7),
      },
      'OWWA ID': {
        agency: 'OWWA',
        format: 'XXXXXXX',
        example: '1234567',
        pattern: /^\d{7}$/,
        formatter: (digits) => String(digits || '').replace(/\D/g, '').slice(0, 7),
      },
      }

      // Backwards-compatible aliases for previously stored values
      base.SSS = base['SSS ID / UMID']
      base.UMID = base['SSS ID / UMID']
      base.GSIS = base['GSIS ID / UMID']
      base.TIN = base['TIN ID']
      base.PhilHealth = base['PhilHealth ID']
      base['Pag-IBIG'] = base['Pag-IBIG ID (HDMF)']
      base.Passport = base['Philippine Passport']
      base["Driver’s License"] = base["Driver's License"]
      base["Voter’s ID"] = base["Voter's ID"]

      return base
    },
    []
  )

  const phGovIdTypes = useMemo(
    () => ([
      'PhilSys National ID',
      'SSS ID / UMID',
      'GSIS ID / UMID',
      'TIN ID',
      'PhilHealth ID',
      'Pag-IBIG ID (HDMF)',
      "Driver's License",
      'Philippine Passport',
      'Postal ID',
      'PRC ID',
      "Voter's ID",
      'Senior Citizen ID',
      'PWD ID',
      'Barangay ID',
      'Police Clearance ID',
      'NBI Clearance',
      'Firearms License',
      "Seafarer's Book (SID)",
      'OWWA ID',
    ]),
    []
  )

  const canonicalizeGovIdType = useCallback((rawType) => {
    const t = String(rawType || '').trim()
    if (!t) return ''
    if (phGovIdTypes.includes(t)) return t
    if (t === 'SSS' || t === 'UMID') return 'SSS ID / UMID'
    if (t === 'GSIS') return 'GSIS ID / UMID'
    if (t === 'TIN') return 'TIN ID'
    if (t === 'PhilHealth') return 'PhilHealth ID'
    if (t === 'Pag-IBIG') return 'Pag-IBIG ID (HDMF)'
    if (t === 'Passport') return 'Philippine Passport'
    if (t === "Driver’s License") return "Driver's License"
    if (t === "Voter’s ID") return "Voter's ID"
    return t
  }, [phGovIdTypes])

  const formatGovIdNumber = useCallback(
    (idType, raw) => {
      const type = String(idType || '').trim()
      const def = govIdDefs[type]
      if (!def) {
        return String(raw || '')
          .toUpperCase()
          .replace(/[^\dA-Z\- ]/g, '')
          .replace(/\s+/g, ' ')
          .trim()
          .slice(0, 30)
      }
      if (type === 'Philippine Passport') return def.formatter(raw)
      const digits = String(raw || '').replace(/\D/g, '')
      return def.formatter(digits)
    },
    [govIdDefs]
  )

  function validateGovIdDocForm(next, opts = {}) {
    const errs = {}
    const type = String(next.id_type || '').trim()
    const number = String(next.id_number || '').trim()
    const agency = String(next.issuing_agency || '').trim()
    if (!type) errs.id_type = 'ID Type is required.'
    if (!number) errs.id_number = 'ID Number is required.'
    if (!agency) errs.issuing_agency = 'Issuing agency is required.'

    const pattern = govIdDefs[type]?.pattern
    if (pattern && number && !pattern.test(number)) {
      errs.id_number = `Format: ${govIdDefs[type]?.format || 'invalid'} (e.g. ${govIdDefs[type]?.example || '—'})`
    }

    const items = Array.isArray(opts.existing) ? opts.existing : []
    const activeId = opts.activeId
    if (number && items.some((x) => String(x?.id) !== String(activeId || '') && String(x?.id_number || '').toLowerCase() === number.toLowerCase())) {
      errs.id_number = 'Duplicate ID number for your profile.'
    }

    const file = next.document_file
    if (!activeId && !(file instanceof File)) errs.document_file = 'Document file is required.'
    if (file instanceof File) {
      const t = String(file.type || '').toLowerCase()
      const allowed = ['application/pdf', 'image/jpeg', 'image/png']
      if (!allowed.includes(t)) errs.document_file = 'File must be PDF, JPG, or PNG.'
      const maxBytes = 10 * 1024 * 1024
      if (file.size > maxBytes) errs.document_file = 'File must be 10MB or less.'
    }
    return errs
  }

  function resetGovIdForm(next = {}) {
    setGovIdErrors({})
    setGovIdForm({
      id_type: '',
      id_number: '',
      issuing_agency: '',
      expiry_date: '',
      document_file: null,
      ...next,
    })
  }

  function openAddGovIdModal() {
    setActiveGovDoc(null)
    resetGovIdForm()
    setGovAddOpen(true)
  }

  function openEditGovIdModal(doc) {
    setActiveGovDoc(doc)
    const canonType = canonicalizeGovIdType(doc?.id_type || '')
    resetGovIdForm({
      id_type: canonType,
      id_number: doc?.id_number || '',
      issuing_agency: doc?.issuing_agency || govIdDefs[canonType]?.agency || '',
      expiry_date: doc?.expiry_date || '',
      document_file: null,
    })
    setGovEditOpen(true)
  }

  function openPreviewGovIdModal(doc) {
    setActiveGovDoc(doc)
    setGovPreviewOpen(true)
  }

  async function submitCreateGovId() {
    if (!canEdit) return
    if (govIdDocsSaving) return
    const errs = validateGovIdDocForm(govIdForm, { existing: govIdDocs, activeId: null })
    setGovIdErrors(errs)
    if (Object.keys(errs).length) return
    setGovIdDocsSaving(true)
    try {
      const data = await createMyGovernmentIdDocument({
        ...govIdForm,
        expiry_date: govIdForm.expiry_date || null,
      })
      const created = data?.government_id
      if (created?.id) setGovIdDocs((prev) => [created, ...prev])
      void queryClient.invalidateQueries({ queryKey: ['employee-profile-government-ids'] })
      toast.success('Government ID uploaded.')
      setGovAddOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to upload Government ID.')
    } finally {
      setGovIdDocsSaving(false)
    }
  }

  async function submitUpdateGovId() {
    if (!canEdit) return
    if (!activeGovDoc?.id || govIdDocsSaving) return
    const errs = validateGovIdDocForm(govIdForm, { existing: govIdDocs, activeId: activeGovDoc.id })
    setGovIdErrors(errs)
    if (Object.keys(errs).length) return
    setGovIdDocsSaving(true)
    try {
      const data = await updateMyGovernmentIdDocument(activeGovDoc.id, {
        ...govIdForm,
        expiry_date: govIdForm.expiry_date || null,
      })
      const updated = data?.government_id
      if (updated?.id) setGovIdDocs((prev) => prev.map((x) => (x.id === activeGovDoc.id ? updated : x)))
      void queryClient.invalidateQueries({ queryKey: ['employee-profile-government-ids'] })
      toast.success('Government ID updated.')
      setGovEditOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to update Government ID.')
    } finally {
      setGovIdDocsSaving(false)
    }
  }

  function requestDeleteGovId(doc) {
    if (!doc?.id || govIdDocsSaving) return
    setGovDeleteDoc(doc)
    setGovDeleteOpen(true)
  }

  async function confirmDeleteGovId() {
    if (!canEdit) return
    if (!govDeleteDoc?.id || govIdDocsSaving) return
    setGovIdDocsSaving(true)
    try {
      await deleteMyGovernmentIdDocument(govDeleteDoc.id)
      setGovIdDocs((prev) => prev.filter((x) => x.id !== govDeleteDoc.id))
      void queryClient.invalidateQueries({ queryKey: ['employee-profile-government-ids'] })
      toast.success('Government ID deleted.')
      setGovDeleteOpen(false)
      setGovDeleteDoc(null)
    } catch (e) {
      toast.error(e?.message || 'Failed to delete Government ID.')
    } finally {
      setGovIdDocsSaving(false)
    }
  }

  function resetDocForm(next = {}) {
    setDocErrors({})
    setDocForm({
      category: docsCategory || 'Contracts',
      document_name: '',
      version: '',
      expiry_date: '',
      file: null,
      ...next,
    })
  }

  function validateDocForm(next, opts = {}) {
    const errs = {}
    const category = String(next.category || '').trim()
    const name = String(next.document_name || '').trim()
    const version = String(next.version || '').trim()
    const file = next.file

    if (!category) errs.category = 'Category is required.'
    if (!name) errs.document_name = 'Document name is required.'
    if (version && version.length > 30) errs.version = 'Version must be 30 characters or less.'

    const activeId = opts.activeId || null
    if (!activeId && !(file instanceof File)) errs.file = 'File is required.'
    if (file instanceof File) {
      const allowed = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
      ]
      const t = String(file.type || '').toLowerCase()
      const name = String(file.name || '').toLowerCase()
      const byExt = name.endsWith('.pdf') || name.endsWith('.docx') || name.endsWith('.doc') || name.endsWith('.xlsx') || name.endsWith('.xls') || /\.(jpe?g|png|gif|webp)$/i.test(name)
      if (!allowed.includes(t) && !byExt) errs.file = 'Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG.'
      const maxBytes = 10 * 1024 * 1024
      if (file.size > maxBytes) errs.file = 'File must be 10MB or less.'
    }

    return errs
  }

  function openUploadDocModal(seedFile = null) {
    setActiveDoc(null)
    resetDocForm({ category: docsCategory, file: seedFile instanceof File ? seedFile : null })
    setDocUploadOpen(true)
    setTimeout(() => docFileRef.current?.focus?.(), 0)
  }

  function openEditDocModal(doc) {
    setActiveDoc(doc)
    resetDocForm({
      category: doc?.category || docsCategory,
      document_name: doc?.document_name || '',
      version: doc?.version || '',
      expiry_date: doc?.expiry_date || '',
      file: null,
    })
    setDocEditOpen(true)
  }

  function openPreviewDocModal(doc) {
    setActiveDoc(doc)
    setDocPreviewOpen(true)
  }

  function requestDeleteDoc(doc) {
    if (!doc?.id || docsSaving) return
    setActiveDoc(doc)
    setDocDeleteOpen(true)
  }

  async function confirmDeleteDoc() {
    if (!canEdit) return
    if (!activeDoc?.id || docsSaving) return
    setDocsSaving(true)
    try {
      await deleteMyDocument(activeDoc.id)
      setDocs((prev) => prev.filter((x) => x.id !== activeDoc.id))
      toast.success('Document deleted.')
      setDocDeleteOpen(false)
      setActiveDoc(null)
    } catch (e) {
      toast.error(e?.message || 'Failed to delete document.')
    } finally {
      setDocsSaving(false)
    }
  }

  async function submitCreateDoc() {
    if (!canEdit) return
    if (docsSaving) return
    const errs = validateDocForm(docForm, { activeId: null })
    setDocErrors(errs)
    if (Object.keys(errs).length) return
    setDocsSaving(true)
    try {
      const data = await createMyDocument({ ...docForm, expiry_date: docForm.expiry_date || null })
      const created = data?.document
      if (created?.id) setDocs((prev) => [created, ...prev])
      toast.success('Document uploaded.')
      setDocUploadOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to upload document.')
    } finally {
      setDocsSaving(false)
    }
  }

  const ALLOWED_DOC_TYPES = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
  ]
  const ALLOWED_DOC_EXT = /\.(pdf|docx?|xlsx?|jpe?g|png|gif|webp)$/i
  function isAllowedDocFile(file) {
    if (!(file instanceof File)) return false
    const t = String(file.type || '').toLowerCase()
    if (ALLOWED_DOC_TYPES.includes(t)) return true
    if (ALLOWED_DOC_EXT.test(String(file.name || ''))) return true
    return false
  }

  async function quickUploadDocsToCategory(files, category) {
    if (!canEdit) return
    const raw = Array.from(files || []).filter((f) => f instanceof File)
    const list = raw.filter((f) => isAllowedDocFile(f) && (f.size || 0) <= 10 * 1024 * 1024)
    const skipped = raw.length - list.length
    if (skipped > 0) {
      toast.error(`${skipped} file(s) skipped. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG. Max 10MB each.`)
    }
    if (!list.length || docsUploading || docsSaving) return
    setDocsUploading(true)
    setDocsUploadProgress({ total: list.length, done: 0 })
    try {
      for (let i = 0; i < list.length; i += 1) {
        const file = list[i]
        const baseName = String(file?.name || 'Document').replace(/\.[^/.]+$/u, '').trim() || 'Document'
        const data = await createMyDocument({
          category,
          document_name: baseName,
          version: '',
          expiry_date: null,
          file,
        })
        const created = data?.document
        if (created?.id) setDocs((prev) => [created, ...prev])
        setDocsUploadProgress((p) => ({ ...p, done: i + 1 }))
      }
      toast.success(list.length ? 'Upload complete.' : 'No valid files to upload.')
    } catch (e) {
      toast.error(e?.message || 'Upload failed.')
    } finally {
      setDocsUploading(false)
      setDocDragOver(false)
      setTimeout(() => setDocsUploadProgress({ total: 0, done: 0 }), 600)
    }
  }

  async function submitUpdateDoc() {
    if (!canEdit) return
    if (!activeDoc?.id || docsSaving) return
    const errs = validateDocForm(docForm, { activeId: activeDoc.id })
    setDocErrors(errs)
    if (Object.keys(errs).length) return
    setDocsSaving(true)
    try {
      const data = await updateMyDocument(activeDoc.id, { ...docForm, expiry_date: docForm.expiry_date || null })
      const updated = data?.document
      if (updated?.id) setDocs((prev) => prev.map((x) => (x.id === activeDoc.id ? updated : x)))
      toast.success('Document updated.')
      setDocEditOpen(false)
      setActiveDoc(null)
    } catch (e) {
      toast.error(e?.message || 'Failed to update document.')
    } finally {
      setDocsSaving(false)
    }
  }

  const canEditDeleteDoc = useCallback((doc) => {
    const s = String(doc?.status || '').toLowerCase()
    return s !== 'active' && s !== 'archived'
  }, [])

  function validateEmergencyContact(payload) {
    const errors = {}
    const phoneRegex = /^[+0-9()\-.\s]{7,20}$/
    if (!hasText(payload.full_name)) errors.full_name = 'Full name is required.'
    if (!hasText(payload.relationship)) errors.relationship = 'Relationship is required.'
    if (!hasText(payload.phone_number)) errors.phone_number = 'Phone number is required.'
    else if (!phoneRegex.test(String(payload.phone_number).trim())) errors.phone_number = 'Use a valid phone format.'
    if (!hasText(payload.address)) errors.address = 'Address is required.'
    return errors
  }

  const onEmergencyAddressPlaceChanged = useCallback(() => {
    const instance = emergencyAddressAutocompleteRef?.current
    if (!instance || typeof instance.getPlace !== 'function') return
    const place = instance.getPlace()
    if (!place) return
    const mapped = mapPlaceToAddressFields(place)
    const address = mapped?.full_address || place?.formatted_address || place?.name || ''
    if (address) setEmergencyForm((prev) => ({ ...prev, address }))
  }, [])

  function startAddEmergencyContact() {
    setEditingEmergencyId('')
    setEmergencyForm(createEmptyEmergencyContact())
    setEmergencyErrors({})
  }

  function startEditEmergencyContact(contact) {
    setEditingEmergencyId(contact.id)
    setEmergencyForm({
      id: contact.id,
      full_name: contact.full_name || '',
      relationship: contact.relationship || '',
      phone_number: contact.phone_number || '',
      address: contact.address || '',
      is_primary: !!contact.is_primary,
    })
    setEmergencyErrors({})
  }

  function saveEmergencyContact() {
    if (!canEdit) return
    const errors = validateEmergencyContact(emergencyForm)
    setEmergencyErrors(errors)
    if (Object.keys(errors).length > 0) return
    const payload = {
      id: editingEmergencyId || `emergency-${Date.now()}`,
      full_name: emergencyForm.full_name.trim(),
      relationship: emergencyForm.relationship.trim(),
      phone_number: emergencyForm.phone_number.trim(),
      address: emergencyForm.address.trim(),
      is_primary: emergencyForm.is_primary || emergencyContacts.length === 0,
    }
    setEmergencyContacts((prev) => {
      const existing = prev.find((item) => item.id === payload.id)
      let next = existing ? prev.map((item) => (item.id === payload.id ? payload : item)) : [...prev, payload]
      if (payload.is_primary) {
        next = next.map((item) => ({ ...item, is_primary: item.id === payload.id }))
      }
      return next
    })
    setEditingEmergencyId(payload.id)
    setEmergencyForm(payload)
    setEmergencyErrors({})
    toast.success('Emergency contact saved.')
  }

  function removeEmergencyContact(contactId) {
    if (!canEdit) return
    setEmergencyContacts((prev) => {
      const remaining = prev.filter((item) => item.id !== contactId)
      if (remaining.length > 0 && !remaining.some((item) => item.is_primary)) {
        remaining[0] = { ...remaining[0], is_primary: true }
      }
      return remaining
    })
    if (editingEmergencyId === contactId) {
      setEditingEmergencyId('')
      setEmergencyForm(createEmptyEmergencyContact())
      setEmergencyErrors({})
    }
  }

  function setPrimaryEmergencyContact(contactId) {
    if (!canEdit) return
    setEmergencyContacts((prev) =>
      prev.map((item) => ({ ...item, is_primary: item.id === contactId }))
    )
  }

  async function saveEmergencyContacts() {
    if (!canEdit) return
    for (const c of emergencyContacts) {
      const errs = validateEmergencyContact(c)
      if (Object.keys(errs).length) {
        toast.error('Fix emergency contact errors before saving.')
        return
      }
    }
    setEmergencySaving(true)
    try {
      const payload = emergencyContacts.map((c) => ({
        full_name: String(c.full_name || '').trim(),
        relationship: String(c.relationship || '').trim(),
        phone_number: String(c.phone_number || '').trim(),
        address: String(c.address || '').trim() || null,
        is_primary: c.is_primary === true,
      }))
      const data = await replaceMyEmergencyContacts(payload)
      const list = Array.isArray(data?.emergency_contacts) ? data.emergency_contacts : []
      setEmergencyContacts(list.map((item, index) => ({
        id: item?.id || `emergency-${index}`,
        full_name: item.full_name || '',
        relationship: item.relationship || '',
        phone_number: item.phone_number || '',
        address: item.address || '',
        is_primary: !!item.is_primary,
      })))
      toast.success('Emergency contacts updated.')
    } catch (e) {
      toast.error(e?.message || 'Failed to save emergency contacts')
    } finally {
      setEmergencySaving(false)
    }
  }

  function handlePhotoSelect(e) {
    if (!canEditPhoto) return
    const file = e.target?.files?.[0]
    e.target.value = ''
    if (!file) return
    const type = String(file.type || '').toLowerCase()
    if (type !== 'image/jpeg' && type !== 'image/png') {
      setPhotoError('Only JPG, JPEG, and PNG images are allowed.')
      return
    }
    if (file.size > MAX_FILE_MB * 1024 * 1024) {
      setPhotoError(`Image must be under ${MAX_FILE_MB} MB.`)
      return
    }
    setPhotoError('')
    setPendingPhotoFile(file)
    setCropOpen(true)
  }

  async function handleCroppedPhotoConfirm(croppedFile) {
    if (!canEditPhoto) return
    setPhotoError('')
    setPhotoLoading(true)
    try {
      const data = await uploadProfilePhoto(croppedFile)
      if (data?.user) mergeAuthUser(data.user)
    } catch (err) {
      setPhotoError(err?.message || 'Failed to upload photo')
    } finally {
      setPhotoLoading(false)
      setPendingPhotoFile(null)
    }
  }

  async function handleRemovePhoto() {
    if (!canEditPhoto) return
    setPhotoError('')
    setPhotoLoading(true)
    try {
      const data = await removeProfilePhoto()
      if (data?.user) mergeAuthUser(data.user)
    } catch (err) {
      setPhotoError(err?.message || 'Failed to remove photo')
    } finally {
      setPhotoLoading(false)
    }
  }

  function requestSensitiveUpdate(type, payload) {
    if (!canEdit) return
    setPendingUpdate({ type, payload })
    setLivenessOpen(true)
  }

  async function submitPendingUpdateWithLiveness(sessionId) {
    if (!canEdit) return
    if (!pendingUpdate) return
    const type = pendingUpdate.type
    const payload = { ...pendingUpdate.payload, liveness_session_id: sessionId }
    setPendingUpdate(null)
    setLivenessOpen(false)

    if (type === 'email') {
      setEmailLoading(true)
      setEmailError('')
    } else if (type === 'phone') {
      setPhoneLoading(true)
      setPhoneError('')
    } else {
      setPasswordLoading(true)
      setPasswordErrors({ current: '', new: '', confirm: '' })
    }

    try {
      const data = await updateProfile(payload)
      if (data?.user) mergeAuthUser(data.user)
      if (type === 'email') toast.success('Email updated.')
      else if (type === 'phone') toast.success('Phone updated.')
      else toast.success('Password updated.')

      if (type === 'password') {
        setCurrentPassword('')
        setNewPassword('')
        setConfirmPassword('')
      }
    } catch (e) {
      const msg = e?.message || 'Update failed'
      if (type === 'email') setEmailError(msg)
      else if (type === 'phone') setPhoneError(msg)
      else setPasswordErrors((prev) => ({ ...prev, new: msg }))
      toast.error(msg)
    } finally {
      setEmailLoading(false)
      setPhoneLoading(false)
      setPasswordLoading(false)
    }
  }

  const tabs = useMemo(() => {
    const base = [
      { id: 'profile', label: 'Profile', icon: User },
      { id: 'employment', label: 'Employment', icon: Briefcase },
      { id: 'salary', label: 'Salary', icon: CircleDollarSign },
      ...(!isReadOnly ? [{ id: 'payslips', label: 'Payslips', icon: Receipt }] : []),
      { id: 'benefits', label: 'Benefits', icon: Gift },
      { id: 'documents', label: 'Documents', icon: FileText },
      { id: 'government', label: 'Government IDs', icon: IdCard },
      { id: 'emergency', label: 'Emergency Contacts', icon: Users },
      { id: 'skills', label: 'Skills', icon: Zap },
      { id: 'account', label: 'Account', icon: ShieldCheck },
    ]
    if (isReadOnly) return base.filter((t) => t.id !== 'account')
    return base
  }, [isReadOnly])

  useEffect(() => {
    if (isReadOnly && activeTab === 'account') setActiveTab('profile')
  }, [isReadOnly, activeTab])

  if (loading) {
    return <ProfilePageSkeleton />
  }

  if (loadError) {
    return <div className="py-12 text-center text-sm text-destructive">{loadError}</div>
  }

  return (
    <div className="mx-auto w-full max-w-[min(100%,85rem)] space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight @md:text-3xl">
          {isReadOnly ? (
            <span className="block">{displayUser?.name ? `${displayUser.name} — profile` : 'Employee profile'}</span>
          ) : (
            <>
              Hi,{' '}
              {String(displayUser?.name || 'there')
                .split(' ')[0]}
              ! 👋
            </>
          )}
        </h2>
        {isReadOnly && (
          <p className="mt-2 rounded-lg border border-amber-200/80 bg-amber-50/90 px-3 py-2 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/35 dark:text-amber-100">
            Viewing in read-only mode. Contact Admin (HR) if edits are needed.
          </p>
        )}
        {!isReadOnly && !canEdit && (
          <p className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-200">
            Profile details can only be edited by HR.
          </p>
        )}
        {!isReadOnly && profileCompletion.percent < 100 ? (
          <p className="mt-0.5 text-sm text-muted-foreground">
            Your profile is{
' '
}
            <span
              className="font-semibold"
              style={{
                color:
                  profileCompletion.percent >= 60
                    ? 'rgb(217 119 6)'
                    : 'rgb(244 114 182)',
              }}
            >
              {profileCompletion.percent}% complete
            </span>
            {
' '
}— keep it updated so your benefits and records stay current.
          </p>
        ) : !isReadOnly ? (
          <p className="mt-0.5 text-sm font-medium text-teal-600 dark:text-teal-400">Your profile is 100% complete. Great work! ✓</p>
        ) : null}
      </div>

      {leaveCreditsLoading && !leaveCreditsInfo ? (
        <div className="w-full animate-pulse rounded-xl border border-border/60 bg-muted/30 p-4">
          <div className="mb-3 h-4 w-40 rounded bg-muted-foreground/20" />
          <div className="h-10 w-28 rounded bg-muted-foreground/20" />
        </div>
      ) : null}

      {leaveCreditsInfo ? (
        <LeaveCreditsCard
          data={leaveCreditsInfo}
          variant="employee"
          className="w-full"
          requestLeaveTo="../requests"
          viewAllActivityTo="../requests"
        />
      ) : null}

      <Card className="border border-border/60 shadow-md dark:border-white/8 dark:bg-[#111827]">
        <CardContent className="p-5 @sm:p-6">
          <input
            ref={photoInputRef}
            type="file"
            accept={ACCEPT_IMAGE}
            className="hidden"
            onChange={handlePhotoSelect}
            disabled={photoLoading}
          />
          <div className="flex flex-wrap items-start gap-5">
            <div className="relative shrink-0 p-2.5">
              {(() => {
                const pct = profileCompletion.percent
                const color = pct === 100 ? '#2dd4bf' : pct >= 60 ? '#fbbf24' : '#fb7185'
                const circ = 2 * Math.PI * 22
                const dash = circ * (pct / 100)
                return (
                  <svg width="104" height="104" className="absolute inset-0" style={{ transform: 'rotate(-90deg)' }}>
                    <circle cx="52" cy="52" r="22" fill="none" stroke="currentColor" strokeWidth="3" className="text-border/25 dark:text-white/8" />
                    <circle cx="52" cy="52" r="22" fill="none" stroke={color} strokeWidth="3" strokeLinecap="round"
                      strokeDasharray={`${dash} ${circ}`}
                      style={{ filter: `drop-shadow(0 0 5px ${color}55)`, transition: 'stroke-dasharray 0.8s ease' }}
                    />
                  </svg>
                )
              })()}
              <Avatar className="size-24 shrink-0 rounded-full shadow-sm ring-4 ring-border/20 dark:ring-white/8">
                <AvatarImage src={avatarSrc || undefined} alt={displayUser?.name} className="object-cover" />
                <AvatarFallback
                  className={cn(
                    'rounded-full text-lg font-bold',
                    getEmployeeAvatarColorClass(displayUser?.id, displayUser?.name),
                  )}
                >
                  {initials}
                </AvatarFallback>
              </Avatar>
              <button type="button" onClick={() => photoInputRef.current?.click()} disabled={photoLoading || !canEditPhoto}
                title="Change photo"
                className="absolute bottom-0 right-0 flex size-7 cursor-pointer items-center justify-center rounded-full border border-border/60 bg-background shadow-sm transition-colors hover:bg-muted dark:border-white/10 dark:bg-[#1e293b] dark:hover:bg-[#263046]"
              >
                {photoLoading ? <Loader2 className="size-3.5 animate-spin text-teal-500" /> : <Camera className="size-3.5 text-muted-foreground" />}
              </button>
            </div>

            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-2xl font-bold tracking-tight text-foreground">{displayUser?.name || 'Employee'}</h3>
                <RoleBadge user={displayUser} size="sm" />
              </div>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {[displayUser?.position, displayUser?.department].filter(Boolean).join(' · ') || 'No position assigned'}
              </p>
              <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                <Badge
                  className={cn(
                    displayUser?.is_active
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-700/40 dark:bg-emerald-900/25 dark:text-emerald-300'
                      : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-700/40 dark:bg-amber-900/25 dark:text-amber-300',
                  )}
                >
                  {displayUser?.is_active ? 'Active' : 'Inactive'}
                </Badge>
                <span className="inline-flex items-center gap-1 rounded-md border border-border/50 px-2 py-0.5 text-xs font-medium text-muted-foreground dark:border-white/10">
                  <IdCard className="size-3 shrink-0" />{displayUser?.employee_code || `ID-${displayUser?.id ?? '—'}`}
                </span>
                {displayUser?.has_qr && <span className="inline-flex items-center gap-1 text-[11px] text-teal-600 dark:text-teal-400"><span className="size-1.5 rounded-full bg-teal-500" />QR Issued</span>}
                {displayUser?.has_face && <span className="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400"><span className="size-1.5 rounded-full bg-emerald-500" />Face Enrolled</span>}
              </div>
              {!isReadOnly && profileCompletion.missing.length > 0 && (
                <p className="mt-2 text-[11px] text-muted-foreground/70">
                  Missing: {profileCompletion.missing.slice(0, 3).join(', ')}
                  {profileCompletion.missing.length > 3 ? ` +${profileCompletion.missing.length - 3} more` : ''}
                </p>
              )}
            </div>

            {avatarSrc && canEditPhoto && (
              <button type="button" onClick={() => setRemovePhotoConfirmOpen(true)} disabled={photoLoading}
                className="shrink-0 self-start text-muted-foreground/50 transition-colors hover:text-destructive" title="Remove photo">
                <Trash2 className="size-4" />
              </button>
            )}
          </div>
          {photoError && <p className="mt-3 text-sm text-destructive">{photoError}</p>}
        </CardContent>
      </Card>

      <ImageCropDialog
        open={cropOpen}
        onOpenChange={(open) => {
          setCropOpen(open)
          if (!open) setPendingPhotoFile(null)
        }}
        file={pendingPhotoFile}
        title="Crop profile picture"
        description="Crop and zoom to fit the circular avatar."
        maxBytes={MAX_FILE_MB * 1024 * 1024}
        onConfirm={handleCroppedPhotoConfirm}
      />

      <Dialog open={removePhotoConfirmOpen} onOpenChange={setRemovePhotoConfirmOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_SM)}
          aria-describedby="emp-profile-remove-photo-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Remove profile photo</DialogTitle>
              <p id="emp-profile-remove-photo-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Are you sure you want to remove your profile picture? You can upload a new one anytime.
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" size="sm" onClick={() => setRemovePhotoConfirmOpen(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              size="sm"
              variant="destructive"
              onClick={async () => {
                setRemovePhotoConfirmOpen(false)
                await handleRemovePhoto()
              }}
              disabled={photoLoading}
            >
              {photoLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Remove Photo
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {activeTab === 'profile' && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/60 bg-card/90 px-4 py-3 shadow-sm backdrop-blur-md supports-backdrop-filter:bg-card/80 dark:border-white/10 dark:bg-[#111827]/92">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-foreground">Personal information</p>
            <p className="text-xs text-muted-foreground">
              {canEdit
                ? 'Update your name, contact details, and address. Save when you are done.'
                : 'Profile details can only be edited by HR.'}
            </p>
          </div>
          <Button
            type="button"
            onClick={savePersonal}
            disabled={personalSaving || !canEdit}
            className="h-10 shrink-0 bg-teal-600 text-white hover:bg-teal-500 focus-visible:ring-teal-500 dark:bg-teal-600 dark:hover:bg-teal-500"
          >
            {personalSaving && personalSaveStatus !== 'saved' ? <Loader2 className="mr-2 size-4 animate-spin" /> : <FilePenLine className="mr-2 size-4" />}
            {personalSaving && personalSaveStatus !== 'saved' ? 'Saving…' : personalSaveStatus === 'saved' ? 'Saved' : 'Save changes'}
          </Button>
        </div>
      )}

      <div className="relative">
        <div className="flex overflow-x-auto rounded-lg border border-border/50 bg-muted/20 px-1 py-1 scrollbar-hide dark:border-white/10 dark:bg-white/3">
          {tabs.map((t) => {
            const Icon = t.icon
            return (
              <button
                key={t.id}
                type="button"
                onClick={() => setActiveTab(t.id)}
                className={[
                  'group relative flex shrink-0 items-center gap-1.5 rounded-md px-3 py-2.5 text-sm font-medium transition-colors',
                  activeTab === t.id
                    ? 'bg-background text-teal-700 shadow-sm dark:text-teal-300'
                    : 'text-muted-foreground hover:bg-background/80 hover:text-foreground',
                ].join(' ')}
              >
                <Icon className="size-3.5" />
                {t.label}
              </button>
            )
          })}
        </div>
      </div>

      {activeTab === 'profile' && (
        <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
          <CardHeader className="space-y-1 pb-2">
            <CardTitle className="flex items-center gap-2 text-xl">
              <UserRound className="size-5 text-foreground" />
              Personal &amp; contact
            </CardTitle>
            <CardDescription>Your legal name, address, and contact details used for HR records.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-8">
            <fieldset disabled={!canEdit} className="min-w-0 border-0 p-0 m-0 space-y-8">
            <div>
              <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Contact</h3>
              <div className="grid gap-5 @sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="first_name" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <UserRound className="size-4 shrink-0 text-muted-foreground" />
                  <span>First name</span>
                  <span className="text-destructive" aria-hidden>*</span>
                </Label>
                <Input
                  id="first_name"
                  value={personal.first_name}
                  onChange={(e) => updatePersonalField('first_name', e.target.value)}
                  className={cn('h-11', personalErrors.first_name && 'border-destructive')}
                />
                {personalErrors.first_name && <p className="text-sm text-destructive">{personalErrors.first_name}</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="middle_name" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <UserRound className="size-4 shrink-0 text-muted-foreground" />
                  <span>Middle name</span>
                  <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Input
                  id="middle_name"
                  value={personal.middle_name}
                  onChange={(e) => updatePersonalField('middle_name', e.target.value)}
                  className={cn('h-11', personalErrors.middle_name && 'border-destructive')}
                />
                {personalErrors.middle_name && <p className="text-sm text-destructive">{personalErrors.middle_name}</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="last_name" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <UserRound className="size-4 shrink-0 text-muted-foreground" />
                  <span>Last name</span>
                  <span className="text-destructive" aria-hidden>*</span>
                </Label>
                <Input
                  id="last_name"
                  value={personal.last_name}
                  onChange={(e) => updatePersonalField('last_name', e.target.value)}
                  className={cn('h-11', personalErrors.last_name && 'border-destructive')}
                />
                {personalErrors.last_name && <p className="text-sm text-destructive">{personalErrors.last_name}</p>}
              </div>
              <div className="space-y-2 @sm:col-span-2">
                <Label htmlFor="profile_email" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Mail className="size-4 shrink-0 text-muted-foreground" />
                  <span>Email address</span>
                </Label>
                <Input
                  id="profile_email"
                  type="email"
                  value={email}
                  onChange={(e) => {
                    const v = e.target.value
                    setEmail(v)
                    setEmailError(validateEmail(v))
                  }}
                  className={cn('h-11', emailError && 'border-destructive')}
                />
                <FieldHint>Use the Account tab to update your login email (identity verification required).</FieldHint>
                {emailError && <p className="text-sm text-destructive">{emailError}</p>}
              </div>
              <div className="space-y-2 @sm:col-span-2">
                <Label htmlFor="profile_phone" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Phone className="size-4 shrink-0 text-muted-foreground" />
                  <span>Phone number</span>
                </Label>
                <Input
                  id="profile_phone"
                  type="tel"
                  value={phone}
                  onChange={(e) => {
                    const v = e.target.value
                    setPhone(v)
                    setPhoneError(validatePhone(v))
                  }}
                  className={cn('h-11', phoneError && 'border-destructive')}
                  placeholder="+63 9XX XXX XXXX or 09XXXXXXXXX"
                />
                <FieldHint>Use the Account tab to update your mobile number (verification required).</FieldHint>
                {phoneError && <p className="text-sm text-destructive">{phoneError}</p>}
              </div>
              </div>
            </div>

            <div className="border-t border-border/40 pt-6 dark:border-white/10">
              <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Demographics</h3>
              <div className="grid gap-5 @sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="date_of_birth" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Calendar className="size-4 shrink-0 text-muted-foreground" />
                  <span>Date of birth</span>
                  <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Input
                  id="date_of_birth"
                  type="date"
                  value={personal.date_of_birth}
                  onChange={(e) => updatePersonalField('date_of_birth', e.target.value)}
                  className="h-11"
                />
                {dobAge != null && <p className="text-xs text-muted-foreground">{dobAge} years old</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="gender" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <UserRound className="size-4 shrink-0 text-muted-foreground" />
                  <span>Gender</span>
                </Label>
                <Select value={personal.gender || 'none'} onValueChange={(v) => updatePersonalField('gender', v === 'none' ? '' : v)}>
                  <SelectTrigger className="h-11 w-full min-w-[220px]" id="gender">
                    <SelectValue placeholder="Select gender" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Select gender</SelectItem>
                    <SelectItem value="Male">Male</SelectItem>
                    <SelectItem value="Female">Female</SelectItem>
                    <SelectItem value="Other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="civil_status" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Heart className="size-4 shrink-0 text-muted-foreground" />
                  <span>Civil status</span>
                </Label>
                <Select value={personal.civil_status || 'none'} onValueChange={(v) => updatePersonalField('civil_status', v === 'none' ? '' : v)}>
                  <SelectTrigger className="h-11 w-full min-w-[220px]" id="civil_status">
                    <SelectValue placeholder="Select civil status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Select civil status</SelectItem>
                    <SelectItem value="Single">Single</SelectItem>
                    <SelectItem value="Married">Married</SelectItem>
                    <SelectItem value="Widowed">Widowed</SelectItem>
                    <SelectItem value="Separated">Separated</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="nationality" className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Flag className="size-4 shrink-0 text-muted-foreground" />
                  <span>Nationality</span>
                </Label>
                <Select value={personal.nationality || 'none'} onValueChange={(v) => updatePersonalField('nationality', v === 'none' ? '' : v)}>
                  <SelectTrigger className="h-11 w-full min-w-[220px]" id="nationality">
                    <SelectValue placeholder="Select nationality" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Select nationality</SelectItem>
                    <SelectItem value="Filipino">Filipino</SelectItem>
                    <SelectItem value="American">American</SelectItem>
                    <SelectItem value="Australian">Australian</SelectItem>
                    <SelectItem value="British">British</SelectItem>
                    <SelectItem value="Canadian">Canadian</SelectItem>
                    <SelectItem value="Chinese">Chinese</SelectItem>
                    <SelectItem value="Indian">Indian</SelectItem>
                    <SelectItem value="Indonesian">Indonesian</SelectItem>
                    <SelectItem value="Japanese">Japanese</SelectItem>
                    <SelectItem value="Korean">Korean</SelectItem>
                    <SelectItem value="Malaysian">Malaysian</SelectItem>
                    <SelectItem value="Singaporean">Singaporean</SelectItem>
                    <SelectItem value="Thai">Thai</SelectItem>
                    <SelectItem value="Vietnamese">Vietnamese</SelectItem>
                    <SelectItem value="Other">Other</SelectItem>
                  </SelectContent>
                </Select>
                {personalErrors.nationality && <p className="text-sm text-destructive">{personalErrors.nationality}</p>}
              </div>
              </div>
            </div>

            <div className="border-t border-border/40 pt-6 dark:border-white/10">
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Address</h3>
              <FieldHint>Used for payroll, IDs, and official correspondence. Search PH addresses or edit each line.</FieldHint>
              <div className="mt-5 grid gap-5 @md:grid-cols-3">
              <div className="space-y-2 @md:col-span-3">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <MapPin className="size-4 shrink-0 text-muted-foreground" />
                  <span>Full address (search)</span>
                  <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                </Label>
                {isMapsLoaded ? (
                  <Autocomplete
                    onLoad={(ac) => { homeAddressAutocompleteRef.current = ac; try { ac.setFields(['address_components', 'formatted_address', 'name']); ac.setTypes(['address']); } catch (e) { void e; } }}
                    onPlaceChanged={makeProfilePlaceChangedHandler(homeAddressAutocompleteRef)}
                    options={{ componentRestrictions: { country: 'ph' } }}
                  >
                    <Input
                      className="h-11"
                      value={personal.home_address}
                      onChange={(e) => updatePersonalField('home_address', e.target.value)}
                      placeholder="Start typing to search an address..."
                    />
                  </Autocomplete>
                ) : (
                  <Input
                    className="h-11"
                    value={personal.home_address}
                    onChange={(e) => updatePersonalField('home_address', e.target.value)}
                    placeholder="Start typing to search an address..."
                  />
                )}
                {mapsLoadError && <p className="text-xs text-amber-600">Address autocomplete unavailable: {mapsLoadError}</p>}
              </div>
              <div className="space-y-2">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Home className="size-4 shrink-0 text-muted-foreground" />
                  <span>Street address</span>
                </Label>
                {isMapsLoaded ? (
                  <Autocomplete
                    onLoad={(ac) => { streetAddressAutocompleteRef.current = ac; try { ac.setFields(['address_components', 'formatted_address', 'name']); ac.setTypes(['address']); } catch (e) { void e; } }}
                    onPlaceChanged={makeProfilePlaceChangedHandler(streetAddressAutocompleteRef)}
                    options={{ componentRestrictions: { country: 'ph' } }}
                  >
                    <Input
                      className="h-11"
                      value={personal.street_address}
                      onChange={(e) => updatePersonalAddressField('street_address', e.target.value)}
                      placeholder="Start typing to search address..."
                    />
                  </Autocomplete>
                ) : (
                  <Input className="h-11" value={personal.street_address} onChange={(e) => updatePersonalAddressField('street_address', e.target.value)} placeholder="House no., street, subdivision" />
                )}
              </div>
              <div className="space-y-2">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <MapPin className="size-4 shrink-0 text-muted-foreground" />
                  <span>Barangay</span>
                </Label>
                {isMapsLoaded ? (
                  <Autocomplete
                    onLoad={(ac) => { barangayAutocompleteRef.current = ac; try { ac.setFields(['address_components', 'formatted_address', 'name']); ac.setTypes(['address']); } catch (e) { void e; } }}
                    onPlaceChanged={makeProfilePlaceChangedHandler(barangayAutocompleteRef)}
                    options={{ componentRestrictions: { country: 'ph' } }}
                  >
                    <Input className="h-11" value={personal.barangay} onChange={(e) => updatePersonalAddressField('barangay', e.target.value)} placeholder="Start typing to search address..." />
                  </Autocomplete>
                ) : (
                  <Input className="h-11" value={personal.barangay} onChange={(e) => updatePersonalAddressField('barangay', e.target.value)} placeholder="Barangay" />
                )}
              </div>
              <div className="space-y-2">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <MapPin className="size-4 shrink-0 text-muted-foreground" />
                  <span>City</span>
                </Label>
                {isMapsLoaded ? (
                  <Autocomplete
                    onLoad={(ac) => { cityAutocompleteRef.current = ac; try { ac.setFields(['address_components', 'formatted_address', 'name']); ac.setTypes(['address']); } catch (e) { void e; } }}
                    onPlaceChanged={makeProfilePlaceChangedHandler(cityAutocompleteRef)}
                    options={{ componentRestrictions: { country: 'ph' } }}
                  >
                    <Input className="h-11" value={personal.city} onChange={(e) => updatePersonalAddressField('city', e.target.value)} placeholder="Start typing to search address..." />
                  </Autocomplete>
                ) : (
                  <Input className="h-11" value={personal.city} onChange={(e) => updatePersonalAddressField('city', e.target.value)} placeholder="City / Municipality" />
                )}
              </div>
              <div className="space-y-2">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <MapPin className="size-4 shrink-0 text-muted-foreground" />
                  <span>Province</span>
                </Label>
                {isMapsLoaded ? (
                  <Autocomplete
                    onLoad={(ac) => { provinceAutocompleteRef.current = ac; try { ac.setFields(['address_components', 'formatted_address', 'name']); ac.setTypes(['address']); } catch (e) { void e; } }}
                    onPlaceChanged={makeProfilePlaceChangedHandler(provinceAutocompleteRef)}
                    options={{ componentRestrictions: { country: 'ph' } }}
                  >
                    <Input className="h-11" value={personal.province} onChange={(e) => updatePersonalAddressField('province', e.target.value)} placeholder="Start typing to search address..." />
                  </Autocomplete>
                ) : (
                  <Input className="h-11" value={personal.province} onChange={(e) => updatePersonalAddressField('province', e.target.value)} placeholder="Province" />
                )}
              </div>
              <div className="space-y-2">
                <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Hash className="size-4 shrink-0 text-muted-foreground" />
                  <span>Postal code</span>
                </Label>
                <Input
                  className="h-11"
                  value={personal.postal_code}
                  onChange={(e) => updatePersonalAddressField('postal_code', e.target.value.replace(/[^\d]/g, '').slice(0, 4))}
                  placeholder="e.g. 1200"
                  inputMode="numeric"
                />
              </div>
              {personalErrors.home_address && <p className="text-sm text-destructive @md:col-span-3">{personalErrors.home_address}</p>}
              </div>
            </div>
            </fieldset>

            <div className="border-t border-border/40 pt-6 dark:border-white/10">
              <ESignatureCard
                title="Electronic Signature"
                status={displayUser?.signature_image ? 'completed' : 'none'}
                signatureImage={displayUser?.signature_image || ''}
                busy={signatureBusy}
                onManage={canEdit ? manageSignature : undefined}
                onRefresh={canEdit ? refreshProfileQuiet : undefined}
              />
            </div>
            <SignaturePadDialog
              open={signatureDialogOpen}
              onOpenChange={setSignatureDialogOpen}
              initialImage={displayUser?.signature_image || ''}
              busy={signatureBusy}
              onSave={saveSignature}
              onRemove={displayUser?.signature_image && canEdit ? removeSignature : null}
            />
          </CardContent>
        </Card>
      )}

      {activeTab === 'employment' && (
        <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
          <CardHeader className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between">
            <div className="space-y-1">
              <CardTitle>Employment Information</CardTitle>
              <CardDescription>These fields are maintained by administrators.</CardDescription>
            </div>
            <Button
              type="button"
              variant="outline"
              className="h-9 shrink-0"
              onClick={handleExportMyProfileCsv}
              disabled={exportingProfileCsv}
              title="Export my profile to CSV"
            >
              {exportingProfileCsv ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <FileDown className="mr-1.5 size-4" />}
              Export My Profile to CSV
            </Button>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <IdCard className="size-3.5 shrink-0" />
                  Employee ID
                </p>
                <p className="font-medium">{displayUser?.employee_code || `ID-${displayUser?.id ?? '—'}`}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <ShieldCheck className="size-3.5 shrink-0" />
                  Company
                </p>
                {displayUser?.company_name ? (
                  <div className="flex items-center gap-2">
                    {displayUser.company_logo_url ? (
                      <img
                        src={displayUser.company_logo_url}
                        alt=""
                        className="size-5 rounded object-contain shrink-0"
                        onError={(e) => { e.currentTarget.style.display = 'none' }}
                      />
                    ) : (
                      <span className="size-5 rounded bg-primary/10 flex shrink-0 items-center justify-center text-[8px] font-bold text-primary select-none">
                        {displayUser.company_name[0].toUpperCase()}
                      </span>
                    )}
                    <p className="font-medium">{displayUser.company_name}</p>
                  </div>
                ) : (
                  <p className="font-medium text-muted-foreground">—</p>
                )}
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <MapPin className="size-3.5 shrink-0" />
                  {displayUser?.management_role === 'company_head' ? 'Work scope' : 'Branch'}
                </p>
                <p className="font-medium">
                  {displayUser?.management_role === 'company_head'
                    ? 'All Branches'
                    : displayUser?.branch_name || displayUser?.branch_office_location || '—'}
                </p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <ShieldCheck className="size-3.5 shrink-0" />
                  Department
                </p>
                <p className="font-medium">
                  {displayUser?.management_role === 'company_head'
                    ? 'Company-wide'
                    : (displayUser?.department || 'No Department Assigned')}
                </p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <Briefcase className="size-3.5 shrink-0" />
                  Job Title / Position
                </p>
                <p className="font-medium">{displayUser?.position || '—'}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <FileText className="size-3.5 shrink-0" />
                  Employment status
                </p>
                <p className="font-medium">{formatEmploymentStatusFromUser(displayUser)}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <Briefcase className="size-3.5 shrink-0" />
                  Work arrangement
                </p>
                <p className="font-medium">{formatWorkArrangement(displayUser?.employment_type)}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <Calendar className="size-3.5 shrink-0" />
                  Status effective date
                </p>
                <p className="font-medium">{displayUser?.employment_status_effective_date || '—'}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <Calendar className="size-3.5 shrink-0" />
                  Hire date
                </p>
                <p className="font-medium">{displayUser?.hire_date || '—'}</p>
              </div>
              <div className="space-y-1">
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  <Clock className="size-3.5 shrink-0" />
                  Current Shift
                </p>
                {displayUser?.schedule_assigned === false ? (
                  <p className="font-medium text-amber-700 dark:text-amber-400">No Shift Assigned</p>
                ) : (
                  <p className="font-medium">
                    {displayUser?.working_schedule_name || '—'}
                    {displayUser?.working_schedule_time && (
                      <span className="text-muted-foreground"> ({formatScheduleLabel12h(displayUser.working_schedule_time)})</span>
                    )}
                  </p>
                )}
                {displayUser?.pending_schedule_effective_from && displayUser?.pending_working_schedule_name ? (
                  <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                    <span className="font-medium text-foreground">Upcoming change: </span>
                    switches to {displayUser.pending_working_schedule_name}
                    {displayUser.pending_working_schedule_time ? ` (${formatScheduleLabel12h(displayUser.pending_working_schedule_time)})` : ''} on{' '}
                    {new Date(`${displayUser.pending_schedule_effective_from}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' })}.
                  </p>
                ) : null}
              </div>
              {displayUser?.employment_status === 'contractual' ? (
                <>
                  <div className="space-y-1">
                    <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                      <Calendar className="size-3.5 shrink-0" />
                      Contract start
                    </p>
                    <p className="font-medium">{displayUser?.contract_start_date || '—'}</p>
                  </div>
                  <div className="space-y-1">
                    <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                      <Calendar className="size-3.5 shrink-0" />
                      Contract end
                    </p>
                    <p className="font-medium">{displayUser?.contract_end_date || '—'}</p>
                  </div>
                </>
              ) : null}
            </div>
            {displayUser?.employment_status === 'probationary' && displayUser?.probation_milestones ? (
              <div className="mt-6 space-y-3 rounded-xl border border-border/60 bg-muted/15 p-4 dark:bg-white/5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-foreground">Probation milestones</p>
                  {displayUser?.probation_review_phase ? (
                    <Badge variant="secondary" className="font-normal">
                      {probationPhaseLabel(displayUser.probation_review_phase)}
                    </Badge>
                  ) : null}
                </div>
                <div className="grid gap-3 @sm:grid-cols-3">
                  {[
                    { label: '3-month (early rec.)', date: displayUser.probation_milestones?.three_months },
                    { label: '5-month alert', date: displayUser.probation_milestones?.five_months, highlight: true },
                    { label: '6-month decision', date: displayUser.probation_milestones?.six_months },
                  ].map((row) => (
                    <div
                      key={row.label}
                      className={cn(
                        'rounded-lg border px-3 py-2',
                        row.highlight
                          ? 'border-amber-200/80 bg-amber-50/40 dark:border-amber-900/40 dark:bg-amber-950/25'
                          : 'border-border/50 bg-background/80'
                      )}
                    >
                      <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{row.label}</p>
                      <p className="font-mono text-sm tabular-nums">{row.date || '—'}</p>
                    </div>
                  ))}
                </div>
                <p className="text-xs text-muted-foreground">
                  5-month: listed for regularization review. 6-month: HR confirms Regular or extended probation — not automatic.
                </p>
              </div>
            ) : null}
            {!isReadOnly ? <EmployeeRegularizationHistoryCard /> : null}
            {displayUser?.employment_status === 'contractual' && displayUser?.contract_expiring_within_30_days ? (
              <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>
                  Contract is ending soon
                  {typeof displayUser.contract_days_until_end === 'number' ? ` (${displayUser.contract_days_until_end} day(s) remaining).` : '.'}{' '}
                  Contact HR regarding renewal.
                </span>
              </div>
            ) : null}
          </CardContent>
        </Card>
      )}

      {activeTab === 'salary' && (
        <div className="rounded-2xl border border-slate-200/90 bg-slate-50/60 p-4 shadow-sm sm:p-6 dark:border-white/10 dark:bg-slate-950/40">
          <SalaryTabShell>
            {!isReadOnly && !isAdminOrHr ? (
              <div
                role="status"
                className="mb-4 rounded-xl border border-sky-200/90 bg-sky-50/90 px-4 py-3 text-sm text-sky-950 shadow-sm dark:border-sky-900/50 dark:bg-sky-950/35 dark:text-sky-100"
              >
                Salary details can only be edited by HR/Admin. Please contact HR.
              </div>
            ) : null}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="space-y-1">
                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Salary</p>
                <h2 className="text-2xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-100">Employment &amp; compensation</h2>
                <p className="max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                  Daily and hourly figures divide your base pay by scheduled workdays in the month (from your assigned shift, including rest days). Public holidays are not subtracted from that count — if you work on a holiday, premium rules apply on top. This tab is view-only — contact HR or payroll to change pay.
                </p>
              </div>
              <Badge variant="outline" className="h-fit w-fit shrink-0 gap-2 border-slate-200 bg-white px-3 py-2 text-[11px] font-medium text-[#0A0A0A] shadow-sm dark:border-white/10 dark:bg-[#111318] dark:text-slate-100">
                <Eye className="size-3.5 opacity-80" aria-hidden />
                View only
              </Badge>
            </div>

            <SalaryCompensationStructureCard
              compensationSummary={compensationSummary}
              payCyclePreview={effectivePayCyclePreview}
              displayUser={displayUser}
              basicMonthlyDisplay={formatSalaryPhp(compensationSummary?.basic_salary ?? displayUser?.monthly_salary)}
              lastUpdatedLabel={formatDate(displayUser?.updated_at)}
              viewOnly
              scheduleHint={salaryScheduleHint}
            />

            {salaryNeedsCompensationAssignment ? (
              <div className="flex flex-col gap-3 rounded-xl border border-amber-200/80 bg-amber-50/70 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-semibold">No compensation assigned yet</p>
                  <p className="text-xs leading-relaxed opacity-90">
                    Basic Salary and pay components are not assigned for this employee profile, so Gross Pay preview remains at ₱0.00.
                  </p>
                </div>
                {canOpenCompensationAssignment ? (
                  <Button
                    type="button"
                    variant="outline"
                    className="border-amber-300 bg-white text-[#0A0A0A] hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-100 dark:hover:bg-amber-900/40"
                    onClick={handleOpenCompensationAssignment}
                  >
                    Assign compensation
                  </Button>
                ) : (
                  <p className="text-xs font-medium">Please contact HR to assign compensation.</p>
                )}
              </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {[
                { label: 'Gross pay', value: formatSalaryPhp(compensationSummary?.totals?.gross_earnings), sub: 'Incl. earnings' },
                { label: 'Daily rate', value: formatSalaryPhp(salaryViewRates.daily), sub: 'From schedule' },
                { label: 'Hourly rate', value: formatSalaryPhp(salaryViewRates.hourly), sub: 'From schedule' },
                { label: 'Effectivity', value: formatDate(displayUser?.salary_effectivity_date), sub: 'Salary structure' },
              ].map((row) => (
                <div key={row.label} className="rounded-xl border border-slate-100 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-[#111318]">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{row.label}</p>
                  <p className="mt-2 text-xl font-semibold tabular-nums text-[#0A0A0A] dark:text-slate-100">{row.value}</p>
                  <p className="mt-1 text-xs text-slate-500">{row.sub}</p>
                </div>
              ))}
            </div>

            <SalaryPayrollHistoryCard
              periods={payrollPeriods}
              loading={payrollPeriodsLoading}
              error={payrollPeriodsError}
              canView={canViewPayrollHistory}
              onViewAll={canViewPayrollHistory ? handleViewAllPayroll : undefined}
            />

            <SalaryPayComponentsBreakdownCard
              earnings={compensationSummary?.earnings || []}
              deductions={compensationSummary?.deductions || []}
              statutory={compensationSummary?.statutory}
              withholding={compensationSummary?.withholding}
              compensationSummary={compensationSummary}
              onUpdateGovIds={() => setActiveTab('government')}
            />

            <SalaryAutomatedDeductionsCard compensationSummary={compensationSummary} />

            <SalaryTaxInfoCard
              compensationSummary={compensationSummary}
              tinResolution={salaryTinResolution}
              showUpdateButton={canEdit}
              onUpdateTaxInfo={() => setActiveTab('government')}
            />

            <SalaryTabNotice>
              Totals and deductions on your payslip may still differ from these reference figures. Use your official payslip for final payroll amounts and statutory
              contributions.
            </SalaryTabNotice>
          </SalaryTabShell>
        </div>
      )}

      {activeTab === 'payslips' && !isReadOnly && (
        <div className="rounded-2xl border border-slate-200/90 bg-slate-50/60 p-4 shadow-sm sm:p-6 dark:border-white/10 dark:bg-slate-950/40">
          <EmployeePayslipsPanel />
        </div>
      )}

      {activeTab === 'benefits' && (
        <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
          <CardHeader>
            <CardTitle>Benefits</CardTitle>
            <CardDescription>Your assigned company benefits.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {benefits.length === 0 ? (
              <p className="text-sm text-muted-foreground">No benefits assigned.</p>
            ) : (
              <ul className="space-y-2">
                {benefits.map((b) => (
                  <li key={b.id} className="rounded-lg border border-border/60 bg-muted/40 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <p className="font-semibold">{b.name || 'Benefit'}</p>
                        <p className="text-xs text-muted-foreground">{b.type || '—'}</p>
                      </div>
                      <span className="rounded-full border border-border bg-background px-2.5 py-1 text-xs font-medium">
                        {b.status || '—'}
                      </span>
                    </div>
                    {b.description && <p className="mt-2 text-sm text-muted-foreground">{b.description}</p>}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      )}

      {activeTab === 'documents' && (
        <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileText className="size-5 text-foreground" />
              Documents
            </CardTitle>
            <CardDescription>Upload and manage your personnel files. Submissions are reviewed by an administrator.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 @lg:grid-cols-[minmax(220px,32%)_minmax(0,1fr)]">
            {/* Folder sidebar */}
            <div className="rounded-xl border border-border/60 bg-muted/10 p-3 shadow-sm dark:border-white/8 dark:bg-[#0d1117]">
              <div className="mb-3 flex items-center justify-between">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Personnel Folders</p>
                <span className="rounded-full border border-border/50 bg-background px-2 py-0.5 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-[#111827]">
                  {docs.length} total
                </span>
              </div>
              <div className="space-y-0.5">
                {documentCategories.map((cat) => {
                  const count = (docsByCategory[cat] || []).length
                  const active = docsCategory === cat
                  const meta = {
                    Contracts: { Icon: FileText, cls: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-50 dark:bg-blue-900/20', border: 'border-blue-200 dark:border-blue-700/40', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
                    IDs: { Icon: IdCard, cls: 'text-slate-600 dark:text-slate-400', bg: 'bg-slate-50 dark:bg-slate-800/30', border: 'border-slate-200 dark:border-slate-700/40', badge: 'bg-slate-100 text-slate-600 dark:bg-slate-700/40 dark:text-slate-300' },
                    Certifications: { Icon: Award, cls: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-50 dark:bg-amber-900/20', border: 'border-amber-200 dark:border-amber-700/40', badge: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' },
                    'Disciplinary Records': { Icon: Gavel, cls: 'text-rose-600 dark:text-rose-400', bg: 'bg-rose-50 dark:bg-rose-900/20', border: 'border-rose-200 dark:border-rose-700/40', badge: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' },
                    'Medical Documents': { Icon: HeartPulse, cls: 'text-emerald-600 dark:text-emerald-400', bg: 'bg-emerald-50 dark:bg-emerald-900/20', border: 'border-emerald-200 dark:border-emerald-700/40', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' },
                    'Performance Evaluations': { Icon: LineChart, cls: 'text-violet-600 dark:text-violet-400', bg: 'bg-violet-50 dark:bg-violet-900/20', border: 'border-violet-200 dark:border-violet-700/40', badge: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300' },
                  }[cat] || { Icon: Folder, cls: 'text-muted-foreground', bg: 'bg-muted/30', border: 'border-border', badge: 'bg-muted text-muted-foreground' }
                  const CatIcon = meta.Icon
                  return (
                    <button
                      key={cat}
                      type="button"
                      onClick={() => setDocsCategory(cat)}
                      onDragEnter={(e) => { e.preventDefault(); e.stopPropagation(); if (e.dataTransfer?.types?.includes('Files')) e.dataTransfer.dropEffect = 'copy' }}
                      onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); if (e.dataTransfer?.types?.includes('Files')) e.dataTransfer.dropEffect = 'copy' }}
                      onDrop={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        try {
                          const files = e.dataTransfer?.files
                          if (files?.length) void quickUploadDocsToCategory(files, cat)
                        } catch {
                          // ignore sync errors
                        }
                      }}
                      className={cn(
                        'group flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-left text-sm transition-all duration-150',
                        active
                          ? 'border-teal-500/40 bg-teal-50/80 shadow-[inset_3px_0_0_#14b8a6] dark:border-teal-600/30 dark:bg-teal-900/15 dark:shadow-[inset_3px_0_0_rgba(20,184,166,0.6)]'
                          : 'border-transparent hover:border-border/40 hover:bg-muted/50 dark:hover:bg-white/5'
                      )}
                    >
                      <span className="flex items-center gap-2.5">
                        <span className={cn('inline-flex size-7 shrink-0 items-center justify-center rounded-md border', meta.bg, meta.border)}>
                          <CatIcon className={cn('size-3.5', meta.cls)} />
                        </span>
                        <span className={cn('text-sm', active ? 'font-semibold text-teal-700 dark:text-teal-300' : 'text-foreground')}>{cat}</span>
                      </span>
                      <span
                        className={cn(
                          'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                          count > 0
                            ? meta.badge
                            : 'border border-border/50 bg-muted/60 text-muted-foreground dark:border-white/10 dark:bg-white/[0.06]',
                        )}
                      >
                        {count}
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>

            {/* Right panel: folder header + compact upload + table or onboarding empty state */}
            <div className="flex min-w-0 flex-col gap-4">
              <div className="flex flex-col gap-3 @md:flex-row @md:items-start @md:justify-between">
                <div className="min-w-0 space-y-0.5">
                  <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Current folder</p>
                  <p className="text-base font-semibold text-foreground">{docsCategory}</p>
                  <p className="text-xs text-muted-foreground">
                    {showDocumentsEmptyOnboarding
                      ? 'Add your first file below or use the quick uploader.'
                      : docsSearch.trim()
                        ? `${filteredDocs.length} match${filteredDocs.length === 1 ? '' : 'es'} in ${docsCategory}`
                        : `${folderRawDocCount} document${folderRawDocCount === 1 ? '' : 's'} in this folder`}
                  </p>
                </div>
                <DocumentCompactDropZone
                  categoryLabel={docsCategory}
                  docDragOver={docDragOver}
                  setDocDragOver={setDocDragOver}
                  docsUploading={docsUploading}
                  docsUploadProgress={docsUploadProgress}
                  dropZoneRef={docDropZoneRef}
                  disabled={docsUploading}
                  onDropFiles={(files) => quickUploadDocsToCategory(files, docsCategory)}
                  onBrowse={() => openUploadDocModal(null)}
                />
              </div>

              {showDocumentsEmptyOnboarding ? (
                <DocumentsFolderEmptyState
                  headline={`No documents in ${docsCategory} yet`}
                  body={docFolderGuidance.body}
                  primaryCta={docFolderGuidance.primaryCta}
                  onPrimaryUpload={() => openUploadDocModal(null)}
                  onBrowse={() => openUploadDocModal(null)}
                  checklist={getFolderChecklistTeaser(documentCategories, docsByCategory, docsCategory)}
                  uploading={docsUploading}
                />
              ) : (
              <div className="overflow-hidden rounded-xl border border-border/60 shadow-sm dark:border-white/8">
                {/* Search bar */}
                <div className="flex items-center gap-2 border-b border-border/50 bg-muted/20 px-3 py-2 dark:border-white/6 dark:bg-[#0a0e18]">
                  <svg className="size-3.5 shrink-0 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                  <input
                    value={docsSearch}
                    onChange={(e) => setDocsSearch(e.target.value)}
                    placeholder={`Search in ${docsCategory}…`}
                    className="flex-1 bg-transparent text-sm text-foreground placeholder:text-muted-foreground/60 outline-none"
                  />
                  {docsSearch && (
                    <button type="button" onClick={() => setDocsSearch('')} className="text-muted-foreground/60 hover:text-foreground">
                      <X className="size-3.5" />
                    </button>
                  )}
                </div>
                <div className="overflow-x-auto bg-white dark:bg-[#0d1117]">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 z-1 bg-[#f1f5f9] text-xs uppercase tracking-wide text-muted-foreground dark:bg-[#0a0e18]">
                    <tr className="[&>th]:px-4 [&>th]:py-3 [&>th]:text-left border-b border-border/30 dark:border-white/6">
                      <th>Document Name</th>
                      <th>Version</th>
                      <th>Upload Date</th>
                      <th>Expiry Date</th>
                      <th>Status</th>
                      <th className="w-[168px] whitespace-nowrap text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border/30 dark:divide-white/5">
                    {docsLoading ? (
                      <tr><td colSpan={6} className="px-4 py-6 text-muted-foreground">Loading…</td></tr>
                    ) : filteredDocs.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center">
                          <p className="text-sm text-muted-foreground">
                            {docsSearch ? `No documents match "${docsSearch}"` : 'No documents in this folder yet.'}
                          </p>
                        </td>
                      </tr>
                    ) : (
                      filteredDocs.map((doc) => (
                        <tr key={doc.id} className="group transition-colors hover:bg-slate-50 dark:hover:bg-[#111827] [&>td]:px-4 [&>td]:py-4">
                          <td className="min-w-[260px]">
                            <div className="flex items-start gap-3">
                              {(() => {
                                const kind = getDocFileKind(doc?.file)
                                const logo = kind === 'pdf'
                                  ? { label: 'PDF', cls: 'text-rose-700', bg: 'bg-rose-50', border: 'border-rose-200' }
                                  : kind === 'docx' || kind === 'doc'
                                    ? { label: kind === 'doc' ? 'DOC' : 'DOCX', cls: 'text-blue-700', bg: 'bg-blue-50', border: 'border-blue-200' }
                                    : kind === 'xlsx'
                                      ? { label: 'XLS', cls: 'text-emerald-700', bg: 'bg-emerald-50', border: 'border-emerald-200' }
                                      : kind === 'image'
                                        ? { label: 'IMG', cls: 'text-violet-700', bg: 'bg-violet-50', border: 'border-violet-200' }
                                        : { label: 'FILE', cls: 'text-slate-700', bg: 'bg-slate-50', border: 'border-slate-200' }
                                return (
                                  <div className={cn('mt-0.5 inline-flex size-9 items-center justify-center rounded-md border font-semibold text-[11px]', logo.bg, logo.border, logo.cls)}>
                                    {logo.label}
                                  </div>
                                )
                              })()}
                              <div className="space-y-0.5">
                                <p className="font-semibold text-foreground">{getDocumentDisplayName(doc)}</p>
                                <p className="text-xs text-muted-foreground">
                                  {getDocFileKind(doc?.file).toUpperCase()} • {formatBytes(doc?.file?.size)}
                                </p>
                              </div>
                            </div>
                          </td>
                          <td className="text-muted-foreground">{doc.version || '—'}</td>
                          <td className="text-muted-foreground">{doc.created_at ? formatDate(doc.created_at) : '—'}</td>
                          {(() => {
                            const meta = expiryMeta(doc.expiry_date)
                            return (
                              <td className={meta.cls === 'text-rose-700' ? 'font-medium text-rose-700' : meta.cls}>
                                {meta.label}
                              </td>
                            )
                          })()}
                          <td>
                            <Badge
                              variant="outline"
                              className={cn(
                                'inline-flex items-center gap-1.5 font-medium',
                                String(doc.status || '').toLowerCase() === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                : String(doc.status || '').toLowerCase() === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                  : String(doc.status || '').toLowerCase() === 'archived' ? 'border-slate-200 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                    : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                              )}
                            >
                              {String(doc.status || '').toLowerCase() === 'active' ? <CheckCircle2 className="size-3.5" /> : String(doc.status || '').toLowerCase() === 'rejected' ? <X className="size-3.5" /> : String(doc.status || '').toLowerCase() === 'archived' ? <Archive className="size-3.5" /> : <Clock className="size-3.5" />}
                              {String(doc.status || '').toLowerCase() === 'active' ? 'Active' : String(doc.status || '').toLowerCase() === 'rejected' ? 'Rejected' : String(doc.status || '').toLowerCase() === 'archived' ? 'Archived' : 'Pending'}
                            </Badge>
                          </td>
                          <td className="w-[168px] whitespace-nowrap text-right">
                            <div className="inline-flex items-center justify-end gap-1">
                              <Button type="button" variant="ghost" size="icon" title="View" className="cursor-pointer hover:bg-muted/60" onClick={() => openPreviewDocModal(doc)} aria-label="View">
                                <Eye className="size-4" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" title="Download" className="cursor-pointer hover:bg-muted/60" onClick={() => window.open(doc?.file?.url, '_blank', 'noopener,noreferrer')} aria-label="Download">
                                <FileDown className="size-4" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" title="Edit" className="cursor-pointer hover:bg-muted/60" onClick={() => openEditDocModal(doc)} aria-label="Edit" disabled={!canEditDeleteDoc(doc)}>
                                <Pencil className="size-4" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" title="Delete" className="cursor-pointer hover:bg-muted/60" onClick={() => requestDeleteDoc(doc)} aria-label="Delete" disabled={!canEditDeleteDoc(doc) || docsSaving}>
                                <Trash2 className="size-4" />
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                </div>
              </div>
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {activeTab === 'government' && (
        <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
          <CardHeader>
            <CardTitle>Government IDs</CardTitle>
            <CardDescription>Upload your government IDs for admin verification.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">Status: Pending → Approved/Rejected by Admin</p>
              <Button type="button" onClick={openAddGovIdModal}>
                <Plus className="mr-2 size-4" />
                Upload Government ID
              </Button>
            </div>

            <div className="overflow-x-auto rounded-lg border border-border/60">
              <table className="w-full text-sm">
                <thead className="bg-muted/30 text-xs uppercase tracking-wide text-muted-foreground">
                  <tr className="[&>th]:px-4 [&>th]:py-3 [&>th]:text-left">
                    <th>ID Type</th>
                    <th>ID Number</th>
                    <th>Issuing Agency</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                    <th>Date Uploaded</th>
                    <th className="w-[132px] whitespace-nowrap text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {govIdDocsLoading ? (
                    <tr><td colSpan={7} className="px-4 py-6 text-muted-foreground">Loading…</td></tr>
                  ) : govIdDocs.length === 0 ? (
                    <tr><td colSpan={7} className="px-4 py-6 text-muted-foreground">No government IDs uploaded yet.</td></tr>
                  ) : (
                    govIdDocs.map((doc) => (
                      <tr key={doc.id} className="[&>td]:px-4 [&>td]:py-4">
                        <td className="font-semibold">{doc.id_type}</td>
                        <td className="text-muted-foreground">{doc.id_number}</td>
                        <td className="text-muted-foreground">{doc.issuing_agency}</td>
                        {(() => {
                          const expMeta = doc.expiry_date ? expiryMeta(doc.expiry_date) : { cls: 'text-muted-foreground' }
                          return (
                            <td className={expMeta.cls === 'text-rose-700' ? 'font-medium text-rose-700' : expMeta.cls}>
                              {doc.expiry_date ? formatDate(doc.expiry_date) : '—'}
                              {expMeta.label && (expMeta.cls === 'text-rose-700' || expMeta.cls === 'text-amber-800') ? (
                                <span className="ml-1.5 text-xs">({expMeta.label})</span>
                              ) : null}
                            </td>
                          )
                        })()}
                        <td>
                          <Badge
                            variant="outline"
                            className={cn(
                              'inline-flex items-center gap-1.5',
                              doc.status === 'approved' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                              : doc.status === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                              : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                            )}
                          >
                            {doc.status === 'approved' ? <CheckCircle2 className="size-3.5" /> : doc.status === 'rejected' ? <X className="size-3.5" /> : <Clock className="size-3.5" />}
                            {doc.status === 'approved' ? 'Approved' : doc.status === 'rejected' ? 'Rejected' : 'Pending'}
                          </Badge>
                        </td>
                        <td className="text-muted-foreground">{formatDate(doc.created_at)}</td>
                        <td className="w-[132px] whitespace-nowrap text-right">
                          <div className="inline-flex items-center justify-end gap-1">
                            <Button type="button" variant="ghost" size="icon" className="cursor-pointer hover:bg-muted/60" onClick={() => openPreviewGovIdModal(doc)} aria-label="Preview">
                              <Eye className="size-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="icon" className="cursor-pointer hover:bg-muted/60" onClick={() => openEditGovIdModal(doc)} aria-label="Edit" disabled={doc.status === 'approved'}>
                              <Pencil className="size-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="icon" className="cursor-pointer hover:bg-muted/60" onClick={() => requestDeleteGovId(doc)} aria-label="Delete" disabled={doc.status === 'approved' || govIdDocsSaving}>
                              <Trash2 className="size-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}

      {activeTab === 'emergency' && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-3xl font-semibold">Emergency Contacts</h3>
              <p className="text-sm text-muted-foreground">Manage contacts to be notified in case of an emergency.</p>
            </div>
            <Button type="button" onClick={startAddEmergencyContact}>
              <Plus className="mr-1 size-4" />
              Add New Contact
            </Button>
          </div>

          <div className="grid gap-4 @lg:grid-cols-2">
            <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
              <CardContent className="space-y-3 p-4">
                {emergencyContacts.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No emergency contacts yet. Add at least one primary contact.</p>
                ) : (
                  emergencyContacts.map((contact) => (
                    <div key={contact.id} className="rounded-lg border border-border/60 p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-2">
                          <div className="rounded-full border border-border/60 bg-muted/20 p-2">
                            <UserRound className="size-4" />
                          </div>
                          <div>
                            <p className="font-semibold">{contact.full_name}</p>
                            <p className="text-xs text-muted-foreground">{contact.relationship}</p>
                          </div>
                        </div>
                        {contact.is_primary && (
                          <Badge className="bg-blue-600 text-white">Primary</Badge>
                        )}
                      </div>
                      <div className="mt-3 space-y-1 text-sm">
                        <p className="inline-flex items-center gap-2 text-muted-foreground">
                          <Phone className="size-4" />
                          {contact.phone_number}
                        </p>
                        <p className="inline-flex items-start gap-2 text-muted-foreground">
                          <MapPin className="mt-0.5 size-4" />
                          <span>{contact.address}</span>
                        </p>
                      </div>
                      <div className="mt-3 flex flex-wrap gap-2 border-t border-border/60 pt-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => startEditEmergencyContact(contact)}>
                          <Pencil className="mr-1 size-3.5" />
                          Edit
                        </Button>
                        <Button type="button" variant="ghost" size="sm" className="text-destructive" onClick={() => removeEmergencyContact(contact.id)}>
                          <Trash2 className="mr-1 size-3.5" />
                          Delete
                        </Button>
                        {!contact.is_primary && (
                          <Button type="button" variant="outline" size="sm" onClick={() => setPrimaryEmergencyContact(contact.id)}>
                            Set as Primary
                          </Button>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>

            <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
              <CardContent className="space-y-3 p-4">
                <div className="space-y-1">
                  <Label>Full Name</Label>
                  <Input
                    value={emergencyForm.full_name}
                    onChange={(e) => setEmergencyForm((prev) => ({ ...prev, full_name: e.target.value }))}
                    placeholder="e.g. Sarah Doe"
                  />
                  {emergencyErrors.full_name && <p className="text-xs text-destructive">{emergencyErrors.full_name}</p>}
                </div>
                <div className="grid gap-3 @sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label>Relationship</Label>
                    <Select value={emergencyForm.relationship || 'none'} onValueChange={(value) => setEmergencyForm((prev) => ({ ...prev, relationship: value === 'none' ? '' : value }))}>
                      <SelectTrigger><SelectValue placeholder="Select relationship" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">Select relationship</SelectItem>
                        <SelectItem value="Spouse">Spouse</SelectItem>
                        <SelectItem value="Parent">Parent</SelectItem>
                        <SelectItem value="Sibling">Sibling</SelectItem>
                        <SelectItem value="Child">Child</SelectItem>
                        <SelectItem value="Friend">Friend</SelectItem>
                        <SelectItem value="Relative">Relative</SelectItem>
                      </SelectContent>
                    </Select>
                    {emergencyErrors.relationship && <p className="text-xs text-destructive">{emergencyErrors.relationship}</p>}
                  </div>
                  <div className="space-y-1">
                    <Label>Phone Number</Label>
                    <Input
                      value={emergencyForm.phone_number}
                      onChange={(e) => setEmergencyForm((prev) => ({ ...prev, phone_number: e.target.value }))}
                      placeholder="+63 9XX XXX XXXX"
                    />
                    {emergencyErrors.phone_number && <p className="text-xs text-destructive">{emergencyErrors.phone_number}</p>}
                  </div>
                </div>
                <div className="space-y-1">
                  <Label>Address</Label>
                  {isMapsLoaded ? (
                    <Autocomplete
                      onLoad={(ac) => {
                        emergencyAddressAutocompleteRef.current = ac
                        try {
                          ac.setFields(['address_components', 'formatted_address', 'name'])
                          ac.setTypes(['address'])
                        } catch (e) {
                          void e
                        }
                      }}
                      onPlaceChanged={onEmergencyAddressPlaceChanged}
                      options={{ componentRestrictions: { country: 'ph' } }}
                    >
                      <Input
                        value={emergencyForm.address}
                        onChange={(e) => setEmergencyForm((prev) => ({ ...prev, address: e.target.value }))}
                        placeholder="Start typing to search address..."
                      />
                    </Autocomplete>
                  ) : (
                    <Input
                      value={emergencyForm.address}
                      onChange={(e) => setEmergencyForm((prev) => ({ ...prev, address: e.target.value }))}
                      placeholder="Street, City, Province"
                    />
                  )}
                  {mapsLoadError && (
                    <p className="text-xs text-amber-600">Address autocomplete unavailable: {mapsLoadError.message || String(mapsLoadError)}</p>
                  )}
                  {emergencyErrors.address && <p className="text-xs text-destructive">{emergencyErrors.address}</p>}
                </div>
                <div className="flex items-center gap-2">
                  <input
                    id="emergency-primary"
                    type="checkbox"
                    checked={!!emergencyForm.is_primary}
                    onChange={(e) => setEmergencyForm((prev) => ({ ...prev, is_primary: e.target.checked }))}
                  />
                  <Label htmlFor="emergency-primary">Set as primary contact</Label>
                </div>
                <div className="flex items-center gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={saveEmergencyContact}>
                    {editingEmergencyId ? 'Update Contact' : 'Add Contact'}
                  </Button>
                  <Button type="button" variant="ghost" onClick={startAddEmergencyContact}>Cancel</Button>
                </div>
                <p className="text-xs text-muted-foreground">Click &quot;Save Emergency Contacts&quot; below to persist your contacts to the server.</p>
              </CardContent>
            </Card>
          </div>

          <Card className="border border-dashed border-border/70">
            <CardContent className="flex flex-col gap-3 p-4 @sm:flex-row @sm:items-center @sm:justify-between">
              <p className="text-xs text-muted-foreground">Save your emergency contacts to update your profile.</p>
              <Button type="button" onClick={saveEmergencyContacts} disabled={emergencySaving}>
                {emergencySaving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                Save Emergency Contacts
              </Button>
            </CardContent>
          </Card>
        </div>
      )}

      {activeTab === 'skills' && (
        <div className="space-y-4">
          <div className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between">
            <div className="space-y-1">
              <h3 className="text-xl font-semibold tracking-tight">Skills &amp; Certifications</h3>
              <p className="text-sm text-muted-foreground">
                Employee ID:{' '}
                <span className="font-medium text-foreground">
                  {displayUser?.employee_code || displayUser?.employee_id || displayUser?.id || '—'}
                </span>
                {displayUser?.position ? <span className="mx-2 text-muted-foreground">•</span> : null}
                <span className="text-muted-foreground">{displayUser?.position || ''}</span>
              </p>
            </div>

            <div className="flex flex-wrap gap-2 @sm:justify-end">
              <Button type="button" variant="outline" onClick={() => window.print()} className="bg-background">
                <Upload className="mr-2 size-4" />
                Export PDF
              </Button>
              <Button type="button" onClick={() => toast.info('This section saves automatically.')}>
                <FileText className="mr-2 size-4" />
                Update Profile
              </Button>
            </div>
          </div>

          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader className="pb-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2 text-base">
                  <Zap className="size-4 text-foreground" />
                  Professional Skills
                </CardTitle>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="shrink-0"
                  onClick={() => openAddSkillModal('')}
                  disabled={skillsSaving || skillsLoading || !canEdit}
                >
                  <Plus className="mr-1 size-4" />
                  Add New Skill
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              <div className="rounded-xl border border-border/60 bg-muted/10 p-4">
                <div className="flex flex-wrap items-center gap-2">
                  {skillsLoading ? (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                  ) : skills.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border/70 bg-muted/5 py-8 text-center">
                      <div className="flex size-12 items-center justify-center rounded-full bg-muted/50 text-muted-foreground">
                        <Zap className="size-6" />
                      </div>
                      <div className="space-y-1">
                        <p className="font-medium text-foreground">No skills added yet</p>
                        <p className="text-sm text-muted-foreground">Add skills like Python, Leadership, or AWS — they help with internal opportunities and showcase your expertise.</p>
                      </div>
                      <Button type="button" variant="outline" size="sm" onClick={() => openAddSkillModal('')} disabled={skillsLoading || !canEdit}>
                        <Plus className="mr-1.5 size-4" />
                        Add first skill
                      </Button>
                    </div>
                  ) : (
                    skills.map((s) => (
                      <div key={s.id} className="group inline-flex items-center gap-2 rounded-full bg-muted px-3 py-2 text-sm">
                        <CheckCircle2 className="size-4 text-emerald-600" />
                        <button type="button" className="font-medium hover:underline" onClick={() => openSkillPreview(s)}>
                          {s.name}
                        </button>
                        {canEdit ? (
                          <div className="hidden items-center gap-1 group-hover:flex">
                            <button
                              type="button"
                              className="rounded-full p-1 text-muted-foreground hover:bg-background hover:text-foreground"
                              onClick={() => openRenameSkillModal(s)}
                              aria-label={`Edit ${s.name}`}
                              disabled={skillsSaving}
                            >
                              <Pencil className="size-3.5" />
                            </button>
                            <button
                              type="button"
                              className="rounded-full p-1 text-muted-foreground hover:bg-background hover:text-foreground"
                              onClick={() => handleRemoveSkill(s)}
                              aria-label={`Remove ${s.name}`}
                              disabled={skillsSaving}
                            >
                              <X className="size-3.5" />
                            </button>
                          </div>
                        ) : null}
                      </div>
                    ))
                  )}

                  {canEdit ? (
                    <button
                      type="button"
                      className="inline-flex items-center gap-2 rounded-full border border-dashed border-border/70 bg-background px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
                      onClick={() => openAddSkillModal('')}
                      disabled={skillsSaving || skillsLoading}
                    >
                      <Plus className="size-4" />
                      New
                    </button>
                  ) : null}
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader className="pb-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2 text-base">
                  <FileText className="size-4 text-foreground" />
                  Certifications
                </CardTitle>
                <Button type="button" size="sm" onClick={openAddCertificationModal} className="shrink-0" disabled={!canEdit}>
                  <Plus className="mr-1 size-4" />
                  Add Certification
                </Button>
              </div>
            </CardHeader>
            <CardContent className="px-0 pb-0">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-muted/30 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr className="[&>th]:px-4 [&>th]:py-3 [&>th]:text-left">
                      <th>Certification Name</th>
                      <th>Issuing Organization</th>
                      <th>Issued Date</th>
                      <th>Expiration Date</th>
                      <th>Verification</th>
                      <th className="text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {certificationsLoading ? (
                      <tr>
                        <td className="px-4 py-6 text-muted-foreground" colSpan={6}>
                          Loading certifications…
                        </td>
                      </tr>
                    ) : certifications.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8">
                          <div className="flex flex-col items-center gap-3 text-center">
                            <div className="flex size-12 items-center justify-center rounded-full bg-muted/50 text-muted-foreground">
                              <Award className="size-6" />
                            </div>
                            <div className="space-y-1">
                              <p className="font-medium text-foreground">No certifications yet</p>
                              <p className="text-sm text-muted-foreground">Upload certs to record your credentials and showcase your professional growth — visible to your manager too.</p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={openAddCertificationModal}>
                              <Plus className="mr-1.5 size-4" />
                              Add certification
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      certifications.map((cert) => (
                        <tr key={cert.id} className="[&>td]:px-4 [&>td]:py-4">
                          <td className="min-w-[240px]">
                            <div className="space-y-0.5">
                              <p className="font-semibold text-foreground">{cert.certification_name}</p>
                              {cert.credential_id ? (
                                <p className="text-xs text-muted-foreground">Credential ID: {cert.credential_id}</p>
                              ) : (
                                <p className="text-xs text-muted-foreground">—</p>
                              )}
                            </div>
                          </td>
                          <td className="min-w-[180px] text-muted-foreground">{cert.issuing_organization || '—'}</td>
                          <td className="min-w-[140px] text-muted-foreground">{formatDate(cert.issue_date)}</td>
                          <td className="min-w-[160px]">
                            {cert.expiration_date ? (() => {
                              const meta = expiryMeta(cert.expiration_date)
                              const daysLeft = Math.ceil((new Date(cert.expiration_date) - new Date()) / (1000 * 60 * 60 * 24))
                              return (
                                <span className={meta.cls === 'text-rose-700' ? 'font-medium text-rose-600 dark:text-rose-400' : meta.cls === 'text-amber-800' ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}>
                                  {formatDate(cert.expiration_date)}
                                  {daysLeft >= 0 && daysLeft <= 60 && (
                                    <span className="ml-1 text-[11px]">
                                      {daysLeft <= 30 ? `(${daysLeft}d left!)` : `(${daysLeft}d)`}
                                    </span>
                                  )}
                                  {daysLeft < 0 && <span className="ml-1 text-[11px]">(Expired)</span>}
                                </span>
                              )
                            })() : <span className="text-muted-foreground">—</span>}
                          </td>
                          <td className="min-w-[140px]">
                            <Badge
                              variant="outline"
                              className={cn(
                                cert.verification_status === 'verified' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                  : cert.verification_status === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                    : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                              )}
                            >
                              {cert.verification_status === 'verified' ? 'Verified' : cert.verification_status === 'rejected' ? 'Rejected' : 'Pending'}
                            </Badge>
                          </td>
                          <td className="min-w-[120px] text-right">
                            <div className="inline-flex items-center justify-end gap-1">
                              <Button type="button" variant="ghost" size="icon" onClick={() => openPreviewCertificationModal(cert)} aria-label={`View ${cert.certification_name}`}>
                                <Eye className="size-4" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" onClick={() => openEditCertificationModal(cert)} aria-label={`Edit ${cert.certification_name}`} disabled={cert.verification_status === 'verified'}>
                                <Pencil className="size-4" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" onClick={() => requestDeleteCertification(cert)} aria-label={`Remove ${cert.certification_name}`} disabled={certificationsSaving}>
                                <Trash2 className="size-4" />
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>

          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between gap-3">
                <CardTitle className="flex items-center gap-2 text-base">
                  <FileText className="size-4 text-foreground" />
                  Certification Documents
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                  {certifications.filter((c) => c?.certificate?.url).length} file{certifications.filter((c) => c?.certificate?.url).length === 1 ? '' : 's'} uploaded{' '}
                  {certifications.filter((c) => c?.certificate?.url).length > 0 ? (
                    <span className="text-muted-foreground">
                      (Total {formatFileSize(certifications.reduce((sum, c) => sum + (Number(c?.certificate?.size) || 0), 0))})
                    </span>
                  ) : null}
                </p>
              </div>
            </CardHeader>
            <CardContent>
              <div className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-3">
                {certifications
                  .filter((c) => c?.certificate?.url)
                  .map((cert) => (
                  <div key={cert.id} className="rounded-xl border border-border/60 bg-background p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex items-start gap-3">
                        <div className="grid size-10 place-items-center rounded-lg bg-rose-50 text-rose-600">
                          <FileText className="size-5" />
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold">{cert.certification_name}</p>
                          <p className="mt-0.5 text-xs text-muted-foreground">{formatFileSize(cert?.certificate?.size)} • {cert?.certificate?.mime || 'file'}</p>
                        </div>
                      </div>
                      <Button type="button" variant="ghost" size="icon" onClick={() => openPreviewCertificationModal(cert)} aria-label={`Preview ${cert.certification_name}`}>
                        <Eye className="size-4" />
                      </Button>
                    </div>
                    <div className="mt-3 flex gap-2">
                      <Button type="button" variant="outline" size="sm" onClick={() => openPreviewCertificationModal(cert)} className="flex-1">
                        View
                      </Button>
                      <Button type="button" variant="outline" size="sm" onClick={() => window.open(cert?.certificate?.url, '_blank', 'noopener,noreferrer')} className="flex-1">
                        Download
                      </Button>
                    </div>
                  </div>
                ))}

                <button
                  type="button"
                  className="group flex min-h-[132px] flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border/70 bg-muted/10 p-4 text-center"
                  onClick={openAddCertificationModal}
                >
                  <div className="grid size-12 place-items-center rounded-full bg-background shadow-sm transition group-hover:shadow">
                    <Upload className="size-5 text-muted-foreground group-hover:text-foreground" />
                  </div>
                  <p className="text-sm font-semibold">Upload New File</p>
                  <p className="text-xs text-muted-foreground">PDF, JPG up to 10MB</p>
                </button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {activeTab === 'account' && (
        <div className="grid gap-6 @lg:grid-cols-2">
          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader>
              <CardTitle>Account Information</CardTitle>
              <CardDescription>Sensitive fields are managed by administrators.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <p><span className="text-muted-foreground">Username:</span> {displayUser?.email || '—'}</p>
              <p><span className="text-muted-foreground">Email Address:</span> {displayUser?.email || '—'}</p>
              <p className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">Role:</span>
                <RoleBadge user={displayUser} size="sm" />
              </p>
              <p><span className="text-muted-foreground">Account Status:</span> {displayUser?.is_active ? 'Active' : 'Inactive'}</p>
              <p><span className="text-muted-foreground">Last Login:</span> —</p>
            </CardContent>
          </Card>

          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader>
              <CardTitle>Update Login Details</CardTitle>
              <CardDescription>Changing email/phone/password requires identity verification (face liveness).</CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <div className="space-y-2">
                <Label htmlFor="acc_email">Email Address</Label>
                <Input
                  id="acc_email"
                  type="email"
                  value={email}
                  onChange={(e) => {
                    const next = sanitizeEmail(e.target.value)
                    setEmail(next)
                    setEmailError(validateEmail(next))
                  }}
                  className={cn(emailError && 'border-destructive')}
                />
                {emailError && <p className="text-sm text-destructive">{emailError}</p>}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    const err = validateEmail(email)
                    setEmailError(err)
                    if (err) return
                    if (email === user?.email) {
                      setEmailError('Enter a new email address to change.')
                      return
                    }
                    requestSensitiveUpdate('email', { email: email.trim() })
                  }}
                  disabled={emailLoading}
                >
                  {emailLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                  Update Email
                </Button>
              </div>

              <div className="space-y-2">
                <Label htmlFor="acc_phone">Contact Number</Label>
                <Input
                  id="acc_phone"
                  value={phone}
                  onChange={(e) => {
                    const next = e.target.value
                    setPhone(next)
                    setPhoneError(validatePhone(next, false))
                  }}
                  className={cn(phoneError && 'border-destructive')}
                />
                {phoneError && <p className="text-sm text-destructive">{phoneError}</p>}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    const err = validatePhone(phone, false)
                    setPhoneError(err)
                    if (err && String(phone || '').trim() !== '') return
                    requestSensitiveUpdate('phone', { phone_number: String(phone || '').trim() || null })
                  }}
                  disabled={phoneLoading}
                >
                  {phoneLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                  Update Phone
                </Button>
              </div>

              <div className="space-y-3">
                <p className="text-sm font-semibold">Change Password</p>
                <div className="space-y-2">
                  <Label htmlFor="acc_current">Current Password</Label>
                  <Input
                    id="acc_current"
                    type="password"
                    value={currentPassword}
                    onChange={(e) => {
                      const next = e.target.value
                      setCurrentPassword(next)
                      setPasswordErrors((prev) => ({ ...prev, current: next ? '' : prev.current }))
                    }}
                    className={cn(passwordErrors.current && 'border-destructive')}
                  />
                  {passwordErrors.current && <p className="text-sm text-destructive">{passwordErrors.current}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="acc_new">New Password</Label>
                  <Input
                    id="acc_new"
                    type="password"
                    value={newPassword}
                    onChange={(e) => {
                      const next = sanitizePassword(e.target.value)
                      setNewPassword(next)
                      setPasswordErrors((prev) => ({
                        ...prev,
                        new: validatePassword(next, true),
                        confirm: confirmPassword ? validateConfirmPassword(next, confirmPassword) : prev.confirm,
                      }))
                    }}
                    className={cn(passwordErrors.new && 'border-destructive')}
                  />
                  {passwordErrors.new && <p className="text-sm text-destructive">{passwordErrors.new}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="acc_confirm">Confirm New Password</Label>
                  <Input
                    id="acc_confirm"
                    type="password"
                    value={confirmPassword}
                    onChange={(e) => {
                      const next = sanitizePassword(e.target.value)
                      setConfirmPassword(next)
                      setPasswordErrors((prev) => ({ ...prev, confirm: validateConfirmPassword(newPassword, next) }))
                    }}
                    className={cn(passwordErrors.confirm && 'border-destructive')}
                  />
                  {passwordErrors.confirm && <p className="text-sm text-destructive">{passwordErrors.confirm}</p>}
                </div>
                <Button
                  type="button"
                  onClick={() => {
                    const currentErr = !currentPassword ? 'Current password is required.' : ''
                    const newErr = validatePassword(newPassword, true)
                    const confirmErr = newPassword ? validateConfirmPassword(newPassword, confirmPassword) : ''
                    setPasswordErrors({ current: currentErr, new: newErr, confirm: confirmErr })
                    if (currentErr || newErr || confirmErr) return
                    requestSensitiveUpdate('password', {
                      current_password: currentPassword,
                      password: newPassword,
                      password_confirmation: confirmPassword,
                    })
                  }}
                  disabled={passwordLoading}
                >
                  {passwordLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                  Update Password
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      <Dialog open={skillAddOpen} onOpenChange={(open) => {
        setSkillAddOpen(open)
        if (!open) {
          setSkillDraft('')
          setSkillDraftError('')
          setSkillSuggestions([])
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="emp-profile-skill-add-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Add Skill</DialogTitle>
              <p id="emp-profile-skill-add-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Start typing to see suggestions, or add a custom skill.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label>Skill Name</Label>
              <Input
                ref={skillInputRef}
                value={skillDraft}
                onChange={(e) => setSkillDraft(e.target.value)}
                placeholder="e.g. React, Project Management"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    void submitAddSkill()
                  }
                }}
              />
              {skillDraftError ? <p className="text-xs text-destructive">{skillDraftError}</p> : null}
            </div>

            <div className="rounded-md border border-border/60 p-3">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Suggestions</p>
              {skillSuggestionsLoading ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
              ) : skillSuggestions.length === 0 ? (
                <p className="text-sm text-muted-foreground">No suggestions.</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {skillSuggestions.map((name) => (
                    <Button key={name} type="button" variant="outline" size="sm" onClick={() => setSkillDraft(String(name))}>
                      {name}
                    </Button>
                  ))}
                </div>
              )}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setSkillAddOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitAddSkill() }}
              disabled={skillsSaving || !String(skillDraft || '').trim()}
            >
              {skillsSaving ? 'Saving…' : 'Save Skill'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={skillRenameOpen} onOpenChange={(open) => {
        setSkillRenameOpen(open)
        if (!open) {
          setSkillDraft('')
          setSkillDraftError('')
          setActiveSkill(null)
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="emp-profile-skill-rename-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Rename Skill</DialogTitle>
              <p id="emp-profile-skill-rename-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Update the skill name in your profile.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2')}>
            <Label>Skill Name</Label>
            <Input
              ref={skillInputRef}
              value={skillDraft}
              onChange={(e) => setSkillDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  void submitRenameSkill()
                }
              }}
            />
            {skillDraftError ? <p className="text-xs text-destructive">{skillDraftError}</p> : null}
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setSkillRenameOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitRenameSkill() }}
              disabled={skillsSaving || !String(skillDraft || '').trim()}
            >
              {skillsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={skillPreviewOpen} onOpenChange={setSkillPreviewOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="emp-profile-skill-preview-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Skill Details</DialogTitle>
              <p id="emp-profile-skill-preview-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Preview skill information.
              </p>
            </DialogHeader>
          </div>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2 text-sm')}>
            {activeSkill ? (
              <>
                <p><span className="text-muted-foreground">Skill:</span> <span className="font-semibold">{activeSkill.name}</span></p>
                <p><span className="text-muted-foreground">Skill ID:</span> {activeSkill.id}</p>
              </>
            ) : null}
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setSkillPreviewOpen(false)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={certModalOpen} onOpenChange={(open) => {
        setCertModalOpen(open)
        if (!open) {
          setActiveCert(null)
          setCertErrors({})
          setCertForm((prev) => ({ ...prev, certificate_file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-cert-add-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Add Certification</DialogTitle>
              <p id="emp-profile-cert-add-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Submit a certification for admin verification.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Certification Name</Label>
              <Input value={certForm.certification_name} onChange={(e) => setCertForm((p) => ({ ...p, certification_name: e.target.value }))} />
              {certErrors.certification_name ? <p className="text-xs text-destructive">{certErrors.certification_name}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Organization</Label>
              <Input value={certForm.issuing_organization} onChange={(e) => setCertForm((p) => ({ ...p, issuing_organization: e.target.value }))} />
              {certErrors.issuing_organization ? <p className="text-xs text-destructive">{certErrors.issuing_organization}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Issue Date</Label>
              <Input type="date" value={certForm.issue_date} onChange={(e) => setCertForm((p) => ({ ...p, issue_date: e.target.value }))} />
              {certErrors.issue_date ? <p className="text-xs text-destructive">{certErrors.issue_date}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={certForm.expiration_date} onChange={(e) => setCertForm((p) => ({ ...p, expiration_date: e.target.value }))} />
              {certErrors.expiration_date ? <p className="text-xs text-destructive">{certErrors.expiration_date}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Credential ID (optional)</Label>
              <Input value={certForm.credential_id} onChange={(e) => setCertForm((p) => ({ ...p, credential_id: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Credential URL (optional)</Label>
              <Input value={certForm.credential_url} onChange={(e) => setCertForm((p) => ({ ...p, credential_url: e.target.value }))} placeholder="https://..." />
            </div>

            <div className="space-y-2 @sm:col-span-2">
              <Label>Certificate File (optional)</Label>
              <input
                ref={certFileRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setCertForm((p) => ({ ...p, certificate_file: file || null }))
                }}
              />
              <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" onClick={() => certFileRef.current?.click()}>
                  <Upload className="mr-2 size-4" />
                  Choose File
                </Button>
                <p className="text-xs text-muted-foreground">
                  {certForm.certificate_file ? `${certForm.certificate_file.name} (${formatFileSize(certForm.certificate_file.size)})` : 'No file selected.'}
                </p>
              </div>
              {certErrors.certificate_file ? <p className="text-xs text-destructive">{certErrors.certificate_file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setCertModalOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitCreateCertification() }}
              disabled={certificationsSaving}
            >
              {certificationsSaving ? 'Saving…' : 'Submit'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={certEditModalOpen} onOpenChange={(open) => {
        setCertEditModalOpen(open)
        if (!open) {
          setActiveCert(null)
          setCertErrors({})
          setCertForm((prev) => ({ ...prev, certificate_file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-cert-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit Certification</DialogTitle>
              <p id="emp-profile-cert-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Editing re-submits your certification for verification.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Certification Name</Label>
              <Input value={certForm.certification_name} onChange={(e) => setCertForm((p) => ({ ...p, certification_name: e.target.value }))} />
              {certErrors.certification_name ? <p className="text-xs text-destructive">{certErrors.certification_name}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Organization</Label>
              <Input value={certForm.issuing_organization} onChange={(e) => setCertForm((p) => ({ ...p, issuing_organization: e.target.value }))} />
              {certErrors.issuing_organization ? <p className="text-xs text-destructive">{certErrors.issuing_organization}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Issue Date</Label>
              <Input type="date" value={certForm.issue_date} onChange={(e) => setCertForm((p) => ({ ...p, issue_date: e.target.value }))} />
              {certErrors.issue_date ? <p className="text-xs text-destructive">{certErrors.issue_date}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={certForm.expiration_date} onChange={(e) => setCertForm((p) => ({ ...p, expiration_date: e.target.value }))} />
              {certErrors.expiration_date ? <p className="text-xs text-destructive">{certErrors.expiration_date}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Credential ID (optional)</Label>
              <Input value={certForm.credential_id} onChange={(e) => setCertForm((p) => ({ ...p, credential_id: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Credential URL (optional)</Label>
              <Input value={certForm.credential_url} onChange={(e) => setCertForm((p) => ({ ...p, credential_url: e.target.value }))} placeholder="https://..." />
            </div>

            <div className="space-y-2 @sm:col-span-2">
              <Label>Replace Certificate File (optional)</Label>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="block w-full text-sm"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setCertForm((p) => ({ ...p, certificate_file: file || null }))
                }}
              />
              {certErrors.certificate_file ? <p className="text-xs text-destructive">{certErrors.certificate_file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setCertEditModalOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitUpdateCertification() }}
              disabled={certificationsSaving}
            >
              {certificationsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={certPreviewOpen} onOpenChange={setCertPreviewOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(
            ADMIN_FORM_DIALOG_MAX_W_DEFAULT,
            '@container max-h-[90vh] w-[95vw] overflow-hidden'
          )}
          aria-describedby="emp-profile-cert-preview-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'flex-shrink-0')}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Preview Certification</DialogTitle>
              <p id="emp-profile-cert-preview-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Review certification details and uploaded certificate.
              </p>
            </DialogHeader>
          </div>

          {activeCert ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid min-h-0 flex-1 gap-3 overflow-y-auto text-sm')}>
              <div className="grid gap-2 grid-cols-1 @sm:grid-cols-2">
                <div className="min-w-0"><span className="text-muted-foreground">Certification:</span> <span className="font-medium break-words">{activeCert.certification_name}</span></div>
                <div className="min-w-0"><span className="text-muted-foreground">Issuing Organization:</span> <span className="font-medium break-words">{activeCert.issuing_organization}</span></div>
                <div className="min-w-0"><span className="text-muted-foreground">Issue Date:</span> <span className="font-medium">{formatDate(activeCert.issue_date)}</span></div>
                <div className="min-w-0"><span className="text-muted-foreground">Expiration Date:</span> <span className="font-medium">{activeCert.expiration_date ? formatDate(activeCert.expiration_date) : '—'}</span></div>
                <div className="min-w-0 @sm:col-span-2"><span className="text-muted-foreground">Credential ID:</span> <span className="font-medium break-words">{activeCert.credential_id || '—'}</span></div>
                <div className="min-w-0 @sm:col-span-2">
                  <span className="text-muted-foreground">Credential URL:</span>{' '}
                  {activeCert.credential_url ? (
                    <a className="font-medium text-primary underline-offset-4 hover:underline break-all" href={activeCert.credential_url} target="_blank" rel="noreferrer">
                      {activeCert.credential_url}
                    </a>
                  ) : (
                    <span className="font-medium">—</span>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">Status:</span>
                <span
                  className={cn(
                    'inline-flex rounded-full border px-2 py-1 text-xs',
                    activeCert.verification_status === 'verified'
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                      : activeCert.verification_status === 'rejected'
                        ? 'border-rose-200 bg-rose-50 text-rose-700'
                        : 'border-border bg-muted/30 text-muted-foreground'
                  )}
                >
                  {activeCert.verification_status === 'verified'
                    ? 'Verified'
                    : activeCert.verification_status === 'rejected'
                      ? 'Rejected'
                      : 'Pending'}
                </span>
                {activeCert.verification_status === 'rejected' && activeCert.rejection_reason ? (
                  <span className="text-xs text-muted-foreground">Reason: {activeCert.rejection_reason}</span>
                ) : null}
              </div>

              {activeCert?.certificate?.url ? (
                <div className="rounded-lg border border-border/60 p-3 min-w-0">
                  <div className="mb-2 flex flex-col gap-2 @sm:flex-row @sm:items-center @sm:justify-between">
                    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Uploaded Certificate</p>
                    <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => window.open(activeCert.certificate.url, '_blank', 'noopener,noreferrer')}>
                      Open in new tab
                    </Button>
                  </div>
                  {String(activeCert?.certificate?.mime || '').startsWith('image/') ? (
                    <img src={activeCert.certificate.url} alt="" className="max-h-[50vh] @sm:max-h-[420px] w-full rounded-md object-contain" />
                  ) : (
                    <iframe title="certificate-preview" src={activeCert.certificate.url} className="min-h-[200px] h-[50vh] @sm:h-[420px] w-full max-w-full rounded-md border" />
                  )}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No certificate file uploaded.</p>
              )}
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" size="default" className="min-w-[100px]" onClick={() => setCertPreviewOpen(false)}>
              <X className="mr-2 size-4" />
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={certDeleteOpen} onOpenChange={(open) => {
        setCertDeleteOpen(open)
        if (!open) setCertToDelete(null)
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="emp-profile-cert-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete certification?</DialogTitle>
              <p id="emp-profile-cert-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                This will permanently remove this certification from your profile. This action cannot be undone.
              </p>
            </DialogHeader>
          </div>

          {certToDelete ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2 text-sm')}>
              <p><span className="text-muted-foreground">Certification:</span> <span className="font-medium">{certToDelete.certification_name}</span></p>
              <p><span className="text-muted-foreground">Issuing organization:</span> <span className="font-medium">{certToDelete.issuing_organization}</span></p>
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setCertDeleteOpen(false)} disabled={certificationsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteCertification() }} disabled={certificationsSaving || !certToDelete?.id}>
              {certificationsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={govAddOpen} onOpenChange={(open) => {
        setGovAddOpen(open)
        if (!open) {
          setActiveGovDoc(null)
          setGovIdErrors({})
          setGovIdForm((p) => ({ ...p, document_file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-gov-add-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Upload Government ID</DialogTitle>
              <p id="emp-profile-gov-add-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Upload an ID document (PDF/JPG/PNG) for verification.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2">
              <Label>ID Type</Label>
              <Select value={govIdForm.id_type || 'none'} onValueChange={(v) => {
                const nextType = v === 'none' ? '' : v
                const def = govIdDefs[nextType]
                setGovIdForm((p) => ({
                  ...p,
                  id_type: nextType,
                  issuing_agency: nextType ? (def?.agency || '') : '',
                  id_number: formatGovIdNumber(nextType, p.id_number),
                }))
              }}>
                <SelectTrigger><SelectValue placeholder="Select ID type" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {phGovIdTypes.map((t) => (
                    <SelectItem key={t} value={t}>{t}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {govIdErrors.id_type ? <p className="text-xs text-destructive">{govIdErrors.id_type}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>ID Number</Label>
              <Input
                placeholder={govIdDefs[govIdForm.id_type]?.format || 'Enter ID number'}
                value={govIdForm.id_number}
                onChange={(e) => setGovIdForm((p) => ({ ...p, id_number: formatGovIdNumber(p.id_type, e.target.value) }))}
              />
              {govIdDefs[govIdForm.id_type]?.format ? (
                <p className="text-xs text-muted-foreground">Format: {govIdDefs[govIdForm.id_type].format} (e.g. {govIdDefs[govIdForm.id_type].example})</p>
              ) : null}
              {govIdErrors.id_number ? <p className="text-xs text-destructive">{govIdErrors.id_number}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Agency</Label>
              <Input value={govIdForm.issuing_agency} onChange={(e) => setGovIdForm((p) => ({ ...p, issuing_agency: e.target.value }))} />
              {govIdErrors.issuing_agency ? <p className="text-xs text-destructive">{govIdErrors.issuing_agency}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={govIdForm.expiry_date} onChange={(e) => setGovIdForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Document File</Label>
              <input
                ref={govFileRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setGovIdForm((p) => ({ ...p, document_file: file || null }))
                }}
              />
              <Button type="button" variant="outline" onClick={() => govFileRef.current?.click()}>
                <Upload className="mr-2 size-4" />
                Choose File
              </Button>
              <p className="text-xs text-muted-foreground">
                {govIdForm.document_file ? `${govIdForm.document_file.name} (${formatFileSize(govIdForm.document_file.size)})` : 'No file selected.'}
              </p>
              {govIdErrors.document_file ? <p className="text-xs text-destructive">{govIdErrors.document_file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setGovAddOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitCreateGovId() }}
              disabled={govIdDocsSaving}
            >
              {govIdDocsSaving ? 'Saving…' : 'Upload'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={govEditOpen} onOpenChange={(open) => {
        setGovEditOpen(open)
        if (!open) {
          setActiveGovDoc(null)
          setGovIdErrors({})
          setGovIdForm((p) => ({ ...p, document_file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-gov-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit Government ID</DialogTitle>
              <p id="emp-profile-gov-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Edits resubmit the ID for verification. Approved IDs cannot be edited.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2">
              <Label>ID Type</Label>
              <Select value={govIdForm.id_type || 'none'} onValueChange={(v) => {
                const nextType = v === 'none' ? '' : v
                const def = govIdDefs[nextType]
                setGovIdForm((p) => ({
                  ...p,
                  id_type: nextType,
                  issuing_agency: nextType ? (def?.agency || '') : '',
                  id_number: formatGovIdNumber(nextType, p.id_number),
                }))
              }}>
                <SelectTrigger><SelectValue placeholder="Select ID type" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {phGovIdTypes.map((t) => (
                    <SelectItem key={t} value={t}>{t}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {govIdErrors.id_type ? <p className="text-xs text-destructive">{govIdErrors.id_type}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>ID Number</Label>
              <Input
                placeholder={govIdDefs[govIdForm.id_type]?.format || 'Enter ID number'}
                value={govIdForm.id_number}
                onChange={(e) => setGovIdForm((p) => ({ ...p, id_number: formatGovIdNumber(p.id_type, e.target.value) }))}
              />
              {govIdDefs[govIdForm.id_type]?.format ? (
                <p className="text-xs text-muted-foreground">Format: {govIdDefs[govIdForm.id_type].format} (e.g. {govIdDefs[govIdForm.id_type].example})</p>
              ) : null}
              {govIdErrors.id_number ? <p className="text-xs text-destructive">{govIdErrors.id_number}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Agency</Label>
              <Input value={govIdForm.issuing_agency} onChange={(e) => setGovIdForm((p) => ({ ...p, issuing_agency: e.target.value }))} />
              {govIdErrors.issuing_agency ? <p className="text-xs text-destructive">{govIdErrors.issuing_agency}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={govIdForm.expiry_date} onChange={(e) => setGovIdForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Replace Document (optional)</Label>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="block w-full text-sm"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setGovIdForm((p) => ({ ...p, document_file: file || null }))
                }}
              />
              {govIdErrors.document_file ? <p className="text-xs text-destructive">{govIdErrors.document_file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setGovEditOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitUpdateGovId() }}
              disabled={govIdDocsSaving}
            >
              {govIdDocsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={govPreviewOpen} onOpenChange={setGovPreviewOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_DEFAULT)}
          aria-describedby="emp-profile-gov-preview-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Government ID Preview</DialogTitle>
              <p id="emp-profile-gov-preview-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Review uploaded ID details and document.
              </p>
            </DialogHeader>
          </div>

          {activeGovDoc ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-3 text-sm')}>
              <div className="grid gap-2 @sm:grid-cols-2">
                <div><span className="text-muted-foreground">ID Type:</span> <span className="font-medium">{activeGovDoc.id_type}</span></div>
                <div><span className="text-muted-foreground">ID Number:</span> <span className="font-medium">{activeGovDoc.id_number}</span></div>
                <div><span className="text-muted-foreground">Issuing Agency:</span> <span className="font-medium">{activeGovDoc.issuing_agency}</span></div>
                <div><span className="text-muted-foreground">Expiry Date:</span> <span className="font-medium">{activeGovDoc.expiry_date ? formatDate(activeGovDoc.expiry_date) : '—'}</span></div>
              </div>
              {activeGovDoc?.document?.url ? (
                <div className="rounded-lg border border-border/60 p-3">
                  <div className="mb-2 flex items-center justify-between gap-2">
                    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Uploaded Document</p>
                    <Button type="button" variant="outline" size="sm" onClick={() => window.open(activeGovDoc.document.url, '_blank', 'noopener,noreferrer')}>
                      Open in new tab
                    </Button>
                  </div>
                  {String(activeGovDoc?.document?.mime || '').startsWith('image/') ? (
                    <img src={activeGovDoc.document.url} alt="" className="max-h-[420px] w-full rounded-md object-contain" />
                  ) : (
                    <iframe title="gov-id-preview" src={activeGovDoc.document.url} className="h-[420px] w-full rounded-md border" />
                  )}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No document uploaded.</p>
              )}
              {activeGovDoc.status === 'rejected' && activeGovDoc.rejection_reason ? (
                <p className="text-xs text-rose-700">Rejection reason: {activeGovDoc.rejection_reason}</p>
              ) : null}
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setGovPreviewOpen(false)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={govDeleteOpen} onOpenChange={(open) => {
        setGovDeleteOpen(open)
        if (!open) setGovDeleteDoc(null)
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="emp-profile-gov-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete Government ID</DialogTitle>
              <p id="emp-profile-gov-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                This will permanently remove the selected Government ID record and its uploaded document.
              </p>
            </DialogHeader>
          </div>

          {govDeleteDoc ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2 text-sm')}>
              <p><span className="text-muted-foreground">ID Type:</span> <span className="font-medium">{govDeleteDoc.id_type}</span></p>
              <p><span className="text-muted-foreground">ID Number:</span> <span className="font-medium">{govDeleteDoc.id_number}</span></p>
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setGovDeleteOpen(false)} disabled={govIdDocsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteGovId() }} disabled={govIdDocsSaving || !govDeleteDoc?.id}>
              {govIdDocsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={docUploadOpen} onOpenChange={(open) => {
        setDocUploadOpen(open)
        if (!open) {
          setDocErrors({})
          setDocForm((p) => ({ ...p, file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-doc-upload-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Upload Document</DialogTitle>
              <p id="emp-profile-doc-upload-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Upload a PDF/DOCX file for admin review.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2">
              <Label>Category</Label>
              <Select value={docForm.category || 'none'} onValueChange={(v) => setDocForm((p) => ({ ...p, category: v === 'none' ? '' : v }))}>
                <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {documentCategories.map((c) => (
                    <SelectItem key={c} value={c}>{c}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {docErrors.category ? <p className="text-xs text-destructive">{docErrors.category}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Version (optional)</Label>
              <Input ref={docFileRef} value={docForm.version} onChange={(e) => setDocForm((p) => ({ ...p, version: e.target.value }))} placeholder="e.g. v1" />
              {docErrors.version ? <p className="text-xs text-destructive">{docErrors.version}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Document Name</Label>
              <Input value={docForm.document_name} onChange={(e) => setDocForm((p) => ({ ...p, document_name: e.target.value }))} placeholder="e.g. Employment Agreement" />
              {docErrors.document_name ? <p className="text-xs text-destructive">{docErrors.document_name}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiry Date (optional)</Label>
              <Input type="date" value={docForm.expiry_date} onChange={(e) => setDocForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>File</Label>
              <input
                type="file"
                accept=".pdf,.docx"
                className="block w-full text-sm"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setDocForm((p) => ({ ...p, file: file || null }))
                }}
              />
              {docErrors.file ? <p className="text-xs text-destructive">{docErrors.file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setDocUploadOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitCreateDoc() }}
              disabled={docsSaving}
            >
              {docsSaving ? 'Saving…' : 'Upload'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={docEditOpen} onOpenChange={(open) => {
        setDocEditOpen(open)
        if (!open) {
          setActiveDoc(null)
          setDocErrors({})
          setDocForm((p) => ({ ...p, file: null }))
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="emp-profile-doc-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit Document</DialogTitle>
              <p id="emp-profile-doc-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Editing re-submits the document as pending for admin review.
              </p>
            </DialogHeader>
          </div>

          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'grid gap-4 @sm:grid-cols-2')}>
            <div className="space-y-2">
              <Label>Category</Label>
              <Select value={docForm.category || 'none'} onValueChange={(v) => setDocForm((p) => ({ ...p, category: v === 'none' ? '' : v }))}>
                <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {documentCategories.map((c) => (
                    <SelectItem key={c} value={c}>{c}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {docErrors.category ? <p className="text-xs text-destructive">{docErrors.category}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Version (optional)</Label>
              <Input value={docForm.version} onChange={(e) => setDocForm((p) => ({ ...p, version: e.target.value }))} placeholder="e.g. v2" />
              {docErrors.version ? <p className="text-xs text-destructive">{docErrors.version}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Document Name</Label>
              <Input value={docForm.document_name} onChange={(e) => setDocForm((p) => ({ ...p, document_name: e.target.value }))} />
              {docErrors.document_name ? <p className="text-xs text-destructive">{docErrors.document_name}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiry Date (optional)</Label>
              <Input type="date" value={docForm.expiry_date} onChange={(e) => setDocForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Replace File (optional)</Label>
              <input
                type="file"
                accept=".pdf,.docx"
                className="block w-full text-sm"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setDocForm((p) => ({ ...p, file: file || null }))
                }}
              />
              {docErrors.file ? <p className="text-xs text-destructive">{docErrors.file}</p> : null}
            </div>
          </div>

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setDocEditOpen(false)}>Cancel</Button>
            <Button
              type="button"
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              onClick={() => { void submitUpdateDoc() }}
              disabled={docsSaving}
            >
              {docsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={docPreviewOpen} onOpenChange={(open) => {
        setDocPreviewOpen(open)
        if (!open) {
          setDocxPreviewHtml('')
          setDocxPreviewError('')
          setDocxPreviewLoading(false)
        }
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(
            'max-w-[min(100vw-1rem,48rem)]',
            '@container max-h-[90vh] w-[95vw] overflow-hidden'
          )}
          aria-describedby="emp-profile-doc-preview-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'flex-shrink-0')}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Document Preview</DialogTitle>
              <p id="emp-profile-doc-preview-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Review the file and metadata.
              </p>
            </DialogHeader>
          </div>

          {activeDoc ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'min-h-0 flex-1 space-y-3 overflow-y-auto text-sm')}>
              <div className="grid gap-2 @sm:grid-cols-2">
                <div><span className="text-muted-foreground">Name:</span> <span className="font-medium">{getDocumentDisplayName(activeDoc)}</span></div>
                <div><span className="text-muted-foreground">Category:</span> <span className="font-medium">{activeDoc.category}</span></div>
                <div><span className="text-muted-foreground">Version:</span> <span className="font-medium">{activeDoc.version || '—'}</span></div>
                <div><span className="text-muted-foreground">Expiry:</span> <span className="font-medium">{activeDoc.expiry_date ? formatDate(activeDoc.expiry_date) : '—'}</span></div>
              </div>

              {String(activeDoc?.status || '').toLowerCase() === 'rejected' && activeDoc?.review_note ? (
                <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-rose-700">
                  <div className="flex items-start gap-2">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-wide">Rejected</p>
                      <p className="text-sm">{activeDoc.review_note}</p>
                    </div>
                  </div>
                </div>
              ) : null}

              {activeDoc?.file?.url ? (
                <div className="rounded-lg border border-border/60 p-3 min-w-0">
                  <div className="mb-2 flex flex-col gap-2 @sm:flex-row @sm:items-center @sm:justify-between">
                    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">File</p>
                    <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => window.open(activeDoc.file.url, '_blank', 'noopener,noreferrer')}>
                      <FileDown className="mr-2 size-4" />
                      Download
                    </Button>
                  </div>
                  {(() => {
                    const kind = getDocFileKind(activeDoc?.file)
                    if (kind === 'pdf') {
                      return <iframe title="doc-preview" src={activeDoc.file.url} className="min-h-[200px] h-[50vh] @sm:h-[520px] w-full max-w-full rounded-md border" />
                    }
                    if (kind === 'image') {
                      return (
                        <div className="flex min-h-[200px] items-center justify-center rounded-md border bg-muted/20 p-4">
                          <img src={activeDoc.file.url} alt="Document preview" className="max-h-[50vh] max-w-full rounded-md object-contain @sm:max-h-[420px]" />
                        </div>
                      )
                    }
                    if (kind === 'docx') {
                      if (docxPreviewLoading) {
                        return (
                          <div className="flex h-[50vh] @sm:h-[520px] w-full min-h-[200px] items-center justify-center rounded-md border">
                            <Loader2 className="mr-2 size-4 animate-spin text-muted-foreground" />
                            <span className="text-sm text-muted-foreground">Generating preview…</span>
                          </div>
                        )
                      }
                      if (docxPreviewError) {
                        return (
                          <div className="flex h-[50vh] @sm:h-[520px] w-full min-h-[200px] flex-col items-center justify-center gap-2 rounded-md border p-6 text-center">
                            <p className="text-sm text-muted-foreground">{docxPreviewError}</p>
                            <p className="text-xs text-muted-foreground">Use Download to open the file.</p>
                          </div>
                        )
                      }
                      return (
                        <div className="h-[50vh] @sm:h-[520px] min-h-[200px] w-full overflow-auto rounded-md border bg-background p-4">
                          <div className="prose prose-sm max-w-none" dangerouslySetInnerHTML={{ __html: docxPreviewHtml || '<p>No preview content.</p>' }} />
                        </div>
                      )
                    }
                    return (
                      <div className="flex min-h-[120px] flex-col items-center justify-center gap-2 rounded-md border bg-muted/20 p-6 text-center">
                        <p className="text-sm text-muted-foreground">
                          {kind === 'doc' ? 'Preview not available for .DOC files.' : kind === 'xlsx' ? 'Preview not available for Excel files.' : 'Preview not available for this file type.'}
                        </p>
                        <p className="text-xs text-muted-foreground">Use Download to open the file.</p>
                      </div>
                    )
                  })()}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No file uploaded.</p>
              )}
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setDocPreviewOpen(false)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={docDeleteOpen} onOpenChange={(open) => {
        setDocDeleteOpen(open)
        if (!open) setActiveDoc(null)
      }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="emp-profile-doc-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete Document</DialogTitle>
              <p id="emp-profile-doc-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                This will permanently remove the selected document and its file.
              </p>
            </DialogHeader>
          </div>

          {activeDoc ? (
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2 text-sm')}>
              <p><span className="text-muted-foreground">Name:</span> <span className="font-medium">{getDocumentDisplayName(activeDoc)}</span></p>
              <p><span className="text-muted-foreground">Category:</span> <span className="font-medium">{activeDoc.category}</span></p>
            </div>
          ) : null}

          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => setDocDeleteOpen(false)} disabled={docsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteDoc() }} disabled={docsSaving || !activeDoc?.id}>
              {docsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={livenessOpen} onOpenChange={(open) => !open && (setLivenessOpen(false), setPendingUpdate(null))}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="emp-profile-liveness-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <ShieldCheck className="size-5" />
                Verify your identity
              </DialogTitle>
              <p id="emp-profile-liveness-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Changing your{' '}
                {pendingUpdate?.type === 'email'
                  ? 'email'
                  : pendingUpdate?.type === 'phone'
                    ? 'phone number'
                    : 'password'} requires identity verification. Complete the face liveness check to continue.
              </p>
            </DialogHeader>
          </div>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            <FaceRekognitionLiveness onVerified={submitPendingUpdateWithLiveness} onSuccess={() => setLivenessOpen(false)} hideInstruction />
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" onClick={() => { setLivenessOpen(false); setPendingUpdate(null) }}>
              Cancel
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

