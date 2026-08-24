import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  CalendarCheck,
  Clock3,
  Info,
  Loader2,
  PauseCircle,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserPlus,
  Users,
} from 'lucide-react'
import {
  deleteOvertimeAutoApproveOverride,
  getEmployees,
  getOvertimeAutoApproveOverrides,
  syncOvertimeAutoApproveOverrides,
  updateOvertimeAutoApproveOverride,
  updateOvertimeAutoApproveOverrideStatus,
  userProfileImageSrc,
} from '@/api'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const PAGE_SHELL = 'w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-6 lg:px-8'
const CARD_SHELL = 'rounded-[1.35rem] border border-border/70 bg-card text-card-foreground shadow-[0_14px_40px_rgba(15,23,42,0.06)] dark:shadow-black/25'
const ORANGE_BUTTON = 'bg-brand text-brand-foreground shadow-sm shadow-brand/20 hover:bg-brand-strong'
const TABLE_HEAD = 'bg-[#fff8f1] text-[11px] font-extrabold uppercase tracking-[0.04em] text-muted-foreground dark:bg-input/25'
const DEFAULT_MAX_HOURS = 2
const HOUR_PRESETS = [1, 2, 3, 4, 6, 8]

const POLICY_POINTS = [
  {
    icon: UserCheck,
    title: 'Complete attendance only',
    description: 'Auto-approve and standing OT require both time-in and time-out on that date (kiosk punch or approved manual attendance). Missing in/out does not qualify.',
  },
  {
    icon: CalendarCheck,
    title: 'Leave blocked',
    description: 'Requests filed on dates with approved leave stay pending for manual review.',
  },
  {
    icon: Clock3,
    title: 'Daily hours + holiday scope',
    description: 'Auto-approve is capped by each employee’s max OT hours per day. Holiday OT rates (RH/SH/DH) apply only when the employee is in that holiday’s coverage scope — same as payroll.',
  },
]

function initialsFor(employee) {
  const name = String(employee?.name || employee?.display_name || '').trim()
  if (!name) return 'EE'
  const parts = name.split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || 'EE'
}

function employeeLabel(employee) {
  if (!employee) return '—'
  return employee.name || employee.display_name || `Employee #${employee?.id ?? ''}`
}

function employeeOrgLine(employee) {
  return [
    employee?.company_name,
    employee?.department,
    employee?.department_name,
  ].filter(Boolean).join(' / ')
}

function formatUpdatedAt(value) {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(value))
  } catch {
    return '—'
  }
}

function formatHours(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return '—'
  return `${n % 1 === 0 ? n.toFixed(0) : n.toFixed(2)} hr${n === 1 ? '' : 's'}`
}

function parseHoursInput(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return null
  if (n < 0.25 || n > 24) return null
  return Math.round(n * 100) / 100
}

export default function AdminOvertimeAutoApprove() {
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [overrides, setOverrides] = useState([])
  const [summary, setSummary] = useState({ total_active: 0 })
  const [search, setSearch] = useState('')
  const [addDialogOpen, setAddDialogOpen] = useState(false)
  const [policyDialogOpen, setPolicyDialogOpen] = useState(false)
  const [removeTarget, setRemoveTarget] = useState(null)
  const [hoursTarget, setHoursTarget] = useState(null)
  const [hoursDraft, setHoursDraft] = useState(String(DEFAULT_MAX_HOURS))
  const [hoursSaving, setHoursSaving] = useState(false)
  const [addMaxHours, setAddMaxHours] = useState(String(DEFAULT_MAX_HOURS))
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [employeeResults, setEmployeeResults] = useState([])
  const [employeeSearchLoading, setEmployeeSearchLoading] = useState(false)
  const [selectedEmployees, setSelectedEmployees] = useState([])

  const configuredIds = useMemo(
    () => new Set(overrides.map((row) => String(row.user_id))),
    [overrides],
  )

  const selectedEmployeeIds = useMemo(
    () => new Set(selectedEmployees.map((employee) => String(employee.id))),
    [selectedEmployees],
  )

  const filteredOverrides = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return overrides
    return overrides.filter((row) => {
      const employee = row.employee || {}
      const haystack = [
        employee.name,
        employee.employee_code,
        employee.department,
        employee.company_name,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(q)
    })
  }, [overrides, search])

  const pausedCount = Math.max(0, overrides.length - (summary.total_active ?? 0))

  const loadOverrides = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getOvertimeAutoApproveOverrides({ per_page: 200, fresh: true })
      setOverrides(data.overrides || [])
      setSummary(data.summary || { total_active: 0 })
    } catch (error) {
      toast({ title: 'Failed to load overrides', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    loadOverrides()
  }, [loadOverrides])

  const loadPickerEmployees = useCallback(async (query = '') => {
    setEmployeeSearchLoading(true)
    try {
      const q = String(query || '').trim()
      const data = await getEmployees({
        ...(q ? { q } : {}),
        per_page: q ? 50 : 100,
        active_filter: 'active',
        lite: true,
        fresh: true,
      })
      const rows = Array.isArray(data.employees) ? data.employees : []
      setEmployeeResults(rows.filter((emp) => !configuredIds.has(String(emp.id))))
    } catch (error) {
      toast({ title: 'Failed to load employees', description: error.message, variant: 'error' })
      setEmployeeResults([])
    } finally {
      setEmployeeSearchLoading(false)
    }
  }, [configuredIds, toast])

  useEffect(() => {
    if (!addDialogOpen) return undefined
    const q = employeeSearch.trim()
    const timer = setTimeout(() => loadPickerEmployees(q), q ? 250 : 0)
    return () => clearTimeout(timer)
  }, [addDialogOpen, employeeSearch, loadPickerEmployees])

  function resetAddDialog() {
    setEmployeeSearch('')
    setEmployeeResults([])
    setSelectedEmployees([])
    setEmployeeSearchLoading(false)
    setAddMaxHours(String(DEFAULT_MAX_HOURS))
  }

  function openAddDialog() {
    resetAddDialog()
    setAddDialogOpen(true)
  }

  function openHoursDialog(row) {
    setHoursTarget(row)
    setHoursDraft(String(row.max_hours_per_day ?? DEFAULT_MAX_HOURS))
  }

  function toggleSelectedEmployee(employee) {
    if (!employee?.id) return
    setSelectedEmployees((current) => {
      const id = String(employee.id)
      if (current.some((item) => String(item.id) === id)) {
        return current.filter((item) => String(item.id) !== id)
      }
      return [...current, employee]
    })
  }

  function removeSelectedEmployee(employeeId) {
    setSelectedEmployees((current) => current.filter((employee) => String(employee.id) !== String(employeeId)))
  }

  async function addSelectedEmployees() {
    if (selectedEmployees.length === 0) {
      toast({ title: 'Select at least one employee', variant: 'error' })
      return
    }
    const hours = parseHoursInput(addMaxHours)
    if (hours == null) {
      toast({ title: 'Invalid hours', description: 'Enter a daily OT limit between 0.25 and 24 hours.', variant: 'error' })
      return
    }
    setSaving(true)
    try {
      const mergedIds = [
        ...overrides.map((row) => Number(row.user_id)),
        ...selectedEmployees.map((employee) => Number(employee.id)),
      ]
      const uniqueIds = [...new Set(mergedIds.filter((id) => id > 0))]
      await syncOvertimeAutoApproveOverrides({
        employee_ids: uniqueIds,
        max_hours_per_day: hours,
      })
      toast({
        title: `${selectedEmployees.length} employee${selectedEmployees.length === 1 ? '' : 's'} added`,
        description: `Auto-approve capped at ${formatHours(hours)} per day for new employees.`,
      })
      setAddDialogOpen(false)
      resetAddDialog()
      await loadOverrides()
    } catch (error) {
      toast({ title: 'Save failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  async function saveHoursEdit() {
    if (!hoursTarget) return
    const hours = parseHoursInput(hoursDraft)
    if (hours == null) {
      toast({ title: 'Invalid hours', description: 'Enter a daily OT limit between 0.25 and 24 hours.', variant: 'error' })
      return
    }
    setHoursSaving(true)
    try {
      const data = await updateOvertimeAutoApproveOverride(hoursTarget.user_id, { max_hours_per_day: hours })
      const updated = data.override
      setOverrides((list) => list.map((item) => (
        item.user_id === hoursTarget.user_id
          ? { ...item, ...(updated || {}), max_hours_per_day: hours }
          : item
      )))
      toast({
        title: 'Daily hours updated',
        description: `${employeeLabel(hoursTarget.employee)} can auto-approve up to ${formatHours(hours)}/day.`,
      })
      setHoursTarget(null)
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    } finally {
      setHoursSaving(false)
    }
  }

  async function toggleOverride(row) {
    try {
      await updateOvertimeAutoApproveOverrideStatus(row.user_id, !row.is_active)
      setOverrides((list) => list.map((item) => (
        item.user_id === row.user_id ? { ...item, is_active: !row.is_active } : item
      )))
      setSummary((prev) => ({
        total_active: Math.max(0, (prev.total_active || 0) + (row.is_active ? -1 : 1)),
      }))
      toast({
        title: row.is_active ? 'Override paused' : 'Override activated',
        description: `${employeeLabel(row.employee)} will ${row.is_active ? 'no longer' : 'now'} receive auto-approval when present.`,
      })
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  async function confirmRemove() {
    if (!removeTarget) return
    try {
      await deleteOvertimeAutoApproveOverride(removeTarget.user_id)
      setOverrides((list) => list.filter((item) => item.user_id !== removeTarget.user_id))
      setSummary((prev) => ({
        total_active: removeTarget.is_active
          ? Math.max(0, (prev.total_active || 0) - 1)
          : (prev.total_active || 0),
      }))
      toast({
        title: 'Employee removed',
        description: `${employeeLabel(removeTarget.employee)} was removed from the auto-approve list.`,
      })
      setRemoveTarget(null)
    } catch (error) {
      toast({ title: 'Remove failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className={cn(PAGE_SHELL, 'space-y-5 md:space-y-6')}>
      <div className="space-y-2">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-start gap-3">
            <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand/20 bg-brand/10 text-brand shadow-sm">
              <ShieldCheck className="h-5 w-5" />
            </div>
            <h1 className="pt-0.5 text-[26px] font-extrabold tracking-tight text-foreground md:text-[30px]">
              Overtime Auto-Approve Override
            </h1>
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => setPolicyDialogOpen(true)}
              className="h-10 rounded-xl border-border/80 bg-card px-4 font-semibold shadow-sm"
            >
              <Info className="mr-2 size-4 text-brand" />
              How it works
            </Button>
            <Button onClick={openAddDialog} className={cn('h-10 rounded-xl px-4 font-bold', ORANGE_BUTTON)}>
              <Plus className="mr-2 size-4" />
              Add Employees
            </Button>
            <Button
              onClick={() => loadOverrides()}
              disabled={loading}
              variant="outline"
              className="h-10 rounded-xl border-border/80 bg-card px-4 font-semibold shadow-sm"
            >
              <RefreshCw className={cn('mr-2 size-4', loading ? 'animate-spin' : '')} />
              Refresh
            </Button>
          </div>
        </div>
        <p className="max-w-4xl pl-[52px] text-sm font-medium leading-6 text-muted-foreground">
          Configure employees whose overtime requests are automatically approved when they file OT and were present on that date.
          Payroll freeze and overlap rules still apply.
        </p>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        {[
          { label: 'Configured', value: overrides.length, icon: Users, tone: 'text-brand' },
          { label: 'Active', value: summary.total_active ?? 0, icon: UserCheck, tone: 'text-emerald-600 dark:text-emerald-300' },
          { label: 'Paused', value: pausedCount, icon: PauseCircle, tone: 'text-amber-600 dark:text-amber-300' },
        ].map((stat) => {
          const Icon = stat.icon
          return (
            <Card key={stat.label} className={cn(CARD_SHELL, 'overflow-hidden')}>
              <CardContent className="flex items-center justify-between gap-3 p-4">
                <div>
                  <p className="text-xs font-bold uppercase tracking-[0.04em] text-muted-foreground">{stat.label}</p>
                  <p className="mt-1 text-2xl font-extrabold text-foreground">{stat.value}</p>
                </div>
                <div className={cn('flex size-11 items-center justify-center rounded-xl border border-border/70 bg-background/80', stat.tone)}>
                  <Icon className="size-5" />
                </div>
              </CardContent>
            </Card>
          )
        })}
      </div>

      <Card className={CARD_SHELL}>
        <CardHeader className="px-4 pb-3 pt-5 sm:px-5">
          <CardTitle className="text-[17px] font-extrabold">Override list</CardTitle>
          <CardDescription className="text-sm">
            Employees on this list receive up to their max OT hours per day on each complete-attendance day when payroll is generated. Days with missing time-in or time-out are excluded.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 px-4 pb-5 sm:px-5">
          <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="h-11 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/45"
                placeholder="Search by name, employee code, department, or company"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="flex items-center rounded-xl border border-border/70 bg-background/70 px-4 py-2 text-xs font-semibold text-muted-foreground dark:bg-input/20">
              <Info className="mr-2 size-4 shrink-0 text-brand" />
              {filteredOverrides.length} of {overrides.length} shown
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-border/70 bg-white dark:bg-input/15">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1040px] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th className="px-4 py-3.5 text-left">Employee</th>
                    <th className="px-4 py-3.5 text-left">Organization</th>
                    <th className="px-4 py-3.5 text-left">Max OT / day</th>
                    <th className="px-4 py-3.5 text-left">Status</th>
                    <th className="px-4 py-3.5 text-left">Last updated</th>
                    <th className="px-4 py-3.5 text-right">Auto-approve</th>
                    <th className="px-4 py-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                  {loading ? (
                    <tr>
                      <td colSpan="7" className="px-4 py-14 text-center text-muted-foreground">
                        <Loader2 className="mx-auto mb-3 size-6 animate-spin text-brand" />
                        <div className="font-bold text-foreground">Loading override list…</div>
                      </td>
                    </tr>
                  ) : filteredOverrides.map((row) => (
                    <tr key={row.id} className="transition hover:bg-[#fff8f1]/60 dark:hover:bg-input/20">
                      <td className="px-4 py-4">
                        <div className="flex min-w-0 items-center gap-3">
                          <Avatar className="size-10 shrink-0 border border-brand/25 bg-card">
                            <AvatarImage src={userProfileImageSrc(row.employee)} alt="" className="object-cover" />
                            <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">
                              {initialsFor(row.employee)}
                            </AvatarFallback>
                          </Avatar>
                          <div className="min-w-0">
                            <div className="truncate font-extrabold leading-tight text-foreground">
                              {employeeLabel(row.employee)}
                            </div>
                            <div className="mt-1 truncate text-xs font-medium text-muted-foreground">
                              {row.employee?.employee_code || '—'}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="max-w-[240px] px-4 py-4 text-xs font-medium leading-5 text-muted-foreground">
                        {employeeOrgLine(row.employee) || '—'}
                      </td>
                      <td className="px-4 py-4">
                        <button
                          type="button"
                          onClick={() => openHoursDialog(row)}
                          className="inline-flex items-center gap-2 rounded-lg border border-border/80 bg-background px-2.5 py-1.5 text-left text-sm font-extrabold text-foreground shadow-sm transition hover:border-brand/40 hover:bg-brand/5"
                        >
                          <Clock3 className="size-3.5 text-brand" />
                          {formatHours(row.max_hours_per_day ?? DEFAULT_MAX_HOURS)}
                          <Pencil className="size-3.5 text-muted-foreground" />
                        </button>
                      </td>
                      <td className="px-4 py-4">
                        <Badge
                          variant={row.is_active ? 'default' : 'outline'}
                          className={cn(
                            'rounded-lg px-2.5 py-1 text-[11px] font-extrabold',
                            row.is_active ? 'bg-brand text-brand-foreground hover:bg-brand' : '',
                          )}
                        >
                          {row.is_active ? 'Active' : 'Paused'}
                        </Badge>
                      </td>
                      <td className="px-4 py-4 text-xs font-medium text-muted-foreground">
                        <div>{formatUpdatedAt(row.updated_at)}</div>
                        {row.updated_by_name ? (
                          <div className="mt-1 text-[11px] text-muted-foreground/80">by {row.updated_by_name}</div>
                        ) : null}
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex items-center justify-end gap-2">
                          <Switch
                            checked={row.is_active}
                            onCheckedChange={() => toggleOverride(row)}
                            aria-label={`Toggle auto-approve for ${employeeLabel(row.employee)}`}
                            className="data-[state=checked]:bg-brand data-[state=unchecked]:bg-muted dark:data-[state=unchecked]:bg-input/80"
                          />
                          <span className={cn('min-w-[52px] text-xs font-extrabold uppercase tracking-wide', row.is_active ? 'text-foreground' : 'text-muted-foreground')}>
                            {row.is_active ? 'On' : 'Off'}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-4 text-right">
                        <Button
                          size="icon"
                          variant="destructive"
                          className="size-8 rounded-lg shadow-sm"
                          onClick={() => setRemoveTarget(row)}
                          aria-label={`Remove ${employeeLabel(row.employee)}`}
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                  {!loading && filteredOverrides.length === 0 ? (
                    <tr>
                      <td colSpan="7" className="px-4 py-14 text-center text-muted-foreground">
                        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl border border-brand/20 bg-brand/10 text-brand">
                          <UserPlus className="h-6 w-6" />
                        </div>
                        <div className="font-bold text-foreground">
                          {overrides.length === 0 ? 'No employees configured yet.' : 'No employees match your search.'}
                        </div>
                        <div className="mt-1 text-xs">
                          {overrides.length === 0
                            ? 'Add employees and set their daily OT auto-approve hours.'
                            : 'Try a different search term.'}
                        </div>
                        {overrides.length === 0 ? (
                          <Button onClick={openAddDialog} className={cn('mt-4 h-10 rounded-xl px-4 font-bold', ORANGE_BUTTON)}>
                            <Plus className="mr-2 size-4" />
                            Add Employees
                          </Button>
                        ) : null}
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </div>
        </CardContent>
      </Card>

      <Dialog open={policyDialogOpen} onOpenChange={setPolicyDialogOpen}>
        <DialogContent className="rounded-2xl border-border bg-card text-card-foreground sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>How auto-approve works</DialogTitle>
            <DialogDescription>
              These rules apply before any overtime request is auto-approved for employees on this list.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            {POLICY_POINTS.map((point) => {
              const Icon = point.icon
              return (
                <div
                  key={point.title}
                  className="rounded-xl border border-border/80 bg-background/70 p-4 dark:bg-input/20"
                >
                  <div className="flex items-start gap-3">
                    <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand ring-1 ring-brand/20">
                      <Icon className="size-4" />
                    </span>
                    <div>
                      <p className="font-extrabold text-foreground">{point.title}</p>
                      <p className="mt-1 text-sm leading-6 text-muted-foreground">{point.description}</p>
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
          <DialogFooter>
            <Button type="button" className={cn('rounded-xl font-bold', ORANGE_BUTTON)} onClick={() => setPolicyDialogOpen(false)}>
              Got it
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={addDialogOpen}
        onOpenChange={(open) => {
          setAddDialogOpen(open)
          if (!open) resetAddDialog()
        }}
      >
        <DialogContent className="max-h-[90vh] overflow-y-auto rounded-2xl border-border bg-card text-card-foreground sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle>Add employees to auto-approve list</DialogTitle>
            <DialogDescription>
              Active employees load automatically. Choose the max OT hours per day to auto-approve, then select employees.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 rounded-xl border border-border/80 bg-background/70 p-4 dark:bg-input/20">
            <div className="rounded-xl border border-brand/20 bg-brand/5 p-4">
              <Label htmlFor="add-max-hours" className="text-sm font-extrabold text-foreground">
                Max OT hours per day (auto-approve)
              </Label>
              <p className="mt-1 text-xs text-muted-foreground">
                Applied to newly added employees. Already configured employees keep their existing limit.
              </p>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                {HOUR_PRESETS.map((hours) => (
                  <Button
                    key={hours}
                    type="button"
                    size="sm"
                    variant={Number(addMaxHours) === hours ? 'default' : 'outline'}
                    className={cn(
                      'h-9 rounded-lg px-3 font-bold',
                      Number(addMaxHours) === hours ? ORANGE_BUTTON : 'border-border/80',
                    )}
                    onClick={() => setAddMaxHours(String(hours))}
                  >
                    {hours}h
                  </Button>
                ))}
                <div className="relative ml-auto w-28">
                  <Input
                    id="add-max-hours"
                    type="number"
                    min="0.25"
                    max="24"
                    step="0.25"
                    value={addMaxHours}
                    onChange={(e) => setAddMaxHours(e.target.value)}
                    className="h-9 rounded-lg border-border/80 pr-8 text-sm font-bold shadow-sm"
                  />
                  <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground">
                    hrs
                  </span>
                </div>
              </div>
            </div>

            <div className="grid gap-3 lg:grid-cols-[1fr_auto]">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="h-11 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/45"
                  placeholder="Search employee name, code, or email"
                  value={employeeSearch}
                  onChange={(e) => setEmployeeSearch(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') loadPickerEmployees(employeeSearch)
                  }}
                />
              </div>
              <Button
                type="button"
                variant="outline"
                disabled={employeeSearchLoading}
                onClick={() => loadPickerEmployees(employeeSearch)}
                className="h-11 rounded-xl border-brand/30 font-bold text-brand hover:bg-brand/10"
              >
                {employeeSearchLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Search className="mr-2 size-4" />}
                Search
              </Button>
            </div>

            {selectedEmployees.length > 0 ? (
              <div className="rounded-xl border border-brand/25 bg-brand/10 p-3 text-sm">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <div className="font-bold text-foreground">
                    {selectedEmployees.length} selected for auto-approve override
                  </div>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 rounded-lg px-2 text-muted-foreground hover:text-foreground"
                    onClick={() => setSelectedEmployees([])}
                  >
                    Clear selection
                  </Button>
                </div>
                <div className="grid gap-2 md:grid-cols-2">
                  {selectedEmployees.map((employee) => (
                    <div
                      key={employee.id}
                      className="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-brand/20 bg-card/90 px-3 py-2"
                    >
                      <div className="flex min-w-0 items-center gap-3">
                        <Avatar className="size-10 border border-brand/25 bg-card">
                          <AvatarImage src={userProfileImageSrc(employee)} alt="" className="object-cover" />
                          <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">
                            {initialsFor(employee)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0">
                          <div className="truncate font-bold text-foreground">{employeeLabel(employee)}</div>
                          <div className="truncate text-xs text-muted-foreground">
                            {[employee.employee_code, employeeOrgLine(employee)].filter(Boolean).join(' • ')}
                          </div>
                        </div>
                      </div>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0 rounded-lg text-muted-foreground hover:text-destructive"
                        onClick={() => removeSelectedEmployee(employee.id)}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}

            {employeeSearchLoading ? (
              <div className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-border/80 bg-background/70 px-3 py-8 text-sm text-muted-foreground dark:bg-input/20">
                <Loader2 className="size-4 animate-spin text-brand" />
                Loading employees…
              </div>
            ) : employeeResults.length > 0 ? (
              <div className="rounded-xl border border-border/80 bg-background p-2 shadow-sm dark:bg-input/20">
                <div className="mb-2 flex items-center justify-between gap-2 px-1 text-xs text-muted-foreground">
                  <span>Select multiple employees from the list below.</span>
                  <span>{selectedEmployees.length} selected · {employeeResults.length} available</span>
                </div>
                <div className="max-h-72 overflow-y-auto">
                  {employeeResults.map((employee) => {
                    const active = selectedEmployeeIds.has(String(employee.id))
                    return (
                      <button
                        key={employee.id}
                        type="button"
                        onClick={() => toggleSelectedEmployee(employee)}
                        className={cn(
                          'flex w-full items-center justify-between gap-3 rounded-lg px-3 py-3 text-left text-sm transition',
                          active ? 'bg-brand text-brand-foreground' : 'hover:bg-muted',
                        )}
                      >
                        <span className="flex min-w-0 items-center gap-3">
                          <span
                            className={cn(
                              'flex size-5 shrink-0 items-center justify-center rounded border text-[11px] font-black',
                              active
                                ? 'border-brand-foreground/50 bg-brand-foreground text-brand'
                                : 'border-border bg-background text-transparent',
                            )}
                          >
                            ✓
                          </span>
                          <Avatar className={cn('size-11 border', active ? 'border-brand-foreground/30 bg-brand-foreground/10' : 'border-border bg-card')}>
                            <AvatarImage src={userProfileImageSrc(employee)} alt="" className="object-cover" />
                            <AvatarFallback className={cn('font-bold', active ? 'bg-brand-foreground/15 text-brand-foreground' : 'bg-brand/10 text-brand')}>
                              {initialsFor(employee)}
                            </AvatarFallback>
                          </Avatar>
                          <span className="min-w-0">
                            <span className="block truncate font-bold">{employeeLabel(employee)}</span>
                            <span className={cn('block truncate text-xs', active ? 'text-brand-foreground/80' : 'text-muted-foreground')}>
                              {[employee.employee_code, employee.email, employeeOrgLine(employee)].filter(Boolean).join(' • ')}
                            </span>
                          </span>
                        </span>
                        {active ? <UserPlus className="h-4 w-4" /> : null}
                      </button>
                    )
                  })}
                </div>
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-border/80 bg-background/70 px-3 py-4 text-center text-sm text-muted-foreground dark:bg-input/20">
                {employeeSearch.trim()
                  ? 'No matching active employees found, or they are already on the override list.'
                  : 'No active employees available to add. All eligible employees may already be on the override list.'}
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" className="rounded-xl" onClick={() => setAddDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              onClick={addSelectedEmployees}
              disabled={saving || selectedEmployees.length === 0}
              className={cn('rounded-xl font-bold', ORANGE_BUTTON)}
            >
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Plus className="mr-2 size-4" />}
              Add {selectedEmployees.length > 0 ? selectedEmployees.length : ''} Employee{selectedEmployees.length === 1 ? '' : 's'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(hoursTarget)} onOpenChange={(open) => !open && setHoursTarget(null)}>
        <DialogContent className="rounded-2xl border-border bg-card text-card-foreground sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Edit max OT hours / day</DialogTitle>
            <DialogDescription>
              {hoursTarget
                ? `Set how many overtime hours can be auto-approved per day for ${employeeLabel(hoursTarget.employee)}.`
                : ''}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {HOUR_PRESETS.map((hours) => (
                <Button
                  key={hours}
                  type="button"
                  size="sm"
                  variant={Number(hoursDraft) === hours ? 'default' : 'outline'}
                  className={cn(
                    'h-9 rounded-lg px-3 font-bold',
                    Number(hoursDraft) === hours ? ORANGE_BUTTON : 'border-border/80',
                  )}
                  onClick={() => setHoursDraft(String(hours))}
                >
                  {hours}h
                </Button>
              ))}
            </div>
            <div>
              <Label htmlFor="edit-max-hours" className="text-sm font-bold">Custom hours</Label>
              <div className="relative mt-1.5">
                <Input
                  id="edit-max-hours"
                  type="number"
                  min="0.25"
                  max="24"
                  step="0.25"
                  value={hoursDraft}
                  onChange={(e) => setHoursDraft(e.target.value)}
                  className="h-11 rounded-xl border-border/80 pr-12 font-bold shadow-sm"
                />
                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground">
                  hrs
                </span>
              </div>
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" className="rounded-xl" onClick={() => setHoursTarget(null)}>
              Cancel
            </Button>
            <Button
              type="button"
              onClick={saveHoursEdit}
              disabled={hoursSaving}
              className={cn('rounded-xl font-bold', ORANGE_BUTTON)}
            >
              {hoursSaving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Save hours
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(removeTarget)} onOpenChange={(open) => !open && setRemoveTarget(null)}>
        <DialogContent className="rounded-2xl border-border bg-card text-card-foreground sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Remove from auto-approve list?</DialogTitle>
            <DialogDescription>
              {removeTarget
                ? `${employeeLabel(removeTarget.employee)} will no longer receive automatic overtime approval. Existing pending requests are not changed.`
                : ''}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" className="rounded-xl" onClick={() => setRemoveTarget(null)}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" className="rounded-xl font-bold" onClick={confirmRemove}>
              Remove employee
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
