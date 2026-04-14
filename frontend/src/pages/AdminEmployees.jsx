import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion' // eslint-disable-line no-unused-vars -- used in JSX
import {
  Plus,
  Calendar,
  UserCheck,
  UserX,
  Loader2,
  UserPlus,
  QrCode,
  Clock,
  AlertTriangle,
  Eye,
  RefreshCw,
  Trash2,
  KeyRound,
  Download,
  MoreVertical,
  Search,
  X,
  ScanFace,
  ChevronDown,
  ArrowUp,
  ArrowDown,
  Upload,
  LayoutList,
  CheckCircle2,
  XCircle,
  CircleDashed,
  Fingerprint,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { RoleBadge } from '@/components/RoleBadge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Checkbox } from '@/components/ui/checkbox'
import { Autocomplete } from '@react-google-maps/api'
import { mapPlaceToAddressFields } from '@/lib/googlePlaces'
import { useGoogleMapsLoader } from '@/hooks/useGoogleMapsLoader'
import { deriveAdminEmployeeListLeaveCredits } from '@/lib/leaveCreditsDisplay'
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
  addEmployee,
  updateEmployeeSchedule,
  toggleEmployeeActive,
  getEmployeeQr,
  regenerateEmployeeQr,
  clearEmployeeQr,
  resetEmployeePassword,
  getEmployeeFace,
  profileImageUrl,
  getDepartments,
  getBranches,
  getCompanies,
  companyLogoUrl,
  getWorkingSchedules,
  deleteEmployee,
  updateEmployee,
  registerEmployeeFace,
  updateEmployeeFace,
  uploadEmployeePhoto,
  removeEmployeePhoto,
} from '@/api'
import ESignatureCard from '@/components/ESignatureCard'
import SignaturePadDialog from '@/components/SignaturePadDialog'
import { TableSkeleton } from '@/components/skeletons'
import { QRCodeCanvas } from 'qrcode.react'
import { useToast } from '@/components/ui/use-toast'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath, isAdminHrUser } from '@/lib/hrRoutes'
import { FaceRekognitionLiveness } from '@/components/FaceRekognitionLiveness'
import { cn } from '@/lib/utils'
import { employmentStatusBadgeClassName, formatEmploymentStatusForViewer } from '@/lib/employmentStatus'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import { useAuth } from '@/contexts/AuthContext'
import { useQuery, useQueryClient } from '@tanstack/react-query'

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

const DEFAULT_SCHEDULE = {
  mon: { in: '08:00', out: '17:00' },
  tue: { in: '08:00', out: '17:00' },
  wed: { in: '08:00', out: '17:00' },
  thu: { in: '08:00', out: '17:00' },
  fri: { in: '08:00', out: '17:00' },
  sat: null,
  sun: null,
}

/** No working days — employee cannot clock in/out until admin assigns a schedule. */
const EMPTY_SCHEDULE = Object.fromEntries(DAY_KEYS.map((k) => [k, null]))

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

function getAddEmployeeFriendlyError(error) {
  const raw = String(error?.message || '').toLowerCase()
  if (
    raw.includes('users_phone_number_unique') ||
    (raw.includes('duplicate entry') && raw.includes('phone_number')) ||
    raw.includes('phone number is already in use')
  ) {
    return 'This phone number is already used by another employee.'
  }
  if (raw.includes('users_email_unique') || raw.includes('duplicate entry') && raw.includes('email')) {
    return 'This email address is already used by another employee.'
  }
  return error?.message || 'Failed to add employee. Please try again.'
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

function isValidEmailAddress(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim())
}

function isValidPhMobile(number) {
  return /^(\+63\s?9\d{9}|09\d{9})$/.test(String(number || '').trim())
}

function getPasswordStrength(password) {
  const value = String(password || '')
  if (!value) return { label: 'None', tone: 'text-muted-foreground' }

  let score = 0
  if (value.length >= 8) score += 1
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1
  if (/\d/.test(value)) score += 1
  if (/[^A-Za-z0-9]/.test(value)) score += 1

  if (score <= 1) return { label: 'Weak', tone: 'text-rose-600' }
  if (score <= 3) return { label: 'Medium', tone: 'text-amber-600' }
  return { label: 'Strong', tone: 'text-emerald-600' }
}

const INITIAL_ADD_FORM = {
  first_name: '',
  middle_name: '',
  last_name: '',
  preferred_name: '',
  date_of_birth: '',
  gender: '',
  civil_status: '',
  nationality: '',
  street_address: '',
  barangay: '',
  city: '',
  province: '',
  postal_code: '',
  email: '',
  phone_number: '',
  branch_id: '',
  department_id: '',
  position: '',
  branch_office_location: '',
  employment_type: '',
  hire_date: '',
  supervisor_id: '',
  working_schedule_id: '',
  password: '',
  profile_photo: null,
}

function isManagerialPosition(position) {
  const p = String(position || '').toLowerCase()
  return p.includes('manager') || p.includes('supervisor') || p.includes('lead') || p.includes('head')
}

export default function AdminEmployees() {
  const { toast } = useToast()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const perms = new Set(user?.permissions ?? [])
  const canCreateEmployees = perms.has('employees.create')
  const canEditEmployees = perms.has('employees.edit')
  const canDeleteEmployees = perms.has('employees.delete')
  const canAssignSchedule = perms.has('schedule.assign')
  const canPasswordReset = perms.has('employees.password_reset')
  const canMutateRows =
    canEditEmployees || canAssignSchedule || canDeleteEmployees || canPasswordReset
  const location = useLocation()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const [searchParams, setSearchParams] = useSearchParams()
  const [employees, setEmployees] = useState([])
  const [error, setError] = useState(null)
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState({ total: 0, perPage: 20, lastPage: 1 })
  const didInitialEmployeeLoadRef = useRef(false)
  /** Company id (string) or '' for all — passed to API as company_id */
  const [filterCompany, setFilterCompany] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [filterSchedule, setFilterSchedule] = useState('')
  const [filterFace, setFilterFace] = useState('')
  const [sortBy, setSortBy] = useState('')
  const [sortDir, setSortDir] = useState('asc')
  const [density, setDensity] = useState('comfortable') // 'compact' | 'comfortable'

  const [addOpen, setAddOpen] = useState(false)
  const [addSubmitting, setAddSubmitting] = useState(false)
  const [addSignatureDataUrl, setAddSignatureDataUrl] = useState('')
  const [addSignatureDialogOpen, setAddSignatureDialogOpen] = useState(false)
  const [addForm, setAddForm] = useState(INITIAL_ADD_FORM)
  const [addStep, setAddStep] = useState(1)
  const [addStepDir, setAddStepDir] = useState(1)
  const [addConfirmPassword, setAddConfirmPassword] = useState('')
  const [addFormError, setAddFormError] = useState('')
  const addPhotoInputRef = useRef(null)
  const [addPhotoPreviewUrl, setAddPhotoPreviewUrl] = useState('')
  const addStreetAutocompleteRef = useRef(null)
  const addBarangayAutocompleteRef = useRef(null)
  const addCityAutocompleteRef = useRef(null)
  const addProvinceAutocompleteRef = useRef(null)
  const { isLoaded: isMapsLoaded, loadError: mapsLoadError } = useGoogleMapsLoader()

  const applyMappedAddAddress = useCallback(
    (place) => {
      try {
        const mapped = mapPlaceToAddressFields(place)
        setAddForm((prev) => ({
          ...prev,
          street_address: mapped.street_address || prev.street_address,
          barangay: mapped.barangay || '',
          city: mapped.city || '',
          province: mapped.province || '',
          postal_code: String(mapped.postal_code || '')
            .replace(/[^\d]/g, '')
            .slice(0, 4),
        }))
      } catch (e) {
        toast({
          title: 'Address autocomplete failed',
          description: e?.message || 'Unable to read selected address.',
          variant: 'destructive',
        })
      }
    },
    [toast]
  )

  const makeAddPlaceChangedHandler = useCallback(
    (ref) => () => {
      try {
        const instance = ref?.current
        if (!instance || typeof instance.getPlace !== 'function') return
        const place = instance.getPlace()
        if (!place) return
        applyMappedAddAddress(place)
      } catch (e) {
        toast({
          title: 'Address autocomplete error',
          description: e?.message || 'Something went wrong while selecting an address.',
          variant: 'destructive',
        })
      }
    },
    [applyMappedAddAddress, toast]
  )

  const [qrOpen, setQrOpen] = useState(false)
  const [qrEmployee, setQrEmployee] = useState(null)
  const [qrLoading, setQrLoading] = useState(false)
  const [qrToken, setQrToken] = useState('')
  const [qrCompanyLogoUrl, setQrCompanyLogoUrl] = useState(null)
  const qrCanvasRef = useRef(null)
  const [pendingQrDownload, setPendingQrDownload] = useState(null)
  const hiddenQrRef = useRef(null)

  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduleEmployee, setScheduleEmployee] = useState(null)
  const [scheduleForm, setScheduleForm] = useState(DEFAULT_SCHEDULE)
  const [scheduleSubmitting, setScheduleSubmitting] = useState(false)

  const [togglingId, setTogglingId] = useState(null)
  const [deactivateOpen, setDeactivateOpen] = useState(false)
  const [deactivateEmployee, setDeactivateEmployee] = useState(null)
  const [resetOpen, setResetOpen] = useState(false)
  const [resetEmployee, setResetEmployee] = useState(null)
  const [resetPasswordValue, setResetPasswordValue] = useState('')
  const [resetSubmitting, setResetSubmitting] = useState(false)
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
  const [faceRegisterRetryKey, setFaceRegisterRetryKey] = useState(0)
  const [faceRegisterSlow, setFaceRegisterSlow] = useState(false)
  const [changeFaceConfirmEmployee, setChangeFaceConfirmEmployee] = useState(null)

  const [viewFaceOpen, setViewFaceOpen] = useState(false)
  const [viewFaceEmployee, setViewFaceEmployee] = useState(null)
  const [viewFaceImage, setViewFaceImage] = useState(null)
  const [viewFaceLoading, setViewFaceLoading] = useState(false)

  const [manageFaceOpen, setManageFaceOpen] = useState(false)
  const [manageFaceEmployee, setManageFaceEmployee] = useState(null)

  useEffect(() => {
    const file = addForm.profile_photo
    if (!file) {
      setAddPhotoPreviewUrl('')
      return
    }
    const objectUrl = URL.createObjectURL(file)
    setAddPhotoPreviewUrl(objectUrl)
    return () => URL.revokeObjectURL(objectUrl)
  }, [addForm.profile_photo])

  useEffect(() => {
    if (location.pathname === hrPanelPath(hrBase, 'employees/add')) {
      setAddOpen(true)
    }
  }, [location.pathname, hrBase])

  const employeesQuery = useQuery({
    queryKey: ['admin-employees-list', { page, q: debouncedSearchQuery, companyId: filterCompany || '' }],
    queryFn: () =>
      getEmployees({
        lite: true,
        page,
        per_page: 20,
        q: debouncedSearchQuery || undefined,
        company_id: filterCompany || undefined,
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
      setEmployees(list)
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
    if (!previewEmployee) return
    const phoneRaw = personalInfoForm.phone_number.trim().replace(/[^\d+\s]/g, '')
    if (!personalInfoForm.first_name.trim() || !personalInfoForm.last_name.trim()) {
      setError('First Name and Last Name are required.')
      return
    }
    if (!personalInfoForm.email.trim()) {
      setError('Email Address is required.')
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
        supervisor_id: normalizedSupervisorId || null,
        working_schedule_id: personalInfoForm.working_schedule_id || null,
      })
      const emp = data.employee
      setEmployees((prev) => prev.map((e) => (e.id === previewEmployee.id ? { ...e, ...emp } : e)))
      setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? { ...p, ...emp } : p))
      setPersonalInfoForm({
        first_name: emp?.first_name || '',
        middle_name: emp?.middle_name || '',
        last_name: emp?.last_name || '',
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

  const filterCompanyInitRef = useRef(true)
  useEffect(() => {
    if (filterCompanyInitRef.current) {
      filterCompanyInitRef.current = false
      return
    }
    setPage(1)
  }, [filterCompany])

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

  const submitAddEmployee = async () => {
    const phoneRaw = addForm.phone_number.trim().replace(/[^\d+\s]/g, '')
    const composedHomeAddress = [
      addForm.street_address?.trim(),
      addForm.barangay?.trim(),
      addForm.city?.trim(),
      addForm.province?.trim(),
      addForm.postal_code?.trim(),
    ]
      .filter(Boolean)
      .join(', ')
    if (!addForm.first_name.trim() || !addForm.last_name.trim()) {
      setAddFormError('First Name and Last Name are required.')
      return
    }
    if (!addForm.email.trim()) {
      setAddFormError('Email Address is required.')
      return
    }
    if (!isValidEmailAddress(addForm.email)) {
      setAddFormError('Enter a valid email address.')
      return
    }
    if (!phoneRaw) {
      setAddFormError('Contact Number is required.')
      return
    }
    if (!isValidPhMobile(phoneRaw)) {
      setAddFormError('Enter a valid Philippine mobile number (e.g. 09123456789 or +639123456789).')
      return
    }
    if (
      !addForm.street_address.trim() ||
      !addForm.barangay.trim() ||
      !addForm.city.trim() ||
      !addForm.province.trim() ||
      !addForm.postal_code.trim()
    ) {
      setAddFormError('Complete address is required (Street Address, Barangay, City, Province, Postal Code).')
      return
    }
    if (!addForm.password || addForm.password.length < 8) {
      setAddFormError('Password must be at least 8 characters.')
      return
    }
    if (addForm.password !== addConfirmPassword) {
      setAddFormError('Password and Confirm Password do not match.')
      return
    }
    setAddSubmitting(true)
    setAddFormError('')
    setError(null)
    try {
      const selectedBranch = branches.find((b) => String(b.id) === String(addForm.branch_id))
      const derivedCompanyId =
        selectedBranch?.company_id != null && selectedBranch.company_id !== ''
          ? Number(selectedBranch.company_id)
          : undefined
      const created = await addEmployee({
        first_name: addForm.first_name.trim(),
        middle_name: addForm.middle_name.trim() || undefined,
        last_name: addForm.last_name.trim(),
        date_of_birth: addForm.date_of_birth?.trim() || undefined,
        gender: addForm.gender?.trim() || undefined,
        civil_status: addForm.civil_status?.trim() || undefined,
        nationality: addForm.nationality?.trim() || undefined,
        home_address: composedHomeAddress || undefined,
        full_address: composedHomeAddress || undefined,
        street_address: addForm.street_address?.trim() || undefined,
        barangay: addForm.barangay?.trim() || undefined,
        city: addForm.city?.trim() || undefined,
        province: addForm.province?.trim() || undefined,
        postal_code: addForm.postal_code?.trim() || undefined,
        email: addForm.email.trim(),
        phone_number: phoneRaw || undefined,
        company_id: derivedCompanyId,
        branch_id: addForm.branch_id || undefined,
        department_id: addForm.department_id || undefined,
        position: addForm.position.trim() || undefined,
        branch_office_location: addForm.branch_office_location.trim() || undefined,
        employment_type: addForm.employment_type || undefined,
        hire_date: addForm.hire_date || undefined,
        supervisor_id: addForm.supervisor_id || undefined,
        working_schedule_id: addForm.working_schedule_id || undefined,
        password: addForm.password,
        profile_photo: addForm.profile_photo || undefined,
        signature_data_url: addSignatureDataUrl || undefined,
      })
      const createdEmployee = created?.employee
      toast({
        title: 'Employee created',
        description: `${createdEmployee?.name || 'New employee'} was added successfully.`,
        variant: 'success',
      })
      setAddForm(INITIAL_ADD_FORM)
      setAddSignatureDataUrl('')
      setAddSignatureDialogOpen(false)
      setAddConfirmPassword('')
      setAddStep(1)
      setAddFormError('')
      setAddOpen(false)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      if (createdEmployee?.id) {
        await queryClient.invalidateQueries({
          queryKey: ['admin-employee-profile-snapshot', String(createdEmployee.id)],
        })
      }
      await fetchEmployees()
    } catch (e) {
      setAddFormError(getAddEmployeeFriendlyError(e))
    } finally {
      setAddSubmitting(false)
    }
  }

  const handleAddSubmit = async (e) => {
    e.preventDefault()
    await submitAddEmployee()
  }

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
      setQrCompanyLogoUrl(data.company_logo_url || null)
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
      setQrCompanyLogoUrl(data.company_logo_url || null)
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
      setPendingQrDownload({ token, fileName: (emp.name || 'employee').replace(/[^a-z0-9-_]/gi, '-') })
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

  const openSchedule = (emp) => {
    setScheduleEmployee(emp)
    if (!hasWorkingDays(emp.schedule)) {
      setScheduleForm({ ...EMPTY_SCHEDULE })
    } else {
      setScheduleForm({ ...DEFAULT_SCHEDULE, ...emp.schedule })
    }
    setScheduleOpen(true)
  }

  const handleScheduleSubmit = async (e) => {
    e.preventDefault()
    if (!scheduleEmployee && bulkScheduleIds.length === 0) return
    setScheduleSubmitting(true)
    setError(null)
    try {
      const schedule = Object.fromEntries(
        Object.entries(scheduleForm).map(([day, v]) => [
          day,
          v && v.in && v.out ? { in: v.in, out: v.out } : null,
        ])
      )
      const normalizedSchedule = hasWorkingDays(schedule) ? schedule : null

      const targetIds =
        bulkScheduleIds.length > 0
          ? [...bulkScheduleIds]
          : scheduleEmployee
            ? [scheduleEmployee.id]
            : []

      await Promise.all(
        targetIds.map((id) =>
          updateEmployeeSchedule(id, { schedule: normalizedSchedule })
        )
      )
      setScheduleOpen(false)
      setScheduleEmployee(null)
      setBulkScheduleIds([])
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
    } catch (e) {
      setError(e.message)
    } finally {
      setScheduleSubmitting(false)
    }
  }

  const handleClearSchedule = async () => {
    if (!scheduleEmployee && bulkScheduleIds.length === 0) return
    setScheduleSubmitting(true)
    setError(null)
    try {
      const targetIds =
        bulkScheduleIds.length > 0
          ? [...bulkScheduleIds]
          : scheduleEmployee
            ? [scheduleEmployee.id]
            : []

      await Promise.all(
        targetIds.map((id) => updateEmployeeSchedule(id, { schedule: null }))
      )
      setScheduleOpen(false)
      setScheduleEmployee(null)
      setBulkScheduleIds([])
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      await fetchEmployees()
    } catch (e) {
      setError(e.message)
    } finally {
      setScheduleSubmitting(false)
    }
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
        const haystack = `${emp.name || ''} ${emp.email || ''} ${emp.department || ''} ${emp.position || ''}`.toLowerCase()
        if (!haystack.includes(normalizedSearchQuery)) return false
      }
      if (filterStatus === 'active' && !emp.is_active) return false
      if (filterStatus === 'inactive' && emp.is_active) return false
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
          va = (a.name || '').toLowerCase()
          vb = (b.name || '').toLowerCase()
          return dir * (va < vb ? -1 : va > vb ? 1 : 0)
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
        default:
          return 0
      }
    })
  }, [employees, normalizedSearchQuery, filterStatus, filterSchedule, filterFace, sortBy, sortDir])

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
    return String(a.name || '').localeCompare(String(b.name || ''))
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
  const addSupervisorOptions = useMemo(
    () => getSupervisorCandidatesByCompany(addForm.department_id),
    [addForm.department_id, getSupervisorCandidatesByCompany]
  )
  const addStepMeta = [
    { step: 1, label: 'Identity', icon: ScanFace },
    { step: 2, label: 'Personal', icon: UserCheck },
    { step: 3, label: 'Contact', icon: Search },
    { step: 4, label: 'Employment', icon: Clock },
    { step: 5, label: 'Credentials', icon: KeyRound },
  ]

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

  const handleAddCompanyChange = (nextCompanyId) => {
    setAddForm((prev) => {
      const scopedSupervisors = getSupervisorCandidatesByCompany(nextCompanyId)
      const keepCurrentSupervisor = scopedSupervisors.some(
        (emp) => String(emp.id) === String(prev.supervisor_id)
      )
      return {
        ...prev,
        department_id: nextCompanyId,
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
    setScheduleForm({ ...DEFAULT_SCHEDULE })
    setScheduleOpen(true)
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
        description: 'All selected employees are already inactive.',
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
    const nextActive = !Boolean(emp?.is_active)
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

  const handleFaceRegisterVerified = async (sessionId) => {
    if (!faceRegisterEmployee || faceRegisterSubmitting) return
    setFaceRegisterSubmitting(true)
    setFaceRegisterSlow(false)
    setFaceRegisterError(null)
    try {
      await registerEmployeeFace(faceRegisterEmployee.id, { liveness_session_id: sessionId })
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
      setFaceRegisterError(msg)
      const code = e.errorCode
      const title =
        code === 'spoof_detected'
          ? 'Spoof detected'
          : code === 'no_face_detected'
            ? 'Face not registered'
            : code === 'service_unavailable'
              ? 'Face service unavailable'
              : 'Face registration failed'
      toast({ title, description: msg, variant: 'destructive' })
    } finally {
      setFaceRegisterSubmitting(false)
    }
  }

  useEffect(() => {
    if (!faceRegisterSubmitting) {
      setFaceRegisterSlow(false)
      return
    }
    const timer = setTimeout(() => setFaceRegisterSlow(true), 6000)
    return () => clearTimeout(timer)
  }, [faceRegisterSubmitting])

  const closeFaceRegister = () => {
    if (!faceRegisterSubmitting) {
      setFaceRegisterOpen(false)
      setFaceRegisterEmployee(null)
      setFaceRegisterError(null)
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
    setViewFaceLoading(true)
    setError(null)
    try {
      const data = await getEmployeeFace(emp.id)
      setViewFaceImage(data.face_image)
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

  const pageTransition = { duration: 0.25, ease: [0.23, 1, 0.32, 1] }

  return (
    <motion.div
      className="space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={pageTransition}
    >
      <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Employees</h2>
          <CardDescription>Add employees, issue QR codes, assign schedule, and activate or deactivate.</CardDescription>
        </div>
        <div className="flex items-center @sm:justify-end">
          <Button
            type="button"
            className="h-9 bg-[#0A0A0A] text-white hover:bg-[#171717]"
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
              placeholder="Search employees by name, email, department..."
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

      <Card className="border-0 bg-card shadow-sm overflow-hidden">
          <CardHeader className="border-b border-border/40 bg-muted/20 dark:border-border/50 dark:bg-muted/30">
            <div className="flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
              <div>
                <CardTitle className="text-lg font-semibold">Employee Directory</CardTitle>
                <CardDescription>
                  {filteredEmployees.length} of {pagination.total} employee(s)
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2 @md:justify-end">
                <div className="relative w-full @md:w-64">
                  <Input
                    type="text"
                    className="h-9 pl-8 text-sm"
                    placeholder="Search name, email, department"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                  />
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground opacity-90 dark:text-primary/65" />
                </div>
                {/* Density toggle */}
                <button
                  type="button"
                  title={density === 'comfortable' ? 'Switch to compact view' : 'Switch to comfortable view'}
                  onClick={() => setDensity((d) => d === 'comfortable' ? 'compact' : 'comfortable')}
                  className="inline-flex h-9 items-center gap-1.5 rounded-md border border-input bg-transparent px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground dark:border-border/50 dark:bg-input/30"
                >
                  <LayoutList className="size-3.5" />
                  {density === 'comfortable' ? 'Comfortable' : 'Compact'}
                </button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {selectedIds.length > 0 && canMutateRows && (
              <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b border-border/40 bg-muted/30 dark:border-sky-400/25 dark:bg-primary/10 px-4 py-2.5">
                <p className="text-sm font-medium text-foreground">
                  {selectedIds.length} Employee{selectedIds.length !== 1 ? 's' : ''} Selected
                </p>
                <div className="flex flex-wrap gap-2">
                  {canAssignSchedule && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-xs"
                      onClick={openBulkSchedule}
                      disabled={bulkSubmitting}
                    >
                      <Clock className="mr-1.5 size-3.5" />
                      Assign Schedule
                    </Button>
                  )}
                  {canEditEmployees && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-xs"
                      onClick={handleBulkIssueQr}
                      disabled={bulkSubmitting}
                    >
                      <QrCode className="mr-1.5 size-3.5" />
                      Issue QR
                    </Button>
                  )}
                  {canEditEmployees && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-xs"
                      onClick={handleBulkDeactivate}
                      disabled={bulkSubmitting}
                    >
                      <UserX className="mr-1.5 size-3.5" />
                      Deactivate
                    </Button>
                  )}
                </div>
              </div>
            )}
            {(employeesQuery.isLoading || employeesQuery.isFetching) ? (
              <div className="overflow-x-auto bg-card px-4 py-4">
                <TableSkeleton rows={10} cols={9} className="rounded-xl border border-border/40" />
              </div>
            ) : employees.length === 0 ? (
              <p className="py-12 text-center text-muted-foreground">No employees yet. Add one to get started.</p>
            ) : (
              <>
                {/* Filter chips bar — glass */}
                <div className="border-b border-border/30 bg-white/80 px-4 py-2.5 backdrop-blur-sm dark:border-border/40 dark:bg-muted/45 dark:backdrop-blur-md">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/70">Filter:</span>

                    {/* Company — neutral chip styling (matches Status/Inactive); avoids sky-on-sky + native option contrast issues */}
                    <div className="relative inline-flex items-center">
                      <select
                        value={filterCompany}
                        onChange={(e) => setFilterCompany(e.target.value)}
                        className={cn(
                          'h-7 min-w-36 max-w-56 truncate appearance-none rounded-full border pl-3 text-[11px] font-semibold transition-all focus:outline-none focus:ring-1 focus:ring-ring cursor-pointer bg-background text-foreground dark:scheme-dark',
                          filterCompany ? 'pr-17' : 'pr-8',
                          filterCompany
                            ? 'border-zinc-400/55 bg-zinc-500/10 text-foreground dark:border-zinc-500/50 dark:bg-zinc-500/15 dark:text-zinc-100'
                            : 'border-border/60 bg-muted/30 text-muted-foreground hover:border-border hover:bg-muted/50 hover:text-foreground dark:border-border/50',
                        )}
                      >
                        <option value="">All companies</option>
                        {companies.map((c) => (
                          <option key={c.id} value={String(c.id)}>{c.name}</option>
                        ))}
                      </select>
                      {filterCompany && (
                        <button
                          type="button"
                          onClick={() => setFilterCompany('')}
                          className="absolute right-7 top-1/2 z-1 flex size-5 -translate-y-1/2 items-center justify-center rounded-full border border-border/70 bg-background text-muted-foreground shadow-sm hover:bg-muted hover:text-foreground dark:border-border dark:bg-card"
                          title="Clear company filter"
                        >
                          <X className="size-2.5" />
                        </button>
                      )}
                      <ChevronDown className="pointer-events-none absolute right-2 top-1/2 size-3 -translate-y-1/2 text-muted-foreground opacity-80" />
                    </div>

                    {/* Status chips */}
                    {[{ val: 'active', label: 'Active', color: 'emerald' }, { val: 'inactive', label: 'Inactive', color: 'zinc' }].map(({ val, label, color }) => (
                      <button
                        key={val}
                        type="button"
                        onClick={() => setFilterStatus(filterStatus === val ? '' : val)}
                        className={[
                          'inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-[11px] font-semibold transition-all',
                          filterStatus === val
                            ? color === 'emerald'
                              ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-700 dark:border-emerald-400/50 dark:bg-emerald-500/20 dark:text-emerald-300'
                              : 'border-zinc-500/60 bg-zinc-500/15 text-zinc-700 dark:border-zinc-400/50 dark:bg-zinc-500/20 dark:text-zinc-300'
                            : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground',
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
                          'inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-[11px] font-semibold transition-all',
                          filterSchedule === val
                            ? color === 'indigo'
                              ? 'border-indigo-500/60 bg-indigo-500/15 text-indigo-700 dark:border-indigo-400/50 dark:bg-indigo-500/20 dark:text-indigo-300'
                              : 'border-amber-500/60 bg-amber-500/15 text-amber-700 dark:border-amber-400/50 dark:bg-amber-500/20 dark:text-amber-300'
                            : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground',
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
                          'inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-[11px] font-semibold transition-all',
                          filterFace === val
                            ? color === 'emerald'
                              ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-700 dark:border-emerald-400/50 dark:bg-emerald-500/20 dark:text-emerald-300'
                              : 'border-rose-500/60 bg-rose-500/15 text-rose-700 dark:border-rose-400/50 dark:bg-rose-500/20 dark:text-rose-300'
                            : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        ].join(' ')}
                      >
                        {filterFace === val
                          ? <><span className="opacity-60">Face:</span> {label} <X className="size-3 ml-0.5" onClick={(e) => { e.stopPropagation(); setFilterFace('') }} /></>
                          : label}
                      </button>
                    ))}

                    {/* Clear all active filters */}
                    {(filterCompany || filterStatus || filterSchedule || filterFace) && (
                      <button
                        type="button"
                        onClick={() => { setFilterCompany(''); setFilterStatus(''); setFilterSchedule(''); setFilterFace('') }}
                        className="ml-1 text-[11px] font-medium text-muted-foreground underline underline-offset-2 hover:text-foreground transition-colors"
                      >
                        Clear all
                      </button>
                    )}
                  </div>
                </div>
                <div className="overflow-x-auto bg-card">
                  <table className="w-full text-sm text-foreground">
                    <thead className="sticky top-0 z-10 border-b border-border/30 bg-[#f1f5f9] dark:border-border/50 dark:bg-card shadow-[0_1px_0_0_var(--border)]">
                      <tr>
                        {canMutateRows && (
                          <th className="w-12 min-w-12 max-w-12 pl-4 pr-2 py-3 text-center">
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
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('name')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Employee
                            {sortBy === 'name' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 w-[150px] max-w-[150px] hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('company_name')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Company
                            {sortBy === 'company_name' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Position</th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Branch</th>
                        <th
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 w-[128px] max-w-[140px] hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('employment_status')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Employment
                            {sortBy === 'employment_status' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Hire Date</th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 whitespace-nowrap">
                          Leave credits
                        </th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">QR</th>
                        <th
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('schedule')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Schedule
                            {sortBy === 'schedule' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('face')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Face
                            {sortBy === 'face' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th
                          className="cursor-pointer text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 select-none transition-colors"
                          onClick={() => toggleSort('status')}
                        >
                          <span className="inline-flex items-center gap-1">
                            Status
                            {sortBy === 'status' ? (sortDir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUp className="size-3 opacity-20" />}
                          </span>
                        </th>
                        <th className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Actions</th>
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
                      /** Aligns list with profile Leave Balance: Regular + 1yr from status effective date; fills stale 0 pool. */
                      const leaveCreditsRow = deriveAdminEmployeeListLeaveCredits(emp)
                      return (
                        <motion.tr
                          key={emp.id}
                          onClick={() => openPreview(emp)}
                          initial={false}
                          transition={{ duration: 0.15 }}
                          className={[
                            'group cursor-pointer border-b border-border/20 transition-all duration-150 dark:border-border/40',
                            density === 'compact' ? '[&>td]:py-2' : '[&>td]:py-5',
                            isSelected
                              ? 'bg-sky-50 dark:bg-primary/10 [&>td:first-child]:shadow-[inset_3px_0_0_rgba(14,165,233,0.7)]'
                              : isActive
                              ? 'bg-primary/5 dark:bg-primary/10 [&>td:first-child]:shadow-[inset_3px_0_0_rgba(99,102,241,0.7)]'
                              : isEven
                              ? 'bg-white dark:bg-muted/30 hover:bg-slate-50 dark:hover:bg-muted/45 [&:hover>td:first-child]:shadow-[inset_3px_0_0_rgba(20,184,166,0.55)]'
                              : 'bg-[#f8fafc] dark:bg-card hover:bg-slate-50 dark:hover:bg-muted/40 [&:hover>td:first-child]:shadow-[inset_3px_0_0_rgba(20,184,166,0.55)]',
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

                          {/* Employee cell — avatar + name hierarchy */}
                          <td className="px-4">
                            <div className="flex items-center gap-3">
                              <Avatar className={`shrink-0 rounded-full shadow-sm ring-2 ring-border/20 transition-all group-hover:ring-teal-500/30 ${density === 'compact' ? 'size-8' : 'size-11'}`}>
                                <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                                <AvatarFallback className={`rounded-full font-bold ${density === 'compact' ? 'text-xs' : 'text-sm'} ${getAvatarColor(emp.id, emp.name)}`}>
                                  {initials}
                                </AvatarFallback>
                              </Avatar>
                              <div className="min-w-0">
                                <div className="flex items-center gap-1.5 flex-wrap">
                                  <p className="font-bold text-[14.5px] leading-tight text-slate-900 dark:text-slate-100 truncate max-w-[190px]">{emp.name}</p>
                                  <RoleBadge user={emp} size={density === 'compact' ? 'xs' : 'sm'} />
                                </div>
                                <p className="truncate text-[11px] text-slate-500 dark:text-slate-500 max-w-[190px]">{emp.email}</p>
                                {emp.phone_number && density !== 'compact' && (
                                  <p className="text-[10.5px] text-slate-400 dark:text-slate-600">{emp.phone_number}</p>
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
                                  <span className="truncate text-[12.5px] text-slate-600 dark:text-slate-300" title={emp.company_name}>
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
                                  <Fingerprint className="size-3.5 text-emerald-500 dark:text-emerald-400 shrink-0" />
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

                          {/* Status — green dot (Active) / gray (Inactive) */}
                          <td className="px-4 align-middle">
                            {emp.is_active ? (
                              <div className="inline-flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-green-500 shrink-0 ring-2 ring-green-500/25" />
                                <span className="text-[12px] font-semibold text-green-700 dark:text-green-400">Active</span>
                              </div>
                            ) : (
                              <div className="inline-flex items-center gap-1.5">
                                <span className="size-2 rounded-full bg-gray-400/60 dark:bg-gray-500/60 shrink-0" />
                                <span className="text-[12px] text-gray-500 dark:text-gray-400">Inactive</span>
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
                                {canEditEmployees && (
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
                                    {canEditEmployees ? 'Edit / View profile' : 'View profile'}
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
                                  {!emp.has_qr && canEditEmployees && (
                                    <DropdownMenuItem onClick={() => generateOrRegenerateQr(emp)}>
                                      <QrCode className="size-4 mr-2" />
                                      Issue QR
                                    </DropdownMenuItem>
                                  )}
                                  {canEditEmployees && (
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
                                  {canEditEmployees && (
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
                                  {canDeleteEmployees && (
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
              </>
            )}
          </CardContent>
          {pagination.total > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/40 px-5 py-3 text-xs text-muted-foreground">
              <div>
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
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-7 px-2"
                  disabled={page <= 1}
                  onClick={() => fetchEmployees(page - 1)}
                >
                  Previous
                </Button>
                <span className="text-muted-foreground">
                  Page {page} of {pagination.lastPage || 1}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-7 px-2"
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
        <DialogContent className="max-w-md gap-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ScanFace className="size-5 text-primary" />
              Manage face
            </DialogTitle>
            <DialogDescription>
              {manageFaceEmployee && (
                <span className="font-medium text-foreground">{manageFaceEmployee.name}</span>
              )}
              {' — '}View, register, change, or remove face recognition.
            </DialogDescription>
          </DialogHeader>
          {manageFaceEmployee && (
            <div className="flex flex-col gap-2">
              {manageFaceEmployee.has_face ? (
                <>
                  <Button
                    variant="outline"
                    className="w-full justify-start gap-2"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      openViewFace(emp)
                    }}
                  >
                    <Eye className="size-4" />
                    View face
                  </Button>
                  <Button
                    variant="outline"
                    className="w-full justify-start gap-2"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      openFaceRegister(emp)
                    }}
                  >
                    <RefreshCw className="size-4" />
                    Change face
                  </Button>
                  <Button
                    variant="outline"
                    className="w-full justify-start gap-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    onClick={() => {
                      const emp = manageFaceEmployee
                      setManageFaceOpen(false)
                      setManageFaceEmployee(null)
                      setRemoveFaceConfirmEmployee(emp)
                    }}
                  >
                    <Trash2 className="size-4" />
                    Remove face
                  </Button>
                </>
              ) : (
                <Button
                  variant="default"
                  className="w-full justify-start gap-2"
                  onClick={() => {
                    const emp = manageFaceEmployee
                    setManageFaceOpen(false)
                    setManageFaceEmployee(null)
                    openFaceRegister(emp)
                  }}
                >
                  <ScanFace className="size-4" />
                  Register face
                </Button>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => { setManageFaceOpen(false); setManageFaceEmployee(null); }}>
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
            <p className="py-8 text-center text-sm text-muted-foreground">No face registered.</p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={closeViewFace}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Register face – Amazon Rekognition Face Liveness */}
      <Dialog open={faceRegisterOpen} onOpenChange={(open) => !open && !faceRegisterSubmitting && closeFaceRegister()}>
        <DialogContent className="max-w-lg gap-4">
          <DialogHeader>
            <DialogTitle>{faceRegisterEmployee?.has_face ? 'Change face' : 'Register face'}</DialogTitle>
            <DialogDescription>
              {faceRegisterEmployee && (
                <>
                  {faceRegisterEmployee.has_face ? (
                    <>
                      Complete the guided face liveness check for <strong className="text-foreground">{faceRegisterEmployee.name}</strong>. Existing face data will be replaced.
                    </>
                  ) : (
                    <>
                      Complete the guided face liveness check for <strong className="text-foreground">{faceRegisterEmployee.name}</strong>. Embedding is encrypted and stored securely.
                    </>
                  )}
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <FaceRekognitionLiveness
            key={faceRegisterRetryKey}
            onVerified={handleFaceRegisterVerified}
            onSuccess={closeFaceRegister}
            hideInstruction
            instructionText="Complete the face liveness check to register this employee's face."
          />
          {faceRegisterSubmitting && (
            <div
              className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground"
              role="status"
              aria-live="polite"
            >
              <Loader2 className="size-4 shrink-0 animate-spin" />
              Processing face…
            </div>
          )}
          {faceRegisterSlow && (
            <p className="rounded-md border border-amber-300/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
              This is taking longer than usual. You can keep this open while registration continues.
            </p>
          )}
          {faceRegisterError && (
            <div className="space-y-2">
              <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                {faceRegisterError}
              </p>
              <Button
                type="button"
                variant="secondary"
                className="w-full"
                disabled={faceRegisterSubmitting}
                onClick={() => {
                  setFaceRegisterError(null)
                  setFaceRegisterRetryKey((k) => k + 1)
                }}
              >
                Try again
              </Button>
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

      {/* Add employee — single name row, sticky footer, visible helper text in dark */}
      <Dialog
        open={addOpen}
        onOpenChange={(open) => {
          setAddOpen(open)
          if (!open) {
            setAddStepDir(1)
            setAddStep(1)
            setAddFormError('')
            setAddConfirmPassword('')
            setAddSignatureDataUrl('')
            setAddSignatureDialogOpen(false)
          }
        }}
      >
        <DialogContent className="max-w-4xl flex max-h-[min(90vh,880px)] flex-col gap-0 border border-border dark:border-border/50 bg-card shadow-2xl dark:shadow-black/40 p-0 overflow-hidden">
          <DialogHeader className="gap-0.5 px-6 pt-5 pb-4 shrink-0">
            <DialogTitle className="flex items-center gap-3 text-2xl">
              <div className="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-muted shrink-0">
                <UserPlus className="size-5 text-foreground" />
              </div>
              Add New Employee
            </DialogTitle>
            <DialogDescription className="mt-1 text-sm text-muted-foreground">
              Step {addStep} of 5 &middot;{' '}
              <span className="font-medium text-foreground">
                {addStepMeta[addStep - 1]?.label}
              </span>{' '}
              — Fill in the details to create a new HRIS record
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleAddSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5 space-y-6">
              <div className="space-y-3">
                <div className="flex items-center">
                  {addStepMeta.map((item, idx) => {
                    const Icon = item.icon
                    const active = addStep === item.step
                    const done = addStep > item.step
                    return (
                      <div key={item.step} className="flex flex-1 items-center">
                        <div className="w-full text-center">
                          <div className={`mx-auto mb-2 flex h-11 w-11 items-center justify-center rounded-xl transition-all duration-200 ${
                            active
                              ? 'bg-zinc-900 text-white shadow-lg shadow-zinc-900/25 ring-4 ring-zinc-900/15 dark:bg-white dark:text-zinc-950 dark:shadow-white/20 dark:ring-white/25'
                              : done
                                ? 'border border-border bg-muted text-foreground dark:border-border dark:bg-muted/80'
                                : 'border border-border bg-muted text-muted-foreground'
                          }`}>
                            {done
                              ? <CheckCircle2 className="size-[18px] text-foreground dark:text-zinc-200" />
                              : <Icon className="size-[18px]" />
                            }
                          </div>
                          <p className={`text-[10px] uppercase tracking-widest font-semibold ${
                            active ? 'text-foreground' : done ? 'text-muted-foreground' : 'text-muted-foreground'
                          }`}>
                            {item.label}
                          </p>
                        </div>
                        {idx < addStepMeta.length - 1 ? (
                          <div className="mx-1 mt-[-22px] h-0.5 flex-1 overflow-hidden rounded-full bg-border/60">
                            <div className={`h-full rounded-full bg-zinc-900 transition-all duration-500 dark:bg-zinc-100 ${addStep > item.step ? 'w-full' : 'w-0'}`} />
                          </div>
                        ) : null}
                      </div>
                    )
                  })}
                </div>
                {/* Overall progress bar */}
                <div className="relative h-1 w-full overflow-hidden rounded-full bg-border/50">
                  <div
                    className="absolute inset-y-0 left-0 rounded-full bg-linear-to-r from-zinc-900 to-zinc-700 transition-all duration-500 dark:from-zinc-200 dark:to-zinc-400"
                    style={{ width: `${((addStep - 1) / (addStepMeta.length - 1)) * 100}%` }}
                  />
                </div>
              </div>

              {addFormError && (
                <div className="flex items-start gap-2.5 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400 dark:text-red-300" role="alert">
                  <AlertTriangle className="mt-0.5 size-4 shrink-0 text-red-400 dark:text-red-300" />
                  <span>{addFormError}</span>
                </div>
              )}

              <AnimatePresence mode="wait" initial={false}>
              {addStep === 1 && (
                <motion.div
                  key="add-step-1"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 dark:border-amber-500/25 dark:bg-amber-500/8">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-500 dark:text-amber-400" />
                    <div>
                      <p className="text-sm font-semibold text-amber-700 dark:text-amber-300">Legal Name Match Required</p>
                      <p className="mt-0.5 text-xs text-amber-600/90 dark:text-amber-400/80">
                        Names must exactly match the employee&apos;s government-issued ID (passport, SSS, PhilSys). Incorrect entries may cause payroll and compliance issues.
                      </p>
                    </div>
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-3">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-first_name" className="text-[13px] font-medium">First Name <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-first_name"
                        value={addForm.first_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, first_name: e.target.value }))}
                        placeholder="e.g. Michael"
                        className="h-9 focus-visible:ring-ring/50"
                        autoFocus
                        required
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-middle_name" className="text-[13px] font-medium text-muted-foreground">Middle Name <span className="text-[10px] font-normal">(optional)</span></Label>
                      <Input
                        id="add-middle_name"
                        value={addForm.middle_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, middle_name: e.target.value }))}
                        placeholder="Leave blank if none"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-last_name" className="text-[13px] font-medium">Last Name <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-last_name"
                        value={addForm.last_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, last_name: e.target.value }))}
                        placeholder="e.g. Scott"
                        className="h-9 focus-visible:ring-ring/50"
                        required
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-preferred_name" className="text-[13px] font-medium text-muted-foreground">
                        Preferred Name <span className="text-[10px] font-normal">(optional)</span>
                      </Label>
                      <Input
                        id="add-preferred_name"
                        value={addForm.preferred_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, preferred_name: e.target.value }))}
                        placeholder="e.g. Mike"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                      <p className="text-xs text-muted-foreground">Used in clock-ins, payslips, and internal communications.</p>
                    </div>
                  </div>
                </motion.div>
              )}

              {addStep === 2 && (
                <motion.div
                  key="add-step-2"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Personal Details</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-date_of_birth" className="text-[13px] font-medium">Date of Birth</Label>
                      <Input
                        id="add-date_of_birth"
                        type="date"
                        value={addForm.date_of_birth}
                        onChange={(e) => setAddForm((f) => ({ ...f, date_of_birth: e.target.value }))}
                        className="h-9 dark:[color-scheme:dark]"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-gender" className="text-[13px] font-medium">Gender</Label>
                      <select
                        id="add-gender"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.gender}
                        onChange={(e) => setAddForm((f) => ({ ...f, gender: e.target.value }))}
                      >
                        <option value="">Select gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-civil_status" className="text-[13px] font-medium">Civil Status</Label>
                      <select
                        id="add-civil_status"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.civil_status}
                        onChange={(e) => setAddForm((f) => ({ ...f, civil_status: e.target.value }))}
                      >
                        <option value="">Select civil status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-nationality" className="text-[13px] font-medium">Nationality</Label>
                      <select
                        id="add-nationality"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.nationality}
                        onChange={(e) => setAddForm((f) => ({ ...f, nationality: e.target.value }))}
                      >
                        <option value="">Select nationality</option>
                        <option value="Filipino">Filipino</option>
                        <option value="American">American</option>
                        <option value="Australian">Australian</option>
                        <option value="British">British</option>
                        <option value="Canadian">Canadian</option>
                        <option value="Chinese">Chinese</option>
                        <option value="Indian">Indian</option>
                        <option value="Indonesian">Indonesian</option>
                        <option value="Japanese">Japanese</option>
                        <option value="Korean">Korean</option>
                        <option value="Malaysian">Malaysian</option>
                        <option value="Singaporean">Singaporean</option>
                        <option value="Thai">Thai</option>
                        <option value="Vietnamese">Vietnamese</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                  </div>
                </motion.div>
              )}

              {addStep === 3 && (
                <motion.div
                  key="add-step-3"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Contact Information</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5 @sm:col-span-2">
                      <Label htmlFor="add-street_address" className="text-[13px] font-medium">Street Address</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addStreetAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore – some Google builds may not expose these setters
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addStreetAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-street_address"
                            value={addForm.street_address}
                            onChange={(e) => setAddForm((f) => ({ ...f, street_address: e.target.value }))}
                            placeholder="Start typing to search an address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-street_address"
                          value={addForm.street_address}
                          onChange={(e) => setAddForm((f) => ({ ...f, street_address: e.target.value }))}
                          placeholder="Start typing to search an address..."
                          className="h-9"
                        />
                      )}
                      {mapsLoadError && (
                        <p className="text-xs text-amber-600">
                          Address autocomplete unavailable: {mapsLoadError}
                        </p>
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-barangay" className="text-[13px] font-medium">Barangay</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addBarangayAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addBarangayAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-barangay"
                            value={addForm.barangay}
                            onChange={(e) => setAddForm((f) => ({ ...f, barangay: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-barangay"
                          value={addForm.barangay}
                          onChange={(e) => setAddForm((f) => ({ ...f, barangay: e.target.value }))}
                          placeholder="Barangay"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-city" className="text-[13px] font-medium">City</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addCityAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addCityAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-city"
                            value={addForm.city}
                            onChange={(e) => setAddForm((f) => ({ ...f, city: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-city"
                          value={addForm.city}
                          onChange={(e) => setAddForm((f) => ({ ...f, city: e.target.value }))}
                          placeholder="City / Municipality"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-province" className="text-[13px] font-medium">Province</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addProvinceAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addProvinceAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-province"
                            value={addForm.province}
                            onChange={(e) => setAddForm((f) => ({ ...f, province: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-province"
                          value={addForm.province}
                          onChange={(e) => setAddForm((f) => ({ ...f, province: e.target.value }))}
                          placeholder="Province"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-postal_code" className="text-[13px] font-medium">Postal Code</Label>
                      <Input
                        id="add-postal_code"
                        value={addForm.postal_code}
                        onChange={(e) => setAddForm((f) => ({ ...f, postal_code: e.target.value.replace(/[^\d]/g, '').slice(0, 4) }))}
                        placeholder="e.g. 1200"
                        className="h-9 focus-visible:ring-ring/50"
                        inputMode="numeric"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-phone_number" className="text-[13px] font-medium">Contact Number <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-phone_number"
                        type="tel"
                        value={addForm.phone_number}
                        onChange={(e) => setAddForm((f) => ({ ...f, phone_number: e.target.value.replace(/[^\d+\s]/g, '') }))}
                        placeholder="09123456789 or +639123456789"
                        className="h-9 focus-visible:ring-ring/50"
                        required
                      />
                      {addForm.phone_number.trim() !== '' && (
                        <p className={`text-xs ${isValidPhMobile(addForm.phone_number) ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {isValidPhMobile(addForm.phone_number)
                            ? `${addForm.phone_number.trim()} ✓ Valid PH number`
                            : 'Use 09123456789 or +639123456789'}
                        </p>
                      )}
              </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-email" className="text-[13px] font-medium">Email Address <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-email"
                        type="email"
                        value={addForm.email}
                        onChange={(e) => setAddForm((f) => ({ ...f, email: e.target.value }))}
                        placeholder="juan@company.com"
                        className="h-9 focus-visible:ring-ring/50"
                        required
                      />
                      {addForm.email.trim() !== '' && (
                        <p className={`text-xs ${isValidEmailAddress(addForm.email) ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {isValidEmailAddress(addForm.email)
                            ? `${addForm.email.trim()} ✓`
                            : 'Enter a valid email address'}
                        </p>
                      )}
              </div>
                  </div>
                </motion.div>
              )}

              {addStep === 4 && (
                <motion.div
                  key="add-step-4"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Employment Details</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-employee-id" className="text-[13px] font-medium text-muted-foreground">Employee ID</Label>
                      <Input id="add-employee-id" value="Auto-generated on save" className="h-9 opacity-60" disabled />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-branch_id" className="text-[13px] font-medium">Branch</Label>
                      <select
                        id="add-branch_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.branch_id}
                        onChange={(e) => { const bid = e.target.value; setAddForm((f) => ({ ...f, branch_id: bid, department_id: '' })) }}
                        disabled={departmentsLoading}
                      >
                        <option value="">Select branch (optional)</option>
                        {branches.map((b) => (
                          <option key={b.id} value={b.id}>{b.name}{b.company_name ? ` — ${b.company_name}` : ''}</option>
                        ))}
                      </select>
                      {addForm.branch_id && (() => { const b = branches.find((x) => String(x.id) === String(addForm.branch_id)); return b?.company_name ? (<p className="mt-1 text-xs text-muted-foreground">Company: <span className="font-medium text-foreground">{b.company_name}</span></p>) : null })()}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-department_id" className="text-[13px] font-medium">Department</Label>
                      <select
                        id="add-department_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.department_id}
                        onChange={(e) => handleAddCompanyChange(e.target.value)}
                        disabled={departmentsLoading}
                      >
                        <option value="">Select department</option>
                        {(addForm.branch_id ? departments.filter((d) => String(d.branch_id) === String(addForm.branch_id)) : departments).map((dept) => (
                          <option key={dept.id} value={dept.id}>{dept.name}</option>
                        ))}
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-position" className="text-[13px] font-medium">Job Title / Position</Label>
                      <Input
                        id="add-position"
                        value={addForm.position}
                        onChange={(e) => setAddForm((f) => ({ ...f, position: e.target.value }))}
                        placeholder="e.g. Software Engineer"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-branch_office_location" className="text-[13px] font-medium">Office Location <span className="font-normal text-muted-foreground">(optional)</span></Label>
                      <Input
                        id="add-branch_office_location"
                        value={addForm.branch_office_location}
                        onChange={(e) => setAddForm((f) => ({ ...f, branch_office_location: e.target.value }))}
                        placeholder="e.g. 3rd Floor, Tower 2"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-employment_type" className="text-[13px] font-medium">Employment Type</Label>
                      <select
                        id="add-employment_type"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.employment_type}
                        onChange={(e) => setAddForm((f) => ({ ...f, employment_type: e.target.value }))}
                      >
                        <option value="">Select employment type</option>
                        <option value="full_time">Full-time</option>
                        <option value="part_time">Part-time</option>
                        <option value="contract">Contract</option>
                        <option value="probationary">Probationary</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-hire_date" className="text-[13px] font-medium">Hire Date</Label>
                      <Input
                        id="add-hire_date"
                        type="date"
                        value={addForm.hire_date}
                        onChange={(e) => setAddForm((f) => ({ ...f, hire_date: e.target.value }))}
                        className="h-9 dark:[color-scheme:dark]"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-supervisor_id" className="text-[13px] font-medium">Supervisor</Label>
                      <select
                        id="add-supervisor_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.supervisor_id}
                        onChange={(e) => setAddForm((f) => ({ ...f, supervisor_id: e.target.value }))}
                      >
                        <option value="">Select supervisor</option>
                        {addSupervisorOptions.length === 0 && (
                          <option value="" disabled>No managerial supervisor available for selected department</option>
                        )}
                        {addSupervisorOptions.map((emp) => (
                          <option key={emp.id} value={emp.id}>
                            {emp.name} {emp.position ? `(${emp.position})` : ''}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-working_schedule_id" className="text-[13px] font-medium">Work Schedule</Label>
                      <select
                        id="add-working_schedule_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.working_schedule_id}
                        onChange={(e) => setAddForm((f) => ({ ...f, working_schedule_id: e.target.value }))}
                      >
                        <option value="">Select work schedule</option>
                        {workingSchedules.map((s) => (
                          <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                </motion.div>
              )}

              {addStep === 5 && (
                <motion.div
                  key="add-step-5"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account Credentials</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-password" className="text-[13px] font-medium">Password <span className="text-muted-foreground">*</span></Label>
                    <Input
                      id="add-password"
                      type="password"
                      value={addForm.password}
                      onChange={(e) => setAddForm((f) => ({ ...f, password: e.target.value }))}
                      placeholder="Min. 8 characters"
                      minLength={8}
                      className="h-9 focus-visible:ring-ring/50"
                      required
                    />
                    {addForm.password !== '' && (
                      <p className={`text-xs ${getPasswordStrength(addForm.password).tone}`}>
                        Password strength: {getPasswordStrength(addForm.password).label}
                      </p>
                    )}
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-confirm-password" className="text-[13px] font-medium">Confirm Password <span className="text-muted-foreground">*</span></Label>
                    <Input
                      id="add-confirm-password"
                      type="password"
                      value={addConfirmPassword}
                      onChange={(e) => setAddConfirmPassword(e.target.value)}
                      placeholder="Re-enter password"
                      minLength={8}
                      className="h-9 focus-visible:ring-ring/50"
                      required
                    />
                    {addConfirmPassword !== '' && (
                      <p className={`text-xs ${addConfirmPassword === addForm.password ? 'text-emerald-500' : 'text-red-400'}`}>
                        {addConfirmPassword === addForm.password ? '✓ Passwords match' : 'Passwords do not match'}
                      </p>
                    )}
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-profile-photo" className="text-[13px] font-medium text-muted-foreground">Profile Photo <span className="text-[10px] font-normal">(optional)</span></Label>
                    <div className="flex items-center gap-3 rounded-lg border border-border/60 bg-muted/20 dark:border-slate-700/50 dark:bg-slate-800/20 p-3">
                      <Avatar className="h-12 w-12">
                        <AvatarImage src={addPhotoPreviewUrl || undefined} alt="Profile photo preview" />
                        <AvatarFallback>
                          {`${addForm.first_name?.[0] || ''}${addForm.last_name?.[0] || ''}`.trim() || 'U'}
                        </AvatarFallback>
                      </Avatar>
                      <div className="space-y-1">
                        <Button type="button" variant="outline" className="h-8" onClick={() => addPhotoInputRef.current?.click()}>
                          Upload
                        </Button>
                        {addForm.profile_photo && (
                          <p className="text-xs text-muted-foreground">{addForm.profile_photo.name}</p>
                        )}
                      </div>
                    </div>
                    <Input
                      ref={addPhotoInputRef}
                      id="add-profile-photo"
                      type="file"
                      accept="image/png,image/jpeg,image/jpg,image/webp,image/gif"
                      className="hidden"
                      onChange={(e) => {
                        const file = e.target.files?.[0] || null
                        setAddForm((f) => ({ ...f, profile_photo: file }))
                      }}
                    />
                  </div>
                  <ESignatureCard
                    title="Electronic Signature"
                    status={addSignatureDataUrl ? 'completed' : 'none'}
                    signatureImage={addSignatureDataUrl || ''}
                    busy={addSubmitting}
                    onManage={() => setAddSignatureDialogOpen(true)}
                    manageLabel={addSignatureDataUrl ? 'Update Signature' : 'Manage Signature'}
                  />
                  <p className="text-xs text-muted-foreground">
                    Draw the e-signature before submitting. It will be saved with the employee profile.
                  </p>
                </motion.div>
              )}
              </AnimatePresence>
            </div>
            <DialogFooter className="border-t border-border/30 px-6 py-4 shrink-0 bg-card flex items-center justify-between">
              <Button
                type="button"
                variant="ghost"
                className="h-9 text-muted-foreground hover:text-foreground"
                onClick={() => setAddOpen(false)}
              >
                Cancel
              </Button>
              <div className="flex items-center gap-2">
                {addStep > 1 && (
                  <Button
                    type="button"
                    variant="outline"
                    className="h-9 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                    onClick={() => {
                      setAddFormError('')
                      setAddStepDir(-1)
                      setAddStep((s) => s - 1)
                    }}
                  >
                    ← Back
                  </Button>
                )}
                {addStep < 5 ? (
                  <Button
                    type="button"
                    className="h-9 bg-black text-white shadow-sm transition-all hover:bg-black/90 hover:shadow-md dark:bg-white dark:text-slate-900 dark:hover:bg-white/90"
                    onClick={() => {
                      if (addStep === 1) {
                        if (!addForm.first_name.trim() || !addForm.last_name.trim()) {
                          setAddFormError('First Name and Last Name are required.')
                          return
                        }
                      }
                      if (addStep === 3) {
                        const phoneRaw = addForm.phone_number.trim().replace(/[^\d+\s]/g, '')
                        if (!addForm.email.trim()) {
                          setAddFormError('Email Address is required.')
                          return
                        }
                        if (!isValidEmailAddress(addForm.email)) {
                          setAddFormError('Enter a valid email address.')
                          return
                        }
                        if (!phoneRaw) {
                          setAddFormError('Contact Number is required.')
                          return
                        }
                        if (!isValidPhMobile(phoneRaw)) {
                          setAddFormError('Enter a valid Philippine mobile number (e.g. 09123456789 or +639123456789).')
                          return
                        }
                      }
                      setAddFormError('')
                      setAddStepDir(1)
                      setAddStep((s) => s + 1)
                    }}
                  >
                    Next Step →
                  </Button>
                ) : (
                  <Button
                    type="submit"
                    disabled={addSubmitting}
                    className="h-9 min-w-[140px] bg-black text-white shadow-sm transition-all hover:bg-black/90 hover:shadow-md disabled:opacity-60 dark:bg-white dark:text-slate-900 dark:hover:bg-white/90"
                  >
                    {addSubmitting
                      ? <><Loader2 className="size-4 animate-spin" /> Adding…</>
                      : <><UserPlus className="size-4" /> Add Employee</>
                    }
                  </Button>
                )}
              </div>
            </DialogFooter>
          </form>
          <SignaturePadDialog
            open={addSignatureDialogOpen}
            onOpenChange={setAddSignatureDialogOpen}
            initialImage={addSignatureDataUrl}
            busy={addSubmitting}
            onSave={async (dataUrl) => {
              setAddSignatureDataUrl(dataUrl)
              setAddSignatureDialogOpen(false)
            }}
            onRemove={addSignatureDataUrl ? async () => {
              setAddSignatureDataUrl('')
              setAddSignatureDialogOpen(false)
            } : null}
          />
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
          <QRCodeCanvas value={pendingQrDownload.token} size={256} level="H" includeMargin />
        </div>
      )}

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

      {/* Assign schedule */}
      <Dialog open={scheduleOpen} onOpenChange={setScheduleOpen}>
        <DialogContent className="max-w-xl gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl">
              <Clock className="size-5 text-primary" />
              Assign schedule
            </DialogTitle>
            <DialogDescription>
              <span className="font-medium text-foreground">{scheduleEmployee?.name}</span>
              {' — '}Set clock-in and clock-out per day. Leave empty for day off.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleScheduleSubmit} className="flex max-h-[60vh] flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto">
              <div className="grid gap-3 py-2">
                <div className="grid grid-cols-[minmax(4rem,1fr)_1fr_1fr] items-center gap-3 text-xs font-medium text-muted-foreground">
                  <span>Day</span>
                  <span>In</span>
                  <span>Out</span>
                </div>
                {[
                  { key: 'mon', label: 'Monday' },
                  { key: 'tue', label: 'Tuesday' },
                  { key: 'wed', label: 'Wednesday' },
                  { key: 'thu', label: 'Thursday' },
                  { key: 'fri', label: 'Friday' },
                  { key: 'sat', label: 'Saturday' },
                  { key: 'sun', label: 'Sunday' },
                ].map(({ key, label }) => (
                  <div key={key} className="grid grid-cols-[minmax(4rem,1fr)_1fr_1fr] items-center gap-3">
                    <Label className="text-sm font-normal">{label}</Label>
                    <Input
                      type="time"
                      value={scheduleForm[key]?.in ?? ''}
                      className="h-9"
                      onChange={(e) =>
                        setScheduleForm((s) => ({
                          ...s,
                          [key]: s[key] ? { ...s[key], in: e.target.value } : { in: e.target.value, out: '17:00' },
                        }))
                      }
                    />
                    <Input
                      type="time"
                      value={scheduleForm[key]?.out ?? ''}
                      className="h-9"
                      onChange={(e) =>
                        setScheduleForm((s) => ({
                          ...s,
                          [key]: s[key] ? { ...s[key], out: e.target.value } : { in: '08:00', out: e.target.value },
                        }))
                      }
                    />
                  </div>
                ))}
              </div>
            </div>
            <DialogFooter className="flex-wrap gap-2">
              <Button type="button" variant="outline" onClick={() => setScheduleOpen(false)}>
                Cancel
              </Button>
              {hasWorkingDays(scheduleEmployee?.schedule) && (
                <Button
                  type="button"
                  variant="outline"
                  className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                  disabled={scheduleSubmitting}
                  onClick={handleClearSchedule}
                >
                  Clear schedule
                </Button>
              )}
              <Button type="submit" disabled={scheduleSubmitting}>
                {scheduleSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Clock className="size-4" />}
                Save schedule
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

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
              <Input
                id="reset-password"
                type="password"
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
                    Status: {previewEmployee?.is_active ? 'Active' : 'Inactive'}
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
                        <Input
                          type="text"
                          className="h-9 text-sm"
                          value={
                            previewEmployee?.employee_code
                            || previewEmployee?.employee_id
                            || (previewEmployee?.id ? `ID-${previewEmployee.id}` : '')
                          }
                          disabled
                        />
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
                          disabled={profilePhotoUploading}
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
                          disabled={profilePhotoUploading}
                          onChange={async (e) => {
                            const file = e.target.files?.[0]
                            if (!file || !previewEmployee) return
                            setProfilePhotoUploading(true)
                        setError(null)
                        try {
                              const data = await uploadEmployeePhoto(previewEmployee.id, file)
                              const emp = data.employee
                              setEmployees((prev) => prev.map((item) => (item.id === previewEmployee.id ? { ...item, ...emp } : item)))
                              setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? { ...p, ...emp } : p))
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
                            disabled={profilePhotoUploading}
                            onClick={async () => {
                              if (!previewEmployee) return
                              setProfilePhotoUploading(true)
                              setError(null)
                              try {
                                const data = await removeEmployeePhoto(previewEmployee.id)
                          const emp = data.employee
                                setEmployees((prev) => prev.map((item) => (item.id === previewEmployee.id ? { ...item, ...emp } : item)))
                                setPreviewEmployee((p) => (p && p.id === previewEmployee.id ? { ...p, ...emp } : p))
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
                  {previewEmployee && (
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
                  {previewEmployee && (
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
                  {previewEmployee && (
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
              <Button type="button" onClick={savePersonalInfo} disabled={profileDetailsSaving}>
                {profileDetailsSaving ? <Loader2 className="size-4 animate-spin" /> : 'Save Changes'}
              </Button>
            </div>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </motion.div>
  )
}
