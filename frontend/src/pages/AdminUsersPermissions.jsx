import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import {
  Shield,
  ShieldCheck,
  Users,
  Search,
  Loader2,
  KeyRound,
  History,
  Save,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  ChevronDown,
  ChevronRight,
  UserPlus,
  Pencil,
  UserX,
  RotateCcw,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import { RoleBadge } from '@/components/RoleBadge'
import { FIELD_SELECT_CLASS_H10 } from '@/lib/fieldClasses'
import {
  getAdminUserAccounts,
  createAdminUserAccount,
  updateAdminUserAccount,
  resetAdminUserAccountPassword,
  getAdminUserAccountActivity,
  getRbacMatrix,
  syncRbacRolePermissions,
  resetRbacRoleToDefaults,
  getCompanies,
  getBranches,
  getDepartments,
  bulkUpdateAdminUserAccounts,
  profileImageUrl,
} from '@/api'

const HR_ROLE_OPTIONS = [
  { value: 'admin_hr', label: 'ADMIN (HR) only (no head assignment)' },
  { value: 'company_head', label: 'COMPANY HEAD' },
  { value: 'branch_head', label: 'BRANCH HEAD' },
  { value: 'department_head', label: 'DEPARTMENT HEAD' },
  { value: 'employee', label: 'EMPLOYEE' },
]

/** Map API user row to form fields for combined Admin (HR) + org head roles. */
function deriveUserFormRoles(u) {
  const isHrAdmin = u.role === 'admin' || u.role === 'super_admin' || u.is_hr_admin === true
  if (Array.isArray(u.hr_roles) && u.hr_roles.length > 0) {
    const orgOnly = u.hr_roles.filter((r) => r !== 'admin_hr')
    const base = orgOnly[0] ?? (isHrAdmin ? 'admin_hr' : 'employee')
    return { is_hr_admin: isHrAdmin, hr_role: base }
  }
  return { is_hr_admin: isHrAdmin, hr_role: u.hr_role ?? 'employee' }
}

/** Collapsible groups (maps `module` from API to section). Optional `hint` shows under the section header when expanded. */
const PERMISSION_SECTIONS = [
  { id: 'dashboard', label: 'Dashboard', modules: ['dashboard'] },
  {
    id: 'employees',
    label: 'Employee Management',
    modules: ['employees', 'benefits', 'documents', 'notifications'],
  },
  {
    id: 'attendance',
    label: 'Attendance & Daily Computation',
    modules: ['attendance'],
    hint: 'Includes attendance monitoring, create/delete DTR corrections, and Approve attendance corrections (multi-level filing with remarks; Admin HR final approval).',
  },
  { id: 'overtime', label: 'Overtime Management', modules: ['overtime'] },
  { id: 'leave', label: 'Leave Management', modules: ['leave'] },
  { id: 'holiday_schedule', label: 'Holiday & Schedule', modules: ['holiday', 'schedule'] },
  { id: 'reports', label: 'Reports', modules: ['reports'] },
  { id: 'payroll', label: 'Payroll Core', modules: ['payroll'] },
  {
    id: 'compensation',
    label: 'Compensation',
    modules: ['compensation'],
    hint: 'Controls pay cycles, pay components, employee compensation assignment, and payroll proration settings.',
  },
  {
    id: 'government_deductions',
    label: 'Government Deductions',
    modules: ['government_deductions'],
    hint: 'Controls statutory rates, contribution previews, compliance audit access, and remittance workflows.',
  },
  { id: 'profile', label: 'Profile', modules: ['profile'], hint: 'Personal/contact/employment details and profile picture.' },
  { id: 'users_rbac', label: 'Users & Permissions', modules: ['users', 'rbac'] },
  { id: 'organization', label: 'Company & Branch Settings', modules: ['organization'] },
]

const ACTION_KEYS = ['view', 'create', 'edit', 'delete', 'manage', 'assign', 'approve', 'audit', 'export', 'import', 'sensitive']

const ACTION_LABELS = {
  view: 'View',
  create: 'Create',
  edit: 'Edit',
  delete: 'Delete',
  manage: 'Manage',
  assign: 'Assign',
  approve: 'Approve',
  audit: 'Audit',
  export: 'Export',
  import: 'Import',
  sensitive: 'Sensitive data',
}

const ACTION_TOOLTIPS = {
  view: 'Read-only access to lists and screens in this area.',
  create: 'Create new records.',
  edit: 'Modify existing records or operational settings.',
  delete: 'Remove records permanently.',
  manage: 'Full administrative control for the module or resource.',
  assign: 'Assign or override records for specific employees or scopes.',
  approve: 'Approve or reject workflow items.',
  audit: 'Run compliance, preview, or audit-specific actions.',
  export: 'Download or export data.',
  import: 'Upload or bulk-import data.',
  sensitive: 'Access salary, IDs, and other restricted HR data.',
}

const CRITICAL_PERMS = new Set(['rbac.manage', 'users.manage', 'employees.sensitive'])

function tabClass(active) {
  return cn(
    'flex w-full items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-all sm:w-auto',
    active
      ? 'bg-background text-foreground shadow-sm ring-1 ring-border/80 dark:bg-card'
      : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
  )
}

function inferActionKey(slug) {
  if (slug === 'view-my-schedule') return 'view'
  if (slug === 'request-schedule') return 'create'
  if (slug === 'approve-schedule') return 'approve'
  if (slug === 'manage-schedules') return 'manage'
  const last = slug.split('.').pop() || ''
  if (['view', 'list'].includes(last)) return 'view'
  if (last === 'create') return 'create'
  if (last === 'manage') return 'manage'
  if (last === 'assign') return 'assign'
  if (['audit', 'prorate'].includes(last)) return last === 'audit' ? 'audit' : 'manage'
  if (
    [
      'edit',
      'update',
      'manage',
      'hours',
      'notes',
      'compute',
      'policies',
      'transfer',
      'password_reset',
      'assign',
      'catalog',
    ].includes(last) ||
    last.endsWith('_hours')
  ) {
    return 'edit'
  }
  if (last === 'delete') return 'delete'
  if (['approve', 'review'].includes(last)) return 'approve'
  if (last === 'export') return 'export'
  if (last === 'import') return 'import'
  if (last === 'sensitive') return 'sensitive'
  if (last === 'audit') return 'view'
  if (last === 'payroll') return 'view'
  return 'view'
}

function sectionForModule(module, slug) {
  // Some deployments may have stale/missing `permission.module`; fall back to slug prefix.
  if (String(slug || '').startsWith('profile.')) {
    return (
      PERMISSION_SECTIONS.find((s) => s.id === 'profile') ?? { id: 'other', label: 'Other', modules: [] }
    )
  }

  const m = module || 'other'
  const found = PERMISSION_SECTIONS.find((s) => s.modules.includes(m))
  return found ?? { id: 'other', label: 'Other', modules: [] }
}

function serializeSet(s) {
  return JSON.stringify([...(s ?? new Set())].sort())
}

function UserAvatar({ name, url }) {
  const initials =
    name
      ?.split(/\s+/)
      .map((s) => s[0])
      .join('')
      .slice(0, 2)
      .toUpperCase() || '?'
  const src = url ? profileImageUrl(url) : undefined
  return src ? (
    <img
      src={src}
      alt=""
      className="size-9 shrink-0 rounded-full border-2 border-border/80 object-cover shadow-sm"
    />
  ) : (
    <div className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 border-border/60 bg-muted text-xs font-semibold text-muted-foreground shadow-sm">
      {initials}
    </div>
  )
}

function PermSwitch({ on, disabled, onToggle, title }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      title={title}
      disabled={disabled}
      onClick={() => {
        if (!disabled) onToggle?.()
      }}
      className={cn(
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border shadow-inner transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        disabled && 'cursor-not-allowed opacity-60',
        !disabled && 'cursor-pointer',
        on
          ? 'border-emerald-700/35 bg-emerald-600 hover:bg-emerald-600/90 dark:border-emerald-500/40 dark:bg-emerald-600'
          : 'border-slate-300/90 bg-slate-200 hover:bg-slate-300/90 dark:border-white/15 dark:bg-muted dark:hover:bg-muted/90'
      )}
    >
      <span
        className={cn(
          'pointer-events-none absolute left-0.5 top-1/2 size-4 -translate-y-1/2 rounded-full bg-white shadow-md ring-1 ring-black/15 transition-transform dark:shadow-sm dark:ring-white/20',
          on ? 'translate-x-[1.375rem]' : 'translate-x-0'
        )}
      />
    </button>
  )
}

export default function AdminUsersPermissions() {
  const { toast } = useToast()
  const { user } = useAuth()
  const [tab, setTab] = useState('users')

  const perms = new Set(user?.permissions ?? [])
  const canRbacMatrix = user?.role === 'admin' || user?.role === 'super_admin' || perms.has('rbac.manage')

  return (
    <div className="w-full max-w-none space-y-8 bg-white p-4 md:p-6 dark:bg-background">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex gap-4">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-border/60 bg-muted/50 shadow-xs dark:bg-muted/30">
            <ShieldCheck className="size-6 text-primary" aria-hidden />
          </div>
          <div className="min-w-0 space-y-1.5">
            <h1 className="hr-page-title">Users &amp; Permissions</h1>
            <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
              Manage accounts, roles, and access. Sensitive changes are audited and retained for compliance.
            </p>
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div
          className="inline-flex w-full flex-col gap-1 rounded-xl border border-border/60 bg-muted/30 p-1 shadow-xs sm:w-auto sm:flex-row sm:items-center"
          role="tablist"
          aria-label="Module sections"
        >
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'users'}
            className={tabClass(tab === 'users')}
            onClick={() => setTab('users')}
          >
            <Users className="size-4 shrink-0 opacity-80" />
            Users
          </button>
          {canRbacMatrix && (
            <button
              type="button"
              role="tab"
              aria-selected={tab === 'roles'}
              className={tabClass(tab === 'roles')}
              onClick={() => setTab('roles')}
            >
              <Shield className="size-4 shrink-0 opacity-80" />
              Roles &amp; permissions
            </button>
          )}
        </div>
      </div>

      {tab === 'users' || !canRbacMatrix ? <UsersTab toast={toast} /> : <RolesTab toast={toast} />}
    </div>
  )
}

function UsersTab({ toast }) {
  const { user } = useAuth()
  const canManageUsers = user?.role === 'admin' || user?.role === 'super_admin' || (user?.permissions ?? []).includes('users.manage')
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [q, setQ] = useState('')
  const [hrRole, setHrRole] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [activeFilter, setActiveFilter] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState(() => new Set())

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    is_hr_admin: false,
    hr_role: 'employee',
    company_id: '',
    branch_id: '',
    department_id: '',
    is_active: true,
  })
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [saving, setSaving] = useState(false)

  const [pwOpen, setPwOpen] = useState(false)
  const [pwUser, setPwUser] = useState(null)
  const [newPassword, setNewPassword] = useState('')

  const [logOpen, setLogOpen] = useState(false)
  const [logUser, setLogUser] = useState(null)
  const [logs, setLogs] = useState([])
  const [logsLoading, setLogsLoading] = useState(false)

  const [deactivateTarget, setDeactivateTarget] = useState(null)
  const [bulkConfirm, setBulkConfirm] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getAdminUserAccounts({
        q: q.trim() || undefined,
        hr_role: hrRole || undefined,
        department_id: departmentId || undefined,
        is_active: activeFilter === '' ? undefined : activeFilter === '1',
        page,
        per_page: 15,
      })
      setRows(data.users ?? [])
      setMeta(data.meta ?? { current_page: 1, last_page: 1, total: 0 })
      setSelected(new Set())
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [q, hrRole, departmentId, activeFilter, page, toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    getCompanies()
      .then((d) => setCompanies(Array.isArray(d) ? d : d?.companies ?? []))
      .catch(() => {})
    getBranches()
      .then((d) => setBranches(Array.isArray(d) ? d : d?.branches ?? []))
      .catch(() => {})
    getDepartments()
      .then((d) => setDepartments(Array.isArray(d) ? d : d?.departments ?? []))
      .catch(() => {})
  }, [])

  const pageIds = useMemo(() => rows.map((r) => r.id), [rows])
  const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id))

  const adminHrBranchesFiltered = useMemo(() => {
    if (!form.company_id) return branches
    return branches.filter((b) => String(b.company_id) === String(form.company_id))
  }, [branches, form.company_id])

  const adminHrDepartmentsFiltered = useMemo(() => {
    if (form.branch_id) {
      return departments.filter((d) => String(d.branch_id) === String(form.branch_id))
    }
    if (form.company_id) {
      const branchIds = new Set(
        branches.filter((b) => String(b.company_id) === String(form.company_id)).map((b) => b.id)
      )
      return departments.filter((d) => branchIds.has(d.branch_id))
    }
    return departments
  }, [departments, branches, form.company_id, form.branch_id])

  const organizationalRoleOptions = useMemo(() => {
    if (!form.is_hr_admin) {
      return HR_ROLE_OPTIONS.filter((o) => o.value !== 'admin_hr')
    }
    return HR_ROLE_OPTIONS
  }, [form.is_hr_admin])

  function toggleSelect(id) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function toggleSelectAll() {
    if (allSelected) {
      setSelected((prev) => {
        const next = new Set(prev)
        pageIds.forEach((id) => next.delete(id))
        return next
      })
    } else {
      setSelected((prev) => {
        const next = new Set(prev)
        pageIds.forEach((id) => next.add(id))
        return next
      })
    }
  }

  function openCreate() {
    setEditing(null)
    setForm({
      name: '',
      email: '',
      password: '',
      is_hr_admin: false,
      hr_role: 'employee',
      company_id: '',
      branch_id: '',
      department_id: '',
      is_active: true,
    })
    setDialogOpen(true)
  }

  function openEdit(u) {
    setEditing(u)
    const { is_hr_admin: isHr, hr_role: baseRole } = deriveUserFormRoles(u)
    setForm({
      name: u.name ?? '',
      email: u.email ?? '',
      password: '',
      is_hr_admin: isHr,
      hr_role: baseRole,
      company_id: u.company_id != null ? String(u.company_id) : '',
      branch_id: u.branch_id != null ? String(u.branch_id) : '',
      department_id: u.department_id != null ? String(u.department_id) : '',
      is_active: !!u.is_active,
    })
    setDialogOpen(true)
  }

  async function submitForm() {
    setSaving(true)
    try {
      let hrRole = form.hr_role
      let isHrAdmin = form.is_hr_admin
      if (!isHrAdmin && hrRole === 'admin_hr') {
        hrRole = 'employee'
      }
      if (hrRole === 'admin_hr') {
        isHrAdmin = true
      }
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        hr_role: hrRole,
        is_hr_admin: isHrAdmin,
        is_active: form.is_active,
        company_id: form.company_id ? Number(form.company_id) : null,
        branch_id: form.branch_id ? Number(form.branch_id) : null,
        department_id: form.department_id ? Number(form.department_id) : null,
      }
      if (!editing) {
        payload.password = form.password
        await createAdminUserAccount(payload)
        toast({ title: 'User created' })
      } else {
        await updateAdminUserAccount(editing.id, payload)
        toast({ title: 'User updated' })
      }
      setDialogOpen(false)
      load()
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  async function openLogs(u) {
    setLogUser(u)
    setLogOpen(true)
    setLogsLoading(true)
    try {
      const list = await getAdminUserAccountActivity(u.id)
      setLogs(list)
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
      setLogs([])
    } finally {
      setLogsLoading(false)
    }
  }

  async function submitPassword() {
    if (!pwUser || newPassword.length < 8) {
      toast({ title: 'Password must be at least 8 characters', variant: 'destructive' })
      return
    }
    try {
      await resetAdminUserAccountPassword(pwUser.id, newPassword)
      toast({ title: 'Password reset' })
      setPwOpen(false)
      setNewPassword('')
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    }
  }

  async function confirmDeactivate() {
    if (!deactivateTarget) return
    try {
      await updateAdminUserAccount(deactivateTarget.id, { is_active: false })
      toast({ title: 'User deactivated' })
      setDeactivateTarget(null)
      load()
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    }
  }

  async function runBulk(action) {
    const ids = [...selected]
    if (ids.length === 0) return
    try {
      await bulkUpdateAdminUserAccounts({ action, user_ids: ids })
      toast({ title: action === 'activate' ? 'Users activated' : 'Users deactivated' })
      setBulkConfirm(null)
      load()
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    }
  }

  function fmtLastLogin(iso) {
    if (!iso) return '—'
    try {
      return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    } catch {
      return '—'
    }
  }

  return (
    <div className="space-y-6">
      <Card className="overflow-hidden border-border/60 shadow-sm">
        <CardHeader className="flex flex-col gap-4 space-y-0 border-b border-border/50 bg-gradient-to-b from-muted/40 to-transparent px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
          <div className="space-y-1">
            <CardTitle className="text-lg font-semibold tracking-tight">User directory</CardTitle>
            <CardDescription className="text-sm leading-relaxed">
              Search and filter by role, department, or status. HR role controls panel access and data scope.
            </CardDescription>
          </div>
          {canManageUsers && (
            <Button type="button" className="h-10 shrink-0 gap-2 shadow-xs" onClick={openCreate}>
              <UserPlus className="size-4" />
              Add user
            </Button>
          )}
        </CardHeader>
        <CardContent className="space-y-5 px-4 pb-6 pt-5 sm:px-6">
          <div className="rounded-xl border border-border/50 bg-muted/20 p-4 dark:bg-muted/10">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div className="sm:col-span-2 lg:col-span-1">
                <label className="mb-1.5 block text-xs font-medium text-muted-foreground">Search</label>
                <div className="relative">
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    className="h-10 border-border/60 bg-background pl-9 shadow-xs dark:bg-input/30"
                    placeholder="Name, email, employee ID…"
                    value={q}
                    onChange={(e) => {
                      setQ(e.target.value)
                      setPage(1)
                    }}
                  />
                </div>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-muted-foreground">Role</label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'min-w-0')}
                  value={hrRole || '__all__'}
                  onChange={(e) => {
                    const v = e.target.value
                    setHrRole(v === '__all__' ? '' : v)
                    setPage(1)
                  }}
                >
                  <option value="__all__">All roles</option>
                  {HR_ROLE_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-muted-foreground">Department</label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'min-w-0')}
                  value={departmentId || '__all__'}
                  onChange={(e) => {
                    const v = e.target.value
                    setDepartmentId(v === '__all__' ? '' : v)
                    setPage(1)
                  }}
                >
                  <option value="__all__">All departments</option>
                  {departments.map((d) => (
                    <option key={d.id} value={String(d.id)}>
                      {d.name}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-muted-foreground">Status</label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'min-w-0')}
                  value={activeFilter === '' ? '__all__' : activeFilter}
                  onChange={(e) => {
                    const v = e.target.value
                    setActiveFilter(v === '__all__' ? '' : v)
                    setPage(1)
                  }}
                >
                  <option value="__all__">All statuses</option>
                  <option value="1">Active</option>
                  <option value="0">Inactive</option>
                </select>
              </div>
            </div>
          </div>

          {canManageUsers && selected.size > 0 && (
            <div className="flex flex-col gap-3 rounded-xl border border-primary/20 bg-primary/[0.04] p-4 dark:bg-primary/10 sm:flex-row sm:items-center sm:justify-between">
              <span className="text-sm text-muted-foreground">
                <span className="font-semibold text-foreground">{selected.size}</span> selected
              </span>
              <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="secondary" onClick={() => setBulkConfirm('activate')}>
                  Activate
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setBulkConfirm('deactivate')}>
                  Deactivate
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                  Clear selection
                </Button>
              </div>
            </div>
          )}

          <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-sm">
                <thead className="border-b border-border/60 bg-muted/40 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  <tr>
                    {canManageUsers && (
                      <th className="w-11 px-3 py-3 pl-4">
                        <Checkbox
                          checked={allSelected}
                          onCheckedChange={toggleSelectAll}
                          aria-label="Select all on this page"
                        />
                      </th>
                    )}
                    <th className="w-14 px-2 py-3 pl-3" aria-label="Photo" />
                    <th className="min-w-[140px] px-3 py-3">Name</th>
                    <th className="min-w-[100px] px-3 py-3">Employee ID</th>
                    <th className="min-w-[180px] px-3 py-3">Email</th>
                    <th className="min-w-[120px] px-3 py-3">Department</th>
                    <th className="min-w-[120px] px-3 py-3">Role</th>
                    <th className="min-w-[88px] px-3 py-3">Status</th>
                    <th className="min-w-[140px] px-3 py-3">Last login</th>
                    <th className="w-[1%] whitespace-nowrap px-3 py-3 pr-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/50">
                  {loading ? (
                    <tr>
                      <td colSpan={canManageUsers ? 10 : 9} className="px-3 py-16 text-center text-muted-foreground">
                        <Loader2 className="mx-auto size-6 animate-spin" />
                      </td>
                    </tr>
                  ) : rows.length === 0 ? (
                    <tr>
                      <td colSpan={canManageUsers ? 10 : 9} className="px-3 py-14 text-center text-muted-foreground">
                        No users match your filters.
                      </td>
                    </tr>
                  ) : (
                    rows.map((u, idx) => (
                      <tr
                        key={u.id}
                        className={cn(
                          'transition-colors hover:bg-muted/30',
                          idx % 2 === 1 ? 'bg-muted/[0.35] dark:bg-muted/10' : ''
                        )}
                      >
                        {canManageUsers && (
                          <td className="px-3 py-2.5 align-middle pl-4">
                            <Checkbox
                              checked={selected.has(u.id)}
                              onCheckedChange={() => toggleSelect(u.id)}
                              aria-label={`Select ${u.name}`}
                            />
                          </td>
                        )}
                        <td className="px-2 py-2.5 pl-3 align-middle">
                          <UserAvatar name={u.name} url={u.profile_image_url} />
                        </td>
                        <td className="px-3 py-2.5 align-middle font-medium text-foreground">{u.name}</td>
                        <td className="px-3 py-2.5 align-middle tabular-nums text-muted-foreground">
                          {u.employee_code || '—'}
                        </td>
                        <td className="max-w-[220px] truncate px-3 py-2.5 align-middle text-muted-foreground">{u.email}</td>
                        <td className="px-3 py-2.5 align-middle text-muted-foreground">{u.department_name || '—'}</td>
                        <td className="px-3 py-2.5 align-middle">
                          <div className="flex flex-col gap-0.5">
                            <div className="flex flex-wrap gap-1">
                              {(Array.isArray(u.hr_roles) && u.hr_roles.length > 0
                                ? u.hr_roles.map((key, i) => ({
                                    key,
                                    label: u.hr_roles_labels?.[i] ?? null,
                                  }))
                                : [{ key: u.hr_role, label: u.hr_role_label }]
                              ).map(({ key, label }) => (
                                <RoleBadge key={`${u.id}-${key}`} hr_role={key} hr_role_label={label} size="sm" />
                              ))}
                            </div>
                            {u.hr_admin_scope_label ? (
                              <span className="text-[11px] text-muted-foreground" title="HR admin org scope">
                                Scope: {u.hr_admin_scope_label}
                              </span>
                            ) : null}
                          </div>
                        </td>
                        <td className="px-3 py-2.5 align-middle">
                          {u.is_active ? (
                            <Badge
                              variant="outline"
                              className="border-emerald-500/30 bg-emerald-500/10 font-normal text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/15 dark:text-emerald-200"
                            >
                              <CheckCircle2 className="mr-1 size-3" aria-hidden />
                              Active
                            </Badge>
                          ) : (
                            <Badge
                              variant="outline"
                              className="border-destructive/30 bg-destructive/5 font-normal text-destructive"
                            >
                              <XCircle className="mr-1 size-3" aria-hidden />
                              Inactive
                            </Badge>
                          )}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2.5 align-middle text-xs text-muted-foreground">
                          {fmtLastLogin(u.last_login_at)}
                        </td>
                        <td className="px-3 py-2.5 pr-4 align-middle">
                          <div className="flex items-center justify-end gap-0.5">
                            {canManageUsers && (
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 text-foreground"
                                title="Edit user"
                                onClick={() => openEdit(u)}
                              >
                                <Pencil className="size-4" />
                              </Button>
                            )}
                            {canManageUsers && (
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                title="Reset password"
                                onClick={() => {
                                  setPwUser(u)
                                  setNewPassword('')
                                  setPwOpen(true)
                                }}
                              >
                                <KeyRound className="size-4" />
                              </Button>
                            )}
                            {canManageUsers && u.is_active && (
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                title="Deactivate user"
                                onClick={() => setDeactivateTarget(u)}
                              >
                                <UserX className="size-4" />
                              </Button>
                            )}
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="size-8"
                              title="Activity log"
                              onClick={() => openLogs(u)}
                            >
                              <History className="size-4" />
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

          {meta.last_page > 1 && (
            <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
              <span>
                Page {meta.current_page} of {meta.last_page} ({meta.total} total)
              </span>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit user' : 'Add user'}</DialogTitle>
            <DialogDescription>
              Enable <strong className="font-medium text-foreground">Admin (HR)</strong> for full HR module access. You can
              combine it with a company, branch, or department head assignment (or plain employee). Head roles require a
              matching org unit. For <strong className="font-medium text-foreground">Admin (HR) only</strong>, optional
              organization fields are informational context only and do not restrict Admin (HR) access.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-3 py-2">
            <div className="grid gap-2">
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
            </div>
            <div className="grid gap-2">
              <Label>Email</Label>
              <Input
                type="email"
                value={form.email}
                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              />
            </div>
            {!editing && (
              <div className="grid gap-2">
                <Label>Password</Label>
                <Input
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                />
              </div>
            )}
            <div className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/15 px-3 py-2">
              <Checkbox
                id="is-hr-admin"
                checked={form.is_hr_admin}
                onCheckedChange={(c) => {
                  const on = !!c
                  setForm((f) => {
                    const next = { ...f, is_hr_admin: on }
                    if (!on && f.hr_role === 'admin_hr') {
                      next.hr_role = 'employee'
                      next.company_id = ''
                      next.branch_id = ''
                      next.department_id = ''
                    }
                    return next
                  })
                }}
              />
              <Label htmlFor="is-hr-admin" className="cursor-pointer font-normal leading-snug">
                Admin (HR) — full HR privileges (can combine with organizational role below)
              </Label>
            </div>
            <div className="grid gap-2">
              <Label>Organizational role</Label>
              <select
                className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                value={organizationalRoleOptions.some((o) => o.value === form.hr_role) ? form.hr_role : 'employee'}
                onChange={(e) => {
                  const v = e.target.value
                  setForm((f) => {
                    const next = { ...f, hr_role: v }
                    if (v === 'admin_hr') {
                      next.is_hr_admin = true
                      next.branch_id = ''
                      next.department_id = ''
                    }
                    if (v === 'employee') {
                      next.company_id = ''
                      next.branch_id = ''
                      next.department_id = ''
                    } else if (v === 'company_head') {
                      next.branch_id = ''
                      next.department_id = ''
                    } else if (v === 'branch_head') {
                      next.company_id = ''
                      next.department_id = ''
                    } else if (v === 'department_head') {
                      next.company_id = ''
                      next.branch_id = ''
                    }
                    return next
                  })
                }}
              >
                {organizationalRoleOptions.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
            {form.hr_role === 'company_head' && (
              <div className="grid gap-2">
                <Label>Company</Label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                  value={form.company_id || ''}
                  onChange={(e) => setForm((f) => ({ ...f, company_id: e.target.value }))}
                >
                  <option value="">—</option>
                  {companies.map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {form.hr_role === 'branch_head' && (
              <div className="grid gap-2">
                <Label>Branch</Label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                  value={form.branch_id || ''}
                  onChange={(e) => setForm((f) => ({ ...f, branch_id: e.target.value }))}
                >
                  <option value="">—</option>
                  {branches.map((b) => (
                    <option key={b.id} value={String(b.id)}>
                      {b.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {form.hr_role === 'department_head' && (
              <div className="grid gap-2">
                <Label>Department</Label>
                <select
                  className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                  value={form.department_id || ''}
                  onChange={(e) => setForm((f) => ({ ...f, department_id: e.target.value }))}
                >
                  <option value="">—</option>
                  {departments.map((d) => (
                    <option key={d.id} value={String(d.id)}>
                      {d.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {form.hr_role === 'admin_hr' && (
              <div className="grid gap-2 rounded-md border border-border/60 bg-muted/20 p-3">
                <p className="text-xs text-muted-foreground">
                  Optional organization context for display/reporting. Admin (HR) access remains full admin scope even
                  when company, branch, or department is selected here.
                </p>
                <div className="grid gap-2">
                  <Label>Company (optional)</Label>
                  <select
                    className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                    value={form.company_id || ''}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        company_id: e.target.value,
                        branch_id: '',
                        department_id: '',
                      }))
                    }
                  >
                    <option value="">— Global (no company filter) —</option>
                    {companies.map((c) => (
                      <option key={c.id} value={String(c.id)}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="grid gap-2">
                  <Label>Branch (optional)</Label>
                  <select
                    className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                    value={form.branch_id || ''}
                    onChange={(e) =>
                      setForm((f) => ({
                        ...f,
                        branch_id: e.target.value,
                        department_id: '',
                      }))
                    }
                  >
                    <option value="">—</option>
                    {adminHrBranchesFiltered.map((b) => (
                      <option key={b.id} value={String(b.id)}>
                        {b.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="grid gap-2">
                  <Label>Department (optional)</Label>
                  <select
                    className={cn(FIELD_SELECT_CLASS_H10, 'w-full')}
                    value={form.department_id || ''}
                    onChange={(e) => setForm((f) => ({ ...f, department_id: e.target.value }))}
                  >
                    <option value="">—</option>
                    {adminHrDepartmentsFiltered.map((d) => (
                      <option key={d.id} value={String(d.id)}>
                        {d.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            )}
            <div className="flex items-center gap-2 pt-1">
              <Checkbox
                id="active"
                checked={form.is_active}
                onCheckedChange={(c) => setForm((f) => ({ ...f, is_active: !!c }))}
              />
              <Label htmlFor="active" className="font-normal">
                Active account
              </Label>
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button type="button" disabled={saving} onClick={submitForm}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={pwOpen} onOpenChange={setPwOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reset password</DialogTitle>
            <DialogDescription>Set a new password for {pwUser?.name}.</DialogDescription>
          </DialogHeader>
          <Input
            type="password"
            value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)}
            placeholder="New password (min 8 characters)"
          />
          <DialogFooter>
            <Button type="button" onClick={submitPassword}>
              Update password
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={logOpen} onOpenChange={setLogOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Activity — {logUser?.name}</DialogTitle>
            <DialogDescription>Recent admin actions on this account.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[320px] overflow-y-auto text-sm">
            {logsLoading ? (
              <Loader2 className="mx-auto size-6 animate-spin" />
            ) : logs.length === 0 ? (
              <p className="text-muted-foreground">No activity recorded yet.</p>
            ) : (
              <ul className="space-y-2">
                {logs.map((log) => (
                  <li key={log.id} className="rounded-md border px-3 py-2">
                    <div className="font-medium">{log.action}</div>
                    <div className="text-xs text-muted-foreground">
                      {log.actor?.name} · {log.created_at ? new Date(log.created_at).toLocaleString() : ''}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={!!deactivateTarget} onOpenChange={() => setDeactivateTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Deactivate user?</DialogTitle>
            <DialogDescription>
              {deactivateTarget?.name} will no longer be able to sign in until reactivated.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => setDeactivateTarget(null)}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" onClick={confirmDeactivate}>
              Deactivate
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!bulkConfirm} onOpenChange={() => setBulkConfirm(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{bulkConfirm === 'activate' ? 'Activate users?' : 'Deactivate users?'}</DialogTitle>
            <DialogDescription>
              This will {bulkConfirm === 'activate' ? 'activate' : 'deactivate'} {selected.size} selected account(s).
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => setBulkConfirm(null)}>
              Cancel
            </Button>
            <Button type="button" onClick={() => runBulk(bulkConfirm)}>
              Confirm
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function RolesTab({ toast }) {
  const { refreshUser } = useAuth()
  const [loading, setLoading] = useState(true)
  const [permissions, setPermissions] = useState([])
  const [draftByRole, setDraftByRole] = useState({})
  const [baselineByRole, setBaselineByRole] = useState({})
  const [selectedRole, setSelectedRole] = useState('company_head')
  const [openSections, setOpenSections] = useState(() => new Set(PERMISSION_SECTIONS.map((s) => s.id).concat(['other'])))
  const [permSearch, setPermSearch] = useState('')
  const [criticalConfirm, setCriticalConfirm] = useState(null)
  const [saveConfirmOpen, setSaveConfirmOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [resetDefaultTarget, setResetDefaultTarget] = useState(null)
  const [resettingRole, setResettingRole] = useState(null)

  const roleKeys = useMemo(() => ['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'], [])

  const roleLabel = (rk) => {
    if (rk === 'admin_hr') return 'ADMIN (HR)'
    return rk.replace(/_/g, ' ').toUpperCase()
  }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getRbacMatrix()
      setPermissions(data.permissions ?? [])
      const next = {}
      const base = {}
      roleKeys.forEach((rk) => {
        const slugs = data.matrix?.[rk] ?? []
        next[rk] = new Set(slugs)
        base[rk] = serializeSet(new Set(slugs))
      })
      setDraftByRole(next)
      setBaselineByRole(base)
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [roleKeys, toast])

  useEffect(() => {
    load()
  }, [load])

  const filteredPermissions = useMemo(() => {
    const q = permSearch.trim().toLowerCase()
    return permissions.filter((p) => {
      if (!q) return true
      return (
        p.slug.toLowerCase().includes(q) ||
        (p.label && p.label.toLowerCase().includes(q)) ||
        (p.module && p.module.toLowerCase().includes(q))
      )
    })
  }, [permissions, permSearch])

  const groupedBySection = useMemo(() => {
    const map = {}
    PERMISSION_SECTIONS.forEach((s) => {
      map[s.id] = []
    })
    map.other = []
    filteredPermissions.forEach((p) => {
      const sec = sectionForModule(p.module, p.slug)
      const id = PERMISSION_SECTIONS.some((x) => x.id === sec.id) ? sec.id : 'other'
      if (!map[id]) map[id] = []
      map[id].push(p)
    })
    return map
  }, [filteredPermissions])

  const locked = selectedRole === 'admin_hr'

  const isDirty =
    !locked && serializeSet(draftByRole[selectedRole]) !== baselineByRole[selectedRole]

  function applyToggle(slug, checked) {
    if (locked) return
    setDraftByRole((prev) => {
      const next = { ...prev }
      const s = new Set(next[selectedRole] ?? [])
      if (checked) s.add(slug)
      else s.delete(slug)
      next[selectedRole] = s
      return next
    })
  }

  function handleToggle(slug, checked) {
    if (locked) return
    if (!checked && CRITICAL_PERMS.has(slug)) {
      setCriticalConfirm({ slug })
      return
    }
    applyToggle(slug, checked)
  }

  function toggleSection(id) {
    setOpenSections((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function saveRole() {
    if (locked) return
    setSaving(true)
    try {
      const slugs = Array.from(draftByRole[selectedRole] ?? [])
      await syncRbacRolePermissions(selectedRole, slugs)
      toast({ title: 'Saved', description: `Permissions updated for ${roleLabel(selectedRole)}.` })
      setSaveConfirmOpen(false)
      await load()
      await refreshUser().catch(() => {})
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  async function confirmResetRoleToDefaults() {
    if (!resetDefaultTarget) return
    setResettingRole(resetDefaultTarget)
    try {
      await resetRbacRoleToDefaults(resetDefaultTarget)
      toast({
        title: 'Defaults restored',
        description: `${roleLabel(resetDefaultTarget)} now uses the default permission set from system configuration.`,
      })
      setResetDefaultTarget(null)
      await load()
      await refreshUser().catch(() => {})
    } catch (e) {
      toast({ title: 'Error', description: e.message, variant: 'destructive' })
    } finally {
      setResettingRole(null)
    }
  }

  return (
    <div className="space-y-6">
      <Card className="overflow-hidden border-border/60 shadow-sm">
        <CardHeader className="space-y-1 border-b border-border/50 bg-gradient-to-b from-muted/40 to-transparent px-6 py-5">
          <CardTitle className="text-lg font-semibold tracking-tight">Roles &amp; permissions</CardTitle>
          <CardDescription className="text-sm leading-relaxed">
            ADMIN (HR) has full access and is locked here. Use <strong>Reset to default</strong> at the bottom of a role’s
            panel to restore grants from{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-xs">config/rbac.php</code>. Saving or restoring writes to
            the database and is audited.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5 px-4 pb-6 pt-5 sm:px-6">
          <div className="max-w-md">
            <label className="mb-1.5 block text-xs font-medium text-muted-foreground">Search permissions</label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="h-10 border-border/60 bg-background pl-9 shadow-xs dark:bg-input/30"
                placeholder="Filter by name, slug, or module…"
                value={permSearch}
                onChange={(e) => setPermSearch(e.target.value)}
              />
            </div>
          </div>

          {loading ? (
            <div className="flex justify-center py-20">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
              <aside className="w-full shrink-0 space-y-2 rounded-xl border border-border/60 bg-card p-2 shadow-xs lg:w-64">
                <p className="px-2 pb-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Roles
                </p>
                {roleKeys.map((rk) => (
                  <div
                    key={rk}
                    className={cn(
                      'rounded-lg border p-1.5 transition-colors',
                      selectedRole === rk ? 'border-primary bg-primary/5' : 'border-border/50 bg-muted/15'
                    )}
                  >
                    <button
                      type="button"
                      onClick={() => setSelectedRole(rk)}
                      className={cn(
                        'flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left text-sm font-medium transition-colors',
                        selectedRole === rk
                          ? 'bg-primary text-primary-foreground shadow-sm'
                          : 'text-foreground hover:bg-muted/80'
                      )}
                    >
                      <span className="truncate">{roleLabel(rk)}</span>
                      {rk === 'admin_hr' && (
                        <Badge
                          variant="outline"
                          className={cn(
                            'shrink-0 border-0 text-[10px] font-normal',
                            selectedRole === rk
                              ? 'bg-primary-foreground/15 text-primary-foreground'
                              : 'bg-muted/60 text-muted-foreground'
                          )}
                        >
                          Locked
                        </Badge>
                      )}
                    </button>
                  </div>
                ))}
              </aside>

              <div className="min-w-0 flex-1 space-y-4">
                <div className="space-y-1 border-b border-border/50 pb-4">
                  <h3 className="text-base font-semibold tracking-tight">{roleLabel(selectedRole)}</h3>
                  <p className="text-sm text-muted-foreground">
                    {locked
                      ? 'This role is fully privileged; the matrix is read-only.'
                      : 'Each row is one permission. Toggle the matching action column to grant or revoke access.'}
                  </p>
                </div>

                <div className="rounded-xl border border-border/60 bg-card shadow-xs">
                  <div className="max-h-[min(70vh,640px)] overflow-auto overscroll-contain">
                    <table className="w-full min-w-[800px] border-separate border-spacing-0 text-sm">
                      <thead>
                        <tr className="border-b border-border/60">
                          <th
                            scope="col"
                            className="sticky left-0 top-0 z-30 min-w-[220px] border-b border-border/60 bg-muted/95 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground shadow-[2px_0_8px_-2px_rgba(0,0,0,0.08)] backdrop-blur supports-[backdrop-filter]:bg-muted/90 dark:shadow-[2px_0_12px_-2px_rgba(0,0,0,0.35)]"
                          >
                            Permission
                          </th>
                          {ACTION_KEYS.map((k) => (
                            <th
                              key={k}
                              scope="col"
                              className="sticky top-0 z-20 min-w-[52px] max-w-[64px] border-b border-border/60 bg-muted/95 px-1 py-2 text-center align-bottom text-[10px] font-semibold uppercase leading-tight text-muted-foreground backdrop-blur supports-[backdrop-filter]:bg-muted/90"
                              title={`${ACTION_LABELS[k]} — ${ACTION_TOOLTIPS[k]}`}
                            >
                              <span className="block hyphens-auto break-words">
                                {k === 'sensitive' ? 'Sens.' : ACTION_LABELS[k]}
                              </span>
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {PERMISSION_SECTIONS.map((section) => {
                          const perms = groupedBySection[section.id] ?? []
                          if (perms.length === 0) return null
                          const open = openSections.has(section.id)
                          return (
                            <Fragment key={section.id}>
                              <tr className="bg-muted/50">
                                <td colSpan={1 + ACTION_KEYS.length} className="px-0 py-0">
                                  <button
                                    type="button"
                                    onClick={() => toggleSection(section.id)}
                                    className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-foreground hover:bg-muted/70"
                                  >
                                    {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
                                    <span>{section.label}</span>
                                    <span className="font-normal text-muted-foreground">({perms.length})</span>
                                  </button>
                                </td>
                              </tr>
                              {open && section.hint && (
                                <tr className="bg-muted/25">
                                  <td colSpan={1 + ACTION_KEYS.length} className="px-4 py-2 text-xs leading-relaxed text-muted-foreground">
                                    {section.hint}
                                  </td>
                                </tr>
                              )}
                              {open &&
                                perms.map((p) => {
                                  const col = inferActionKey(p.slug)
                                  const on = (draftByRole[selectedRole] ?? new Set()).has(p.slug)
                                  return (
                                    <tr key={p.slug} className="border-t border-border/50 transition-colors hover:bg-muted/20">
                                      <td className="px-4 py-2.5 align-middle">
                                        <div className="font-medium leading-snug text-foreground">{p.label}</div>
                                        <div className="font-mono text-[11px] text-muted-foreground">{p.slug}</div>
                                      </td>
                                      {ACTION_KEYS.map((k) => {
                                        const isCell = k === col
                                        const cellOn = locked ? true : on
                                        return (
                                          <td
                                            key={`${p.slug}-${k}`}
                                            className={cn(
                                              'px-1 py-2 text-center align-middle',
                                              !isCell && 'bg-muted/10 dark:bg-muted/5'
                                            )}
                                          >
                                            {isCell ? (
                                              <div className="flex justify-center">
                                                <PermSwitch
                                                  on={cellOn}
                                                  disabled={locked}
                                                  title={p.description || p.label}
                                                  onToggle={() => handleToggle(p.slug, !cellOn)}
                                                />
                                              </div>
                                            ) : (
                                              <span className="sr-only">Not applicable</span>
                                            )}
                                          </td>
                                        )
                                      })}
                                    </tr>
                                  )
                                })}
                            </Fragment>
                          )
                        })}
                        {(groupedBySection.other ?? []).length > 0 && (
                          <Fragment key="other-section">
                            <tr className="bg-muted/50">
                              <td colSpan={1 + ACTION_KEYS.length} className="px-0 py-0">
                                <button
                                  type="button"
                                  onClick={() => toggleSection('other')}
                                  className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-foreground hover:bg-muted/70"
                                >
                                  {openSections.has('other') ? (
                                    <ChevronDown className="size-4 shrink-0" />
                                  ) : (
                                    <ChevronRight className="size-4 shrink-0" />
                                  )}
                                  <span>Other</span>
                                  <span className="font-normal text-muted-foreground">
                                    ({groupedBySection.other.length})
                                  </span>
                                </button>
                              </td>
                            </tr>
                            {openSections.has('other') &&
                              groupedBySection.other.map((p) => {
                                const col = inferActionKey(p.slug)
                                const on = (draftByRole[selectedRole] ?? new Set()).has(p.slug)
                                return (
                                  <tr key={p.slug} className="border-t border-border/50 transition-colors hover:bg-muted/20">
                                    <td className="px-4 py-2.5 align-middle">
                                      <div className="font-medium leading-snug text-foreground">{p.label}</div>
                                      <div className="font-mono text-[11px] text-muted-foreground">{p.slug}</div>
                                    </td>
                                    {ACTION_KEYS.map((k) => {
                                      const isCell = k === col
                                      const cellOn = locked ? true : on
                                      return (
                                        <td
                                          key={`${p.slug}-${k}`}
                                          className={cn(
                                            'px-1 py-2 text-center align-middle',
                                            !isCell && 'bg-muted/10 dark:bg-muted/5'
                                          )}
                                        >
                                          {isCell ? (
                                            <div className="flex justify-center">
                                              <PermSwitch
                                                on={cellOn}
                                                disabled={locked}
                                                title={p.description || p.label}
                                                onToggle={() => handleToggle(p.slug, !cellOn)}
                                              />
                                            </div>
                                          ) : (
                                            <span className="sr-only">Not applicable</span>
                                          )}
                                        </td>
                                      )
                                    })}
                                  </tr>
                                )
                              })}
                          </Fragment>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                <p className="rounded-lg border border-border/50 bg-muted/20 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
                  Hover column headers for action descriptions. Each permission maps to one action column. Changes are
                  written to the permission audit log.
                </p>

                <div className="flex flex-col items-stretch gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end sm:gap-3">
                  <Button
                    type="button"
                    variant="outline"
                    className="h-10 shrink-0 gap-2 border-border/80 font-normal text-muted-foreground hover:text-foreground"
                    disabled={!!resettingRole}
                    onClick={() => setResetDefaultTarget(selectedRole)}
                  >
                    <RotateCcw className="size-4 shrink-0 opacity-80" aria-hidden />
                    Reset to default
                  </Button>
                  {!locked && (
                    <Button
                      type="button"
                      className="h-10 shrink-0 gap-2 shadow-xs"
                      disabled={!isDirty || saving}
                      onClick={() => setSaveConfirmOpen(true)}
                    >
                      {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                      Save changes
                    </Button>
                  )}
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={!!criticalConfirm} onOpenChange={() => setCriticalConfirm(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <AlertTriangle className="size-5 text-amber-500" />
              Revoke critical permission?
            </DialogTitle>
            <DialogDescription>
              Removing <code className="rounded bg-muted px-1">{criticalConfirm?.slug}</code> may lock HR staff out of key
              functions. Continue?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => setCriticalConfirm(null)}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => {
                if (criticalConfirm) {
                  applyToggle(criticalConfirm.slug, false)
                }
                setCriticalConfirm(null)
              }}
            >
              Revoke
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={saveConfirmOpen} onOpenChange={setSaveConfirmOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Save permission changes?</DialogTitle>
            <DialogDescription>
              This will update <strong>{roleLabel(selectedRole)}</strong> in the database. The change is recorded in the
              permission audit log.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => setSaveConfirmOpen(false)}>
              Cancel
            </Button>
            <Button type="button" disabled={saving} onClick={saveRole}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : 'Save changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!resetDefaultTarget}
        onOpenChange={(open) => {
          if (!open && !resettingRole) setResetDefaultTarget(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reset to default?</DialogTitle>
            <DialogDescription>
              This will restore <strong>{resetDefaultTarget ? roleLabel(resetDefaultTarget) : ''}</strong> to the default
              permission set from system configuration (
              <code className="rounded bg-muted px-1 text-xs">config/rbac.php</code>
              ). Custom grants for this role only will be removed. This action is audited.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setResetDefaultTarget(null)}
              disabled={!!resettingRole}
            >
              Cancel
            </Button>
            <Button type="button" disabled={!!resettingRole} onClick={confirmResetRoleToDefaults}>
              {resettingRole ? <Loader2 className="size-4 animate-spin" /> : 'Reset to default'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
