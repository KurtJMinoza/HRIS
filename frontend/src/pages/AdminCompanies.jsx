import { useState, useEffect, useCallback, useRef, useMemo, createElement } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, Building2, Loader2, UserPlus, Trash2, ImagePlus, MoreVertical,
  ChevronRight, ChevronUp, ChevronDown, MapPin, Users, Users2, UserCheck, ExternalLink,
  Building, Pencil, Search, Crown, Eye, UserCircle, X, ShieldAlert, ArrowRight, FileBarChart, FileText,
  Mail, Hash, Activity, Network, Layers, Phone, Calendar, Percent, UserMinus,
  TrendingUp, TrendingDown, CheckCircle2, Clock3, UserX, Umbrella, PenLine, Sparkles, Upload,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetClose } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import LeadershipPositionsSection from '@/components/organization/LeadershipPositionsSection'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import {
  getCompanies,
  createCompany,
  updateCompany,
  updateCompanyProfile,
  deleteCompany,
  getEmployees,
  getDepartments,
  getBranches,
  getDashboardData,
  companyLogoUrl,
  departmentLogoUrl,
  profileImageUrl,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { isAdminHrUser, hrPanelPath } from '@/lib/hrRoutes'
import { useToast } from '@/components/ui/use-toast'
import { Skeleton } from '@/components/ui/skeleton'
import { hasEmoji, hasFancyUnicode } from '@/validation'
import { RoleBadge } from '@/components/RoleBadge'
import { cn } from '@/lib/utils'
import { compareEmployeesByLastName } from '@/lib/employeeSort'
import { isRosterStaffMember } from '@/lib/rosterStaff'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_LG,
  ADMIN_FORM_DIALOG_MAX_W_MD,
  ADMIN_FORM_DIALOG_MAX_W_SM,
} from '@/lib/adminFormDialogStyles'

const LOGO_ACCEPT = 'image/jpeg,image/jpg,image/png,image/webp'
const LOGO_MAX_SIZE_MB = 2
const LOGO_MAX_BYTES = LOGO_MAX_SIZE_MB * 1024 * 1024
const companyCardClass =
  'rounded-[18px] border border-border/70 bg-card text-card-foreground shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const companyPrimaryButtonClass =
  'h-12 gap-2 rounded-lg bg-brand px-6 text-base font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const companyOutlineButtonClass =
  'h-12 gap-2 rounded-lg border-border/80 bg-card px-5 text-base font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'

function validateCompanyName(value) {
  const trimmed = value.trim()
  if (!trimmed) return 'Company name is required.'
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) return 'Please use standard letters/numbers only.'
  if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) return 'Company name may only contain letters, numbers, spaces, hyphens, and apostrophes.'
  if (trimmed.length > 100) return 'Company name must be 100 characters or less.'
  return ''
}

function initials(name) {
  return (name || '?').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '?'
}

/** Pick a sensible default tab: employees-only orgs open on Employees; else prefer data. */
function resolveDefaultDetailTab(company) {
  if (!company) return 'branches'
  const br = Number(company.branches_count) || 0
  const dep = Number(company.departments_count) || 0
  const emp = Number(company.total_employees) || 0
  if (emp > 0 && br === 0 && dep === 0) return 'employees'
  if (br > 0) return 'branches'
  if (dep > 0) return 'departments'
  if (emp > 0) return 'employees'
  return 'branches'
}

/** Clear copy for org cards — avoids "Est. Today" reading like a typo. */
function formatEstablishedLabel(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  if (Number.isNaN(d.getTime())) return ''
  const days = Math.floor((Date.now() - d.getTime()) / (1000 * 60 * 60 * 24))
  if (days === 0) return 'Added today'
  if (days === 1) return 'Added yesterday'
  const short = d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  return `Since ${short}`
}

/** Overlapping avatars / logos for org overview stat cells. */
function OrgProfileStack({ items, max = 4, className }) {
  if (!items?.length) return null
  const visible = items.slice(0, max)
  const rest = items.length - visible.length
  return (
    <div className={cn('flex min-h-[30px] items-center justify-center px-0.5', className)} aria-hidden>
      <div className="flex items-center justify-center">
        {visible.map((it, idx) => (
          <span key={it.key} className="inline-flex" title={it.name || undefined}>
            <Avatar
              className={cn(
                'size-7 shrink-0 border-0 shadow-sm ring-2 ring-card dark:ring-card',
                idx > 0 && '-ml-2.5'
              )}
              style={{ zIndex: visible.length - idx }}
            >
              <AvatarImage src={it.src} alt="" className="object-cover" />
              <AvatarFallback
                className={cn(
                  'text-[9px] font-bold',
                  it.fallbackClassName || 'bg-primary/12 text-primary'
                )}
              >
                {it.initials}
              </AvatarFallback>
            </Avatar>
          </span>
        ))}
        {rest > 0 && (
          <div
            className="-ml-2.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold tabular-nums text-muted-foreground ring-2 ring-card dark:ring-card"
            style={{ zIndex: 0 }}
            title={`${rest} more`}
          >
            +{rest}
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * Single stat cell: icon + optional profile stack + number + label.
 */
function OrgStatCell({
  value,
  singular,
  plural,
  icon,
  onClick,
  title,
  emphasize = false,
  stackItems = null,
}) {
  const n = Number(value) || 0
  const isZero = n === 0
  const label = n === 1 ? singular : plural
  const iconClass = cn(
    'size-4 shrink-0',
    emphasize && n > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'
  )
  const hasStack = Array.isArray(stackItems) && stackItems.length > 0
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onClick?.(e)
      }}
      title={title}
      className={cn(
        'flex min-w-0 flex-1 flex-col items-center gap-1 rounded-xl border px-2 py-2.5 text-center transition-colors',
        'hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        emphasize && n > 0
          ? 'border-emerald-200/90 bg-emerald-50/50 shadow-sm dark:border-emerald-500/35 dark:bg-emerald-500/10'
          : 'border-border/60 bg-muted/15 dark:border-white/10 dark:bg-white/3',
        isZero && 'opacity-75 text-muted-foreground'
      )}
    >
      {createElement(icon, { className: iconClass, 'aria-hidden': true })}
      {hasStack ? <OrgProfileStack items={stackItems} max={4} className="py-0.5" /> : null}
      <span
        className={cn(
          'text-lg font-bold tabular-nums leading-tight',
          !isZero && emphasize && 'text-emerald-800 dark:text-emerald-300',
          isZero && 'text-muted-foreground/50 font-semibold'
        )}
      >
        {isZero ? '0' : n}
      </span>
      <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
    </button>
  )
}

/** Build overlapping avatar items for employees under a company (direct company_id or branch in company). */
function buildEmployeeStackItems(companyId, employees, branches) {
  const cid = Number(companyId)
  const branchIds = new Set(
    (branches || []).filter((b) => Number(b.company_id) === cid).map((b) => Number(b.id))
  )
  const list = (employees || []).filter((e) => {
    if (e.role !== 'employee') return false
    if (Number(e.company_id) === cid) return true
    if (e.branch_id != null && branchIds.has(Number(e.branch_id))) return true
    return false
  })
  list.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }))
  return list.slice(0, 32).map((e) => ({
    key: `e-${e.id}`,
    src: profileImageUrl(e.profile_image),
    initials: initials(e.name),
    name: e.name || '',
    fallbackClassName: 'bg-emerald-600/15 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
  }))
}

function buildBranchStackItems(companyId, branches) {
  const list = (branches || [])
    .filter((b) => Number(b.company_id) === Number(companyId))
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }))
  return list.slice(0, 32).map((b) => ({
    key: `b-${b.id}`,
    src: b.branch_manager_profile_image || b.logo_url || undefined,
    initials: initials(b.name),
    name: b.name || '',
    fallbackClassName: 'bg-sky-500/15 text-sky-800 dark:text-sky-200',
  }))
}

function buildDepartmentStackItems(companyId, departments) {
  const list = (departments || [])
    .filter((d) => Number(d.company_id) === Number(companyId))
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }))
  return list.slice(0, 32).map((d) => ({
    key: `d-${d.id}`,
    src: profileImageUrl(d.department_head_profile_image) || departmentLogoUrl(d) || undefined,
    initials: initials(d.name),
    name: d.name || '',
    fallbackClassName: 'bg-violet-500/15 text-violet-800 dark:text-violet-200',
  }))
}

function AssignHeadDialog({ open, onOpenChange, company, headId, onHeadIdChange, employees, companies, onSubmit, submitting }) {
  const [search, setSearch] = useState('')
  const [popoverOpen, setPopoverOpen] = useState(false)
  const inputRef = useRef(null)

  // Build informational notes for employees with other leadership roles.
  const roleNoteMap = useMemo(() => {
    const map = new Map()
    if (!company) return map
    for (const c of companies || []) {
      if (c.company_head_id && Number(c.id) !== Number(company.id)) {
        map.set(String(c.company_head_id), `Company Head — ${c.name}`)
      }
    }
    return map
  }, [companies, company])

  const filtered = useMemo(() => {
    if (!search.trim()) return employees.slice(0, 50)
    const q = search.trim().toLowerCase()
    return employees
      .filter((e) => (e.name || '').toLowerCase().includes(q) || (e.employee_code || '').toLowerCase().includes(q) || (e.email || '').toLowerCase().includes(q))
      .slice(0, 30)
  }, [employees, search])

  const selected = employees.find((e) => String(e.id) === headId)

  const handleOpenChange = (nextOpen) => {
    if (!nextOpen) {
      setSearch('')
      setPopoverOpen(false)
    }
    onOpenChange(nextOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton
        className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
        aria-describedby="assign-head-desc"
      >
        <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
            <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Assign Company Head</DialogTitle>
            <p id="assign-head-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
              {company?.name} — select any active employee to oversee this company. Cross-company and multiple leadership assignments are allowed.
            </p>
          </DialogHeader>
        </div>
        <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
          <div>
            <Label>Company Head</Label>
            <Popover open={popoverOpen} onOpenChange={setPopoverOpen}>
              <PopoverTrigger asChild>
                <button
                  type="button"
                  ref={inputRef}
                  className="mt-1 flex h-10 w-full items-center gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-left text-sm shadow-sm hover:bg-muted/50 focus:outline-none focus:ring-2 focus:ring-ring dark:border-border/50 dark:bg-input/30"
                >
                  {selected ? (
                    <>
                      <Avatar className="size-6 shrink-0">
                        <AvatarImage src={profileImageUrl(selected.profile_image)} />
                        <AvatarFallback className="text-[10px] font-bold bg-primary/10 text-primary">{initials(selected.name)}</AvatarFallback>
                      </Avatar>
                      <span className="flex-1 truncate">{selected.name}{selected.employee_code ? ` (${selected.employee_code})` : ''}</span>
                    </>
                  ) : (
                    <span className="text-muted-foreground">Search or select employee…</span>
                  )}
                </button>
              </PopoverTrigger>
              <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-[280px] p-0" align="start">
                <div className="border-b p-2">
                  <div className="relative">
                    <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder="Search by name, code, email…"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      className="h-9 pl-8"
                      autoFocus
                    />
                  </div>
                </div>
                <div className="max-h-[260px] overflow-y-auto">
                  <button
                    type="button"
                    onClick={() => { onHeadIdChange(''); setPopoverOpen(false) }}
                    className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted ${!headId ? 'bg-muted' : ''}`}
                  >
                    <span className="text-muted-foreground">Not assigned</span>
                  </button>
                  {filtered.map((emp) => {
                    const roleNote = roleNoteMap.get(String(emp.id))
                    const isInactive = emp.is_active === false && String(emp.id) !== String(company?.company_head_id)
                    return (
                      <button
                        key={emp.id}
                        type="button"
                        disabled={isInactive}
                        onClick={() => { if (!isInactive) { onHeadIdChange(String(emp.id)); setPopoverOpen(false) } }}
                        className={`flex w-full items-start gap-2 px-3 py-2 text-left text-sm transition-colors
                          ${isInactive ? 'cursor-not-allowed opacity-60' : 'hover:bg-muted'}
                          ${headId === String(emp.id) ? 'bg-muted' : ''}`}
                      >
                        <Avatar className="mt-0.5 size-7 shrink-0">
                          <AvatarImage src={profileImageUrl(emp.profile_image)} />
                          <AvatarFallback className="text-[10px] font-bold bg-primary/10 text-primary">{initials(emp.name)}</AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                          <p className="truncate font-medium leading-tight">{emp.name}</p>
                          {(emp.employee_code || emp.position) && (
                            <p className="truncate text-xs text-muted-foreground">{[emp.employee_code, emp.position].filter(Boolean).join(' · ')}</p>
                          )}
                          {roleNote && (
                            <span className="mt-0.5 inline-flex items-center gap-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-300">
                              {roleNote}
                            </span>
                          )}
                        </div>
                      </button>
                    )
                  })}
                  {filtered.length === 0 && <div className="px-3 py-4 text-center text-sm text-muted-foreground">No matches</div>}
                </div>
              </PopoverContent>
            </Popover>
            {headId && (
              <div className="mt-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 border-destructive/40 text-destructive hover:bg-destructive/10"
                  onClick={() => onHeadIdChange('')}
                >
                  <UserMinus className="mr-1.5 size-3.5" />
                  Remove employee
                </Button>
              </div>
            )}
            <p className="mt-1 text-xs text-muted-foreground">Type to search. Employees already Company Head of another company cannot be selected.</p>
          </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={submitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Save'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function CompanyAccessDenied() {
  return (
    <div className="space-y-6 p-4 @md:p-6">
      <Card className="overflow-hidden border border-destructive/25 bg-destructive/5">
        <CardContent className="flex flex-col items-center gap-4 py-16 text-center @md:flex-row @md:text-left @md:py-12 @md:px-8">
          <div className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-destructive/10 text-destructive">
            <ShieldAlert className="size-8" />
          </div>
          <div className="max-w-lg space-y-2">
            <h2 className="text-xl font-semibold tracking-tight text-foreground">Access denied</h2>
            <p className="text-sm leading-relaxed text-muted-foreground">
              The Company module is available only to HR administrators and company heads. Open Branches or Departments
              under Organization for your scope, or contact HR if you need a different role.
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function DeltaCount({ current, previous, noun }) {
  if (current == null || previous == null) return null
  const d = Number(current) - Number(previous)
  if (Number.isNaN(d) || d === 0) {
    return <span className="text-[11px] font-medium text-muted-foreground">Same as yesterday</span>
  }
  const up = d > 0
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 text-[11px] font-semibold',
        up ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
      )}
    >
      {up ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
      {up ? '+' : ''}
      {d} {noun} vs yesterday
    </span>
  )
}

function RateDeltaPoints({ currentRate, previousRate }) {
  if (currentRate == null || previousRate == null) return null
  const d = currentRate - previousRate
  if (Math.abs(d) < 0.05) {
    return <span className="text-[11px] font-medium text-muted-foreground">In line with yesterday</span>
  }
  const up = d > 0
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 text-[11px] font-semibold',
        up ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
      )}
    >
      {up ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
      {up ? '+' : ''}
      {d.toFixed(1)} pts vs yesterday
    </span>
  )
}

function WeeklyAttendanceBars({ days }) {
  if (!Array.isArray(days) || days.length === 0) return null
  const counts = days.map((d) => Number(d.present_count) || 0)
  const max = Math.max(...counts, 1)
  return (
    <div className="flex h-[100px] items-end gap-1.5 @md:gap-2">
      {days.map((d) => {
        const v = Number(d.present_count) || 0
        const hPx = Math.max(6, Math.round((v / max) * 72))
        return (
          <div
            key={d.date}
            className="flex min-w-0 flex-1 flex-col items-center justify-end gap-2"
            title={`${d.label}: ${v} present`}
          >
            <div
              className="w-full max-w-[32px] rounded-t-md bg-gradient-to-t from-emerald-700/95 to-teal-400/85 shadow-sm dark:from-emerald-600 dark:to-teal-500/80"
              style={{ height: `${hPx}px` }}
            />
            <span className="w-full truncate text-center text-[10px] font-medium leading-none text-muted-foreground">
              {d.label}
            </span>
          </div>
        )
      })}
    </div>
  )
}

function AttendanceRateRing({ pct }) {
  if (pct == null || Number.isNaN(pct)) return null
  const p = Math.min(100, Math.max(0, pct))
  const r = 28
  const c = 2 * Math.PI * r
  const dash = (p / 100) * c
  return (
    <div className="relative size-[72px] shrink-0">
      <svg viewBox="0 0 72 72" className="size-full -rotate-90" aria-hidden>
        <circle cx="36" cy="36" r={r} fill="none" className="stroke-muted/25" strokeWidth="6" />
        <circle
          cx="36"
          cy="36"
          r={r}
          fill="none"
          className="stroke-emerald-500 dark:stroke-emerald-400"
          strokeWidth="6"
          strokeLinecap="round"
          strokeDasharray={`${dash} ${c}`}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-lg font-bold tabular-nums leading-none text-emerald-700 dark:text-emerald-300">{Math.round(p)}%</span>
      </div>
    </div>
  )
}

function CompanyHeadOverview({
  company,
  loading,
  error,
  basePath,
  allBranches,
  allEmployees,
  dashStats,
  dashStatsPrev,
  weeklyOverview,
  onRefresh,
}) {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [editOpen, setEditOpen] = useState(false)
  const [editPhone, setEditPhone] = useState('')
  const [editEmail, setEditEmail] = useState('')
  const [editTin, setEditTin] = useState('')
  const [editAddress, setEditAddress] = useState('')
  const [editFounded, setEditFounded] = useState('')
  const [editLogo, setEditLogo] = useState(null)
  const [editLogoPreview, setEditLogoPreview] = useState(null)
  const [editSubmitting, setEditSubmitting] = useState(false)
  const editLogoInputRef = useRef(null)

  const branches = useMemo(
    () => (allBranches || []).filter((b) => String(b.company_id) === String(company?.id)),
    [allBranches, company?.id]
  )
  const headEmp = useMemo(
    () => allEmployees.find((e) => String(e.id) === String(company?.company_head_id)),
    [allEmployees, company?.company_head_id]
  )

  useEffect(() => {
    if (!company || !editOpen) return
    setEditPhone(company.phone ?? '')
    setEditEmail(company.email ?? '')
    setEditTin(company.tin ?? '')
    setEditAddress(company.address ?? '')
    setEditFounded(company.founded_at ? String(company.founded_at).slice(0, 10) : '')
    setEditLogo(null)
    setEditLogoPreview(null)
  }, [company, editOpen])

  const presentToday = typeof dashStats?.present_today === 'number' ? dashStats.present_today : null
  const totalHeadcount =
    typeof dashStats?.total_employees === 'number'
      ? dashStats.total_employees
      : Number(company?.total_employees) || 0

  const attendanceRate =
    totalHeadcount > 0 && typeof presentToday === 'number'
      ? Math.round((presentToday / totalHeadcount) * 1000) / 10
      : null

  const presentYesterday =
    typeof dashStatsPrev?.present_today === 'number' ? dashStatsPrev.present_today : null
  const rateYesterday =
    totalHeadcount > 0 && presentYesterday != null
      ? Math.round((presentYesterday / totalHeadcount) * 1000) / 10
      : null

  const link = (segment, query = {}) => {
    const path = hrPanelPath(basePath, segment)
    const qs = new URLSearchParams(query)
    const q = qs.toString()
    return q ? `${path}?${q}` : path
  }

  const openEdit = () => {
    if (!company) return
    setEditOpen(true)
  }

  const submitProfile = async (e) => {
    e.preventDefault()
    if (!company) return
    setEditSubmitting(true)
    try {
      await updateCompanyProfile(company.id, {
        phone: editPhone,
        email: editEmail,
        tin: editTin,
        address: editAddress,
        founded_at: editFounded || null,
        logo: editLogo instanceof File ? editLogo : undefined,
      })
      toast({ title: 'Profile updated', description: 'Your company details were saved.' })
      setEditOpen(false)
      onRefresh?.()
    } catch (err) {
      toast({ variant: 'destructive', title: 'Update failed', description: err.message || 'Could not save.' })
    } finally {
      setEditSubmitting(false)
    }
  }

  if (loading) {
    return (
      <div className="min-h-[60vh] space-y-8 bg-[#F8FAFC] p-4 @md:p-8 dark:bg-background">
        <Skeleton className="h-52 w-full rounded-3xl" />
        <div className="grid gap-4 @md:grid-cols-2 @lg:grid-cols-3 @xl:grid-cols-5">
          {[...Array(5)].map((_, i) => (
            <Skeleton key={i} className="h-40 rounded-2xl" />
          ))}
        </div>
        <Skeleton className="h-36 w-full rounded-2xl" />
        <div className="grid gap-6 @xl:grid-cols-3">
          <Skeleton className="h-80 rounded-2xl @xl:col-span-2" />
          <Skeleton className="h-80 rounded-2xl" />
        </div>
      </div>
    )
  }

  if (error || !company) {
    return (
      <div className="p-4 @md:p-6">
        <Card className="border-dashed">
          <CardContent className="py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {error || 'No company could be loaded for your account. Contact HR if this is unexpected.'}
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  const addresses = branches.map((b) => b.address).filter(Boolean).slice(0, 4)
  const lateToday = typeof dashStats?.late_today === 'number' ? dashStats.late_today : null
  const absentToday = typeof dashStats?.absent_today === 'number' ? dashStats.absent_today : null
  const onLeave = typeof dashStats?.on_leave === 'number' ? dashStats.on_leave : null

  const snapshotDate = new Date().toLocaleDateString('en-PH', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })

  return (
    <div className="min-h-screen space-y-10 bg-[#F8FAFC] p-4 pb-16 @md:p-8 dark:bg-background">
      {/* Executive hero */}
      <div className="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-[#0f172a] px-6 py-8 text-white shadow-2xl @md:px-10 @md:py-11">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_20%_0%,rgba(16,185,129,0.18),transparent_55%)]" />
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_100%_100%,rgba(59,130,246,0.12),transparent_45%)]" />
        <div className="relative flex flex-col gap-10 @lg:flex-row @lg:items-center @lg:justify-between">
          <div className="flex flex-col gap-8 @lg:flex-row @lg:items-center @lg:gap-10">
            <div className="flex shrink-0 justify-center @lg:justify-start">
              {companyLogoUrl(company) ? (
                <div className="flex size-[7.5rem] @md:size-32 items-center justify-center overflow-hidden rounded-2xl border border-white/15 bg-white/5 shadow-[0_0_48px_-12px_rgba(16,185,129,0.55)] ring-2 ring-emerald-400/30">
                  <img src={companyLogoUrl(company)} alt="" className="size-full object-cover" />
                </div>
              ) : (
                <div className="flex size-[7.5rem] @md:size-32 items-center justify-center rounded-2xl border border-white/15 bg-white/5 text-3xl font-bold tracking-tight text-white shadow-[0_0_48px_-12px_rgba(16,185,129,0.45)] ring-2 ring-emerald-400/25">
                  {initials(company.name)}
                </div>
              )}
            </div>
            <div className="min-w-0 flex-1 space-y-4 text-center @lg:text-left">
              <div className="flex flex-wrap items-center justify-center gap-2 @lg:justify-start">
                <Badge className="rounded-full border border-emerald-400/45 bg-emerald-500/20 px-3 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-emerald-50">
                  Company Head
                </Badge>
                <span className="text-[11px] font-medium uppercase tracking-[0.2em] text-white/45">Executive</span>
              </div>
              <div className="space-y-2">
                <p className="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-200/90">Overview</p>
                <h1 className="text-balance text-4xl font-bold tracking-tight @md:text-5xl @lg:text-[2.75rem] @lg:leading-[1.05]">
                  {company.name}
                </h1>
                <p className="mx-auto flex max-w-2xl items-center justify-center gap-2 text-sm leading-relaxed text-white/75 @lg:mx-0 @lg:justify-start">
                  <Sparkles className="size-4 shrink-0 text-emerald-300/90" aria-hidden />
                  <span>Calm authority: one glance at headcount, locations, and today&apos;s attendance — structure changes stay with HR.</span>
                </p>
              </div>
              <div className="flex flex-wrap items-center justify-center gap-3 pt-1 @lg:justify-start">
                <Button
                  type="button"
                  className="h-11 rounded-full border-0 bg-white px-6 text-base font-semibold text-slate-900 shadow-lg transition-all hover:scale-[1.02] hover:bg-white hover:shadow-xl"
                  onClick={openEdit}
                >
                  <Pencil className="mr-2 size-4" />
                  Edit company details
                </Button>
                <p className="w-full text-center text-[11px] text-white/45 @lg:w-auto @lg:text-left">Snapshot · {snapshotDate}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* KPI row */}
      <div className="grid gap-4 @md:grid-cols-2 @lg:grid-cols-3 @xl:grid-cols-5">
        <Card className="border-border/60 shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-border/50">
          <CardContent className="flex min-h-[168px] flex-col justify-between gap-4 p-7">
            <Users className="size-7 text-emerald-600/90 dark:text-emerald-400" aria-hidden />
            <div>
              <p className="text-4xl font-bold tabular-nums tracking-tight text-foreground">{totalHeadcount}</p>
              <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Total employees</p>
              <p className="mt-2 text-xs leading-relaxed text-muted-foreground">Company-wide headcount in your scope.</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-border/60 shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-border/50">
          <CardContent className="flex min-h-[168px] flex-col justify-between gap-4 p-7">
            <MapPin className="size-7 text-sky-600/90 dark:text-sky-400" aria-hidden />
            <div>
              <p className="text-4xl font-bold tabular-nums tracking-tight text-foreground">{company.branches_count ?? 0}</p>
              <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Branches</p>
              <p className="mt-2 text-xs leading-relaxed text-muted-foreground">Registered locations for your company.</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-border/60 shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-border/50">
          <CardContent className="flex min-h-[168px] flex-col justify-between gap-4 p-7">
            <Building2 className="size-7 text-violet-600/85 dark:text-violet-400" aria-hidden />
            <div>
              <p className="text-4xl font-bold tabular-nums tracking-tight text-foreground">{company.departments_count ?? 0}</p>
              <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Departments</p>
              <p className="mt-2 text-xs leading-relaxed text-muted-foreground">Org units under your company.</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-border/60 shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-border/50">
          <CardContent className="flex min-h-[168px] flex-col justify-between gap-4 p-7">
            <UserCheck className="size-7 text-amber-600/90 dark:text-amber-400" aria-hidden />
            <div>
              <p className="text-4xl font-bold tabular-nums tracking-tight text-foreground">{presentToday ?? '—'}</p>
              <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Active today</p>
              <div className="mt-2 space-y-1">
                <p className="text-xs text-muted-foreground">Clocked in today (your scope).</p>
                <DeltaCount current={presentToday} previous={presentYesterday} noun="present" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card
          className={cn(
            'border-emerald-200/90 shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-emerald-500/40',
            'bg-gradient-to-br from-emerald-50/95 via-white to-white dark:from-emerald-950/40 dark:via-card dark:to-card'
          )}
        >
          <CardContent className="flex min-h-[168px] flex-row items-center gap-5 p-7">
            <AttendanceRateRing pct={attendanceRate} />
            <div className="min-w-0 flex-1">
              <Percent className="size-7 text-emerald-600 dark:text-emerald-400" aria-hidden />
              <p className="mt-3 text-4xl font-bold tabular-nums tracking-tight text-foreground">
                {attendanceRate != null ? `${attendanceRate}%` : '—'}
              </p>
              <p className="mt-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Attendance rate</p>
              <div className="mt-2 space-y-1">
                <p className="text-xs text-muted-foreground">Present ÷ headcount · today.</p>
                <RateDeltaPoints currentRate={attendanceRate} previousRate={rateYesterday} />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* 7-day pulse */}
      {weeklyOverview && weeklyOverview.length > 0 && (
        <Card className="border-border/60 shadow-md dark:border-border/50">
          <CardHeader className="pb-2">
            <div className="flex flex-col gap-1 @md:flex-row @md:items-end @md:justify-between">
              <div>
                <CardTitle className="text-lg font-semibold">Attendance pulse</CardTitle>
                <CardDescription>Distinct clock-ins per day · last 7 days · your scope</CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="px-6 pb-7 pt-0">
            <WeeklyAttendanceBars days={weeklyOverview} />
          </CardContent>
        </Card>
      )}

      <div className="grid gap-6 @xl:grid-cols-3">
        <Card className="border-border/60 shadow-md @xl:col-span-2 dark:border-border/50">
          <CardHeader className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between">
            <div>
              <CardTitle className="text-xl font-semibold tracking-tight">Company details</CardTitle>
              <CardDescription>Identity, contact, and leadership</CardDescription>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="shrink-0 gap-2 rounded-full border-border/80"
              onClick={openEdit}
            >
              <PenLine className="size-4" />
              Edit profile
            </Button>
          </CardHeader>
          <CardContent className="grid gap-6 text-sm @lg:grid-cols-2">
            <div className="space-y-4">
              <div className="group rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-colors hover:border-border dark:border-border/50">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                      <MapPin className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Address</p>
                      <p className="mt-1.5 whitespace-pre-wrap text-sm font-medium leading-relaxed text-foreground">
                        {company.address?.trim() || <span className="italic text-muted-foreground">Not set</span>}
                      </p>
                    </div>
                  </div>
                  <button
                    type="button"
                    className="opacity-0 transition-opacity group-hover:opacity-100"
                    onClick={openEdit}
                    aria-label="Edit address"
                  >
                    <PenLine className="size-4 text-muted-foreground hover:text-foreground" />
                  </button>
                </div>
              </div>
              <div className="group rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                      <Hash className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Tax ID / TIN</p>
                      <p className="mt-1.5 text-sm font-medium text-foreground">
                        {company.tin?.trim() || <span className="italic text-muted-foreground">Not set</span>}
                      </p>
                    </div>
                  </div>
                  <button type="button" className="opacity-0 transition-opacity group-hover:opacity-100" onClick={openEdit} aria-label="Edit TIN">
                    <PenLine className="size-4 text-muted-foreground hover:text-foreground" />
                  </button>
                </div>
              </div>
              <div className="group rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                      <Phone className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Contact number</p>
                      <p className="mt-1.5 text-sm font-medium text-foreground">
                        {company.phone?.trim() || <span className="italic text-muted-foreground">Not set</span>}
                      </p>
                    </div>
                  </div>
                  <button type="button" className="opacity-0 transition-opacity group-hover:opacity-100" onClick={openEdit} aria-label="Edit phone">
                    <PenLine className="size-4 text-muted-foreground hover:text-foreground" />
                  </button>
                </div>
              </div>
              <div className="group rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                      <Mail className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Company email</p>
                      <p className="mt-1.5 break-all text-sm font-medium text-foreground">
                        {company.email?.trim() || <span className="italic text-muted-foreground">Not set</span>}
                      </p>
                    </div>
                  </div>
                  <button type="button" className="opacity-0 transition-opacity group-hover:opacity-100" onClick={openEdit} aria-label="Edit email">
                    <PenLine className="size-4 text-muted-foreground hover:text-foreground" />
                  </button>
                </div>
              </div>
            </div>
            <div className="space-y-4">
              <div className="group rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                      <Calendar className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Founded</p>
                      <p className="mt-1.5 text-sm font-medium text-foreground">
                        {company.founded_at ? (
                          new Date(company.founded_at).toLocaleDateString('en-PH', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                          })
                        ) : (
                          <span className="italic text-muted-foreground">Not set</span>
                        )}
                      </p>
                    </div>
                  </div>
                  <button type="button" className="opacity-0 transition-opacity group-hover:opacity-100" onClick={openEdit} aria-label="Edit founded date">
                    <PenLine className="size-4 text-muted-foreground hover:text-foreground" />
                  </button>
                </div>
              </div>
              <div className="rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <div className="flex gap-3">
                  <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60">
                    <MapPin className="size-5 text-muted-foreground" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Branch locations</p>
                    {addresses.length ? (
                      <ul className="mt-2 space-y-2 text-sm font-medium text-foreground">
                        {addresses.map((a, i) => (
                          <li key={i} className="flex gap-2 rounded-lg bg-muted/30 px-3 py-2">
                            <MapPin className="mt-0.5 size-3.5 shrink-0 text-emerald-600/80" />
                            <span className="leading-snug">{a}</span>
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="mt-2 italic text-muted-foreground">No branch addresses on file yet.</p>
                    )}
                    <p className="mt-3 text-xs text-muted-foreground">Pulled from branch records in your org structure.</p>
                  </div>
                </div>
              </div>
              <div className="rounded-2xl border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Company head</p>
                <div className="mt-3 flex items-center gap-3">
                  <Avatar className="size-11 border border-border/60 shadow-sm">
                    <AvatarImage src={profileImageUrl(headEmp?.profile_image)} alt="" />
                    <AvatarFallback className="bg-emerald-600/15 text-sm font-bold text-emerald-900 dark:bg-emerald-500/25 dark:text-emerald-100">
                      {initials(company.company_head_name || headEmp?.name || '?')}
                    </AvatarFallback>
                  </Avatar>
                  <div className="min-w-0">
                    <p className="truncate font-semibold text-foreground">
                      {company.company_head_name || headEmp?.name || (
                        <span className="italic font-normal text-muted-foreground">Not assigned</span>
                      )}
                    </p>
                    {headEmp?.email && <p className="truncate text-xs text-muted-foreground">{headEmp.email}</p>}
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-md dark:border-border/50">
          <CardHeader>
            <CardTitle className="text-xl font-semibold tracking-tight">Quick actions</CardTitle>
            <CardDescription className="text-sm">Jump to your scope — keyboard-friendly, one click each.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3">
            {[
              { label: 'View all employees', to: link('employees', { company_id: company.id }), icon: Users },
              { label: 'View branches', to: link('branches', { company_id: company.id }), icon: Network },
              { label: 'View departments', to: link('departments', { company_id: company.id }), icon: Layers },
              { label: 'Company reports', to: link('reports'), icon: FileBarChart },
            ].map((row) => {
              const Icon = row.icon
              return (
                <button
                  key={row.label}
                  type="button"
                  onClick={() => navigate(row.to)}
                  className="group flex w-full items-center justify-between gap-3 rounded-2xl border border-border/70 bg-card px-4 py-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-emerald-500/25 hover:bg-muted/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:border-border/50"
                >
                  <span className="flex min-w-0 items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">
                      <Icon className="size-5" />
                    </span>
                    <span className="truncate font-semibold text-foreground">{row.label}</span>
                  </span>
                  <ChevronRight className="size-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </button>
              )
            })}
          </CardContent>
        </Card>
      </div>

      <Card className="border-border/60 shadow-md dark:border-border/50">
        <CardHeader>
          <CardTitle className="text-xl font-semibold tracking-tight">Today&apos;s attendance</CardTitle>
          <CardDescription>Live counts for your scope · {snapshotDate}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 @sm:grid-cols-2">
          {[
            {
              label: 'Present',
              value: presentToday,
              icon: CheckCircle2,
              bar: 'border-l-emerald-500 bg-emerald-500/5',
              iconClass: 'text-emerald-600 dark:text-emerald-400',
            },
            {
              label: 'Late',
              value: lateToday,
              icon: Clock3,
              bar: 'border-l-amber-500 bg-amber-500/5',
              iconClass: 'text-amber-600 dark:text-amber-400',
            },
            {
              label: 'Absent (expected)',
              value: absentToday,
              icon: UserX,
              bar: 'border-l-rose-500 bg-rose-500/5',
              iconClass: 'text-rose-600 dark:text-rose-400',
            },
            {
              label: 'On leave',
              value: onLeave,
              icon: Umbrella,
              bar: 'border-l-sky-500 bg-sky-500/5',
              iconClass: 'text-sky-600 dark:text-sky-400',
            },
          ].map((row) => {
            const Icon = row.icon
            return (
              <div
                key={row.label}
                className={cn(
                  'flex items-center justify-between gap-3 rounded-2xl border border-border/60 border-l-4 bg-muted/20 px-4 py-4 shadow-sm',
                  row.bar
                )}
              >
                <span className="flex items-center gap-3">
                  <Icon className={cn('size-6 shrink-0', row.iconClass)} aria-hidden />
                  <span className="text-sm font-medium text-foreground">{row.label}</span>
                </span>
                <span className="text-2xl font-bold tabular-nums text-foreground">{row.value ?? '—'}</span>
              </div>
            )
          })}
        </CardContent>
      </Card>

      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="company-profile-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit company details</DialogTitle>
              <p id="company-profile-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Logo and contact information only. Company name and leadership are managed by HR.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={submitProfile} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
              <div>
                <Label>Logo</Label>
                <div className="mt-2 flex flex-wrap items-center gap-3">
                  {editLogoPreview || companyLogoUrl(company) ? (
                    <img
                      src={editLogoPreview || companyLogoUrl(company)}
                      alt=""
                      className="size-16 rounded-lg border object-cover"
                    />
                  ) : null}
                  <input
                    ref={editLogoInputRef}
                    type="file"
                    accept={LOGO_ACCEPT}
                    className="hidden"
                    onChange={(ev) => {
                      const f = ev.target.files?.[0]
                      if (!f) return
                      if (f.size > LOGO_MAX_BYTES) {
                        toast({ variant: 'destructive', title: 'File too large', description: `Max ${LOGO_MAX_SIZE_MB}MB.` })
                        return
                      }
                      setEditLogo(f)
                      setEditLogoPreview(URL.createObjectURL(f))
                    }}
                  />
                  <Button type="button" variant="outline" size="sm" onClick={() => editLogoInputRef.current?.click()}>
                    <ImagePlus className="mr-2 size-4" />
                    Change logo
                  </Button>
                </div>
              </div>
              <div>
                <Label htmlFor="co-phone">Contact number</Label>
                <Input id="co-phone" className="mt-1" value={editPhone} onChange={(e) => setEditPhone(e.target.value)} />
              </div>
              <div>
                <Label htmlFor="co-email">Company email</Label>
                <Input id="co-email" type="email" className="mt-1" value={editEmail} onChange={(e) => setEditEmail(e.target.value)} />
              </div>
              <div>
                <Label htmlFor="co-tin">Company TIN (ID number)</Label>
                <Input id="co-tin" className="mt-1" value={editTin} onChange={(e) => setEditTin(e.target.value)} placeholder="Tax identification number" />
              </div>
              <div>
                <Label htmlFor="co-addr">Company address</Label>
                <textarea
                  id="co-addr"
                  rows={3}
                  className="mt-1 flex min-h-[80px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 dark:border-border/50 dark:bg-input/30"
                  value={editAddress}
                  onChange={(e) => setEditAddress(e.target.value)}
                />
              </div>
              <div>
                <Label htmlFor="co-founded">Founded date</Label>
                <Input id="co-founded" type="date" className="mt-1" value={editFounded} onChange={(e) => setEditFounded(e.target.value)} />
              </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={editSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {editSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default function AdminCompanies() {
  const { toast } = useToast()
  const { user, loading: authLoading } = useAuth()
  const basePath = useHrBasePath()
  const navigate = useNavigate()
  const [companies, setCompanies] = useState([])
  const [allEmployees, setAllEmployees] = useState([])
  const [allBranches, setAllBranches] = useState([])
  const [allDepartments, setAllDepartments] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [dashStats, setDashStats] = useState(null)
  const [dashStatsPrev, setDashStatsPrev] = useState(null)
  const [weeklyOverview, setWeeklyOverview] = useState([])

  const hrRole = String(user?.hr_role || '').trim()
  const isAdminHr = isAdminHrUser(user)
  const isCompanyHead = hrRole === 'company_head'
  const isBranchOrDeptHead = hrRole === 'branch_head' || hrRole === 'department_head'
  const canViewCompaniesModule = (isAdminHr || isCompanyHead) && !isBranchOrDeptHead

  // Create dialog
  const [createOpen, setCreateOpen] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createLogo, setCreateLogo] = useState(null)
  const [createLogoPreview, setCreateLogoPreview] = useState(null)
  const [createLogoError, setCreateLogoError] = useState(null)
  const createLogoInputRef = useRef(null)
  const [createTin, setCreateTin] = useState('')
  const [createAddress, setCreateAddress] = useState('')
  const [createSubmitting, setCreateSubmitting] = useState(false)
  const [createLogoDragOver, setCreateLogoDragOver] = useState(false)

  // Assign head dialog
  const [headOpen, setHeadOpen] = useState(false)
  const [headCompany, setHeadCompany] = useState(null)
  const [headId, setHeadId] = useState('')
  const [headSubmitting, setHeadSubmitting] = useState(false)

  // Delete dialog
  const [deleteConfirmCompany, setDeleteConfirmCompany] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  // Edit logo dialog
  const [editLogoOpen, setEditLogoOpen] = useState(false)
  const [editLogoCompany, setEditLogoCompany] = useState(null)
  const [editLogoFile, setEditLogoFile] = useState(null)
  const [editLogoPreview, setEditLogoPreview] = useState(null)
  const [editLogoError, setEditLogoError] = useState(null)
  const [editLogoSubmitting, setEditLogoSubmitting] = useState(false)
  const editLogoInputRef = useRef(null)

  // Edit name dialog
  const [editNameOpen, setEditNameOpen] = useState(false)
  const [editNameCompany, setEditNameCompany] = useState(null)
  const [editNameValue, setEditNameValue] = useState('')
  const [editNameSubmitting, setEditNameSubmitting] = useState(false)

  // Edit details (name + address + TIN) — admin list + detail sheet
  const [editDetailsOpen, setEditDetailsOpen] = useState(false)
  const [editDetailsCompany, setEditDetailsCompany] = useState(null)
  const [editDetailsName, setEditDetailsName] = useState('')
  const [editDetailsAddress, setEditDetailsAddress] = useState('')
  const [editDetailsTin, setEditDetailsTin] = useState('')
  const [editDetailsSubmitting, setEditDetailsSubmitting] = useState(false)

  // Detail sheet state
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailCompany, setDetailCompany] = useState(null)
  const [detailTab, setDetailTab] = useState('branches')
  const [detailBranches, setDetailBranches] = useState([])
  const [detailBranchesLoading, setDetailBranchesLoading] = useState(false)
  const [detailDepts, setDetailDepts] = useState([])
  const [detailDeptsLoading, setDetailDeptsLoading] = useState(false)
  const [detailEmployees, setDetailEmployees] = useState([])
  const [detailEmployeesLoading, setDetailEmployeesLoading] = useState(false)

  // Table sort
  const [sortCol, setSortCol] = useState('name')
  const [sortDir, setSortDir] = useState('asc')

  const fetchCompanies = useCallback(async () => {
    setError(null)
    if (!canViewCompaniesModule) {
      setDashStats(null)
      setDashStatsPrev(null)
      setWeeklyOverview([])
      setCompanies([])
      setAllEmployees([])
      setAllBranches([])
      setAllDepartments([])
      setLoading(false)
      return
    }
    setLoading(true)
    try {
      const promises = [
        getCompanies(),
        getEmployees({ for_leadership_assignment: true, per_page: 'all' }).catch(() => ({ employees: [] })),
        getBranches().catch(() => ({ branches: [] })),
        getDepartments().catch(() => ({ departments: [] })),
      ]
      if (isCompanyHead) {
        promises.push(getDashboardData().catch(() => null))
      }
      const results = await Promise.all(promises)
      const companiesRes = results[0]
      const employeesRes = results[1]
      const branchesRes = results[2]
      const departmentsRes = results[3]
      const dashRes = isCompanyHead ? results[4] : null

      setCompanies(Array.isArray(companiesRes?.companies) ? companiesRes.companies : [])
      setAllEmployees(employeesRes?.employees ?? [])
      setAllBranches(Array.isArray(branchesRes?.branches) ? branchesRes.branches : [])
      setAllDepartments(Array.isArray(departmentsRes?.departments) ? departmentsRes.departments : [])
      if (isCompanyHead && dashRes) {
        setDashStats(dashRes.stats ?? null)
        setDashStatsPrev(dashRes.stats_prev ?? null)
        setWeeklyOverview(Array.isArray(dashRes.weekly_overview) ? dashRes.weekly_overview : [])
      } else {
        setDashStats(null)
        setDashStatsPrev(null)
        setWeeklyOverview([])
      }
    } catch (e) {
      setError(e.message)
      setCompanies([])
      setAllEmployees([])
      setAllBranches([])
      setAllDepartments([])
      setDashStats(null)
      setDashStatsPrev(null)
      setWeeklyOverview([])
    } finally {
      setLoading(false)
    }
  }, [canViewCompaniesModule, isCompanyHead])

  useEffect(() => {
    if (authLoading) return
    void fetchCompanies()
  }, [fetchCompanies, authLoading])

  // Load detail tab data when sheet opens or tab changes
  useEffect(() => {
    if (!detailOpen || !detailCompany) return

    if (detailTab === 'branches' || detailTab === 'employees') {
      setDetailBranchesLoading(true)
      getBranches({ company_id: detailCompany.id })
        .then((d) => setDetailBranches(d.branches || []))
        .catch(() => setDetailBranches([]))
        .finally(() => setDetailBranchesLoading(false))
    }
    if (detailTab === 'departments') {
      setDetailDeptsLoading(true)
      getDepartments({ company_id: detailCompany.id })
        .then((d) => setDetailDepts(d.departments || []))
        .catch(() => setDetailDepts([]))
        .finally(() => setDetailDeptsLoading(false))
    }
    if (detailTab === 'employees') {
      setDetailEmployeesLoading(true)
      getEmployees({ company_id: detailCompany.id, per_page: 100 })
        .then((d) => setDetailEmployees(d.employees || []))
        .catch(() => setDetailEmployees([]))
        .finally(() => setDetailEmployeesLoading(false))
    }
  }, [detailOpen, detailCompany?.id, detailTab]) // eslint-disable-line react-hooks/exhaustive-deps -- detailCompany object ref can change; id is the stable key

  const openDetail = (company, tab = null) => {
    setDetailCompany(company)
    setDetailTab(tab ?? resolveDefaultDetailTab(company))
    setDetailBranches([])
    setDetailDepts([])
    setDetailEmployees([])
    setDetailOpen(true)
  }

  const closeDetail = () => {
    setDetailOpen(false)
    setDetailCompany(null)
  }

  // Logo validation helper
  const validateLogoFile = (file, setError) => {
    if (!['image/jpeg', 'image/jpg', 'image/png', 'image/webp'].includes(file.type)) {
      setError('Please choose a JPG, PNG, or WebP image.')
      return false
    }
    if (file.size > LOGO_MAX_BYTES) {
      setError(`File must be under ${LOGO_MAX_SIZE_MB}MB.`)
      return false
    }
    return true
  }

  const applyCreateLogoFile = (file) => {
    setCreateLogoError(null)
    if (!file) {
      setCreateLogo(null)
      setCreateLogoPreview((prev) => {
        if (prev) URL.revokeObjectURL(prev)
        return null
      })
      return
    }
    if (!validateLogoFile(file, setCreateLogoError)) {
      setCreateLogo(null)
      setCreateLogoPreview((prev) => {
        if (prev) URL.revokeObjectURL(prev)
        return null
      })
      return
    }
    setCreateLogo(file)
    setCreateLogoPreview((prev) => {
      if (prev) URL.revokeObjectURL(prev)
      return URL.createObjectURL(file)
    })
  }

  const onCreateLogoChange = (e) => {
    const input = e.target
    const file = input.files?.[0]
    applyCreateLogoFile(file ?? null)
    input.value = ''
  }

  const onCreateLogoDragOver = (e) => {
    e.preventDefault()
    e.stopPropagation()
    setCreateLogoDragOver(true)
  }

  const onCreateLogoDragLeave = (e) => {
    e.preventDefault()
    e.stopPropagation()
    setCreateLogoDragOver(false)
  }

  const onCreateLogoDrop = (e) => {
    e.preventDefault()
    e.stopPropagation()
    setCreateLogoDragOver(false)
    const file = e.dataTransfer?.files?.[0]
    if (file) applyCreateLogoFile(file)
  }

  const openCreateCompanyDialog = () => {
    setCreateLogoPreview((prev) => {
      if (prev) URL.revokeObjectURL(prev)
      return null
    })
    setCreateLogo(null)
    setCreateLogoError(null)
    setCreateName('')
    setCreateTin('')
    setCreateAddress('')
    setCreateLogoDragOver(false)
    setCreateOpen(true)
  }

  const handleCreate = async (e) => {
    e.preventDefault()
    const nameError = validateCompanyName(createName)
    if (nameError) { toast({ title: 'Invalid company name', description: nameError, variant: 'error' }); return }
    setCreateSubmitting(true)
    setCreateLogoError(null)
    try {
      await createCompany({
        name: createName.trim(),
        ...(createLogo ? { logo: createLogo } : {}),
        company_head_id: undefined,
        tin: createTin.trim() || undefined,
        address: createAddress.trim() || undefined,
      })
      const savedName = createName.trim()
      setCreateName('')
      setCreateTin('')
      setCreateAddress('')
      setCreateLogo(null)
      if (createLogoPreview) URL.revokeObjectURL(createLogoPreview)
      setCreateLogoPreview(null)
      setCreateOpen(false)
      await fetchCompanies()
      toast({ title: `Company '${savedName}' created`, variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to create company', description: e.message, variant: 'error' })
    } finally {
      setCreateSubmitting(false)
    }
  }

  const openEditLogo = (company) => {
    setEditLogoCompany(company)
    setEditLogoFile(null)
    if (editLogoPreview) URL.revokeObjectURL(editLogoPreview)
    setEditLogoPreview(null)
    setEditLogoError(null)
    setEditLogoOpen(true)
  }

  const onEditLogoChange = (e) => {
    const file = e.target.files?.[0]
    setEditLogoError(null)
    if (!file) { setEditLogoFile(null); setEditLogoPreview(null); return }
    if (!validateLogoFile(file, setEditLogoError)) { setEditLogoFile(null); setEditLogoPreview(null); return }
    setEditLogoFile(file)
    setEditLogoPreview(URL.createObjectURL(file))
  }

  const handleEditLogo = async (e) => {
    e.preventDefault()
    if (!editLogoCompany || !editLogoFile) return
    setEditLogoSubmitting(true)
    setEditLogoError(null)
    try {
      const data = await updateCompany(editLogoCompany.id, { logo: editLogoFile })
      if (data.company && detailCompany?.id === editLogoCompany.id) setDetailCompany(data.company)
      setEditLogoOpen(false)
      setEditLogoCompany(null)
      setEditLogoFile(null)
      if (editLogoPreview) URL.revokeObjectURL(editLogoPreview)
      setEditLogoPreview(null)
      await fetchCompanies()
      toast({ title: 'Company logo updated', variant: 'success' })
    } catch (err) {
      setEditLogoError(err.message)
    } finally {
      setEditLogoSubmitting(false)
    }
  }

  const openHeadDialog = (company) => {
    setHeadCompany(company)
    setHeadId(company.company_head_id ? String(company.company_head_id) : '')
    setHeadOpen(true)
  }

  const handleAssignHead = async (e) => {
    e.preventDefault()
    if (!headCompany) return
    setHeadSubmitting(true)
    try {
      const companyId = headCompany.id
      await updateCompany(companyId, { company_head_id: headId ? parseInt(headId, 10) : null })
      setHeadOpen(false)
      setHeadCompany(null)
      await fetchCompanies()
      if (detailOpen && detailCompany?.id === companyId && detailTab === 'employees') {
        setDetailEmployeesLoading(true)
        try {
          const d = await getEmployees({ company_id: companyId, per_page: 100 })
          setDetailEmployees(d.employees || [])
        } catch {
          setDetailEmployees([])
        } finally {
          setDetailEmployeesLoading(false)
        }
      }
      toast({ title: 'Company head updated', variant: 'success' })
    } catch (e) {
      toast({ title: 'Cannot assign head', description: e.message, variant: 'error' })
    } finally {
      setHeadSubmitting(false)
    }
  }

  const openEditName = (company) => {
    setEditNameCompany(company)
    setEditNameValue(company.name)
    setEditNameOpen(true)
  }

  const handleEditName = async (e) => {
    e.preventDefault()
    const nameError = validateCompanyName(editNameValue)
    if (nameError) { toast({ title: 'Invalid name', description: nameError, variant: 'error' }); return }
    setEditNameSubmitting(true)
    try {
      const data = await updateCompany(editNameCompany.id, { name: editNameValue.trim() })
      if (data.company && detailCompany?.id === editNameCompany.id) setDetailCompany(data.company)
      setEditNameOpen(false)
      await fetchCompanies()
      toast({ title: 'Company name updated', variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to update name', description: e.message, variant: 'error' })
    } finally {
      setEditNameSubmitting(false)
    }
  }

  const openEditDetails = (company) => {
    setEditDetailsCompany(company)
    setEditDetailsName(company.name ?? '')
    setEditDetailsAddress(company.address ?? '')
    setEditDetailsTin(company.tin ?? '')
    setEditDetailsOpen(true)
  }

  const handleEditDetails = async (e) => {
    e.preventDefault()
    if (!editDetailsCompany) return
    const nameError = validateCompanyName(editDetailsName)
    if (nameError) {
      toast({ title: 'Invalid company name', description: nameError, variant: 'error' })
      return
    }
    setEditDetailsSubmitting(true)
    try {
      const data = await updateCompany(editDetailsCompany.id, {
        name: editDetailsName.trim(),
        tin: editDetailsTin.trim() || null,
        address: editDetailsAddress.trim() || null,
      })
      if (data.company && detailCompany?.id === editDetailsCompany.id) setDetailCompany(data.company)
      setEditDetailsOpen(false)
      setEditDetailsCompany(null)
      await fetchCompanies()
      toast({
        title: 'Company details updated',
        description: 'Name, address, and TIN are saved. Payslips will use these on the next load.',
        variant: 'success',
      })
    } catch (err) {
      toast({ title: 'Update failed', description: err.message || 'Could not save company details.', variant: 'error' })
    } finally {
      setEditDetailsSubmitting(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteConfirmCompany) return
    setDeleteSubmitting(true)
    try {
      await deleteCompany(deleteConfirmCompany.id)
      setDeleteConfirmCompany(null)
      await fetchCompanies()
      toast({ title: 'Company deleted', variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to delete company', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const toggleSort = (col) => {
    if (sortCol === col) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    else { setSortCol(col); setSortDir('asc') }
  }

  // All employees eligible for the Company Head picker (all roles=employee; conflict detection done inside the dialog)
  const assignableEmployees = useMemo(() => allEmployees.filter((e) => isRosterStaffMember(e)), [allEmployees])

  // Employees tab: grouped by role (Company Head → Branch Heads → Department Heads → Employees)
  const groupedEmployees = useMemo(() => {
    const companyHeadId = detailCompany?.company_head_id
    const companyHead = detailEmployees.find((e) => String(e.id) === String(companyHeadId))

    const bmMap = new Map() // empId -> { emp, branchName }
    for (const e of detailEmployees) {
      if (String(e.id) === String(companyHeadId)) continue
      if (e.managed_branch_id && e.managed_branch_name) {
        bmMap.set(String(e.id), { emp: e, branchName: e.managed_branch_name || '—' })
      }
    }
    for (const b of detailBranches || []) {
      if (!b.branch_manager_id || bmMap.has(String(b.branch_manager_id))) continue
      const emp = detailEmployees.find((e) => String(e.id) === String(b.branch_manager_id))
      if (emp && String(emp.id) !== String(companyHeadId)) {
        bmMap.set(String(emp.id), { emp, branchName: b.name || '—' })
      }
    }
    const branchHeads = [...bmMap.values()].sort((a, b) =>
      (a.branchName || '').localeCompare(b.branchName || '')
    )
    const branchHeadIds = new Set(branchHeads.map((x) => String(x.emp.id)))

    const rest = detailEmployees.filter(
      (e) => String(e.id) !== String(companyHeadId) && !branchHeadIds.has(String(e.id))
    )
    const departmentHeads = rest.filter((e) => e.management_role === 'department_head')
    const employees = rest.filter((e) => e.management_role !== 'department_head')

    const sortByName = (a, b) => compareEmployeesByLastName(a, b)
    departmentHeads.sort((a, b) => sortByName(a, b))
    employees.sort((a, b) => sortByName(a, b))

    return { companyHead, branchHeads, departmentHeads, employees }
  }, [detailCompany?.company_head_id, detailEmployees, detailBranches])

  const sortedCompanies = [...companies].sort((a, b) => {
    let va = a[sortCol]
    let vb = b[sortCol]
    if (sortCol === 'name') { va = (va || '').toLowerCase(); vb = (vb || '').toLowerCase() }
    if (['total_employees', 'branches_count', 'departments_count'].includes(sortCol)) {
      va = Number(va) || 0; vb = Number(vb) || 0
    }
    if (va < vb) return sortDir === 'asc' ? -1 : 1
    if (va > vb) return sortDir === 'asc' ? 1 : -1
    return 0
  })

  if (authLoading) {
    return (
      <div className="space-y-6 p-4 @md:p-6">
        <div className="grid gap-5 @sm:grid-cols-1 @lg:grid-cols-2 @2xl:grid-cols-3">
          {[...Array(3)].map((_, i) => (
            <Card key={i} className="overflow-hidden rounded-2xl border border-border/70 shadow-md dark:border-white/10">
              <CardContent className="space-y-5 p-5 @sm:p-6">
                <Skeleton className="h-36 w-full rounded-xl" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    )
  }

  if (!canViewCompaniesModule) {
    return <CompanyAccessDenied />
  }

  if (isCompanyHead) {
    return (
      <CompanyHeadOverview
        company={companies[0] ?? null}
        loading={loading}
        error={error}
        basePath={basePath}
        allBranches={allBranches}
        allEmployees={allEmployees}
        dashStats={dashStats}
        dashStatsPrev={dashStatsPrev}
        weeklyOverview={weeklyOverview}
        onRefresh={fetchCompanies}
      />
    )
  }

  return (
    <div className="min-h-0 min-w-0 max-w-full space-y-8 overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6">
      <div className="flex flex-col gap-5 pb-1 @lg:flex-row @lg:items-start @lg:justify-between">
        <div className="space-y-3">
          <h1 className="text-3xl font-extrabold tracking-tight text-foreground @md:text-4xl">Companies</h1>
          <p className="max-w-3xl text-base leading-relaxed text-muted-foreground">
            Manage your organization hierarchy. Click a card to explore branches and departments.
          </p>
        </div>
        <div className="flex w-full flex-col gap-3 @sm:w-auto @sm:flex-row @sm:items-center @sm:justify-end">
          {!loading && sortedCompanies.length > 1 ? (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" className={companyOutlineButtonClass}>
                  Sort by: {sortCol === 'name' ? 'Name' : sortCol === 'total_employees' ? 'Employees' : sortCol === 'branches_count' ? 'Branches' : 'Departments'}
                  {sortDir === 'asc' ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => toggleSort('name')}>Name</DropdownMenuItem>
                <DropdownMenuItem onClick={() => toggleSort('total_employees')}>Employees</DropdownMenuItem>
                <DropdownMenuItem onClick={() => toggleSort('branches_count')}>Branches</DropdownMenuItem>
                <DropdownMenuItem onClick={() => toggleSort('departments_count')}>Departments</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          ) : null}
          <Button className={companyPrimaryButtonClass} onClick={openCreateCompanyDialog}>
            <Plus className="size-4" />
            Add Company
          </Button>
        </div>
      </div>

      {error ? (
        <div className="rounded-xl border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>
      ) : null}

      <div className="min-w-0 space-y-5">
          {loading ? (
            <div className="grid gap-5 @lg:grid-cols-2 @2xl:grid-cols-3">
              {[...Array(6)].map((_, i) => (
                <Card key={i} className={cn(companyCardClass, 'overflow-hidden')}>
                  <CardContent className="space-y-5 p-5">
                    <div className="flex items-start gap-4">
                      <Skeleton className="size-16 shrink-0 rounded-full" />
                      <div className="flex-1 space-y-2 pt-1">
                        <Skeleton className="h-5 w-32" />
                        <Skeleton className="h-3 w-24" />
                      </div>
                    </div>
                    <Skeleton className="h-20 rounded-xl" />
                    <Skeleton className="h-20 rounded-xl" />
                    <Skeleton className="h-11 rounded-lg" />
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : sortedCompanies.length > 0 ? (
            <div className="grid gap-5 @lg:grid-cols-2 @2xl:grid-cols-3">
              {sortedCompanies.map((company, idx) => {
                const companyInitials = initials(company.name)
                const headEmp = allEmployees.find((e) => String(e.id) === String(company.company_head_id))
                const branchStackItems = buildBranchStackItems(company.id, allBranches)
                const departmentStackItems = buildDepartmentStackItems(company.id, allDepartments)
                const employeeStackItems = buildEmployeeStackItems(company.id, allEmployees, allBranches)
                return (
                  <Card
                    key={company.id}
                    tabIndex={0}
                    role="button"
                    aria-label={`View ${company.name} details`}
                    onClick={() => openDetail(company)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(company) }
                      if (e.key === 'ArrowDown' && idx < sortedCompanies.length - 1) {
                        e.preventDefault()
                        document.querySelector(`[data-company-id="${sortedCompanies[idx + 1].id}"]`)?.focus()
                      }
                      if (e.key === 'ArrowUp' && idx > 0) {
                        e.preventDefault()
                        document.querySelector(`[data-company-id="${sortedCompanies[idx - 1].id}"]`)?.focus()
                      }
                    }}
                    data-company-id={company.id}
                    className={cn(
                      companyCardClass,
                      'group cursor-pointer overflow-hidden transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 active:scale-[0.99] hover:-translate-y-0.5 hover:border-brand/35 hover:shadow-[0_18px_46px_-28px_rgba(15,23,42,0.65)] dark:hover:border-brand/35'
                    )}
                  >
                    <CardContent className="p-5">
                      <div className="flex items-start gap-4">
                        <div className="shrink-0">
                          {companyLogoUrl(company) ? (
                            <div className="flex size-16 items-center justify-center overflow-hidden rounded-full border border-border/80 bg-background shadow-inner ring-2 ring-border/30 dark:border-white/10 dark:ring-white/10">
                              <img src={companyLogoUrl(company)} alt="" className="size-full object-cover" />
                            </div>
                          ) : (
                            <div className="flex size-16 items-center justify-center rounded-full border border-brand/20 bg-brand/10 text-lg font-black text-brand ring-2 ring-brand/10">
                              {companyInitials}
                            </div>
                          )}
                        </div>
                        <div className="min-w-0 flex-1 pt-0.5">
                          <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <h3 className="truncate text-xl font-black tracking-tight text-foreground group-hover:text-brand">{company.name}</h3>
                            <Badge className="rounded-full bg-brand/10 px-2.5 py-0.5 text-[10px] font-bold text-brand shadow-none dark:bg-brand/15">Organization</Badge>
                          </div>
                          {company.created_at ? <p className="mt-1 text-xs text-muted-foreground">{formatEstablishedLabel(company.created_at)}</p> : null}
                        </div>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                            <Button variant="ghost" size="icon" className="size-9 shrink-0 rounded-full text-muted-foreground hover:bg-muted/80 hover:text-foreground" aria-label="Company actions">
                              <MoreVertical className="size-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem onClick={() => openDetail(company)}><Building className="size-4" /><span>View details</span></DropdownMenuItem>
                            <DropdownMenuItem onClick={(e) => { e.stopPropagation(); openEditDetails(company) }}><FileText className="size-4" /><span>Edit details</span></DropdownMenuItem>
                            <DropdownMenuItem onClick={() => openEditName(company)}><Pencil className="size-4" /><span>Rename</span></DropdownMenuItem>
                            <DropdownMenuItem onClick={() => openHeadDialog(company)}><UserPlus className="size-4" /><span>Assign head</span></DropdownMenuItem>
                            <DropdownMenuItem onClick={() => openEditLogo(company)}><ImagePlus className="size-4" /><span>Change logo</span></DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" onClick={() => setDeleteConfirmCompany(company)}><Trash2 className="size-4" /><span>Delete</span></DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>

                      <div className="mt-4 rounded-xl border border-border/60 bg-background/60 px-4 py-3 dark:border-white/10 dark:bg-background/30" onClick={(e) => e.stopPropagation()}>
                        {company.company_head_name ? (
                          <div className="flex items-center gap-3">
                            <Avatar className="size-11 shrink-0 rounded-full border-2 border-background shadow-sm ring-1 ring-border/60 dark:ring-white/10">
                              <AvatarImage src={profileImageUrl(headEmp?.profile_image)} className="object-cover" />
                              <AvatarFallback className="bg-brand/10 text-xs font-bold text-brand dark:bg-brand/15">{initials(company.company_head_name)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                              <p className="text-xs text-muted-foreground">Company head</p>
                              <p className="truncate text-sm font-semibold text-foreground">{company.company_head_name}</p>
                            </div>
                          </div>
                        ) : (
                          <div className="flex items-center gap-3">
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-full border border-brand/20 bg-brand/10 text-brand dark:bg-brand/15">
                              <UserPlus className="size-5" />
                            </div>
                            <div className="min-w-0 flex-1">
                              <p className="text-xs text-muted-foreground">Company head</p>
                              <p className="text-sm text-muted-foreground">No head assigned yet</p>
                              <button type="button" onClick={(e) => { e.stopPropagation(); openHeadDialog(company) }} className="text-sm font-bold text-brand hover:underline">Assign head</button>
                            </div>
                          </div>
                        )}
                      </div>

                      <div className="mt-4 grid grid-cols-3 divide-x divide-border/70 rounded-xl border border-border/60 bg-background/60 dark:divide-white/10 dark:border-white/10 dark:bg-background/30">
                        <button type="button" onClick={(e) => { e.stopPropagation(); navigate(`/admin/branches?company_id=${company.id}`) }} className="px-3 py-3 text-center hover:bg-muted/35">
                          <p className="text-2xl font-black tabular-nums text-foreground">{Number(company.branches_count) || 0}</p>
                          <OrgProfileStack items={branchStackItems} max={3} className="mt-1" />
                          <p className="text-[10px] font-bold uppercase text-muted-foreground">{Number(company.branches_count) === 1 ? 'Branch' : 'Branches'}</p>
                        </button>
                        <button type="button" onClick={(e) => { e.stopPropagation(); navigate(`/admin/departments?company_id=${company.id}`) }} className="px-3 py-3 text-center hover:bg-muted/35">
                          <p className="text-2xl font-black tabular-nums text-foreground">{Number(company.departments_count) || 0}</p>
                          <OrgProfileStack items={departmentStackItems} max={3} className="mt-1" />
                          <p className="text-[10px] font-bold uppercase text-muted-foreground">Departments</p>
                        </button>
                        <button type="button" onClick={(e) => { e.stopPropagation(); openDetail(company, 'employees') }} className="px-3 py-3 text-center hover:bg-muted/35">
                          <p className="text-2xl font-black tabular-nums text-brand">{Number(company.total_employees) || 0}</p>
                          <OrgProfileStack items={employeeStackItems} max={3} className="mt-1" />
                          <p className="text-[10px] font-bold uppercase text-muted-foreground">Employees</p>
                        </button>
                      </div>

                      <Button
                        className="mt-4 h-11 w-full justify-between rounded-lg bg-muted/45 px-4 text-base font-semibold text-foreground hover:bg-brand/10 hover:text-brand dark:bg-muted/25"
                        onClick={(e) => { e.stopPropagation(); openDetail(company) }}
                      >
                        <span className="inline-flex items-center gap-2"><Eye className="size-4" /> View organization</span>
                        <ArrowRight className="size-4 text-brand" />
                      </Button>
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          ) : (
            <Card className="border-dashed border-2 dark:border-white/10">
              <CardContent className="flex flex-col items-center justify-center px-8 py-20 text-center">
                <div className="mb-5 flex size-20 items-center justify-center rounded-2xl bg-brand/10 text-brand dark:bg-brand/15">
                  <Building2 className="size-10" />
                </div>
                <h3 className="text-xl font-bold text-foreground">No companies yet</h3>
                <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                  Start organizing your organization. Companies are the top level - add one to create branches, departments, and assign employees.
                </p>
                <Button className={cn(companyPrimaryButtonClass, 'mt-6')} onClick={openCreateCompanyDialog}>
                  <Plus className="size-4" />
                  Add Company
                </Button>
              </CardContent>
            </Card>
          )}

          {!loading && sortedCompanies.length > 0 ? (
            <button
              type="button"
              onClick={openCreateCompanyDialog}
              className="flex min-h-20 w-full items-center justify-center gap-3 rounded-[18px] border border-dashed border-border/90 bg-background/40 text-base font-bold text-brand transition hover:border-brand hover:bg-brand/10 dark:border-white/15 dark:bg-background/20"
            >
              <Plus className="size-4" />
              Add New Company
            </button>
          ) : null}
        </div>
      {/* ── Create Company ── */}
      <Dialog
        open={createOpen}
        onOpenChange={(open) => {
          setCreateOpen(open)
          if (!open) {
            setCreateLogoPreview((prev) => {
              if (prev) URL.revokeObjectURL(prev)
              return null
            })
            setCreateLogo(null)
            setCreateLogoError(null)
            setCreateName('')
            setCreateTin('')
            setCreateAddress('')
            setCreateLogoDragOver(false)
          }
        }}
      >
        <DialogContent
          showCloseButton
          innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0"
          className={cn('admin-add-company-dialog', adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG))}
          aria-describedby="company-create-desc"
        >
          {createSubmitting ? (
            <div className="flex min-h-0 flex-1 flex-col">
              <div className="shrink-0 px-6 pt-6 pb-3">
                <Skeleton className="h-6 w-48 rounded-md" />
              </div>
              <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 pb-6">
                <div className="flex gap-3">
                  <Skeleton className="size-11 shrink-0 rounded-full" />
                  <div className="flex-1 space-y-2">
                    <Skeleton className="h-3 w-full" />
                    <Skeleton className="h-3 w-4/5" />
                  </div>
                </div>
                <div className="space-y-2">
                  <Skeleton className="h-4 w-28" />
                  <Skeleton className="h-12 w-full rounded-xl" />
                  <Skeleton className="h-3 w-full" />
                </div>
                <div className="space-y-2">
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-12 w-full rounded-xl" />
                </div>
                <div className="space-y-2">
                  <Skeleton className="h-4 w-48" />
                  <Skeleton className="h-12 w-full rounded-xl" />
                </div>
                <Skeleton className="h-32 w-full rounded-xl" />
                <div className="flex justify-end gap-2 pt-2">
                  <Skeleton className="h-12 w-24 rounded-lg" />
                  <Skeleton className="h-12 w-40 rounded-lg" />
                </div>
              </div>
            </div>
          ) : (
            <form onSubmit={handleCreate} className="flex min-h-0 flex-1 flex-col">
              <div className="shrink-0 px-6 pt-6 pb-3 pr-14">
                <DialogHeader className="p-0 text-left">
                  <DialogTitle className="hr-dialog-title">Add New Company</DialogTitle>
                </DialogHeader>
              </div>
              <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-6 pb-6">
                <div className="mb-5 flex gap-3 rounded-xl border border-border/60 bg-muted/25 px-4 py-3.5 dark:border-white/10 dark:bg-white/5">
                  <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand/12 text-brand dark:bg-brand/18">
                    <Building2 className="size-5" aria-hidden />
                  </div>
                  <p id="company-create-desc" className="text-sm leading-relaxed text-muted-foreground">
                    Create a new company to organize branches and departments. Company heads can oversee all branches and approve organization changes.
                  </p>
                </div>
                <div className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="create-name" className="text-foreground">
                      Company Name <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="create-name"
                      value={createName}
                      onChange={(e) => setCreateName(e.target.value)}
                      placeholder="e.g. Acme Corporation"
                      className="h-12 rounded-[0.875rem] shadow-sm"
                      autoComplete="organization"
                    />
                    <p className="text-xs text-muted-foreground">Letters, numbers, spaces, hyphens, and apostrophes only.</p>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="create-address" className="text-foreground">
                      Company Address{' '}
                      <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                      id="create-address"
                      value={createAddress}
                      onChange={(e) => setCreateAddress(e.target.value)}
                      placeholder="Registered business address"
                      className="h-12 rounded-[0.875rem] shadow-sm"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="create-tin" className="text-foreground">
                      Company TIN (ID number){' '}
                      <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                      id="create-tin"
                      value={createTin}
                      onChange={(e) => setCreateTin(e.target.value)}
                      placeholder="e.g. 123-456-789-000"
                      className="h-12 rounded-[0.875rem] shadow-sm"
                      autoComplete="off"
                    />
                  </div>
                  <div className="space-y-2">
                    <div>
                      <Label className="text-foreground">
                        Company Logo{' '}
                        <span className="font-normal text-muted-foreground">(optional)</span>
                      </Label>
                      <p className="mt-1 text-xs text-muted-foreground">This logo will represent the company across the system.</p>
                    </div>
                    <div
                      onDragOver={onCreateLogoDragOver}
                      onDragLeave={onCreateLogoDragLeave}
                      onDrop={onCreateLogoDrop}
                      className={cn(
                        'rounded-xl border-2 border-dashed transition-colors',
                        createLogoDragOver
                          ? 'border-brand bg-brand/10 dark:border-brand dark:bg-brand/15'
                          : 'border-border bg-muted/15 dark:border-white/15 dark:bg-white/5'
                      )}
                    >
                      <input
                        ref={createLogoInputRef}
                        id="create-logo-input"
                        type="file"
                        accept={LOGO_ACCEPT}
                        className="sr-only"
                        onChange={onCreateLogoChange}
                      />
                      <button
                        type="button"
                        className={cn(
                          'flex w-full cursor-pointer flex-col items-center justify-center gap-2 px-4 py-10 text-center transition-colors hover:bg-muted/25 dark:hover:bg-white/8',
                          createLogoDragOver && 'bg-transparent'
                        )}
                        onClick={() => createLogoInputRef.current?.click()}
                      >
                        {createLogoPreview ? (
                          <div className="flex flex-col items-center gap-3">
                            <img src={createLogoPreview} alt="" className="h-16 w-16 rounded-xl border border-border object-cover dark:border-white/10" />
                            <p className="text-sm font-medium text-foreground">
                              <span className="text-brand">Click to replace</span>
                              <span className="text-muted-foreground"> or drag and drop</span>
                            </p>
                          </div>
                        ) : (
                          <>
                            <Upload className="size-8 text-muted-foreground" strokeWidth={1.5} aria-hidden />
                            <p className="text-sm text-foreground">
                              <span className="font-semibold text-brand">Click to upload</span>{' '}
                              <span className="text-muted-foreground">or drag and drop</span>
                            </p>
                            <p className="text-xs text-muted-foreground">PNG, JPG, WebP up to 2MB</p>
                          </>
                        )}
                      </button>
                    </div>
                    {createLogoPreview ? (
                      <button
                        type="button"
                        className="mt-2 text-xs font-semibold text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        onClick={() => {
                          applyCreateLogoFile(null)
                          if (createLogoInputRef.current) createLogoInputRef.current.value = ''
                        }}
                      >
                        Remove file
                      </button>
                    ) : null}
                    {createLogoError ? <p className="text-xs text-destructive">{createLogoError}</p> : null}
                  </div>
                </div>
              </div>
              <DialogFooter className="shrink-0 gap-3 border-t border-border/50 bg-muted/10 px-6 py-4 dark:border-white/10 dark:bg-muted/10">
                <Button type="button" variant="outline" className={cn(companyOutlineButtonClass, 'h-11 min-w-[100px]')} onClick={() => setCreateOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" className={cn(companyPrimaryButtonClass, 'h-11 min-w-[160px] px-5')} disabled={createSubmitting}>
                  {createSubmitting ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <>
                      Create Company
                      <ArrowRight className="size-4" />
                    </>
                  )}
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* ── Edit Name ── */}
      <Dialog open={editNameOpen} onOpenChange={setEditNameOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_SM)}
          aria-describedby="company-rename-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Rename Company</DialogTitle>
              <p id="company-rename-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>{editNameCompany?.name}</p>
            </DialogHeader>
          </div>
          <form onSubmit={handleEditName} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div>
              <Label htmlFor="edit-name">New Name *</Label>
              <Input id="edit-name" value={editNameValue} onChange={(e) => setEditNameValue(e.target.value)} className="mt-1" />
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditNameOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={editNameSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {editNameSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* ── Edit details (name, address, TIN) ── */}
      <Dialog open={editDetailsOpen} onOpenChange={(open) => { setEditDetailsOpen(open); if (!open) setEditDetailsCompany(null) }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="company-edit-details-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit company details</DialogTitle>
              <p id="company-edit-details-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Optional fields improve payslip accuracy. Registered address and TIN appear on employee payslips.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleEditDetails} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
              <div>
                <Label htmlFor="edit-details-name">Company name *</Label>
                <Input
                  id="edit-details-name"
                  value={editDetailsName}
                  onChange={(e) => setEditDetailsName(e.target.value)}
                  className="mt-1"
                  autoComplete="organization"
                />
                <p className="mt-1 text-xs text-muted-foreground">Letters, numbers, spaces, hyphens, and apostrophes only.</p>
              </div>
              <div>
                <Label htmlFor="edit-details-address">Company address</Label>
                <textarea
                  id="edit-details-address"
                  rows={3}
                  value={editDetailsAddress}
                  onChange={(e) => setEditDetailsAddress(e.target.value)}
                  placeholder="Registered business address (optional)"
                  className="mt-1 flex min-h-[80px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 dark:border-border/50 dark:bg-input/30"
                />
              </div>
              <div>
                <Label htmlFor="edit-details-tin">Company TIN (ID number)</Label>
                <Input
                  id="edit-details-tin"
                  value={editDetailsTin}
                  onChange={(e) => setEditDetailsTin(e.target.value)}
                  placeholder="e.g. 123-456-789-000 (optional)"
                  className="mt-1"
                  autoComplete="off"
                />
              </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditDetailsOpen(false)}>
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={editDetailsSubmitting}
                className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'bg-[#0A0A0A] text-white hover:bg-[#0A0A0A]/90 dark:bg-[#0A0A0A] dark:text-white dark:hover:bg-[#0A0A0A]/90')}
              >
                {editDetailsSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* ── Assign Head ── */}
      <AssignHeadDialog
        open={headOpen}
        onOpenChange={setHeadOpen}
        company={headCompany}
        headId={headId}
        onHeadIdChange={setHeadId}
        employees={assignableEmployees}
        companies={companies}
        onSubmit={handleAssignHead}
        submitting={headSubmitting}
      />

      {/* ── Edit Logo ── */}
      <Dialog open={editLogoOpen} onOpenChange={(open) => { setEditLogoOpen(open); if (!open) setEditLogoCompany(null) }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="company-logo-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>
                {companyLogoUrl(editLogoCompany) ? 'Change company logo' : 'Add company logo'}
              </DialogTitle>
              <p id="company-logo-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>{editLogoCompany?.name}</p>
            </DialogHeader>
          </div>
          <form onSubmit={handleEditLogo} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div>
              <Label>Logo</Label>
              <div className="mt-1 flex items-center gap-3">
                <input ref={editLogoInputRef} type="file" accept={LOGO_ACCEPT} className="hidden" onChange={onEditLogoChange} />
                <Button type="button" variant="outline" size="sm" onClick={() => editLogoInputRef.current?.click()}>
                  {editLogoPreview ? 'Change' : 'Choose'} image
                </Button>
                {(editLogoPreview || companyLogoUrl(editLogoCompany)) && (
                  <img src={editLogoPreview || companyLogoUrl(editLogoCompany)} alt="" className="h-14 w-14 rounded-lg object-cover border border-border" />
                )}
              </div>
              {editLogoError && <p className="mt-1 text-xs text-destructive">{editLogoError}</p>}
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditLogoOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={!editLogoFile || editLogoSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {editLogoSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Update'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* ── Delete confirm ── */}
      <Dialog open={!!deleteConfirmCompany} onOpenChange={(open) => !open && setDeleteConfirmCompany(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="company-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete Company</DialogTitle>
              <p id="company-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Delete &quot;{deleteConfirmCompany?.name}&quot;? This action cannot be undone. Deletion will fail if the company has branches — remove them first.
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button type="button" variant="outline" onClick={() => setDeleteConfirmCompany(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Company Detail Sheet (single deep-dive; list card stays summary-only) ── */}
      <Sheet open={detailOpen} onOpenChange={(open) => { if (!open) closeDetail() }}>
        <SheetContent
          side="right"
          showCloseButton={false}
          className="flex w-full flex-col gap-0 overflow-hidden border-l border-border/60 bg-background p-0 sm:max-w-xl dark:border-slate-700 dark:bg-slate-950"
        >
          {detailCompany && (
            <>
              {/* Top bar: breadcrumb + overflow + close */}
              <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border/50 bg-muted/15 px-4 py-3 dark:bg-slate-900/80">
                <nav className="flex min-w-0 items-center gap-1.5 text-sm" aria-label="Breadcrumb">
                  <button
                    type="button"
                    onClick={() => navigate('/admin/companies')}
                    className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                  >
                    Companies
                  </button>
                  <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                  <span className="truncate font-semibold text-foreground">{detailCompany.name}</span>
                </nav>
                <div className="flex shrink-0 items-center gap-0.5">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="size-9 text-muted-foreground" aria-label="More actions">
                        <MoreVertical className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                      <DropdownMenuItem onClick={() => openEditDetails(detailCompany)}>
                        <FileText className="size-4" /><span>Edit details</span>
                      </DropdownMenuItem>
                      <DropdownMenuItem onClick={() => openEditName(detailCompany)}>
                        <Pencil className="size-4" /><span>Rename company</span>
                      </DropdownMenuItem>
                      <DropdownMenuItem onClick={() => openHeadDialog(detailCompany)}>
                        <UserPlus className="size-4" /><span>Assign head</span>
                      </DropdownMenuItem>
                      <DropdownMenuItem onClick={() => openEditLogo(detailCompany)}>
                        <ImagePlus className="size-4" /><span>Change logo</span>
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" onClick={() => setDeleteConfirmCompany(detailCompany)}>
                        <Trash2 className="size-4" /><span>Delete company</span>
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                  <SheetClose asChild>
                    <Button variant="ghost" size="icon" className="size-9 text-muted-foreground" aria-label="Close panel">
                      <X className="size-4" />
                    </Button>
                  </SheetClose>
                </div>
              </div>

              {/* Hero: identity + primary CTAs (Add branch first for empty orgs) */}
              <div className="shrink-0 space-y-4 border-b border-border/40 px-6 pb-5 pt-5">
                <div className="flex flex-col gap-5 @sm:flex-row @sm:items-start @sm:justify-between">
                  <div className="flex min-w-0 gap-4">
                    <div className="shrink-0">
                      {companyLogoUrl(detailCompany) ? (
                        <div className="flex size-20 items-center justify-center overflow-hidden rounded-full border border-border/70 bg-muted/30 shadow-sm ring-2 ring-border/20 dark:ring-white/10">
                          <img src={companyLogoUrl(detailCompany)} alt="" className="size-full object-cover" />
                        </div>
                      ) : (
                        <div className="flex size-20 items-center justify-center rounded-full border border-border/70 bg-linear-to-br from-primary/20 to-primary/5 text-xl font-bold text-primary shadow-inner ring-2 ring-primary/10">
                          {initials(detailCompany.name)}
                        </div>
                      )}
                    </div>
                    <div className="min-w-0 pt-0.5">
                      <SheetTitle className="text-2xl font-bold tracking-tight text-foreground">{detailCompany.name}</SheetTitle>
                      <SheetDescription className="sr-only">
                        Organization details for {detailCompany.name}
                      </SheetDescription>
                      <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        {detailCompany.company_head_name ? (
                          <>
                            <span className="text-muted-foreground">Head</span>
                            <Badge variant="secondary" className="h-6 rounded-full border border-sky-200/80 bg-sky-100/90 px-2.5 text-xs font-semibold text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-100">
                              {detailCompany.company_head_name}
                            </Badge>
                          </>
                        ) : (
                          <>
                            <span>No head assigned</span>
                            <button
                              type="button"
                              onClick={() => openHeadDialog(detailCompany)}
                              className="font-semibold text-primary hover:underline"
                            >
                              Assign head
                            </button>
                          </>
                        )}
                      </div>
                      {detailCompany.created_at && (
                        <p className="mt-1.5 text-xs text-muted-foreground">{formatEstablishedLabel(detailCompany.created_at)}</p>
                      )}
                    </div>
                  </div>
                  <div className="flex w-full flex-col gap-2 @sm:w-auto @sm:min-w-[200px] @sm:items-end">
                    <Button
                      className="h-10 w-full gap-2 font-semibold shadow-sm @sm:w-full"
                      onClick={() => navigate(`/admin/branches?company_id=${detailCompany.id}`)}
                    >
                      <Plus className="size-4 shrink-0" />
                      Add branch
                    </Button>
                    <div className="flex w-full gap-2">
                      <Button type="button" variant="outline" className="h-9 flex-1 gap-1.5 text-xs font-medium" onClick={() => openEditLogo(detailCompany)}>
                        <ImagePlus className="size-3.5 shrink-0" />
                        Logo
                      </Button>
                      <Button type="button" variant="outline" className="h-9 flex-1 gap-1.5 text-xs font-medium" onClick={() => openHeadDialog(detailCompany)}>
                        <UserPlus className="size-3.5 shrink-0" />
                        Head
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            </>
          )}

          <Tabs value={detailTab} onValueChange={setDetailTab} className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <TabsList className="mx-6 mt-4 flex h-auto w-full min-w-0 max-w-full flex-none flex-row gap-1 rounded-xl border border-border/50 bg-muted/50 p-1 dark:bg-muted/25">
              <TabsTrigger
                value="branches"
                className="relative flex min-h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium shadow-none ring-0 transition-colors after:hidden hover:text-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm data-[state=inactive]:text-muted-foreground @sm:min-h-11 @sm:gap-2 @sm:px-3 @sm:text-sm"
              >
                <span className="flex min-w-0 items-center justify-center gap-1.5 @sm:gap-2">
                  <MapPin className="size-3.5 shrink-0 opacity-70" aria-hidden />
                  <span className="truncate">Branches</span>
                  <span
                    className={cn(
                      'inline-flex min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums @sm:min-w-6 @sm:text-xs',
                      (detailCompany?.branches_count ?? 0) === 0
                        ? 'bg-muted/90 text-muted-foreground'
                        : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'
                    )}
                  >
                    {detailCompany?.branches_count ?? 0}
                  </span>
                </span>
              </TabsTrigger>
              <TabsTrigger
                value="departments"
                className="relative flex min-h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium shadow-none ring-0 transition-colors after:hidden hover:text-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm data-[state=inactive]:text-muted-foreground @sm:min-h-11 @sm:gap-2 @sm:px-3 @sm:text-sm"
              >
                <span className="flex min-w-0 items-center justify-center gap-1.5 @sm:gap-2">
                  <Building2 className="size-3.5 shrink-0 opacity-70" aria-hidden />
                  <span className="truncate">Depts</span>
                  <span
                    className={cn(
                      'inline-flex min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums @sm:min-w-6 @sm:text-xs',
                      (detailCompany?.departments_count ?? 0) === 0
                        ? 'bg-muted/90 text-muted-foreground'
                        : 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300'
                    )}
                  >
                    {detailCompany?.departments_count ?? 0}
                  </span>
                </span>
              </TabsTrigger>
              <TabsTrigger
                value="employees"
                className="relative flex min-h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium shadow-none ring-0 transition-colors after:hidden hover:text-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm data-[state=inactive]:text-muted-foreground @sm:min-h-11 @sm:gap-2 @sm:px-3 @sm:text-sm"
              >
                <span className="flex min-w-0 items-center justify-center gap-1.5 @sm:gap-2">
                  <Users2 className="size-3.5 shrink-0 opacity-70" aria-hidden />
                  <span className="truncate">People</span>
                  <span
                    className={cn(
                      'inline-flex min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums @sm:min-w-6 @sm:text-xs',
                      (detailCompany?.total_employees ?? 0) === 0
                        ? 'bg-muted/90 text-muted-foreground'
                        : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-300'
                    )}
                  >
                    {detailCompany?.total_employees ?? 0}
                  </span>
                </span>
              </TabsTrigger>
            </TabsList>

            {detailCompany?.id ? (
              <div className="border-b border-border/60 px-6 py-4">
                <LeadershipPositionsSection
                  legacyType="company"
                  legacyId={detailCompany.id}
                  employeeOptions={allEmployees}
                  canManage
                />
              </div>
            ) : null}

            {/* Branches tab */}
            <TabsContent value="branches" className="flex-1 overflow-y-auto px-6 py-4 mt-0">
              <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h3 className="text-base font-semibold text-foreground">
                  Branches
                  <span className="ml-1.5 text-sm font-normal text-muted-foreground">({detailBranches.length})</span>
                </h3>
                <Button
                  size="sm"
                  variant="outline"
                  className="font-medium"
                  onClick={() => navigate(`/admin/branches?company_id=${detailCompany?.id}`)}
                >
                  <ExternalLink className="size-3.5 mr-1.5" />
                  Open in Branches
                </Button>
              </div>
              {detailBranchesLoading ? (
                <div className="space-y-3 py-2">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="rounded-xl border border-border/50 p-4 space-y-2">
                      <Skeleton className="h-4 w-2/3" />
                      <Skeleton className="h-3 w-full" />
                      <Skeleton className="h-3 w-1/2" />
                    </div>
                  ))}
                </div>
              ) :               detailBranches.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border/60 bg-muted/15 px-6 py-12 text-center dark:bg-slate-900/40">
                  <MapPin className="mx-auto size-12 text-muted-foreground/40" strokeWidth={1.25} />
                  <p className="mt-4 text-lg font-semibold text-foreground">No branches yet</p>
                  <p className="mt-2 max-w-sm mx-auto text-sm text-muted-foreground leading-relaxed">
                    Branches represent offices or locations. Add one to organize departments under this company.
                  </p>
                  <Button className="mt-6 gap-2 shadow-sm" onClick={() => navigate(`/admin/branches?company_id=${detailCompany?.id}`)}>
                    <Plus className="size-4" />
                    Add branch
                  </Button>
                </div>
              ) : (
                <ul className="space-y-3">
                  {detailBranches.map((b) => (
                    <li
                      key={b.id}
                      className="group rounded-xl border border-border/60 bg-card px-4 py-4 transition-all hover:border-border hover:bg-muted/20 hover:shadow-md cursor-pointer"
                      onClick={() => navigate(`/admin/departments?branch_id=${b.id}`)}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0 flex-1">
                          <p className="text-base font-bold text-foreground">{b.name}</p>
                          {b.address && (
                            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                              <MapPin className="size-3 shrink-0" />{b.address}
                            </p>
                          )}
                          <div className="mt-2 flex items-center gap-2">
                            {b.branch_manager_name ? (
                              <>
                                <Avatar className="size-7 shrink-0 rounded-full border border-border/50">
                                  <AvatarImage src={profileImageUrl(b.branch_manager_profile_image)} />
                                  <AvatarFallback className="text-[10px] font-semibold bg-muted text-muted-foreground">
                                    {initials(b.branch_manager_name)}
                                  </AvatarFallback>
                                </Avatar>
                                <span className="text-sm text-foreground">{b.branch_manager_name}</span>
                                <span className="text-[10px] font-medium text-muted-foreground rounded-full bg-muted/60 px-1.5 py-0.5">Branch Head</span>
                              </>
                            ) : (
                              <span className="text-sm text-muted-foreground flex items-center gap-1">
                                <UserCircle className="size-3.5" />No manager assigned
                              </span>
                            )}
                          </div>
                          <div className="mt-2 flex items-center gap-3 text-[12px]">
                            <span className="flex items-center gap-1 text-emerald-600 dark:text-green-400 font-medium">
                              <Users className="size-3.5" />
                              {b.employees_count ?? 0} Employees
                            </span>
                            <span className="text-muted-foreground">·</span>
                            <span className="flex items-center gap-1 text-purple-600 dark:text-purple-400 font-medium">
                              <Building2 className="size-3.5" />
                              {b.departments_count ?? 0} Department{(b.departments_count ?? 0) !== 1 ? 's' : ''}
                            </span>
                          </div>
                        </div>
                        <button
                          type="button"
                          onClick={(e) => { e.stopPropagation(); navigate(`/admin/departments?branch_id=${b.id}`) }}
                          className="shrink-0 rounded-md p-2 text-muted-foreground opacity-0 transition-all hover:bg-muted hover:text-foreground group-hover:opacity-100"
                          title="View departments"
                        >
                          <ExternalLink className="size-4" />
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </TabsContent>

            {/* Departments tab */}
            <TabsContent value="departments" className="flex-1 overflow-y-auto px-6 py-4 mt-0">
              <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h3 className="text-base font-semibold text-foreground">
                  Departments
                  <span className="ml-1.5 text-sm font-normal text-muted-foreground">({detailDepts.length})</span>
                </h3>
                <Button
                  size="sm"
                  variant="outline"
                  className="font-medium"
                  onClick={() => navigate(`/admin/departments?company_id=${detailCompany?.id}`)}
                >
                  <ExternalLink className="size-3.5 mr-1.5" />
                  Open in Departments
                </Button>
              </div>
              {detailDeptsLoading ? (
                <div className="space-y-3 py-2">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="rounded-xl border border-border/50 p-4 flex gap-3">
                      <Skeleton className="h-8 w-8 rounded-lg shrink-0" />
                      <div className="flex-1 space-y-2">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-3 w-full" />
                      </div>
                    </div>
                  ))}
                </div>
              ) :               detailDepts.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border/60 bg-muted/15 px-6 py-12 text-center dark:bg-slate-900/40">
                  <Building2 className="mx-auto size-12 text-muted-foreground/40" strokeWidth={1.25} />
                  <p className="mt-4 text-lg font-semibold text-foreground">No departments yet</p>
                  <p className="mt-2 max-w-sm mx-auto text-sm text-muted-foreground leading-relaxed">
                    Departments live under branches. Add a branch first, then create departments for teams and reporting lines.
                  </p>
                  <Button variant="outline" className="mt-6 font-medium" onClick={() => navigate(`/admin/branches?company_id=${detailCompany?.id}`)}>
                    <Plus className="size-4 mr-1.5" />
                    Go add a branch
                  </Button>
                </div>
              ) : (
                <ul className="space-y-3">
                  {detailDepts.map((d) => {
                    const logoUrl = departmentLogoUrl(d)
                    return (
                    <li
                      key={d.id}
                      className="group rounded-xl border border-border/60 bg-card px-4 py-4 transition-all hover:border-border hover:bg-muted/20 hover:shadow-md cursor-pointer"
                      onClick={() => navigate(`/admin/departments?branch_id=${d.branch_id}`)}
                    >
                      <div className="flex items-start gap-3">
                        {logoUrl ? (
                          <img src={logoUrl} alt="" className="h-10 w-10 rounded-lg object-cover border border-border/40 shrink-0" />
                        ) : (
                          <div className="flex h-10 w-10 items-center justify-center rounded-lg border border-border/40 bg-muted/60 shrink-0">
                            <span className="text-[10px] font-bold text-muted-foreground">{initials(d.name)}</span>
                          </div>
                        )}
                        <div className="min-w-0 flex-1">
                          <p className="text-base font-bold text-foreground">{d.name}</p>
                          {d.branch_name && (
                            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                              <Building2 className="size-3 shrink-0" />
                              {d.branch_name}
                            </p>
                          )}
                          <div className="mt-2 flex items-center gap-2">
                            {d.department_head_name ? (
                              <>
                                <Avatar className="size-7 shrink-0 rounded-full border border-border/50">
                                  <AvatarImage src={profileImageUrl(d.department_head_profile_image)} />
                                  <AvatarFallback className="text-[10px] font-semibold bg-muted text-muted-foreground">
                                    {initials(d.department_head_name)}
                                  </AvatarFallback>
                                </Avatar>
                                <span className="text-sm text-foreground">{d.department_head_name}</span>
                                <span className="text-[10px] font-medium text-muted-foreground rounded-full bg-muted/60 px-1.5 py-0.5">Department Head</span>
                              </>
                            ) : (
                              <span className="text-sm text-muted-foreground flex items-center gap-1">
                                <UserCircle className="size-3.5" />No head assigned
                              </span>
                            )}
                          </div>
                          <div className="mt-2 flex items-center gap-1 text-[12px] font-medium text-emerald-600 dark:text-green-400">
                            <Users className="size-3.5" />
                            {d.total_employees ?? 0} Employee{(d.total_employees ?? 0) !== 1 ? 's' : ''}
                          </div>
                        </div>
                      </div>
                    </li>
                    )
                  })}
                </ul>
              )}
            </TabsContent>

            {/* Employees tab */}
            <TabsContent value="employees" className="flex-1 overflow-y-auto px-6 py-4 mt-0">
              <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h3 className="text-base font-semibold text-foreground">
                  Employees
                  <span className="ml-1.5 text-sm font-normal text-muted-foreground">({detailEmployees.length})</span>
                </h3>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="font-medium"
                  onClick={() => navigate('/admin/employees')}
                >
                  <UserPlus className="size-3.5 mr-1.5" />
                  Add employee
                </Button>
              </div>
              {detailEmployeesLoading ? (
                <div className="space-y-2 py-2">
                  {[1, 2, 3, 4, 5].map((i) => (
                    <div key={i} className="flex items-center gap-3 rounded-lg px-2 py-2">
                      <Skeleton className="size-8 rounded-full shrink-0" />
                      <div className="flex-1 space-y-1">
                        <Skeleton className="h-4 w-1/3" />
                        <Skeleton className="h-3 w-1/4" />
                      </div>
                    </div>
                  ))}
                </div>
              ) : detailEmployees.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border/60 bg-muted/20 dark:bg-slate-800/30 px-6 py-10 text-center">
                  <UserCheck className="mx-auto size-10 text-muted-foreground/50" />
                  <p className="mt-2 font-medium text-foreground">No employees yet</p>
                  <p className="mt-1 text-sm text-muted-foreground">Assign employees to branches or departments from the Employees page.</p>
                  <Button size="sm" variant="outline" className="mt-4" onClick={() => navigate('/admin/employees')}>
                    <ExternalLink className="size-3.5 mr-1.5" />View employees
                  </Button>
                </div>
              ) : (
                <div className="space-y-6">
                  {!detailCompany?.company_head_id && (
                    <div className="rounded-lg border border-amber-200/60 bg-amber-50/50 dark:bg-amber-950/20 dark:border-amber-800/50 px-4 py-2.5 flex items-center gap-2">
                      <Crown className="size-4 text-amber-600 dark:text-amber-500 shrink-0" />
                      <p className="text-sm font-medium text-amber-800 dark:text-amber-200">No Company Head assigned</p>
                    </div>
                  )}

                  {(() => {
                    const renderEmployeeCard = (emp, branchName) => {
                      const displayRole = emp.management_role ?? (emp.id === detailCompany?.company_head_id ? 'company_head' : branchName ? 'branch_head' : null)
                      return (
                        <div
                          key={emp.id}
                          className="group flex items-start gap-4 rounded-xl border border-border/60 bg-card p-4 transition-all hover:border-border hover:bg-muted/20 hover:shadow-sm cursor-pointer"
                          onClick={() => navigate(`/admin/employees/${emp.id}`)}
                        >
                          <Avatar className="size-10 shrink-0 rounded-full border-2 border-border/50">
                            <AvatarImage src={profileImageUrl(emp.profile_image)} />
                            <AvatarFallback className="text-xs font-bold bg-muted text-muted-foreground">{initials(emp.name)}</AvatarFallback>
                          </Avatar>
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                              <p className="font-semibold text-[15px] text-foreground">{emp.name}</p>
                              <RoleBadge management_role={displayRole} variant="soft" />
                              {branchName && (
                                <span className="text-[11px] text-muted-foreground">– {branchName}</span>
                              )}
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                              {emp.position || '—'}
                            </p>
                            {(emp.branch_name || emp.department) && (
                              <div className="mt-2 flex flex-col gap-0.5 text-[12px] text-muted-foreground">
                                {emp.branch_name && (
                                  <span className="flex items-center gap-1.5">
                                    <MapPin className="size-3 shrink-0" />
                                    {emp.branch_name}
                                  </span>
                                )}
                                {emp.department && (
                                  <span className="flex items-center gap-1.5">
                                    <Building2 className="size-3 shrink-0" />
                                    {emp.department}
                                  </span>
                                )}
                              </div>
                            )}
                          </div>
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                              <Button variant="ghost" size="icon" className="size-8 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                <MoreVertical className="size-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                              <DropdownMenuItem onClick={(e) => { e.stopPropagation(); navigate(`/admin/employees/${emp.id}`) }}>
                                <Eye className="mr-2 size-4" />View profile
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      )
                    }

                    const Section = ({ Icon, title, items, mapper }) => {
                      if (!items?.length) return null
                      const IconEl = Icon
                      return (
                        <div className="space-y-3">
                          <div className="flex items-center gap-2">
                            <IconEl className="size-4 text-muted-foreground" />
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</h4>
                          </div>
                          <div className="space-y-2">{items.map(mapper)}</div>
                        </div>
                      )
                    }

                    return (
                      <>
                        <Section
                          Icon={Crown}
                          title="Company Head"
                          items={groupedEmployees.companyHead ? [groupedEmployees.companyHead] : []}
                          mapper={(emp) => renderEmployeeCard(emp, null)}
                        />
                        <Section
                          Icon={Building}
                          title="Branch Heads"
                          items={groupedEmployees.branchHeads}
                          mapper={({ emp, branchName }) => renderEmployeeCard(emp, branchName)}
                        />
                        <Section
                          Icon={Building2}
                          title="Department Heads"
                          items={groupedEmployees.departmentHeads}
                          mapper={(emp) => renderEmployeeCard(emp, null)}
                        />
                        <Section
                          Icon={Users}
                          title="Employees"
                          items={groupedEmployees.employees}
                          mapper={(emp) => renderEmployeeCard(emp, null)}
                        />
                      </>
                    )
                  })()}
                </div>
              )}
            </TabsContent>
          </Tabs>
        </SheetContent>
      </Sheet>
    </div>
  )
}
