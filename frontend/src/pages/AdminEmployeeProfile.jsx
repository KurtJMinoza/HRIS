import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Circle, FilePenLine, UserRound, BriefcaseBusiness, Sparkles, Clock3, Shield, Plus, ExternalLink, KeyRound, UserCog, UserX, Zap, FileText, LockKeyhole, BadgeCheck, Landmark, Building2, CreditCard, Car, Globe, Pencil, Upload, Eye, Trash2, AlertTriangle, CheckCircle2, Clock4, Phone, MapPin, X, Folder, FileUp, FileDown, Archive, Award, Gavel, HeartPulse, LineChart, IdCard, Mail, Calendar, Heart, MoreVertical, Camera, ArrowRightLeft, Loader2, Users } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { RoleBadge } from '@/components/RoleBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { PasswordInput } from '@/components/ui/password-input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import ApprovalRoutePreviewSection from '@/components/organization/ApprovalRoutePreviewSection'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { addEmployeeSkill, adjustEmployeeLeaveCredits, clearEmployeeSignature, createEmployeeCertification, createEmployeeDocument, createEmployeeGovernmentIdDocument, getAdminEmployeeScheduleRatePreview, getAdminEmployeeStatus, getDepartments, getCompanies, getBranches, getSectionsOrUnits, getEmployeeBenefits, getEmployeeCertifications, getEmployeeDocuments, getEmployeeGovernmentIdDocuments, getEmployeeOrganizationAssignments, getEmployeeProfileSnapshot, getEmployeeSkills, getEmployees, getPayrollPeriodsForEmployee, getSkillSuggestions, getWorkingSchedules, profileImageUrl, removeEmployeePhoto, removeEmployeeSkill, resetEmployeePassword, reviewEmployeeDocument, saveEmployeeSignature, toggleEmployeeActive, transferEmployee, updateEmployee, updateEmployeeCertification, updateEmployeeDocument, updateEmployeeGovernmentIdDocument, updateEmployeeSkill, updateProfile, uploadEmployeePhoto, verifyEmployeeCertification, verifyEmployeeGovernmentIdDocument } from '@/api'
import { motion as Motion } from 'framer-motion'
import { toast } from 'sonner'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ImageCropDialog } from '@/components/ImageCropDialog'
import { Autocomplete } from '@react-google-maps/api'
import { mapPlaceToAddressFields } from '@/lib/googlePlaces'
import { useGoogleMapsLoader } from '@/hooks/useGoogleMapsLoader'
import mammoth from 'mammoth'
import ESignatureCard from '@/components/ESignatureCard'
import SignaturePadDialog from '@/components/SignaturePadDialog'
import { ProfilePageSkeleton } from '@/components/skeletons'
import { LeaveCreditsCard } from '@/components/leave/LeaveCreditsCard'
import {
  DocumentCompactDropZone,
  DocumentsFolderEmptyState,
  getDocFolderGuidance,
  getFolderChecklistTeaser,
} from '@/lib/employeeDocumentsUi'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import { cn } from '@/lib/utils'
import { formatEmployeeName } from '@/lib/employeeSort'
import { formatScheduleLabel12h } from '@/lib/timeFormat'
import {
  SalaryAutomatedDeductionsCard,
  SalaryCompensationStructureCard,
  SalaryGovernmentDeductionExemptionsCard,
  SalaryPayComponentsBreakdownCard,
  SalaryPayrollHistoryCard,
  SalaryTaxInfoCard,
  SalaryTabNotice,
  SalaryTabShell,
} from '@/components/salary/EmployeeSalaryTab'
import { resolveTinForSalaryDisplay } from '@/components/salary/salaryTabFormatters'


function field(value) {
  return value || '—'
}

function hasText(value) {
  return String(value || '').trim() !== ''
}

/**
 * Profile header org line — uses `management_role` from API ({@see \App\Support\ManagementRole}).
 * Company heads are company-wide; avoid a single-branch pill inferred from department.
 */
function AdminProfileOrgScopePill({ employee }) {
  const mr = employee?.management_role
  if (mr === 'company_head') {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Landmark className="size-3 shrink-0" aria-hidden />
        {hasText(employee?.company_name) ? `${employee.company_name} · Company-wide` : 'Company-wide'}
      </span>
    )
  }
  if (mr === 'branch_head') {
    const b = employee?.managed_branch_name || employee?.branch_name || employee?.branch_office_location
    if (!hasText(b)) return null
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Building2 className="size-3 shrink-0" aria-hidden />
        {b}
      </span>
    )
  }
  if (mr === 'department_head') {
    const d = employee?.department
    if (!hasText(d)) return null
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Users className="size-3 shrink-0" aria-hidden />
        {d}
      </span>
    )
  }
  if (hasText(employee?.branch_name) || hasText(employee?.branch_office_location)) {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Building2 className="size-3 shrink-0" aria-hidden />
        {employee.branch_name || employee.branch_office_location}
      </span>
    )
  }
  return null
}

/** Parse optional money fields for admin salary form; empty → null. */
function parseSalaryNumber(value) {
  const s = String(value ?? '')
    .trim()
    .replace(/,/g, '')
  if (s === '') return null
  const n = Number(s)
  return Number.isFinite(n) ? n : null
}

function formatSalaryPhp(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return '—'
  return `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function AdminCompSummaryCard({ label, value }) {
  return (
    <div className="rounded-2xl border border-border/60 bg-muted/20 p-4 shadow-sm dark:border-white/8 dark:bg-white/3">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-2 text-2xl font-semibold tabular-nums text-foreground">{formatSalaryPhp(value)}</p>
    </div>
  )
}

/** Add calendar months to YYYY-MM-DD (probation milestone display; aligns with payroll hire-date rules). */
function hireDatePlusMonths(ymd, monthsToAdd) {
  if (!ymd || typeof ymd !== 'string') return '—'
  const parts = ymd.split('-').map((p) => parseInt(p, 10))
  if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) return '—'
  const [yy, mm, dd] = parts
  const dt = new Date(yy, mm - 1 + monthsToAdd, dd)
  const y = dt.getFullYear()
  const m = String(dt.getMonth() + 1).padStart(2, '0')
  const d = String(dt.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function formatMoneyInput(n) {
  if (!Number.isFinite(n) || n < 0) return ''
  return n.toFixed(2)
}

/** Parse H:i or H:i:s (API often returns MySQL time as "08:00:00"). */
function parseHm(value) {
  const text = String(value || '').trim()
  if (!text) return null
  const m = text.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/)
  if (!m) return null
  const hh = Number(m[1])
  const mm = Number(m[2])
  if (!Number.isFinite(hh) || !Number.isFinite(mm) || hh > 23 || mm > 59) return null
  return hh * 60 + mm
}

function computeScheduleWorkingHours(schedule) {
  const timeIn = parseHm(schedule?.time_in)
  const timeOut = parseHm(schedule?.time_out)
  if (timeIn == null || timeOut == null) return 0

  let minutes = timeOut - timeIn
  if (minutes <= 0) minutes += 24 * 60

  const breakStart = parseHm(schedule?.break_start)
  const breakEnd = parseHm(schedule?.break_end)
  if (breakStart != null && breakEnd != null) {
    let breakMinutes = breakEnd - breakStart
    if (breakMinutes <= 0) breakMinutes += 24 * 60
    minutes -= breakMinutes
  }

  return Number(Math.max(0, minutes / 60).toFixed(2))
}

function buildWorkingScheduleRateMeta(schedule) {
  if (!schedule) {
    return { workingDaysPerWeek: 0, workingDaysPerMonth: 0, workingHoursPerDay: 0, scheduleName: '' }
  }

  const restDays = Array.isArray(schedule.rest_days) ? schedule.rest_days : []
  const workingDaysPerWeek = Math.max(0, 7 - restDays.length)
  // Keep admin fallback aligned with backend payroll divisor:
  // 5d/week => 22 days/month, 6d/week => 26 days/month.
  const workingDaysPerMonth = Number(Math.max(1, Math.round((workingDaysPerWeek * 52) / 12)))
  const workingHoursPerDay = computeScheduleWorkingHours(schedule)

  return {
    workingDaysPerWeek,
    workingDaysPerMonth,
    workingHoursPerDay,
    scheduleName: schedule.name || '',
  }
}

/** Backend {@see ScheduleRateService} fields on user/employee or schedule-rate-preview payload. */
function scheduleMetricsFromApiPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return { workingDaysPerWeek: 0, workingDaysPerMonth: 0, workingHoursPerDay: 0, scheduleName: '' }
  }
  const workingDaysPerWeek = Number(payload.schedule_working_days_per_week || 0)
  let workingDaysPerMonth = Number(payload.schedule_working_days_per_month || 0)
  const calendarDays = Number(payload.schedule_working_days_in_calendar_month || 0)
  const divisorSource = String(payload.schedule_rate_divisor_source || '')
  // Align with self-service `buildSalaryScheduleSummaryLine` — calendar-month divisor when that drives rates.
  if (divisorSource === 'calendar_month_schedule' && Number.isFinite(calendarDays) && calendarDays > 0) {
    workingDaysPerMonth = calendarDays
  }
  const workingHoursPerDay = Number(payload.schedule_working_hours_per_day || 0)
  if (workingDaysPerMonth > 0 || workingHoursPerDay > 0) {
    return {
      workingDaysPerWeek: Number.isFinite(workingDaysPerWeek) ? workingDaysPerWeek : 0,
      workingDaysPerMonth: Number.isFinite(workingDaysPerMonth) ? workingDaysPerMonth : 0,
      workingHoursPerDay: Number.isFinite(workingHoursPerDay) ? workingHoursPerDay : 0,
      scheduleName: payload.working_schedule_name || payload.schedule_name || 'Current work schedule',
    }
  }
  return { workingDaysPerWeek: 0, workingDaysPerMonth: 0, workingHoursPerDay: 0, scheduleName: '' }
}

function buildEmployeeScheduleRateMeta(employee) {
  return scheduleMetricsFromApiPayload(employee)
}

/** From basic monthly salary: daily = monthly / schedule days, hourly = daily / schedule hours. */
function deriveDerivedSalaryFieldsFromBasic(monthlyStr, scheduleRateMeta) {
  const m = parseSalaryNumber(monthlyStr)
  if (m === null || m <= 0 || !scheduleRateMeta?.workingDaysPerMonth || !scheduleRateMeta?.workingHoursPerDay) {
    return { daily_rate: '', hourly_rate: '', monthly_rate: '' }
  }
  const daily = m / scheduleRateMeta.workingDaysPerMonth
  const hourly = daily / scheduleRateMeta.workingHoursPerDay
  return {
    daily_rate: formatMoneyInput(daily),
    hourly_rate: formatMoneyInput(hourly),
    monthly_rate: formatMoneyInput(m),
  }
}

function normalizeEmploymentStatusValue(raw) {
  const value = String(raw || '').trim().toLowerCase().replace(/[-\s]+/g, '_')
  if (!value) return ''
  const aliases = {
    probation: 'probationary',
    probational: 'probationary',
    probationary: 'probationary',
    regular: 'regular',
    active: 'regular',
    contract: 'contractual',
    contractual: 'contractual',
    project: 'project_based',
    project_based: 'project_based',
    projectbased: 'project_based',
    consultant: 'consultant',
    consultancy: 'consultant',
    separated: 'separated',
    inactive: 'separated',
    resigned: 'separated',
    terminated: 'separated',
  }
  return aliases[value] || ''
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

function isValidEmailAddress(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim())
}

function isValidPhMobile(number) {
  return /^(\+63\s?9\d{9}|09\d{9})$/.test(String(number || '').trim())
}

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
    return {
      street_address: '',
      barangay: '',
      city: '',
      province: '',
      postal_code: '',
    }
  }
  const segments = text.split(',').map((p) => p.trim()).filter(Boolean)
  const [street_address = '', barangay = '', city = '', province = '', postalRaw = ''] = segments
  const postal_code = String(postalRaw || '')
    .replace(/[^\d]/g, '')
    .slice(0, 4)
  return {
    street_address,
    barangay,
    city,
    province,
    postal_code,
  }
}

/** Age in full years from YYYY-MM-DD (for DOB helper text). */
function ageFromDob(isoDate) {
  if (!isoDate) return null
  const d = new Date(`${String(isoDate).slice(0, 10)}T12:00:00`)
  if (Number.isNaN(d.getTime())) return null
  const t = new Date()
  let a = t.getFullYear() - d.getFullYear()
  if (t.getMonth() < d.getMonth() || (t.getMonth() === d.getMonth() && t.getDate() < d.getDate())) a -= 1
  return a >= 0 && a < 130 ? a : null
}

function parseIsoDateOnly(isoDate) {
  const raw = String(isoDate || '').trim()
  if (!raw) return null
  const [yy, mm, dd] = raw.split('-').map((part) => Number(part))
  if (!Number.isFinite(yy) || !Number.isFinite(mm) || !Number.isFinite(dd)) return null
  const dt = new Date(yy, mm - 1, dd)
  if (Number.isNaN(dt.getTime())) return null
  return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate())
}

function addYearsDateOnly(date, years) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null
  return new Date(date.getFullYear() + years, date.getMonth(), date.getDate())
}

function FieldHint({ children }) {
  return <p className="text-xs text-muted-foreground leading-relaxed">{children}</p>
}

function InlineValidationMessage({ tone = 'info', children }) {
  const toneClass =
    tone === 'success'
      ? 'border-emerald-200/80 bg-emerald-50/70 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/25 dark:text-emerald-200'
      : tone === 'error'
        ? 'border-red-200/80 bg-red-50/70 text-red-800 dark:border-red-900/50 dark:bg-red-950/25 dark:text-red-200'
        : 'border-amber-200/80 bg-amber-50/70 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/25 dark:text-amber-200'
  const Icon = tone === 'success' ? CheckCircle2 : AlertTriangle
  return (
    <div className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-xs leading-relaxed ${toneClass}`}>
      <Icon className="mt-0.5 size-3.5 shrink-0" aria-hidden />
      <p>{children}</p>
    </div>
  )
}

function getProfileFriendlyError(error) {
  const raw = String(error?.message || '').toLowerCase()
  if (raw.includes('timed out') || raw.includes('timeout') || raw.includes('time out')) {
    return 'Request timed out. The server may be busy — your changes were not saved. Try again or check your connection.'
  }
  if (
    raw.includes('users_phone_number_unique') ||
    (raw.includes('duplicate entry') && raw.includes('phone_number')) ||
    raw.includes('phone number is already in use')
  ) {
    return 'This phone number is already used by another employee.'
  }
  if (raw.includes('users_email_unique') || (raw.includes('duplicate entry') && raw.includes('email'))) {
    return 'This email address is already used by another employee.'
  }
  if (raw.includes('invalid server response') || raw.includes('missing employee')) {
    return error?.message || 'Save failed — the server response was incomplete. Please reload and try again.'
  }
  return error?.message || 'Failed to update profile.'
}

function initials(name) {
  return (name || '?')
    .trim()
    .split(/\s+/)
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '?'
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  if (Number.isNaN(date.getTime())) return dateStr
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  if (Number.isNaN(date.getTime())) return dateStr
  return date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function validateAccountPasswordConfirm(newPassword, confirmPassword) {
  const c = String(confirmPassword || '')
  if (!c) return 'Confirm your new password.'
  if (String(newPassword || '') !== c) return 'Password confirmation does not match.'
  return ''
}

function validateTempPassword(value) {
  const v = String(value || '')
  const trimmed = v.trim()
  if (trimmed.length === 0) return 'Password is required.'
  if (trimmed.length < 8) return 'Password must be at least 8 characters.'
  // Block emojis / "font generator" unicode by allowing only printable ASCII.
  if (!/^[\x20-\x7E]+$/.test(trimmed)) return 'Use only standard letters, numbers, and symbols (no emojis or special fonts).'
  // Disallow whitespace to avoid copy/paste surprises.
  if (/\s/.test(trimmed)) return 'Password cannot contain spaces.'
  return ''
}

function isManagerialPosition(position) {
  const p = String(position || '').toLowerCase()
  return p.includes('manager') || p.includes('supervisor') || p.includes('lead') || p.includes('head')
}

function hasWorkingDays(schedule) {
  if (!schedule || typeof schedule !== 'object') return false
  return Object.values(schedule).some((v) => v && v.in && v.out)
}

function hasAssignedSchedule(employee) {
  if (!employee || typeof employee !== 'object') return false
  if (employee.schedule && hasWorkingDays(employee.schedule)) return true
  if (employee.working_schedule_id !== null && employee.working_schedule_id !== undefined && employee.working_schedule_id !== '') return true
  return false
}

function formatFileSize(bytes) {
  const size = Number(bytes || 0)
  if (!Number.isFinite(size) || size <= 0) return '0 B'
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
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

function employeeExtrasKey(employeeId) {
  return `employee-profile-extras:${employeeId}`
}

function createEmptyBenefitsState() {
  return {
    coverages: [],
    leave_balances: [],
    allowances: [],
    perks: [],
    resources: [],
  }
}

function toText(value) {
  if (value == null) return ''
  // Never allow "null"/"undefined" literal strings to leak into inputs.
  const out = String(value).trim()
  if (out === 'null' || out === 'undefined') return ''
  return out
}

function toBenefitsState(assignments) {
  const state = createEmptyBenefitsState()
  const list = Array.isArray(assignments) ? assignments : []
  list.forEach((assignment, index) => {
    const meta =
      assignment?.metadata && typeof assignment.metadata === 'object'
        ? assignment.metadata
        : assignment?.catalog?.metadata && typeof assignment.catalog.metadata === 'object'
          ? assignment.catalog.metadata
          : {}
    const type = toText(assignment?.catalog?.type || 'other').toLowerCase()
    const planName = toText(assignment?.catalog?.name) || 'Unnamed Benefit'
    const effectiveDate = toText(assignment?.effective_date)
    const status = toText(assignment?.status) || 'active'

    if (type === 'allowance') {
      state.allowances.push({
        id: assignment?.id || `allowance-${index}`,
        label: planName,
        amount: toText(meta.amount),
      })
      return
    }

    if (type === 'leave_benefits') {
      const leaveDays = toText(meta.leave_days || meta.days || meta.balance)
      state.leave_balances.push({
        id: assignment?.id || `leave-${index}`,
        label: planName,
        value: leaveDays || '0',
        unit: 'days',
        accent: Number(leaveDays) > 0 ? Math.min(100, Math.max(15, Number(leaveDays) * 5)) : 0,
        note: toText(meta.frequency) || 'No accrual details provided',
      })
      return
    }

    if (type === 'other') {
      state.perks.push(planName)
      return
    }

    state.coverages.push({
      id: assignment?.id || `coverage-${index}`,
      label: type === 'health_insurance' ? 'Health Insurance' : type === 'retirement_plan' ? 'Retirement' : 'Coverage',
      plan: planName,
      provider: toText(meta.provider),
      coverage_type: toText(meta.coverage || meta.coverage_type),
      effective_date: effectiveDate,
      monthly_premium: toText(meta.amount || meta.contribution),
      contribution_note: toText(meta.frequency),
      status: status.charAt(0).toUpperCase() + status.slice(1),
    })
  })
  return state
}

function formatMoney(value) {
  const amount = Number(value || 0)
  if (!Number.isFinite(amount)) return 'PHP 0.00'
  return `PHP ${amount.toFixed(2)}`
}

function sanitizeAsciiByRegex(value, allowedCharRegex, maxLength = 40) {
  const source = String(value || '').replace(/[^\x20-\x7E]/g, '')
  let out = ''
  for (const ch of source) {
    if (allowedCharRegex.test(ch)) out += ch
  }
  return out.slice(0, maxLength)
}

function sanitizeGovIdByField(fieldName, value) {
  const source = String(value || '').replace(/[^\x20-\x7E]/g, '')
  const digitsAndHyphen = source.replace(/[^0-9-]/g, '')
  const limits = { tin: 15, sss: 12, philhealth: 14, pagibig: 14 }
  return digitsAndHyphen.slice(0, limits[fieldName] || 24)
}

function validateGovIdByField(fieldName, value) {
  const v = String(value || '').trim()
  if (!v) return ''
  const formats = {
    tin: /^\d{3}-\d{3}-\d{3}-\d{3}$/,
    sss: /^\d{2}-\d{7}-\d{1}$/,
    philhealth: /^\d{2}-\d{9}-\d{1}$/,
    pagibig: /^\d{4}-\d{4}-\d{4}$/,
  }
  const helpText = {
    tin: 'Use format 000-000-000-000.',
    sss: 'Use format 00-0000000-0.',
    philhealth: 'Use format 00-000000000-0.',
    pagibig: 'Use format 0000-0000-0000.',
  }
  if (!formats[fieldName]?.test(v)) return helpText[fieldName] || 'Invalid ID format.'
  return ''
}

function _daysUntil(dateStr) {
  if (!dateStr) return null
  const d = new Date(dateStr)
  if (Number.isNaN(d.getTime())) return null
  const now = new Date()
  const utcNow = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())
  const utcDate = Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())
  return Math.ceil((utcDate - utcNow) / (1000 * 60 * 60 * 24))
}

function createEmptyGovIdsState() {
  return {
    tin: '',
    sss: '',
    philhealth: '',
    pagibig: '',
  }
}

function createDefaultGovIdVerificationState() {
  return {
    tin: 'pending',
    sss: 'pending',
    philhealth: 'pending',
    pagibig: 'pending',
  }
}

function createEmptyGovIdDocumentsState() {
  return {
    tin: null,
    sss: null,
    philhealth: null,
    pagibig: null,
  }
}

function createDefaultSecondaryIdsState() {
  return [
    { id: 'driver-license', type: "Driver's License", number: '', expiry_date: '', status: 'valid' },
    { id: 'passport', type: 'Passport', number: '', expiry_date: '', status: 'valid' },
    { id: 'umid', type: 'UMID Card', number: '', expiry_date: '', status: 'lifetime' },
  ]
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

function buildProfileSnapshot(form, skills, certifications, govIds, secondaryIds, govIdVerification, govIdDocuments, emergencyContacts) {
  return JSON.stringify({
    form: {
      first_name: form.first_name || '',
      middle_name: form.middle_name || '',
      last_name: form.last_name || '',
      suffix: form.suffix || '',
      email: form.email || '',
      phone_number: form.phone_number || '',
      date_of_birth: form.date_of_birth || '',
      gender: form.gender || '',
      civil_status: form.civil_status || '',
      nationality: form.nationality || '',
      home_address: form.home_address || '',
      department_id: form.department_id || '',
      position: form.position || '',
      employment_status: form.employment_status || '',
      employment_status_effective_date: form.employment_status_effective_date || '',
      employment_type: form.employment_type || '',
      branch_office_location: form.branch_office_location || '',
      hire_date: form.hire_date || '',
      contract_start_date: form.contract_start_date || '',
      contract_end_date: form.contract_end_date || '',
      supervisor_id: form.supervisor_id || '',
      monthly_salary: form.monthly_salary || '',
      hourly_rate: form.hourly_rate || '',
      salary_effectivity_date: form.salary_effectivity_date || '',
      daily_rate: form.daily_rate || '',
      monthly_rate: form.monthly_rate || '',
    },
    skills: Array.isArray(skills) ? skills : [],
    certifications: Array.isArray(certifications)
      ? certifications.map((c) => ({
          certification_name: c.certification_name,
          issuing_organization: c.issuing_organization,
          issue_date: c.issue_date,
          expiration_date: c.expiration_date,
          verification_status: c.verification_status,
        }))
      : [],
    government_ids: govIds || createEmptyGovIdsState(),
    secondary_ids: Array.isArray(secondaryIds)
      ? secondaryIds.map((item) => ({
          id: item.id,
          type: item.type,
          number: item.number,
          expiry_date: item.expiry_date,
          status: item.status,
        }))
      : [],
    gov_id_verification: govIdVerification || createDefaultGovIdVerificationState(),
    gov_id_documents: govIdDocuments
      ? {
          tin: govIdDocuments.tin ? { name: govIdDocuments.tin.name, type: govIdDocuments.tin.type, size: govIdDocuments.tin.size, uploaded_at: govIdDocuments.tin.uploaded_at } : null,
          sss: govIdDocuments.sss ? { name: govIdDocuments.sss.name, type: govIdDocuments.sss.type, size: govIdDocuments.sss.size, uploaded_at: govIdDocuments.sss.uploaded_at } : null,
          philhealth: govIdDocuments.philhealth ? { name: govIdDocuments.philhealth.name, type: govIdDocuments.philhealth.type, size: govIdDocuments.philhealth.size, uploaded_at: govIdDocuments.philhealth.uploaded_at } : null,
          pagibig: govIdDocuments.pagibig ? { name: govIdDocuments.pagibig.name, type: govIdDocuments.pagibig.type, size: govIdDocuments.pagibig.size, uploaded_at: govIdDocuments.pagibig.uploaded_at } : null,
        }
      : createEmptyGovIdDocumentsState(),
    emergency_contacts: Array.isArray(emergencyContacts)
      ? emergencyContacts.map((item) => ({
          id: item.id,
          full_name: item.full_name,
          relationship: item.relationship,
          phone_number: item.phone_number,
          address: item.address,
          is_primary: !!item.is_primary,
        }))
      : [],
  })
}

export default function AdminEmployeeProfile() {
  const { user, setUser } = useAuth()
  const { employeeId: routeEmployeeId } = useParams()
  const effectiveEmployeeId = routeEmployeeId ?? user?.id ?? null
  const isOwnProfile = Number(effectiveEmployeeId) === Number(user?.id)
  const roleValue = String(user?.role || '').trim().toLowerCase()
  const hrRoleValue = String(user?.hr_role || '').trim().toLowerCase()
  const isAdminOrHr = roleValue === 'admin' || roleValue === 'super_admin' || hrRoleValue === 'admin_hr' || hrRoleValue === 'admin'
  const permissions = user?.permissions ?? []
  const hasSelfEditPermission = permissions.includes('profile.edit')
    || permissions.includes('edit-own-profile')
    || permissions.includes('employees.edit')
  const canEditProfile = isAdminOrHr || (isOwnProfile && hasSelfEditPermission)
  const canEditProfilePhoto = isAdminOrHr
    || (isOwnProfile && (permissions.includes('profile.picture.edit') || hasSelfEditPermission))
  // Self-profile editing should honor profile-level permissions, not only employees.edit.
  const canEditEmployeeRecord = isAdminOrHr || (isOwnProfile && hasSelfEditPermission)
  const canViewPayrollHistory = (user?.permissions ?? []).includes('payroll.view')
  /** Backend: `employees.sensitive` + `profile.salary.edit`; Laravel `admin` role bypasses checks server-side. */
  const canEditSalaryDetails =
    isAdminOrHr || permissions.includes('profile.salary.edit')
  const employeeId = effectiveEmployeeId
  const queryClient = useQueryClient()
  const refreshAdminEmployeeCaches = useCallback(
    async (id) => {
      if (id == null || id === '') return
      const sid = String(id)
      await queryClient.invalidateQueries({ queryKey: ['admin-employee-profile-snapshot', sid] })
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
    },
    [queryClient],
  )
  const navigate = useNavigate()
  const location = useLocation()
  const hrBase = useHrBasePath()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [employee, setEmployee] = useState(null)
  const [compensationSummary, setCompensationSummary] = useState(null)
  const [payrollPeriods, setPayrollPeriods] = useState([])
  const [payrollPeriodsLoading, setPayrollPeriodsLoading] = useState(false)
  const [payrollPeriodsError, setPayrollPeriodsError] = useState('')
  const [leaveCreditsBlock, setLeaveCreditsBlock] = useState(null)
  const [leaveCreditsLoading, setLeaveCreditsLoading] = useState(false)

  useEffect(() => {
    setLeaveCreditsBlock(null)
    setLeaveCreditsLoading(false)
  }, [employeeId])

  const [employeeStatusHistory, setEmployeeStatusHistory] = useState([])
  const [leaveAdjustOpen, setLeaveAdjustOpen] = useState(false)
  const [leaveAdjustDelta, setLeaveAdjustDelta] = useState('')
  const [leaveAdjustReason, setLeaveAdjustReason] = useState('')
  const [leaveAdjustSaving, setLeaveAdjustSaving] = useState(false)
  const [allEmployees, setAllEmployees] = useState([])
  const [departments, setDepartments] = useState([])
  const [sectionsOrUnits, setSectionsOrUnits] = useState([])
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [workingSchedules, setWorkingSchedules] = useState([])
  /** When editing, form schedule differs from saved — server preview (calendar month + holidays). */
  const [scheduleRatePreview, setScheduleRatePreview] = useState(null)
  const [saving, setSaving] = useState(false)
  const [saveStatus, setSaveStatus] = useState('idle')
  const saveStatusTimerRef = useRef(null)
  /** Snapshot for rollback if PATCH /admin/employees/{id} fails after optimistic UI update. */
  const profileSaveRollbackRef = useRef(null)
  const [signatureBusy, setSignatureBusy] = useState(false)
  const [signatureDialogOpen, setSignatureDialogOpen] = useState(false)
  const [photoSaving, setPhotoSaving] = useState(false)
  const [removePhotoConfirmOpen, setRemovePhotoConfirmOpen] = useState(false)
  const [photoCropOpen, setPhotoCropOpen] = useState(false)
  const [pendingPhotoFile, setPendingPhotoFile] = useState(null)
  const [skillAddOpen, setSkillAddOpen] = useState(false)
  const [skillRenameOpen, setSkillRenameOpen] = useState(false)
  const [skillPreviewOpen, setSkillPreviewOpen] = useState(false)
  const [activeSkill, setActiveSkill] = useState(null)
  const [skillDraft, setSkillDraft] = useState('')
  const [skillDraftError, setSkillDraftError] = useState('')
  const [skillSuggestions, setSkillSuggestions] = useState([])
  const [skillSuggestionsLoading, setSkillSuggestionsLoading] = useState(false)
  const [skills, setSkills] = useState([]) // [{ id, name }]
  const [skillsLoading, setSkillsLoading] = useState(false)
  const [skillsSaving, setSkillsSaving] = useState(false)
  const [certifications, setCertifications] = useState([])
  const [certificationsLoading, setCertificationsLoading] = useState(false)
  const [certificationsSaving, setCertificationsSaving] = useState(false)
  const [certModalOpen, setCertModalOpen] = useState(false)
  const [certEditModalOpen, setCertEditModalOpen] = useState(false)
  const [certPreviewOpen, setCertPreviewOpen] = useState(false)
  const [certVerifyOpen, setCertVerifyOpen] = useState(false)
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
  const [verifyStatus, setVerifyStatus] = useState('verified')
  const [verifyReason, setVerifyReason] = useState('')
  const [govIds, setGovIds] = useState(createEmptyGovIdsState())
  const [secondaryIds, setSecondaryIds] = useState(createDefaultSecondaryIdsState())
  const [govIdVerification, setGovIdVerification] = useState(createDefaultGovIdVerificationState())
  const [govIdDocuments, setGovIdDocuments] = useState(createEmptyGovIdDocumentsState())
  const [govDocs, setGovDocs] = useState([])
  const [govDocsLoading, setGovDocsLoading] = useState(false)
  const [govDocsSaving, setGovDocsSaving] = useState(false)
  const [govAddOpen, setGovAddOpen] = useState(false)
  const [govEditOpen, setGovEditOpen] = useState(false)
  const [govPreviewOpen, setGovPreviewOpen] = useState(false)
  const [govVerifyOpen, setGovVerifyOpen] = useState(false)
  const [govDeleteOpen, setGovDeleteOpen] = useState(false)
  const [govDeleteDoc, setGovDeleteDoc] = useState(null)
  const [activeGovDoc, setActiveGovDoc] = useState(null)
  const [govForm, setGovForm] = useState({ id_type: '', id_number: '', issuing_agency: '', expiry_date: '', document_file: null })
  const [govErrors, setGovErrors] = useState({})
  const [govVerifyStatus, setGovVerifyStatus] = useState('approved')
  const [govVerifyReason, setGovVerifyReason] = useState('')
  const [transferOpen, setTransferOpen] = useState(false)
  const [transferForm, setTransferForm] = useState({
    targetBranchId: '',
    transferDate: new Date().toISOString().slice(0, 10),
    departmentId: '',
    reason: '',
  })
  const [transferBranches, setTransferBranches] = useState([])
  const [transferBranchesLoading, setTransferBranchesLoading] = useState(false)
  const [transferDepartments, setTransferDepartments] = useState([])
  const [transferSaving, setTransferSaving] = useState(false)

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
  const [docReviewOpen, setDocReviewOpen] = useState(false)
  const [activeDoc, setActiveDoc] = useState(null)
  const [docForm, setDocForm] = useState({ category: 'Contracts', document_name: '', version: '', expiry_date: '', file: null })
  const [docErrors, setDocErrors] = useState({})
  const [docReviewStatus, setDocReviewStatus] = useState('active')
  const [docReviewNote, setDocReviewNote] = useState('')
  const [docDragOver, setDocDragOver] = useState(false)
  const docDropZoneRef = useRef(null)
  const [docxPreviewHtml, setDocxPreviewHtml] = useState('')
  const [docxPreviewLoading, setDocxPreviewLoading] = useState(false)
  const [docxPreviewError, setDocxPreviewError] = useState('')
  const [emergencyContacts, setEmergencyContacts] = useState([])
  const [emergencyForm, setEmergencyForm] = useState(createEmptyEmergencyContact())
  const [editingEmergencyId, setEditingEmergencyId] = useState('')
  const [emergencyErrors, setEmergencyErrors] = useState({})
  const [_showSecondaryIds, _setShowSecondaryIds] = useState(true)
  const [benefitsData, setBenefitsData] = useState(createEmptyBenefitsState())
  const [benefitsEditMode, setBenefitsEditMode] = useState(false)
  const [benefitsLoading, setBenefitsLoading] = useState(false)
  const [organizationAssignments, setOrganizationAssignments] = useState([])
  const [organizationAssignmentsLoading, setOrganizationAssignmentsLoading] = useState(false)
  const [benefitsError, setBenefitsError] = useState('')
  const [reportingManagerAutoHint, setReportingManagerAutoHint] = useState('')
  const [reportingManagerManualOverride, setReportingManagerManualOverride] = useState(false)
  const [activeTab, setActiveTab] = useState('personal-info')
  const [accountEmail, setAccountEmail] = useState('')
  const [accountPhone, setAccountPhone] = useState('')
  const [accountCurrentPassword, setAccountCurrentPassword] = useState('')
  const [accountNewPassword, setAccountNewPassword] = useState('')
  const [accountConfirmPassword, setAccountConfirmPassword] = useState('')
  const [accountBusy, setAccountBusy] = useState({ email: false, phone: false, password: false })
  const [accountErrors, setAccountErrors] = useState({ email: '', phone: '', password: '' })
  const [accountPasswordFieldErrors, setAccountPasswordFieldErrors] = useState({ current: '', new: '', confirm: '' })
  const [deferredSectionLoading, setDeferredSectionLoading] = useState({
    salary: false,
    government: false,
    emergency: false,
  })
  const deferredLoadedRef = useRef({
    salaryCore: false,
    benefits: false,
    skills: false,
    certifications: false,
    /** HR form gov numbers from profile snapshot (Gov IDs tab). */
    governmentIds: false,
    /** Uploaded gov ID documents — same API as Gov IDs tab; used for Salary TIN. */
    govIdDocumentsLoaded: false,
    emergencyContacts: false,
    documents: false,
    payrollHistory: false,
  })

  useEffect(() => {
    return () => {
      if (saveStatusTimerRef.current) {
        clearTimeout(saveStatusTimerRef.current)
      }
    }
  }, [])

  useEffect(() => {
    deferredLoadedRef.current = {
      salaryCore: false,
      benefits: false,
      skills: false,
      certifications: false,
      governmentIds: false,
      govIdDocumentsLoaded: false,
      emergencyContacts: false,
      documents: false,
      payrollHistory: false,
    }
  }, [employeeId])

  useEffect(() => {
    if (!isOwnProfile || !employee) return
    setAccountEmail(String(employee.email || ''))
    setAccountPhone(String(employee.phone_number || ''))
  }, [isOwnProfile, employee?.id, employee?.email, employee?.phone_number])

  useEffect(() => {
    const onSchedulesChanged = () => {
      const sid = String(employeeId || '')
      if (!sid) return
      // Realtime schedule propagation: refresh profile snapshot so Salary-tab schedule hints
      // (working days/month, hours/day, derived rates) reflect Admin->Schedules changes immediately.
      void queryClient.invalidateQueries({ queryKey: ['admin-employee-profile-snapshot', sid] })
      void queryClient.refetchQueries({ queryKey: ['admin-employee-profile-snapshot', sid], type: 'active' })
    }
    window.addEventListener('hr:schedules-changed', onSchedulesChanged)
    return () => window.removeEventListener('hr:schedules-changed', onSchedulesChanged)
  }, [employeeId, queryClient])

  const profileSnapshotQuery = useQuery({
    queryKey: ['admin-employee-profile-snapshot', String(employeeId || '')],
    enabled: Boolean(employeeId),
    staleTime: 20 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchOnMount: false,
    retry: 1,
    queryFn: () =>
      getEmployeeProfileSnapshot(employeeId, {
        lite: true,
        include_government_ids: false,
        include_emergency_contacts: false,
        include_benefits: false,
        include_leave_credits: false,
        include_compensation_summary: false,
        include_pay_cycle_preview: false,
      }),
  })
  const [resetPasswordOpen, setResetPasswordOpen] = useState(false)
  const [resetPasswordValue, setResetPasswordValue] = useState('')
  const resetPasswordError = useMemo(() => validateTempPassword(resetPasswordValue), [resetPasswordValue])
  const [toggleActiveOpen, setToggleActiveOpen] = useState(false)
  const [toggleActiveSaving, setToggleActiveSaving] = useState(false)
  const [initialSnapshot, setInitialSnapshot] = useState(null)
  const fileInputRef = useRef(null)
  const photoInputRef = useRef(null)
  const govIdFileInputRef = useRef(null)
  /** Hidden file inputs for Government IDs tab upload / edit modals (separate from salary TIN picker ref). */
  const govUploadModalFileRef = useRef(null)
  const govEditModalFileRef = useRef(null)
  const skillInputRef = useRef(null)
  const reportingManagerOrgKeyRef = useRef('')
  const [activeGovIdFileField, setActiveGovIdFileField] = useState('')
  const homeAddressAutocompleteRef = useRef(null)
  const streetAddressAutocompleteRef = useRef(null)
  const barangayAutocompleteRef = useRef(null)
  const cityAutocompleteRef = useRef(null)
  const provinceAutocompleteRef = useRef(null)
  const emergencyAddressAutocompleteRef = useRef(null)
  const { isLoaded: isMapsLoaded, loadError: mapsLoadError } = useGoogleMapsLoader()
  const [addressTouched, setAddressTouched] = useState(false)
  const [form, setForm] = useState({
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    username: '',
    email: '',
    phone_number: '',
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
    department_id: '',
    section_unit_id: '',
    company_id: '',
    branch_id: '',
    position: '',
    employment_status: 'probationary',
    employment_status_effective_date: '',
    employment_type: '',
    branch_office_location: '',
    hire_date: '',
    payroll_effective_date: '',
    contract_start_date: '',
    contract_end_date: '',
    supervisor_id: '',
    working_schedule_id: '',
    monthly_salary: '',
    hourly_rate: '',
    salary_effectivity_date: '',
    daily_rate: '',
    monthly_rate: '',
  })
  const selectedWorkingSchedule = useMemo(
    () => workingSchedules.find((item) => String(item.id) === String(form.working_schedule_id || employee?.working_schedule_id || '')) || null,
    [workingSchedules, form.working_schedule_id, employee?.working_schedule_id]
  )
  const adminEffectivePayCyclePreview = useMemo(
    () => employee?.pay_cycle_preview ?? compensationSummary?.pay_cycle_preview ?? null,
    [employee?.pay_cycle_preview, compensationSummary?.pay_cycle_preview],
  )
  const salaryTinResolution = useMemo(
    () => resolveTinForSalaryDisplay(govDocs, govIds?.tin, { loading: govDocsLoading }),
    [govDocs, govIds?.tin, govDocsLoading],
  )
  const scheduleRateMeta = useMemo(() => {
    const savedScheduleId = String(employee?.working_schedule_id ?? '')
    const formScheduleId = String(form.working_schedule_id ?? '')
    const matchesSavedAssignment = Boolean(savedScheduleId && formScheduleId === savedScheduleId)

    // Prefer API schedule metrics (calendar-month divisor from ScheduleRateService) when the form matches the saved assignment.
    if (matchesSavedAssignment) {
      const fromEmployee = buildEmployeeScheduleRateMeta(employee)
      if (fromEmployee.workingDaysPerMonth > 0 && fromEmployee.workingHoursPerDay > 0) {
        return fromEmployee
      }
    }

    // Unsaved schedule change: use server preview for the selected template (same logic as payroll).
    if (!matchesSavedAssignment && scheduleRatePreview) {
      const fromPreview = scheduleMetricsFromApiPayload(scheduleRatePreview)
      if (fromPreview.workingDaysPerMonth > 0 && fromPreview.workingHoursPerDay > 0) {
        return {
          ...fromPreview,
          scheduleName: scheduleRatePreview.schedule_name || selectedWorkingSchedule?.name || fromPreview.scheduleName,
        }
      }
    }

    const selectedMeta = buildWorkingScheduleRateMeta(selectedWorkingSchedule)
    if (selectedMeta.workingDaysPerMonth > 0 && selectedMeta.workingHoursPerDay > 0) {
      if (matchesSavedAssignment) {
        const fromEmployee = buildEmployeeScheduleRateMeta(employee)
        if (selectedMeta.workingDaysPerMonth > 0 && fromEmployee.workingHoursPerDay > 0) {
          return { ...selectedMeta, workingHoursPerDay: fromEmployee.workingHoursPerDay }
        }
      }
      return selectedMeta
    }

    if (matchesSavedAssignment) {
      const fromEmployee = buildEmployeeScheduleRateMeta(employee)
      if (fromEmployee.workingHoursPerDay > 0) {
        return { ...selectedMeta, workingHoursPerDay: fromEmployee.workingHoursPerDay }
      }
    }
    return selectedMeta
  }, [selectedWorkingSchedule, form.working_schedule_id, employee, scheduleRatePreview])

  useEffect(() => {
    const empId = employee?.id
    const saved = String(employee?.working_schedule_id ?? '')
    const formId = String(form.working_schedule_id ?? '')
    if (!empId || !formId || formId === saved) {
      setScheduleRatePreview(null)
      return undefined
    }
    let cancelled = false
    const t = setTimeout(() => {
      getAdminEmployeeScheduleRatePreview(empId, formId)
        .then((data) => {
          if (!cancelled) setScheduleRatePreview(data)
        })
        .catch(() => {
          if (!cancelled) setScheduleRatePreview(null)
        })
    }, 280)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [employee?.id, employee?.working_schedule_id, form.working_schedule_id])

  /** Single source of truth for read-only salary rate fields (updates every render with monthly + schedule). */
  const liveDerivedSalary = useMemo(
    () => deriveDerivedSalaryFieldsFromBasic(form.monthly_salary, scheduleRateMeta),
    [form.monthly_salary, scheduleRateMeta]
  )
  const grossPayPreview = useMemo(() => {
    const liveMonthly = parseSalaryNumber(form.monthly_salary)
    const currentMonthly = parseSalaryNumber(employee?.monthly_salary)
    const currentGross = Number(compensationSummary?.totals?.gross_earnings ?? 0)
    if (liveMonthly == null) return currentGross
    if (currentMonthly == null || !Number.isFinite(currentGross)) return liveMonthly
    return Math.max(0, currentGross - currentMonthly + liveMonthly)
  }, [form.monthly_salary, employee?.monthly_salary, compensationSummary?.totals?.gross_earnings])

  useEffect(() => {
    setForm((prev) => {
      const derived = deriveDerivedSalaryFieldsFromBasic(prev.monthly_salary, scheduleRateMeta)
      if (
        prev.daily_rate === derived.daily_rate
        && prev.hourly_rate === derived.hourly_rate
        && prev.monthly_rate === derived.monthly_rate
      ) {
        return prev
      }
      return {
        ...prev,
        ...derived,
      }
    })
  }, [form.monthly_salary, scheduleRateMeta])

  const applyMappedHomeAddress = useCallback((place) => {
      try {
        const mapped = mapPlaceToAddressFields(place)
        setAddressTouched(true)
        setForm((prev) => ({
          ...prev,
          home_address: mapped.full_address || prev.home_address,
          street_address: mapped.street_address || '',
          barangay: mapped.barangay || '',
          city: mapped.city || '',
          province: mapped.province || '',
          postal_code: String(mapped.postal_code || '')
            .replace(/[^\d]/g, '')
            .slice(0, 4),
        }))
      } catch (e) {
        toast.error(e?.message || 'Unable to read selected address.')
      }
    }, [])

  const makeProfilePlaceChangedHandler = useCallback(
    (ref) => () => {
      try {
        const instance = ref?.current
        if (!instance || typeof instance.getPlace !== 'function') return
        const place = instance.getPlace()
        if (!place) return
        applyMappedHomeAddress(place)
      } catch (e) {
        toast.error(e?.message || 'Address autocomplete error.')
      }
    },
    [applyMappedHomeAddress]
  )

  const onEmergencyAddressPlaceChanged = useCallback(() => {
    try {
      const instance = emergencyAddressAutocompleteRef?.current
      if (!instance || typeof instance.getPlace !== 'function') return
      const place = instance.getPlace()
      if (!place) return
      const mapped = mapPlaceToAddressFields(place)
      const address = mapped?.full_address || place?.formatted_address || place?.name || ''
      if (!address) return
      setEmergencyForm((prev) => ({ ...prev, address }))
    } catch (e) {
      toast.error(e?.message || 'Unable to read selected address.')
    }
  }, [])

  useEffect(() => {
    console.info('[AdminEmployeeProfile] Fetching profile snapshot', {
      employeeId,
      isLoading: profileSnapshotQuery.isLoading,
      hasData: Boolean(profileSnapshotQuery.data),
      hasError: Boolean(profileSnapshotQuery.error),
    })
    setLoading(profileSnapshotQuery.isLoading)
    if (profileSnapshotQuery.error) {
      console.error('[AdminEmployeeProfile] Profile snapshot failed', profileSnapshotQuery.error)
      setError(profileSnapshotQuery.error?.message || 'Failed to load employee profile')
      return
    }
    const d = profileSnapshotQuery.data
    if (!d) return

    console.info('[AdminEmployeeProfile] Profile data received', {
      employeeId,
      hasUser: Boolean(d?.user),
      hasCompensationSummary: Boolean(d?.compensation_summary),
      hasLeaveCredits: Boolean(d?.leave_credits),
    })
    setError(null)
    const found = d?.user ?? null
    setEmployee(found || null)
    setLeaveCreditsBlock((prev) =>
      d?.leave_credits != null && typeof d.leave_credits === 'object' ? d.leave_credits : prev
    )
    setCompensationSummary(d?.compensation_summary ?? null)
    if (found) {
          const [firstName, ...restNames] = String(found.name || '').trim().split(/\s+/)
          const normalizedEmploymentType = ['full_time', 'part_time', 'contract', 'probationary'].includes(found.employment_type)
            ? found.employment_type
            : ''
          let nextSkills = []
          let nextGovIds = createEmptyGovIdsState()
          let nextSecondaryIds = createDefaultSecondaryIdsState()
          let nextGovIdVerification = createDefaultGovIdVerificationState()
          let nextGovIdDocuments = createEmptyGovIdDocumentsState()
          let nextEmergencyContacts = []
          try {
            const raw = localStorage.getItem(employeeExtrasKey(found.id))
            const parsed = raw ? JSON.parse(raw) : null
            // Skills are now stored in the database; do not load from localStorage.
            nextSkills = []
            if (parsed?.government_ids && typeof parsed.government_ids === 'object') {
              nextGovIds = {
                tin: toText(parsed.government_ids.tin),
                sss: toText(parsed.government_ids.sss),
                philhealth: toText(parsed.government_ids.philhealth),
                pagibig: toText(parsed.government_ids.pagibig),
              }
            }
            if (Array.isArray(parsed?.secondary_ids) && parsed.secondary_ids.length > 0) {
              nextSecondaryIds = parsed.secondary_ids.map((item, index) => ({
                id: item?.id || `secondary-${index}`,
                type: toText(item?.type),
                number: toText(item?.number),
                expiry_date: toText(item?.expiry_date),
                status: toText(item?.status) || 'valid',
              }))
            }
            if (parsed?.gov_id_verification && typeof parsed.gov_id_verification === 'object') {
              nextGovIdVerification = {
                tin: toText(parsed.gov_id_verification.tin) || 'pending',
                sss: toText(parsed.gov_id_verification.sss) || 'pending',
                philhealth: toText(parsed.gov_id_verification.philhealth) || 'pending',
                pagibig: toText(parsed.gov_id_verification.pagibig) || 'pending',
              }
            }
            if (parsed?.gov_id_documents && typeof parsed.gov_id_documents === 'object') {
              const normalizeDoc = (doc) =>
                doc && typeof doc === 'object'
                  ? { name: toText(doc.name), type: toText(doc.type), size: Number(doc.size) || 0, uploaded_at: toText(doc.uploaded_at) }
                  : null
              nextGovIdDocuments = {
                tin: normalizeDoc(parsed.gov_id_documents.tin),
                sss: normalizeDoc(parsed.gov_id_documents.sss),
                philhealth: normalizeDoc(parsed.gov_id_documents.philhealth),
                pagibig: normalizeDoc(parsed.gov_id_documents.pagibig),
              }
            }
            if (Array.isArray(parsed?.emergency_contacts)) {
              nextEmergencyContacts = parsed.emergency_contacts.map((item, index) => ({
                id: item?.id || `emergency-${index}`,
                full_name: toText(item?.full_name),
                relationship: toText(item?.relationship),
                phone_number: toText(item?.phone_number),
                address: toText(item?.address),
                is_primary: !!item?.is_primary,
              }))
            }
          } catch {
            nextSkills = []
            nextGovIds = createEmptyGovIdsState()
            nextSecondaryIds = createDefaultSecondaryIdsState()
            nextGovIdVerification = createDefaultGovIdVerificationState()
            nextGovIdDocuments = createEmptyGovIdDocumentsState()
            nextEmergencyContacts = []
          }
          const parsedAddress = parseComposedHomeAddress(found.full_address || found.home_address || '')
          const normalizedEmploymentStatus = normalizeEmploymentStatusValue(found.employment_status || found.status) || 'probationary'
          reportingManagerOrgKeyRef.current = ''
          setReportingManagerManualOverride(false)
          setReportingManagerAutoHint('')
          const nextForm = {
            first_name: found.first_name || firstName || '',
            middle_name: found.middle_name || '',
            last_name: found.last_name || restNames.join(' ') || '',
            suffix: found.suffix || '',
            username: found.username || '',
            email: found.email || '',
            phone_number: found.phone_number || '',
            date_of_birth: found.date_of_birth || '',
            gender: found.gender || '',
            civil_status: found.civil_status || '',
            nationality: found.nationality || '',
            home_address: found.full_address || found.home_address || '',
            street_address: found.street_address || parsedAddress.street_address,
            barangay: found.barangay || parsedAddress.barangay,
            city: found.city || parsedAddress.city,
            province: found.province || parsedAddress.province,
            postal_code: found.postal_code != null && String(found.postal_code).trim() !== ''
              ? String(found.postal_code).trim()
              : parsedAddress.postal_code,
            department_id: found.department_id != null ? String(found.department_id) : '',
            section_unit_id: found.section_unit_id != null ? String(found.section_unit_id) : '',
            company_id: found.company_id != null ? String(found.company_id) : '',
            branch_id: found.branch_id != null ? String(found.branch_id) : '',
            position: found.position || '',
            employment_status: normalizedEmploymentStatus,
            employment_status_effective_date: found.employment_status_effective_date || '',
            employment_type: normalizedEmploymentType,
            branch_office_location: found.branch_name || found.branch_office_location || '',
            hire_date: found.hire_date || '',
            payroll_effective_date: found.payroll_effective_date || '',
            contract_start_date: found.contract_start_date || '',
            contract_end_date: found.contract_end_date || '',
            supervisor_id: found.supervisor_id != null ? String(found.supervisor_id) : '',
            working_schedule_id: found.working_schedule_id != null ? String(found.working_schedule_id) : '',
            monthly_salary: found.monthly_salary != null && found.monthly_salary !== '' ? String(found.monthly_salary) : '',
            hourly_rate: found.hourly_rate != null && found.hourly_rate !== '' ? String(found.hourly_rate) : '',
            salary_effectivity_date: found.salary_effectivity_date || '',
            daily_rate: found.daily_rate != null && found.daily_rate !== '' ? String(found.daily_rate) : '',
            monthly_rate: found.monthly_rate != null && found.monthly_rate !== '' ? String(found.monthly_rate) : '',
          }
          setForm(nextForm)
          setSkills(nextSkills)
          setGovIds(nextGovIds)
          setSecondaryIds(nextSecondaryIds)
          setGovIdVerification(nextGovIdVerification)
          setGovIdDocuments(nextGovIdDocuments)
          setEmergencyContacts(nextEmergencyContacts)
          setEmergencyForm(createEmptyEmergencyContact())
          setEditingEmergencyId('')
          setEmergencyErrors({})
          setBenefitsData(createEmptyBenefitsState())
          setBenefitsEditMode(false)
          setInitialSnapshot(buildProfileSnapshot(nextForm, nextSkills, [], nextGovIds, nextSecondaryIds, nextGovIdVerification, nextGovIdDocuments, nextEmergencyContacts))
        }
  }, [profileSnapshotQuery.data, profileSnapshotQuery.error, profileSnapshotQuery.isLoading])

  useEffect(() => {
    if (!employeeId) return
    let alive = true
    getEmployees({ per_page: 500, lite: true })
      .then((data) => {
        if (!alive) return
        const list = Array.isArray(data?.employees) ? data.employees : []
        setAllEmployees(list)
      })
      .catch(() => {
        if (!alive) return
        setAllEmployees([])
      })
    return () => {
      alive = false
    }
  }, [employeeId])

  useEffect(() => {
    const refreshCompensationSummary = () => {
      if (!employeeId) return
      getEmployeeProfileSnapshot(employeeId, {
        lite: true,
        include_government_ids: false,
        include_emergency_contacts: false,
        include_benefits: false,
        include_leave_credits: false,
        include_leave_credits_history: false,
        include_compensation_summary: true,
        include_pay_cycle_preview: true,
      })
        .then((data) => {
          if (data?.user) {
            setEmployee((prev) => (prev ? { ...prev, ...data.user } : data.user))
            setForm((prev) => ({
              ...prev,
              monthly_salary: data.user.monthly_salary != null && data.user.monthly_salary !== '' ? String(data.user.monthly_salary) : '',
              hourly_rate: data.user.hourly_rate != null && data.user.hourly_rate !== '' ? String(data.user.hourly_rate) : '',
              salary_effectivity_date: data.user.salary_effectivity_date || '',
              daily_rate: data.user.daily_rate != null && data.user.daily_rate !== '' ? String(data.user.daily_rate) : '',
              monthly_rate: data.user.monthly_rate != null && data.user.monthly_rate !== '' ? String(data.user.monthly_rate) : '',
            }))
          }
          setCompensationSummary(data?.compensation_summary ?? null)
          setLeaveCreditsBlock((prev) =>
            data?.leave_credits != null && typeof data.leave_credits === 'object'
              ? data.leave_credits
              : prev
          )
        })
        .catch(() => {})
    }
    window.addEventListener('hr:pay-components-changed', refreshCompensationSummary)
    window.addEventListener('hr:employee-compensation-changed', refreshCompensationSummary)
    window.addEventListener('hr:deduction-schedule-changed', refreshCompensationSummary)
    window.addEventListener('hr:employee-deductions-changed', refreshCompensationSummary)
    return () => {
      window.removeEventListener('hr:pay-components-changed', refreshCompensationSummary)
      window.removeEventListener('hr:employee-compensation-changed', refreshCompensationSummary)
      window.removeEventListener('hr:deduction-schedule-changed', refreshCompensationSummary)
      window.removeEventListener('hr:employee-deductions-changed', refreshCompensationSummary)
    }
  }, [employeeId])

  useEffect(() => {
    if (!employeeId || activeTab !== 'salary') return
    if (deferredLoadedRef.current.salaryCore) return
    console.info('[AdminEmployeeProfile] Loading deferred salary data', { employeeId })
    setDeferredSectionLoading((prev) => ({ ...prev, salary: true }))
    getEmployeeProfileSnapshot(employeeId, {
      lite: true,
      include_government_ids: true,
      include_emergency_contacts: false,
      include_benefits: false,
      include_leave_credits: false,
      include_leave_credits_history: false,
      include_compensation_summary: true,
      include_pay_cycle_preview: true,
    })
      .then((data) => {
        console.info('[AdminEmployeeProfile] Deferred salary data loaded', {
          employeeId,
          hasCompensationSummary: Boolean(data?.compensation_summary),
          hasLeaveCredits: Boolean(data?.leave_credits),
        })
        if (data?.user) {
          setEmployee((prev) => (prev ? { ...prev, ...data.user } : data.user))
          setForm((prev) => ({
            ...prev,
            monthly_salary: data.user.monthly_salary != null && data.user.monthly_salary !== '' ? String(data.user.monthly_salary) : '',
            hourly_rate: data.user.hourly_rate != null && data.user.hourly_rate !== '' ? String(data.user.hourly_rate) : '',
            salary_effectivity_date: data.user.salary_effectivity_date || '',
            daily_rate: data.user.daily_rate != null && data.user.daily_rate !== '' ? String(data.user.daily_rate) : '',
            monthly_rate: data.user.monthly_rate != null && data.user.monthly_rate !== '' ? String(data.user.monthly_rate) : '',
          }))
        }
        setCompensationSummary(data?.compensation_summary ?? null)
        setLeaveCreditsBlock((prev) =>
          data?.leave_credits != null && typeof data.leave_credits === 'object'
            ? data.leave_credits
            : prev
        )
        const ids = data?.government_ids
        if (ids && typeof ids === 'object' && !Array.isArray(ids)) {
          setGovIds({
            tin: toText(ids.tin_number),
            sss: toText(ids.sss_number),
            philhealth: toText(ids.philhealth_number),
            pagibig: toText(ids.pagibig_number),
          })
        }
        deferredLoadedRef.current.salaryCore = true
      })
      .catch((e) => {
        console.error('[AdminEmployeeProfile] Deferred salary data failed', e)
      })
      .finally(() => {
        setDeferredSectionLoading((prev) => ({ ...prev, salary: false }))
      })
  }, [activeTab, employeeId])

  useEffect(() => {
    if (!employeeId || activeTab !== 'employment') return
    if (leaveCreditsBlock) return
    let alive = true
    setLeaveCreditsLoading(true)
    getEmployeeProfileSnapshot(employeeId, {
      lite: true,
      include_government_ids: false,
      include_emergency_contacts: false,
      include_benefits: false,
      include_leave_credits: true,
      include_leave_credits_history: false,
      include_compensation_summary: false,
      include_pay_cycle_preview: false,
    })
      .then((data) => {
        if (!alive) return
        setLeaveCreditsBlock(data?.leave_credits ?? null)
      })
      .catch(() => {})
      .finally(() => {
        if (alive) setLeaveCreditsLoading(false)
      })
    return () => {
      alive = false
    }
  }, [activeTab, employeeId, leaveCreditsBlock])

  useEffect(() => {
    if (!employeeId || activeTab !== 'government-ids') return
    if (deferredLoadedRef.current.governmentIds) return
    setDeferredSectionLoading((prev) => ({ ...prev, government: true }))
    getEmployeeProfileSnapshot(employeeId, {
      lite: true,
      include_government_ids: true,
      include_emergency_contacts: false,
      include_benefits: false,
      include_leave_credits: false,
      include_compensation_summary: false,
      include_pay_cycle_preview: false,
    })
      .then((data) => {
        const ids = data?.government_ids
        if (ids && typeof ids === 'object' && !Array.isArray(ids)) {
          setGovIds({
            tin: toText(ids.tin_number),
            sss: toText(ids.sss_number),
            philhealth: toText(ids.philhealth_number),
            pagibig: toText(ids.pagibig_number),
          })
        }
        deferredLoadedRef.current.governmentIds = true
      })
      .catch(() => {})
      .finally(() => {
        setDeferredSectionLoading((prev) => ({ ...prev, government: false }))
      })
  }, [activeTab, employeeId])

  useEffect(() => {
    if (!employeeId || activeTab !== 'emergency-contacts') return
    if (deferredLoadedRef.current.emergencyContacts) return
    setDeferredSectionLoading((prev) => ({ ...prev, emergency: true }))
    getEmployeeProfileSnapshot(employeeId, {
      lite: true,
      include_government_ids: false,
      include_emergency_contacts: true,
      include_benefits: false,
      include_leave_credits: false,
      include_compensation_summary: false,
      include_pay_cycle_preview: false,
    })
      .then((data) => {
        const contacts = Array.isArray(data?.emergency_contacts)
          ? data.emergency_contacts.map((item, index) => ({
              id: item?.id || `emergency-${index}`,
              full_name: item.full_name || '',
              relationship: item.relationship || '',
              phone_number: item.phone_number || '',
              address: item.address || '',
              is_primary: !!item.is_primary,
            }))
          : []
        setEmergencyContacts(contacts)
        deferredLoadedRef.current.emergencyContacts = true
      })
      .catch(() => {})
      .finally(() => {
        setDeferredSectionLoading((prev) => ({ ...prev, emergency: false }))
      })
  }, [activeTab, employeeId])

  useEffect(() => {
    if (!employeeId) return
    let alive = true
    getAdminEmployeeStatus(employeeId)
      .then((data) => {
        if (!alive) return
        setEmployeeStatusHistory(Array.isArray(data?.history) ? data.history : [])
      })
      .catch(() => {
        if (!alive) return
        setEmployeeStatusHistory([])
      })
    return () => {
      alive = false
    }
  }, [employeeId, location.key])

  useEffect(() => {
    let alive = true
    Promise.all([
      getDepartments().catch(() => ({ departments: [] })),
      getSectionsOrUnits().catch(() => ({ sections_or_units: [] })),
      getWorkingSchedules().catch(() => ({ schedules: [] })),
      getCompanies().catch(() => ({ companies: [] })),
    ])
      .then(([deptData, sectionData, schedulesData, companiesData]) => {
        if (!alive) return
        setDepartments(Array.isArray(deptData?.departments) ? deptData.departments : [])
        setSectionsOrUnits(Array.isArray(sectionData?.sections_or_units) ? sectionData.sections_or_units : [])
        const list = Array.isArray(schedulesData?.schedules) ? schedulesData.schedules : []
        setWorkingSchedules(list)
        setCompanies(Array.isArray(companiesData?.companies) ? companiesData.companies : [])
      })
      .catch(() => {
        if (!alive) return
        setDepartments([])
        setSectionsOrUnits([])
        setWorkingSchedules([])
        setCompanies([])
      })
    return () => {
      alive = false
    }
  }, [])

  useEffect(() => {
    if (!form.company_id) {
      setBranches([])
      return
    }
    let alive = true
    getBranches({ company_id: form.company_id })
      .then((data) => {
        if (!alive) return
        setBranches(Array.isArray(data?.branches) ? data.branches : [])
      })
      .catch(() => {
        if (!alive) return
        setBranches([])
      })
    return () => { alive = false }
  }, [form.company_id])

  useEffect(() => {
    if (!transferOpen || !employee?.id) {
      setTransferBranches([])
      setTransferBranchesLoading(false)
      setTransferDepartments([])
      return
    }
    let alive = true
    setTransferBranchesLoading(true)
    const companyId = employee.company_id || null
    getBranches(companyId ? { company_id: companyId } : {})
      .then((data) => {
        if (!alive) return
        setTransferBranches(Array.isArray(data?.branches) ? data.branches : [])
      })
      .catch(() => {
        if (!alive) return
        setTransferBranches([])
      })
      .finally(() => {
        if (alive) setTransferBranchesLoading(false)
      })
    return () => { alive = false }
  }, [transferOpen, employee?.id, employee?.company_id])

  useEffect(() => {
    if (!transferForm.targetBranchId) {
      setTransferDepartments([])
      return
    }
    let alive = true
    getDepartments({ branch_id: transferForm.targetBranchId })
      .then((data) => {
        if (!alive) return
        setTransferDepartments(Array.isArray(data?.departments) ? data.departments : [])
      })
      .catch(() => {
        if (!alive) return
        setTransferDepartments([])
      })
    return () => { alive = false }
  }, [transferForm.targetBranchId])

  useEffect(() => {
    if (!employee?.id) {
      setBenefitsData(createEmptyBenefitsState())
      setBenefitsError('')
      setBenefitsLoading(false)
      deferredLoadedRef.current.benefits = false
      return
    }
    if (activeTab !== 'benefits') return
    if (deferredLoadedRef.current.benefits) return
    let alive = true
    setBenefitsLoading(true)
    setBenefitsError('')
    getEmployeeBenefits(employee.id)
      .then((data) => {
        if (!alive) return
        setBenefitsData(toBenefitsState(data?.benefits))
        deferredLoadedRef.current.benefits = true
      })
      .catch((e) => {
        if (!alive) return
        setBenefitsData(createEmptyBenefitsState())
        setBenefitsError(e.message || 'Failed to load benefits.')
      })
      .finally(() => {
        if (alive) setBenefitsLoading(false)
      })

    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  useEffect(() => {
    if (!employee?.id) {
      setOrganizationAssignments([])
      return
    }
    if (activeTab !== 'employment') return
    let alive = true
    setOrganizationAssignmentsLoading(true)
    getEmployeeOrganizationAssignments(employee.id, { fresh: true })
      .then((data) => {
        if (!alive) return
        setOrganizationAssignments(Array.isArray(data?.assignments) ? data.assignments : [])
      })
      .catch(() => {
        if (!alive) return
        setOrganizationAssignments([])
      })
      .finally(() => {
        if (alive) setOrganizationAssignmentsLoading(false)
      })

    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  const tabs = [
    { id: 'personal-info', label: 'Personal' },
    { id: 'employment', label: 'Employment' },
    { id: 'salary', label: 'Salary & Contributions' },
    { id: 'documents', label: 'Documents' },
    { id: 'government-ids', label: 'Gov IDs' },
    { id: 'emergency-contacts', label: 'Emergency' },
    { id: 'skills', label: 'Skills' },
    ...(isOwnProfile ? [{ id: 'account', label: 'Account' }] : []),
  ]

  const mergeOwnUserFromApi = useCallback((nextUser) => {
    if (!nextUser || typeof nextUser !== 'object') return
    setEmployee((prev) => (prev ? { ...prev, ...nextUser } : nextUser))
    if (isOwnProfile && typeof setUser === 'function') {
      setUser((prev) => ({
        ...(prev && typeof prev === 'object' ? prev : {}),
        ...nextUser,
      }))
    }
  }, [isOwnProfile, setUser])

  async function handleAccountEmailSave() {
    const next = String(accountEmail || '').trim()
    if (!isValidEmailAddress(next)) {
      setAccountErrors((prev) => ({ ...prev, email: 'Enter a valid email address.' }))
      return
    }
    if (next === String(employee?.email || '').trim()) {
      setAccountErrors((prev) => ({ ...prev, email: 'Enter a new email address to change.' }))
      return
    }
    setAccountErrors((prev) => ({ ...prev, email: '' }))
    setAccountBusy((prev) => ({ ...prev, email: true }))
    try {
      const data = await updateProfile({ email: next })
      mergeOwnUserFromApi(data?.user)
      toast.success('Email updated.')
    } catch (e) {
      setAccountErrors((prev) => ({ ...prev, email: e?.message || 'Failed to update email.' }))
    } finally {
      setAccountBusy((prev) => ({ ...prev, email: false }))
    }
  }

  async function handleAccountPhoneSave() {
    const next = String(accountPhone || '').trim()
    if (next && !isValidPhMobile(next)) {
      setAccountErrors((prev) => ({ ...prev, phone: 'Enter a valid PH mobile number (+63... or 09...).' }))
      return
    }
    setAccountErrors((prev) => ({ ...prev, phone: '' }))
    setAccountBusy((prev) => ({ ...prev, phone: true }))
    try {
      const data = await updateProfile({ phone_number: next || null })
      mergeOwnUserFromApi(data?.user)
      setAccountPhone(String(data?.user?.phone_number || ''))
      toast.success('Phone updated.')
    } catch (e) {
      setAccountErrors((prev) => ({ ...prev, phone: e?.message || 'Failed to update phone.' }))
    } finally {
      setAccountBusy((prev) => ({ ...prev, phone: false }))
    }
  }

  async function handleAccountPasswordSave() {
    const current = String(accountCurrentPassword || '')
    const next = String(accountNewPassword || '')
    const confirm = String(accountConfirmPassword || '')
    const currentErr = !current ? 'Current password is required.' : ''
    const newErr = validateTempPassword(next)
    const confirmErr = next ? validateAccountPasswordConfirm(next, confirm) : ''
    setAccountPasswordFieldErrors({ current: currentErr, new: newErr, confirm: confirmErr })
    setAccountErrors((prev) => ({ ...prev, password: '' }))
    if (currentErr || newErr || confirmErr) return
    setAccountBusy((prev) => ({ ...prev, password: true }))
    try {
      await updateProfile({
        current_password: current,
        password: next,
        password_confirmation: confirm,
      })
      setAccountCurrentPassword('')
      setAccountNewPassword('')
      setAccountConfirmPassword('')
      setAccountPasswordFieldErrors({ current: '', new: '', confirm: '' })
      toast.success('Password updated.')
    } catch (e) {
      setAccountErrors((prev) => ({ ...prev, password: e?.message || 'Failed to update password.' }))
    } finally {
      setAccountBusy((prev) => ({ ...prev, password: false }))
    }
  }

  const reportingManagerSuggestion = useMemo(() => {
    if (!employee?.id) return null

    const company = companies.find((c) => String(c.id) === String(form.company_id || employee.company_id || '')) || null
    const branch = branches.find((b) => String(b.id) === String(form.branch_id || employee.branch_id || '')) || null
    const department = departments.find((d) => String(d.id) === String(form.department_id || employee.department_id || '')) || null

    const employeeId = String(employee.id)
    const selectedPosition = String(form.position || employee.position || '').trim().toLowerCase()
    const isDepartmentHead = Boolean(department && String(department.department_head_id || '') === employeeId)
      || selectedPosition.includes('department head')
    const isBranchHead = Boolean(branch && String(branch.branch_manager_id || '') === employeeId)
      || selectedPosition.includes('branch head')
      || selectedPosition.includes('branch manager')
    const isCompanyHead = Boolean(company && String(company.company_head_id || '') === employeeId)
      || selectedPosition.includes('company head')

    let suggestedId = ''
    let label = ''

    if (isCompanyHead) {
      suggestedId = employeeId
      label = 'Company Head reports to self by default.'
    } else if (isBranchHead) {
      suggestedId = company?.company_head_id != null ? String(company.company_head_id) : ''
      label = suggestedId ? 'Auto-suggested based on organization hierarchy (Branch Head -> Company Head).' : ''
    } else if (isDepartmentHead) {
      suggestedId = branch?.branch_manager_id != null ? String(branch.branch_manager_id) : ''
      label = suggestedId ? 'Auto-suggested based on organization hierarchy (Department Head -> Branch Head).' : ''
    } else if (Array.isArray(department?.team_leader_ids) && department.team_leader_ids.length > 0) {
      suggestedId = String(department.team_leader_ids[0])
      label = 'Auto-suggested based on assigned department team leader.'
    } else if (department?.department_head_id != null) {
      suggestedId = String(department.department_head_id)
      label = 'Auto-suggested based on organization hierarchy (Department -> Department Head).'
    } else if (branch?.branch_manager_id != null) {
      suggestedId = String(branch.branch_manager_id)
      label = 'Auto-suggested based on organization hierarchy (Branch -> Branch Head).'
    } else if (company?.company_head_id != null) {
      suggestedId = String(company.company_head_id)
      label = 'Auto-suggested based on organization hierarchy (Company -> Company Head).'
    }

    if (!suggestedId) return null
    const suggestedEmployee = allEmployees.find((row) => String(row.id) === suggestedId) || null
    if (!suggestedEmployee) return null
    if (String(suggestedEmployee.id) !== employeeId && !isManagerialPosition(suggestedEmployee.position)) {
      return null
    }
    if (String(suggestedEmployee.id) === employeeId && !isManagerialPosition(form.position || employee.position)) {
      return null
    }

    return {
      id: suggestedId,
      label,
      name: suggestedEmployee?.name || '',
    }
  }, [allEmployees, branches, companies, departments, employee?.company_id, employee?.department_id, employee?.id, employee?.position, employee?.branch_id, form.branch_id, form.company_id, form.department_id, form.position])

  const managerEmployees = useMemo(() => {
    const managers = allEmployees.filter((e) => e.id !== employee?.id && isManagerialPosition(e.position))
    const extras = []

    if (employee && isManagerialPosition(form.position || employee.position)) {
      extras.push({
        id: employee.id,
        name: employee.name,
        position: form.position || employee.position,
      })
    }

    if (reportingManagerSuggestion?.id) {
      const suggested = allEmployees.find((row) => String(row.id) === String(reportingManagerSuggestion.id))
      if (suggested && isManagerialPosition(suggested.position)) extras.push(suggested)
    }

    if (form.supervisor_id) {
      const currentSupervisor = allEmployees.find((row) => String(row.id) === String(form.supervisor_id))
      if (currentSupervisor && isManagerialPosition(currentSupervisor.position)) extras.push(currentSupervisor)
    }

    const deduped = new Map()
    for (const person of [...extras, ...managers]) {
      if (!person?.id) continue
      const key = String(person.id)
      if (!deduped.has(key)) deduped.set(key, person)
    }
    return Array.from(deduped.values())
  }, [allEmployees, employee, form.position, form.supervisor_id, reportingManagerSuggestion])

  useEffect(() => {
    if (!form.supervisor_id) return
    if (String(form.supervisor_id) === String(employee?.id) && isManagerialPosition(form.position || employee?.position)) return

    const currentSupervisor = allEmployees.find((row) => String(row.id) === String(form.supervisor_id))
    if (!currentSupervisor || !isManagerialPosition(currentSupervisor.position)) {
      setForm((prev) => {
        if (!prev.supervisor_id) return prev
        return { ...prev, supervisor_id: '' }
      })
      setReportingManagerAutoHint('')
      setReportingManagerManualOverride(false)
    }
  }, [allEmployees, employee?.id, employee?.position, form.position, form.supervisor_id])

  useEffect(() => {
    if (!employee?.id) return

    const orgKey = [
      String(form.company_id || ''),
      String(form.branch_id || ''),
      String(form.department_id || ''),
      String(form.position || ''),
    ].join('|')
    const orgChanged = reportingManagerOrgKeyRef.current !== orgKey
    if (orgChanged) {
      reportingManagerOrgKeyRef.current = orgKey
      setReportingManagerManualOverride(false)
    }

    if (!reportingManagerSuggestion?.id) {
      if (orgChanged) {
        setReportingManagerAutoHint('')
      }
      return
    }

    if (!reportingManagerManualOverride || orgChanged) {
      setForm((prev) => {
        if (String(prev.supervisor_id || '') === String(reportingManagerSuggestion.id)) return prev
        return { ...prev, supervisor_id: String(reportingManagerSuggestion.id) }
      })
      setReportingManagerAutoHint(reportingManagerSuggestion.label)
    }
  }, [employee?.id, form.branch_id, form.company_id, form.department_id, form.position, reportingManagerManualOverride, reportingManagerSuggestion])

  const probationMilestones = useMemo(() => {
    if (form.employment_status !== 'probationary' || !hasText(form.hire_date)) return null
    const h = String(form.hire_date).trim()
    return {
      three: hireDatePlusMonths(h, 3),
      five: hireDatePlusMonths(h, 5),
      six: hireDatePlusMonths(h, 6),
    }
  }, [form.employment_status, form.hire_date])

  const completedRegularizationEntry = useMemo(() => {
    if (!Array.isArray(employeeStatusHistory) || employeeStatusHistory.length === 0) return null
    if (String(form.employment_status || '').trim().toLowerCase() !== 'regular') return null
    const currentEffectiveDate = String(form.employment_status_effective_date || '').trim()
    if (!currentEffectiveDate) return null
    return (
      employeeStatusHistory.find((row) => {
        const next = String(row?.new_status || '').trim().toLowerCase()
        const trigger = String(row?.trigger_type || '').trim().toLowerCase()
        const effectiveDate = String(row?.effective_date || '').trim()
        return next === 'regular'
          && effectiveDate === currentEffectiveDate
          && ['hr_approval', 'head_recommendation', 'system_automation'].includes(trigger)
      }) || null
    )
  }, [employeeStatusHistory, form.employment_status, form.employment_status_effective_date])

  const employmentDateValidation = useMemo(() => {
    const today = new Date()
    const todayDateOnly = new Date(today.getFullYear(), today.getMonth(), today.getDate())
    const hireDate = parseIsoDateOnly(form.hire_date)
    const statusEffectiveDate = parseIsoDateOnly(form.employment_status_effective_date)

    const hireMessages = []
    const statusMessages = []

    if (hireDate && hireDate.getTime() > todayDateOnly.getTime()) {
      hireMessages.push({ tone: 'error', text: 'Hire Date cannot be in the future.' })
    }

    if (statusEffectiveDate && statusEffectiveDate.getTime() > todayDateOnly.getTime()) {
      statusMessages.push({ tone: 'error', text: 'Status Effective Date cannot be in the future.' })
    }

    return { hireMessages, statusMessages }
  }, [form.hire_date, form.employment_status_effective_date])

  const liveLeaveCreditsBlock = useMemo(() => {
    if (!leaveCreditsBlock || typeof leaveCreditsBlock !== 'object') return leaveCreditsBlock

    const today = new Date()
    const todayDateOnly = new Date(today.getFullYear(), today.getMonth(), today.getDate())
    const effectiveDate = parseIsoDateOnly(form.employment_status_effective_date)
    const isRegular = String(form.employment_status || '').trim().toLowerCase() === 'regular'
    const eligibilityDate = effectiveDate ? addYearsDateOnly(effectiveDate, 1) : null
    const eligibleNow = Boolean(isRegular && eligibilityDate && todayDateOnly.getTime() >= eligibilityDate.getTime())
    const annualAllocation = Math.max(0, Number(leaveCreditsBlock.annual_allocation ?? 7)) || 7
    const remaining = Number(leaveCreditsBlock.remaining ?? 0)

    // Keep the server payload as the source of truth when it already matches the live employment data.
    // Only override the obvious stale UI case: Employment tab proves eligibility, but the snapshot still
    // says 0/7 or ineligible because it has not caught up to the latest status/effective-date update yet.
    if (eligibleNow) {
      return {
        ...leaveCreditsBlock,
        remaining: remaining > 0 ? remaining : annualAllocation,
        effective_available:
          Number(leaveCreditsBlock.effective_available ?? 0) > 0
            ? Number(leaveCreditsBlock.effective_available)
            : annualAllocation,
        eligible_for_paid_leave_pool: true,
        is_regular_employment: true,
        probationary: false,
        has_one_year_of_service: true,
        display: `${remaining > 0 ? remaining : annualAllocation}/${annualAllocation} credits (Eligible)`,
        status_summary: 'Eligible for paid leave credits (Regular + 1 year service)',
        unpaid_leave_notice: null,
        warning:
          remaining > 0 || Number(leaveCreditsBlock.effective_available ?? 0) > 0
            ? null
            : null,
        service_anchor_date: form.employment_status_effective_date || leaveCreditsBlock.service_anchor_date || null,
        regular_service_start_date: form.employment_status_effective_date || leaveCreditsBlock.regular_service_start_date || null,
      }
    }

    if (isRegular && effectiveDate && eligibilityDate && todayDateOnly.getTime() < eligibilityDate.getTime()) {
      return {
        ...leaveCreditsBlock,
        remaining: 0,
        effective_available: 0,
        eligible_for_paid_leave_pool: false,
        is_regular_employment: true,
        probationary: false,
        has_one_year_of_service: false,
        display: `0/${annualAllocation} - Not yet eligible (under 1 year regular service)`,
        status_summary: 'Complete 1 full year of regular service to unlock paid leave credits.',
        unpaid_leave_notice: 'This leave will be unpaid because you are not yet eligible for paid leave credits.',
        warning: 'This leave will be unpaid because you are not yet eligible for paid leave credits.',
        service_anchor_date: form.employment_status_effective_date || leaveCreditsBlock.service_anchor_date || null,
        regular_service_start_date: form.employment_status_effective_date || leaveCreditsBlock.regular_service_start_date || null,
      }
    }

    return leaveCreditsBlock
  }, [leaveCreditsBlock, form.employment_status, form.employment_status_effective_date])

  const completionState = useMemo(() => {
    const sections = [
      {
        label: 'Personal Information',
        fields: [
          { label: 'First Name', done: hasText(form.first_name) },
          { label: 'Last Name', done: hasText(form.last_name) },
          { label: 'Username', done: hasText(form.username) },
          { label: 'Email Address', done: !hasText(form.email) || isValidEmailAddress(form.email) },
          { label: 'Phone Number', done: isValidPhMobile(form.phone_number) },
          { label: 'Date of Birth', done: hasText(form.date_of_birth) },
          { label: 'Gender', done: hasText(form.gender) },
          { label: 'Civil Status', done: hasText(form.civil_status) },
          { label: 'Nationality', done: hasText(form.nationality) },
          { label: 'Home Address', done: hasText(form.home_address) },
        ],
      },
      {
        label: 'Employment Details',
        fields: [
          { label: 'Department', done: hasText(form.department_id) },
          { label: 'Job Title', done: hasText(form.position) },
          { label: 'Employment status', done: hasText(form.employment_status) },
          { label: 'Work arrangement', done: hasText(form.employment_type) },
          { label: 'Branch', done: hasText(form.branch_id) || hasText(form.branch_office_location) },
          { label: 'Hire Date', done: hasText(form.hire_date) },
        ],
      },
      {
        label: 'Access Setup',
        fields: [
          { label: 'QR', done: !!employee?.has_qr },
          { label: 'Schedule', done: hasAssignedSchedule(employee) },
          { label: 'Face', done: !!employee?.has_face },
          { label: 'Profile Photo', done: hasText(employee?.profile_image) },
        ],
      },
      {
        label: 'Certifications',
        fields: [
          { label: 'Skills', done: skills.length > 0 },
          { label: 'Uploaded Certifications', done: certifications.length > 0 },
        ],
      },
      {
        label: 'Government IDs',
        fields: [
          { label: 'TIN', done: hasText(govIds.tin) },
          { label: 'SSS', done: hasText(govIds.sss) },
          { label: 'PhilHealth', done: hasText(govIds.philhealth) },
          { label: 'Pag-IBIG', done: hasText(govIds.pagibig) },
        ],
      },
      {
        label: 'Emergency Contacts',
        fields: [
          { label: 'At least one contact', done: emergencyContacts.length > 0 },
          {
            label: 'Complete contact details',
            done: emergencyContacts.some((c) => hasText(c.full_name) && hasText(c.relationship) && hasText(c.phone_number) && hasText(c.address)),
          },
        ],
      },
    ]

    const sectionStats = sections.map((section) => {
      const total = section.fields.length
      const done = section.fields.filter((f) => f.done).length
      return {
        label: section.label,
        done,
        total,
        complete: total > 0 && done === total,
        missingFields: section.fields.filter((f) => !f.done).map((f) => `${section.label}: ${f.label}`),
      }
    })

    const doneFields = sectionStats.reduce((sum, s) => sum + s.done, 0)
    const totalFields = sectionStats.reduce((sum, s) => sum + s.total, 0)
    const percent = totalFields > 0 ? Math.round((doneFields / totalFields) * 100) : 0
    const missing = sectionStats.flatMap((s) => s.missingFields)

    return { sections: sectionStats, percent, missing, doneFields, totalFields }
  }, [form, employee, skills, certifications, govIds, emergencyContacts])

  const isDirty = useMemo(() => {
    if (!initialSnapshot) return false
    return buildProfileSnapshot(form, skills, certifications, govIds, secondaryIds, govIdVerification, govIdDocuments, emergencyContacts) !== initialSnapshot
  }, [form, skills, certifications, govIds, secondaryIds, govIdVerification, govIdDocuments, emergencyContacts, initialSnapshot])

  useEffect(() => {
    if (!employee?.id) return
    try {
      localStorage.setItem(
        employeeExtrasKey(employee.id),
        JSON.stringify({
          government_ids: govIds,
          secondary_ids: secondaryIds,
          gov_id_verification: govIdVerification,
          gov_id_documents: {
            tin: govIdDocuments.tin ? { name: govIdDocuments.tin.name, type: govIdDocuments.tin.type, size: govIdDocuments.tin.size, uploaded_at: govIdDocuments.tin.uploaded_at } : null,
            sss: govIdDocuments.sss ? { name: govIdDocuments.sss.name, type: govIdDocuments.sss.type, size: govIdDocuments.sss.size, uploaded_at: govIdDocuments.sss.uploaded_at } : null,
            philhealth: govIdDocuments.philhealth ? { name: govIdDocuments.philhealth.name, type: govIdDocuments.philhealth.type, size: govIdDocuments.philhealth.size, uploaded_at: govIdDocuments.philhealth.uploaded_at } : null,
            pagibig: govIdDocuments.pagibig ? { name: govIdDocuments.pagibig.name, type: govIdDocuments.pagibig.type, size: govIdDocuments.pagibig.size, uploaded_at: govIdDocuments.pagibig.uploaded_at } : null,
          },
          emergency_contacts: emergencyContacts,
        })
      )
    } catch {
      // ignore storage quota/private mode issues
    }
  }, [employee?.id, govIds, secondaryIds, govIdVerification, govIdDocuments, emergencyContacts])

  // Load skills from DB whenever employee changes.
  useEffect(() => {
    if (!employee?.id) {
      deferredLoadedRef.current.skills = false
      return
    }
    if (activeTab !== 'skills') return
    if (deferredLoadedRef.current.skills) return
    let alive = true
    setSkillsLoading(true)
    getEmployeeSkills(employee.id)
      .then((data) => {
        if (!alive) return
        const list = Array.isArray(data?.skills) ? data.skills : []
        setSkills(list)
        deferredLoadedRef.current.skills = true
      })
      .catch((e) => {
        if (!alive) return
        setSkills([])
        toast.error(e?.message || 'Failed to load skills.')
      })
      .finally(() => alive && setSkillsLoading(false))
    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  // Load certifications from DB whenever employee changes.
  useEffect(() => {
    if (!employee?.id) {
      deferredLoadedRef.current.certifications = false
      return
    }
    if (activeTab !== 'skills') return
    if (deferredLoadedRef.current.certifications) return
    let alive = true
    setCertificationsLoading(true)
    getEmployeeCertifications(employee.id)
      .then((data) => {
        if (!alive) return
        setCertifications(Array.isArray(data?.certifications) ? data.certifications : [])
        deferredLoadedRef.current.certifications = true
      })
      .catch((e) => {
        if (!alive) return
        setCertifications([])
        toast.error(e?.message || 'Failed to load certifications.')
      })
      .finally(() => alive && setCertificationsLoading(false))
    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  // Load government ID documents from DB whenever employee changes (same rows as Gov IDs tab; needed for Salary TIN).
  useEffect(() => {
    if (!employee?.id) {
      deferredLoadedRef.current.govIdDocumentsLoaded = false
      return
    }
    if (activeTab !== 'government-ids' && activeTab !== 'salary') return
    if (deferredLoadedRef.current.govIdDocumentsLoaded) return
    let alive = true
    setGovDocsLoading(true)
    getEmployeeGovernmentIdDocuments(employee.id)
      .then((data) => {
        if (!alive) return
        setGovDocs(Array.isArray(data?.government_ids) ? data.government_ids : [])
        deferredLoadedRef.current.govIdDocumentsLoaded = true
      })
      .catch((e) => {
        if (!alive) return
        setGovDocs([])
        toast.error(e?.message || 'Failed to load government IDs.')
      })
      .finally(() => alive && setGovDocsLoading(false))
    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  useEffect(() => {
    if (!employee?.id) return
    if (activeTab !== 'documents') return
    if (deferredLoadedRef.current.documents) return
    let alive = true
    setDocsLoading(true)
    getEmployeeDocuments(employee.id)
      .then((data) => {
        if (!alive) return
        setDocs(Array.isArray(data?.documents) ? data.documents : [])
        deferredLoadedRef.current.documents = true
      })
      .catch((e) => {
        if (!alive) return
        setDocs([])
        toast.error(e?.message || 'Failed to load documents.')
      })
      .finally(() => alive && setDocsLoading(false))
    return () => {
      alive = false
    }
  }, [employee?.id, activeTab])

  useEffect(() => {
    if (!employee?.id || activeTab !== 'salary' || !canViewPayrollHistory) return
    if (deferredLoadedRef.current.payrollHistory) return
    let cancelled = false
    setPayrollPeriodsLoading(true)
    setPayrollPeriodsError('')
    getPayrollPeriodsForEmployee(employee.id, { per_page: 6, finalized_only: true })
      .then((data) => {
        if (cancelled) return
        setPayrollPeriods(Array.isArray(data?.data) ? data.data : [])
        deferredLoadedRef.current.payrollHistory = true
      })
      .catch((e) => {
        if (cancelled) return
        setPayrollPeriods([])
        setPayrollPeriodsError(e?.message || 'Could not load payroll history.')
      })
      .finally(() => {
        if (!cancelled) setPayrollPeriodsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [employee?.id, activeTab, canViewPayrollHistory])

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
          const list = Array.isArray(data?.suggestions) ? data.suggestions : []
          setSkillSuggestions(list)
        })
        .catch(() => {
          if (!alive) return
          setSkillSuggestions([])
        })
        .finally(() => {
          if (alive) setSkillSuggestionsLoading(false)
        })
    }, 200)
    return () => {
      alive = false
      clearTimeout(t)
    }
  }, [skillAddOpen, skillDraft])

  async function submitAddSkill() {
    const value = String(skillDraft || '').trim()
    if (!value || !employee?.id || skillsSaving) return
    if (skills.some((s) => String(s?.name || '').toLowerCase() === value.toLowerCase())) {
      setSkillDraftError('Skill already exists.')
      return
    }
    setSkillDraftError('')
    setSkillsSaving(true)
    try {
      const data = await addEmployeeSkill(employee.id, value)
      const created = data?.skill
      if (created?.id) {
        setSkills((prev) => [...prev, created].sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || ''))))
      } else {
        const refreshed = await getEmployeeSkills(employee.id)
        setSkills(Array.isArray(refreshed?.skills) ? refreshed.skills : [])
      }
      setSkillAddOpen(false)
      setSkillDraft('')
      setSkillSuggestions([])
    } catch (e) {
      toast.error(e?.message || 'Failed to add skill.')
    } finally {
      setSkillsSaving(false)
    }
  }

  async function submitRenameSkill() {
    if (!employee?.id || !activeSkill?.id || skillsSaving) return
    const value = String(skillDraft || '').trim()
    if (!value) {
      setSkillDraftError('Skill name is required.')
      return
    }
    if (skills.some((s) => s.id !== activeSkill.id && String(s?.name || '').toLowerCase() === value.toLowerCase())) {
      setSkillDraftError('Skill already exists.')
      return
    }
    setSkillDraftError('')
    setSkillsSaving(true)
    try {
      const data = await updateEmployeeSkill(employee.id, activeSkill.id, value)
      const updated = data?.skill
      setSkills((prev) =>
        prev
          .map((s) => (s.id === activeSkill.id ? (updated?.id ? updated : { ...s, name: value }) : s))
          .sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || '')))
      )
      setSkillRenameOpen(false)
      setActiveSkill(null)
      setSkillDraft('')
    } catch (e) {
      toast.error(e?.message || 'Failed to update skill.')
    } finally {
      setSkillsSaving(false)
    }
  }

  function openAddSkillModal(seed = '') {
    setActiveSkill(null)
    setSkillDraft(seed)
    setSkillDraftError('')
    setSkillSuggestions([])
    setSkillAddOpen(true)
    setTimeout(() => skillInputRef.current?.focus(), 0)
  }

  function openRenameSkillModal(skill) {
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

  async function handleRemoveSkill(skill) {
    if (!employee?.id || !skill?.id || skillsSaving) return
    setSkillsSaving(true)
    try {
      await removeEmployeeSkill(employee.id, skill.id)
      setSkills((prev) => prev.filter((s) => s.id !== skill.id))
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

  function openVerifyCertificationModal(cert) {
    setActiveCert(cert)
    setVerifyStatus('verified')
    setVerifyReason('')
    setCertVerifyOpen(true)
  }

  async function submitCreateCertification() {
    if (!employee?.id || certificationsSaving) return
    const errs = validateCertificationForm(certForm)
    setCertErrors(errs)
    if (Object.keys(errs).length) return
    setCertificationsSaving(true)
    try {
      const data = await createEmployeeCertification(employee.id, {
        ...certForm,
        expiration_date: certForm.expiration_date || null,
        credential_id: certForm.credential_id || null,
        credential_url: certForm.credential_url || null,
      })
      const created = data?.certification
      if (created?.id) {
        setCertifications((prev) => [created, ...prev].sort((a, b) => String(b.issue_date || '').localeCompare(String(a.issue_date || ''))))
      } else {
        const refreshed = await getEmployeeCertifications(employee.id)
        setCertifications(Array.isArray(refreshed?.certifications) ? refreshed.certifications : [])
      }
      toast.success('Certification added.')
      setCertModalOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to add certification.')
    } finally {
      setCertificationsSaving(false)
    }
  }

  async function submitUpdateCertification() {
    if (!employee?.id || !activeCert?.id || certificationsSaving) return
    const errs = validateCertificationForm(certForm)
    setCertErrors(errs)
    if (Object.keys(errs).length) return
    setCertificationsSaving(true)
    try {
      const data = await updateEmployeeCertification(employee.id, activeCert.id, {
        ...certForm,
        expiration_date: certForm.expiration_date || null,
        credential_id: certForm.credential_id || null,
        credential_url: certForm.credential_url || null,
      })
      const updated = data?.certification
      if (updated?.id) {
        setCertifications((prev) => prev.map((c) => (c.id === activeCert.id ? updated : c)))
      } else {
        const refreshed = await getEmployeeCertifications(employee.id)
        setCertifications(Array.isArray(refreshed?.certifications) ? refreshed.certifications : [])
      }
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
    if (!employee?.id || !certToDelete?.id || certificationsSaving) return
    setCertificationsSaving(true)
    try {
      await deleteEmployeeCertification(employee.id, certToDelete.id)
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

  async function submitVerification() {
    if (!employee?.id || !activeCert?.id || certificationsSaving) return
    if (verifyStatus === 'rejected' && !String(verifyReason || '').trim()) {
      setCertErrors((prev) => ({ ...prev, rejection_reason: 'Rejection reason is required.' }))
      return
    }
    setCertificationsSaving(true)
    try {
      const data = await verifyEmployeeCertification(employee.id, activeCert.id, verifyStatus, verifyStatus === 'rejected' ? verifyReason : null)
      const updated = data?.certification
      if (updated?.id) setCertifications((prev) => prev.map((c) => (c.id === activeCert.id ? updated : c)))
      toast.success('Verification updated.')
      setCertVerifyOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to verify certification.')
    } finally {
      setCertificationsSaving(false)
    }
  }

  function resetGovForm(next = {}) {
    setGovErrors({})
    setGovForm({ id_type: '', id_number: '', issuing_agency: '', expiry_date: '', document_file: null, ...next })
  }

  function validateGovForm(next, opts = {}) {
    const errs = {}
    if (!String(next.id_type || '').trim()) errs.id_type = 'ID Type is required.'
    if (!String(next.id_number || '').trim()) errs.id_number = 'ID Number is required.'
    if (!String(next.issuing_agency || '').trim()) errs.issuing_agency = 'Issuing agency is required.'
    const items = Array.isArray(opts.existing) ? opts.existing : []
    const activeId = opts.activeId
    const number = String(next.id_number || '').trim()
    if (number && items.some((x) => String(x?.id) !== String(activeId || '') && String(x?.id_number || '').toLowerCase() === number.toLowerCase())) {
      errs.id_number = 'Duplicate ID number for this employee.'
    }
    const type = String(next.id_type || '').trim()
    const pattern = govIdDefs[type]?.pattern
    if (pattern && number && !pattern.test(number)) {
      errs.id_number = `Format: ${govIdDefs[type]?.format || 'invalid'} (e.g. ${govIdDefs[type]?.example || '—'})`
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

  const govIdDefs = useMemo(
    () => {
      const base = {
      'PhilSys National ID': {
        agency: 'PSA (Philippine Statistics Authority)',
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
      'SSS ID / UMID': {
        agency: 'Social Security System',
        format: 'XX-XXXXXXX-X',
        example: '12-3456789-0',
        pattern: /^\d{2}-\d{7}-\d$/,
        formatter: (digits) => {
          const d = String(digits || '').replace(/\D/g, '').slice(0, 10)
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
          const d = String(digits || '').replace(/\D/g, '').slice(0, 10)
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
          const d = String(digits || '').replace(/\D/g, '').slice(0, 12)
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
          const d = String(digits || '').replace(/\D/g, '').slice(0, 12)
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
          const d = String(digits || '').replace(/\D/g, '').slice(0, 12)
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
          const d = String(digits || '').replace(/\D/g, '').slice(0, 10)
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

  function openAddGovModal() {
    setActiveGovDoc(null)
    resetGovForm()
    setGovAddOpen(true)
  }

  function openEditGovModal(doc) {
    setActiveGovDoc(doc)
    const canonType = canonicalizeGovIdType(doc?.id_type || '')
    resetGovForm({
      id_type: canonType,
      id_number: doc?.id_number || '',
      issuing_agency: doc?.issuing_agency || govIdDefs[canonType]?.agency || '',
      expiry_date: doc?.expiry_date || '',
      document_file: null,
    })
    setGovEditOpen(true)
  }

  function openPreviewGovModal(doc) {
    setActiveGovDoc(doc)
    setGovPreviewOpen(true)
  }

  function openVerifyGovModal(doc) {
    setActiveGovDoc(doc)
    setGovVerifyStatus('approved')
    setGovVerifyReason('')
    setGovVerifyOpen(true)
  }

  async function submitCreateGov() {
    if (!employee?.id || govDocsSaving) return
    const errs = validateGovForm(govForm, { existing: govDocs, activeId: null })
    setGovErrors(errs)
    if (Object.keys(errs).length) return
    setGovDocsSaving(true)
    try {
      const data = await createEmployeeGovernmentIdDocument(employee.id, { ...govForm, expiry_date: govForm.expiry_date || null })
      const created = data?.government_id
      if (created?.id) setGovDocs((prev) => [created, ...prev])
      toast.success('Government ID uploaded.')
      setGovAddOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to upload Government ID.')
    } finally {
      setGovDocsSaving(false)
    }
  }

  async function submitUpdateGov() {
    if (!employee?.id || !activeGovDoc?.id || govDocsSaving) return
    const errs = validateGovForm(govForm, { existing: govDocs, activeId: activeGovDoc.id })
    setGovErrors(errs)
    if (Object.keys(errs).length) return
    setGovDocsSaving(true)
    try {
      const data = await updateEmployeeGovernmentIdDocument(employee.id, activeGovDoc.id, { ...govForm, expiry_date: govForm.expiry_date || null })
      const updated = data?.government_id
      if (updated?.id) setGovDocs((prev) => prev.map((x) => (x.id === activeGovDoc.id ? updated : x)))
      toast.success('Government ID updated.')
      setGovEditOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to update Government ID.')
    } finally {
      setGovDocsSaving(false)
    }
  }

  function requestDeleteGov(doc) {
    if (!doc?.id || govDocsSaving) return
    setGovDeleteDoc(doc)
    setGovDeleteOpen(true)
  }

  async function confirmDeleteGov() {
    if (!employee?.id || !govDeleteDoc?.id || govDocsSaving) return
    setGovDocsSaving(true)
    try {
      await deleteEmployeeGovernmentIdDocument(employee.id, govDeleteDoc.id)
      setGovDocs((prev) => prev.filter((x) => x.id !== govDeleteDoc.id))
      toast.success('Government ID deleted.')
      setGovDeleteOpen(false)
      setGovDeleteDoc(null)
    } catch (e) {
      toast.error(e?.message || 'Failed to delete Government ID.')
    } finally {
      setGovDocsSaving(false)
    }
  }

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

  const docFolderGuidanceAdmin = useMemo(
    () => getDocFolderGuidance(docsCategory, { isAdmin: true }),
    [docsCategory],
  )

  function resetDocForm(next = {}) {
    setDocErrors({})
    setDocForm({ category: docsCategory || 'Contracts', document_name: '', version: '', expiry_date: '', file: null, ...next })
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
    if (!employee?.id || !activeDoc?.id || docsSaving) return
    setDocsSaving(true)
    try {
      await deleteEmployeeDocument(employee.id, activeDoc.id)
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
    if (!employee?.id || docsSaving) return
    const errs = validateDocForm(docForm, { activeId: null })
    setDocErrors(errs)
    if (Object.keys(errs).length) return
    setDocsSaving(true)
    try {
      const data = await createEmployeeDocument(employee.id, { ...docForm, expiry_date: docForm.expiry_date || null })
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
    const raw = Array.from(files || []).filter((f) => f instanceof File)
    const list = raw.filter((f) => isAllowedDocFile(f) && (f.size || 0) <= 10 * 1024 * 1024)
    const skipped = raw.length - list.length
    if (skipped > 0) {
      toast.error(`${skipped} file(s) skipped. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG. Max 10MB each.`)
    }
    if (!employee?.id || !list.length || docsUploading || docsSaving) return
    setDocsUploading(true)
    setDocsUploadProgress({ total: list.length, done: 0 })
    try {
      for (let i = 0; i < list.length; i += 1) {
        const file = list[i]
        const baseName = String(file?.name || 'Document').replace(/\.[^/.]+$/u, '').trim() || 'Document'
        const data = await createEmployeeDocument(employee.id, {
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
    if (!employee?.id || !activeDoc?.id || docsSaving) return
    const errs = validateDocForm(docForm, { activeId: activeDoc.id })
    setDocErrors(errs)
    if (Object.keys(errs).length) return
    setDocsSaving(true)
    try {
      const data = await updateEmployeeDocument(employee.id, activeDoc.id, { ...docForm, expiry_date: docForm.expiry_date || null })
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

  function openReviewDocModal(doc, presetStatus) {
    setActiveDoc(doc)
    setDocReviewStatus(presetStatus || 'active')
    setDocReviewNote('')
    setDocErrors((prev) => ({ ...prev, review_note: '' }))
    setDocReviewOpen(true)
  }

  async function submitReviewDoc() {
    if (!employee?.id || !activeDoc?.id || docsSaving) return
    if (docReviewStatus === 'rejected' && !String(docReviewNote || '').trim()) {
      setDocErrors((prev) => ({ ...prev, review_note: 'A reason is required when rejecting.' }))
      return
    }
    setDocsSaving(true)
    try {
      const data = await reviewEmployeeDocument(employee.id, activeDoc.id, docReviewStatus, docReviewStatus === 'rejected' ? docReviewNote : (docReviewNote || null))
      const updated = data?.document
      if (updated?.id) setDocs((prev) => prev.map((x) => (x.id === activeDoc.id ? updated : x)))
      toast.success('Document status updated.')
      setDocReviewOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to review document.')
    } finally {
      setDocsSaving(false)
    }
  }

  async function submitGovVerification() {
    if (!employee?.id || !activeGovDoc?.id || govDocsSaving) return
    if (govVerifyStatus === 'rejected' && !String(govVerifyReason || '').trim()) {
      setGovErrors((prev) => ({ ...prev, rejection_reason: 'Rejection reason is required.' }))
      return
    }
    setGovDocsSaving(true)
    try {
      const data = await verifyEmployeeGovernmentIdDocument(employee.id, activeGovDoc.id, govVerifyStatus, govVerifyStatus === 'rejected' ? govVerifyReason : null)
      const updated = data?.government_id
      if (updated?.id) setGovDocs((prev) => prev.map((x) => (x.id === activeGovDoc.id ? updated : x)))
      toast.success('Verification updated.')
      setGovVerifyOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to verify Government ID.')
    } finally {
      setGovDocsSaving(false)
    }
  }

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

  function handleOpenPhotoPicker() {
    if (!canEditProfilePhoto) return
    photoInputRef.current?.click()
  }

  async function handlePhotoSelected(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!canEditProfilePhoto || !file || !employee?.id) return
    const type = String(file.type || '').toLowerCase()
    if (type !== 'image/jpeg' && type !== 'image/png') {
      toast.error('Only JPG, JPEG, and PNG images are allowed.')
      return
    }
    const maxBytes = 2 * 1024 * 1024
    if (file.size > maxBytes) {
      toast.error('Image must be under 2 MB.')
      return
    }
    setPendingPhotoFile(file)
    setPhotoCropOpen(true)
  }

  async function handleCroppedPhotoConfirm(croppedFile) {
    if (!canEditProfilePhoto) return
    if (!employee?.id) return
    setPhotoSaving(true)
    try {
      const data = await uploadEmployeePhoto(employee.id, croppedFile)
      if (data?.employee) setEmployee(data.employee)
      if (isOwnProfile && data?.employee && typeof setUser === 'function') {
        const e = data.employee
        setUser((prev) =>
          prev && typeof prev === 'object'
            ? {
                ...prev,
                profile_image: e.profile_image ?? null,
                profile_image_url: e.profile_image_url ?? e.profile_image ?? null,
                updated_at: e.updated_at ?? prev.updated_at,
              }
            : prev,
        )
      }
      await refreshAdminEmployeeCaches(employee.id)
      toast.success('Profile picture updated.')
    } catch (e) {
      toast.error(e.message || 'Failed to upload photo.')
    } finally {
      setPhotoSaving(false)
      setPendingPhotoFile(null)
    }
  }

  async function handleRemovePhoto() {
    if (!canEditProfilePhoto) return
    if (!employee?.id) return
    setPhotoSaving(true)
    try {
      const data = await removeEmployeePhoto(employee.id)
      if (data?.employee) setEmployee(data.employee)
      else setEmployee((prev) => (prev ? { ...prev, profile_image: null } : prev))
      if (isOwnProfile && typeof setUser === 'function') {
        const e = data?.employee
        setUser((prev) =>
          prev && typeof prev === 'object'
            ? {
                ...prev,
                profile_image: e?.profile_image ?? null,
                profile_image_url: e?.profile_image_url ?? e?.profile_image ?? null,
                updated_at: e?.updated_at ?? prev.updated_at,
              }
            : prev,
        )
      }
      await refreshAdminEmployeeCaches(employee.id)
      toast.success('Profile picture removed.')
    } catch (e) {
      toast.error(e.message || 'Failed to remove photo.')
    } finally {
      setPhotoSaving(false)
    }
  }

  const totalAllowance = useMemo(() => {
    return benefitsData.allowances.reduce((sum, item) => sum + (Number(item.amount) || 0), 0)
  }, [benefitsData.allowances])

  const benefitsValidation = useMemo(() => {
    const coverage = benefitsData.coverages.map((item) => ({
      provider: hasText(item.provider) ? '' : 'Provider is required.',
      coverage_type: hasText(item.coverage_type) ? '' : 'Coverage type is required.',
      effective_date: /^\d{4}-\d{2}-\d{2}$/.test(String(item.effective_date || '')) ? '' : 'Use a valid date (YYYY-MM-DD).',
      monthly_premium: hasText(item.monthly_premium) ? '' : 'Premium/value is required.',
    }))
    const leave = benefitsData.leave_balances.map((item) => {
      const value = String(item.value || '').trim()
      const numeric = Number(value)
      return {
        value: value !== '' && Number.isFinite(numeric) && numeric >= 0 ? '' : 'Use a valid non-negative number.',
      }
    })
    const allowances = benefitsData.allowances.map((item) => {
      const amount = String(item.amount || '').trim()
      const numeric = Number(amount)
      return {
        label: hasText(item.label) ? '' : 'Label is required.',
        amount: amount !== '' && Number.isFinite(numeric) && numeric >= 0 ? '' : 'Use a valid non-negative amount.',
      }
    })
    const normalizedPerks = benefitsData.perks.map((perk) => String(perk || '').trim().toLowerCase())
    const perks = benefitsData.perks.map((perk) => {
      const value = String(perk || '').trim()
      const duplicates = normalizedPerks.filter((p) => p === value.toLowerCase()).length > 1
      return {
        value: !value ? 'Perk name is required.' : duplicates ? 'Perk name must be unique.' : '',
      }
    })

    const hasErrors =
      coverage.some((errors) => Object.values(errors).some(Boolean)) ||
      leave.some((errors) => Object.values(errors).some(Boolean)) ||
      allowances.some((errors) => Object.values(errors).some(Boolean)) ||
      perks.some((errors) => Object.values(errors).some(Boolean))

    return { coverage, leave, allowances, perks, hasErrors }
  }, [benefitsData])

  function toggleBenefitsEditMode() {
    if (benefitsEditMode && benefitsValidation.hasErrors) {
      toast.error('Resolve benefit validation errors first.')
      return
    }
    setBenefitsEditMode((v) => !v)
  }

  function updateCoverageField(index, fieldName, value) {
    setBenefitsData((prev) => ({
      ...prev,
      coverages: prev.coverages.map((item, itemIndex) => (itemIndex === index ? { ...item, [fieldName]: value } : item)),
    }))
  }

  function updateLeaveBalanceField(index, fieldName, value) {
    setBenefitsData((prev) => ({
      ...prev,
      leave_balances: prev.leave_balances.map((item, itemIndex) => (itemIndex === index ? { ...item, [fieldName]: value } : item)),
    }))
  }

  function updateAllowanceField(index, fieldName, value) {
    setBenefitsData((prev) => ({
      ...prev,
      allowances: prev.allowances.map((item, itemIndex) => (itemIndex === index ? { ...item, [fieldName]: value } : item)),
    }))
  }

  function handleAddAllowance() {
    setBenefitsData((prev) => ({
      ...prev,
      allowances: [
        ...prev.allowances,
        { id: `allowance-${Date.now()}`, label: `Allowance ${prev.allowances.length + 1}`, amount: '0.00' },
      ],
    }))
  }

  function handleAddPerk() {
    setBenefitsData((prev) => ({
      ...prev,
      perks: [...prev.perks, `New Perk ${prev.perks.length + 1}`],
    }))
  }

  function updatePerk(index, value) {
    setBenefitsData((prev) => ({
      ...prev,
      perks: prev.perks.map((perk, perkIndex) => (perkIndex === index ? value : perk)),
    }))
  }

  function _updateGovId(fieldName, value) {
    const sanitized = sanitizeGovIdByField(fieldName, value)
    setGovIds((prev) => ({ ...prev, [fieldName]: sanitized }))
    setGovIdVerification((prev) => ({ ...prev, [fieldName]: 'pending' }))
  }

  function _updateSecondaryId(index, fieldName, value) {
    let sanitizedValue = value
    if (fieldName === 'type') {
      sanitizedValue = sanitizeAsciiByRegex(value, /[A-Za-z0-9'().,\-/ ]/, 40)
    } else if (fieldName === 'number') {
      sanitizedValue = sanitizeAsciiByRegex(value, /[A-Za-z0-9\-/ ]/, 30)
    }
    setSecondaryIds((prev) => prev.map((item, itemIndex) => (itemIndex === index ? { ...item, [fieldName]: sanitizedValue } : item)))
  }

  function _removeSecondaryIdRow(rowId) {
    setSecondaryIds((prev) => prev.filter((item) => item.id !== rowId))
  }

  const govIdValidation = useMemo(() => {
    const ids = {
      tin: '',
      sss: '',
      philhealth: '',
      pagibig: '',
    }
    Object.entries(govIds).forEach(([key, raw]) => {
      const value = String(raw || '').trim()
      if (!value) return
      ids[key] = validateGovIdByField(key, value)
      if (!ids[key] && govIdVerification[key] === 'verified' && !govIdDocuments[key]) {
        ids[key] = 'Upload supporting document before verification.'
      }
    })

    const defaultTypeSet = new Set(["Driver's License", 'Passport', 'UMID Card', 'Other ID'])
    const secondary = secondaryIds.map((item) => {
      const typeValue = String(item.type || '').trim()
      const numberValue = String(item.number || '').trim()
      const expiryValue = String(item.expiry_date || '').trim()
      const hasCustomType = typeValue !== '' && !defaultTypeSet.has(typeValue)
      const hasContent = numberValue !== '' || expiryValue !== '' || hasCustomType
      const rowErrors = { type: '', number: '' }
      if (!hasContent) return rowErrors
      if (!typeValue) rowErrors.type = 'ID type is required.'
      if (!numberValue) rowErrors.number = 'ID number is required.'
      return rowErrors
    })

    const hasGovIdInput = Object.values(govIds).some((v) => String(v || '').trim() !== '')
    const hasSecondaryInput = secondaryIds.some((item) => {
      const typeValue = String(item.type || '').trim()
      const numberValue = String(item.number || '').trim()
      const expiryValue = String(item.expiry_date || '').trim()
      const hasCustomType = typeValue !== '' && !defaultTypeSet.has(typeValue)
      return numberValue !== '' || expiryValue !== '' || hasCustomType
    })

    const shouldValidate = hasGovIdInput || hasSecondaryInput
    const hasErrors =
      shouldValidate &&
      (Object.values(ids).some(Boolean) ||
        secondary.some((row) => Object.values(row).some(Boolean)))

    return { ids, secondary, hasErrors, shouldValidate }
  }, [govIds, secondaryIds, govIdVerification, govIdDocuments])

  function _addSecondaryId() {
    setSecondaryIds((prev) => [
      ...prev,
      {
        id: `secondary-${Date.now()}`,
        type: 'Other ID',
        number: '',
        expiry_date: '',
        status: 'valid',
      },
    ])
  }

  function _toggleGovIdVerification(fieldName) {
    const value = String(govIds[fieldName] || '').trim()
    const formatError = validateGovIdByField(fieldName, value)
    if (!value) {
      toast.error('Enter ID value first.')
      return
    }
    if (formatError) {
      toast.error(formatError)
      return
    }
    if (!govIdDocuments[fieldName]) {
      toast.error('Upload supporting document before verification.')
      return
    }
    setGovIdVerification((prev) => ({
      ...prev,
      [fieldName]: prev[fieldName] === 'verified' ? 'pending' : 'verified',
    }))
  }

  function _handleOpenGovIdFilePicker(fieldName) {
    setActiveGovIdFileField(fieldName)
    govIdFileInputRef.current?.click()
  }

  function _handleGovIdFileSelected(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file || !activeGovIdFileField) return
    setGovIdDocuments((prev) => ({
      ...prev,
      [activeGovIdFileField]: {
        name: file.name,
        type: file.type || 'application/octet-stream',
        size: file.size,
        uploaded_at: new Date().toISOString(),
        object_url: URL.createObjectURL(file),
      },
    }))
  }

  function _handleRemoveGovIdDocument(fieldName) {
    const existing = govIdDocuments[fieldName]
    if (existing?.object_url) URL.revokeObjectURL(existing.object_url)
    setGovIdDocuments((prev) => ({ ...prev, [fieldName]: null }))
    setGovIdVerification((prev) => ({ ...prev, [fieldName]: 'pending' }))
  }

  function _handleViewGovIdDocument(fieldName) {
    const doc = govIdDocuments[fieldName]
    if (doc?.object_url) {
      window.open(doc.object_url, '_blank')
      return
    }
    toast.message('Document preview is available for files uploaded in this session.')
  }

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
    setEmergencyContacts((prev) =>
      prev.map((item) => ({ ...item, is_primary: item.id === contactId }))
    )
  }

  async function handleResetPasswordQuickAction() {
    if (!employee?.id) return
    const trimmed = String(resetPasswordValue || '').trim()
    const err = validateTempPassword(trimmed)
    if (err) {
      toast.error(err)
      return
    }
    try {
      await resetEmployeePassword(employee.id, trimmed)
      toast.success('Password reset successfully.')
      setResetPasswordOpen(false)
      setResetPasswordValue('')
    } catch (e) {
      toast.error(e.message || 'Failed to reset password.')
    }
  }

  async function handleToggleEmployeeActiveQuickAction() {
    if (!employee?.id) return
    setToggleActiveSaving(true)
    try {
      await toggleEmployeeActive(employee.id)
      setEmployee((prev) => (prev ? { ...prev, is_active: !prev.is_active } : prev))
      toast.success(employee.is_active ? 'Employee deactivated.' : 'Employee activated.')
      setToggleActiveOpen(false)
    } catch (e) {
      toast.error(e.message || 'Failed to update employee status.')
    } finally {
      setToggleActiveSaving(false)
    }
  }

  const isCompanyHead = useMemo(
    () => companies.some((c) => String(c.company_head_id) === String(employee?.id)),
    [companies, employee?.id]
  )

  const isBranchManager = useMemo(() => {
    if (!employee?.id || !transferBranches.length) return false
    return transferBranches.some((b) => String(b.branch_manager_id) === String(employee.id))
  }, [employee?.id, transferBranches])

  const { effectiveCurrentBranchId, currentBranchName } = useMemo(() => {
    if (employee?.branch_id) {
      const name = employee.branch_name || transferBranches.find((b) => String(b.id) === String(employee.branch_id))?.name
      return { effectiveCurrentBranchId: String(employee.branch_id), currentBranchName: name }
    }
    if (employee?.department_id) {
      const dept = departments.find((d) => String(d.id) === String(employee.department_id))
      return {
        effectiveCurrentBranchId: dept?.branch_id ? String(dept.branch_id) : null,
        currentBranchName: dept?.branch_name,
      }
    }
    return { effectiveCurrentBranchId: null, currentBranchName: employee?.branch_name }
  }, [employee?.branch_id, employee?.branch_name, employee?.department_id, departments, transferBranches])

  const availableTransferBranches = useMemo(
    () => transferBranches.filter((b) => !effectiveCurrentBranchId || String(b.id) !== effectiveCurrentBranchId),
    [transferBranches, effectiveCurrentBranchId]
  )

  function openTransferModal() {
    setTransferForm({
      targetBranchId: '',
      transferDate: new Date().toISOString().slice(0, 10),
      departmentId: '',
      reason: '',
    })
    setTransferOpen(true)
  }

  async function handleLeaveCreditAdjustSubmit() {
    if (!employee?.id || !canEditEmployeeRecord) return
    const d = parseInt(String(leaveAdjustDelta).trim(), 10)
    if (!Number.isFinite(d) || d === 0) {
      toast.error('Enter a non-zero whole number for the adjustment.')
      return
    }
    const reason = String(leaveAdjustReason || '').trim()
    if (!reason) {
      toast.error('Reason is required for audit.')
      return
    }
    setLeaveAdjustSaving(true)
    try {
      const data = await adjustEmployeeLeaveCredits(employee.id, { delta: d, reason })
      if (data?.employee) {
        setEmployee((prev) => (prev ? { ...prev, ...data.employee } : prev))
      }
      const snap = await getEmployeeProfileSnapshot(String(employeeId), {
        lite: true,
        include_government_ids: false,
        include_emergency_contacts: false,
        include_benefits: false,
        include_leave_credits: true,
        include_leave_credits_history: false,
        include_compensation_summary: true,
        include_pay_cycle_preview: false,
      })
      setLeaveCreditsBlock(snap?.leave_credits ?? null)
      setLeaveAdjustOpen(false)
      setLeaveAdjustDelta('')
      setLeaveAdjustReason('')
      toast.success('Leave credits updated.')
    } catch (e) {
      toast.error(e.message || 'Failed to adjust leave credits.')
    } finally {
      setLeaveAdjustSaving(false)
    }
  }

  async function handleTransferSubmit() {
    if (!employee?.id || !transferForm.targetBranchId) return
    setTransferSaving(true)
    try {
      const payload = {
        target_branch_id: Number(transferForm.targetBranchId),
        transfer_date: transferForm.transferDate || new Date().toISOString().slice(0, 10),
        department_id: transferForm.departmentId ? Number(transferForm.departmentId) : undefined,
        reason: transferForm.reason?.trim() || undefined,
      }
      const data = await transferEmployee(employee.id, payload)
      const updated = data.employee
      if (updated) setEmployee(updated)
      const toBranch = transferBranches.find((b) => String(b.id) === String(transferForm.targetBranchId))
      toast.success(`Employee transferred to ${toBranch?.name || 'new branch'}.`)
      setTransferOpen(false)
    } catch (e) {
      toast.error(e.message || 'Failed to transfer employee.')
    } finally {
      setTransferSaving(false)
    }
  }

  async function handleSave() {
    if (!canEditProfile || !employee?.id) return
    if (activeTab === 'government-ids' && govIdValidation.hasErrors) {
      toast.error('Fix Government ID validation errors before saving.')
      return
    }
    const hasPartialStructuredAddress =
      hasText(form.street_address) ||
      hasText(form.barangay) ||
      hasText(form.city) ||
      hasText(form.province) ||
      hasText(form.postal_code)
    // Allow partial structured address updates; do not hard-block profile save.
    setSaving(true)
    profileSaveRollbackRef.current = employee ? { ...employee } : null
    try {
      const salaryDerived = deriveDerivedSalaryFieldsFromBasic(form.monthly_salary, scheduleRateMeta)
      const composedHomeAddress = hasPartialStructuredAddress ? composeHomeAddress(form) : form.home_address
      const normalizedEmploymentStatus = normalizeEmploymentStatusValue(form.employment_status)
      const payload = {
        first_name: form.first_name || undefined,
        middle_name: form.middle_name || null,
        last_name: form.last_name || undefined,
        suffix: form.suffix || null,
        username: form.username || undefined,
        email: form.email || undefined,
        phone_number: form.phone_number || undefined,
        date_of_birth: form.date_of_birth || null,
        gender: form.gender || null,
        civil_status: form.civil_status || null,
        nationality: form.nationality || null,
        home_address: composedHomeAddress || null,
        full_address: composedHomeAddress || null,
        street_address: form.street_address?.trim() || null,
        barangay: form.barangay?.trim() || null,
        city: form.city?.trim() || null,
        province: form.province?.trim() || null,
        postal_code: form.postal_code?.trim() || null,
        department_id: form.department_id ? Number(form.department_id) : null,
        section_unit_id: form.section_unit_id ? Number(form.section_unit_id) : null,
        company_id: form.company_id ? Number(form.company_id) : null,
        branch_id: form.branch_id ? Number(form.branch_id) : null,
        position: form.position || undefined,
        ...(normalizedEmploymentStatus
          ? {
              employment_status: normalizedEmploymentStatus,
              employment_status_effective_date: form.employment_status_effective_date || null,
            }
          : {}),
        employment_type: form.employment_type || undefined,
        branch_office_location: form.branch_office_location || null,
        hire_date: form.hire_date || null,
        payroll_effective_date: form.payroll_effective_date || null,
        contract_start_date: form.contract_start_date || null,
        contract_end_date: form.contract_end_date || null,
        working_schedule_id: form.working_schedule_id ? Number(form.working_schedule_id) : null,
        monthly_salary: parseSalaryNumber(form.monthly_salary),
        hourly_rate: salaryDerived.hourly_rate !== '' ? parseSalaryNumber(salaryDerived.hourly_rate) : parseSalaryNumber(form.hourly_rate),
        daily_rate: salaryDerived.daily_rate !== '' ? parseSalaryNumber(salaryDerived.daily_rate) : parseSalaryNumber(form.daily_rate),
        monthly_rate: salaryDerived.monthly_rate !== '' ? parseSalaryNumber(salaryDerived.monthly_rate) : parseSalaryNumber(form.monthly_rate),
      }
      const requestPayload = canEditSalaryDetails
        ? payload
        : (() => {
            const {
              monthly_salary: _ms,
              hourly_rate: _hr,
              daily_rate: _dr,
              monthly_rate: _mr,
              ...rest
            } = payload
            return rest
          })()
      // Optimistic merge (omit undefined so we do not wipe fields with undefined overwrites).
      const optimisticPatch = Object.fromEntries(
        Object.entries(requestPayload).filter(([, v]) => v !== undefined)
      )
      const optimisticName = formatEmployeeName(payload)
      setEmployee((prev) => {
        if (!prev) return prev
        return {
          ...prev,
          ...optimisticPatch,
          ...(optimisticName ? { name: optimisticName } : {}),
          ...(canEditSalaryDetails && payload.monthly_salary != null ? { monthly_salary: String(payload.monthly_salary) } : {}),
        }
      })
      setSaveStatus('saved')
      if (saveStatusTimerRef.current) {
        clearTimeout(saveStatusTimerRef.current)
      }
      saveStatusTimerRef.current = setTimeout(() => setSaveStatus('idle'), 1800)
      // Backend returns quickly; heavy profile/compensation work runs on the queue.
      const data = await updateEmployee(employee.id, requestPayload, { timeoutMs: 45000 })
      if (!data?.employee || typeof data.employee !== 'object') {
        throw new Error('Invalid server response: missing employee. Your changes may not have been saved.')
      }
      const updated = data.employee
      setEmployee(updated)
      profileSaveRollbackRef.current = null
      await refreshAdminEmployeeCaches(updated.id)
      // Refetch fresh profile payload immediately so dependent tabs (payroll/tax/payslip context)
      // read the same persisted demographics used by backend computations.
      const refreshedProfile = await getEmployeeProfileSnapshot(String(updated.id), {
        lite: true,
        include_government_ids: false,
        include_emergency_contacts: false,
        include_benefits: false,
        include_leave_credits: false,
        include_leave_credits_history: false,
        include_compensation_summary: false,
        include_pay_cycle_preview: false,
      })
      if (refreshedProfile?.user) {
        setEmployee((prev) => (prev ? { ...prev, ...refreshedProfile.user } : refreshedProfile.user))
      }
      toast.success('Employee profile updated. Recalculation is running in the background.')
      // Refresh heavy snapshot data in the background so the Save button is never blocked by slow GETs.
      void (async () => {
        try {
          const snap = await getEmployeeProfileSnapshot(employee.id, {
            lite: true,
            include_government_ids: true,
            include_emergency_contacts: true,
            include_benefits: false,
            include_leave_credits: true,
            include_leave_credits_history: false,
            include_compensation_summary: true,
            include_pay_cycle_preview: false,
          })
          if (snap?.user) {
            setEmployee((prev) => (prev ? { ...prev, ...snap.user } : snap.user))
          }
          setLeaveCreditsBlock(snap?.leave_credits ?? null)
          setCompensationSummary(snap?.compensation_summary ?? null)
          const statusData = await getAdminEmployeeStatus(employee.id)
          setEmployeeStatusHistory(Array.isArray(statusData?.history) ? statusData.history : [])
        } catch (err) {
          console.warn('[AdminEmployeeProfile] Post-save refresh failed', err)
        }
      })()
    } catch (e) {
      if (saveStatusTimerRef.current) {
        clearTimeout(saveStatusTimerRef.current)
        saveStatusTimerRef.current = null
      }
      if (profileSaveRollbackRef.current) {
        setEmployee(profileSaveRollbackRef.current)
        profileSaveRollbackRef.current = null
      }
      setSaveStatus('idle')
      toast.error(getProfileFriendlyError(e))
    } finally {
      setSaving(false)
    }
  }

  async function handleManageSignature() {
    if (!canEditEmployeeRecord) return
    if (!employee?.id) return
    setSignatureDialogOpen(true)
  }

  async function handleSaveSignature(dataUrl) {
    if (!canEditEmployeeRecord || !employee?.id) return
    setSignatureBusy(true)
    try {
      const data = await saveEmployeeSignature(employee.id, dataUrl)
      if (data?.employee) setEmployee(data.employee)
      toast.success('Signature saved.')
      setSignatureDialogOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to save signature.')
    } finally {
      setSignatureBusy(false)
    }
  }

  async function handleClearSignature() {
    if (!canEditEmployeeRecord || !employee?.id) return
    setSignatureBusy(true)
    try {
      const data = await clearEmployeeSignature(employee.id)
      if (data?.employee) setEmployee(data.employee)
      toast.success('Signature removed.')
      setSignatureDialogOpen(false)
    } catch (e) {
      toast.error(e?.message || 'Failed to remove signature.')
    } finally {
      setSignatureBusy(false)
    }
  }

  if (loading) {
    console.info('[AdminEmployeeProfile] Rendering loading state', { employeeId })
    return <ProfilePageSkeleton />
  }

  if (error) {
    console.error('[AdminEmployeeProfile] Rendering error state', { employeeId, error })
    return <div className="py-12 text-center text-sm text-destructive">{error}</div>
  }

  if (!employee) {
    console.warn('[AdminEmployeeProfile] Rendering empty employee state', { employeeId })
    return (
      <div className="space-y-4 py-12 text-center">
        <p className="text-sm text-muted-foreground">Employee not found.</p>
        <Button variant="outline" onClick={() => navigate(hrPanelPath(hrBase, 'employees'))}>
          Back to Employees
        </Button>
      </div>
    )
  }

  const statusKey = String(employee.employment_status || employee.status || (employee.is_active ? 'active' : 'inactive')).toLowerCase()
  const statusMeta = {
    active: { label: 'Active', className: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-700/40 dark:bg-emerald-900/25 dark:text-emerald-300' },
    inactive: { label: 'Inactive', className: 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700/50 dark:bg-slate-800/50 dark:text-slate-400' },
    suspended: { label: 'Suspended', className: 'border-red-200 bg-red-50 text-red-700 dark:border-red-700/40 dark:bg-red-900/25 dark:text-red-300' },
  }[statusKey] || { label: employee.is_active ? 'Active' : 'Inactive', className: employee.is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-700/40 dark:bg-emerald-900/25 dark:text-emerald-300' : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700/50 dark:bg-slate-800/50 dark:text-slate-400' }

  const _govIdStatusMeta = {
    verified: { label: 'Verified', className: 'border-emerald-200 bg-emerald-50 text-emerald-700', icon: CheckCircle2 },
    pending: { label: 'Pending Verification', className: 'border-amber-200 bg-amber-50 text-amber-700', icon: Clock4 },
    rejected: { label: 'Rejected', className: 'border-red-200 bg-red-50 text-red-700', icon: AlertTriangle },
  }

  const dobAge = ageFromDob(form.date_of_birth)

  return (
    <Motion.div
      className="w-full space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: 'easeOut' }}
    >
      {!canEditProfile && (
        <div
          className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100"
          role="status"
        >
          View-only access: you can review this profile but cannot save changes. Contact HR if you need edits.
        </div>
      )}
      {/* Top actions — Back, identity, Cancel / Save */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/60 bg-card/90 px-4 py-3 shadow-sm backdrop-blur-md supports-[backdrop-filter]:bg-card/80 dark:border-white/10 dark:bg-[#111827]/92">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 @md:gap-3">
          <Button variant="ghost" size="sm" className="shrink-0 text-muted-foreground hover:text-foreground" onClick={() => navigate(hrPanelPath(hrBase, 'employees'))}>
            <ArrowLeft className="mr-1 size-4" />
            Back
          </Button>
          <div className="hidden h-8 w-px bg-border/60 @sm:block" aria-hidden />
          <div className="flex min-w-0 items-center gap-2">
            <Avatar className="size-9 shrink-0 rounded-lg ring-2 ring-border/30 dark:ring-white/10">
              <AvatarImage src={profileImageUrl(employee.profile_image)} alt="" />
              <AvatarFallback className="rounded-lg text-xs font-bold">{initials(employee.name)}</AvatarFallback>
            </Avatar>
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-foreground">{employee.name}</p>
              <p className="truncate text-xs text-muted-foreground">{employee.employee_code || employee.employee_id || `EMP-${employee.id}`}</p>
            </div>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {isOwnProfile && canEditProfile && employee?.id ? (
            <Button
              variant="outline"
              size="sm"
              onClick={() => navigate(hrPanelPath(hrBase, `profile/${employee.id}`))}
              className="dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5"
            >
              <Pencil className="mr-1.5 size-4" />
              Edit Profile
            </Button>
          ) : null}
          {canEditProfile && (
            <>
              <Button variant="outline" size="sm" onClick={() => navigate(hrPanelPath(hrBase, 'employees'))} className="dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">
                Cancel
              </Button>
              <Button
                onClick={handleSave}
                disabled={saving}
                size="sm"
                className="bg-teal-600 text-white hover:bg-teal-500 focus-visible:ring-teal-500 dark:bg-teal-600 dark:hover:bg-teal-500"
              >
                <FilePenLine className="mr-1.5 size-4" />
                {saving && saveStatus !== 'saved' ? 'Saving…' : saveStatus === 'saved' ? 'Saved' : 'Save Changes'}
              </Button>
            </>
          )}
        </div>
      </div>

      <div>
        <h1 className="text-xl font-bold tracking-tight text-foreground @sm:text-2xl">
          {canEditProfile ? 'Edit Employee Profile' : 'Employee Profile'}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {canEditProfile
            ? 'Update records for payroll, government reporting, and access. Unsaved changes are kept until you save.'
            : 'Review employee records for payroll, government reporting, and access.'}
        </p>
      </div>

      <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.05, duration: 0.2 }}>
      <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
        <CardContent className="p-5 @sm:p-6">
          <input
            ref={photoInputRef}
            type="file"
            accept="image/png,image/jpeg,image/jpg"
            className="hidden"
            onChange={handlePhotoSelected}
          />
          <div className="flex flex-wrap items-start gap-6">
            {/* Avatar with camera overlay */}
            <div className="relative shrink-0">
              <Avatar className="size-28 rounded-2xl ring-4 ring-border/20 dark:ring-white/8">
                <AvatarImage src={profileImageUrl(employee.profile_image)} alt={employee.name} />
                <AvatarFallback className="rounded-2xl text-xl font-bold">{initials(employee.name)}</AvatarFallback>
              </Avatar>
              <button
                type="button"
                onClick={handleOpenPhotoPicker}
                disabled={photoSaving || !canEditProfilePhoto}
                title="Change photo"
                className="absolute -bottom-1.5 -right-1.5 flex size-8 cursor-pointer items-center justify-center rounded-full border border-border/60 bg-background shadow-sm transition-colors hover:bg-muted dark:border-white/10 dark:bg-[#1e293b] dark:hover:bg-[#263046]"
              >
                <Camera className="size-4 text-muted-foreground" />
              </button>
              <Circle className={`absolute -top-1 -left-1 size-3.5 fill-current ${employee.is_active ? 'text-emerald-500' : 'text-slate-500'}`} />
            </div>

            {/* Name + meta + kebab */}
            <div className="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <h2 className="text-3xl font-bold tracking-tight text-foreground">{employee.name}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {[employee.position, employee.department].filter((v) => String(v || '').trim() !== '').join(' · ') || 'No position assigned'}
                </p>

                {/* Status pills row */}
                <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                  <Badge className={statusMeta.className}>{statusMeta.label}</Badge>
                  <RoleBadge user={employee} />
                  {employee.employee_level_label ? (
                    <Badge variant="outline" className="border-slate-300 bg-slate-50 text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                      {employee.employee_level_label}
                    </Badge>
                  ) : null}
                  {employee.is_execom || employee.execom_badge ? (
                    <Badge className="border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900/50 dark:bg-violet-950/40 dark:text-violet-200">
                      EXECOM
                    </Badge>
                  ) : null}
                  {String(employee.employment_status || '').toLowerCase() === 'consultant' ? (
                    <>
                      <Badge className="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        Consultant
                      </Badge>
                      <Badge variant="outline" className="border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                        Fixed Salary Payroll
                      </Badge>
                    </>
                  ) : null}
                  <span className="inline-flex items-center gap-1 rounded-md border border-border/50 px-2 py-0.5 text-xs font-medium text-muted-foreground dark:border-white/10">
                    <CreditCard className="size-3 shrink-0" />
                    {employee.employee_code || employee.employee_id || `EMP-${employee.id}`}
                  </span>
                  <AdminProfileOrgScopePill employee={employee} />
                  {hasText(employee.hire_date) && (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                      <Clock3 className="size-3 shrink-0" />
                      Hired {formatDate(employee.hire_date)}
                    </span>
                  )}
                </div>

                {/* QR / Schedule / Face access status dots */}
                <div className="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                  <span className="flex items-center gap-1">
                    <span className={`size-1.5 rounded-full ${employee.has_qr ? 'bg-teal-500' : 'bg-slate-500'}`} />
                    QR {employee.has_qr ? 'Issued' : 'Not Issued'}
                  </span>
                  <span className="flex items-center gap-1">
                    <span className={`size-1.5 rounded-full ${hasAssignedSchedule(employee) ? 'bg-indigo-500' : 'bg-slate-500'}`} />
                    {hasAssignedSchedule(employee) ? 'Scheduled' : 'No Schedule'}
                  </span>
                  <span className="flex items-center gap-1">
                    <span className={`size-1.5 rounded-full ${employee.has_face ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                    Face {employee.has_face ? 'Registered' : 'Not Registered'}
                  </span>
                </div>
              </div>

              {/* Kebab actions menu */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="size-8 shrink-0 text-muted-foreground hover:text-foreground dark:hover:bg-white/5">
                    <MoreVertical className="size-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48 dark:bg-[#1e293b] dark:border-white/10">
                  <DropdownMenuItem onClick={handleOpenPhotoPicker} disabled={photoSaving || !canEditProfilePhoto} className="cursor-pointer">
                    <Camera className="mr-2 size-4" />
                    Change Photo
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onClick={() => setRemovePhotoConfirmOpen(true)}
                    disabled={photoSaving || !employee.profile_image || !canEditProfilePhoto}
                    className="cursor-pointer text-destructive focus:text-destructive"
                  >
                    <Trash2 className="mr-2 size-4" />
                    Remove Photo
                  </DropdownMenuItem>
                  <DropdownMenuSeparator className="dark:bg-white/10" />
                  <DropdownMenuItem onClick={() => setResetPasswordOpen(true)} className="cursor-pointer">
                    <KeyRound className="mr-2 size-4" />
                    Reset Password
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onClick={() => setToggleActiveOpen(true)}
                    className="cursor-pointer text-destructive focus:text-destructive"
                  >
                    {employee.is_active ? <UserX className="mr-2 size-4" /> : <UserCog className="mr-2 size-4" />}
                    {employee.is_active ? 'Deactivate Employee' : 'Activate Employee'}
                  </DropdownMenuItem>
                  <DropdownMenuSeparator className="dark:bg-white/10" />
                  <DropdownMenuItem
                    onClick={openTransferModal}
                    disabled={isCompanyHead}
                    className="cursor-pointer"
                    title={isCompanyHead ? 'Company Heads cannot be transferred. Reassign head first.' : 'Transfer employee to another branch'}
                  >
                    <ArrowRightLeft className="mr-2 size-4" />
                    Transfer Employee
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>
        </CardContent>
      </Card>
      </Motion.div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList variant="line" className="mb-6 h-auto w-full justify-start gap-0.5 overflow-x-auto whitespace-nowrap rounded-lg border border-border/50 bg-muted/20 px-1 py-1 dark:border-white/10 dark:bg-white/[0.03]">
          {tabs.map((t) => (
            <TabsTrigger
              key={t.id}
              value={t.id}
              className="relative rounded-md border-b-0 border-transparent px-4 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-background/80 hover:text-foreground data-[state=active]:bg-background data-[state=active]:text-teal-700 data-[state=active]:shadow-sm dark:data-[state=active]:text-teal-300"
            >
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {activeTab === 'personal-info' && (
        <TabsContent value="personal-info">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }}>
          <div className="grid gap-8 @lg:grid-cols-3">
            <div className="space-y-8 @lg:col-span-2">
              <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                <CardHeader className="space-y-1 pb-2">
                  <CardTitle className="flex items-center gap-2 text-xl"><UserRound className="size-5 text-foreground" />Personal Information</CardTitle>
                  <p className="text-sm font-normal text-muted-foreground">Legal name and contact details used for payroll and HR records.</p>
                </CardHeader>
                <CardContent className="space-y-8">
                  <div>
                    <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Contact</h3>
                    <div className="grid gap-5 @sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <UserRound className="size-4 shrink-0 text-muted-foreground" />
                      First name
                      <span className="text-destructive" aria-hidden>*</span>
                    </Label>
                    <Input className="h-11" value={form.first_name} onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))} />
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <UserRound className="size-4 shrink-0 text-muted-foreground" />
                      Middle name
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input className="h-11" value={form.middle_name} onChange={(e) => setForm((f) => ({ ...f, middle_name: e.target.value }))} />
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <UserRound className="size-4 shrink-0 text-muted-foreground" />
                      Last name
                      <span className="text-destructive" aria-hidden>*</span>
                    </Label>
                    <Input className="h-11" value={form.last_name} onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))} />
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <UserRound className="size-4 shrink-0 text-muted-foreground" />
                      Suffix
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input className="h-11" value={form.suffix || ''} onChange={(e) => setForm((f) => ({ ...f, suffix: e.target.value }))} placeholder="e.g. Jr." />
                  </div>
                  <div className="space-y-2">
                  <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                    <UserRound className="size-4 shrink-0 text-muted-foreground" />
                    Username
                    <span className="text-destructive" aria-hidden>*</span>
                  </Label>
                  <Input className="h-11" value={form.username} onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))} />
                  <FieldHint>Used for login. For imports, this is generated from first name.</FieldHint>
                </div>
                <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <Mail className="size-4 shrink-0 text-muted-foreground" />
                      Email
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input className="h-11" type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} />
                    <FieldHint>Optional. If provided, it must be unique across employees.</FieldHint>
                    {hasText(form.email) ? (
                      <p className="inline-flex items-center gap-1 text-[11px] text-muted-foreground"><Circle className="size-2 fill-current" />Email is verified and active</p>
                    ) : (
                      <p className="inline-flex items-center gap-1 text-[11px] text-muted-foreground"><Circle className="size-2 fill-current" />No email on file</p>
                    )}
                  </div>
                  <div className="space-y-2 @sm:col-span-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <Phone className="size-4 shrink-0 text-muted-foreground" />
                      Mobile number
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input className="h-11" placeholder="+63 9XX XXX XXXX or 09XXXXXXXXX" value={form.phone_number} onChange={(e) => setForm((f) => ({ ...f, phone_number: e.target.value }))} />
                    <FieldHint>Used for SMS alerts, OTP, and emergency contact when provided.</FieldHint>
                  </div>
                    </div>
                  </div>

                  <div className="border-t border-border/40 pt-6 dark:border-white/10">
                    <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Demographics</h3>
                    <div className="grid gap-5 @sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <Calendar className="size-4 shrink-0 text-muted-foreground" />
                      Date of birth
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input className="h-11" type="date" value={form.date_of_birth || ''} onChange={(e) => setForm((f) => ({ ...f, date_of_birth: e.target.value }))} />
                    {dobAge != null && (
                      <p className="text-xs text-muted-foreground">{dobAge} years old</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <UserRound className="size-4 shrink-0 text-muted-foreground" />
                      Gender
                    </Label>
                    <Select value={form.gender || 'none'} onValueChange={(v) => setForm((f) => ({ ...f, gender: v === 'none' ? '' : v }))}>
                      <SelectTrigger className="h-11 w-full min-w-[220px]"><SelectValue placeholder="Select gender" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">Select gender</SelectItem>
                        <SelectItem value="Male">Male</SelectItem>
                        <SelectItem value="Female">Female</SelectItem>
                        <SelectItem value="Other">Other</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <Heart className="size-4 shrink-0 text-muted-foreground" />
                      Civil status
                    </Label>
                    <Select value={form.civil_status || 'none'} onValueChange={(v) => setForm((f) => ({ ...f, civil_status: v === 'none' ? '' : v }))}>
                      <SelectTrigger className="h-11 w-full min-w-[220px]"><SelectValue placeholder="Select civil status" /></SelectTrigger>
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
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <Globe className="size-4 shrink-0 text-muted-foreground" />
                      Nationality
                    </Label>
                    <Select value={form.nationality || 'none'} onValueChange={(v) => setForm((f) => ({ ...f, nationality: v === 'none' ? '' : v }))}>
                      <SelectTrigger className="h-11 w-full min-w-[220px]"><SelectValue placeholder="Select nationality" /></SelectTrigger>
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
                  </div>
                    </div>
                  </div>

                  <div className="border-t border-border/40 pt-6 dark:border-white/10">
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Address</h3>
                    <FieldHint>Used for payroll, government IDs, and official correspondence. Search PH addresses or edit line items.</FieldHint>
                    <div className="mt-5 grid gap-5 @sm:grid-cols-2">
                  <div className="space-y-2 @sm:col-span-2">
                    <Label className="flex items-center gap-2 text-sm font-medium text-foreground">
                      <MapPin className="size-4 shrink-0 text-muted-foreground" />
                      Full address (search)
                      <span className="text-xs font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    {isMapsLoaded ? (
                      <Autocomplete
                        onLoad={(ac) => {
                          homeAddressAutocompleteRef.current = ac
                          try {
                            ac.setFields(['address_components', 'formatted_address', 'name'])
                            ac.setTypes(['address'])
                          } catch {
                            // ignore
                          }
                        }}
                        onPlaceChanged={makeProfilePlaceChangedHandler(homeAddressAutocompleteRef)}
                        options={{
                          componentRestrictions: { country: 'ph' },
                        }}
                      >
                        <Input
                          className="h-11"
                          value={form.home_address}
                          onChange={(e) => {
                            setAddressTouched(true)
                            setForm((f) => ({ ...f, home_address: e.target.value }))
                          }}
                          placeholder="Start typing to search an address..."
                        />
                      </Autocomplete>
                    ) : (
                      <Input
                        className="h-11"
                        value={form.home_address}
                        onChange={(e) => {
                          setAddressTouched(true)
                          setForm((f) => ({ ...f, home_address: e.target.value }))
                        }}
                        placeholder="Start typing to search an address..."
                      />
                    )}
                    {mapsLoadError && (
                      <p className="text-xs text-amber-600">Address autocomplete unavailable: {mapsLoadError}</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" />
                      Street Address
                    </Label>
                    {isMapsLoaded ? (
                      <Autocomplete
                        onLoad={(ac) => {
                          streetAddressAutocompleteRef.current = ac
                          try {
                            ac.setFields(['address_components', 'formatted_address', 'name'])
                            ac.setTypes(['address'])
                          } catch {
                            // ignore
                          }
                        }}
                        onPlaceChanged={makeProfilePlaceChangedHandler(streetAddressAutocompleteRef)}
                        options={{
                          componentRestrictions: { country: 'ph' },
                        }}
                      >
                        <Input
                          className="h-11"
                          value={form.street_address}
                          onChange={(e) => {
                            const v = e.target.value
                            setAddressTouched(true)
                            setForm((prev) => {
                              const next = { ...prev, street_address: v }
                              const composed = composeHomeAddress(next)
                              return { ...next, home_address: composed || next.home_address }
                            })
                          }}
                          placeholder="Start typing to search address..."
                        />
                      </Autocomplete>
                    ) : (
                      <Input
                        className="h-11"
                        value={form.street_address}
                        onChange={(e) => {
                          const v = e.target.value
                          setAddressTouched(true)
                          setForm((prev) => {
                            const next = { ...prev, street_address: v }
                            const composed = composeHomeAddress(next)
                            return { ...next, home_address: composed || next.home_address }
                          })
                        }}
                        placeholder="House no., street, subdivision"
                      />
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" />
                      Barangay
                    </Label>
                    {isMapsLoaded ? (
                      <Autocomplete
                        onLoad={(ac) => {
                          barangayAutocompleteRef.current = ac
                          try {
                            ac.setFields(['address_components', 'formatted_address', 'name'])
                            ac.setTypes(['address'])
                          } catch {
                            // ignore
                          }
                        }}
                        onPlaceChanged={makeProfilePlaceChangedHandler(barangayAutocompleteRef)}
                        options={{
                          componentRestrictions: { country: 'ph' },
                        }}
                      >
                        <Input
                          className="h-11"
                          value={form.barangay}
                          onChange={(e) => {
                            const v = e.target.value
                            setAddressTouched(true)
                            setForm((prev) => {
                              const next = { ...prev, barangay: v }
                              const composed = composeHomeAddress(next)
                              return { ...next, home_address: composed || next.home_address }
                            })
                          }}
                          placeholder="Start typing to search address..."
                        />
                      </Autocomplete>
                    ) : (
                      <Input
                        className="h-11"
                        value={form.barangay}
                        onChange={(e) => {
                          const v = e.target.value
                          setAddressTouched(true)
                          setForm((prev) => {
                            const next = { ...prev, barangay: v }
                            const composed = composeHomeAddress(next)
                            return { ...next, home_address: composed || next.home_address }
                          })
                        }}
                        placeholder="Barangay"
                      />
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" />
                      City
                    </Label>
                    {isMapsLoaded ? (
                      <Autocomplete
                        onLoad={(ac) => {
                          cityAutocompleteRef.current = ac
                          try {
                            ac.setFields(['address_components', 'formatted_address', 'name'])
                            ac.setTypes(['address'])
                          } catch {
                            // ignore
                          }
                        }}
                        onPlaceChanged={makeProfilePlaceChangedHandler(cityAutocompleteRef)}
                        options={{
                          componentRestrictions: { country: 'ph' },
                        }}
                      >
                        <Input
                          className="h-11"
                          value={form.city}
                          onChange={(e) => {
                            const v = e.target.value
                            setAddressTouched(true)
                            setForm((prev) => {
                              const next = { ...prev, city: v }
                              const composed = composeHomeAddress(next)
                              return { ...next, home_address: composed || next.home_address }
                            })
                          }}
                          placeholder="Start typing to search address..."
                        />
                      </Autocomplete>
                    ) : (
                      <Input
                        className="h-11"
                        value={form.city}
                        onChange={(e) => {
                          const v = e.target.value
                          setAddressTouched(true)
                          setForm((prev) => {
                            const next = { ...prev, city: v }
                            const composed = composeHomeAddress(next)
                            return { ...next, home_address: composed || next.home_address }
                          })
                        }}
                        placeholder="City / Municipality"
                      />
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" />
                      Province
                    </Label>
                    {isMapsLoaded ? (
                      <Autocomplete
                        onLoad={(ac) => {
                          provinceAutocompleteRef.current = ac
                          try {
                            ac.setFields(['address_components', 'formatted_address', 'name'])
                            ac.setTypes(['address'])
                          } catch {
                            // ignore
                          }
                        }}
                        onPlaceChanged={makeProfilePlaceChangedHandler(provinceAutocompleteRef)}
                        options={{
                          componentRestrictions: { country: 'ph' },
                        }}
                      >
                        <Input
                          className="h-11"
                          value={form.province}
                          onChange={(e) => {
                            const v = e.target.value
                            setAddressTouched(true)
                            setForm((prev) => {
                              const next = { ...prev, province: v }
                              const composed = composeHomeAddress(next)
                              return { ...next, home_address: composed || next.home_address }
                            })
                          }}
                          placeholder="Start typing to search address..."
                        />
                      </Autocomplete>
                    ) : (
                      <Input
                        className="h-11"
                        value={form.province}
                        onChange={(e) => {
                          const v = e.target.value
                          setAddressTouched(true)
                          setForm((prev) => {
                            const next = { ...prev, province: v }
                            const composed = composeHomeAddress(next)
                            return { ...next, home_address: composed || next.home_address }
                          })
                        }}
                        placeholder="Province"
                      />
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="size-3.5 shrink-0" />
                      Postal Code
                    </Label>
                    <Input
                      value={form.postal_code}
                      onChange={(e) => {
                        const v = String(e.target.value || '')
                          .replace(/[^\d]/g, '')
                          .slice(0, 4)
                        setAddressTouched(true)
                        setForm((prev) => {
                          const next = { ...prev, postal_code: v }
                          const composed = composeHomeAddress(next)
                          return { ...next, home_address: composed || next.home_address }
                        })
                      }}
                      placeholder="e.g. 1200"
                      inputMode="numeric"
                      className="h-11"
                    />
                  </div>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>

            <div className="space-y-6">
              <ESignatureCard
                title="Electronic Signature"
                status={employee?.signature_image ? 'completed' : 'none'}
                signatureImage={employee?.signature_image || ''}
                busy={signatureBusy}
                onManage={canEditEmployeeRecord ? handleManageSignature : undefined}
                onRefresh={null}
              />
              <SignaturePadDialog
                open={signatureDialogOpen}
                onOpenChange={setSignatureDialogOpen}
                initialImage={employee?.signature_image || ''}
                busy={signatureBusy}
                onSave={handleSaveSignature}
                onRemove={employee?.signature_image && canEditEmployeeRecord ? handleClearSignature : null}
              />

              <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                <CardHeader className="pb-2">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Profile completion</CardTitle>
                    <span className={['text-sm font-bold tabular-nums', completionState.percent === 100 ? 'text-teal-600 dark:text-teal-400' : 'text-foreground'].join(' ')}>
                      {completionState.percent}%
                    </span>
                  </div>
                  <p className="text-xs font-normal text-muted-foreground">Complete sections to unlock smoother payroll and compliance reviews.</p>
                </CardHeader>
                <CardContent className="space-y-3 pt-0">
                  <div className="h-2.5 overflow-hidden rounded-full bg-muted/80 dark:bg-white/10">
                    <div
                      className={[
                        'h-2.5 rounded-full bg-gradient-to-r transition-all duration-700',
                        completionState.percent === 100
                          ? 'from-teal-500 to-teal-400'
                          : completionState.percent >= 60
                            ? 'from-teal-500 via-amber-400 to-amber-500'
                            : 'from-teal-500 via-amber-300 to-orange-400',
                      ].join(' ')}
                      style={{ width: `${completionState.percent}%` }}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">{completionState.doneFields}/{completionState.totalFields} checks completed</p>
                  <ul className="space-y-1.5">
                    {completionState.sections.map((section) => (
                      <li key={section.label} className="flex items-center justify-between gap-2 text-xs">
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                          {section.complete
                            ? <CheckCircle2 className="size-3 text-teal-500" />
                            : <span className="size-3 rounded-full border border-amber-400/60 bg-amber-400/10" />
                          }
                          {section.label}
                        </span>
                        <span className={['font-medium tabular-nums', section.complete ? 'text-teal-600 dark:text-teal-400' : 'text-muted-foreground'].join(' ')}>
                          {section.done}/{section.total}
                        </span>
                      </li>
                    ))}
                  </ul>
                  {completionState.missing.length > 0 && (
                    <p className="text-[11px] text-muted-foreground/70">
                      Missing: {completionState.missing.slice(0, 3).join(', ')}{completionState.missing.length > 3 ? ` +${completionState.missing.length - 3} more` : ''}
                    </p>
                  )}
                </CardContent>
              </Card>

            </div>
          </div>
          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'employment' && (
        <TabsContent value="employment">
          <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
            <CardHeader className="pb-4">
              <CardTitle className="flex items-center gap-2 text-2xl">
                <BriefcaseBusiness className="size-6 text-foreground" />
                Employment Details
              </CardTitle>
            </CardHeader>
            <CardContent className="px-6 pb-8 pt-0">
              {liveLeaveCreditsBlock ? (
                <LeaveCreditsCard
                  data={liveLeaveCreditsBlock}
                  variant="hr"
                  showAdjustButton={canEditEmployeeRecord}
                  onAdjustCredits={() => setLeaveAdjustOpen(true)}
                  className="mb-8 w-full max-w-full"
                />
              ) : null}
              {leaveCreditsLoading && !liveLeaveCreditsBlock ? (
                <div className="mb-8 w-full animate-pulse rounded-xl border border-border/60 bg-muted/30 p-4">
                  <div className="mb-3 h-4 w-44 rounded bg-muted-foreground/20" />
                  <div className="h-10 w-28 rounded bg-muted-foreground/20" />
                </div>
              ) : null}
              <div className="mb-8 rounded-xl border border-border/60 bg-muted/10 p-5 dark:border-white/10">
                <div className="mb-4 flex items-center justify-between gap-3">
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">Organization assignments</h3>
                    <p className="text-sm text-muted-foreground">Primary, shared, temporary, and acting placements.</p>
                  </div>
                </div>
                {organizationAssignmentsLoading ? (
                  <p className="text-sm text-muted-foreground">Loading organization assignments…</p>
                ) : organizationAssignments.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No organization assignments recorded yet.</p>
                ) : (
                  <ul className="space-y-3">
                    {organizationAssignments.map((row) => (
                      <li
                        key={row.id}
                        className="rounded-lg border border-border/60 bg-background px-4 py-3 dark:border-white/10 dark:bg-[#151922]"
                      >
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant={row.is_primary ? 'default' : 'secondary'} className="capitalize">
                            {row.assignment_type || (row.is_primary ? 'primary' : 'shared')}
                          </Badge>
                          <Badge variant="outline">{row.is_active ? 'Active' : 'Inactive'}</Badge>
                        </div>
                        <p className="mt-2 text-sm font-medium text-foreground">{row.org_path || row.organization_unit_name || 'Organization unit'}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {row.effective_from ? `From ${row.effective_from}` : 'Open start'}
                          {row.effective_to ? ` · Until ${row.effective_to}` : ''}
                          {row.remarks ? ` · ${row.remarks}` : ''}
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="grid gap-6 @sm:grid-cols-2 @lg:grid-cols-3">
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Building2 className="size-4 shrink-0" />
                    Company
                  </Label>
                  <Select
                    value={form.company_id || 'none'}
                    onValueChange={(v) => setForm((f) => ({ ...f, company_id: v === 'none' ? '' : v, branch_id: '', department_id: '', section_unit_id: '' }))}
                  >
                    <SelectTrigger className="h-11 w-full text-base"><SelectValue placeholder="Select company" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {companies.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {employee?.management_role === 'company_head' ? (
                  <>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <MapPin className="size-4 shrink-0" />
                        Branch
                      </Label>
                      <Input className="h-11 text-base" value="All Branches" readOnly />
                    </div>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <Building2 className="size-4 shrink-0" />
                        Department
                      </Label>
                      <Input className="h-11 text-base" value="Company-wide" readOnly />
                    </div>
                  </>
                ) : (
                  <>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <MapPin className="size-4 shrink-0" />
                        Branch
                      </Label>
                      <Select
                        value={form.branch_id || 'none'}
                        onValueChange={(v) => {
                          const branchId = v === 'none' ? '' : v
                          const branch = branches.find((b) => String(b.id) === v)
                          setForm((f) => ({
                            ...f,
                            branch_id: branchId,
                            department_id: '',
                            section_unit_id: '',
                            branch_office_location: branch ? (branch.name || branch.address || '') : f.branch_office_location,
                          }))
                        }}
                      >
                        <SelectTrigger className="h-11 w-full text-base"><SelectValue placeholder="Select branch" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">None</SelectItem>
                          {(form.company_id
                            ? branches.filter((b) => b.company_id != null && String(b.company_id) === String(form.company_id))
                            : branches
                          ).map((b) => (
                            <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <Building2 className="size-4 shrink-0" />
                        Department
                      </Label>
                      <Select
                        value={form.department_id || 'none'}
                        onValueChange={(v) => setForm((f) => ({ ...f, department_id: v === 'none' ? '' : v, section_unit_id: '' }))}
                      >
                        <SelectTrigger className="h-11 w-full text-base"><SelectValue placeholder="Select department" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">None</SelectItem>
                          {(form.branch_id
                            ? departments.filter((d) => d.branch_id != null && String(d.branch_id) === String(form.branch_id))
                            : departments
                          ).map((d) => (
                            <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <Building2 className="size-4 shrink-0" />
                        Section / Unit
                      </Label>
                      <Select
                        value={form.section_unit_id || 'none'}
                        onValueChange={(v) => setForm((f) => ({ ...f, section_unit_id: v === 'none' ? '' : v }))}
                      >
                        <SelectTrigger className="h-11 w-full text-base"><SelectValue placeholder="Select section/unit" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">None</SelectItem>
                          {(form.department_id
                            ? sectionsOrUnits.filter((s) => {
                                if (String(s.department_id ?? '') !== String(form.department_id)) return false
                                if (form.company_id && String(s.company_id ?? '') !== String(form.company_id)) return false
                                if (form.branch_id && String(s.branch_id ?? '') !== String(form.branch_id)) return false
                                return true
                              })
                            : []
                          ).map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </>
                )}
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <BriefcaseBusiness className="size-4 shrink-0" />
                    Job Title
                  </Label>
                  <Input className="h-11 text-base" value={form.position} onChange={(e) => setForm((f) => ({ ...f, position: e.target.value }))} placeholder="Position / role" />
                </div>
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <FileText className="size-4 shrink-0" />
                    Employment status
                  </Label>
                  <Select
                    value={form.employment_status || 'probationary'}
                    onValueChange={(v) => setForm((f) => ({ ...f, employment_status: v }))}
                  >
                    <SelectTrigger className="h-11 w-full text-base">
                      <SelectValue placeholder="Select status" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="probationary">Probationary</SelectItem>
                      <SelectItem value="regular">Regular</SelectItem>
                      <SelectItem value="contractual">Contractual</SelectItem>
                      <SelectItem value="project_based">Project-based</SelectItem>
                      <SelectItem value="consultant">Consultant</SelectItem>
                      <SelectItem value="separated">Separated</SelectItem>
                    </SelectContent>
                  </Select>
                  {form.employment_status === 'consultant' ? (
                    <div className="flex flex-wrap gap-2 pt-1">
                      <Badge variant="outline" className="border-amber-300 bg-amber-50 text-amber-700">Consultant</Badge>
                      <Badge variant="outline" className="border-emerald-300 bg-emerald-50 text-emerald-700">Fixed Salary Payroll</Badge>
                    </div>
                  ) : null}
                </div>
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Calendar className="size-4 shrink-0" />
                    Status effective date
                  </Label>
                  <Input
                    className="h-11 text-base"
                    type="date"
                    value={form.employment_status_effective_date || ''}
                    onChange={(e) => setForm((f) => ({ ...f, employment_status_effective_date: e.target.value }))}
                  />
                  <div className="space-y-2">
                    {employmentDateValidation.statusMessages.map((message, idx) => (
                      <InlineValidationMessage key={`status-effective-msg-${idx}`} tone={message.tone}>
                        {message.text}
                      </InlineValidationMessage>
                    ))}
                  </div>
                </div>
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Clock3 className="size-4 shrink-0" />
                    Work arrangement
                  </Label>
                  <Select
                    value={form.employment_type || 'none'}
                    onValueChange={(v) => setForm((f) => ({ ...f, employment_type: v === 'none' ? '' : v }))}
                  >
                    <SelectTrigger className="h-11 w-full text-base"><SelectValue placeholder="Select arrangement" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">Not set</SelectItem>
                      <SelectItem value="full_time">Full-time</SelectItem>
                      <SelectItem value="part_time">Part-time</SelectItem>
                      <SelectItem value="contract">Contract (hours)</SelectItem>
                      <SelectItem value="probationary">Probationary</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Calendar className="size-4 shrink-0" />
                    Hire date
                  </Label>
                  <Input className="h-11 text-base" type="date" value={form.hire_date || ''} onChange={(e) => setForm((f) => ({ ...f, hire_date: e.target.value }))} />
                  <div className="space-y-2">
                    {employmentDateValidation.hireMessages.map((message, idx) => (
                      <InlineValidationMessage key={`hire-date-msg-${idx}`} tone={message.tone}>
                        {message.text}
                      </InlineValidationMessage>
                    ))}
                  </div>
                </div>
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Calendar className="size-4 shrink-0" />
                    Payroll Effective Date
                  </Label>
                  <Input className="h-11 text-base" type="date" value={form.payroll_effective_date || ''} onChange={(e) => setForm((f) => ({ ...f, payroll_effective_date: e.target.value }))} />
                  <p className="text-sm text-muted-foreground">Defaults to the employee created date if blank.</p>
                </div>
                {form.employment_status === 'contractual' ? (
                  <>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <Calendar className="size-4 shrink-0" />
                        Contract start
                      </Label>
                      <Input
                        className="h-11 text-base"
                        type="date"
                        value={form.contract_start_date || ''}
                        onChange={(e) => setForm((f) => ({ ...f, contract_start_date: e.target.value }))}
                      />
                    </div>
                    <div className="space-y-3">
                      <Label className="flex items-center gap-2 text-base text-muted-foreground">
                        <Calendar className="size-4 shrink-0" />
                        Contract end
                      </Label>
                      <Input
                        className="h-11 text-base"
                        type="date"
                        value={form.contract_end_date || ''}
                        onChange={(e) => setForm((f) => ({ ...f, contract_end_date: e.target.value }))}
                      />
                    </div>
                  </>
                ) : null}
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-base text-muted-foreground">
                    <Clock3 className="size-4 shrink-0" />
                    Work schedule
                  </Label>
                  <Select
                    value={form.working_schedule_id || 'none'}
                    onValueChange={(v) =>
                      setForm((f) => ({
                        ...f,
                        working_schedule_id: v === 'none' ? '' : v,
                      }))
                    }
                  >
                    <SelectTrigger className="h-11 w-full text-base">
                      <SelectValue placeholder="Select work schedule" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {workingSchedules.map((s) => (
                        <SelectItem key={s.id} value={String(s.id)}>
                          {s.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {employee?.pending_schedule_effective_from && employee?.pending_working_schedule_name ? (
                    <p className="text-xs leading-relaxed text-muted-foreground">
                      Approved change pending: becomes{' '}
                      <span className="font-medium text-foreground">{employee.pending_working_schedule_name}</span>
                      {employee.pending_working_schedule_time ? ` (${formatScheduleLabel12h(employee.pending_working_schedule_time)})` : ''} on{' '}
                      {new Date(`${employee.pending_schedule_effective_from}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' })}.
                    </p>
                  ) : null}
                </div>
                {completedRegularizationEntry ? (
                  <div className="space-y-3 @sm:col-span-2 @lg:col-span-3 rounded-xl border border-emerald-200/70 bg-emerald-50/60 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/25">
                    <p className="text-sm font-medium text-foreground">Regularization completed</p>
                    <p className="text-sm text-foreground/90">
                      Regularization completed on {formatDate(completedRegularizationEntry.effective_date)}. Employee is now Regular.
                    </p>
                    <p className="text-xs text-muted-foreground">
                      Historical probation milestones are hidden because a probation-to-regular transition has already been approved.
                    </p>
                  </div>
                ) : probationMilestones ? (
                  <div className="space-y-3 @sm:col-span-2 @lg:col-span-3 rounded-xl border border-border/60 bg-muted/20 p-4 dark:bg-white/5">
                    <p className="text-sm font-medium text-foreground">Probation milestones (from hire date)</p>
                    <p className="text-xs text-muted-foreground">
                      5-month mark: system alert for the Regularization queue. 6-month mark: HR decides Regular vs extended probation — no automatic change.
                    </p>
                    <div className="grid gap-3 @sm:grid-cols-3">
                      <div className="rounded-lg border border-border/50 bg-background/80 px-3 py-2">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">3-month (early rec.)</p>
                        <p className="font-mono text-sm tabular-nums">{probationMilestones.three}</p>
                      </div>
                      <div className="rounded-lg border border-amber-200/80 bg-amber-50/50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">5-month alert</p>
                        <p className="font-mono text-sm tabular-nums">{probationMilestones.five}</p>
                      </div>
                      <div className="rounded-lg border border-primary/25 bg-primary/5 px-3 py-2">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">6-month decision</p>
                        <p className="font-mono text-sm tabular-nums">{probationMilestones.six}</p>
                      </div>
                    </div>
                  </div>
                ) : null}
              </div>
              {employee?.id ? (
                <div className="mt-8">
                  <ApprovalRoutePreviewSection employeeId={employee.id} />
                </div>
              ) : null}
            </CardContent>
          </Card>
        </TabsContent>
        )}

        {activeTab === 'salary' && (
        <TabsContent value="salary">
          <div className="rounded-2xl border border-slate-200/90 bg-slate-50/60 p-4 shadow-sm sm:p-6 dark:border-white/10 dark:bg-slate-950/40">
            {deferredSectionLoading.salary && (
              <div className="mb-4 inline-flex items-center gap-2 rounded-md border border-border/60 bg-background/70 px-3 py-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading salary preview...
              </div>
            )}
            <SalaryTabShell>
              {!canEditSalaryDetails ? (
                <div
                  role="status"
                  className="mb-4 rounded-xl border border-amber-200/90 bg-amber-50/90 px-4 py-3 text-sm text-amber-950 shadow-sm dark:border-amber-900/50 dark:bg-amber-950/35 dark:text-amber-100"
                >
                  Salary details can only be edited by HR/Admin with the &quot;Edit salary details&quot; permission. Please contact HR or a Super Admin to update basic
                  pay and effectivity.
                </div>
              ) : null}
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1">
                  <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Salary</p>
                  <h2 className="text-2xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-100">Employment &amp; compensation</h2>
                  <p className="max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                    Basic pay and schedule-derived rates. Pay cycle follows the company default (manage under Compensation → Pay cycles).
                  </p>
                </div>
                <Badge variant="outline" className="w-fit gap-1.5 border-slate-200 bg-white font-normal text-[#0A0A0A] shadow-sm dark:border-white/10 dark:bg-[#111318] dark:text-slate-100">
                  <Shield className="size-3.5 text-[#0A0A0A] dark:text-slate-100" aria-hidden />
                  {canEditSalaryDetails ? 'Admin only' : 'View only'}
                </Badge>
              </div>

              <SalaryCompensationStructureCard
                compensationSummary={compensationSummary}
                payCyclePreview={adminEffectivePayCyclePreview}
                displayUser={employee}
                basicMonthlyDisplay={formatSalaryPhp(form.monthly_salary)}
                lastUpdatedLabel={formatDate(employee?.updated_at)}
                viewOnly={!canEditSalaryDetails}
                scheduleHint={
                  scheduleRateMeta.workingDaysPerMonth > 0 || scheduleRateMeta.workingHoursPerDay > 0
                    ? `${scheduleRateMeta.scheduleName || employee?.working_schedule_name || 'Work schedule'} · ${scheduleRateMeta.workingDaysPerMonth} working days/mo · ${scheduleRateMeta.workingHoursPerDay} hrs/day`
                    : scheduleRateMeta.scheduleName || employee?.working_schedule_name || null
                }
                adminToolbar={
                  <>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="border-slate-200 text-[#0A0A0A] hover:bg-slate-100 dark:border-white/15 dark:text-slate-100 dark:hover:bg-white/10"
                      onClick={() => navigate(hrPanelPath(hrBase, 'compensation/pay-cycles'))}
                    >
                      + New payroll cycle
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={() => navigate(hrPanelPath(hrBase, 'reports'))}>
                      Export 201 file
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      className="bg-[#0A0A0A] text-white hover:bg-black dark:bg-slate-100 dark:text-[#0A0A0A] dark:hover:bg-white"
                      onClick={() => navigate(hrPanelPath(hrBase, 'daily-computation'))}
                    >
                      Daily computation
                    </Button>
                  </>
                }
                salaryEditSlot={
                  canEditSalaryDetails ? (
                    <div className="flex rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#151922]">
                      <span className="flex items-center border-r border-slate-200 px-3 text-sm text-slate-500 dark:border-white/10">₱</span>
                      <Input
                        id="admin-salary-monthly"
                        inputMode="decimal"
                        placeholder="0.00"
                        className="border-0 shadow-none focus-visible:ring-0"
                        value={form.monthly_salary}
                        onChange={(e) => {
                          const v = e.target.value
                          const derived = deriveDerivedSalaryFieldsFromBasic(v, scheduleRateMeta)
                          setForm((f) => ({
                            ...f,
                            monthly_salary: v,
                            ...derived,
                          }))
                        }}
                        autoComplete="off"
                      />
                    </div>
                  ) : null
                }
              />

              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div className="space-y-2">
                  <Label htmlFor="admin-salary-hourly">Hourly rate</Label>
                  <div className="flex rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
                    <span className="flex items-center border-r border-slate-200 px-3 text-sm text-muted-foreground dark:border-white/10">₱</span>
                    <Input
                      id="admin-salary-hourly"
                      inputMode="decimal"
                      placeholder="0.00"
                      className="border-0 bg-slate-50/80 shadow-none focus-visible:ring-0 tabular-nums dark:bg-transparent"
                      value={liveDerivedSalary.hourly_rate !== '' ? liveDerivedSalary.hourly_rate : form.hourly_rate}
                      readOnly
                      aria-readonly="true"
                      autoComplete="off"
                    />
                  </div>
                  <FieldHint>From schedule.</FieldHint>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="admin-salary-daily">Daily rate</Label>
                  <div className="flex rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
                    <span className="flex items-center border-r border-slate-200 px-3 text-sm text-muted-foreground dark:border-white/10">₱</span>
                    <Input
                      id="admin-salary-daily"
                      inputMode="decimal"
                      placeholder="0.00"
                      className="border-0 bg-slate-50/80 shadow-none focus-visible:ring-0 tabular-nums dark:bg-transparent"
                      value={liveDerivedSalary.daily_rate !== '' ? liveDerivedSalary.daily_rate : form.daily_rate}
                      readOnly
                      aria-readonly="true"
                      autoComplete="off"
                    />
                  </div>
                  <FieldHint>From schedule.</FieldHint>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="admin-salary-monthly-rate">Monthly rate</Label>
                  <div className="flex rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111318]">
                    <span className="flex items-center border-r border-slate-200 px-3 text-sm text-muted-foreground dark:border-white/10">₱</span>
                    <Input
                      id="admin-salary-monthly-rate"
                      inputMode="decimal"
                      placeholder="0.00"
                      className="border-0 bg-slate-50/80 shadow-none focus-visible:ring-0 tabular-nums dark:bg-transparent"
                      value={liveDerivedSalary.monthly_rate !== '' ? liveDerivedSalary.monthly_rate : form.monthly_rate}
                      readOnly
                      aria-readonly="true"
                      autoComplete="off"
                    />
                  </div>
                  <FieldHint>Aligned with basic for payroll.</FieldHint>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <AdminCompSummaryCard label="Gross pay (live preview)" value={grossPayPreview} />
                <div className="flex items-end justify-end rounded-2xl border border-slate-100 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-[#111318]">
                  <Button
                    type="button"
                    className="bg-[#0A0A0A] text-white shadow-sm hover:bg-[#171717] focus-visible:ring-2 focus-visible:ring-[#0A0A0A]/35 focus-visible:ring-offset-2 dark:bg-[#0A0A0A] dark:text-white dark:hover:bg-neutral-800"
                    onClick={handleSave}
                    disabled={saving || !canEditProfile}
                  >
                    {saving && saveStatus !== 'saved' ? 'Saving…' : saveStatus === 'saved' ? 'Saved' : 'Save Changes'}
                  </Button>
                </div>
              </div>

              <SalaryPayrollHistoryCard
                periods={payrollPeriods}
                loading={payrollPeriodsLoading}
                error={payrollPeriodsError}
                canView={canViewPayrollHistory}
                onViewAll={canViewPayrollHistory ? () => navigate(hrPanelPath(hrBase, 'daily-computation')) : undefined}
              />

              <SalaryPayComponentsBreakdownCard
                earnings={compensationSummary?.earnings || []}
                deductions={compensationSummary?.deductions || []}
                statutory={compensationSummary?.statutory}
                withholding={compensationSummary?.withholding}
                compensationSummary={compensationSummary}
                onUpdateGovIds={() => setActiveTab('government-ids')}
              />

              <SalaryGovernmentDeductionExemptionsCard compensationSummary={compensationSummary} />

              <SalaryAutomatedDeductionsCard
                compensationSummary={compensationSummary}
                employeeUserId={employee?.id != null ? Number(employee.id) : undefined}
                enableEarlyPayoff
              />

              <SalaryTaxInfoCard
                compensationSummary={compensationSummary}
                tinResolution={salaryTinResolution}
                showUpdateButton={canEditProfile}
                onUpdateTaxInfo={() => setActiveTab('government-ids')}
              />

              <SalaryTabNotice>
                Totals and deductions on payslips may still differ from these reference figures. Use posted payroll and official registers for final amounts.
              </SalaryTabNotice>
            </SalaryTabShell>
          </div>
        </TabsContent>
        )}

        {activeTab === 'benefits' && (
        <TabsContent value="benefits">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }}>
            <div className="grid gap-4 @lg:grid-cols-3">
              <div className="space-y-4 @lg:col-span-2">
                <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                  <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                    <CardTitle className="text-base">Health & Financial Wellness</CardTitle>
                    <Button type="button" variant="outline" size="sm" className="shrink-0" onClick={toggleBenefitsEditMode}>
                      {benefitsEditMode ? 'Done' : 'Edit Coverage'}
                    </Button>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    {benefitsLoading && <p className="text-sm text-muted-foreground">Loading benefits...</p>}
                    {!benefitsLoading && benefitsError && <p className="text-sm text-destructive">{benefitsError}</p>}
                    {!benefitsLoading && !benefitsError && benefitsData.coverages.length === 0 && (
                      <p className="text-sm text-muted-foreground">No health or financial coverage assigned yet.</p>
                    )}
                    {benefitsData.coverages.map((coverage, index) => (
                      <div key={coverage.id} className="rounded-lg border border-border/60 bg-muted/20 p-4">
                        <div className="mb-3 flex items-start justify-between gap-3">
                          <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{coverage.label}</p>
                            <p className="text-lg font-semibold">{coverage.plan}</p>
                          </div>
                          <Badge variant="outline">{coverage.status}</Badge>
                        </div>
                        {benefitsEditMode ? (
                          <div className="grid gap-3 @sm:grid-cols-2">
                            <div className="space-y-1">
                              <Label>Provider</Label>
                              <Input value={coverage.provider} onChange={(e) => updateCoverageField(index, 'provider', e.target.value)} />
                              {benefitsValidation.coverage[index]?.provider && (
                                <p className="text-xs text-destructive">{benefitsValidation.coverage[index].provider}</p>
                              )}
                            </div>
                            <div className="space-y-1">
                              <Label>Coverage Type</Label>
                              <Input value={coverage.coverage_type} onChange={(e) => updateCoverageField(index, 'coverage_type', e.target.value)} />
                              {benefitsValidation.coverage[index]?.coverage_type && (
                                <p className="text-xs text-destructive">{benefitsValidation.coverage[index].coverage_type}</p>
                              )}
                            </div>
                            <div className="space-y-1">
                              <Label>Effective Date</Label>
                              <Input type="date" value={coverage.effective_date || ''} onChange={(e) => updateCoverageField(index, 'effective_date', e.target.value)} />
                              {benefitsValidation.coverage[index]?.effective_date && (
                                <p className="text-xs text-destructive">{benefitsValidation.coverage[index].effective_date}</p>
                              )}
                            </div>
                            <div className="space-y-1">
                              <Label>Monthly Premium / Value</Label>
                              <Input value={coverage.monthly_premium} onChange={(e) => updateCoverageField(index, 'monthly_premium', e.target.value)} />
                              {benefitsValidation.coverage[index]?.monthly_premium && (
                                <p className="text-xs text-destructive">{benefitsValidation.coverage[index].monthly_premium}</p>
                              )}
                            </div>
                          </div>
                        ) : (
                          <div className="grid gap-3 text-sm @sm:grid-cols-2">
                            <div>
                              <p className="text-xs text-muted-foreground">Provider</p>
                              <p className="font-medium">{field(coverage.provider)}</p>
                            </div>
                            <div>
                              <p className="text-xs text-muted-foreground">Coverage Type</p>
                              <p className="font-medium">{field(coverage.coverage_type)}</p>
                            </div>
                            <div>
                              <p className="text-xs text-muted-foreground">Effective Date</p>
                              <p className="font-medium">{formatDate(coverage.effective_date)}</p>
                            </div>
                            <div>
                              <p className="text-xs text-muted-foreground">Monthly Premium</p>
                              <p className="font-medium">{field(coverage.monthly_premium)} {coverage.contribution_note ? `(${coverage.contribution_note})` : ''}</p>
                            </div>
                          </div>
                        )}
                      </div>
                    ))}
                  </CardContent>
                </Card>

                <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                  <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                    <CardTitle className="text-base">Leave & Time Off Benefits</CardTitle>
                    <Button type="button" variant="outline" size="sm" className="shrink-0">Adjust Balances</Button>
                  </CardHeader>
                  <CardContent className="grid gap-3 @md:grid-cols-3">
                    {!benefitsLoading && !benefitsError && benefitsData.leave_balances.length === 0 && (
                      <p className="text-sm text-muted-foreground @md:col-span-3">No leave benefit balances assigned yet.</p>
                    )}
                    {benefitsData.leave_balances.map((leave, index) => (
                      <div key={leave.id} className="rounded-lg border border-border/60 p-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{leave.label}</p>
                        <div className="mt-1 flex items-end gap-1">
                          {benefitsEditMode ? (
                            <Input
                              className="h-8"
                              value={leave.value}
                              onChange={(e) => updateLeaveBalanceField(index, 'value', e.target.value)}
                            />
                          ) : (
                            <p className="text-3xl font-semibold leading-none">{leave.value}</p>
                          )}
                          <p className="pb-0.5 text-sm text-muted-foreground">{leave.unit}</p>
                        </div>
                        {benefitsEditMode && benefitsValidation.leave[index]?.value && (
                          <p className="mt-2 text-xs text-destructive">{benefitsValidation.leave[index].value}</p>
                        )}
                        <div className="mt-3 h-1.5 rounded-full bg-muted">
                          <div className="h-1.5 rounded-full bg-foreground" style={{ width: `${Math.max(0, Math.min(100, Number(leave.accent) || 0))}%` }} />
                        </div>
                        <p className="mt-2 text-[11px] text-muted-foreground">{leave.note}</p>
                      </div>
                    ))}
                  </CardContent>
                </Card>
              </div>

              <div className="space-y-4">
                <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                  <CardHeader className="flex flex-row items-center justify-between space-y-0">
                    <CardTitle className="text-base">Monthly Allowances</CardTitle>
                    <Button type="button" variant="ghost" size="icon" className="size-8" onClick={handleAddAllowance}>
                      <Plus className="size-4" />
                    </Button>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {!benefitsLoading && !benefitsError && benefitsData.allowances.length === 0 && (
                      <p className="text-sm text-muted-foreground">No monthly allowances assigned yet.</p>
                    )}
                    {benefitsData.allowances.map((allowance, index) => (
                      <div key={allowance.id} className="flex items-center justify-between gap-2 rounded-md border border-border/60 px-3 py-2">
                        {benefitsEditMode ? (
                          <>
                            <div className="flex-1">
                              <Input
                                className="h-8"
                                value={allowance.label}
                                onChange={(e) => updateAllowanceField(index, 'label', e.target.value)}
                              />
                              {benefitsValidation.allowances[index]?.label && (
                                <p className="mt-1 text-xs text-destructive">{benefitsValidation.allowances[index].label}</p>
                              )}
                            </div>
                            <div className="w-28">
                              <Input
                                className="h-8 text-right"
                                value={allowance.amount}
                                onChange={(e) => updateAllowanceField(index, 'amount', e.target.value)}
                              />
                              {benefitsValidation.allowances[index]?.amount && (
                                <p className="mt-1 text-xs text-destructive">{benefitsValidation.allowances[index].amount}</p>
                              )}
                            </div>
                          </>
                        ) : (
                          <>
                            <p className="text-sm">{allowance.label}</p>
                            <p className="text-sm font-semibold">{formatMoney(allowance.amount)}</p>
                          </>
                        )}
                      </div>
                    ))}
                    <div className="flex items-center justify-between border-t border-border/60 pt-3 text-sm">
                      <span className="text-muted-foreground">Total Monthly</span>
                      <span className="font-semibold">{formatMoney(totalAllowance)}</span>
                    </div>
                  </CardContent>
                </Card>

                <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                  <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                    <CardTitle className="text-base">Company Perks</CardTitle>
                    <Button type="button" variant="outline" size="sm" className="shrink-0" onClick={handleAddPerk}>Assign New Perk</Button>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {!benefitsLoading && !benefitsError && benefitsData.perks.length === 0 && (
                      <p className="text-sm text-muted-foreground">No company perks assigned yet.</p>
                    )}
                    {benefitsData.perks.map((perk, index) =>
                      benefitsEditMode ? (
                        <div key={`${perk}-${index}`}>
                          <Input value={perk} onChange={(e) => updatePerk(index, e.target.value)} />
                          {benefitsValidation.perks[index]?.value && (
                            <p className="mt-1 text-xs text-destructive">{benefitsValidation.perks[index].value}</p>
                          )}
                        </div>
                      ) : (
                        <Badge key={`${perk}-${index}`} variant="secondary" className="mr-2">{perk}</Badge>
                      )
                    )}
                  </CardContent>
                </Card>

                <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                  <CardHeader>
                    <CardTitle className="text-base">Resources</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {!benefitsLoading && !benefitsError && benefitsData.resources.length === 0 && (
                      <p className="text-sm text-muted-foreground">No resources available.</p>
                    )}
                    {benefitsData.resources.map((resource) => (
                      <a
                        key={resource.id}
                        href={resource.href || '#'}
                        className="flex items-center justify-between rounded-md border border-border/60 px-3 py-2 text-sm hover:bg-muted/30"
                      >
                        <span>{resource.label}</span>
                        <ExternalLink className="size-3.5 text-muted-foreground" />
                      </a>
                    ))}
                  </CardContent>
                </Card>
              </div>
            </div>

          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'documents' && (
        <TabsContent value="documents">
          <Motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, ease: [0.25, 0.1, 0.25, 1] }}
            className="space-y-5"
          >
            <Motion.div
              initial={{ opacity: 0, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.25, delay: 0.05 }}
              className="space-y-1"
            >
              <h3 className="text-2xl font-semibold">Documents</h3>
              <p className="text-sm text-muted-foreground">Manage employee personnel files and review submitted documents. Use the uploader in each folder to add files.</p>
            </Motion.div>

            <Motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ duration: 0.3, delay: 0.1 }}
              className="grid gap-4 @lg:grid-cols-[minmax(220px,32%)_minmax(0,1fr)]"
            >
              {/* ── Folder sidebar ── */}
              <Motion.div
                initial={{ opacity: 0, x: -12 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, ease: [0.25, 0.1, 0.25, 1] }}
                className="rounded-xl border border-border/60 bg-muted/10 p-3 shadow-sm dark:border-white/8 dark:bg-[#0d1117]"
              >
                <div className="mb-3 flex items-center justify-between">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Personnel Folders</p>
                  <span className="rounded-full border border-border/50 bg-background px-2 py-0.5 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-[#111827]">
                    {docs.length} total
                  </span>
                </div>
                <div className="space-y-0.5">
                  {documentCategories.map((cat, idx) => {
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
                      <Motion.button
                        key={cat}
                        type="button"
                        initial={{ opacity: 0, x: -8 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ duration: 0.2, delay: 0.05 + idx * 0.03 }}
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
                        className={[
                          'group flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-left text-sm transition-all duration-150',
                          active
                            ? 'border-teal-500/40 bg-teal-50/80 shadow-[inset_3px_0_0_#14b8a6] dark:border-teal-600/30 dark:bg-teal-900/15 dark:shadow-[inset_3px_0_0_rgba(20,184,166,0.6)]'
                            : 'border-transparent hover:border-border/40 hover:bg-muted/50 dark:hover:bg-white/5',
                        ].join(' ')}
                      >
                        <span className="flex items-center gap-2.5">
                          <span className={['inline-flex size-7 shrink-0 items-center justify-center rounded-md border', meta.bg, meta.border].join(' ')}>
                            <CatIcon className={['size-3.5', meta.cls].join(' ')} />
                          </span>
                          <span className={['text-sm', active ? 'font-semibold text-teal-700 dark:text-teal-300' : 'text-foreground'].join(' ')}>{cat}</span>
                        </span>
                        <span
                          className={[
                            'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                            count > 0 ? meta.badge : 'border border-border/50 bg-muted/60 text-muted-foreground dark:border-white/10 dark:bg-white/[0.06]',
                          ].join(' ')}
                        >
                          {count}
                        </span>
                      </Motion.button>
                    )
                  })}
                </div>
              </Motion.div>

              {/* ── Right panel: folder header + compact upload + table or onboarding ── */}
              <Motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, delay: 0.15 }}
                className="flex min-w-0 flex-col gap-4"
              >
                <div className="flex flex-col gap-3 @md:flex-row @md:items-start @md:justify-between">
                  <div className="min-w-0 space-y-0.5">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Current folder</p>
                    <p className="text-base font-semibold text-foreground">{docsCategory}</p>
                    <p className="text-xs text-muted-foreground">
                      {showDocumentsEmptyOnboarding
                        ? 'Add a file for this employee below or use the quick uploader.'
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
                    body={docFolderGuidanceAdmin.body}
                    primaryCta={docFolderGuidanceAdmin.primaryCta}
                    onPrimaryUpload={() => openUploadDocModal(null)}
                    onBrowse={() => openUploadDocModal(null)}
                    checklist={getFolderChecklistTeaser(documentCategories, docsByCategory, docsCategory)}
                    uploading={docsUploading}
                  />
                ) : (
                <Motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ duration: 0.25, delay: 0.2 }}
                  className="overflow-hidden rounded-xl border border-border/60 shadow-sm dark:border-white/8"
                >
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
                        <th className="w-[200px] whitespace-nowrap text-right">Actions</th>
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
                        filteredDocs.map((doc, index) => (
                          <Motion.tr
                            key={doc.id}
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.25, delay: index * 0.04, ease: [0.25, 0.1, 0.25, 1] }}
                            className="group transition-colors duration-100 hover:bg-slate-50 dark:hover:bg-[#111827] [&>td]:px-4 [&>td]:py-4"
                          >
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
                                    <div className={['mt-0.5 inline-flex size-9 items-center justify-center rounded-md border font-semibold text-[11px]', logo.bg, logo.border, logo.cls].join(' ')}>
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
                                className={[
                                  'inline-flex items-center gap-1.5 font-medium',
                                  String(doc.status || '').toLowerCase() === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                  : String(doc.status || '').toLowerCase() === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                    : String(doc.status || '').toLowerCase() === 'archived' ? 'border-slate-200 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                      : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
                                ].join(' ')}
                              >
                                {String(doc.status || '').toLowerCase() === 'active' ? <CheckCircle2 className="size-3.5" /> : String(doc.status || '').toLowerCase() === 'rejected' ? <X className="size-3.5" /> : String(doc.status || '').toLowerCase() === 'archived' ? <Archive className="size-3.5" /> : <Clock4 className="size-3.5" />}
                                {String(doc.status || '').toLowerCase() === 'active' ? 'Active' : String(doc.status || '').toLowerCase() === 'rejected' ? 'Rejected' : String(doc.status || '').toLowerCase() === 'archived' ? 'Archived' : 'Pending'}
                              </Badge>
                            </td>
                            <td className="w-[200px] whitespace-nowrap text-right">
                              <div className="inline-flex items-center justify-end gap-1">
                                <Button type="button" variant="ghost" size="icon" title="View" className="cursor-pointer hover:bg-muted/60" onClick={() => openPreviewDocModal(doc)} aria-label="View">
                                  <Eye className="size-4" />
                                </Button>
                                <Button type="button" variant="ghost" size="icon" title="Download" className="cursor-pointer hover:bg-muted/60" onClick={() => window.open(doc?.file?.url, '_blank', 'noopener,noreferrer')} aria-label="Download">
                                  <FileDown className="size-4" />
                                </Button>
                                <Button type="button" variant="ghost" size="icon" title="Edit" className="cursor-pointer hover:bg-muted/60" onClick={() => openEditDocModal(doc)} aria-label="Edit">
                                  <Pencil className="size-4" />
                                </Button>
                                {String(doc.status || '').toLowerCase() === 'pending' ? (
                                  <>
                                    <Button type="button" variant="ghost" size="icon" title="Approve" className="cursor-pointer hover:bg-muted/60" onClick={() => openReviewDocModal(doc, 'active')} aria-label="Approve">
                                      <CheckCircle2 className="size-4" />
                                    </Button>
                                    <Button type="button" variant="ghost" size="icon" title="Reject" className="cursor-pointer hover:bg-muted/60" onClick={() => openReviewDocModal(doc, 'rejected')} aria-label="Reject">
                                      <X className="size-4" />
                                    </Button>
                                  </>
                                ) : (
                                  <Button type="button" variant="ghost" size="icon" title="Change status" className="cursor-pointer hover:bg-muted/60" onClick={() => openReviewDocModal(doc, String(doc.status || '').toLowerCase() === 'archived' ? 'archived' : 'active')} aria-label="Change status">
                                    <BadgeCheck className="size-4" />
                                  </Button>
                                )}
                                <Button type="button" variant="ghost" size="icon" title="Delete" className="cursor-pointer hover:bg-muted/60" onClick={() => requestDeleteDoc(doc)} aria-label="Delete" disabled={docsSaving}>
                                  <Trash2 className="size-4" />
                                </Button>
                              </div>
                            </td>
                          </Motion.tr>
                        ))
                      )}
                    </tbody>
                  </table>
                  </div>
                </Motion.div>
                )}
              </Motion.div>
            </Motion.div>
          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'government-ids' && (
        <TabsContent value="government-ids">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }} className="space-y-5">
            {deferredSectionLoading.government && (
              <div className="inline-flex items-center gap-2 rounded-md border border-border/60 bg-background/70 px-3 py-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading government IDs...
              </div>
            )}
            <div className="rounded-xl border border-border/40 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
              <div className="flex flex-col gap-3 border-b border-border/30 px-5 py-4 @sm:flex-row @sm:items-start @sm:justify-between">
                <div className="space-y-1">
                  <h3 className="text-lg font-semibold text-[#0A0A0A] dark:text-white">Government IDs</h3>
                  <p className="text-sm text-muted-foreground">Review uploaded IDs and approve or reject after verification.</p>
                </div>
                <Button type="button" size="sm" className="gap-1.5" onClick={openAddGovModal}>
                  <Plus className="size-3.5" />
                  Upload ID
                </Button>
              </div>

              {govDocsLoading ? (
                <div className="flex items-center justify-center px-6 py-14">
                  <Loader2 className="mr-2 size-5 animate-spin text-muted-foreground" />
                  <span className="text-sm text-muted-foreground">Loading documents…</span>
                </div>
              ) : govDocs.length === 0 ? (
                <div className="flex flex-col items-center justify-center px-6 py-14">
                  <div className="rounded-xl border border-dashed border-border/60 bg-muted/15 p-4">
                    <IdCard className="size-8 text-muted-foreground/50" />
                  </div>
                  <p className="mt-4 text-sm font-medium text-[#0A0A0A] dark:text-white">No government IDs on file</p>
                  <p className="mt-1 max-w-sm text-center text-xs text-muted-foreground">Upload an ID document for this employee so it can be reviewed and verified.</p>
                  <Button type="button" variant="outline" size="sm" className="mt-4 gap-1.5" onClick={openAddGovModal}>
                    <Upload className="size-3.5" />
                    Upload first ID
                  </Button>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-y border-border/40 bg-muted/25 text-xs font-medium tracking-wide text-muted-foreground [&>th]:px-5 [&>th]:py-2.5 [&>th]:text-left">
                        <th>ID Type</th>
                        <th>ID Number</th>
                        <th className="hidden @lg:table-cell">Issuing Agency</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th className="hidden @md:table-cell">Uploaded</th>
                        <th className="w-[168px] text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {govDocs.map((doc) => (
                        <tr key={doc.id} className="border-b border-border/30 transition-colors hover:bg-muted/20 [&>td]:px-5 [&>td]:py-3.5">
                          <td className="font-medium text-[#0A0A0A] dark:text-white">{doc.id_type}</td>
                          <td className="font-mono text-xs text-muted-foreground">{doc.id_number}</td>
                          <td className="hidden text-muted-foreground @lg:table-cell">{doc.issuing_agency || '—'}</td>
                          {(() => {
                            const expMeta = doc.expiry_date ? expiryMeta(doc.expiry_date) : { cls: 'text-muted-foreground' }
                            return (
                              <td className={expMeta.cls === 'text-rose-700' ? 'font-medium text-rose-700' : expMeta.cls}>
                                {doc.expiry_date ? formatDate(doc.expiry_date) : '—'}
                                {expMeta.label && (expMeta.cls === 'text-rose-700' || expMeta.cls === 'text-amber-800') ? (
                                  <span className="ml-1 text-[10px]">({expMeta.label})</span>
                                ) : null}
                              </td>
                            )
                          })()}
                          <td>
                            <Badge
                              variant="outline"
                              className={cn(
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                doc.status === 'approved' ? 'border-emerald-200/80 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                  : doc.status === 'rejected' ? 'border-rose-200/80 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                  : 'border-amber-200/80 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                              )}
                            >
                              {doc.status === 'approved' ? <CheckCircle2 className="size-3" /> : doc.status === 'rejected' ? <X className="size-3" /> : <Clock4 className="size-3" />}
                              {doc.status === 'approved' ? 'Approved' : doc.status === 'rejected' ? 'Rejected' : 'Pending'}
                            </Badge>
                          </td>
                          <td className="hidden text-muted-foreground @md:table-cell">{formatDate(doc.created_at)}</td>
                          <td className="w-[168px] text-right">
                            <div className="inline-flex items-center justify-end gap-0.5">
                              <Button type="button" variant="ghost" size="icon" className="size-8 cursor-pointer text-muted-foreground hover:bg-muted/60 hover:text-foreground" onClick={() => openPreviewGovModal(doc)} aria-label="Preview">
                                <Eye className="size-3.5" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" className="size-8 cursor-pointer text-muted-foreground hover:bg-muted/60 hover:text-foreground" onClick={() => openEditGovModal(doc)} aria-label="Edit">
                                <Pencil className="size-3.5" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" className="size-8 cursor-pointer text-muted-foreground hover:bg-muted/60 hover:text-foreground" onClick={() => openVerifyGovModal(doc)} aria-label="Verify">
                                <BadgeCheck className="size-3.5" />
                              </Button>
                              <Button type="button" variant="ghost" size="icon" className="size-8 cursor-pointer text-muted-foreground hover:bg-red-50 hover:text-rose-600 dark:hover:bg-rose-950/30" onClick={() => requestDeleteGov(doc)} aria-label="Delete" disabled={govDocsSaving}>
                                <Trash2 className="size-3.5" />
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'emergency-contacts' && (
        <TabsContent value="emergency-contacts">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }} className="space-y-4">
            {deferredSectionLoading.emergency && (
              <div className="inline-flex items-center gap-2 rounded-md border border-border/60 bg-background/70 px-3 py-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading emergency contacts...
              </div>
            )}
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
                  <p className="text-xs text-muted-foreground">Click &quot;Save Changes&quot; at the bottom to persist all profile changes.</p>
                </CardContent>
              </Card>
            </div>

            <Card className="border border-dashed border-border/70">
              <CardContent className="flex flex-col gap-3 p-4 @sm:flex-row @sm:items-center @sm:justify-between">
                <p className="text-xs text-muted-foreground">Changes will be reviewed by HR before being finalized.</p>
                <div className="flex gap-2">
                  <Button type="button" variant="outline" onClick={() => navigate(hrPanelPath(hrBase, 'employees'))}>Cancel</Button>
                  <Button type="button" onClick={handleSave} disabled={saving}>
                    {saving && saveStatus !== 'saved' ? 'Saving...' : saveStatus === 'saved' ? 'Saved' : 'Save Changes'}
                  </Button>
                </div>
              </CardContent>
            </Card>
          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'skills' && (
        <TabsContent value="skills">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }}>
            <div className="space-y-4">
              <div className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between">
                <div className="space-y-1">
                  <h2 className="text-2xl font-semibold tracking-tight">Skills & Certifications</h2>
                  <p className="text-sm text-muted-foreground">
                    Employee ID:{' '}
                    <span className="font-medium text-foreground">
                      {employee?.employee_code || employee?.employee_id || employee?.id || '—'}
                    </span>
                    {employee?.position ? <span className="mx-2 text-muted-foreground">•</span> : null}
                    <span className="text-muted-foreground">{employee?.position || ''}</span>
                  </p>
                </div>

                <div className="flex flex-wrap gap-2 @sm:justify-end">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.print()}
                    className="bg-background"
                  >
                    <Upload className="mr-2 size-4" />
                    Export PDF
                  </Button>
                  <Button type="button" onClick={handleSave} disabled={saving}>
                    <FilePenLine className="mr-2 size-4" />
                    {saving ? 'Updating…' : 'Update Profile'}
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
                      disabled={skillsSaving || skillsLoading}
                    >
                      <Plus className="mr-1 size-4" />
                      Add Skill
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
                            <Sparkles className="size-6" />
                          </div>
                          <div className="space-y-1">
                            <p className="font-medium text-foreground">No skills added yet</p>
                            <p className="text-sm text-muted-foreground">Add skills like Python, Leadership, or AWS to better represent this employee's expertise and help with internal matching.</p>
                          </div>
                          <Button type="button" variant="outline" size="sm" onClick={() => openAddSkillModal('')} disabled={skillsLoading}>
                            <Plus className="mr-1.5 size-4" />
                            Add first skill
                          </Button>
                        </div>  
                      ) : (
                        skills.map((s) => (
                          <div
                            key={s.id}
                            className="group inline-flex items-center gap-2 rounded-full bg-muted px-3 py-2 text-sm"
                          >
                            <CheckCircle2 className="size-4 text-emerald-600" />
                            <button type="button" className="font-medium hover:underline" onClick={() => openSkillPreview(s)}>
                              {s.name}
                            </button>
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
                          </div>
                        ))
                      )}

                      <button
                        type="button"
                        className="inline-flex items-center gap-2 rounded-full border border-dashed border-border/70 bg-background px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
                        onClick={() => openAddSkillModal('')}
                        disabled={skillsSaving || skillsLoading}
                      >
                        <Plus className="size-4" />
                        New
                      </button>
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
                    <Button type="button" size="sm" className="shrink-0" onClick={openAddCertificationModal}>
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
                                  <p className="text-sm text-muted-foreground">Upload certifications to record credentials and showcase this employee&apos;s professional qualifications.</p>
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
                                  const daysLeft = Math.ceil((new Date(cert.expiration_date) - new Date()) / (1000 * 60 * 60 * 24))
                                  return (
                                    <span className={daysLeft < 0 ? 'font-medium text-rose-600 dark:text-rose-400' : daysLeft <= 30 ? 'font-medium text-rose-600 dark:text-rose-400' : daysLeft <= 60 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}>
                                      {formatDate(cert.expiration_date)}
                                      {daysLeft >= 0 && daysLeft <= 60 && <span className="ml-1 text-[11px]">{daysLeft <= 30 ? `(${daysLeft}d left!)` : `(${daysLeft}d)`}</span>}
                                      {daysLeft < 0 && <span className="ml-1 text-[11px]">(Expired)</span>}
                                    </span>
                                  )
                                })() : <span className="text-muted-foreground">—</span>}
                              </td>
                              <td className="min-w-[140px]">
                                <Badge
                                  variant="outline"
                                  className={cert.verification_status === 'verified' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : cert.verification_status === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' : 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'}
                                >
                                  {cert.verification_status === 'verified' ? 'Verified' : cert.verification_status === 'rejected' ? 'Rejected' : 'Pending'}
                                </Badge>
                              </td>
                              <td className="min-w-[120px] text-right">
                                <div className="inline-flex items-center justify-end gap-1">
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => openPreviewCertificationModal(cert)}
                                    aria-label={`View ${cert.certification_name}`}
                                  >
                                    <Eye className="size-4" />
                                  </Button>
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => openEditCertificationModal(cert)}
                                    aria-label={`Edit ${cert.certification_name}`}
                                  >
                                    <Pencil className="size-4" />
                                  </Button>
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => openVerifyCertificationModal(cert)}
                                    aria-label={`Verify ${cert.certification_name}`}
                                  >
                                    <BadgeCheck className="size-4" />
                                  </Button>
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => requestDeleteCertification(cert)}
                                    aria-label={`Remove ${cert.certification_name}`}
                                    disabled={certificationsSaving}
                                  >
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
          </Motion.div>
        </TabsContent>
        )}

        {activeTab === 'account' && isOwnProfile && (
        <TabsContent value="account">
          <Motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }} className="space-y-4">
            <div className="grid gap-6 @lg:grid-cols-2">
              <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                <CardHeader>
                  <CardTitle>Account Information</CardTitle>
                  <CardDescription>Sensitive fields are managed by administrators.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <p><span className="text-muted-foreground">Username:</span> {employee?.username || '—'}</p>
                  <p><span className="text-muted-foreground">Email Address:</span> {employee?.email || '—'}</p>
                  <p className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground">Role:</span>
                    <RoleBadge user={employee} size="sm" />
                  </p>
                  <p><span className="text-muted-foreground">Account Status:</span> {employee?.is_active ? 'Active' : 'Inactive'}</p>
                  <p><span className="text-muted-foreground">Last Login:</span> {formatDateTime(employee?.last_login_at)}</p>
                </CardContent>
              </Card>

              <Card className="border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]">
                <CardHeader>
                  <CardTitle>Update Login Details</CardTitle>
                  <CardDescription>Update your login email, mobile number, or password below.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="admin_acc_email">Email Address</Label>
                    <Input
                      id="admin_acc_email"
                      type="email"
                      value={accountEmail}
                      onChange={(e) => {
                        setAccountEmail(e.target.value)
                        setAccountErrors((prev) => ({ ...prev, email: '' }))
                      }}
                      className={cn(accountErrors.email && 'border-destructive')}
                    />
                    {accountErrors.email ? <p className="text-sm text-destructive">{accountErrors.email}</p> : null}
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => { void handleAccountEmailSave() }}
                      disabled={accountBusy.email}
                    >
                      {accountBusy.email ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                      Update Email
                    </Button>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="admin_acc_phone">Contact Number</Label>
                    <Input
                      id="admin_acc_phone"
                      value={accountPhone}
                      onChange={(e) => {
                        setAccountPhone(e.target.value)
                        setAccountErrors((prev) => ({ ...prev, phone: '' }))
                      }}
                      className={cn(accountErrors.phone && 'border-destructive')}
                    />
                    {accountErrors.phone ? <p className="text-sm text-destructive">{accountErrors.phone}</p> : null}
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => { void handleAccountPhoneSave() }}
                      disabled={accountBusy.phone}
                    >
                      {accountBusy.phone ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                      Update Phone
                    </Button>
                  </div>

                  <div className="space-y-3">
                    <p className="text-sm font-semibold">Change Password</p>
                    <div className="space-y-2">
                      <Label htmlFor="admin_acc_current">Current Password</Label>
                      <PasswordInput
                        id="admin_acc_current"
                        value={accountCurrentPassword}
                        onChange={(e) => {
                          const next = e.target.value
                          setAccountCurrentPassword(next)
                          setAccountPasswordFieldErrors((prev) => ({ ...prev, current: next ? '' : prev.current }))
                          setAccountErrors((p) => ({ ...p, password: '' }))
                        }}
                        className={cn(accountPasswordFieldErrors.current && 'border-destructive')}
                      />
                      {accountPasswordFieldErrors.current ? <p className="text-sm text-destructive">{accountPasswordFieldErrors.current}</p> : null}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="admin_acc_new">New Password</Label>
                      <PasswordInput
                        id="admin_acc_new"
                        value={accountNewPassword}
                        onChange={(e) => {
                          const next = e.target.value
                          setAccountNewPassword(next)
                          setAccountPasswordFieldErrors((prev) => ({
                            ...prev,
                            new: next ? validateTempPassword(next) : '',
                            confirm: accountConfirmPassword ? validateAccountPasswordConfirm(next, accountConfirmPassword) : prev.confirm,
                          }))
                          setAccountErrors((p) => ({ ...p, password: '' }))
                        }}
                        className={cn(accountPasswordFieldErrors.new && 'border-destructive')}
                      />
                      {accountPasswordFieldErrors.new ? <p className="text-sm text-destructive">{accountPasswordFieldErrors.new}</p> : null}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="admin_acc_confirm">Confirm New Password</Label>
                      <PasswordInput
                        id="admin_acc_confirm"
                        value={accountConfirmPassword}
                        onChange={(e) => {
                          const next = e.target.value
                          setAccountConfirmPassword(next)
                          setAccountPasswordFieldErrors((prev) => ({
                            ...prev,
                            confirm: next ? validateAccountPasswordConfirm(accountNewPassword, next) : '',
                          }))
                          setAccountErrors((p) => ({ ...p, password: '' }))
                        }}
                        className={cn(accountPasswordFieldErrors.confirm && 'border-destructive')}
                      />
                      {accountPasswordFieldErrors.confirm ? <p className="text-sm text-destructive">{accountPasswordFieldErrors.confirm}</p> : null}
                    </div>
                    {accountErrors.password ? <p className="text-sm text-destructive">{accountErrors.password}</p> : null}
                    <Button
                      type="button"
                      onClick={() => { void handleAccountPasswordSave() }}
                      disabled={accountBusy.password}
                    >
                      {accountBusy.password ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                      Update Password
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          </Motion.div>
        </TabsContent>
        )}
      </Tabs>

      {isDirty && (
        <div className="fixed bottom-0 left-0 right-0 z-40 border-t border-border bg-background/95 px-4 py-3 backdrop-blur">
          <div className="mx-auto flex max-w-5xl items-center justify-end gap-2">
            <Button variant="outline" onClick={() => navigate(hrPanelPath(hrBase, 'employees'))}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              <FilePenLine className="mr-2 size-4" />
              {saving && saveStatus !== 'saved' ? 'Saving...' : saveStatus === 'saved' ? 'Saved' : 'Save Changes'}
            </Button>
          </div>
        </div>
      )}

      <Dialog open={certModalOpen} onOpenChange={(open) => {
        setCertModalOpen(open)
        if (!open) {
          setActiveCert(null)
          setCertErrors({})
          setCertForm((prev) => ({ ...prev, certificate_file: null }))
        }
      }}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Add Certification</DialogTitle>
            <DialogDescription>Fill in the certification details and upload the certificate file (PDF/JPG/PNG).</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
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
              {certErrors.credential_url ? <p className="text-xs text-destructive">{certErrors.credential_url}</p> : null}
            </div>

            <div className="space-y-2 @sm:col-span-2">
              <Label>Certificate File (optional)</Label>
              <input
                ref={fileInputRef}
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
                <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setCertModalOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitCreateCertification() }} disabled={certificationsSaving}>
              {certificationsSaving ? 'Saving…' : 'Save Certification'}
            </Button>
          </div>
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
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Edit Certification</DialogTitle>
            <DialogDescription>Updating a certification will set it back to pending verification.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
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
              {certErrors.credential_url ? <p className="text-xs text-destructive">{certErrors.credential_url}</p> : null}
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setCertEditModalOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitUpdateCertification() }} disabled={certificationsSaving}>
              {certificationsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={certPreviewOpen} onOpenChange={setCertPreviewOpen}>
        <DialogContent className="max-h-[90vh] w-[95vw] max-w-2xl overflow-hidden flex flex-col p-4 sm:p-6">
          <DialogHeader className="flex-shrink-0">
            <DialogTitle>Preview Certification</DialogTitle>
            <DialogDescription>Review certification details and the uploaded certificate.</DialogDescription>
          </DialogHeader>

          {activeCert ? (
            <div className="grid gap-3 text-sm overflow-y-auto flex-1 min-h-0">
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
                <Badge
                  variant={activeCert.verification_status === 'verified' ? 'default' : activeCert.verification_status === 'rejected' ? 'destructive' : 'outline'}
                >
                  {activeCert.verification_status === 'verified' ? 'Verified' : activeCert.verification_status === 'rejected' ? 'Rejected' : 'Pending'}
                </Badge>
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

          <div className="mt-4 flex flex-col-reverse gap-3 border-t border-border/60 pt-4 flex-shrink-0 @sm:flex-row @sm:justify-end">
            <Button type="button" variant="outline" size="default" className="min-w-[100px]" onClick={() => setCertPreviewOpen(false)}>
              <X className="mr-2 size-4" />
              Close
            </Button>
            {activeCert ? (
              <Button type="button" size="default" className="min-w-[120px]" onClick={() => openVerifyCertificationModal(activeCert)}>
                <BadgeCheck className="mr-2 size-4" />
                Verify certification
              </Button>
            ) : null}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={certVerifyOpen} onOpenChange={setCertVerifyOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Admin Verification</DialogTitle>
            <DialogDescription>Approve or reject this certification.</DialogDescription>
          </DialogHeader>

          {activeCert ? (
            <div className="space-y-3 text-sm">
              <div className="rounded-md border border-border/60 p-3">
                <p className="font-semibold">{activeCert.certification_name}</p>
                <p className="text-xs text-muted-foreground">{activeCert.issuing_organization}</p>
                <p className="text-xs text-muted-foreground">{formatDate(activeCert.issue_date)}{activeCert.expiration_date ? ` → ${formatDate(activeCert.expiration_date)}` : ''}</p>
                {activeCert?.certificate?.url ? (
                  <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => window.open(activeCert.certificate.url, '_blank', 'noopener,noreferrer')}>
                    View Uploaded Certificate
                  </Button>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label>Verification Decision</Label>
                <Select value={verifyStatus} onValueChange={setVerifyStatus}>
                  <SelectTrigger><SelectValue placeholder="Select decision" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="verified">Approve (Verified)</SelectItem>
                    <SelectItem value="rejected">Reject</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {verifyStatus === 'rejected' ? (
                <div className="space-y-2">
                  <Label>Rejection Reason</Label>
                  <Input value={verifyReason} onChange={(e) => setVerifyReason(e.target.value)} placeholder="Explain why this was rejected" />
                  {certErrors.rejection_reason ? <p className="text-xs text-destructive">{certErrors.rejection_reason}</p> : null}
                </div>
              ) : null}
            </div>
          ) : null}

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setCertVerifyOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitVerification() }} disabled={certificationsSaving}>
              {certificationsSaving ? 'Saving…' : verifyStatus === 'rejected' ? 'Reject' : 'Approve'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={certDeleteOpen} onOpenChange={(open) => {
        setCertDeleteOpen(open)
        if (!open) setCertToDelete(null)
      }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Delete certification?</DialogTitle>
            <DialogDescription>This will permanently remove this certification from the employee profile. This action cannot be undone.</DialogDescription>
          </DialogHeader>

          {certToDelete ? (
            <div className="space-y-2 text-sm">
              <p><span className="text-muted-foreground">Certification:</span> <span className="font-medium">{certToDelete.certification_name}</span></p>
              <p><span className="text-muted-foreground">Issuing organization:</span> <span className="font-medium">{certToDelete.issuing_organization}</span></p>
            </div>
          ) : null}

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setCertDeleteOpen(false)} disabled={certificationsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteCertification() }} disabled={certificationsSaving || !certToDelete?.id}>
              {certificationsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={skillAddOpen} onOpenChange={(open) => {
        setSkillAddOpen(open)
        if (!open) {
          setSkillDraft('')
          setSkillDraftError('')
          setSkillSuggestions([])
        }
      }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Add Skill</DialogTitle>
            <DialogDescription>Start typing to see suggestions, or add a custom skill.</DialogDescription>
          </DialogHeader>

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
                  <Button
                    key={name}
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setSkillDraft(String(name))}
                  >
                    {name}
                  </Button>
                ))}
              </div>
            )}
          </div>

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setSkillAddOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitAddSkill() }} disabled={skillsSaving || !String(skillDraft || '').trim()}>
              {skillsSaving ? 'Saving…' : 'Save Skill'}
            </Button>
          </div>
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
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Rename Skill</DialogTitle>
            <DialogDescription>Update the skill name for this employee.</DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setSkillRenameOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitRenameSkill() }} disabled={skillsSaving || !String(skillDraft || '').trim()}>
              {skillsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={skillPreviewOpen} onOpenChange={setSkillPreviewOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Skill Details</DialogTitle>
            <DialogDescription>Preview skill information.</DialogDescription>
          </DialogHeader>
          {activeSkill ? (
            <div className="space-y-2 text-sm">
              <p><span className="text-muted-foreground">Skill:</span> <span className="font-semibold">{activeSkill.name}</span></p>
              <p><span className="text-muted-foreground">Skill ID:</span> {activeSkill.id}</p>
            </div>
          ) : null}
          <div className="mt-2 flex justify-end">
            <Button type="button" variant="outline" onClick={() => setSkillPreviewOpen(false)}>Close</Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={govAddOpen} onOpenChange={(open) => {
        setGovAddOpen(open)
        if (!open) {
          setActiveGovDoc(null)
          setGovErrors({})
          setGovForm((p) => ({ ...p, document_file: null }))
        }
      }}>
        <DialogContent className="max-w-xl border-border/40 bg-white shadow-lg dark:bg-[#111827]">
          <DialogHeader>
            <DialogTitle className="text-[#0A0A0A] dark:text-white">Upload Government ID</DialogTitle>
            <DialogDescription>Upload a clear scan or photo (PDF, JPG, or PNG, max 10 MB) for HR verification.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
            <div className="space-y-2">
              <Label>ID Type</Label>
              <Select
                value={govForm.id_type || 'none'}
                onValueChange={(v) => {
                  const nextType = v === 'none' ? '' : v
                  const def = govIdDefs[nextType]
                  setGovForm((p) => ({
                    ...p,
                    id_type: nextType,
                    issuing_agency: nextType ? (def?.agency || '') : '',
                    id_number: formatGovIdNumber(nextType, p.id_number),
                  }))
                }}
              >
                <SelectTrigger><SelectValue placeholder="Select ID type" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {phGovIdTypes.map((t) => (
                    <SelectItem key={t} value={t}>{t}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {govErrors.id_type ? <p className="text-xs text-destructive">{govErrors.id_type}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>ID Number</Label>
              <Input
                placeholder={govIdDefs[govForm.id_type]?.format || 'Enter ID number'}
                value={govForm.id_number}
                onChange={(e) => setGovForm((p) => ({ ...p, id_number: formatGovIdNumber(p.id_type, e.target.value) }))}
              />
              {govIdDefs[govForm.id_type]?.format ? (
                <p className="text-xs text-muted-foreground">Format: {govIdDefs[govForm.id_type].format} (e.g. {govIdDefs[govForm.id_type].example})</p>
              ) : null}
              {govErrors.id_number ? <p className="text-xs text-destructive">{govErrors.id_number}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Agency</Label>
              <Input value={govForm.issuing_agency} onChange={(e) => setGovForm((p) => ({ ...p, issuing_agency: e.target.value }))} />
              {govErrors.issuing_agency ? <p className="text-xs text-destructive">{govErrors.issuing_agency}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={govForm.expiry_date} onChange={(e) => setGovForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Document File</Label>
              <input
                ref={govUploadModalFileRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setGovForm((p) => ({ ...p, document_file: file || null }))
                }}
              />
              <div
                role="button"
                tabIndex={0}
                onClick={() => govUploadModalFileRef.current?.click()}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') govUploadModalFileRef.current?.click() }}
                onDragOver={(e) => { e.preventDefault(); e.currentTarget.classList.add('border-primary', 'bg-primary/5') }}
                onDragLeave={(e) => { e.currentTarget.classList.remove('border-primary', 'bg-primary/5') }}
                onDrop={(e) => {
                  e.preventDefault()
                  e.currentTarget.classList.remove('border-primary', 'bg-primary/5')
                  const file = e.dataTransfer.files?.[0]
                  if (file) setGovForm((p) => ({ ...p, document_file: file }))
                }}
                className={cn(
                  'group flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-6 transition-all',
                  govForm.document_file
                    ? 'border-emerald-300 bg-emerald-50/50 dark:border-emerald-700 dark:bg-emerald-950/20'
                    : 'border-border/60 bg-muted/10 hover:border-primary/50 hover:bg-muted/20',
                  govErrors.document_file && 'border-destructive/50'
                )}
              >
                {govForm.document_file ? (
                  <>
                    <div className="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                      <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <p className="mt-2 text-sm font-medium text-[#0A0A0A] dark:text-white">{govForm.document_file.name}</p>
                    <p className="text-xs text-muted-foreground">{formatFileSize(govForm.document_file.size)}</p>
                    <button
                      type="button"
                      className="mt-2 text-xs font-medium text-primary hover:underline"
                      onClick={(e) => { e.stopPropagation(); setGovForm((p) => ({ ...p, document_file: null })) }}
                    >
                      Remove and choose another
                    </button>
                  </>
                ) : (
                  <>
                    <div className="flex size-10 items-center justify-center rounded-full bg-muted/40 transition-colors group-hover:bg-primary/10">
                      <Upload className="size-5 text-muted-foreground transition-colors group-hover:text-primary" />
                    </div>
                    <p className="mt-2 text-sm font-medium text-[#0A0A0A] dark:text-white">Click to upload or drag and drop</p>
                    <p className="text-xs text-muted-foreground">PDF, JPG, or PNG · max 10 MB</p>
                  </>
                )}
              </div>
              {govErrors.document_file ? <p className="mt-1 text-xs text-destructive">{govErrors.document_file}</p> : null}
            </div>
          </div>

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setGovAddOpen(false)}>Cancel</Button>
            <Button type="button" className="bg-[#0A0A0A] text-white hover:bg-[#0A0A0A]/90 dark:bg-white dark:text-[#0A0A0A] dark:hover:bg-white/90" onClick={() => { void submitCreateGov() }} disabled={govDocsSaving}>
              {govDocsSaving ? (
                <>
                  <Loader2 className="mr-2 size-4 animate-spin" />
                  Uploading…
                </>
              ) : (
                <>
                  <Upload className="mr-2 size-4" />
                  Upload ID
                </>
              )}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={govEditOpen} onOpenChange={(open) => {
        setGovEditOpen(open)
        if (!open) {
          setActiveGovDoc(null)
          setGovErrors({})
          setGovForm((p) => ({ ...p, document_file: null }))
        }
      }}>
        <DialogContent className="max-w-xl border-border/40 bg-white shadow-lg dark:bg-[#111827]">
          <DialogHeader>
            <DialogTitle className="text-[#0A0A0A] dark:text-white">Edit Government ID</DialogTitle>
            <DialogDescription>Editing resets status to pending until re-verified. Replace the file if needed (PDF, JPG, or PNG, max 10 MB).</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
            <div className="space-y-2">
              <Label>ID Type</Label>
              <Select
                value={govForm.id_type || 'none'}
                onValueChange={(v) => {
                  const nextType = v === 'none' ? '' : v
                  const def = govIdDefs[nextType]
                  setGovForm((p) => ({
                    ...p,
                    id_type: nextType,
                    issuing_agency: nextType ? (def?.agency || '') : '',
                    id_number: formatGovIdNumber(nextType, p.id_number),
                  }))
                }}
              >
                <SelectTrigger><SelectValue placeholder="Select ID type" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Select</SelectItem>
                  {phGovIdTypes.map((t) => (
                    <SelectItem key={t} value={t}>{t}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {govErrors.id_type ? <p className="text-xs text-destructive">{govErrors.id_type}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>ID Number</Label>
              <Input
                placeholder={govIdDefs[govForm.id_type]?.format || 'Enter ID number'}
                value={govForm.id_number}
                onChange={(e) => setGovForm((p) => ({ ...p, id_number: formatGovIdNumber(p.id_type, e.target.value) }))}
              />
              {govIdDefs[govForm.id_type]?.format ? (
                <p className="text-xs text-muted-foreground">Format: {govIdDefs[govForm.id_type].format} (e.g. {govIdDefs[govForm.id_type].example})</p>
              ) : null}
              {govErrors.id_number ? <p className="text-xs text-destructive">{govErrors.id_number}</p> : null}
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Issuing Agency</Label>
              <Input value={govForm.issuing_agency} onChange={(e) => setGovForm((p) => ({ ...p, issuing_agency: e.target.value }))} />
              {govErrors.issuing_agency ? <p className="text-xs text-destructive">{govErrors.issuing_agency}</p> : null}
            </div>
            <div className="space-y-2">
              <Label>Expiration Date (optional)</Label>
              <Input type="date" value={govForm.expiry_date} onChange={(e) => setGovForm((p) => ({ ...p, expiry_date: e.target.value }))} />
            </div>
            <div className="space-y-2 @sm:col-span-2">
              <Label>Replace Document (optional)</Label>
              <input
                ref={govEditModalFileRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  setGovForm((p) => ({ ...p, document_file: file || null }))
                }}
              />
              <div
                role="button"
                tabIndex={0}
                onClick={() => govEditModalFileRef.current?.click()}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') govEditModalFileRef.current?.click() }}
                onDragOver={(e) => { e.preventDefault(); e.currentTarget.classList.add('border-primary', 'bg-primary/5') }}
                onDragLeave={(e) => { e.currentTarget.classList.remove('border-primary', 'bg-primary/5') }}
                onDrop={(e) => {
                  e.preventDefault()
                  e.currentTarget.classList.remove('border-primary', 'bg-primary/5')
                  const file = e.dataTransfer.files?.[0]
                  if (file) setGovForm((p) => ({ ...p, document_file: file }))
                }}
                className={cn(
                  'group flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-5 transition-all',
                  govForm.document_file
                    ? 'border-emerald-300 bg-emerald-50/50 dark:border-emerald-700 dark:bg-emerald-950/20'
                    : 'border-border/60 bg-muted/10 hover:border-primary/50 hover:bg-muted/20'
                )}
              >
                {govForm.document_file ? (
                  <>
                    <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
                    <p className="mt-1.5 text-sm font-medium text-[#0A0A0A] dark:text-white">{govForm.document_file.name}</p>
                    <p className="text-xs text-muted-foreground">{formatFileSize(govForm.document_file.size)}</p>
                    <button
                      type="button"
                      className="mt-1.5 text-xs font-medium text-primary hover:underline"
                      onClick={(e) => { e.stopPropagation(); setGovForm((p) => ({ ...p, document_file: null })) }}
                    >
                      Remove
                    </button>
                  </>
                ) : (
                  <>
                    <Upload className="size-5 text-muted-foreground transition-colors group-hover:text-primary" />
                    <p className="mt-1.5 text-sm text-muted-foreground">Click or drag to replace document</p>
                    <p className="text-[11px] text-muted-foreground/80">PDF, JPG, or PNG · max 10 MB</p>
                  </>
                )}
              </div>
              {govErrors.document_file ? <p className="mt-1 text-xs text-destructive">{govErrors.document_file}</p> : null}
            </div>
          </div>

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setGovEditOpen(false)}>Cancel</Button>
            <Button type="button" className="bg-[#0A0A0A] text-white hover:bg-[#0A0A0A]/90 dark:bg-white dark:text-[#0A0A0A] dark:hover:bg-white/90" onClick={() => { void submitUpdateGov() }} disabled={govDocsSaving}>
              {govDocsSaving ? (
                <>
                  <Loader2 className="mr-2 size-4 animate-spin" />
                  Saving…
                </>
              ) : 'Save Changes'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={govPreviewOpen} onOpenChange={setGovPreviewOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Government ID Preview</DialogTitle>
            <DialogDescription>Review uploaded ID details and document.</DialogDescription>
          </DialogHeader>

          {activeGovDoc ? (
            <div className="space-y-3 text-sm">
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setGovPreviewOpen(false)}>Close</Button>
            {activeGovDoc ? (
              <Button type="button" onClick={() => openVerifyGovModal(activeGovDoc)}>
                <BadgeCheck className="mr-2 size-4" />
                Verify
              </Button>
            ) : null}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={govVerifyOpen} onOpenChange={setGovVerifyOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Admin Approval</DialogTitle>
            <DialogDescription>Approve or reject this Government ID.</DialogDescription>
          </DialogHeader>

          <div className="space-y-3 text-sm">
            <div className="space-y-2">
              <Label>Decision</Label>
              <Select value={govVerifyStatus} onValueChange={setGovVerifyStatus}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="approved">Approve</SelectItem>
                  <SelectItem value="rejected">Reject</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {govVerifyStatus === 'rejected' ? (
              <div className="space-y-2">
                <Label>Rejection Reason</Label>
                <Input value={govVerifyReason} onChange={(e) => setGovVerifyReason(e.target.value)} />
                {govErrors.rejection_reason ? <p className="text-xs text-destructive">{govErrors.rejection_reason}</p> : null}
              </div>
            ) : null}
          </div>

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setGovVerifyOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitGovVerification() }} disabled={govDocsSaving}>
              {govDocsSaving ? 'Saving…' : govVerifyStatus === 'rejected' ? 'Reject' : 'Approve'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={govDeleteOpen} onOpenChange={(open) => {
        setGovDeleteOpen(open)
        if (!open) setGovDeleteDoc(null)
      }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Delete Government ID</DialogTitle>
            <DialogDescription>
              This will permanently remove the selected Government ID record and its uploaded document.
            </DialogDescription>
          </DialogHeader>

          {govDeleteDoc ? (
            <div className="space-y-2 text-sm">
              <p><span className="text-muted-foreground">ID Type:</span> <span className="font-medium">{govDeleteDoc.id_type}</span></p>
              <p><span className="text-muted-foreground">ID Number:</span> <span className="font-medium">{govDeleteDoc.id_number}</span></p>
            </div>
          ) : null}

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setGovDeleteOpen(false)} disabled={govDocsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteGov() }} disabled={govDocsSaving || !govDeleteDoc?.id}>
              {govDocsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={docUploadOpen} onOpenChange={(open) => {
        setDocUploadOpen(open)
        if (!open) {
          setDocErrors({})
          setDocForm((p) => ({ ...p, file: null }))
        }
      }}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Upload Document</DialogTitle>
            <DialogDescription>Upload a PDF/DOCX file for review.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
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
              <Input value={docForm.version} onChange={(e) => setDocForm((p) => ({ ...p, version: e.target.value }))} placeholder="e.g. v1" />
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setDocUploadOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitCreateDoc() }} disabled={docsSaving}>
              {docsSaving ? 'Saving…' : 'Upload'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={docEditOpen} onOpenChange={(open) => {
        setDocEditOpen(open)
        if (!open) {
          setDocErrors({})
          setDocForm((p) => ({ ...p, file: null }))
        }
      }}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Edit Document</DialogTitle>
            <DialogDescription>Editing resets the status to pending until reviewed again.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 @sm:grid-cols-2">
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
              <Input value={docForm.version} onChange={(e) => setDocForm((p) => ({ ...p, version: e.target.value }))} />
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

          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setDocEditOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitUpdateDoc() }} disabled={docsSaving}>
              {docsSaving ? 'Saving…' : 'Save Changes'}
            </Button>
          </div>
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
        <DialogContent className="max-h-[90vh] w-[95vw] max-w-3xl overflow-hidden flex flex-col p-4 sm:p-6">
          <Motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.25, ease: [0.25, 0.1, 0.25, 1] }}
            className="flex flex-col min-h-0 flex-1 overflow-hidden"
          >
            <DialogHeader className="flex-shrink-0">
              <DialogTitle>Document Preview</DialogTitle>
              <DialogDescription>Review the file and metadata.</DialogDescription>
            </DialogHeader>

            {activeDoc ? (
              <div className="space-y-3 text-sm overflow-y-auto flex-1 min-h-0">
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
                      return <iframe title="doc-preview" src={activeDoc.file.url} className="min-h-[200px] h-[50vh] @sm:h-[420px] w-full max-w-full rounded-md border" />
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
                          <div className="flex h-[50vh] @sm:h-[420px] w-full min-h-[200px] items-center justify-center rounded-md border">
                            <Loader2 className="mr-2 size-4 animate-spin text-muted-foreground" />
                            <span className="text-sm text-muted-foreground">Generating preview…</span>
                          </div>
                        )
                      }
                      if (docxPreviewError) {
                        return (
                          <div className="flex h-[50vh] @sm:h-[420px] w-full min-h-[200px] flex-col items-center justify-center gap-2 rounded-md border p-6 text-center">
                            <p className="text-sm text-muted-foreground">{docxPreviewError}</p>
                            <p className="text-xs text-muted-foreground">Use Download to open the file.</p>
                          </div>
                        )
                      }
                      return (
                        <div className="h-[50vh] @sm:h-[420px] min-h-[200px] w-full overflow-auto rounded-md border bg-background p-4">
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

            <div className="mt-2 flex justify-end">
              <Button type="button" variant="outline" onClick={() => setDocPreviewOpen(false)}>Close</Button>
            </div>
          </Motion.div>
        </DialogContent>
      </Dialog>

      <Dialog open={docReviewOpen} onOpenChange={(open) => {
        setDocReviewOpen(open)
        if (!open) {
          setActiveDoc(null)
          setDocErrors({})
        }
      }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Review Document</DialogTitle>
            <DialogDescription>Set the document status and add a note.</DialogDescription>
          </DialogHeader>

          <div className="space-y-3 text-sm">
            <div className="space-y-2">
              <Label>Status</Label>
              <Select value={docReviewStatus} onValueChange={setDocReviewStatus}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="archived">Archived</SelectItem>
                  <SelectItem value="rejected">Rejected</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{docReviewStatus === 'rejected' ? 'Rejection Reason' : 'Review Note (optional)'}</Label>
              <Input value={docReviewNote} onChange={(e) => setDocReviewNote(e.target.value)} placeholder={docReviewStatus === 'rejected' ? 'Enter reason...' : 'Optional note...'} />
              {docErrors.review_note ? <p className="text-xs text-destructive">{docErrors.review_note}</p> : null}
            </div>
          </div>

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setDocReviewOpen(false)}>Cancel</Button>
            <Button type="button" onClick={() => { void submitReviewDoc() }} disabled={docsSaving}>
              {docsSaving ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={docDeleteOpen} onOpenChange={(open) => {
        setDocDeleteOpen(open)
        if (!open) setActiveDoc(null)
      }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Delete Document</DialogTitle>
            <DialogDescription>This will permanently remove the selected document and its file.</DialogDescription>
          </DialogHeader>

          {activeDoc ? (
            <div className="space-y-2 text-sm">
              <p><span className="text-muted-foreground">Name:</span> <span className="font-medium">{getDocumentDisplayName(activeDoc)}</span></p>
              <p><span className="text-muted-foreground">Category:</span> <span className="font-medium">{activeDoc.category}</span></p>
            </div>
          ) : null}

          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setDocDeleteOpen(false)} disabled={docsSaving}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => { void confirmDeleteDoc() }} disabled={docsSaving || !activeDoc?.id}>
              {docsSaving ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <ImageCropDialog
        open={photoCropOpen}
        onOpenChange={(open) => {
          setPhotoCropOpen(open)
          if (!open) setPendingPhotoFile(null)
        }}
        file={pendingPhotoFile}
        title="Crop profile picture"
        description="Crop and zoom to fit the circular avatar."
        maxBytes={2 * 1024 * 1024}
        onConfirm={handleCroppedPhotoConfirm}
      />

      <Dialog open={resetPasswordOpen} onOpenChange={(open) => {
        setResetPasswordOpen(open)
        if (!open) setResetPasswordValue('')
      }}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Reset Password</DialogTitle>
            <DialogDescription>
              Set a temporary password for this employee. They will need to change it after logging in.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="reset-password-input">Temporary password</Label>
            <PasswordInput
              id="reset-password-input"
              value={resetPasswordValue}
              onChange={(e) => setResetPasswordValue(e.target.value)}
              placeholder="Minimum 8 characters"
              aria-invalid={!!resetPasswordError}
              className={resetPasswordError ? 'border-destructive focus-visible:ring-destructive' : undefined}
            />
            {resetPasswordError ? (
              <p className="text-xs text-destructive">{resetPasswordError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                For security, share this password with the employee through a secure channel.
              </p>
            )}
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => setResetPasswordOpen(false)}>
              Cancel
            </Button>
            <Button type="button" size="sm" onClick={handleResetPasswordQuickAction} disabled={!!resetPasswordError}>
              Confirm Reset
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={toggleActiveOpen} onOpenChange={setToggleActiveOpen}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>{employee?.is_active ? 'Deactivate Employee' : 'Activate Employee'}</DialogTitle>
            <DialogDescription>
              {employee?.is_active
                ? 'This will prevent the employee from logging in and using the system until reactivated.'
                : 'This will allow the employee to log in and use the system.'}
            </DialogDescription>
          </DialogHeader>
          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => setToggleActiveOpen(false)} disabled={toggleActiveSaving}>
              Cancel
            </Button>
            <Button
              type="button"
              size="sm"
              variant={employee?.is_active ? 'destructive' : 'default'}
              onClick={handleToggleEmployeeActiveQuickAction}
              disabled={toggleActiveSaving}
            >
              {toggleActiveSaving ? 'Updating...' : employee?.is_active ? 'Confirm Deactivate' : 'Confirm Activate'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={leaveAdjustOpen} onOpenChange={setLeaveAdjustOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Adjust leave credits</DialogTitle>
            <DialogDescription>
              Enter a positive number to add credits or a negative number to reduce. This is logged for audit.
            </DialogDescription>
          </DialogHeader>
          <div className="mt-2 space-y-3">
            <div className="space-y-2">
              <Label htmlFor="leave-adjust-delta">Change (days)</Label>
              <Input
                id="leave-adjust-delta"
                type="number"
                step="1"
                value={leaveAdjustDelta}
                onChange={(e) => setLeaveAdjustDelta(e.target.value)}
                placeholder="e.g. +2 or -1"
                disabled={leaveAdjustSaving}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="leave-adjust-reason">Reason</Label>
              <textarea
                id="leave-adjust-reason"
                rows={3}
                value={leaveAdjustReason}
                onChange={(e) => setLeaveAdjustReason(e.target.value)}
                disabled={leaveAdjustSaving}
                className="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                placeholder="Required — e.g. HR correction, carry-over from prior year…"
              />
            </div>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => setLeaveAdjustOpen(false)} disabled={leaveAdjustSaving}>
              Cancel
            </Button>
            <Button type="button" size="sm" onClick={handleLeaveCreditAdjustSubmit} disabled={leaveAdjustSaving}>
              {leaveAdjustSaving ? 'Saving…' : 'Apply'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={transferOpen} onOpenChange={(open) => {
        if (!open) setTransferOpen(false)
      }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ArrowRightLeft className="size-5" />
              Transfer Employee
            </DialogTitle>
            <DialogDescription>
              Move this employee to a different branch. Department will be cleared unless you select one for the target branch.
            </DialogDescription>
          </DialogHeader>
          <div className="mt-2 space-y-4">
            {(currentBranchName || employee?.branch_name) && (
              <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2">
                <p className="text-xs font-medium text-muted-foreground">Current Branch</p>
                <p className="text-sm font-semibold text-foreground">{currentBranchName || employee.branch_name} {employee.company_name ? `(${employee.company_name})` : ''}</p>
              </div>
            )}
            {isBranchManager && (
              <div className="flex items-center gap-2 rounded-lg border border-amber-200/60 bg-amber-50/50 px-3 py-2 dark:border-amber-800/50 dark:bg-amber-950/20">
                <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                <p className="text-sm text-amber-800 dark:text-amber-200">
                  This employee is Branch Manager. Transfer will remove that role from the current branch.
                </p>
              </div>
            )}
            <div className="space-y-2">
              <Label htmlFor="transfer-target-branch">Target Branch *</Label>
              {transferBranchesLoading ? (
                <div className="flex h-10 items-center rounded-md border border-input bg-muted/50 px-3 text-sm text-muted-foreground">
                  Loading branches...
                </div>
              ) : availableTransferBranches.length === 0 ? (
                <div className="rounded-lg border border-amber-200/60 bg-amber-50/50 px-4 py-3 dark:border-amber-800/50 dark:bg-amber-950/30">
                  <p className="text-sm font-medium text-amber-800 dark:text-amber-200">
                    No other branches available.
                  </p>
                  <p className="mt-0.5 text-xs text-amber-700/90 dark:text-amber-300/80">
                    {employee?.company_name
                      ? `This company (${employee.company_name}) has only one branch. Create a new branch first to transfer employees.`
                      : 'Create a branch first to assign or transfer this employee.'}
                  </p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-2"
                    onClick={() => {
                      setTransferOpen(false)
                      navigate(hrPanelPath(hrBase, 'branches'))
                    }}
                  >
                    <ExternalLink className="mr-1.5 size-3.5" />View branches
                  </Button>
                </div>
              ) : (
                <Select
                  value={transferForm.targetBranchId}
                  onValueChange={(v) => setTransferForm((f) => ({ ...f, targetBranchId: v, departmentId: '' }))}
                >
                  <SelectTrigger id="transfer-target-branch">
                    <SelectValue placeholder="Select branch" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableTransferBranches.map((b) => (
                      <SelectItem key={b.id} value={String(b.id)}>
                        {b.name} {b.company_name ? `(${b.company_name})` : ''}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>
            {transferForm.targetBranchId && transferDepartments.length > 0 && (
              <div className="space-y-2">
                <Label htmlFor="transfer-department">Department (optional)</Label>
                <Select
                  value={transferForm.departmentId}
                  onValueChange={(v) => setTransferForm((f) => ({ ...f, departmentId: v }))}
                >
                  <SelectTrigger id="transfer-department">
                    <SelectValue placeholder="None" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">None</SelectItem>
                    {transferDepartments.map((d) => (
                      <SelectItem key={d.id} value={String(d.id)}>
                        {d.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-2">
              <Label htmlFor="transfer-date">Transfer Date</Label>
              <Input
                id="transfer-date"
                type="date"
                value={transferForm.transferDate}
                onChange={(e) => setTransferForm((f) => ({ ...f, transferDate: e.target.value }))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="transfer-reason">Reason (optional)</Label>
              <Input
                id="transfer-reason"
                placeholder="e.g. Relocation, department restructure"
                value={transferForm.reason}
                onChange={(e) => setTransferForm((f) => ({ ...f, reason: e.target.value }))}
              />
            </div>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => setTransferOpen(false)} disabled={transferSaving}>
              Cancel
            </Button>
            <Button
              type="button"
              size="sm"
              onClick={handleTransferSubmit}
              disabled={transferSaving || !transferForm.targetBranchId}
            >
              {transferSaving ? 'Transferring...' : 'Confirm Transfer'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={removePhotoConfirmOpen} onOpenChange={setRemovePhotoConfirmOpen}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Remove profile photo</DialogTitle>
            <DialogDescription>
              Are you sure you want to remove this employee&apos;s profile picture? You can upload a new one anytime.
            </DialogDescription>
          </DialogHeader>
          <div className="mt-4 flex justify-end gap-2">
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
              disabled={photoSaving || !canEditProfilePhoto}
            >
              {photoSaving ? 'Removing...' : 'Remove Photo'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}

