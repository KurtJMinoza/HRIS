import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion' // eslint-disable-line no-unused-vars -- used in JSX
import {
  Plus,
  Calendar,
  CalendarCheck,
  UserCheck,
  UserX,
  Loader2,
  QrCode,
  Clock,
  AlertTriangle,
  Eye,
  Mail,
  Phone,
  BriefcaseBusiness,
  MapPin,
  RefreshCw,
  Trash2,
  KeyRound,
  EyeOff,
  Copy,
  Check,
  Download,
  MoreVertical,
  Search,
  X,
  ScanFace,
  ChevronDown,
  ChevronRight,
  ArrowUp,
  ArrowDown,
  Upload,
  LayoutList,
  CheckCircle2,
  XCircle,
  CircleDashed,
  Funnel,
  IdCard,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { PasswordInput } from '@/components/ui/password-input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { RoleBadge } from '@/components/RoleBadge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Checkbox } from '@/components/ui/checkbox'
import { AdminAddEmployeeDialog } from '@/components/admin/AdminAddEmployeeDialog'
import { deriveAdminEmployeeListLeaveCredits } from '@/lib/leaveCreditsDisplay'
import { compareEmployeesByLastName, formatEmployeeName } from '@/lib/employeeSort'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '@/components/ui/sheet'
import {
  getEmployees,
  exportAllEmployeesCsv,
  toggleEmployeeActive,
  getEmployeeQr,
  regenerateEmployeeQr,
  clearEmployeeQr,
  resetEmployeePassword,
  getEmployeePassword,
  getEmployeeFace,
  profileImageUrl,
  getDepartments,
  getBranches,
  getCompanies,
  companyLogoUrl,
  getWorkingSchedules,
  deleteEmployee,
  updateEmployee,
  checkEmployeeCodeAvailability,
  registerEmployeeFace,
  updateEmployeeFace,
  uploadEmployeePhoto,
  removeEmployeePhoto,
} from '@/api'
import { TableSkeleton } from '@/components/skeletons'
import ImportEmployeesModal from '@/components/admin/ImportEmployeesModal'
import { EmployeeScheduleAssignDialog } from '@/components/schedules/EmployeeScheduleAssignDialog'
import { ScheduleAdjustmentDialog } from '@/components/schedules/ScheduleAdjustmentDialog'
import { QRCodeCanvas } from 'qrcode.react'
import { useToast } from '@/components/ui/use-toast'
import { useHrBasePath } from '@/contexts/useHrBasePath'
import { hrPanelPath, isAdminHrUser } from '@/lib/hrRoutes'
import { FaceVerificationLiveness } from '@/components/FaceVerificationLiveness'
import { employmentStatusBadgeClassName, formatEmploymentStatusForViewer } from '@/lib/employmentStatus'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import { composeEmployeeCode, employeeCodeDigits, EMPLOYEE_CODE_PREFIX, isValidEmployeeCode } from '@/lib/employeeCode'
import { useAuth } from '@/contexts/AuthContext'
import { useQuery, useQueryClient } from '@tanstack/react-query'

const EMPLOYEE_LEVEL_OPTIONS = [
  { value: '0', label: 'Level 0 Staff / Employee' },
  { value: '1', label: 'Level 1 OIC / Team Leader / Unit/Section Head' },
  { value: '2', label: 'Level 2 Department Head' },
  { value: '3', label: 'Level 3 Division Head' },
  { value: '4', label: 'Level 4 Branch Head' },
  { value: '5', label: 'Level 5 Company Head / Executive' },
  { value: '6', label: 'Level 6 Admin' },
]

const BUSINESS_CARD_BUILDINGS_BG = '/business-card-assets/buildings-header.png'
const BUSINESS_CARD_DEFAULT_THEME = {
  primary: '#003da5',
  primaryDark: '#002d7f',
  primarySoft: '#e8f1ff',
  text: '#233b60',
  accent: '#5aa142',
}

function hasWorkingDays(schedule) {
  if (!schedule || typeof schedule !== 'object') return false
  return Object.values(schedule).some((v) => v && v.in && v.out)
}

/** Format 24h time (e.g. "08:00" or "17:00:00") to readable "8:00 AM" / "5:00 PM". */
function formatTime12h(timeStr) {
  if (!timeStr || typeof timeStr !== 'string') return ''
  const parts = timeStr.trim().split(':')
  const h = parseInt(parts[0], 10)
  const m = parts[1] ? parseInt(parts[1], 10) : 0
  if (Number.isNaN(h)) return timeStr
  const period = h >= 12 ? 'PM' : 'AM'
  const h12 = h % 12 || 12
  return `${h12}:${String(m).padStart(2, '0')} ${period}`
}

function formatSchedule(schedule) {
  if (!schedule || typeof schedule !== 'object') return '—'
  const entries = Object.entries(schedule).filter(([, v]) => v && v.in && v.out)
  if (entries.length === 0) return '—'
  const same = entries.every(([, v]) => v.in === entries[0][1].in && v.out === entries[0][1].out)
  if (same && entries.length >= 5) {
    const { in: inTime, out: outTime } = entries[0][1]
    return `${formatTime12h(inTime)} — ${formatTime12h(outTime)}`
  }
  return `${entries.length} days set`
}

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  })
}

function hasAssignedSchedule(employee) {
  if (!employee || typeof employee !== 'object') return false
  if (employee.schedule && hasWorkingDays(employee.schedule)) return true
  if (employee.working_schedule_id !== null && employee.working_schedule_id !== undefined && employee.working_schedule_id !== '') return true
  return false
}

function toBooleanLike(value) {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value === 1
  const normalized = String(value ?? '').trim().toLowerCase()
  return normalized === '1' || normalized === 'true' || normalized === 'yes'
}

function normalizeEmployeeFlags(employee) {
  if (!employee || typeof employee !== 'object') return employee
  const faceStatus = String(employee.face_status ?? '').trim().toLowerCase()
  const hasFace = toBooleanLike(employee.has_face) || faceStatus === 'registered'
  const deactivated =
    toBooleanLike(employee.is_deactivated)
    || String(employee.active_status || employee.employment_active_status || '').toLowerCase() === 'deactivated'
  return {
    ...employee,
    name: formatEmployeeName(employee, employee.name || 'Employee'),
    has_face: hasFace,
    is_active: !deactivated && toBooleanLike(employee.is_active),
  }
}

/** Consistent avatar color per employee (improves visual scan). */
const AVATAR_COLORS = [
  'bg-blue-500/20 text-blue-700 dark:bg-blue-400/25 dark:text-blue-200',
  'bg-violet-500/20 text-violet-700 dark:bg-violet-400/25 dark:text-violet-200',
  'bg-emerald-500/20 text-emerald-700 dark:bg-emerald-400/25 dark:text-emerald-200',
  'bg-amber-500/20 text-amber-700 dark:bg-amber-400/25 dark:text-amber-200',
  'bg-rose-500/20 text-rose-700 dark:bg-rose-400/25 dark:text-rose-200',
  'bg-cyan-500/20 text-cyan-700 dark:bg-cyan-400/25 dark:text-cyan-200',
  'bg-orange-500/20 text-orange-700 dark:bg-orange-400/25 dark:text-orange-200',
  'bg-fuchsia-500/20 text-fuchsia-700 dark:bg-fuchsia-400/25 dark:text-fuchsia-200',
]
function getAvatarColor(id, name) {
  let h = typeof id === 'number' ? id : 0
  const s = `${id ?? ''}-${name ?? ''}`
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length]
}

function isValidUsername(value) {
  return /^[A-Za-z0-9._]+$/.test(String(value || '').trim())
}

function isManagerialPosition(position) {
  const p = String(position || '').toLowerCase()
  return p.includes('manager') || p.includes('supervisor') || p.includes('lead') || p.includes('head')
}

function employeeInitials(employee) {
  const name = employee?.name || employee?.display_name || '?'
  return name
    .trim()
    .split(/\s+/)
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '?'
}

function companyInitials(name) {
  const normalized = String(name || '')
    .trim()
    .replace(/\s+/g, ' ')
  const words = normalized
    .split(' ')
    .filter(Boolean)
  if (words.length === 0) return 'CO'
  const firstWord = words[0].replace(/[^A-Za-z0-9]/g, '').toUpperCase()
  const acronymLikeFirstWord = /^[A-Z0-9]{3,8}$/.test(firstWord)
  if (acronymLikeFirstWord) return firstWord

  const ignoredWords = new Set([
    'A',
    'AN',
    'AND',
    'THE',
    'OF',
    'FOR',
    'IN',
    'ON',
    'AT',
    'BY',
    'CORP',
    'CORPORATION',
    'CO',
    'COMPANY',
    'INC',
    'INCORPORATED',
    'LTD',
    'LIMITED',
    'LLC',
  ])
  const meaningfulWords = words
    .map((word) => word.replace(/[^A-Za-z0-9]/g, '').toUpperCase())
    .filter((word) => word && !ignoredWords.has(word))
  if (meaningfulWords.length === 0) return firstWord || 'CO'
  if (meaningfulWords.length === 1) return meaningfulWords[0].slice(0, 8)
  return meaningfulWords.map((word) => word[0]).join('').slice(0, 8)
}

function safeDownloadName(value, fallback = 'employee-business-card') {
  return String(value || fallback)
    .trim()
    .replace(/[^a-z0-9-_]+/gi, '-')
    .replace(/^-+|-+$/g, '')
    || fallback
}

function firstPresentEmployeeValue(employee, keys) {
  for (const key of keys) {
    const value = employee?.[key]
    if (value !== null && value !== undefined && String(value).trim() !== '') return String(value).trim()
  }
  return ''
}

function loadCanvasImage(src) {
  return new Promise((resolve) => {
    if (!src) {
      resolve(null)
      return
    }
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = () => resolve(null)
    img.src = src
  })
}

function hexToRgb(hex) {
  const normalized = String(hex || '').replace('#', '').trim()
  if (!/^[0-9a-f]{6}$/i.test(normalized)) return { r: 0, g: 61, b: 165 }
  return {
    r: parseInt(normalized.slice(0, 2), 16),
    g: parseInt(normalized.slice(2, 4), 16),
    b: parseInt(normalized.slice(4, 6), 16),
  }
}

function rgbToHex({ r, g, b }) {
  return `#${[r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')}`
}

function mixRgb(a, b, weight = 0.5) {
  return {
    r: a.r * (1 - weight) + b.r * weight,
    g: a.g * (1 - weight) + b.g * weight,
    b: a.b * (1 - weight) + b.b * weight,
  }
}

function luminance({ r, g, b }) {
  return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
}

function themeFromPrimary(primaryHex) {
  const primary = hexToRgb(primaryHex)
  const dark = mixRgb(primary, { r: 0, g: 0, b: 0 }, luminance(primary) > 0.42 ? 0.38 : 0.18)
  const soft = mixRgb(primary, { r: 255, g: 255, b: 255 }, 0.9)
  const text = mixRgb(primary, { r: 15, g: 23, b: 42 }, 0.42)
  return {
    primary: rgbToHex(primary),
    primaryDark: rgbToHex(dark),
    primarySoft: rgbToHex(soft),
    text: rgbToHex(text),
    accent: BUSINESS_CARD_DEFAULT_THEME.accent,
  }
}

async function extractThemeFromLogo(logoUrl) {
  const img = await loadCanvasImage(logoUrl)
  if (!img) return BUSINESS_CARD_DEFAULT_THEME
  try {
    const size = 48
    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    ctx.drawImage(img, 0, 0, size, size)
    const { data } = ctx.getImageData(0, 0, size, size)
    const buckets = new Map()
    for (let i = 0; i < data.length; i += 4) {
      const alpha = data[i + 3]
      if (alpha < 80) continue
      const r = data[i]
      const g = data[i + 1]
      const b = data[i + 2]
      const max = Math.max(r, g, b)
      const min = Math.min(r, g, b)
      const saturation = max === 0 ? 0 : (max - min) / max
      const light = (r + g + b) / 3
      if (light > 238 || light < 25 || saturation < 0.18) continue
      const qr = Math.round(r / 24) * 24
      const qg = Math.round(g / 24) * 24
      const qb = Math.round(b / 24) * 24
      const key = `${qr},${qg},${qb}`
      const current = buckets.get(key) || { r: 0, g: 0, b: 0, weight: 0 }
      const weight = saturation * (1.2 - Math.abs(light - 128) / 180)
      buckets.set(key, {
        r: current.r + r * weight,
        g: current.g + g * weight,
        b: current.b + b * weight,
        weight: current.weight + weight,
      })
    }
    const dominant = [...buckets.values()].sort((a, b) => b.weight - a.weight)[0]
    if (!dominant || dominant.weight <= 0) return BUSINESS_CARD_DEFAULT_THEME
    return themeFromPrimary(rgbToHex({
      r: dominant.r / dominant.weight,
      g: dominant.g / dominant.weight,
      b: dominant.b / dominant.weight,
    }))
  } catch {
    return BUSINESS_CARD_DEFAULT_THEME
  }
}

function roundedRectPath(ctx, x, y, width, height, radius) {
  const r = Math.min(radius, width / 2, height / 2)
  ctx.beginPath()
  ctx.moveTo(x + r, y)
  ctx.arcTo(x + width, y, x + width, y + height, r)
  ctx.arcTo(x + width, y + height, x, y + height, r)
  ctx.arcTo(x, y + height, x, y, r)
  ctx.arcTo(x, y, x + width, y, r)
  ctx.closePath()
}

function drawContainedImage(ctx, img, x, y, width, height) {
  if (!img) return
  const ratio = Math.min(width / img.width, height / img.height)
  const drawWidth = img.width * ratio
  const drawHeight = img.height * ratio
  ctx.drawImage(img, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight)
}

function drawCanvasText(ctx, text, x, y, maxWidth, lineHeight, maxLines = 2) {
  const value = String(text || '').trim()
  if (!value) return y
  const words = value.split(/\s+/)
  let line = ''
  let lines = []
  words.forEach((word) => {
    if (ctx.measureText(word).width > maxWidth) {
      if (line) {
        lines.push(line)
        line = ''
      }
      let chunk = ''
      for (const char of word) {
        if (chunk && ctx.measureText(`${chunk}${char}`).width > maxWidth) {
          lines.push(chunk)
          chunk = char
        } else {
          chunk += char
        }
      }
      line = chunk
      return
    }

    const testLine = line ? `${line} ${word}` : word
    if (ctx.measureText(testLine).width <= maxWidth || !line) {
      line = testLine
    } else {
      lines.push(line)
      line = word
    }
  })
  if (line) lines.push(line)
  lines = lines.slice(0, maxLines)
  if (lines.length === maxLines && words.length > 0 && ctx.measureText(value).width > maxWidth) {
    let last = lines[lines.length - 1]
    while (last.length > 1 && ctx.measureText(`${last}...`).width > maxWidth) {
      last = last.slice(0, -1)
    }
    lines[lines.length - 1] = `${last}...`
  }
  lines.forEach((row, index) => ctx.fillText(row, x, y + index * lineHeight))
  return y + lines.length * lineHeight
}

function fitCanvasFont(ctx, text, { maxWidth, fontWeight = 800, maxSize = 48, minSize = 24, family = 'Inter, Arial, sans-serif' }) {
  const value = String(text || '').trim()
  for (let size = maxSize; size >= minSize; size -= 1) {
    ctx.font = `${fontWeight} ${size}px ${family}`
    if (ctx.measureText(value).width <= maxWidth) return size
  }
  return minSize
}

export default function AdminEmployees() {
  const { toast } = useToast()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const perms = new Set(user?.permissions ?? [])
  const roleValue = String(user?.role || '').trim().toLowerCase()
  const hrRoleValue = String(user?.hr_role || '').trim().toLowerCase()
  const isAdminOrHr = roleValue === 'admin' || roleValue === 'super_admin' || hrRoleValue === 'admin_hr' || hrRoleValue === 'admin'
  const canCreateEmployees = perms.has('employees.create')
  const canExportEmployees = perms.has('employees.export')
  const canEditEmployees = perms.has('employees.edit')
  const canDeleteEmployees = perms.has('employees.delete')
  const canAssignSchedule = perms.has('schedule.assign')
  const canManageSchedules = perms.has('schedule.manage') || perms.has('manage-schedules')
  const canPasswordReset = perms.has('employees.password_reset')
  const canScopedEditEmployees = canEditEmployees && isAdminOrHr
  const canDeleteEmployeeTarget = (emp) => canDeleteEmployees && isAdminOrHr && Number(emp?.id) !== Number(user?.id)
  const canEditEmployeeTarget = (emp) =>
    canEditEmployees && (isAdminOrHr || Number(emp?.id) === Number(user?.id))
  const canMutateRows =
    canEditEmployees || canAssignSchedule || canDeleteEmployees || canPasswordReset
  const location = useLocation()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const [searchParams, setSearchParams] = useSearchParams()
  const [employees, setEmployees] = useState([])
  const [error, setError] = useState(null)
  const [exportingCsv, setExportingCsv] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState({ total: 0, perPage: 20, lastPage: 1 })
  const didInitialEmployeeLoadRef = useRef(false)
  const [filterStatus, setFilterStatus] = useState('active')
  const [filterCompany, setFilterCompany] = useState('')
  const [filterBranch, setFilterBranch] = useState(() => searchParams.get('branch_id') || '')
  const [filterLevel, setFilterLevel] = useState('')
  const [filterSchedule, setFilterSchedule] = useState('')
  const [filterFace, setFilterFace] = useState('')
  const [sortBy, setSortBy] = useState('')
  const [sortDir, setSortDir] = useState('asc')
  const [density, setDensity] = useState('comfortable') // 'compact' | 'comfortable'

  const [addOpen, setAddOpen] = useState(false)

  const [qrOpen, setQrOpen] = useState(false)
  const [qrEmployee, setQrEmployee] = useState(null)
  const [qrLoading, setQrLoading] = useState(false)
  const [qrToken, setQrToken] = useState('')
  const [qrCompanyLogoUrl, setQrCompanyLogoUrl] = useState(null)
  const qrCanvasRef = useRef(null)
  const [pendingQrDownload, setPendingQrDownload] = useState(null)
  const hiddenQrRef = useRef(null)
  const [businessCardOpen, setBusinessCardOpen] = useState(false)
  const [businessCardEmployee, setBusinessCardEmployee] = useState(null)
  const [businessCardDownloading, setBusinessCardDownloading] = useState(false)
  const [businessCardTheme, setBusinessCardTheme] = useState(BUSINESS_CARD_DEFAULT_THEME)

  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduleEmployee, setScheduleEmployee] = useState(null)
  const [adjustmentOpen, setAdjustmentOpen] = useState(false)
  const [adjustmentEmployeeIds, setAdjustmentEmployeeIds] = useState([])

  const [togglingId, setTogglingId] = useState(null)
  const [deactivateOpen, setDeactivateOpen] = useState(false)
  const [deactivateEmployee, setDeactivateEmployee] = useState(null)
  const [resetOpen, setResetOpen] = useState(false)
  const [resetEmployee, setResetEmployee] = useState(null)
  const [resetPasswordValue, setResetPasswordValue] = useState('')
  const [resetSubmitting, setResetSubmitting] = useState(false)
  const [viewPasswordOpen, setViewPasswordOpen] = useState(false)
  const [viewPasswordEmployee, setViewPasswordEmployee] = useState(null)
  const [viewPasswordValue, setViewPasswordValue] = useState('')
  const [viewPasswordSource, setViewPasswordSource] = useState(null)
  const [viewPasswordLoading, setViewPasswordLoading] = useState(false)
  const [viewPasswordError, setViewPasswordError] = useState(null)
  const [viewPasswordCopied, setViewPasswordCopied] = useState(false)
  const [clearQrConfirmEmployee, setClearQrConfirmEmployee] = useState(null)
  const [clearQrSubmitting, setClearQrSubmitting] = useState(false)

  const [deleteConfirmEmployee, setDeleteConfirmEmployee] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const urlQ = searchParams.get('q') || ''
  const [searchQuery, setSearchQuery] = useState(() => urlQ)
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState(() => urlQ.trim())
  const urlUpdateByUsRef = useRef(false)

  const [searchModalOpen, setSearchModalOpen] = useState(false)
  const [searchModalQuery, setSearchModalQuery] = useState('')
  const searchModalInputRef = useRef(null)

  const [selectedIds, setSelectedIds] = useState([])
  const [bulkSubmitting, setBulkSubmitting] = useState(false)
  const [bulkScheduleIds, setBulkScheduleIds] = useState([])

  const [previewOpen, setPreviewOpen] = useState(false)
  const [previewEmployee, setPreviewEmployee] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewSummary, setPreviewSummary] = useState(null)
  const [personalInfoForm, setPersonalInfoForm] = useState({
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    username: '',
    employee_code: '',
    email: '',
    phone_number: '',
    date_of_birth: '',
    gender: '',
    civil_status: '',
    nationality: '',
    home_address: '',
    branch_id: '',
    department_id: '',
    position: '',
    branch_office_location: '',
    employment_type: '',
    hire_date: '',
    payroll_effective_date: '',
    supervisor_id: '',
    working_schedule_id: '',
  })
  const [profileDetailsSaving, setProfileDetailsSaving] = useState(false)
  const [profilePhotoUploading, setProfilePhotoUploading] = useState(false)
  const profilePhotoInputRef = useRef(null)

  const [departments, setDepartments] = useState([])
  const [departmentsLoading, setDepartmentsLoading] = useState(false)
  const [branches, setBranches] = useState([])
  const [companies, setCompanies] = useState([])
  const [workingSchedules, setWorkingSchedules] = useState([])
  const [activeEmployeeId, setActiveEmployeeId] = useState(null)
  const [regenerateConfirmEmployee, setRegenerateConfirmEmployee] = useState(null)
  const [removeFaceConfirmEmployee, setRemoveFaceConfirmEmployee] = useState(null)
  const [faceRemoveSubmitting, setFaceRemoveSubmitting] = useState(false)

  const [faceRegisterOpen, setFaceRegisterOpen] = useState(false)
  const [faceRegisterEmployee, setFaceRegisterEmployee] = useState(null)
  const [faceRegisterSubmitting, setFaceRegisterSubmitting] = useState(false)
  const [faceRegisterError, setFaceRegisterError] = useState(null)
  const [faceRegisterErrorCode, setFaceRegisterErrorCode] = useState(null)
  const [faceRegisterRetryKey, setFaceRegisterRetryKey] = useState(0)
  const [changeFaceConfirmEmployee, setChangeFaceConfirmEmployee] = useState(null)

  const [viewFaceOpen, setViewFaceOpen] = useState(false)
  const [viewFaceEmployee, setViewFaceEmployee] = useState(null)
  const [viewFaceImage, setViewFaceImage] = useState(null)
  const [viewFaceMessage, setViewFaceMessage] = useState(null)
  const [viewFaceLoading, setViewFaceLoading] = useState(false)

  const [manageFaceOpen, setManageFaceOpen] = useState(false)
  const [manageFaceEmployee, setManageFaceEmployee] = useState(null)
  const [editEmployeeCodesOpen, setEditEmployeeCodesOpen] = useState(false)
  const [editEmployeeCodesDrafts, setEditEmployeeCodesDrafts] = useState({})
  const [editEmployeeCodesErrors, setEditEmployeeCodesErrors] = useState({})
  const [editEmployeeCodesChecking, setEditEmployeeCodesChecking] = useState({})
  const [editEmployeeCodesSaving, setEditEmployeeCodesSaving] = useState(false)
  const listPerPage = 20
  const listPage = page

  const companyById = useMemo(() => {
    const byId = new Map()
    companies.forEach((company) => {
      if (company?.id !== null && company?.id !== undefined) byId.set(String(company.id), company)
    })
    return byId
  }, [companies])

  const getEmployeeCompanyLogoUrl = useCallback((emp) => {
    const direct = companyLogoUrl(emp?.company_logo_url || emp?.logo_url)
    if (direct) return direct
    const company = emp?.company_id !== null && emp?.company_id !== undefined
      ? companyById.get(String(emp.company_id))
      : null
    return companyLogoUrl(company)
  }, [companyById])

  const getBusinessCardDetails = useCallback((emp) => {
    if (!emp) return null
    const companyName = emp.company_name || 'Company not assigned'
    return {
      name: emp.name || emp.display_name || 'Employee',
      employeeCode: composeEmployeeCode(emp.employee_code) || emp.username || '',
      position: emp.position || 'Position not set',
      email: emp.email || '',
      phone: emp.phone_number || '',
      companyName,
      department: emp.department_name || emp.department || '',
      branch: emp.branch_name || '',
      hireDate: emp.hire_date || '',
      employmentStatus: formatEmploymentStatusForViewer(emp.employment_status, emp.employment_status_label, false) || '',
      level: emp.employee_level_label || '',
      currentOrgPath: emp.current_org_path || '',
      initials: employeeInitials(emp),
      logoUrl: getEmployeeCompanyLogoUrl(emp),
      avatarUrl: profileImageUrl(firstPresentEmployeeValue(emp, ['profile_image', 'profile_image_url', 'avatar_url'])),
    }
  }, [getEmployeeCompanyLogoUrl])

  const openBusinessCard = useCallback((emp) => {
    setBusinessCardEmployee(emp)
    setBusinessCardOpen(true)
  }, [])

  const closeBusinessCard = useCallback(() => {
    setBusinessCardOpen(false)
    setBusinessCardEmployee(null)
    setBusinessCardDownloading(false)
    setBusinessCardTheme(BUSINESS_CARD_DEFAULT_THEME)
  }, [])

  useEffect(() => {
    let cancelled = false
    if (!businessCardOpen || !businessCardEmployee) {
      setBusinessCardTheme(BUSINESS_CARD_DEFAULT_THEME)
      return () => {
        cancelled = true
      }
    }

    const logoUrl = getEmployeeCompanyLogoUrl(businessCardEmployee)
    extractThemeFromLogo(logoUrl).then((theme) => {
      if (!cancelled) setBusinessCardTheme(theme)
    })
    return () => {
      cancelled = true
    }
  }, [businessCardOpen, businessCardEmployee, getEmployeeCompanyLogoUrl])

  useEffect(() => {
    setPage(1)
  }, [filterStatus, filterCompany, filterBranch, filterLevel, filterSchedule, filterFace])

  useEffect(() => {
    if (location.pathname === hrPanelPath(hrBase, 'employees/add')) {
      setAddOpen(true)
    }
  }, [location.pathname, hrBase])

  const employeesQuery = useQuery({
    queryKey: ['admin-employees-list', {
      page: listPage,
      perPage: listPerPage,
      q: debouncedSearchQuery,
      activeFilter: filterStatus,
      companyId: filterCompany,
      branchId: filterBranch,
      employeeLevel: filterLevel,
      scheduleFilter: filterSchedule,
      faceFilter: filterFace,
    }],
    queryFn: () =>
      getEmployees({
        lite: true,
        page: listPage,
        per_page: listPerPage,
        q: debouncedSearchQuery || undefined,
        active_filter: filterStatus || 'active',
        company_id: filterCompany || undefined,
        branch_id: filterBranch || undefined,
        employee_level: filterLevel || undefined,
        schedule_filter: filterSchedule || undefined,
        face_filter: filterFace || undefined,
      }),
    staleTime: 60 * 1000,
    gcTime: 2 * 60 * 1000,
    refetchOnWindowFocus: false,
  })
  const refetchEmployeesQuery = employeesQuery.refetch

  const fetchEmployees = useCallback(async (pageToLoad) => {
    const targetPage = pageToLoad ?? page ?? 1
    setError(null)
    if (targetPage !== page) {
      setPage(targetPage)
      return
    }
    await refetchEmployeesQuery()
  }, [page, refetchEmployeesQuery])

  // Keep URL ?q= in sync (so header global search can deep-link here)
  useEffect(() => {
    const q = searchQuery.trim()
    const current = urlQ
    if ((q || '') === (current || '')) return
    urlUpdateByUsRef.current = true
    const next = new URLSearchParams(searchParams)
    if (q) next.set('q', q)
    else next.delete('q')
    setSearchParams(next, { replace: true })
  }, [searchQuery, searchParams, setSearchParams, urlQ])

  // Keep URL ?branch_id= in sync for deep links from Branches / org modules.
  useEffect(() => {
    const current = searchParams.get('branch_id') || ''
    if ((filterBranch || '') === current) return
    const next = new URLSearchParams(searchParams)
    if (filterBranch) next.set('branch_id', filterBranch)
    else next.delete('branch_id')
    setSearchParams(next, { replace: true })
  }, [filterBranch, searchParams, setSearchParams])

  useEffect(() => {
    const urlBranch = searchParams.get('branch_id') || ''
    if (urlBranch !== filterBranch) {
      setFilterBranch(urlBranch)
    }
  }, [searchParams]) // eslint-disable-line react-hooks/exhaustive-deps -- sync external URL changes only

  // When URL changes from outside (e.g. back button, global search link), sync to input.
  // Do not overwrite searchQuery when we just updated the URL ourselves (avoids corrupting input while typing).
  useEffect(() => {
    if (urlQ === searchQuery) {
      urlUpdateByUsRef.current = false
      return
    }
    if (urlUpdateByUsRef.current) return
    setSearchQuery(urlQ)
  }, [urlQ, searchQuery])

  useEffect(() => {
    const q = searchQuery.trim()
    if (q && page !== 1) {
      setPage(1)
      return
    }
    const delay = q ? 250 : 0
    const t = setTimeout(() => {
      setDebouncedSearchQuery(q)
    }, delay)
    return () => clearTimeout(t)
  }, [searchQuery, page])

  useEffect(() => {
    setDepartmentsLoading(true)
    Promise.all([getDepartments(), getBranches(), getCompanies()])
      .then(([deptData, branchData, companyData]) => {
        setDepartments(Array.isArray(deptData.departments) ? deptData.departments : [])
        setBranches(Array.isArray(branchData.branches) ? branchData.branches : [])
        setCompanies(Array.isArray(companyData.companies) ? companyData.companies : [])
      })
      .catch(() => {})
      .finally(() => {
        setDepartmentsLoading(false)
      })
  }, [])

  // When navigating away to an employee profile (and coming back to the list),
  // the component may stay mounted. Refetch to ensure email and leave credits
  // are not displayed from an outdated snapshot.
  useEffect(() => {
    const listPath = hrPanelPath(hrBase, 'employees')
    if (location.pathname !== listPath) return
    if (!didInitialEmployeeLoadRef.current) {
      didInitialEmployeeLoadRef.current = true
      return
    }
    fetchEmployees(page)
  }, [location.pathname, hrBase, fetchEmployees, page])

  useEffect(() => {
    if (employeesQuery.data) {
      const data = employeesQuery.data
      const list = Array.isArray(data?.employees) ? data.employees : []
      setEmployees(list.map(normalizeEmployeeFlags))
      setSelectedIds([])
      setBulkScheduleIds([])
      const meta = data?.meta || {}
      const total = typeof meta.total === 'number' ? meta.total : list.length
      const perPage = typeof meta.per_page === 'number' ? meta.per_page : list.length || 20
      const lastPage = typeof meta.last_page === 'number' ? meta.last_page : 1
      setPagination({ total, perPage, lastPage })
      setError(null)
      return
    }
    if (employeesQuery.error) {
      setEmployees([])
      setSelectedIds([])
      setBulkScheduleIds([])
      setPagination({ total: 0, perPage: 20, lastPage: 1 })
      setError(employeesQuery.error?.message || 'Failed to load employees')
    }
  }, [employeesQuery.data, employeesQuery.error])

  const workingScheduleNameById = (() => {
    const map = new Map()
    for (const s of workingSchedules) {
      if (s?.id !== undefined && s?.id !== null) {
        map.set(String(s.id), s.name || `Schedule #${s.id}`)
      }
    }
    return map
  })()

  const getScheduleLabel = (emp) => {
    if (emp?.schedule && hasWorkingDays(emp.schedule)) return formatSchedule(emp.schedule)
    if (emp?.working_schedule_id !== null && emp?.working_schedule_id !== undefined && emp?.working_schedule_id !== '') {
      return workingScheduleNameById.get(String(emp.working_schedule_id)) || `Schedule #${emp.working_schedule_id}`
    }
    return 'Not set'
  }

  const savePersonalInfo = async () => {
    if (!previewEmployee || !canEditEmployeeTarget(previewEmployee)) return
    const phoneRaw = personalInfoForm.phone_number.trim().replace(/[^\d+\s]/g, '')
    if (!personalInfoForm.first_name.trim() || !personalInfoForm.last_name.trim()) {
      setError('First Name and Last Name are required.')
      return
    }
    if (!personalInfoForm.email.trim()) {
      setError('Email Address is required.')
      return
    }
    if (!personalInfoForm.username.trim()) {
      setError('Username is required.')
      return
    }
    if (!isValidUsername(personalInfoForm.username)) {
      setError('Username can only contain letters, numbers, underscores, and dots (no spaces).')
      return
    }
    if (!phoneRaw) {
      setError('Contact Number is required.')
      return
    }
    if (!/^(\+63\s?9\d{9}|09\d{9})$/.test(phoneRaw)) {
      setError('Enter a valid Philippine mobile number (e.g. +63 912 345 6789 or 09123456789).')
      return
    }
    setProfileDetailsSaving(true)
    setError(null)
    try {
      const validSupervisorOptions = getSupervisorCandidatesByCompany(
        personalInfoForm.department_id,
        previewEmployee?.id
      )
      const validSupervisorIds = new Set(validSupervisorOptions.map((s) => String(s.id)))
      const normalizedSupervisorId =
        personalInfoForm.supervisor_id && validSupervisorIds.has(String(personalInfoForm.supervisor_id))
          ? personalInfoForm.supervisor_id
          : ''

      const data = await updateEmployee(previewEmployee.id, {
        first_name: personalInfoForm.first_name.trim(),
        middle_name: personalInfoForm.middle_name.trim() || null,
        last_name: personalInfoForm.last_name.trim(),
        suffix: personalInfoForm.suffix.trim() || null,
        username: personalInfoForm.username.trim(),
        employee_code: composeEmployeeCode(personalInfoForm.employee_code) || null,
        email: personalInfoForm.email.trim(),
        phone_number: phoneRaw || null,
        date_of_birth: personalInfoForm.date_of_birth || null,
        gender: personalInfoForm.gender || null,
        civil_status: personalInfoForm.civil_status || null,
        nationality: personalInfoForm.nationality.trim() || null,
        home_address: personalInfoForm.home_address.trim() || null,
        branch_id: personalInfoForm.branch_id || null,
        department_id: personalInfoForm.department_id || null,
        position: personalInfoForm.position.trim() || null,
        branch_office_location: personalInfoForm.branch_office_location.trim() || null,
        employment_type: personalInfoForm.employment_type || null,
        hire_date: personalInfoForm.hire_date || null,
        payroll_effective_date: personalInfoForm.payroll_effective_date || null,
        supervisor_id: normalizedSupervisorId || null,
        working_schedule_id: personalInfoForm.working_schedule_id || null,
      })
      const emp = normalizeEmployeeFlags(data.employee)
      setEmployees((prev) => prev.map((e) => (e.id === previewEmployee.id ? normalizeEmployeeFlags({ ...e, ...emp }) : e)))
      setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? normalizeEmployeeFlags({ ...p, ...emp }) : p))
      setPersonalInfoForm({
        first_name: emp?.first_name || '',
        middle_name: emp?.middle_name || '',
        last_name: emp?.last_name || '',
        suffix: emp?.suffix || '',
        username: emp?.username || '',
        employee_code: emp?.employee_code || emp?.employee_id || '',
        email: emp?.email || '',
        phone_number: emp?.phone_number || '',
        date_of_birth: emp?.date_of_birth || '',
        gender: emp?.gender || '',
        civil_status: emp?.civil_status || '',
        nationality: emp?.nationality || '',
        home_address: emp?.home_address || '',
        branch_id: emp?.branch_id ?? '',
        department_id: emp?.department_id ?? '',
        position: emp?.position || '',
        branch_office_location: emp?.branch_office_location || '',
        employment_type: emp?.employment_type || '',
        hire_date: emp?.hire_date || '',
        payroll_effective_date: emp?.payroll_effective_date || '',
        supervisor_id: emp?.supervisor_id ?? '',
        working_schedule_id: emp?.working_schedule_id ?? '',
      })
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees(page)
      toast({
        title: 'Changes saved',
        description: `${emp?.name || previewEmployee?.name || 'Employee'} profile was updated successfully.`,
        variant: 'success',
      })
    } catch (e) {
      setError(e.message)
      toast({
        title: 'Failed to save changes',
        description: e.message || 'Unable to update employee profile.',
        variant: 'error',
      })
    } finally {
      setProfileDetailsSaving(false)
    }
  }

  useEffect(() => {
    getWorkingSchedules()
      .then((data) => {
        setWorkingSchedules(Array.isArray(data.schedules) ? data.schedules : [])
      })
      .catch(() => {
        setWorkingSchedules([])
      })
  }, [])

  useEffect(() => {
    const handleKeyDown = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        setSearchModalOpen(true)
        setSearchModalQuery('')
        setTimeout(() => searchModalInputRef.current?.focus(), 50)
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [])

  const closeQr = useCallback(() => {
    setQrOpen(false)
    setQrEmployee(null)
    setQrToken('')
    setQrCompanyLogoUrl(null)
    setQrLoading(false)
  }, [])

  const showQr = useCallback(async (emp) => {
    setError(null)
    setQrEmployee(emp)
    setQrOpen(true)
    setQrLoading(true)
    setQrToken('')
    setQrCompanyLogoUrl(null)
    try {
      const data = await getEmployeeQr(emp.id)
      setQrToken(data.qr_token || '')
      setQrCompanyLogoUrl(companyLogoUrl(data.company_logo_url) || null)
    } catch (e) {
      setError(e.message)
      closeQr()
    } finally {
      setQrLoading(false)
    }
  }, [closeQr])

  const generateOrRegenerateQr = useCallback(async (emp) => {
    setError(null)
    setQrEmployee(emp)
    setQrOpen(true)
    setQrLoading(true)
    setQrToken('')
    setQrCompanyLogoUrl(null)
    try {
      const data = await regenerateEmployeeQr(emp.id)
      setQrToken(data.qr_token || '')
      setQrCompanyLogoUrl(companyLogoUrl(data.company_logo_url) || null)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
    } catch (e) {
      setError(e.message)
      closeQr()
    } finally {
      setQrLoading(false)
    }
  }, [closeQr, fetchEmployees])

  const removeQr = useCallback(async (emp) => {
    setError(null)
    setClearQrSubmitting(true)
    try {
      await clearEmployeeQr(emp.id)
      setClearQrConfirmEmployee(null)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
    } catch (e) {
      setError(e.message)
    } finally {
      setClearQrSubmitting(false)
    }
  }, [fetchEmployees])

  const downloadQrFromCanvas = useCallback((fileName, format = 'png') => {
    const container = qrCanvasRef.current
    const canvas = container?.querySelector('canvas')
    if (!canvas) return
    const safeName = (fileName || 'qr-code').replace(/[^a-z0-9-_]/gi, '-')
    if (format === 'png') {
      const url = canvas.toDataURL('image/png')
      const a = document.createElement('a')
      a.href = url
      a.download = `${safeName}.png`
      a.click()
    }
  }, [])

  const handleDownloadQrFromTable = useCallback(async (emp) => {
    setError(null)
    try {
      const data = await getEmployeeQr(emp.id)
      const token = data.qr_token || ''
      if (!token) return
      setPendingQrDownload({
        token,
        fileName: (emp.name || 'employee').replace(/[^a-z0-9-_]/gi, '-'),
        companyLogoUrl: companyLogoUrl(data.company_logo_url) || null,
      })
    } catch (e) {
      setError(e.message)
    }
  }, [])

  useEffect(() => {
    if (!pendingQrDownload) return
    const timer = setTimeout(() => {
      const container = hiddenQrRef.current
      const canvas = container?.querySelector('canvas')
      if (canvas) {
        const url = canvas.toDataURL('image/png')
        const a = document.createElement('a')
        a.href = url
        a.download = `${pendingQrDownload.fileName}.png`
        a.click()
      }
      setPendingQrDownload(null)
    }, 150)
    return () => clearTimeout(timer)
  }, [pendingQrDownload])

  const downloadBusinessCard = useCallback(async (emp = businessCardEmployee) => {
    const details = getBusinessCardDetails(emp)
    if (!details || businessCardDownloading) return
    setBusinessCardDownloading(true)
    try {
      const theme = await extractThemeFromLogo(details.logoUrl)
      const scale = 2
      const width = 980
      const height = 1090
      const canvas = document.createElement('canvas')
      canvas.width = width * scale
      canvas.height = height * scale
      const ctx = canvas.getContext('2d')
      ctx.scale(scale, scale)

      const logo = await loadCanvasImage(details.logoUrl)
      const avatar = await loadCanvasImage(details.avatarUrl)
      const buildingBg = await loadCanvasImage(BUSINESS_CARD_BUILDINGS_BG)

      ctx.clearRect(0, 0, width, height)
      ctx.fillStyle = '#ffffff'
      roundedRectPath(ctx, 10, 10, width - 20, height - 20, 22)
      ctx.fill()

      ctx.save()
      roundedRectPath(ctx, 10, 10, width - 20, 272, 22)
      ctx.clip()
      const headerBg = ctx.createLinearGradient(10, 10, width, 290)
      headerBg.addColorStop(0, theme.primaryDark)
      headerBg.addColorStop(0.55, theme.primary)
      headerBg.addColorStop(1, '#67a6e8')
      ctx.fillStyle = headerBg
      ctx.fillRect(10, 10, width - 20, 300)
      if (buildingBg) {
        const imgRatio = buildingBg.width / buildingBg.height
        const drawHeight = 300
        const drawWidth = drawHeight * imgRatio
        ctx.drawImage(buildingBg, width - drawWidth - 10, 10, drawWidth, drawHeight)
      }
      const tintRgb = hexToRgb(theme.primary)
      ctx.fillStyle = `rgba(${tintRgb.r},${tintRgb.g},${tintRgb.b},0.28)`
      ctx.fillRect(10, 10, width - 20, 300)
      const overlay = ctx.createLinearGradient(10, 10, width, 10)
      const primary = hexToRgb(theme.primary)
      const primaryDark = hexToRgb(theme.primaryDark)
      overlay.addColorStop(0, `rgba(${primaryDark.r},${primaryDark.g},${primaryDark.b},0.98)`)
      overlay.addColorStop(0.42, `rgba(${primary.r},${primary.g},${primary.b},0.72)`)
      overlay.addColorStop(0.72, `rgba(${primary.r},${primary.g},${primary.b},0.16)`)
      overlay.addColorStop(1, 'rgba(0,0,0,0.05)')
      ctx.fillStyle = overlay
      ctx.fillRect(10, 10, width - 20, 300)

      ctx.fillStyle = '#ffffff'
      roundedRectPath(ctx, 54, 70, 124, 124, 22)
      ctx.fill()
      if (logo) {
        drawContainedImage(ctx, logo, 70, 86, 92, 92)
      } else {
        ctx.fillStyle = theme.primary
        ctx.font = '800 34px Inter, Arial, sans-serif'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillText(companyInitials(details.companyName), 116, 132)
      }

      ctx.strokeStyle = 'rgba(120,186,255,0.75)'
      ctx.lineWidth = 2
      ctx.beginPath()
      ctx.moveTo(204, 86)
      ctx.lineTo(204, 176)
      ctx.stroke()

      ctx.textAlign = 'left'
      ctx.textBaseline = 'alphabetic'
      ctx.fillStyle = '#ffffff'
      const companyAcronym = companyInitials(details.companyName)
      ctx.font = `800 ${fitCanvasFont(ctx, companyAcronym, { maxWidth: 220, maxSize: 48, minSize: 30 })}px Inter, Arial, sans-serif`
      ctx.fillText(companyAcronym, 230, 132)
      ctx.font = '500 28px Inter, Arial, sans-serif'
      drawCanvasText(ctx, (details.branch || 'Branch not set').toUpperCase(), 230, 176, 250, 32, 1)
      ctx.restore()

      ctx.save()
      roundedRectPath(ctx, 400, 180, 180, 180, 90)
      ctx.clip()
      ctx.fillStyle = theme.primary
      ctx.fillRect(400, 180, 180, 180)
      if (avatar) {
        const side = Math.min(avatar.width, avatar.height)
        ctx.drawImage(avatar, (avatar.width - side) / 2, (avatar.height - side) / 2, side, side, 400, 180, 180, 180)
      } else {
        ctx.fillStyle = '#ffffff'
        ctx.font = '800 58px Inter, Arial, sans-serif'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillText(details.initials, 490, 270)
      }
      ctx.restore()

      ctx.strokeStyle = '#ffffff'
      ctx.lineWidth = 10
      roundedRectPath(ctx, 400, 180, 180, 180, 90)
      ctx.stroke()
      ctx.strokeStyle = 'rgba(15,23,42,0.12)'
      ctx.lineWidth = 2
      roundedRectPath(ctx, 400, 180, 180, 180, 90)
      ctx.stroke()

      ctx.textAlign = 'center'
      ctx.textBaseline = 'alphabetic'
      ctx.fillStyle = '#050a14'
      const nameSize = fitCanvasFont(ctx, details.name, { maxWidth: width - 220, maxSize: 48, minSize: 30 })
      ctx.font = `800 ${nameSize}px Inter, Arial, sans-serif`
      drawCanvasText(ctx, details.name, 110, 452, width - 220, nameSize + 8, 2)
      const positionSize = fitCanvasFont(ctx, details.position.toUpperCase(), { maxWidth: width - 300, fontWeight: 500, maxSize: 28, minSize: 18 })
      ctx.font = `500 ${positionSize}px Inter, Arial, sans-serif`
      ctx.fillStyle = theme.primary
      drawCanvasText(ctx, details.position.toUpperCase(), 150, 515, width - 300, positionSize + 6, 1)

      ctx.fillStyle = theme.primary
      roundedRectPath(ctx, 421, 552, 112, 4, 2)
      ctx.fill()
      ctx.fillStyle = theme.accent
      roundedRectPath(ctx, 532, 552, 34, 4, 2)
      ctx.fill()

      const rows = [
        ['Employee ID', details.employeeCode || 'Not set'],
        ['Position', details.position || 'Not set'],
        ['Work Email', details.email || 'Not set'],
        ['Contact', details.phone || 'Not set'],
        ['Branch', details.branch || 'Not set'],
      ]
      ctx.strokeStyle = '#d8dee8'
      ctx.lineWidth = 1
      ctx.beginPath()
      ctx.moveTo(56, 620)
      ctx.lineTo(56, 978)
      ctx.stroke()
      ctx.strokeStyle = theme.primary
      ctx.lineWidth = 4
      ctx.beginPath()
      ctx.moveTo(56, 620)
      ctx.lineTo(56, 678)
      ctx.stroke()

      let y = 646
      const rowLeft = 150
      const rowValueLeft = 372
      const rowRight = 918
      ctx.textAlign = 'left'
      rows.forEach(([label, value], index) => {
        ctx.fillStyle = theme.text
        ctx.font = '600 22px Inter, Arial, sans-serif'
        ctx.fillText(label.toUpperCase(), rowLeft, y)
        ctx.fillStyle = '#050a14'
        const valueSize = fitCanvasFont(ctx, value, { maxWidth: rowRight - rowValueLeft, fontWeight: 500, maxSize: 24, minSize: 18 })
        ctx.font = `500 ${valueSize}px Inter, Arial, sans-serif`
        const nextTextY = drawCanvasText(ctx, value, rowValueLeft, y, rowRight - rowValueLeft, valueSize + 8, 2)
        const rowHeight = Math.max(78, nextTextY - y + 42)
        if (index < rows.length - 1) {
          ctx.strokeStyle = '#d8dee8'
          ctx.lineWidth = 1
          ctx.beginPath()
          ctx.moveTo(108, y + rowHeight - 24)
          ctx.lineTo(rowRight, y + rowHeight - 24)
          ctx.stroke()
        }
        y += rowHeight
      })

      ctx.strokeStyle = '#d8dee8'
      ctx.lineWidth = 1
      ctx.beginPath()
      ctx.moveTo(10, 990)
      ctx.lineTo(width - 10, 990)
      ctx.stroke()

      const a = document.createElement('a')
      a.href = canvas.toDataURL('image/png')
      a.download = `${safeDownloadName(details.name)}-business-card.png`
      a.click()
      toast({ title: 'Business card downloaded', description: details.name, variant: 'success' })
    } catch (e) {
      toast({
        title: 'Failed to download business card',
        description: e?.message || 'Unable to render the employee business card.',
        variant: 'error',
      })
    } finally {
      setBusinessCardDownloading(false)
    }
  }, [businessCardEmployee, businessCardDownloading, getBusinessCardDetails, toast])

  const openSchedule = (emp) => {
    setScheduleEmployee(emp)
    setBulkScheduleIds([])
    setScheduleOpen(true)
  }

  const handleDeleteEmployee = async () => {
    if (!deleteConfirmEmployee) return
    setDeleteSubmitting(true)
    setError(null)
    try {
      await deleteEmployee(deleteConfirmEmployee.id)
      setDeleteConfirmEmployee(null)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
      toast({ title: 'Employee deleted', description: deleteConfirmEmployee.name, variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to delete employee', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const normalizedSearchQuery = useMemo(() => searchQuery.trim().toLowerCase(), [searchQuery])

  const filteredEmployees = useMemo(() => {
    let list = employees.filter((emp) => {
      if (normalizedSearchQuery) {
        const haystack = `${emp.name || ''} ${emp.email || ''} ${emp.employee_code || ''} ${emp.department || ''} ${emp.position || ''}`.toLowerCase()
        if (!haystack.includes(normalizedSearchQuery)) return false
      }
      if (filterStatus === 'active' && !emp.is_active) return false
      if (filterStatus === 'deactivated' && emp.is_active) return false
      if (filterLevel && String(emp.employee_level ?? '') !== String(filterLevel)) return false
      const hasSchedule = hasAssignedSchedule(emp)
      if (filterSchedule === 'scheduled' && !hasSchedule) return false
      if (filterSchedule === 'unscheduled' && hasSchedule) return false
      if (filterFace === 'registered' && !emp.has_face) return false
      if (filterFace === 'unregistered' && emp.has_face) return false
      return true
    })
    if (!sortBy) return list

    const dir = sortDir === 'asc' ? 1 : -1
    return [...list].sort((a, b) => {
      let va, vb
      switch (sortBy) {
        case 'name':
          return dir * compareEmployeesByLastName(a, b)
        case 'employee_code': {
          va = composeEmployeeCode(a.employee_code) || ''
          vb = composeEmployeeCode(b.employee_code) || ''
          return dir * va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' })
        }
        case 'company_name':
          va = (a.company_name || '').toLowerCase()
          vb = (b.company_name || '').toLowerCase()
          return dir * (va < vb ? -1 : va > vb ? 1 : 0)
        case 'department':
          va = (a.department || '').toLowerCase()
          vb = (b.department || '').toLowerCase()
          return dir * (va < vb ? -1 : va > vb ? 1 : 0)
        case 'schedule':
          va = hasAssignedSchedule(a) ? 1 : 0
          vb = hasAssignedSchedule(b) ? 1 : 0
          return dir * (va - vb)
        case 'face':
          va = a.has_face ? 1 : 0
          vb = b.has_face ? 1 : 0
          return dir * (va - vb)
        case 'status':
          va = a.is_active ? 1 : 0
          vb = b.is_active ? 1 : 0
          return dir * (va - vb)
        case 'employment_status':
          va = formatEmploymentStatusForViewer(a.employment_status, a.employment_status_label, false) || '\uFFFF'
          vb = formatEmploymentStatusForViewer(b.employment_status, b.employment_status_label, false) || '\uFFFF'
          return dir * va.localeCompare(vb, undefined, { sensitivity: 'base' })
        case 'employee_level':
          va = Number(a.employee_level ?? 99)
          vb = Number(b.employee_level ?? 99)
          return dir * (va - vb)
        default:
          return 0
      }
    })
  }, [employees, normalizedSearchQuery, filterStatus, filterLevel, filterSchedule, filterFace, sortBy, sortDir])

  const hasListFilters = useMemo(
    () =>
      Boolean(
        filterCompany ||
        filterBranch ||
        filterLevel ||
        filterSchedule ||
        filterFace ||
        debouncedSearchQuery ||
        (filterStatus && filterStatus !== 'active'),
      ),
    [filterCompany, filterBranch, filterLevel, filterSchedule, filterFace, debouncedSearchQuery, filterStatus],
  )

  const toggleSort = (column) => {
    if (sortBy === column) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortDir('asc')
    }
  }

  const searchModalResults = useMemo(() => {
    const q = searchModalQuery.trim().toLowerCase()
    if (!q) return filteredEmployees.slice(0, 10)
    return filteredEmployees.filter((emp) => {
      const haystack = `${emp.name || ''} ${emp.email || ''} ${emp.department || ''} ${emp.position || ''}`.toLowerCase()
      return haystack.includes(q)
    })
  }, [searchModalQuery, filteredEmployees])

  const companyNameById = useMemo(() => {
    const byId = new Map()
    companies.forEach((c) => {
      byId.set(String(c.id), String(c.name || '').trim().toLowerCase())
    })
    return byId
  }, [companies])

  const activeCompanyOptions = useMemo(
    () =>
      companies
        .filter((company) => String(company?.status || 'active').toLowerCase() === 'active')
        .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''))),
    [companies]
  )

  const branchFilterOptions = useMemo(() => {
    let list = [...branches]
    if (filterCompany && filterCompany !== 'no_company') {
      list = list.filter((branch) => String(branch.company_id) === String(filterCompany))
    }
    return list.sort((a, b) => {
      const companyCompare = String(a.company_name || '').localeCompare(String(b.company_name || ''))
      if (companyCompare !== 0) return companyCompare
      return String(a.name || '').localeCompare(String(b.name || ''))
    })
  }, [branches, filterCompany])

  const handleCompanyFilterChange = useCallback((nextCompany) => {
    setFilterCompany(nextCompany)
    setFilterBranch((current) => {
      if (!current || !nextCompany || nextCompany === 'no_company') return ''
      const branch = branches.find((item) => String(item.id) === String(current))
      if (!branch) return ''
      return String(branch.company_id) === String(nextCompany) ? current : ''
    })
  }, [branches])

  const handleBranchFilterChange = useCallback((nextBranch) => {
    setFilterBranch(nextBranch)
    if (!nextBranch) return
    const branch = branches.find((item) => String(item.id) === String(nextBranch))
    if (!branch?.company_id) return
    if (filterCompany && filterCompany !== 'no_company' && String(filterCompany) !== String(branch.company_id)) {
      return
    }
    if (!filterCompany || filterCompany === 'no_company') {
      setFilterCompany(String(branch.company_id))
    }
  }, [branches, filterCompany])

  const getCompanyNameById = useCallback((companyId) => companyNameById.get(String(companyId)) || '', [companyNameById])

  const isEmployeeInCompany = useCallback((emp, companyId) => {
    if (!companyId) return true
    // Primary check: employee's synced company_id
    if (emp.company_id && String(emp.company_id) === String(companyId)) return true
    // Fallback: company name match
    const companyName = getCompanyNameById(companyId)
    if (!companyName) return false
    return String(emp.company_name || '').trim().toLowerCase() === companyName
  }, [getCompanyNameById])

  const sortSupervisorCandidates = useCallback((a, b) => {
    if (Boolean(a.is_active) !== Boolean(b.is_active)) {
      return a.is_active ? -1 : 1
    }
    return compareEmployeesByLastName(a, b)
  }, [])

  const getCompanyHeadId = useCallback((companyId) => {
    const company = companies.find((c) => String(c.id) === String(companyId))
    return company?.company_head_id ?? null
  }, [companies])

  const getSupervisorCandidatesByCompany = useCallback((companyId, excludeEmployeeId = null) => {
    const base = employees.filter((emp) => emp.id !== excludeEmployeeId)
    const managerialMatches = base
      .filter((emp) => isManagerialPosition(emp.position) && isEmployeeInCompany(emp, companyId))
      .sort(sortSupervisorCandidates)
    if (managerialMatches.length === 0) return []

    // Prefer company head from Company module when valid.
    const companyHeadId = getCompanyHeadId(companyId)
    if (!companyHeadId) return managerialMatches
    const head = managerialMatches.find((emp) => String(emp.id) === String(companyHeadId))
    if (!head) return managerialMatches
    return [head, ...managerialMatches.filter((emp) => String(emp.id) !== String(companyHeadId))]
  }, [employees, isEmployeeInCompany, sortSupervisorCandidates, getCompanyHeadId])

  const profileSupervisorOptions = useMemo(
    () => getSupervisorCandidatesByCompany(personalInfoForm.department_id, previewEmployee?.id),
    [personalInfoForm.department_id, previewEmployee?.id, getSupervisorCandidatesByCompany]
  )
  const handleProfileDepartmentChange = (nextDepartmentId) => {
    setPersonalInfoForm((prev) => {
      const scopedSupervisors = getSupervisorCandidatesByCompany(
        nextDepartmentId,
        previewEmployee?.id
      )

      const keepCurrentSupervisor = scopedSupervisors.some(
        (emp) => String(emp.id) === String(prev.supervisor_id)
      )

      return {
        ...prev,
        department_id: nextDepartmentId,
        supervisor_id: keepCurrentSupervisor
          ? prev.supervisor_id
          : (scopedSupervisors[0]?.id ? String(scopedSupervisors[0].id) : ''),
      }
    })
  }

  const allVisibleSelected =
    filteredEmployees.length > 0 &&
    filteredEmployees.every((emp) => selectedIds.includes(emp.id))
  const someVisibleSelected = filteredEmployees.some((emp) => selectedIds.includes(emp.id))

  const toggleSelectOne = (id) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    )
  }

  const toggleSelectAllVisible = () => {
    setSelectedIds((prev) => {
      if (filteredEmployees.length === 0) return prev
      const visibleIds = filteredEmployees.map((e) => e.id)
      const allSelected = visibleIds.every((id) => prev.includes(id))
      if (allSelected) {
        return prev.filter((id) => !visibleIds.includes(id))
      }
      const merged = new Set([...prev, ...visibleIds])
      return Array.from(merged)
    })
  }

  const openBulkEditEmployeeCodes = () => {
    if (selectedIds.length === 0) {
      toast({
        title: 'No employees selected',
        description: 'Select at least one employee to edit Employee ID.',
        variant: 'destructive',
      })
      return
    }
    const drafts = {}
    selectedIds.forEach((id) => {
      const emp = employees.find((e) => e.id === id)
      drafts[id] = composeEmployeeCode(emp?.employee_code) || ''
    })
    setEditEmployeeCodesDrafts(drafts)
    setEditEmployeeCodesErrors({})
    setEditEmployeeCodesChecking({})
    setEditEmployeeCodesOpen(true)
  }

  useEffect(() => {
    if (!editEmployeeCodesOpen) return undefined

    let cancelled = false
    const timers = []

    selectedIds.forEach((id) => {
      const code = composeEmployeeCode(editEmployeeCodesDrafts[id])
      const emp = employees.find((e) => e.id === id)
      const original = composeEmployeeCode(emp?.employee_code)
      const digits = employeeCodeDigits(code)

      if (!digits) {
        setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: false }))
        setEditEmployeeCodesErrors((prev) => ({ ...prev, [id]: 'Enter the numeric part of the Employee ID.' }))
        return
      }

      const duplicateInSelection = selectedIds.some((otherId) => {
        if (otherId === id) return false
        const other = composeEmployeeCode(editEmployeeCodesDrafts[otherId])
        return Boolean(other) && other.toLowerCase() === code.toLowerCase()
      })
      if (duplicateInSelection) {
        setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: false }))
        setEditEmployeeCodesErrors((prev) => ({ ...prev, [id]: 'Duplicate Employee ID in this selection.' }))
        return
      }

      if (original && code.toLowerCase() === original.toLowerCase()) {
        setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: false }))
        setEditEmployeeCodesErrors((prev) => {
          if (!prev[id]) return prev
          const next = { ...prev }
          delete next[id]
          return next
        })
        return
      }

      setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: true }))
      setEditEmployeeCodesErrors((prev) => {
        if (!prev[id]) return prev
        const next = { ...prev }
        delete next[id]
        return next
      })

      const timer = setTimeout(() => {
        checkEmployeeCodeAvailability(code, id)
          .then((data) => {
            if (cancelled) return
            setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: false }))
            if (data?.available) {
              setEditEmployeeCodesErrors((prev) => {
                if (!prev[id]) return prev
                const next = { ...prev }
                delete next[id]
                return next
              })
              return
            }
            setEditEmployeeCodesErrors((prev) => ({
              ...prev,
              [id]: data?.message || 'This Employee ID is already used by another employee.',
            }))
          })
          .catch((err) => {
            if (cancelled) return
            setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: false }))
            setEditEmployeeCodesErrors((prev) => ({
              ...prev,
              [id]: err?.message || 'Unable to verify Employee ID. Try again.',
            }))
          })
      }, 350)
      timers.push(timer)
    })

    return () => {
      cancelled = true
      timers.forEach(clearTimeout)
    }
  }, [editEmployeeCodesOpen, editEmployeeCodesDrafts, selectedIds, employees])

  const editEmployeeCodesBusy =
    editEmployeeCodesSaving ||
    Object.values(editEmployeeCodesChecking).some(Boolean) ||
    Object.keys(editEmployeeCodesErrors).length > 0

  const saveBulkEmployeeCodes = async () => {
    const ids = selectedIds.filter((id) => editEmployeeCodesDrafts[id] !== undefined)
    const errors = {}
    const seen = new Map()

    for (const id of ids) {
      const code = composeEmployeeCode(editEmployeeCodesDrafts[id])
      if (!isValidEmployeeCode(code)) {
        errors[id] = 'Enter numbers only after EMP-.'
        continue
      }
      const key = code.toLowerCase()
      if (seen.has(key)) {
        errors[id] = 'Duplicate Employee ID in this selection.'
        errors[seen.get(key)] = 'Duplicate Employee ID in this selection.'
        continue
      }
      seen.set(key, id)
    }

    if (Object.keys(errors).length > 0) {
      setEditEmployeeCodesErrors(errors)
      return
    }

    setEditEmployeeCodesSaving(true)
    setEditEmployeeCodesErrors({})
    try {
      const updates = []
      for (const id of ids) {
        const emp = employees.find((e) => e.id === id)
        const nextCode = composeEmployeeCode(editEmployeeCodesDrafts[id])
        const currentCode = composeEmployeeCode(emp?.employee_code)
        if (nextCode.toLowerCase() === currentCode.toLowerCase()) continue

        const availability = await checkEmployeeCodeAvailability(nextCode, id)
        if (!availability?.available) {
          errors[id] = availability?.message || 'This Employee ID is already used by another employee.'
          continue
        }
        updates.push({ id, employee_code: nextCode, name: emp?.name })
      }

      if (Object.keys(errors).length > 0) {
        setEditEmployeeCodesErrors(errors)
        return
      }

      if (updates.length === 0) {
        setEditEmployeeCodesOpen(false)
        toast({ title: 'No changes', description: 'Employee IDs were already up to date.', variant: 'success' })
        return
      }

      for (const row of updates) {
        await updateEmployee(row.id, { employee_code: row.employee_code })
      }

      setEmployees((prev) =>
        prev.map((emp) => {
          const hit = updates.find((u) => u.id === emp.id)
          return hit ? { ...emp, employee_code: hit.employee_code } : emp
        })
      )
      setEditEmployeeCodesOpen(false)
      setSelectedIds([])
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      toast({
        title: 'Employee IDs updated',
        description: `Updated ${updates.length} employee${updates.length === 1 ? '' : 's'}.`,
        variant: 'success',
      })
    } catch (e) {
      toast({
        title: 'Failed to update Employee IDs',
        description: e.message || 'Unable to save Employee ID changes.',
        variant: 'error',
      })
    } finally {
      setEditEmployeeCodesSaving(false)
    }
  }

  const openBulkSchedule = () => {
    if (selectedIds.length === 0) {
      toast({
        title: 'No employees selected',
        description: 'Select at least one employee to assign a schedule.',
        variant: 'destructive',
      })
      return
    }
    setScheduleEmployee(null)
    setBulkScheduleIds([...selectedIds])
    setScheduleOpen(true)
  }

  const openBulkScheduleAdjustment = () => {
    if (selectedIds.length === 0) {
      toast({
        title: 'No employees selected',
        description: 'Select at least one employee to add a schedule adjustment.',
        variant: 'destructive',
      })
      return
    }
    setAdjustmentEmployeeIds([...selectedIds])
    setAdjustmentOpen(true)
  }

  const handleBulkDeactivate = async () => {
    if (selectedIds.length === 0) {
      toast({
        title: 'No employees selected',
        description: 'Select at least one employee to deactivate.',
        variant: 'destructive',
      })
      return
    }

    const targets = employees.filter(
      (e) => selectedIds.includes(e.id) && e.is_active
    )
    if (targets.length === 0) {
      toast({
        title: 'Nothing to deactivate',
        description: 'All selected employees are already deactivated.',
        variant: 'default',
      })
      return
    }

    setBulkSubmitting(true)
    setError(null)
    try {
      await Promise.all(targets.map((emp) => toggleEmployeeActive(emp.id)))
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
      toast({
        title: 'Employees deactivated',
        description: `${targets.length} employee(s) updated.`,
        variant: 'success',
      })
    } catch (e) {
      setError(e.message)
      toast({
        title: 'Failed to deactivate employees',
        description: e.message,
        variant: 'error',
      })
    } finally {
      setBulkSubmitting(false)
    }
  }

  const handleBulkIssueQr = async () => {
    if (selectedIds.length === 0) {
      toast({
        title: 'No employees selected',
        description: 'Select at least one employee to issue QR codes.',
        variant: 'destructive',
      })
      return
    }

    const targets = employees.filter(
      (e) => selectedIds.includes(e.id) && !e.has_qr
    )
    if (targets.length === 0) {
      toast({
        title: 'Nothing to issue',
        description: 'All selected employees already have QR codes.',
        variant: 'default',
      })
      return
    }

    setBulkSubmitting(true)
    setError(null)
    try {
      await Promise.all(targets.map((emp) => regenerateEmployeeQr(emp.id)))
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
      toast({
        title: 'QR codes issued',
        description: `${targets.length} employee(s) updated.`,
        variant: 'success',
      })
    } catch (e) {
      setError(e.message)
      toast({
        title: 'Failed to issue QR codes',
        description: e.message,
        variant: 'error',
      })
    } finally {
      setBulkSubmitting(false)
    }
  }

  const openPreview = async (emp) => {
    if (!emp?.id) return
    setPreviewLoading(false)
    if (isAdminHrUser(user)) {
      navigate(hrPanelPath(hrBase, `employees/${emp.id}`))
    } else {
      navigate(hrPanelPath(hrBase, `profile/${emp.id}`))
    }
  }

  const handleToggleActive = async (emp) => {
    if (emp.is_active) {
      setDeactivateEmployee(emp)
      setDeactivateOpen(true)
      return
    }
    await doToggleActive(emp)
  }

  const doToggleActive = async (emp) => {
    setDeactivateOpen(false)
    setDeactivateEmployee(null)
    setTogglingId(emp.id)
    setError(null)
    const nextActive = !emp?.is_active
    setEmployees((prev) =>
      prev.map((row) =>
        row.id === emp.id
          ? { ...row, is_active: nextActive }
          : row
      )
    )
    if (previewEmployee?.id === emp.id) {
      setPreviewEmployee((prev) => (prev ? { ...prev, is_active: nextActive } : prev))
    }
    try {
      await toggleEmployeeActive(emp.id)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
    } catch (e) {
      setEmployees((prev) =>
        prev.map((row) =>
          row.id === emp.id
            ? { ...row, is_active: Boolean(emp?.is_active) }
            : row
        )
      )
      if (previewEmployee?.id === emp.id) {
        setPreviewEmployee((prev) => (prev ? { ...prev, is_active: Boolean(emp?.is_active) } : prev))
      }
      setError(e.message)
    } finally {
      setTogglingId(null)
    }
  }

  const handleFaceRegisterVerified = async (verificationPayload) => {
    if (!faceRegisterEmployee || faceRegisterSubmitting) return
    setFaceRegisterSubmitting(true)
    setFaceRegisterError(null)
    try {
      await registerEmployeeFace(
        faceRegisterEmployee.id,
        {
          image_base64: verificationPayload?.image_base64,
          liveness_type: 'mediapipe',
        },
        'mediapipe'
      )
      const wasChange = faceRegisterEmployee.has_face
      setFaceRegisterOpen(false)
      setFaceRegisterEmployee(null)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
      toast({
        title: wasChange ? 'Face successfully updated.' : 'Face registered',
        description: wasChange ? `${faceRegisterEmployee.name}'s face has been replaced.` : `${faceRegisterEmployee.name} can now sign in with face recognition.`,
        variant: 'success',
      })
    } catch (e) {
      const msg = e.message || 'Face registration failed'
      const code = e.errorCode
      setFaceRegisterError(msg)
      setFaceRegisterErrorCode(code || null)
      const title =
        code === 'face_already_registered'
          ? 'Duplicate face detected'
          : code === 'spoof_detected'
            ? 'Liveness check failed'
            : code === 'no_face_detected'
              ? 'No face detected'
              : code === 'registration_timeout'
                ? 'Registration timed out'
                : code === 'service_unavailable'
                  ? 'Face service unavailable'
                  : code === 'face_needs_reregistration'
                    ? 'Face update required'
                    : 'Face registration failed'
      toast({ title, description: msg, variant: 'destructive', duration: code === 'face_already_registered' ? 8000 : 4000 })
    } finally {
      setFaceRegisterSubmitting(false)
    }
  }

  const closeFaceRegister = () => {
    if (!faceRegisterSubmitting) {
      setFaceRegisterOpen(false)
      setFaceRegisterEmployee(null)
      setFaceRegisterError(null)
      setFaceRegisterErrorCode(null)
    }
  }

  const handleRemoveFace = async () => {
    if (!removeFaceConfirmEmployee) return
    setFaceRemoveSubmitting(true)
    try {
      await updateEmployeeFace(removeFaceConfirmEmployee.id, { face_descriptor: null })
      setEmployees((prev) =>
        prev.map((e) =>
          e.id === removeFaceConfirmEmployee.id
            ? { ...e, has_face: false, face_status: 'not_registered' }
            : e
        )
      )
      if (previewEmployee?.id === removeFaceConfirmEmployee.id) {
        setPreviewEmployee((p) =>
          p && p.id === removeFaceConfirmEmployee.id
            ? { ...p, has_face: false, face_status: 'not_registered' }
            : p
        )
      }
      if (viewFaceEmployee?.id === removeFaceConfirmEmployee.id) {
        closeViewFace()
      }
      setRemoveFaceConfirmEmployee(null)
      toast({
        title: 'Face registration reset',
        description: `${removeFaceConfirmEmployee.name}'s face artifacts were cleared and can now be re-registered.`,
        variant: 'success',
      })
    } catch (e) {
      toast({ title: 'Failed to remove face', description: e.message, variant: 'destructive' })
    } finally {
      setFaceRemoveSubmitting(false)
    }
  }

  const openFaceRegister = (emp, skipConfirm = false) => {
    if (emp?.has_face && !skipConfirm) {
      setChangeFaceConfirmEmployee(emp)
      return
    }
    setFaceRegisterEmployee(emp)
    setFaceRegisterError(null)
    setFaceRegisterErrorCode(null)
    setFaceRegisterRetryKey((k) => k + 1)
    setFaceRegisterOpen(true)
  }

  const confirmChangeFace = () => {
    if (changeFaceConfirmEmployee) {
      setFaceRegisterEmployee(changeFaceConfirmEmployee)
      setFaceRegisterError(null)
      setFaceRegisterRetryKey((k) => k + 1)
      setFaceRegisterOpen(true)
      setChangeFaceConfirmEmployee(null)
    }
  }

  const openViewFace = async (emp) => {
    if (!emp?.has_face) return
    setViewFaceEmployee(emp)
    setViewFaceOpen(true)
    setViewFaceImage(null)
    setViewFaceMessage(null)
    setViewFaceLoading(true)
    setError(null)
    try {
      const data = await getEmployeeFace(emp.id)
      if (!data?.has_face) {
        setError(data?.message || 'No face registered.')
        setViewFaceOpen(false)
        return
      }
      setViewFaceImage(data.face_image)
      setViewFaceMessage(data.message || null)
    } catch (e) {
      setError(e.message)
      setViewFaceOpen(false)
    } finally {
      setViewFaceLoading(false)
    }
  }

  const closeViewFace = () => {
    setViewFaceOpen(false)
    setViewFaceEmployee(null)
    setViewFaceImage(null)
    setViewFaceMessage(null)
  }

  const openAddEmployeeModal = useCallback(() => {
    if (!canCreateEmployees) {
      toast({
        title: 'Access denied',
        description: 'You do not have permission to create employees.',
        variant: 'destructive',
      })
      return
    }
    setAddOpen(true)
  }, [canCreateEmployees, toast])

  const handleExportAllCsv = useCallback(async () => {
    if (!canExportEmployees) {
      toast({
        title: 'Access denied',
        description: 'You do not have permission to export employees.',
        variant: 'destructive',
      })
      return
    }

    setExportingCsv(true)
    setError(null)
    try {
      const { blob, filename } = await exportAllEmployeesCsv()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename || `employees_by_company_${new Date().toISOString().slice(0, 10)}.xlsx`
      anchor.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
      toast({ title: 'Export started', description: 'Employee workbook has been downloaded.' })
    } catch (e) {
      const message = e?.message || 'Failed to export employee workbook.'
      setError(message)
      toast({ title: 'Export failed', description: message, variant: 'destructive' })
    } finally {
      setExportingCsv(false)
    }
  }, [canExportEmployees, toast])

  const businessCardDetails = useMemo(
    () => getBusinessCardDetails(businessCardEmployee),
    [businessCardEmployee, getBusinessCardDetails],
  )
  const businessCardThemeStyle = useMemo(() => ({
    '--business-card-primary': businessCardTheme.primary,
    '--business-card-primary-dark': businessCardTheme.primaryDark,
    '--business-card-primary-soft': businessCardTheme.primarySoft,
    '--business-card-text': businessCardTheme.text,
    '--business-card-accent': businessCardTheme.accent,
    '--business-card-primary-rgb': `${hexToRgb(businessCardTheme.primary).r}, ${hexToRgb(businessCardTheme.primary).g}, ${hexToRgb(businessCardTheme.primary).b}`,
    '--business-card-overlay': `linear-gradient(90deg, ${businessCardTheme.primaryDark}fa 0%, ${businessCardTheme.primary}bf 43%, ${businessCardTheme.primary}29 73%, rgba(0,0,0,.05) 100%)`,
  }), [businessCardTheme])

  const pageTransition = { duration: 0.25, ease: [0.23, 1, 0.32, 1] }

  return (
    <motion.div
      className="admin-employees-page space-y-7 text-foreground"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={pageTransition}
    >
      <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <h2 className="text-[30px] font-extrabold leading-tight tracking-tight text-foreground">Employees</h2>
          <CardDescription className="mt-2 text-sm leading-relaxed text-muted-foreground">
            Add employees, issue QR codes, assign schedule, and activate or deactivate.
          </CardDescription>
        </div>
        <div className="flex items-center @sm:justify-end">
          <Button
            type="button"
            className="h-12 rounded-md bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_14px_28px_rgba(255,107,0,0.24)] hover:bg-brand-strong dark:shadow-[0_16px_36px_rgba(0,0,0,0.35)]"
            onClick={openAddEmployeeModal}
          >
            <Plus className="mr-1.5 size-4" />
            Add Employee
          </Button>
        </div>
      </div>

      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {/* Cmd+K / Ctrl+K — quick search modal */}
      <Dialog open={searchModalOpen} onOpenChange={setSearchModalOpen}>
        <DialogContent className="max-w-md gap-0 p-0 overflow-hidden">
          <div className="flex items-center border-b border-border px-3">
            <Search className="size-4 shrink-0 text-muted-foreground" />
            <Input
              ref={searchModalInputRef}
              type="text"
              placeholder="Search employees by name, username, email, department..."
              value={searchModalQuery}
              onChange={(e) => setSearchModalQuery(e.target.value)}
              className="h-12 border-0 shadow-none focus-visible:ring-0 focus-visible:ring-offset-0"
            />
            <kbd className="pointer-events-none hidden rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground @sm:inline-block">
              ESC
            </kbd>
          </div>
          <div className="max-h-[60vh] overflow-y-auto">
            {searchModalResults.length === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-muted-foreground">No matching employees.</p>
            ) : (
              <ul className="py-1">
                {searchModalResults.map((emp) => (
                  <li key={emp.id}>
                    <button
                      type="button"
                      className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-muted/80 focus:bg-muted/80 focus:outline-none"
                      onClick={() => {
                        openPreview(emp)
                        setSearchModalOpen(false)
                        setSearchModalQuery('')
                      }}
                    >
                      <Avatar className="size-8 shrink-0 rounded-full">
                        <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                        <AvatarFallback className={`rounded-full text-xs font-semibold ${getAvatarColor(emp.id, emp.name)}`}>
                          {(emp.name || '?').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '?'}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5 flex-wrap">
                          <span className="font-medium text-foreground">{emp.name}</span>
                        </div>
                        <p className="truncate text-xs text-muted-foreground">{emp.email}</p>
                      </div>
                      {emp.company_name && (
                        <span className="truncate text-xs text-muted-foreground max-w-[120px]">{emp.company_name}</span>
                      )}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Card className="overflow-hidden rounded-lg border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_36px_rgba(15,23,42,0.06)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.32)]">
          <CardHeader className="border-b border-border/60 bg-card px-5 py-6 dark:bg-card">
            <div className="grid gap-4 @lg:grid-cols-[minmax(14rem,1fr)_minmax(18rem,30rem)_auto] @lg:items-start">
              <div>
                <CardTitle className="text-lg font-extrabold tracking-tight text-foreground">Employee Directory</CardTitle>
                <CardDescription className="mt-1 text-sm text-muted-foreground">
                  {filteredEmployees.length} of {pagination.total} employee(s)
                </CardDescription>
              </div>
              <div className="relative w-full">
                  <Input
                    type="text"
                    className="h-10 rounded-md border-border/70 bg-background pl-10 pr-10 text-sm shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-brand/25 dark:bg-background/70"
                    placeholder="Search by name, username, or email..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                  />
                  <Search className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <button
                    type="button"
                    className="absolute right-2 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md border border-border/60 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    title="Search filters"
                    onClick={() => setSearchModalOpen(true)}
                  >
                    <Funnel className="size-3.5" />
                  </button>
                </div>
              <div className="flex flex-col items-stretch gap-3 @lg:items-end">
                <div className="flex flex-wrap items-center gap-2 @lg:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 rounded-md border-brand/70 px-4 text-xs font-bold text-brand hover:bg-brand/8 hover:text-brand"
                  onClick={() => setImportOpen(true)}
                  disabled={!canCreateEmployees}
                  title={canCreateEmployees ? 'Import employees from CSV/XLSX' : 'No create permission'}
                >
                  <Upload className="mr-1.5 size-4" />
                  Import Employees
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 rounded-md border-brand/70 px-4 text-xs font-bold text-brand hover:bg-brand/8 hover:text-brand"
                  onClick={handleExportAllCsv}
                  disabled={!canExportEmployees || exportingCsv}
                  title={canExportEmployees ? 'Export employees by company workbook' : 'No export permission'}
                >
                  {exportingCsv ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Download className="mr-1.5 size-4" />}
                  Export by Company
                </Button>
                </div>
                {/* Density toggle */}
                <button
                  type="button"
                  title={density === 'comfortable' ? 'Switch to compact view' : 'Switch to comfortable view'}
                  onClick={() => setDensity((d) => d === 'comfortable' ? 'compact' : 'comfortable')}
                  className="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-transparent bg-transparent px-3 text-xs font-semibold text-foreground transition-colors hover:border-border/60 hover:bg-muted/50 dark:text-foreground"
                >
                  <LayoutList className="size-3.5" />
                  Customize
                </button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {selectedIds.length > 0 && canMutateRows && (
              <div
                className="sticky top-0 z-10 border-b border-border/70 border-l-[3px] border-l-brand bg-muted/40 px-5 py-2.5 backdrop-blur-sm dark:bg-muted/25"
                role="region"
                aria-label="Bulk employee actions"
              >
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <span className="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-foreground px-2 text-xs font-bold tabular-nums text-background">
                      {selectedIds.length}
                    </span>
                    <p className="text-sm text-muted-foreground">
                      <span className="font-semibold text-foreground">
                        employee{selectedIds.length === 1 ? '' : 's'}
                      </span>{' '}
                      selected
                    </p>
                    <button
                      type="button"
                      className="text-xs font-medium text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
                      onClick={() => setSelectedIds([])}
                    >
                      Clear
                    </button>
                  </div>

                  <div className="ml-auto flex flex-wrap items-center gap-0.5">
                    {canAssignSchedule && (
                      <>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-foreground hover:bg-background/80"
                          onClick={openBulkSchedule}
                          disabled={bulkSubmitting}
                        >
                          <Clock className="size-3.5 text-muted-foreground" />
                          Assign schedule
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-foreground hover:bg-background/80"
                          onClick={openBulkScheduleAdjustment}
                          disabled={bulkSubmitting}
                        >
                          <CalendarCheck className="size-3.5 text-muted-foreground" />
                          Adjust schedule
                        </Button>
                      </>
                    )}
                    {canScopedEditEmployees && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-foreground hover:bg-background/80"
                        onClick={openBulkEditEmployeeCodes}
                        disabled={bulkSubmitting || editEmployeeCodesSaving}
                      >
                        <IdCard className="size-3.5 text-muted-foreground" />
                        Edit Employee ID
                      </Button>
                    )}
                    {canScopedEditEmployees && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-foreground hover:bg-background/80"
                        onClick={handleBulkIssueQr}
                        disabled={bulkSubmitting}
                      >
                        <QrCode className="size-3.5 text-muted-foreground" />
                        Issue QR
                      </Button>
                    )}
                    {canScopedEditEmployees && (
                      <>
                        <span className="mx-1 hidden h-4 w-px bg-border sm:block" aria-hidden />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-destructive hover:bg-destructive/10 hover:text-destructive"
                          onClick={handleBulkDeactivate}
                          disabled={bulkSubmitting}
                        >
                          <UserX className="size-3.5" />
                          Deactivate
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              </div>
            )}
            {/* Filter bar always visible — never tied to result count or refresh */}
            <div className="border-b border-border/60 bg-background/55 px-5 py-4 backdrop-blur-sm dark:bg-background/25">
                  <div className="flex flex-wrap items-center gap-2.5">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Filter:</span>

                    <label className="inline-flex items-center gap-2">
                      <span className="sr-only">Company</span>
                      <select
                        value={filterCompany}
                        onChange={(event) => handleCompanyFilterChange(event.target.value)}
                        className={`${FIELD_SELECT_CLASS} h-10 min-w-[13rem] rounded-md text-sm`}
                        aria-label="Filter employees by company"
                      >
                        <option value="">All Companies</option>
                        <option value="no_company">No Company Assigned</option>
                        {activeCompanyOptions.map((company) => (
                          <option key={company.id} value={company.id}>
                            {company.name}
                          </option>
                        ))}
                      </select>
                    </label>

                    <label className="inline-flex items-center gap-2">
                      <span className="sr-only">Branch</span>
                      <select
                        value={filterBranch}
                        onChange={(event) => handleBranchFilterChange(event.target.value)}
                        disabled={filterCompany === 'no_company' || branchFilterOptions.length === 0}
                        className={`${FIELD_SELECT_CLASS} h-10 min-w-[13rem] rounded-md text-sm disabled:cursor-not-allowed disabled:opacity-60`}
                        aria-label="Filter employees by branch"
                      >
                        <option value="">All Branches</option>
                        {branchFilterOptions.map((branch) => (
                          <option key={branch.id} value={branch.id}>
                            {branch.name}
                            {branch.company_name && !filterCompany ? ` (${branch.company_name})` : ''}
                          </option>
                        ))}
                      </select>
                    </label>

                    <label className="inline-flex items-center gap-2">
                      <span className="sr-only">Employee Level</span>
                      <select
                        value={filterLevel}
                        onChange={(event) => setFilterLevel(event.target.value)}
                        className={`${FIELD_SELECT_CLASS} h-10 min-w-[13rem] rounded-md text-sm`}
                        aria-label="Filter employees by level"
                      >
                        <option value="">All Levels</option>
                        {EMPLOYEE_LEVEL_OPTIONS.map((level) => (
                          <option key={level.value} value={level.value}>
                            {level.label}
                          </option>
                        ))}
                      </select>
                    </label>

                    {/* Status chips */}
                    {[{ val: 'active', label: 'Active', color: 'emerald' }, { val: 'deactivated', label: 'Deactivated', color: 'zinc' }, { val: 'all', label: 'All', color: 'blue' }].map(({ val, label, color }) => (
                      <button
                        key={val}
                        type="button"
                        onClick={() => setFilterStatus(filterStatus === val ? '' : val)}
                        className={[
                          'inline-flex h-10 items-center gap-1.5 rounded-md border px-4 text-sm font-medium transition-all',
                          filterStatus === val
                            ? color === 'emerald'
                              ? 'border-brand/70 bg-brand/8 text-brand dark:border-brand/60 dark:bg-brand/12 dark:text-brand'
                              : 'border-zinc-500/60 bg-zinc-500/15 text-zinc-700 dark:border-zinc-400/50 dark:bg-zinc-500/20 dark:text-zinc-300'
                            : 'border-border/70 bg-card text-foreground hover:border-brand/50 hover:bg-brand/5 hover:text-brand dark:bg-card',
                        ].join(' ')}
                      >
                        {filterStatus === val
                          ? <><span className="opacity-60">Status:</span> {label} <X className="size-3 ml-0.5" onClick={(e) => { e.stopPropagation(); setFilterStatus('') }} /></>
                          : label}
                      </button>
                    ))}

                    {/* Schedule chips */}
                    {[{ val: 'scheduled', label: 'Has Schedule', color: 'indigo' }, { val: 'unscheduled', label: 'No Schedule', color: 'amber' }].map(({ val, label, color }) => (
                      <button
                        key={val}
                        type="button"
                        onClick={() => setFilterSchedule(filterSchedule === val ? '' : val)}
                        className={[
                          'inline-flex h-10 items-center gap-1.5 rounded-md border px-4 text-sm font-medium transition-all',
                          filterSchedule === val
                            ? color === 'indigo'
                              ? 'border-indigo-500/60 bg-indigo-500/15 text-indigo-700 dark:border-indigo-400/50 dark:bg-indigo-500/20 dark:text-indigo-300'
                              : 'border-amber-500/60 bg-amber-500/15 text-amber-700 dark:border-amber-400/50 dark:bg-amber-500/20 dark:text-amber-300'
                            : 'border-border/70 bg-card text-foreground hover:border-brand/50 hover:bg-brand/5 hover:text-brand dark:bg-card',
                        ].join(' ')}
                      >
                        {filterSchedule === val
                          ? <><span className="opacity-60">Schedule:</span> {label} <X className="size-3 ml-0.5" onClick={(e) => { e.stopPropagation(); setFilterSchedule('') }} /></>
                          : label}
                      </button>
                    ))}

                    {/* Face chips */}
                    {[{ val: 'registered', label: 'Face Registered', color: 'emerald' }, { val: 'unregistered', label: 'No Face', color: 'rose' }].map(({ val, label, color }) => (
                      <button
                        key={val}
                        type="button"
                        onClick={() => setFilterFace(filterFace === val ? '' : val)}
                        className={[
                          'inline-flex h-10 items-center gap-1.5 rounded-md border px-4 text-sm font-medium transition-all',
                          filterFace === val
                            ? color === 'emerald'
                              ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-700 dark:border-emerald-400/50 dark:bg-emerald-500/20 dark:text-emerald-300'
                              : 'border-rose-500/60 bg-rose-500/15 text-rose-700 dark:border-rose-400/50 dark:bg-rose-500/20 dark:text-rose-300'
                            : 'border-border/70 bg-card text-foreground hover:border-brand/50 hover:bg-brand/5 hover:text-brand dark:bg-card',
                        ].join(' ')}
                      >
                        {filterFace === val
                          ? <><span className="opacity-60">Face:</span> {label} <X className="size-3 ml-0.5" onClick={(e) => { e.stopPropagation(); setFilterFace('') }} /></>
                          : label}
                      </button>
                    ))}

                    {/* Clear all active filters */}
                    {(filterCompany || filterBranch || filterLevel || filterStatus || filterSchedule || filterFace) && (
                      <button
                        type="button"
                        onClick={() => {
                          setFilterCompany('')
                          setFilterBranch('')
                          setFilterLevel('')
                          setFilterStatus('')
                          setFilterSchedule('')
                          setFilterFace('')
                        }}
                        className="ml-auto inline-flex h-10 items-center gap-1 rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                      >
                        <X className="size-3.5" />
                        Clear all
                      </button>
                    )}
                  </div>
            </div>

            {(employeesQuery.isLoading || employeesQuery.isFetching) ? (
              <div className="overflow-x-auto bg-card px-4 py-4">
                <TableSkeleton rows={10} cols={9} className="rounded-xl border border-border/40" />
              </div>
            ) : filteredEmployees.length === 0 ? (
              <p className="py-12 text-center text-muted-foreground">
                {hasListFilters
                  ? 'No employees found for the selected filters.'
                  : 'No employees yet. Add one to get started.'}
              </p>
            ) : (
              <div className="overflow-x-auto bg-card">
                  <table className="w-full text-sm text-foreground">
                    <thead className="sticky top-0 z-10 border-b border-border/60 bg-muted/35 shadow-[0_1px_0_0_var(--border)] dark:border-border dark:bg-background/35">
                      <tr>
                        {canMutateRows && (
                          <th className="w-12 min-w-12 max-w-12 py-4 pl-4 pr-2 text-center">
                            <Checkbox
                              checked={allVisibleSelected}
                              onCheckedChange={toggleSelectAllVisible}
                              aria-label="Select all visible employees"
                              className={
                                someVisibleSelected && !allVisibleSelected
                                  ? 'data-[state=indeterminate]:bg-primary/30'
                                  : undefined
                              }
                            />
                          </th>
                        )}
                        <th
                          className="w-[118px] cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('employee_code')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Employee ID
                            {sortBy === 'employee_code' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('name')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Employee
                            {sortBy === 'name' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="w-[150px] max-w-[150px] cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('company_name')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Company
                            {sortBy === 'company_name' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Position</th>
                        <th
                          className="w-[150px] cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('employee_level')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Level
                            {sortBy === 'employee_level' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Branch</th>
                        <th
                          className="w-[128px] max-w-[140px] cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('employment_status')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Employment
                            {sortBy === 'employment_status' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Hire Date</th>
                        <th className="whitespace-nowrap px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                          Leave credits
                        </th>
                        <th className="px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">QR</th>
                        <th
                          className="cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('schedule')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Schedule
                            {sortBy === 'schedule' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('face')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Face
                            {sortBy === 'face' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer select-none px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground transition-colors hover:text-foreground"
                          onClick={() => toggleSort('status')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Status
                            {sortBy === 'status' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="px-4 py-4 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Actions</th>
                      </tr>
                    </thead>
                  <tbody>
                    {filteredEmployees.map((emp, rowIdx) => {
                      const initials = (emp.name || '?')
                        .trim()
                        .split(/\s+/)
                        .map((n) => n[0])
                        .join('')
                        .toUpperCase()
                        .slice(0, 2) || '?'
                      const isActive = activeEmployeeId === emp.id
                      const isSelected = selectedIds.includes(emp.id)
                      const isEven = rowIdx % 2 === 1
                      /** Aligns list with profile Leave Balance: Regular + 1 year from Hire Date; fills stale 0 pool. */
                      const leaveCreditsRow = deriveAdminEmployeeListLeaveCredits(emp)
                      return (
                        <motion.tr
                          key={emp.id}
                          onClick={() => openPreview(emp)}
                          initial={false}
                          transition={{ duration: 0.15 }}
                          className={[
                            'group cursor-pointer border-b border-border/45 transition-all duration-150 dark:border-border/55',
                            density === 'compact' ? '[&>td]:py-2.5' : '[&>td]:py-5',
                            isSelected
                              ? 'bg-brand/7 dark:bg-brand/10 [&>td:first-child]:shadow-[inset_3px_0_0_var(--brand)]'
                              : isActive
                              ? 'bg-brand/5 dark:bg-brand/10 [&>td:first-child]:shadow-[inset_3px_0_0_var(--brand)]'
                              : isEven
                              ? 'bg-card hover:bg-muted/25 dark:bg-card dark:hover:bg-muted/30 [&:hover>td:first-child]:shadow-[inset_3px_0_0_var(--brand)]'
                              : 'bg-card hover:bg-muted/25 dark:bg-card dark:hover:bg-muted/30 [&:hover>td:first-child]:shadow-[inset_3px_0_0_var(--brand)]',
                          ].join(' ')}
                        >
                          {canMutateRows && (
                            <td
                              className="w-12 min-w-12 max-w-12 pl-4 pr-2 text-center align-middle"
                              onClick={(e) => { e.stopPropagation() }}
                            >
                              <Checkbox
                                checked={selectedIds.includes(emp.id)}
                                onCheckedChange={() => toggleSelectOne(emp.id)}
                                aria-label={`Select ${emp.name}`}
                              />
                            </td>
                          )}

                          {/* Employee ID */}
                          <td className="px-4 align-middle">
                            <span className="font-mono text-[12.5px] font-semibold tabular-nums text-foreground">
                              {composeEmployeeCode(emp.employee_code) || '—'}
                            </span>
                          </td>

                          {/* Employee cell — avatar + name hierarchy */}
                          <td className="px-4">
                            <div className="flex items-center gap-3">
                              <Avatar className={`shrink-0 rounded-full shadow-sm ring-2 ring-border/40 transition-all group-hover:ring-brand/30 ${density === 'compact' ? 'size-8' : 'size-11'}`}>
                                <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                                <AvatarFallback className={`rounded-full font-bold ${density === 'compact' ? 'text-xs' : 'text-sm'} ${getAvatarColor(emp.id, emp.name)}`}>
                                  {initials}
                                </AvatarFallback>
                              </Avatar>
                              <div className="min-w-0">
                                <div className="flex items-center gap-1.5 flex-wrap">
                                  <p className="max-w-[190px] truncate text-[14.5px] font-bold leading-tight text-foreground">{emp.name}</p>
                                  <RoleBadge user={emp} size={density === 'compact' ? 'xs' : 'sm'} />
                                  {emp.scope_assignment_source && emp.scope_assignment_source !== 'primary' && (
                                    <Badge variant="outline" className="rounded-md px-1.5 py-0 text-[9px] font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">
                                      {emp.scope_assignment_source}
                                    </Badge>
                                  )}
                                </div>
                                <p className="max-w-[190px] truncate text-[11px] text-muted-foreground">{emp.email}</p>
                                {emp.phone_number && density !== 'compact' && (
                                  <p className="text-[10.5px] text-muted-foreground/70">{emp.phone_number}</p>
                                )}
                              </div>
                            </div>
                          </td>

                          {/* Company */}
                          <td className="px-4 align-middle max-w-[170px]">
                            {emp.company_name ? (() => {
                              const co = companies.find((c) => String(c.id) === String(emp.company_id))
                              const logoSrc = co ? companyLogoUrl(co) : null
                              return (
                                <div className="flex items-center gap-1.5 min-w-0">
                                  {logoSrc ? (
                                    <img
                                      src={logoSrc}
                                      alt=""
                                      className="size-5 rounded object-contain shrink-0"
                                      onError={(e) => { e.currentTarget.style.display = 'none' }}
                                    />
                                  ) : (
                                    <span className="size-5 rounded bg-primary/10 shrink-0 flex items-center justify-center text-[8px] font-bold text-primary select-none">
                                      {emp.company_name[0].toUpperCase()}
                                    </span>
                                  )}
                                  <span className="truncate text-[12.5px] text-foreground/75" title={emp.company_name}>
                                    {emp.company_name}
                                  </span>
                                </div>
                              )
                            })() : (
                              <span className="text-[12.5px] text-slate-400 dark:text-slate-500">—</span>
                            )}
                          </td>

                          {/* Position */}
                          <td className="px-4 align-middle">
                            <span className="text-[12.5px] text-slate-500 dark:text-slate-400">{emp.position || '—'}</span>
                          </td>

                          {/* Employee Level */}
                          <td className="px-4 align-middle">
                            <span className="block max-w-[150px] truncate text-[12.5px] font-medium text-slate-600 dark:text-slate-300" title={emp.employee_level_label || undefined}>
                              {emp.employee_level_label || '—'}
                            </span>
                          </td>

                          {/* Branch */}
                          <td className="px-4 align-middle max-w-[160px]">
                            <span className="block truncate text-[12.5px] text-slate-500 dark:text-slate-400" title={emp.branch_name?.trim() || undefined}>
                              {emp.branch_name || '—'}
                            </span>
                          </td>

                          {/* Employment status — same canonical labels as Employee Profile (Employment tab) */}
                          <td className="px-4 align-middle w-[128px] max-w-[140px]">
                            <Badge
                              variant="outline"
                              className={employmentStatusBadgeClassName(emp.employment_status)}
                              title={formatEmploymentStatusForViewer(emp.employment_status, emp.employment_status_label, false)}
                            >
                              <span className="truncate">
                                {formatEmploymentStatusForViewer(emp.employment_status, emp.employment_status_label, false)}
                              </span>
                            </Badge>
                          </td>

                          {/* Hire Date */}
                          <td className="px-4 align-middle whitespace-nowrap">
                            <span className="text-[12.5px] text-slate-500 dark:text-slate-400">{emp.hire_date || '—'}</span>
                          </td>

                          {/* Leave credits: API snapshot + deriveAdminEmployeeListLeaveCredits (same dates as profile liveLeaveCreditsBlock) */}
                          <td className="px-4 align-middle whitespace-nowrap tabular-nums">
                            <span className="text-[12.5px] font-medium text-slate-700 dark:text-slate-200" title={leaveCreditsRow.title || undefined}>
                              <span className="tabular-nums">{leaveCreditsRow.fractionLabel}</span>
                              {leaveCreditsRow.showEligibleBadge ? (
                                <span className="ml-2 inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:border-emerald-500/35 dark:bg-emerald-500/15 dark:text-emerald-200">
                                  <CheckCircle2 className="size-2.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
                                  Eligible
                                </span>
                              ) : null}
                            </span>
                          </td>

                          {/* QR */}
                          <td className="px-4 align-middle">
                            <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                              {emp.has_qr ? (
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <button
                                      type="button"
                                      className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-teal-700 dark:text-teal-300 hover:text-teal-800 dark:hover:text-teal-200 transition-colors"
                                      title="QR actions"
                                    >
                                      <span className="size-2 rounded-full bg-teal-500 ring-2 ring-teal-500/25 inline-block shrink-0" />
                                      Issued
                                      <ChevronDown className="size-3 opacity-50" />
                                    </button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="start" className="w-48">
                                    <DropdownMenuItem onClick={() => showQr(emp)}>
                                      <Eye className="size-4 mr-2" />View QR
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onClick={() => handleDownloadQrFromTable(emp)}>
                                      <Download className="size-4 mr-2" />Download QR
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onClick={() => setRegenerateConfirmEmployee(emp)} className="text-amber-700 focus:text-amber-800">
                                      <RefreshCw className="size-4 mr-2" />Reissue QR
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={() => setClearQrConfirmEmployee(emp)} className="text-destructive focus:text-destructive">
                                      <Trash2 className="size-4 mr-2" />Delete QR
                                    </DropdownMenuItem>
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              ) : (
                                <button
                                  type="button"
                                  className="inline-flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground/70 hover:text-sky-600 dark:hover:text-sky-400 transition-colors"
                                  onClick={(e) => { e.stopPropagation(); generateOrRegenerateQr(emp) }}
                                >
                                  <span className="size-1.5 rounded-full bg-border inline-block" />
                                  Issue QR
                                </button>
                              )}
                            </div>
                          </td>

                          {/* Schedule — indigo dot + label */}
                          <td className="px-4 align-middle">
                            {hasAssignedSchedule(emp) ? (
                              <div className="flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-indigo-500 dark:bg-indigo-400 shrink-0 ring-2 ring-indigo-500/20 dark:ring-indigo-400/20" />
                                <span className="text-[12px] font-medium text-indigo-700 dark:text-indigo-300 truncate max-w-[120px]" title={getScheduleLabel(emp)}>
                                  {getScheduleLabel(emp)}
                                </span>
                              </div>
                            ) : (
                              <div className="flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-amber-400/70 shrink-0" />
                                <span className="text-[12px] text-amber-600/80 dark:text-amber-400/80">No schedule</span>
                              </div>
                            )}
                          </td>

                          {/* Face — dot + label + hover reveal Manage Face */}
                          <td className="px-4 align-middle">
                            <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                              {emp.has_face ? (
                                <div className="flex items-center gap-1.5">
                                  <ScanFace className="size-3.5 text-emerald-500 dark:text-emerald-400 shrink-0" />
                                  <span className="text-[12px] font-medium text-emerald-700 dark:text-emerald-400">Registered</span>
                                </div>
                              ) : (
                                <div className="flex items-center gap-1.5">
                                  <CircleDashed className="size-3.5 text-rose-500/60 dark:text-rose-400/60 shrink-0" />
                                  <span className="text-[12px] text-rose-600/70 dark:text-rose-400/70">Not registered</span>
                                </div>
                              )}
                              <button
                                type="button"
                                className="opacity-0 group-hover:opacity-100 transition-opacity text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap"
                                onClick={() => { setManageFaceEmployee(emp); setManageFaceOpen(true) }}
                              >
                                Manage
                              </button>
                            </div>
                          </td>

                          {/* Status — green dot (Active) / gray (Deactivated) */}
                          <td className="px-4 align-middle">
                            {emp.is_active ? (
                              <div className="inline-flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-green-500 shrink-0 ring-2 ring-green-500/25" />
                                <span className="text-[12px] font-semibold text-green-700 dark:text-green-400">Active</span>
                              </div>
                            ) : (
                              <div className="inline-flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-gray-400/60 dark:bg-gray-500/60 shrink-0" />
                                <span className="text-[12px] text-gray-500 dark:text-gray-400">Deactivated</span>
                              </div>
                            )}
                          </td>
                          <td className="px-5 align-middle">
                            <div className="flex items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
                              <div className="flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
                                  aria-label="View profile"
                                  onClick={(e) => { e.stopPropagation(); openPreview(emp) }}
                                >
                                  <Eye className="size-3.5" />
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
                                  aria-label="Business card"
                                  onClick={(e) => { e.stopPropagation(); openBusinessCard(emp) }}
                                >
                                  <IdCard className="size-3.5" />
                                </Button>
                                {canAssignSchedule && (
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
                                    aria-label="Assign schedule"
                                    onClick={(e) => { e.stopPropagation(); openSchedule(emp) }}
                                  >
                                    <Clock className="size-3.5" />
                                  </Button>
                                )}
                                {canEditEmployeeTarget(emp) && (
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
                                    aria-label={emp.is_active ? 'Deactivate' : 'Activate'}
                                    onClick={(e) => { e.stopPropagation(); handleToggleActive(emp) }}
                                    disabled={togglingId === emp.id}
                                  >
                                    {togglingId === emp.id ? (
                                      <Loader2 className="size-3.5 animate-spin" />
                                    ) : emp.is_active ? (
                                      <UserX className="size-3.5" />
                                    ) : (
                                      <UserCheck className="size-3.5" />
                                    )}
                                  </Button>
                                )}
                              </div>
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8 shrink-0 p-0"
                                    aria-label="More actions"
                                  >
                                    <MoreVertical className="size-4" />
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-52">
                                  <DropdownMenuItem onClick={() => openPreview(emp)}>
                                    <Eye className="size-4 mr-2" />
                                    {canEditEmployeeTarget(emp) ? 'Edit / View profile' : 'View profile'}
                                  </DropdownMenuItem>
                                  <DropdownMenuItem onClick={() => openBusinessCard(emp)}>
                                    <IdCard className="size-4 mr-2" />
                                    Business card
                                  </DropdownMenuItem>
                                  {canAssignSchedule && (
                                    <DropdownMenuItem onClick={() => openSchedule(emp)}>
                                      <Clock className="size-4 mr-2" />
                                      Assign Schedule
                                    </DropdownMenuItem>
                                  )}
                                  {emp.has_qr && (
                                    <DropdownMenuItem onClick={() => showQr(emp)}>
                                      <QrCode className="size-4 mr-2" />
                                      View QR
                                    </DropdownMenuItem>
                                  )}
                                  {!emp.has_qr && canEditEmployeeTarget(emp) && (
                                    <DropdownMenuItem onClick={() => generateOrRegenerateQr(emp)}>
                                      <QrCode className="size-4 mr-2" />
                                      Issue QR
                                    </DropdownMenuItem>
                                  )}
                                  {canEditEmployeeTarget(emp) && (
                                    <DropdownMenuItem
                                      onClick={() => {
                                        setManageFaceEmployee(emp)
                                        setManageFaceOpen(true)
                                      }}
                                    >
                                      <ScanFace className="size-4 mr-2" />
                                      Manage Face
                                    </DropdownMenuItem>
                                  )}
                                  {canPasswordReset && (
                                    <DropdownMenuItem
                                      onClick={async () => {
                                        setViewPasswordEmployee(emp)
                                        setViewPasswordValue('')
                                        setViewPasswordSource(null)
                                        setViewPasswordError(null)
                                        setViewPasswordCopied(false)
                                        setViewPasswordOpen(true)
                                        setViewPasswordLoading(true)
                                        try {
                                          const data = await getEmployeePassword(emp.id)
                                          setViewPasswordValue(data.password || '')
                                          setViewPasswordSource(data.source || null)
                                          if (data.source === 'stale' || !data.password) {
                                            setViewPasswordError(data.message || null)
                                          }
                                        } catch (e) {
                                          setViewPasswordError(e.message || 'Failed to load password')
                                        } finally {
                                          setViewPasswordLoading(false)
                                        }
                                      }}
                                    >
                                      <EyeOff className="size-4 mr-2" />
                                      View password
                                    </DropdownMenuItem>
                                  )}
                                  {canPasswordReset && (
                                    <DropdownMenuItem
                                      onClick={() => {
                                        setResetEmployee(emp)
                                        setResetPasswordValue('')
                                        setResetOpen(true)
                                      }}
                                    >
                                      <KeyRound className="size-4 mr-2" />
                                      Reset password
                                    </DropdownMenuItem>
                                  )}
                                  {canEditEmployeeTarget(emp) && (
                                    <DropdownMenuItem
                                      onClick={() => handleToggleActive(emp)}
                                      disabled={togglingId === emp.id}
                                      className={
                                        emp.is_active
                                          ? 'text-amber-700 data-highlighted:bg-amber-50 data-highlighted:text-amber-800 dark:data-highlighted:bg-amber-950/30'
                                          : 'text-emerald-700 data-highlighted:bg-emerald-50 data-highlighted:text-emerald-800 dark:data-highlighted:bg-emerald-950/30'
                                      }
                                    >
                                      {togglingId === emp.id ? (
                                        <Loader2 className="size-4 mr-2 animate-spin" />
                                      ) : emp.is_active ? (
                                        <UserX className="size-4 mr-2" />
                                      ) : (
                                        <UserCheck className="size-4 mr-2" />
                                      )}
                                      {emp.is_active ? 'Deactivate' : 'Activate'}
                                    </DropdownMenuItem>
                                  )}
                                  {canDeleteEmployeeTarget(emp) && (
                                    <>
                                      <DropdownMenuSeparator />
                                      <DropdownMenuItem
                                        onClick={() => setDeleteConfirmEmployee(emp)}
                                        className="text-destructive focus:text-destructive focus:bg-destructive/10"
                                      >
                                        <AlertTriangle className="size-4 mr-2" />
                                        Delete
                                      </DropdownMenuItem>
                                    </>
                                  )}
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </div>
                          </td>
                        </motion.tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
          {pagination.total > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 bg-card px-5 py-5 text-sm text-muted-foreground">
              <div className="font-medium">
                {(() => {
                  const { total, perPage } = pagination
                  const first = total === 0 ? 0 : (page - 1) * perPage + 1
                  const last = total === 0 ? 0 : Math.min(page * perPage, total)
                  return (
                    <span>
                      {total === 0 ? '0 of 0' : `${first}–${last} of ${total}`} employee{total !== 1 ? 's' : ''}
                    </span>
                  )
                })()}
              </div>
              <div className="flex items-center gap-4">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-10 rounded-md px-4 text-sm"
                  disabled={page <= 1}
                  onClick={() => fetchEmployees(page - 1)}
                >
                  Previous
                </Button>
                <span className="min-w-20 text-center text-sm font-medium text-foreground">
                  Page {page} of {pagination.lastPage || 1}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-10 rounded-md border-brand/70 px-4 text-sm font-bold text-brand hover:bg-brand/8 hover:text-brand"
                  disabled={page >= pagination.lastPage}
                  onClick={() => fetchEmployees(page + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </Card>

      {/* Manage Face — single modal for all face actions */}
      <Dialog
        open={manageFaceOpen}
        onOpenChange={(open) => {
          if (!open) {
            setManageFaceOpen(false)
            setManageFaceEmployee(null)
          }
        }}
      >
        <DialogContent
          className="max-w-xl rounded-2xl border-slate-200/90 bg-white p-0 shadow-[0_24px_70px_-34px_rgba(15,23,42,0.62)] dark:border-slate-800 dark:bg-card"
          innerClassName="gap-0 overflow-y-auto p-0"
          closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-300 bg-white text-slate-950 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-card dark:text-slate-50"
        >
          <DialogHeader className="px-5 pb-5 pt-5 pr-20 text-left sm:px-6 sm:pb-6 sm:pt-6">
            <DialogTitle className="flex items-center gap-4 text-2xl font-black tracking-tight text-slate-950 dark:text-slate-50">
              <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-orange-100/75 text-orange-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.8)] dark:bg-orange-500/10 dark:text-orange-300">
                <ScanFace className="size-7" strokeWidth={2.2} aria-hidden />
              </span>
              Manage face
            </DialogTitle>
            <DialogDescription className="ml-16 mt-1 text-sm leading-relaxed text-slate-500 dark:text-slate-400">
              {manageFaceEmployee && (
                <span className="font-black text-slate-950 dark:text-slate-50">{manageFaceEmployee.name}</span>
              )}
              {' — '}View, register, change, or remove face recognition.
            </DialogDescription>
          </DialogHeader>
          {manageFaceEmployee && (
            <div className="space-y-3 px-5 pb-5 sm:px-6">
              {manageFaceEmployee.has_face ? (
                <>
                  <button
                    type="button"
                    className="group flex w-full items-center gap-4 rounded-xl border border-slate-300 bg-white px-4 py-4 text-left transition-colors hover:border-slate-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-card dark:hover:bg-slate-900/40"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      openViewFace(emp)
                    }}
                  >
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-950 dark:bg-slate-800 dark:text-slate-50">
                      <Eye className="size-6" strokeWidth={2.4} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-base font-black text-slate-950 dark:text-slate-50">View face</span>
                      <span className="mt-1 block text-sm leading-relaxed text-slate-500 dark:text-slate-400">View the current face recognition.</span>
                    </span>
                    <ChevronRight className="size-5 shrink-0 text-slate-950 transition-transform group-hover:translate-x-0.5 dark:text-slate-50" strokeWidth={2.2} aria-hidden />
                  </button>
                  <button
                    type="button"
                    className="group flex w-full items-center gap-4 rounded-xl border border-slate-300 bg-white px-4 py-4 text-left transition-colors hover:border-slate-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-card dark:hover:bg-slate-900/40"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      openFaceRegister(emp)
                    }}
                  >
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-950 dark:bg-slate-800 dark:text-slate-50">
                      <RefreshCw className="size-6" strokeWidth={2.4} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-base font-black text-slate-950 dark:text-slate-50">Change face</span>
                      <span className="mt-1 block text-sm leading-relaxed text-slate-500 dark:text-slate-400">Update the current face recognition.</span>
                    </span>
                    <ChevronRight className="size-5 shrink-0 text-slate-950 transition-transform group-hover:translate-x-0.5 dark:text-slate-50" strokeWidth={2.2} aria-hidden />
                  </button>
                  <button
                    type="button"
                    className="group flex w-full items-center gap-4 rounded-xl border border-red-500 bg-red-50/45 px-4 py-4 text-left transition-colors hover:bg-red-50 dark:border-red-500/80 dark:bg-red-500/10 dark:hover:bg-red-500/15"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      setRemoveFaceConfirmEmployee(emp)
                    }}
                  >
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300">
                      <Trash2 className="size-6" strokeWidth={2.4} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-base font-black text-red-600 dark:text-red-300">Remove face</span>
                      <span className="mt-1 block text-sm leading-relaxed text-slate-500 dark:text-slate-400">Remove the current face recognition.</span>
                    </span>
                    <ChevronRight className="size-5 shrink-0 text-red-600 transition-transform group-hover:translate-x-0.5 dark:text-red-300" strokeWidth={2.2} aria-hidden />
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  className="group flex w-full items-center gap-4 rounded-xl border border-orange-500 bg-orange-50/60 px-4 py-4 text-left transition-colors hover:bg-orange-50 dark:border-orange-400/80 dark:bg-orange-500/10 dark:hover:bg-orange-500/15"
                  onClick={() => {
                    const emp = manageFaceEmployee
                    setManageFaceOpen(false)
                    setManageFaceEmployee(null)
                    openFaceRegister(emp)
                  }}
                >
                  <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                    <ScanFace className="size-6" strokeWidth={2.4} aria-hidden />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-base font-black text-orange-700 dark:text-orange-300">Register face</span>
                    <span className="mt-1 block text-sm leading-relaxed text-slate-500 dark:text-slate-400">Add face recognition for this employee.</span>
                  </span>
                  <ChevronRight className="size-5 shrink-0 text-orange-600 transition-transform group-hover:translate-x-0.5 dark:text-orange-300" strokeWidth={2.2} aria-hidden />
                </button>
              )}
            </div>
          )}
          <DialogFooter className="border-t border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-card sm:px-6">
            <Button
              variant="outline"
              className="h-10 min-w-24 rounded-lg border-slate-950 px-6 text-sm text-slate-950 hover:bg-slate-50 dark:border-slate-200 dark:text-slate-50 dark:hover:bg-slate-900/40"
              onClick={() => { setManageFaceOpen(false); setManageFaceEmployee(null); }}
            >
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Confirm change face (replace existing) */}
      <Dialog
        open={!!changeFaceConfirmEmployee}
        onOpenChange={(open) => !open && setChangeFaceConfirmEmployee(null)}
      >
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle>Replace registered face?</DialogTitle>
            <DialogDescription>
              Are you sure you want to replace the existing registered face? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setChangeFaceConfirmEmployee(null)}>
              Cancel
            </Button>
            <Button onClick={confirmChangeFace}>
              Confirm
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Confirm remove face */}
      <Dialog open={!!removeFaceConfirmEmployee} onOpenChange={(open) => !open && !faceRemoveSubmitting && setRemoveFaceConfirmEmployee(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Remove registered face?</DialogTitle>
            <DialogDescription>
              {removeFaceConfirmEmployee && (
                <>
                  This will remove the face data for <strong className="text-foreground">{removeFaceConfirmEmployee.name}</strong>.
                  They will not be able to use facial recognition for DTR login until a new face is registered.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRemoveFaceConfirmEmployee(null)} disabled={faceRemoveSubmitting}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleRemoveFace}
              disabled={faceRemoveSubmitting}
            >
              {faceRemoveSubmitting ? (
                <>
                  <Loader2 className="size-4 mr-2 animate-spin" />
                  Removing…
                </>
              ) : (
                <>
                  <Trash2 className="size-4 mr-2" />
                  Remove face
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Confirm clear QR */}
      <Dialog
        open={editEmployeeCodesOpen}
        onOpenChange={(open) => {
          if (editEmployeeCodesSaving) return
          setEditEmployeeCodesOpen(open)
          if (!open) {
            setEditEmployeeCodesDrafts({})
            setEditEmployeeCodesErrors({})
            setEditEmployeeCodesChecking({})
          }
        }}
      >
        <DialogContent className="max-w-lg gap-0 p-0 overflow-hidden">
          <DialogHeader className="border-b border-border/60 px-5 py-4 text-left">
            <DialogTitle className="flex items-center gap-2 text-lg">
              <IdCard className="size-5 text-muted-foreground" />
              Edit Employee ID
            </DialogTitle>
            <DialogDescription>
              Update Employee ID for {selectedIds.length} selected employee{selectedIds.length === 1 ? '' : 's'}. Prefix EMP- is fixed; numbers only.
            </DialogDescription>
          </DialogHeader>
          <div className="max-h-[min(60vh,28rem)] space-y-3 overflow-y-auto px-5 py-4">
            {selectedIds.map((id) => {
              const emp = employees.find((e) => e.id === id)
              const err = editEmployeeCodesErrors[id]
              const checking = Boolean(editEmployeeCodesChecking[id])
              const draftCode = composeEmployeeCode(editEmployeeCodesDrafts[id])
              const originalCode = composeEmployeeCode(emp?.employee_code)
              const unchanged = Boolean(draftCode) && draftCode.toLowerCase() === originalCode.toLowerCase()
              const available = !checking && !err && !unchanged && isValidEmployeeCode(draftCode)
              return (
                <div key={id} className="rounded-md border border-border/60 p-3">
                  <p className="mb-2 truncate text-sm font-semibold text-foreground">{emp?.name || `Employee #${id}`}</p>
                  <div
                    className={[
                      'flex h-9 items-stretch overflow-hidden rounded-md border bg-background',
                      err ? 'border-destructive' : available ? 'border-emerald-500/70' : 'border-input',
                    ].join(' ')}
                  >
                    <span className="inline-flex select-none items-center border-r border-input bg-muted/50 px-2.5 text-xs font-semibold tracking-wide text-muted-foreground">
                      {EMPLOYEE_CODE_PREFIX}
                    </span>
                    <input
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      autoComplete="off"
                      spellCheck={false}
                      className="min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none"
                      value={employeeCodeDigits(editEmployeeCodesDrafts[id])}
                      onChange={(e) => {
                        const next = composeEmployeeCode(e.target.value)
                        setEditEmployeeCodesDrafts((prev) => ({ ...prev, [id]: next }))
                        setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: true }))
                        setEditEmployeeCodesErrors((prev) => {
                          if (!prev[id]) return prev
                          const copy = { ...prev }
                          delete copy[id]
                          return copy
                        })
                      }}
                      onKeyDown={(e) => {
                        if (e.ctrlKey || e.metaKey || e.altKey) return
                        const allowed = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End']
                        if (allowed.includes(e.key)) return
                        if (!/^\d$/.test(e.key)) e.preventDefault()
                      }}
                      onPaste={(e) => {
                        e.preventDefault()
                        const next = composeEmployeeCode(e.clipboardData?.getData('text') || '')
                        setEditEmployeeCodesDrafts((prev) => ({ ...prev, [id]: next }))
                        setEditEmployeeCodesChecking((prev) => ({ ...prev, [id]: true }))
                      }}
                      placeholder="000123"
                      disabled={editEmployeeCodesSaving}
                      aria-invalid={Boolean(err)}
                    />
                  </div>
                  {checking ? (
                    <p className="mt-1.5 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                      <Loader2 className="size-3 animate-spin" />
                      Checking if Employee ID is available…
                    </p>
                  ) : err ? (
                    <p className="mt-1.5 text-xs font-medium text-destructive">{err}</p>
                  ) : available ? (
                    <p className="mt-1.5 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                      <CheckCircle2 className="size-3.5 shrink-0" />
                      Employee ID is available
                    </p>
                  ) : null}
                </div>
              )
            })}
          </div>
          <DialogFooter className="border-t border-border/60 px-5 py-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setEditEmployeeCodesOpen(false)}
              disabled={editEmployeeCodesSaving}
            >
              Cancel
            </Button>
            <Button type="button" onClick={saveBulkEmployeeCodes} disabled={editEmployeeCodesBusy}>
              {editEmployeeCodesSaving ? <Loader2 className="size-4 animate-spin" /> : 'Save Employee IDs'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!clearQrConfirmEmployee} onOpenChange={(open) => !open && setClearQrConfirmEmployee(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Delete QR code?</DialogTitle>
            <DialogDescription>
              {clearQrConfirmEmployee && (
                <>
                  Are you sure you want to delete the QR code for <strong className="text-foreground">{clearQrConfirmEmployee.name}</strong>?
                  They will no longer be able to scan for attendance until a new QR is generated.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex flex-row gap-3 @sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setClearQrConfirmEmployee(null)}
              disabled={clearQrSubmitting}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => clearQrConfirmEmployee && removeQr(clearQrConfirmEmployee)}
              disabled={clearQrSubmitting}
            >
              {clearQrSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4 mr-2" />}
              Delete QR
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* View face modal */}
      <Dialog open={viewFaceOpen} onOpenChange={(open) => !open && closeViewFace()}>
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl">
              <ScanFace className="size-5 text-primary" />
              Registered Face
            </DialogTitle>
            <DialogDescription>
              <span className="font-medium text-foreground">{viewFaceEmployee?.name}</span>
              {' — '}Face image used for recognition.
            </DialogDescription>
          </DialogHeader>
          {viewFaceLoading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="size-10 animate-spin text-muted-foreground" />
            </div>
          ) : viewFaceImage ? (
            <div className="flex justify-center rounded-lg border-2 border-border bg-muted/30 p-4">
              <img
                src={viewFaceImage}
                alt="Registered face"
                className="max-h-64 w-auto rounded-lg object-contain"
              />
            </div>
          ) : (
            <p className="py-8 text-center text-sm text-muted-foreground">
              {viewFaceMessage || 'Face is registered for attendance. Reference photo is not available.'}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={closeViewFace}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Register face */}
      <Dialog open={faceRegisterOpen} onOpenChange={(open) => !open && !faceRegisterSubmitting && closeFaceRegister()}>
        <DialogContent
          className="max-w-2xl gap-4 max-[760px]:left-0 max-[760px]:top-0 max-[760px]:h-[100dvh] max-[760px]:max-h-[100dvh] max-[760px]:w-screen max-[760px]:translate-x-0 max-[760px]:translate-y-0 max-[760px]:rounded-none"
          innerClassName="overflow-x-hidden max-[760px]:px-2 max-[760px]:pb-3 max-[760px]:pr-2 max-[760px]:pt-14"
        >
          <DialogHeader>
            <DialogTitle>{faceRegisterEmployee?.has_face ? 'Change face' : 'Register face'}</DialogTitle>
            <DialogDescription>
              {faceRegisterEmployee && (
                <>
                  {faceRegisterEmployee.has_face ? (
                    <>
                      Complete face verification for <strong className="text-foreground">{faceRegisterEmployee.name}</strong>. Existing face data will be replaced.
                    </>
                  ) : (
                    <>
                      Complete face verification for <strong className="text-foreground">{faceRegisterEmployee.name}</strong>. Embedding is encrypted and stored securely.
                    </>
                  )}
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <FaceVerificationLiveness
            key={faceRegisterRetryKey}
            onVerified={handleFaceRegisterVerified}
            onSuccess={closeFaceRegister}
            hideInstruction
            instructionText="Center your face, adjust forward or back as prompted, then hold still."
          />
          {faceRegisterSubmitting && (
            <div
              className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground"
              role="status"
              aria-live="polite"
            >
              <Loader2 className="size-4 shrink-0 animate-spin" />
              Registering face…
            </div>
          )}
          {faceRegisterError && (
            <div className="space-y-2">
              {faceRegisterErrorCode === 'face_already_registered' ? (
                <div className="flex items-start gap-3 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3" role="alert">
                  <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive" />
                  <div className="space-y-1">
                    <p className="text-sm font-semibold text-destructive">Duplicate Face Detected</p>
                    <p className="text-sm text-destructive/90">{faceRegisterError}</p>
                  </div>
                </div>
              ) : (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                  {faceRegisterError}
                </p>
              )}
              {faceRegisterErrorCode !== 'face_already_registered' && (
                <Button
                  type="button"
                  variant="secondary"
                  className="w-full"
                  disabled={faceRegisterSubmitting}
                  onClick={() => {
                    setFaceRegisterError(null)
                    setFaceRegisterErrorCode(null)
                    setFaceRegisterRetryKey((k) => k + 1)
                  }}
                >
                  Try again
                </Button>
              )}
            </div>
          )}
          <Button variant="outline" onClick={closeFaceRegister} disabled={faceRegisterSubmitting} className="w-full">
            Cancel
          </Button>
        </DialogContent>
      </Dialog>

      {/* Delete employee confirmation */}
      <Dialog open={!!deleteConfirmEmployee} onOpenChange={(open) => !open && setDeleteConfirmEmployee(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Delete employee?</DialogTitle>
            <DialogDescription>
              {deleteConfirmEmployee && (
                <>
                  Are you sure you want to delete{' '}
                  <strong className="text-foreground">{deleteConfirmEmployee.name}</strong>?
                  This will also remove their attendance logs.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setDeleteConfirmEmployee(null)}
              disabled={deleteSubmitting}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDeleteEmployee}
              disabled={deleteSubmitting}
            >
              {deleteSubmitting ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Trash2 className="size-4 mr-2" />
              )}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {addOpen && (
        <AdminAddEmployeeDialog
          open={addOpen}
          onOpenChange={setAddOpen}
          branches={branches}
          departments={departments}
          workingSchedules={workingSchedules}
          departmentsLoading={departmentsLoading}
          getSupervisorCandidatesByCompany={getSupervisorCandidatesByCompany}
          fetchEmployees={fetchEmployees}
        />
      )}

      {/* Employee business card */}
      <Dialog open={businessCardOpen} onOpenChange={(open) => !open && closeBusinessCard()}>
        <DialogContent
          className="max-h-[calc(100dvh-1rem)] w-[min(calc(100vw-1rem),30rem)] rounded-2xl border-slate-200/90 bg-white p-0 shadow-[0_26px_80px_-32px_rgba(15,23,42,0.7)] dark:border-slate-800 dark:bg-white"
          innerClassName="gap-0 overflow-y-auto p-0"
          closeButtonClassName="right-3 top-3 size-8 rounded-xl border-white/70 bg-white text-slate-950 shadow-lg hover:bg-white sm:right-4 sm:top-4"
        >
          {businessCardDetails && (
            <>
              <DialogHeader className="sr-only">
                <DialogTitle>Employee business card</DialogTitle>
                <DialogDescription>
                  Downloadable business card for {businessCardDetails.name}.
                </DialogDescription>
              </DialogHeader>
              <div className="bg-white text-slate-950" style={businessCardThemeStyle}>
                <div
                  className="relative h-32 overflow-hidden rounded-t-2xl bg-[var(--business-card-primary)] bg-cover bg-right text-white sm:h-36"
                  style={{ backgroundImage: `url(${BUSINESS_CARD_BUILDINGS_BG})` }}
                >
                  <div className="absolute inset-0 bg-[rgba(var(--business-card-primary-rgb),0.28)] mix-blend-multiply" aria-hidden />
                  <div className="absolute inset-0" style={{ background: 'var(--business-card-overlay)' }} aria-hidden />
                  <div className="absolute inset-0 opacity-20 [background-image:repeating-linear-gradient(132deg,transparent_0,transparent_25px,rgba(255,255,255,.2)_26px,transparent_27px)]" aria-hidden />
                  <div className="relative flex h-full items-start gap-3 px-5 pt-5 sm:px-6 sm:pt-7">
                    <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white p-2 shadow-lg sm:size-16">
                      {businessCardDetails.logoUrl ? (
                        <img
                          src={businessCardDetails.logoUrl}
                          alt=""
                          className="max-h-full max-w-full object-contain"
                          onError={(e) => { e.currentTarget.style.display = 'none' }}
                        />
                      ) : (
                        <span className="text-sm font-black text-[var(--business-card-primary)] sm:text-base">
                          {companyInitials(businessCardDetails.companyName)}
                        </span>
                      )}
                    </div>
                    <div className="mt-1.5 h-11 w-px shrink-0 bg-sky-200/80 sm:h-12" aria-hidden />
                    <div className="mt-1 min-w-0">
                      <p className="truncate pr-9 text-xl font-black leading-none tracking-normal sm:text-2xl">{companyInitials(businessCardDetails.companyName)}</p>
                      <p className="mt-2 max-w-[13rem] truncate text-sm font-light uppercase tracking-normal text-white/95 sm:max-w-[17rem] sm:text-base">
                        {businessCardDetails.branch || 'Branch not set'}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="bg-white px-5 pb-5 pt-0 text-slate-950 sm:px-6">
                  <div className="-mt-8 flex flex-col items-center sm:-mt-9">
                    <Avatar className="size-20 rounded-full border-[5px] border-white bg-[var(--business-card-primary)] shadow-xl ring-1 ring-slate-950/10 sm:size-24 sm:border-[6px]">
                      <AvatarImage src={businessCardDetails.avatarUrl} alt="" className="object-cover" />
                      <AvatarFallback className="rounded-full bg-[var(--business-card-primary)] text-2xl font-black text-white sm:text-3xl">
                        {businessCardDetails.initials}
                      </AvatarFallback>
                    </Avatar>
                    <h3 className="mt-3 max-w-full text-center text-[clamp(1.15rem,5vw,1.55rem)] font-black leading-tight tracking-normal text-slate-950 sm:mt-4">
                      {businessCardDetails.name}
                    </h3>
                    <p className="mt-1.5 max-w-full text-center text-[clamp(.75rem,3.4vw,.95rem)] font-medium uppercase tracking-normal text-[var(--business-card-primary)]">
                      {businessCardDetails.position || 'Position not set'}
                    </p>
                    <div className="mt-3 flex h-0.5 w-16 overflow-hidden rounded-full bg-[var(--business-card-primary)] sm:w-20">
                      <span className="h-full flex-1 bg-[var(--business-card-primary)]" />
                      <span className="h-full w-5 bg-[var(--business-card-accent)]" />
                    </div>
                  </div>

                  <div className="relative mt-4 space-y-0 border-l border-slate-200 pl-4 sm:pl-5">
                    <span className="absolute -left-[2px] top-0 h-9 w-1 rounded-full bg-[var(--business-card-primary)]" aria-hidden />
                    {[
                      { icon: IdCard, label: 'Employee ID', value: businessCardDetails.employeeCode || 'Not set' },
                      { icon: BriefcaseBusiness, label: 'Position', value: businessCardDetails.position || 'Not set' },
                      { icon: Mail, label: 'Work Email', value: businessCardDetails.email || 'Not set', breakAll: true },
                      { icon: Phone, label: 'Contact', value: businessCardDetails.phone || 'Not set' },
                      { icon: MapPin, label: 'Branch', value: businessCardDetails.branch || 'Not set' },
                    ].map((row, index, rows) => {
                      const Icon = row.icon
                      return (
                        <div
                          key={row.label}
                          className={[
                            'grid min-h-12 grid-cols-[1.5rem_minmax(0,1fr)] items-center gap-x-2 gap-y-1 py-1.5 sm:grid-cols-[1.75rem_6.25rem_minmax(0,1fr)] sm:py-0',
                            index < rows.length - 1 ? 'border-b border-slate-200' : '',
                          ].join(' ')}
                        >
                          <Icon className="size-4 text-[var(--business-card-primary)]" strokeWidth={2} />
                          <span className="text-[.68rem] font-medium uppercase tracking-normal text-[var(--business-card-text)] sm:text-xs">
                            {row.label}
                          </span>
                          <span className={`col-start-2 min-w-0 text-sm font-normal text-slate-950 sm:col-start-auto ${row.breakAll ? 'break-all' : 'break-words'}`}>
                            {row.value}
                          </span>
                        </div>
                      )
                    })}
                  </div>
                </div>
              </div>
              <DialogFooter className="grid grid-cols-2 gap-2 border-t border-slate-200 bg-white px-5 py-3.5 sm:flex sm:px-6">
                <Button
                  type="button"
                  variant="outline"
                  className="h-9 min-w-0 rounded-xl border-[var(--business-card-primary)] px-4 text-sm font-medium text-[var(--business-card-primary)] hover:bg-[var(--business-card-primary-soft)] hover:text-[var(--business-card-primary)] sm:min-w-20 sm:px-6"
                  onClick={closeBusinessCard}
                >
                  Close
                </Button>
                <Button
                  type="button"
                  className="h-9 min-w-0 rounded-xl bg-[var(--business-card-primary)] px-4 text-sm font-medium text-white shadow-lg hover:bg-[var(--business-card-primary-dark)] sm:min-w-32 sm:px-6"
                  onClick={() => downloadBusinessCard()}
                  disabled={businessCardDownloading}
                  title="Download business card as PNG"
                >
                  {businessCardDownloading ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                  Download
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Issue / view QR */}
      <Dialog open={qrOpen} onOpenChange={(open) => !open && closeQr()}>
        <DialogContent className="max-w-md gap-3">
          <DialogHeader className="flex flex-row items-start justify-between gap-3">
            <div>
              <DialogTitle className="flex items-center gap-2 text-xl">
                <QrCode className="size-5 text-primary" />
                Employee QR
              </DialogTitle>
              <DialogDescription>
                <span className="font-medium text-foreground">{qrEmployee?.name}</span>
                {' — '}Use this QR code for kiosk and employee attendance scanning.
              </DialogDescription>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="mt-1 h-8 w-8 shrink-0 rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
              onClick={closeQr}
            >
              <X className="size-4" />
              <span className="sr-only">Close</span>
            </Button>
          </DialogHeader>

          {qrLoading ? (
            <div className="flex items-center justify-center rounded-lg border bg-muted py-10">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          ) : qrToken ? (
            <div className="flex flex-col items-center gap-4 rounded-xl border-2 border-border bg-white p-6">
              <div ref={qrCanvasRef} className="rounded-lg bg-white p-3 shadow-inner ring-1 ring-black/5">
                <QRCodeCanvas
                  value={qrToken}
                  size={280}
                  level="H"
                  includeMargin
                  style={{ imageRendering: 'pixelated' }}
                  imageSettings={
                    qrCompanyLogoUrl
                      ? {
                          src: qrCompanyLogoUrl,
                          height: 56,
                          width: 56,
                          excavate: true,
                        }
                      : undefined
                  }
                />
              </div>
              <p className="break-all rounded-md bg-muted/50 px-3 py-2 font-mono text-xs text-muted-foreground">
                {qrToken}
              </p>
            </div>
          ) : (
            <div className="rounded-lg border bg-muted px-4 py-6 text-center text-sm text-muted-foreground">
              No QR token generated yet.
            </div>
          )}

          <DialogFooter className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={closeQr}>
              Close
            </Button>
            {qrEmployee && qrToken && (
              <Button
                type="button"
                variant="outline"
                onClick={() => downloadQrFromCanvas(qrEmployee.name)}
                disabled={qrLoading}
                title="Download QR as PNG"
              >
                <Download className="size-4" />
                Download
              </Button>
            )}
            {qrEmployee && (
              <Button
                type="button"
                variant="outline"
                className={qrToken ? 'border-amber-400 bg-amber-50 text-amber-800 hover:bg-amber-100' : ''}
                onClick={() => {
                  if (qrToken) {
                    setRegenerateConfirmEmployee(qrEmployee)
                  } else {
                    generateOrRegenerateQr(qrEmployee)
                  }
                }}
                disabled={qrLoading}
              >
                {qrLoading ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <RefreshCw className="size-4 mr-1.5" />
                )}
                {qrToken ? 'Regenerate QR' : 'Generate QR'}
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Hidden QR canvas for table-row download */}
      {pendingQrDownload && (
        <div
          ref={hiddenQrRef}
          className="pointer-events-none fixed -left-[9999px] top-0 opacity-0"
          aria-hidden
        >
          <QRCodeCanvas
            value={pendingQrDownload.token}
            size={256}
            level="H"
            includeMargin
            imageSettings={
              pendingQrDownload.companyLogoUrl
                ? {
                    src: pendingQrDownload.companyLogoUrl,
                    height: 48,
                    width: 48,
                    excavate: true,
                  }
                : undefined
            }
          />
        </div>
      )}

      <ImportEmployeesModal
        open={importOpen}
        onOpenChange={setImportOpen}
        toast={toast}
        canUndoImport={canDeleteEmployees}
        onImported={() => queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })}
      />

      {/* Regenerate QR confirmation */}
      <Dialog
        open={!!regenerateConfirmEmployee}
        onOpenChange={(open) => {
          if (!open) {
            setRegenerateConfirmEmployee(null)
          }
        }}
      >
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-amber-700">
              <AlertTriangle className="size-5" />
              Regenerate QR code?
            </DialogTitle>
            <DialogDescription>
              {regenerateConfirmEmployee && (
                <>
                  This will <strong>invalidate the current QR code</strong> for{' '}
                  <span className="font-medium text-foreground">{regenerateConfirmEmployee.name}</span>.
                  Any printed or saved copies will stop working.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex flex-wrap gap-2 @sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setRegenerateConfirmEmployee(null)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={async () => {
                if (!regenerateConfirmEmployee) return
                try {
                  await generateOrRegenerateQr(regenerateConfirmEmployee)
                } finally {
                  setRegenerateConfirmEmployee(null)
                }
              }}
            >
              <RefreshCw className="mr-2 size-4" />
              Regenerate QR
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <EmployeeScheduleAssignDialog
        open={scheduleOpen}
        onOpenChange={(open) => {
          setScheduleOpen(open)
          if (!open) {
            setScheduleEmployee(null)
            setBulkScheduleIds([])
          }
        }}
        employee={scheduleEmployee}
        bulkEmployeeIds={bulkScheduleIds}
        employees={employees}
        workingSchedules={workingSchedules}
        onWorkingSchedulesUpdated={setWorkingSchedules}
        canManageSchedules={canManageSchedules}
        onSuccess={async () => {
          await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
          await fetchEmployees()
        }}
      />

      <ScheduleAdjustmentDialog
        open={adjustmentOpen}
        onOpenChange={(open) => {
          setAdjustmentOpen(open)
          if (!open) setAdjustmentEmployeeIds([])
        }}
        schedules={workingSchedules}
        initialEmployeeIds={adjustmentEmployeeIds}
        onApplied={async () => {
          await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
          await fetchEmployees()
          setSelectedIds([])
        }}
      />

      {/* Deactivate confirmation */}
      <Dialog
        open={deactivateOpen}
        onOpenChange={(open) => {
          if (!open) {
            setDeactivateOpen(false)
            setDeactivateEmployee(null)
          }
        }}
      >
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-amber-600 dark:text-amber-500">
              <AlertTriangle className="size-5" />
              Deactivate employee
            </DialogTitle>
            <DialogDescription>
              <span className="font-medium text-foreground">{deactivateEmployee?.name}</span>
              {' — '}They will not be able to log in until an admin activates the account again.
            </DialogDescription>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            Are you sure you want to deactivate this employee? You can reactivate them anytime from the list.
          </p>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setDeactivateOpen(false)
                setDeactivateEmployee(null)
              }}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={togglingId === deactivateEmployee?.id}
              onClick={() => deactivateEmployee && doToggleActive(deactivateEmployee)}
            >
              {togglingId === deactivateEmployee?.id ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <UserX className="size-4" />
              )}
              Deactivate
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* View password */}
      <Dialog
        open={viewPasswordOpen}
        onOpenChange={(open) => {
          if (!open) {
            setViewPasswordOpen(false)
            setViewPasswordEmployee(null)
            setViewPasswordValue('')
            setViewPasswordSource(null)
            setViewPasswordError(null)
            setViewPasswordCopied(false)
            setViewPasswordLoading(false)
          }
        }}
      >
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl">
              <EyeOff className="size-5 text-primary" />
              View password
            </DialogTitle>
            <DialogDescription>
              <span className="font-medium text-foreground">{viewPasswordEmployee?.name}</span>
              {' — '}Current recoverable password (updated when the employee or admin changes it).
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-3">
            {viewPasswordLoading ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading password…
              </div>
            ) : viewPasswordSource === 'stale' ? (
              <div className="space-y-2">
                <p className="text-sm text-amber-800 dark:text-amber-300">
                  {viewPasswordError
                    || 'This employee changed their password, so the old recoverable copy no longer matches.'}
                </p>
                <p className="text-xs text-muted-foreground">
                  Use Reset password to set a new viewable password.
                </p>
              </div>
            ) : viewPasswordError ? (
              <p className="text-sm text-destructive">{viewPasswordError}</p>
            ) : (
              <>
                <div className="grid gap-2">
                  <Label htmlFor="view-password">Password</Label>
                  <div className="flex gap-2">
                    <div className="min-w-0 flex-1">
                      <PasswordInput
                        id="view-password"
                        value={viewPasswordValue}
                        readOnly
                        className="h-9 w-full"
                      />
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      className="h-9 w-9 shrink-0"
                      disabled={!viewPasswordValue || viewPasswordSource === 'unset'}
                      aria-label="Copy password"
                      onClick={async () => {
                        try {
                          await navigator.clipboard.writeText(viewPasswordValue)
                          setViewPasswordCopied(true)
                          toast({ title: 'Password copied', variant: 'success' })
                          window.setTimeout(() => setViewPasswordCopied(false), 1500)
                        } catch {
                          toast({ title: 'Could not copy password', variant: 'destructive' })
                        }
                      }}
                    >
                      {viewPasswordCopied ? <Check className="size-4" /> : <Copy className="size-4" />}
                    </Button>
                  </div>
                </div>
                {viewPasswordSource === 'unset' && (
                  <p className="text-xs text-muted-foreground">
                    No recoverable password is stored. Use Reset password to set one.
                  </p>
                )}
                {viewPasswordSource === 'import_default' && (
                  <p className="text-xs text-muted-foreground">
                    Showing the default import password for this employee.
                  </p>
                )}
              </>
            )}
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setViewPasswordOpen(false)
                setViewPasswordEmployee(null)
                setViewPasswordValue('')
                setViewPasswordSource(null)
                setViewPasswordError(null)
                setViewPasswordCopied(false)
              }}
            >
              Close
            </Button>
            {canPasswordReset && viewPasswordEmployee && (
              <Button
                type="button"
                onClick={() => {
                  const emp = viewPasswordEmployee
                  setViewPasswordOpen(false)
                  setViewPasswordEmployee(null)
                  setViewPasswordValue('')
                  setViewPasswordSource(null)
                  setViewPasswordError(null)
                  setResetEmployee(emp)
                  setResetPasswordValue('')
                  setResetOpen(true)
                }}
              >
                Reset password
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reset password */}
      <Dialog
        open={resetOpen}
        onOpenChange={(open) => {
          if (!open) {
            setResetOpen(false)
            setResetEmployee(null)
            setResetPasswordValue('')
          }
        }}
      >
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl">
              <KeyRound className="size-5 text-primary" />
              Reset password
            </DialogTitle>
            <DialogDescription>
              <span className="font-medium text-foreground">{resetEmployee?.name}</span>
              {' — '}Set a new password for this employee.
            </DialogDescription>
          </DialogHeader>
          <form
            onSubmit={async (e) => {
              e.preventDefault()
              if (!resetEmployee || !resetPasswordValue) return
              setResetSubmitting(true)
              setError(null)
              try {
                await resetEmployeePassword(resetEmployee.id, resetPasswordValue)
                setResetOpen(false)
                setResetEmployee(null)
                setResetPasswordValue('')
                toast({
                  title: 'Password reset successfully',
                  description: `${resetEmployee.name} can now sign in with the new password.`,
                  variant: 'success',
                })
              } catch (e) {
                setError(e.message)
                toast({ title: 'Failed to reset password', description: e.message, variant: 'destructive' })
              } finally {
                setResetSubmitting(false)
              }
            }}
            className="flex flex-col gap-4"
          >
            <div className="grid gap-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="reset-password">New password</Label>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 text-xs text-muted-foreground hover:text-foreground"
                  onClick={() => {
                    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%'
                    let pwd = ''
                    for (let i = 0; i < 12; i++) pwd += chars[Math.floor(Math.random() * chars.length)]
                    setResetPasswordValue(pwd)
                  }}
                >
                  Generate
                </Button>
              </div>
              <PasswordInput
                id="reset-password"
                value={resetPasswordValue}
                onChange={(e) => setResetPasswordValue(e.target.value)}
                minLength={8}
                placeholder="Min. 8 characters"
                className="h-9"
                required
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setResetOpen(false)
                  setResetEmployee(null)
                  setResetPasswordValue('')
                }}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={resetSubmitting}>
                {resetSubmitting ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
                Save new password
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Employee profile — side panel (row click or Actions → Edit / View profile) */}
      <Sheet
        open={previewOpen}
        onOpenChange={(open) => {
          if (!open) {
            setPreviewOpen(false)
            setPreviewEmployee(null)
            setPreviewSummary(null)
            setActiveEmployeeId(null)
          }
        }}
      >
        <SheetContent side="right" className="flex w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl lg:max-w-4xl">
          <SheetHeader className="border-b border-border/50 bg-muted/30 px-6 py-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Employee Profile</p>
            <div className="flex items-center gap-4">
              {previewEmployee && (
                <Avatar className="size-14 shrink-0 rounded-full border-2 border-background shadow-sm">
                  <AvatarImage
                    src={profileImageUrl(previewEmployee.profile_image)}
                    alt=""
                    className="object-cover"
                  />
                  <AvatarFallback className={`rounded-full text-sm font-semibold ${getAvatarColor(previewEmployee.id, previewEmployee.name)}`}>
                    {(previewEmployee.name || '?')
                      .trim()
                      .split(/\s+/)
                      .map((n) => n[0])
                      .join('')
                      .toUpperCase()
                      .slice(0, 2) || '?'}
                  </AvatarFallback>
                </Avatar>
              )}
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <SheetTitle className="text-lg font-semibold tracking-tight text-foreground">
                    {previewEmployee?.name || 'Employee'}
                  </SheetTitle>
                  {previewEmployee && <RoleBadge user={previewEmployee} size="sm" />}
                </div>
                <SheetDescription className="mt-0.5 text-sm text-foreground">
                  {previewEmployee?.position || 'No position assigned'}
                </SheetDescription>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {previewEmployee?.department || 'No department assigned'}
                </p>
                <div className="mt-2 space-y-1 text-xs">
                  <p className="text-muted-foreground">Email: {previewEmployee?.email || '—'}</p>
                  <p className="text-muted-foreground">Phone: {previewEmployee?.phone_number || '—'}</p>
                  <p className={previewEmployee?.is_active ? 'font-medium text-emerald-600 dark:text-emerald-500' : 'font-medium text-muted-foreground'}>
                    Status: {previewEmployee?.is_active ? 'Active' : 'Deactivated'}
                  </p>
                </div>
              </div>
            </div>
          </SheetHeader>
          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
            <div className="space-y-8">
              <section className="rounded-lg border border-border/50 bg-muted/20 px-5 py-5">
                <div className="mb-6 rounded-md border border-border/50 bg-background/70 p-4">
                  <div className="mb-3 flex items-center gap-3">
                    <div className="h-px flex-1 bg-border" />
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Overview</h3>
                    <div className="h-px flex-1 bg-border" />
                  </div>
                  <div className="grid grid-cols-1 gap-3 @sm:grid-cols-2 @lg:grid-cols-3 text-sm">
                    <p><span className="text-muted-foreground">Employee ID:</span> <span className="font-medium text-foreground">{previewEmployee?.employee_code || previewEmployee?.employee_id || (previewEmployee?.id ? `ID-${previewEmployee.id}` : '—')}</span></p>
                    <p><span className="text-muted-foreground">Company:</span> <span className="font-medium text-foreground">{previewEmployee?.company_name || '—'}</span></p>
                    <p><span className="text-muted-foreground">Position:</span> <span className="font-medium text-foreground">{previewEmployee?.position || '—'}</span></p>
                    <p><span className="text-muted-foreground">Hire Date:</span> <span className="font-medium text-foreground">{previewEmployee?.hire_date || '—'}</span></p>
                    <p>
                      <span className="text-muted-foreground">Leave credits:</span>{' '}
                      <span className="font-medium tabular-nums text-foreground">
                        {previewEmployee
                          ? (() => {
                              const plc = deriveAdminEmployeeListLeaveCredits(previewEmployee)
                              return (
                                <>
                                  {plc.fractionLabel}
                                  {plc.showEligibleBadge ? (
                                    <span className="ml-2 inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-200">
                                      <CheckCircle2 className="size-2.5 shrink-0" aria-hidden />
                                      Eligible
                                    </span>
                                  ) : null}
                                </>
                              )
                            })()
                          : '—'}
                      </span>
                    </p>
                    <p><span className="text-muted-foreground">Supervisor:</span> <span className="font-medium text-foreground">{previewEmployee?.supervisor_name || '—'}</span></p>
                    <p><span className="text-muted-foreground">Work Schedule:</span> <span className="font-medium text-foreground">{getScheduleLabel(previewEmployee)}</span></p>
                  </div>
                </div>
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="text-sm font-semibold tracking-wide text-foreground">Personal Information</h3>
                </div>
                <div className="mb-4 flex items-center gap-3">
                  <div className="h-px flex-1 bg-border" />
                  <span className="text-[10px] uppercase tracking-wider text-muted-foreground">Personal Information</span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <p className="mb-3 text-xs text-muted-foreground">
                  Primary identity record. Basic fields are captured during Add Employee, while additional details are completed here.
                </p>
                <div className="space-y-8">
                  <div className="rounded-md border border-border/50 bg-background/60 p-4">
                    <div className="mb-4 flex items-center gap-3">
                      <div className="h-px flex-1 bg-border" />
                      <span className="text-[10px] uppercase tracking-wider text-muted-foreground">Basic Information</span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                    <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">First Name</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="Enter first name"
                          value={personalInfoForm.first_name}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, first_name: e.target.value }))}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Last Name</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="Enter last name"
                          value={personalInfoForm.last_name}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, last_name: e.target.value }))}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Username</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="e.g., neziahpaul or npbernabé"
                          value={personalInfoForm.username}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, username: e.target.value }))}
                          required
                        />
                        <p className="mt-2 text-xs text-muted-foreground">Used for login along with email</p>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Email Address</label>
                        <Input
                          type="email"
                          className="h-9 text-sm"
                          placeholder="you@company.com"
                          value={personalInfoForm.email}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, email: e.target.value }))}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Contact Number (Mobile)</label>
                        <Input
                          type="tel"
                          className="h-9 text-sm"
                          placeholder="+63 912 345 6789 or 09123456789"
                          value={personalInfoForm.phone_number}
                          onChange={(e) =>
                            setPersonalInfoForm((f) => ({ ...f, phone_number: e.target.value.replace(/[^\d+\s]/g, '') }))
                          }
                          required
                        />
                      </div>
                    </div>
                  </div>

                  <div className="rounded-md border border-border/50 bg-background/60 p-4">
                    <div className="mb-4 flex items-center gap-3">
                      <div className="h-px flex-1 bg-border" />
                      <span className="text-[10px] uppercase tracking-wider text-muted-foreground">Personal Details</span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                    <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Middle Name</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="Enter middle name"
                          value={personalInfoForm.middle_name}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, middle_name: e.target.value }))}
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Suffix</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="e.g. Jr."
                          value={personalInfoForm.suffix}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, suffix: e.target.value }))}
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Date of Birth</label>
                        <Input
                          type="date"
                          className="h-9 text-sm dark:[color-scheme:dark]"
                          value={personalInfoForm.date_of_birth}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, date_of_birth: e.target.value }))}
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Gender</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.gender}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, gender: e.target.value }))}
                        >
                          <option value="">Select gender</option>
                          <option value="Male">Male</option>
                          <option value="Female">Female</option>
                          <option value="Other">Other</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Civil Status</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.civil_status}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, civil_status: e.target.value }))}
                        >
                          <option value="">Select civil status</option>
                          <option value="Single">Single</option>
                          <option value="Married">Married</option>
                          <option value="Widowed">Widowed</option>
                          <option value="Separated">Separated</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Nationality</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="e.g. Filipino"
                          value={personalInfoForm.nationality}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, nationality: e.target.value }))}
                        />
                      </div>
                      <div className="@sm:col-span-2">
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Home Address</label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="Street, Barangay, City, Province"
                          value={personalInfoForm.home_address}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, home_address: e.target.value }))}
                        />
                      </div>
                    </div>
                  </div>

                  <div className="rounded-md border border-border/50 bg-background/60 p-4">
                    <div className="mb-4 flex items-center gap-3">
                      <div className="h-px flex-1 bg-border" />
                      <span className="text-[10px] uppercase tracking-wider text-muted-foreground">Employment Details</span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                    <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Employee ID</label>
                        <div className="flex h-9 items-stretch overflow-hidden rounded-md border border-input bg-background">
                          <span className="inline-flex select-none items-center border-r border-input bg-muted/50 px-2.5 text-xs font-semibold tracking-wide text-muted-foreground">
                            {EMPLOYEE_CODE_PREFIX}
                          </span>
                          <input
                            type="text"
                            inputMode="numeric"
                            pattern="[0-9]*"
                            autoComplete="off"
                            spellCheck={false}
                            className="min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none"
                            value={employeeCodeDigits(personalInfoForm.employee_code)}
                            onChange={(e) => setPersonalInfoForm((f) => ({ ...f, employee_code: composeEmployeeCode(e.target.value) }))}
                            onKeyDown={(e) => {
                              if (e.ctrlKey || e.metaKey || e.altKey) return
                              const allowed = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End']
                              if (allowed.includes(e.key)) return
                              if (!/^\d$/.test(e.key)) e.preventDefault()
                            }}
                            onPaste={(e) => {
                              e.preventDefault()
                              setPersonalInfoForm((f) => ({ ...f, employee_code: composeEmployeeCode(e.clipboardData?.getData('text') || '') }))
                            }}
                            placeholder="000123"
                          />
                        </div>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Branch</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.branch_id}
                          onChange={(e) => { const bid = e.target.value; setPersonalInfoForm((f) => ({ ...f, branch_id: bid, department_id: '' })) }}
                          disabled={departmentsLoading}
                        >
                          <option value="">Select branch (optional)</option>
                          {branches.map((b) => (
                            <option key={b.id} value={b.id}>{b.name}{b.company_name ? ` — ${b.company_name}` : ''}</option>
                          ))}
                        </select>
                        {personalInfoForm.branch_id && (() => { const b = branches.find((x) => String(x.id) === String(personalInfoForm.branch_id)); return b?.company_name ? (<p className="mt-1 text-xs text-muted-foreground">Company: <span className="font-medium text-foreground">{b.company_name}</span></p>) : null })()}
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Department</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.department_id}
                          onChange={(e) => handleProfileDepartmentChange(e.target.value)}
                          disabled={departmentsLoading}
                          title={departments.find((d) => String(d.id) === String(personalInfoForm.department_id))?.name || ''}
                        >
                          <option value="">Select department</option>
                          {(personalInfoForm.branch_id ? departments.filter((d) => String(d.branch_id) === String(personalInfoForm.branch_id)) : departments).map((dept) => (
                            <option key={dept.id} value={dept.id}>{dept.name}</option>
                          ))}
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Position</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.position}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, position: e.target.value }))}
                        >
                          <option value="">Select position</option>
                          {Array.from(
                            new Set(
                              employees
                                .map((emp) => String(emp.position || '').trim())
                                .filter(Boolean)
                                .concat(personalInfoForm.position ? [String(personalInfoForm.position).trim()] : [])
                            )
                          )
                            .sort((a, b) => a.localeCompare(b))
                            .map((position) => (
                              <option key={position} value={position}>
                                {position}
                              </option>
                            ))}
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Office Location <span className="font-normal">(optional)</span></label>
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          placeholder="e.g. 3rd Floor, Tower 2"
                          value={personalInfoForm.branch_office_location}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, branch_office_location: e.target.value }))}
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Employment Type</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.employment_type}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, employment_type: e.target.value }))}
                        >
                          <option value="">Select employment type</option>
                          <option value="full_time">Full-time</option>
                          <option value="part_time">Part-time</option>
                          <option value="contract">Contract</option>
                          <option value="probationary">Probationary</option>
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Hire Date</label>
                        <Input
                          type="date"
                          className="h-9 text-sm dark:[color-scheme:dark]"
                          value={personalInfoForm.hire_date}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, hire_date: e.target.value }))}
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Payroll Effective Date</label>
                        <Input
                          type="date"
                          className="h-9 text-sm dark:scheme-dark"
                          value={personalInfoForm.payroll_effective_date}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, payroll_effective_date: e.target.value }))}
                        />
                        <p className="mt-1 text-[11px] text-muted-foreground">Defaults to the employee created date if blank.</p>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Supervisor</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.supervisor_id}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, supervisor_id: e.target.value }))}
                        >
                          <option value="">Select supervisor</option>
                          {profileSupervisorOptions.length === 0 && (
                            <option value="" disabled>No managerial supervisor available for selected department</option>
                          )}
                          {profileSupervisorOptions
                            .map((emp) => (
                              <option key={emp.id} value={emp.id}>
                                {emp.name} {emp.position ? `(${emp.position})` : ''}
                              </option>
                            ))}
                        </select>
                      </div>
                      <div>
                        <label className="mb-2 block text-xs font-medium text-muted-foreground">Work Schedule</label>
                        <select
                          className={FIELD_SELECT_CLASS}
                          value={personalInfoForm.working_schedule_id}
                          onChange={(e) => setPersonalInfoForm((f) => ({ ...f, working_schedule_id: e.target.value }))}
                        >
                          <option value="">Select work schedule</option>
                          {workingSchedules.map((s) => (
                            <option key={s.id} value={s.id}>{s.name}</option>
                          ))}
                        </select>
                      </div>
                    </div>
                  </div>

                  <div className="rounded-md border border-border/50 bg-background/60 p-4">
                    <div className="mb-4 flex items-center gap-3">
                      <div className="h-px flex-1 bg-border" />
                      <span className="text-[10px] uppercase tracking-wider text-muted-foreground">Profile Photo</span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                    <div className="flex items-center gap-3">
                      <Avatar className="size-14 rounded-full border border-border/60">
                        <AvatarImage src={profileImageUrl(previewEmployee?.profile_image)} alt="" className="object-cover" />
                        <AvatarFallback className={`rounded-full text-sm font-semibold ${getAvatarColor(previewEmployee?.id, previewEmployee?.name)}`}>
                          {(previewEmployee?.name || '?')
                            .trim()
                            .split(/\s+/)
                            .map((n) => n[0])
                            .join('')
                            .toUpperCase()
                            .slice(0, 2) || '?'}
                        </AvatarFallback>
                      </Avatar>
                      <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                          className="h-8 text-xs"
                          disabled={profilePhotoUploading || !canEditEmployeeTarget(previewEmployee)}
                          onClick={() => profilePhotoInputRef.current?.click()}
                        >
                          <Upload className="size-3.5 mr-1.5" />
                          Upload / Replace
                        </Button>
                        <Input
                          id="profile-photo-upload"
                          ref={profilePhotoInputRef}
                          type="file"
                          accept="image/png,image/jpeg,image/jpg,image/webp,image/gif"
                          className="hidden"
                          disabled={profilePhotoUploading || !canEditEmployeeTarget(previewEmployee)}
                          onChange={async (e) => {
                            const file = e.target.files?.[0]
                            if (!file || !previewEmployee || !canEditEmployeeTarget(previewEmployee)) return
                            setProfilePhotoUploading(true)
                        setError(null)
                        try {
                              const data = await uploadEmployeePhoto(previewEmployee.id, file)
                              const emp = normalizeEmployeeFlags(data.employee)
                              setEmployees((prev) => prev.map((item) => (item.id === previewEmployee.id ? normalizeEmployeeFlags({ ...item, ...emp }) : item)))
                              setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? normalizeEmployeeFlags({ ...p, ...emp }) : p))
                              toast({
                                title: 'Profile photo updated',
                                description: `${previewEmployee.name}'s photo was uploaded successfully.`,
                                variant: 'success',
                              })
                            } catch (err) {
                              setError(err.message)
                              toast({
                                title: 'Photo upload failed',
                                description: err.message,
                                variant: 'error',
                              })
                            } finally {
                              setProfilePhotoUploading(false)
                              e.target.value = ''
                            }
                          }}
                        />
                        <div className="w-full rounded-md border border-dashed border-border/70 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                          Drag image here or browse file
                        </div>
                        {previewEmployee?.profile_image && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs"
                            disabled={profilePhotoUploading || !canEditEmployeeTarget(previewEmployee)}
                            onClick={async () => {
                              if (!previewEmployee || !canEditEmployeeTarget(previewEmployee)) return
                              setProfilePhotoUploading(true)
                              setError(null)
                              try {
                                const data = await removeEmployeePhoto(previewEmployee.id)
                          const emp = normalizeEmployeeFlags(data.employee)
                                setEmployees((prev) => prev.map((item) => (item.id === previewEmployee.id ? normalizeEmployeeFlags({ ...item, ...emp }) : item)))
                                setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? normalizeEmployeeFlags({ ...p, ...emp }) : p))
                                toast({
                                  title: 'Profile photo removed',
                                  description: `${previewEmployee.name}'s photo was removed.`,
                                  variant: 'success',
                                })
                              } catch (err) {
                                setError(err.message)
                                toast({
                                  title: 'Remove photo failed',
                                  description: err.message,
                                  variant: 'error',
                                })
                        } finally {
                                setProfilePhotoUploading(false)
                        }
                      }}
                    >
                            Remove Photo
                    </Button>
                  )}
                        {profilePhotoUploading && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                </div>
                  </div>
                  </div>
                </div>
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <div className="mb-1.5 flex items-center justify-between">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Schedule</h3>
                  {previewEmployee && canEditEmployeeTarget(previewEmployee) && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 text-xs text-muted-foreground hover:text-foreground border-none shadow-none outline-none bg-transparent hover:bg-transparent focus-visible:ring-0 focus-visible:ring-offset-0"
                      onClick={() => openSchedule(previewEmployee)}
                    >
                      Edit
                    </Button>
                  )}
                </div>
                <p className="text-sm font-medium text-foreground">
                  {getScheduleLabel(previewEmployee)}
                </p>
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">QR code</h3>
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-medium text-foreground">
                    {previewEmployee?.has_qr ? 'Issued' : 'Not issued'}
                  </p>
                  {previewEmployee && canEditEmployeeTarget(previewEmployee) && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className={`h-8 text-xs ${
                        previewEmployee.has_qr
                          ? 'border-amber-400 bg-amber-50 text-amber-800 hover:bg-amber-100'
                          : ''
                      }`}
                      onClick={() => {
                        if (previewEmployee.has_qr) {
                          setRegenerateConfirmEmployee(previewEmployee)
                        } else {
                          generateOrRegenerateQr(previewEmployee)
                        }
                      }}
                    >
                      {previewEmployee.has_qr ? 'Regenerate QR' : 'Generate QR'}
                    </Button>
                  )}
                </div>
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-gray-400">
                  Registered Face
                </h3>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <p className="text-sm font-medium text-foreground">
                    {previewEmployee?.has_face ? 'Face Registered' : 'Not registered'}
                  </p>
                  {previewEmployee && canEditEmployeeTarget(previewEmployee) && (
                    <div className="flex flex-wrap items-center gap-2">
                      {previewEmployee.has_face ? (
                        <>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs"
                            onClick={() => openViewFace(previewEmployee)}
                          >
                            <Eye className="size-3.5 mr-1.5" />
                            View Face
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs"
                            onClick={() => openFaceRegister(previewEmployee)}
                          >
                            <RefreshCw className="size-3.5 mr-1.5" />
                            Change Face
                          </Button>
                        </>
                      ) : (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 text-xs"
                          onClick={() => openFaceRegister(previewEmployee)}
                        >
                          <ScanFace className="size-3.5 mr-1.5" />
                          Register Face
                        </Button>
                      )}
                    </div>
                  )}
                </div>
                {previewEmployee?.has_face && (
                  <div className="mt-3 border-t border-border/50 pt-3">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-xs border-destructive/50 bg-destructive/5 text-destructive hover:bg-destructive/15 hover:text-destructive hover:border-destructive"
                      onClick={() => setRemoveFaceConfirmEmployee(previewEmployee)}
                    >
                      <Trash2 className="size-3.5 mr-1.5" />
                      Remove face data
                    </Button>
                  </div>
                )}
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Attendance (last 30 days)
                </h3>
                {previewSummary?.from && previewSummary?.to && (
                  <p className="mb-3 text-[11px] text-muted-foreground">
                    {previewSummary.from} – {previewSummary.to}
                  </p>
                )}
                {previewLoading ? (
                  <div className="flex items-center justify-center py-8">
                    <Loader2 className="size-5 animate-spin text-muted-foreground" />
                  </div>
                ) : previewSummary?.metrics ? (
                  <dl className="grid grid-cols-2 gap-x-6 gap-y-2.5 text-sm">
                    <div className="flex justify-between border-b border-border/30 py-1.5">
                      <dt className="text-muted-foreground">Present</dt>
                      <dd className="font-medium tabular-nums text-foreground">{previewSummary.metrics.present_count ?? 0}</dd>
                    </div>
                    <div className="flex justify-between border-b border-border/30 py-1.5">
                      <dt className="text-muted-foreground">Late</dt>
                      <dd className="font-medium tabular-nums text-foreground">{previewSummary.metrics.late_count ?? 0}</dd>
                    </div>
                    <div className="flex justify-between border-b border-border/30 py-1.5">
                      <dt className="text-muted-foreground">Absent</dt>
                      <dd className="font-medium tabular-nums text-foreground">{previewSummary.metrics.absent_count ?? 0}</dd>
                    </div>
                    <div className="flex justify-between border-b border-border/30 py-1.5">
                      <dt className="text-muted-foreground">Half day</dt>
                      <dd className="font-medium tabular-nums text-foreground">{previewSummary.metrics.halfday_count ?? 0}</dd>
                    </div>
                    <div className="flex justify-between border-b border-border/30 py-1.5">
                      <dt className="text-muted-foreground">Undertime</dt>
                      <dd className="font-medium tabular-nums text-foreground">{previewSummary.metrics.undertime_count ?? 0}</dd>
                    </div>
                    <div className="flex justify-between py-1.5">
                      <dt className="text-muted-foreground">Total hours</dt>
                      <dd className="font-medium tabular-nums text-foreground">
                        {Number(previewSummary.metrics.total_hours ?? 0).toFixed(2)}
                      </dd>
                    </div>
                  </dl>
                ) : (
                  <p className="text-sm text-muted-foreground">No data for this period.</p>
                )}
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-gray-400">
                  Leave history
                </h3>
                <p className="text-sm text-muted-foreground">View leave requests in the Leave module.</p>
              </section>
              <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-2.5">
                <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Activity Logs</h3>
                <div className="text-sm text-muted-foreground space-y-1">
                  <p>Last updated by: Not tracked yet</p>
                  <p>Date: {formatDateTime(previewEmployee?.updated_at || previewEmployee?.created_at)}</p>
            </div>
              </section>
          </div>
          </div>
          <SheetFooter className="sticky bottom-0 border-t border-border/50 bg-background/95 backdrop-blur px-6 py-4">
            <div className="flex w-full items-center justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setPreviewOpen(false)
                setPreviewEmployee(null)
                setPreviewSummary(null)
                setActiveEmployeeId(null)
              }}
            >
                Cancel
            </Button>
              {canEditEmployeeTarget(previewEmployee) ? (
                <Button type="button" onClick={savePersonalInfo} disabled={profileDetailsSaving}>
                  {profileDetailsSaving ? <Loader2 className="size-4 animate-spin" /> : 'Save Changes'}
                </Button>
              ) : null}
            </div>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </motion.div>
  )
}
