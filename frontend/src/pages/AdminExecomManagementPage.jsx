import { useCallback, useEffect, useMemo, useState } from 'react'
import { Banknote, Filter, Loader2, Pencil, Plus, RefreshCw, Save, Search, Trash2, UserPlus } from 'lucide-react'
import {
  createExecomEmployee,
  deleteExecomEmployee,
  getEmployees,
  getExecomEmployees,
  getExecomPayrollSettings,
  updateExecomEmployee,
  updateExecomPayrollSettings,
  userProfileImageSrc,
} from '@/api'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const PAGE_SHELL = 'w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-6 lg:px-8'
const CARD_SHELL = 'rounded-[1.35rem] border border-border/70 bg-card text-card-foreground shadow-[0_14px_40px_rgba(15,23,42,0.06)] dark:shadow-black/25'
const CONTROL =
  'h-11 rounded-xl border border-border/80 bg-background px-3 text-sm font-medium text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:bg-input/45'
const ORANGE_BUTTON = 'bg-brand text-brand-foreground shadow-sm shadow-brand/20 hover:bg-brand-strong'
const TABLE_HEAD = 'bg-[#fff8f1] text-[11px] font-extrabold uppercase tracking-[0.04em] text-muted-foreground dark:bg-input/25'

function initialsFor(employee) {
  const name = String(employee?.name || employee?.display_name || employee?.formatted_name || '').trim()
  if (!name) return 'EE'
  const parts = name.split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || 'EE'
}

function employeeOrgLine(employee) {
  return [
    employee?.company_name,
    employee?.branch_name,
    employee?.department_name || employee?.department,
  ].filter(Boolean).join(' / ')
}

function employeeSalaryLabel(employee) {
  const value = Number(employee?.monthly_salary ?? employee?.monthly_rate ?? employee?.daily_rate ?? 0)
  if (!Number.isFinite(value) || value <= 0) return null
  const label = employee?.monthly_salary || employee?.monthly_rate ? 'Monthly' : 'Daily'
  return `${label}: ${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatMoney(value) {
  const amount = Number(value || 0)
  if (!Number.isFinite(amount) || amount <= 0) return '—'
  return amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const DEFAULT_SETTINGS = {
  apply_government_deductions: true,
  apply_custom_deductions: true,
  apply_allowances: true,
  allow_overtime: false,
  allow_holiday_pay: false,
  auto_present_attendance_reports: true,
}

const SETTINGS_LABELS = {
  apply_government_deductions: 'Apply government deductions',
  apply_custom_deductions: 'Apply custom deductions',
  apply_allowances: 'Apply allowances',
  allow_overtime: 'Allow overtime',
  allow_holiday_pay: 'Allow holiday pay',
  auto_present_attendance_reports: 'Auto present attendance reports',
}

export default function AdminExecomManagementPage() {
  const { toast } = useToast()
  const [rows, setRows] = useState([])
  const [pagination, setPagination] = useState(null)
  const [filters, setFilters] = useState({ status: 'active', q: '', page: 1, per_page: 25 })
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [employeeResults, setEmployeeResults] = useState([])
  const [employeeSearchLoading, setEmployeeSearchLoading] = useState(false)
  const [selectedEmployees, setSelectedEmployees] = useState([])
  const [settings, setSettings] = useState(DEFAULT_SETTINGS)
  const [quickSetupDialogOpen, setQuickSetupDialogOpen] = useState(false)
  const [settingsSaving, setSettingsSaving] = useState(false)
  const [salaryDialogRow, setSalaryDialogRow] = useState(null)
  const [salaryDraft, setSalaryDraft] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)

  const selectedEmployeeIds = useMemo(
    () => new Set(selectedEmployees.map((employee) => String(employee.id))),
    [selectedEmployees],
  )

  async function loadRows(next = filters) {
    setLoading(true)
    try {
      const data = await getExecomEmployees(next)
      setRows(Array.isArray(data.execom_employees) ? data.execom_employees : [])
      setPagination(data.pagination || null)
    } catch (e) {
      toast({ title: 'Failed to load EXECOM employees', description: e.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }

  async function loadSettings() {
    try {
      const data = await getExecomPayrollSettings()
      setSettings({ ...DEFAULT_SETTINGS, ...(data.settings || {}) })
    } catch (e) {
      toast({ title: 'Failed to load EXECOM settings', description: e.message, variant: 'error' })
    }
  }

  useEffect(() => {
    loadRows()
    loadSettings()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.status, filters.page])

  const searchEmployees = useCallback(async (eventOrQuery) => {
    if (eventOrQuery && typeof eventOrQuery === 'object' && typeof eventOrQuery.preventDefault === 'function') {
      eventOrQuery.preventDefault()
    }
    const q = typeof eventOrQuery === 'string' ? eventOrQuery.trim() : employeeSearch.trim()
    if (q.length < 2) {
      setEmployeeResults([])
      return
    }
    setEmployeeSearchLoading(true)
    try {
      const data = await getEmployees({
        q,
        active_filter: 'active',
        per_page: 8,
        fresh: true,
      })
      setEmployeeResults(Array.isArray(data.employees) ? data.employees : Array.isArray(data.data) ? data.data : [])
    } catch (e) {
      toast({ title: 'Employee search failed', description: e.message, variant: 'error' })
    } finally {
      setEmployeeSearchLoading(false)
    }
  }, [employeeSearch, toast])

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

  useEffect(() => {
    const q = employeeSearch.trim()
    if (q.length < 2) {
      setEmployeeResults([])
      setEmployeeSearchLoading(false)
      return undefined
    }
    const timer = setTimeout(() => {
      searchEmployees(q)
    }, 250)
    return () => clearTimeout(timer)
  }, [employeeSearch, searchEmployees])

  async function addSelectedEmployee() {
    if (selectedEmployees.length === 0) {
      toast({ title: 'Select at least one employee first', variant: 'error' })
      return
    }
    setSaving(true)
    try {
      const results = await Promise.allSettled(
        selectedEmployees.map((employee) => createExecomEmployee({
          employee_id: Number(employee.id),
        })),
      )
      const added = results.filter((result) => result.status === 'fulfilled').length
      const failed = results.length - added
      if (added > 0) {
        toast({
          title: `${added} EXECOM employee${added === 1 ? '' : 's'} added`,
          description: failed > 0 ? `${failed} employee${failed === 1 ? '' : 's'} could not be added.` : undefined,
          variant: failed > 0 ? 'error' : undefined,
        })
      } else {
        const firstReason = results.find((result) => result.status === 'rejected')?.reason
        throw new Error(firstReason?.message || 'No selected employees were added.')
      }
      if (failed === 0) {
        setSelectedEmployees([])
        setEmployeeSearch('')
        setEmployeeResults([])
      } else {
        setSelectedEmployees((current) => current.filter((_, index) => results[index]?.status === 'rejected'))
      }
      await loadRows()
    } catch (e) {
      toast({ title: 'Save failed', description: e.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  async function saveSettings() {
    setSettingsSaving(true)
    try {
      await updateExecomPayrollSettings(settings)
      toast({ title: 'EXECOM settings saved' })
    } catch (e) {
      toast({ title: 'Settings save failed', description: e.message, variant: 'error' })
    } finally {
      setSettingsSaving(false)
    }
  }

  function openSalaryDialog(row) {
    setSalaryDialogRow(row)
    setSalaryDraft(String(row.fixed_salary ?? ''))
  }

  async function saveSalaryEdit() {
    if (!salaryDialogRow) return
    const value = Number(salaryDraft)
    if (!Number.isFinite(value) || value < 0) {
      toast({ title: 'Invalid salary', description: 'Enter a valid non-negative salary.', variant: 'error' })
      return
    }
    try {
      await updateExecomEmployee(salaryDialogRow.id, {
        employee_id: salaryDialogRow.employee_id,
        company_id: salaryDialogRow.company_id,
        branch_id: salaryDialogRow.branch_id,
        department_id: salaryDialogRow.department_id,
        fixed_salary: value,
        pay_schedule: salaryDialogRow.pay_schedule || 'per_period',
        is_active: salaryDialogRow.is_active,
        effective_from: salaryDialogRow.effective_from,
        effective_to: salaryDialogRow.effective_to,
        remarks: salaryDialogRow.remarks,
      })
      toast({ title: 'EXECOM salary updated' })
      setSalaryDialogRow(null)
      setSalaryDraft('')
      await loadRows()
    } catch (e) {
      toast({ title: 'Update failed', description: e.message, variant: 'error' })
    }
  }

  async function removeRow(row) {
    if (!window.confirm(`Remove ${row.employee_name || 'this employee'} from active EXECOM payroll?`)) return
    try {
      await deleteExecomEmployee(row.id)
      toast({ title: 'EXECOM employee deactivated' })
      await loadRows()
    } catch (e) {
      toast({ title: 'Remove failed', description: e.message, variant: 'error' })
    }
  }

  return (
    <div className={cn(PAGE_SHELL, 'space-y-5 md:space-y-6')}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand/20 bg-brand/10 text-brand shadow-sm">
            <Banknote className="h-5 w-5" />
          </div>
          <div className="min-w-0">
            <h1 className="text-[26px] font-extrabold tracking-tight text-foreground md:text-[30px]">EXECOM Management</h1>
            <p className="mt-1 max-w-4xl text-sm font-medium leading-6 text-muted-foreground">
              Fixed-salary payroll independent from attendance, leave, holiday, overtime, late, undertime, and absence rules.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button onClick={() => setQuickSetupDialogOpen(true)} className={cn('h-10 rounded-xl px-4 font-bold', ORANGE_BUTTON)}>
            <Plus className="mr-2 size-4" /> Quick Setup
          </Button>
          <Button onClick={() => loadRows()} disabled={loading} variant="outline" className="h-10 rounded-xl border-border/80 bg-card px-4 font-semibold shadow-sm">
            <RefreshCw className={cn('mr-2 size-4', loading ? 'animate-spin' : '')} /> Refresh
          </Button>
        </div>
      </div>

      <Card className={CARD_SHELL}>
        <CardHeader className="px-4 pb-3 pt-5 sm:px-5">
          <CardTitle className="text-[17px] font-extrabold">EXECOM Employees</CardTitle>
          <CardDescription className="text-sm">Only active EXECOM employees appear in EXECOM payroll batches.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 px-4 pb-5 sm:px-5">
          <div className="grid gap-3 lg:grid-cols-[240px_minmax(260px,1fr)_220px]">
            <div className="relative">
              <Filter className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand" />
              <select className={cn(CONTROL, 'w-full pl-9')} value={filters.status} onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value, page: 1 }))}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="all">All</option>
              </select>
            </div>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input className="h-11 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/45" placeholder="Search employee" value={filters.q} onChange={(e) => setFilters((prev) => ({ ...prev, q: e.target.value }))} />
            </div>
            <Button variant="outline" onClick={() => loadRows({ ...filters, page: 1 })} className="h-11 rounded-xl border-brand/30 font-bold text-brand hover:bg-brand/10">
              <Search className="mr-2 size-4" /> Search
            </Button>
          </div>

          <div className="overflow-hidden rounded-2xl border border-border/70 bg-white dark:bg-input/15">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1120px] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th className="px-4 py-3.5 text-left">Employee</th>
                    <th className="px-4 py-3.5 text-left">Company</th>
                    <th className="px-4 py-3.5 text-left">Context</th>
                    <th className="px-4 py-3.5 text-right">Fixed Salary</th>
                    <th className="px-4 py-3.5 text-right">Other Allowance</th>
                    <th className="px-4 py-3.5 text-right">Other Deductions</th>
                    <th className="px-4 py-3.5 text-left">Status</th>
                    <th className="px-4 py-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                  {rows.map((row) => (
                    <tr key={row.id} className="transition hover:bg-[#fff8f1]/60 dark:hover:bg-input/20">
                      <td className="px-4 py-4">
                        <div className="font-extrabold leading-tight text-foreground">{row.employee_name || `#${row.employee_id}`}</div>
                        <div className="mt-1 text-xs font-medium text-muted-foreground">{row.employee_code || '—'}</div>
                      </td>
                      <td className="px-4 py-4 font-semibold text-foreground">{row.company_name || '—'}</td>
                      <td className="max-w-[220px] px-4 py-4 text-xs font-medium uppercase leading-5 tracking-[0.02em] text-muted-foreground">
                        {[row.branch_name, row.department_name].filter(Boolean).join(' / ') || '—'}
                      </td>
                      <td className="px-4 py-4 text-right font-mono font-extrabold text-foreground">
                        {formatMoney(row.fixed_salary)}
                      </td>
                      <td className="px-4 py-4 text-right font-mono font-extrabold text-emerald-600 dark:text-emerald-300">
                        {formatMoney(row.other_allowance_total)}
                      </td>
                      <td className="px-4 py-4 text-right font-mono font-extrabold text-red-600 dark:text-red-300">
                        {formatMoney(row.other_deduction_total)}
                      </td>
                      <td className="px-4 py-4">
                        <Badge variant={row.is_active ? 'default' : 'outline'} className={cn('rounded-lg px-2.5 py-1 text-[11px] font-extrabold', row.is_active ? 'bg-brand text-brand-foreground hover:bg-brand' : '')}>
                          {row.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </td>
                      <td className="px-4 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <Button size="sm" variant="outline" className="h-8 rounded-lg border-border/80 bg-white px-3 font-semibold shadow-sm hover:bg-muted dark:bg-input/35" onClick={() => openSalaryDialog(row)}>
                            <Pencil className="mr-1.5 size-3.5" /> Edit Salary
                          </Button>
                          <Button size="icon" variant="destructive" className="size-8 rounded-lg shadow-sm" onClick={() => removeRow(row)}><Trash2 className="size-4" /></Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan="8" className="px-4 py-14 text-center text-muted-foreground">
                        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl border border-brand/20 bg-brand/10 text-brand">
                          <UserPlus className="h-6 w-6" />
                        </div>
                        <div className="font-bold text-foreground">{loading ? 'Loading...' : 'No EXECOM employees found.'}</div>
                        {!loading ? <div className="mt-1 text-xs">Add an employee to get started.</div> : null}
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </div>
          {pagination ? (
            <div className="flex flex-col gap-3 pt-1 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
              <span>
                Showing {rows.length > 0 ? ((Number(pagination.current_page || 1) - 1) * Number(filters.per_page || 25)) + 1 : 0}
                {' '}to {Math.min(Number(pagination.total || 0), (Number(pagination.current_page || 1) - 1) * Number(filters.per_page || 25) + rows.length)}
                {' '}of {pagination.total} employees
              </span>
              <div className="flex items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-8 rounded-lg border-border/80 bg-card shadow-sm"
                  disabled={Number(pagination.current_page || 1) <= 1}
                  onClick={() => setFilters((prev) => ({ ...prev, page: Math.max(1, Number(prev.page || 1) - 1) }))}
                >
                  ‹
                </Button>
                <span className="flex size-8 items-center justify-center rounded-lg border border-brand/30 bg-brand/10 font-bold text-brand">
                  {pagination.current_page}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-8 rounded-lg border-border/80 bg-card shadow-sm"
                  disabled={Number(pagination.current_page || 1) >= Number(pagination.last_page || 1)}
                  onClick={() => setFilters((prev) => ({ ...prev, page: Math.min(Number(pagination.last_page || 1), Number(prev.page || 1) + 1) }))}
                >
                  ›
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Dialog open={quickSetupDialogOpen} onOpenChange={setQuickSetupDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto rounded-2xl border-border bg-card text-card-foreground sm:max-w-5xl">
          <DialogHeader>
            <DialogTitle>Quick Setup</DialogTitle>
            <DialogDescription>
              Search employees, select one or more results, then add them to EXECOM. Company, branch, department, and salary are inferred from each employee profile.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5">
            <div className="space-y-4 rounded-xl border border-border/80 bg-background/70 p-4 dark:bg-input/20">
              <div className="grid gap-3 lg:grid-cols-[1fr_120px_auto]">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    className="h-11 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/45"
                    placeholder="Search employee name, code, or email"
                    value={employeeSearch}
                    onChange={(e) => setEmployeeSearch(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') searchEmployees(e)
                    }}
                  />
                </div>
                <Button type="button" variant="outline" disabled={employeeSearchLoading} onClick={searchEmployees} className="h-11 rounded-xl border-brand/30 text-brand hover:bg-brand/10">
                  {employeeSearchLoading ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Search className="mr-2 size-4" />} Search
                </Button>
                <Button onClick={addSelectedEmployee} disabled={saving || selectedEmployees.length === 0} className={cn('h-11 rounded-xl px-5 font-bold', ORANGE_BUTTON)}>
                  {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Plus className="mr-2 size-4" />}
                  Add {selectedEmployees.length > 0 ? selectedEmployees.length : ''} EXECOM
                </Button>
              </div>

              {selectedEmployees.length > 0 ? (
                <div className="rounded-xl border border-brand/25 bg-brand/10 p-3 text-sm">
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div className="font-bold text-foreground">{selectedEmployees.length} selected for EXECOM payroll</div>
                    <Button type="button" variant="ghost" size="sm" className="h-8 rounded-lg px-2 text-muted-foreground hover:text-foreground" onClick={() => setSelectedEmployees([])}>
                      Clear selection
                    </Button>
                  </div>
                  <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    {selectedEmployees.map((employee) => (
                      <div key={employee.id} className="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-brand/20 bg-card/90 px-3 py-2">
                        <div className="flex min-w-0 items-center gap-3">
                          <Avatar className="size-10 border border-brand/25 bg-card">
                            <AvatarImage src={userProfileImageSrc(employee)} alt="" className="object-cover" />
                            <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">{initialsFor(employee)}</AvatarFallback>
                          </Avatar>
                          <div className="min-w-0">
                            <div className="truncate font-bold text-foreground">{employee.name || employee.display_name || `Employee #${employee.id}`}</div>
                            <div className="truncate text-xs text-muted-foreground">
                              {[employee.employee_code || `ID ${employee.id}`, employee.position, employeeOrgLine(employee)].filter(Boolean).join(' • ')}
                            </div>
                          </div>
                        </div>
                        <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0 rounded-lg text-muted-foreground hover:text-destructive" onClick={() => removeSelectedEmployee(employee.id)}>
                          <Trash2 className="size-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                </div>
              ) : null}

              {employeeResults.length > 0 ? (
                <div className="rounded-xl border border-border/80 bg-background p-2 shadow-sm dark:bg-input/20">
                  <div className="mb-2 flex items-center justify-between gap-2 px-1 text-xs text-muted-foreground">
                    <span>Select multiple employees from the search results.</span>
                    <span>{selectedEmployees.length} selected</span>
                  </div>
                  <div className="max-h-64 overflow-y-auto">
                    {employeeResults.map((employee) => {
                      const active = selectedEmployeeIds.has(String(employee.id))
                      const orgLine = employeeOrgLine(employee)
                      const salaryLabel = employeeSalaryLabel(employee)
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
                            <span className={cn(
                              'flex size-5 shrink-0 items-center justify-center rounded border text-[11px] font-black',
                              active ? 'border-brand-foreground/50 bg-brand-foreground text-brand' : 'border-border bg-background text-transparent',
                            )}>
                              ✓
                            </span>
                            <Avatar className={cn('size-11 border', active ? 'border-brand-foreground/30 bg-brand-foreground/10' : 'border-border bg-card')}>
                              <AvatarImage src={userProfileImageSrc(employee)} alt="" className="object-cover" />
                              <AvatarFallback className={cn('font-bold', active ? 'bg-brand-foreground/15 text-brand-foreground' : 'bg-brand/10 text-brand')}>
                                {initialsFor(employee)}
                              </AvatarFallback>
                            </Avatar>
                            <span className="min-w-0">
                              <span className="block truncate font-bold">{employee.name || employee.display_name || `Employee #${employee.id}`}</span>
                              <span className={cn('block truncate text-xs', active ? 'text-brand-foreground/80' : 'text-muted-foreground')}>
                                {[employee.employee_code || `ID ${employee.id}`, employee.position || employee.hr_role_label, employee.email].filter(Boolean).join(' • ')}
                              </span>
                              {(orgLine || salaryLabel) ? (
                                <span className={cn('mt-1 block truncate text-xs', active ? 'text-brand-foreground/75' : 'text-muted-foreground')}>
                                  {[orgLine, salaryLabel].filter(Boolean).join(' • ')}
                                </span>
                              ) : null}
                            </span>
                          </span>
                          {active ? <UserPlus className="h-4 w-4" /> : null}
                        </button>
                      )
                    })}
                  </div>
                </div>
              ) : employeeSearch.trim().length >= 2 && !employeeSearchLoading ? (
                <div className="rounded-xl border border-dashed border-border/80 bg-background/70 px-3 py-4 text-center text-sm text-muted-foreground dark:bg-input/20">
                  No matching active employees found.
                </div>
              ) : null}
            </div>

            <div className="space-y-4 rounded-xl border border-border/80 bg-background/70 p-4 dark:bg-input/20">
              <div>
                <div className="text-sm font-extrabold">EXECOM Payroll Settings</div>
                <div className="mt-1 text-xs text-muted-foreground">
                  Default settings • Configure deductions, allowances, overtime, holiday pay, and auto-present behavior.
                </div>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                {Object.keys(DEFAULT_SETTINGS).map((key) => (
                  <label
                    key={key}
                    className="flex cursor-pointer items-start gap-3 rounded-xl border border-border/80 bg-background/80 p-3 text-sm font-medium text-foreground transition hover:border-brand/40 hover:bg-brand/5 dark:bg-input/20"
                  >
                    <Checkbox
                      checked={Boolean(settings[key])}
                      onCheckedChange={(checked) => setSettings((prev) => ({ ...prev, [key]: Boolean(checked) }))}
                      className="mt-0.5 border-brand/50 data-[state=checked]:border-brand data-[state=checked]:bg-brand data-[state=checked]:text-brand-foreground"
                    />
                    <span>
                      <span className="block font-bold">{SETTINGS_LABELS[key] || key.replaceAll('_', ' ')}</span>
                      <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                        {settings[key] ? 'Enabled' : 'Disabled'}
                      </span>
                    </span>
                  </label>
                ))}
              </div>
              <div className="flex justify-end">
                <Button type="button" onClick={saveSettings} disabled={settingsSaving} className={ORANGE_BUTTON}>
                  {settingsSaving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}
                  Save Settings
                </Button>
              </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(salaryDialogRow)} onOpenChange={(open) => !open && setSalaryDialogRow(null)}>
        <DialogContent className="rounded-2xl border-border bg-card text-card-foreground">
          <DialogHeader>
            <DialogTitle>Edit EXECOM Salary</DialogTitle>
            <DialogDescription>
              Update the fixed salary used by EXECOM payroll for {salaryDialogRow?.employee_name || 'this employee'}.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor="execom-fixed-salary">Fixed Salary</Label>
            <Input
              id="execom-fixed-salary"
              type="number"
              step="0.01"
              min="0"
              value={salaryDraft}
              onChange={(e) => setSalaryDraft(e.target.value)}
              className="h-11 rounded-xl border-border/80 bg-background dark:bg-input/45"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setSalaryDialogRow(null)}>Cancel</Button>
            <Button onClick={saveSalaryEdit} className={ORANGE_BUTTON}>Save Salary</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

    </div>
  )
}
