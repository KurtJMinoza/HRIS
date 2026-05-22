import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { Crown, Loader2, Plus, RefreshCw, Save, Search, Trash2, UserRound } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/components/ui/use-toast'
import {
  getOrganizationLeadership,
  getEmployees,
  profileImageUrl,
  updateOrganizationLeadership,
} from '@/api'
import { formatEmployeeName } from '@/lib/employeeSort'
import { filterEmployeesByQuery } from '@/lib/employeeSearch'
import { cn } from '@/lib/utils'

const EMPTY_ROW = {
  position_type_id: '',
  employee_id: '',
  is_active: true,
  remarks: '',
  department_scope_mode: 'none',
  department_scope_ids: [],
  scope_request_type: 'all',
}

const SCOPE_REQUEST_TYPES = [
  ['all', 'All request types'],
  ['leave', 'Leave only'],
  ['overtime', 'Overtime only'],
]

function normalizeScopeRequestType(value) {
  const key = String(value || 'all')
  return SCOPE_REQUEST_TYPES.some(([optionValue]) => optionValue === key) ? key : 'all'
}

function normalizeRowsForCompare(rows) {
  return (rows || [])
    .filter((row) => row.position_type_id && row.employee_id)
    .map((row) => ({
      position_type_id: String(row.position_type_id),
      employee_id: String(row.employee_id),
      is_active: Boolean(row.is_active),
      remarks: String(row.remarks || '').trim(),
      department_scope_mode: row.department_scope_mode || 'none',
      department_scope_ids: (Array.isArray(row.department_scope_ids) ? row.department_scope_ids : [])
        .map((id) => Number(id))
        .filter((id) => id > 0)
        .sort((a, b) => a - b),
      scope_request_type: normalizeScopeRequestType(row.scope_request_type),
    }))
}

function mapAssignmentRows(assignments) {
  return (assignments || []).map((row) => ({
    ...row,
    position_type_id: String(row.position_type_id || ''),
    employee_id: String(row.employee_id || ''),
    remarks: row.remarks || '',
    department_scope_mode: row.department_scope_mode || 'none',
    department_scope_ids: Array.isArray(row.department_scope_ids) ? row.department_scope_ids : [],
    scope_request_type: normalizeScopeRequestType(row.scope_request_type),
    department_scope_labels: row.department_scope_labels || [],
  }))
}

function buildAssignmentsPayload(rows, positionTypes) {
  return rows
    .filter((row) => row.position_type_id && row.employee_id)
    .map((row) => {
      const positionType = positionTypes.find(
        (type) => String(type.id) === String(row.position_type_id),
      )
      const mode = row.department_scope_mode || 'none'
      const departmentScopeIds = Array.isArray(row.department_scope_ids)
        ? row.department_scope_ids.map(Number).filter((id) => id > 0)
        : []

      if (mode === 'selected' && departmentScopeIds.length === 0) {
        throw new Error('Select at least one department for the selected approval scope.')
      }

      return {
        position_type_id: Number(row.position_type_id),
        employee_id: Number(row.employee_id),
        is_active: Boolean(row.is_active),
        remarks: row.remarks?.trim() || null,
        is_primary: false,
        approval_priority: Number(positionType?.approval_priority || 1),
        effective_from: null,
        effective_to: null,
        department_scope_mode: mode,
        department_scope_ids: departmentScopeIds,
        scope_request_type: normalizeScopeRequestType(row.scope_request_type),
      }
    })
}

function scopeSummaryLabel(row) {
  if (row.department_scope_mode === 'all') return 'All departments'
  if (row.department_scope_mode === 'none') return 'None'
  const labels = row.department_scope_labels || []
  return labels.length > 0 ? labels.join(', ') : 'None selected'
}

function requestTypeLabel(value) {
  return SCOPE_REQUEST_TYPES.find(([key]) => key === value)?.[1] || 'All request types'
}

function positionTypeFor(row, positionTypes) {
  return positionTypes.find((type) => String(type.id) === String(row.position_type_id)) || null
}

function rowSupportsDepartmentScope(legacyType, row, positionTypes) {
  return legacyType === 'division' && Boolean(positionTypeFor(row, positionTypes)?.can_approve ?? true)
}

function employeeInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] || ''}${parts[parts.length - 1][0] || ''}`.toUpperCase()
}

function findEmployee(roster, employeeId) {
  if (!employeeId) return null
  return (Array.isArray(roster) ? roster : []).find((employee) => String(employee.id) === String(employeeId)) || null
}

function EmployeeSearchSelect({ value, onChange, roster, disabled }) {
  const [search, setSearch] = useState('')

  const filtered = useMemo(() => {
    const list = Array.isArray(roster) ? roster : []
    const q = search.trim().toLowerCase()
    if (!q) return list.slice(0, 200)
    return filterEmployeesByQuery(list, q).slice(0, 200)
  }, [roster, search])

  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search all active employees…"
          className="h-10 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/35"
          disabled={disabled}
        />
      </div>
      <select
        className="h-11 w-full rounded-xl border border-border/80 bg-background px-3 text-sm shadow-sm outline-none transition-colors focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled}
      >
        <option value="">Select employee</option>
        {filtered.map((employee) => (
          <option key={employee.id} value={employee.id}>
            {formatEmployeeName(employee, 'employee')}
            {employee.employee_code ? ` (${employee.employee_code})` : ''}
            {employee.company_name ? ` — ${employee.company_name}` : ''}
          </option>
        ))}
      </select>
    </div>
  )
}

function DepartmentApprovalScopeEditor({ row, index, departments, canManage, saving, onUpdate }) {
  const selectedIds = Array.isArray(row.department_scope_ids)
    ? row.department_scope_ids.map(String)
    : []

  const toggleDepartment = (departmentId) => {
    const id = String(departmentId)
    const next = selectedIds.includes(id)
      ? selectedIds.filter((value) => value !== id)
      : [...selectedIds, id]
    onUpdate(index, {
      department_scope_mode: 'selected',
      department_scope_ids: next.map(Number),
    })
  }

  return (
    <div className="space-y-4 rounded-xl border border-border/70 bg-muted/10 p-4">
      <div>
        <Label className="text-sm font-semibold text-foreground">Department Approval Scope</Label>
        <p className="mt-1 text-xs text-muted-foreground">
          Division Head approval applies only to the selected departments. If no departments are selected, this Division Head will not be used as approver for department-scoped requests.
        </p>
      </div>

      <div className="grid gap-2 sm:grid-cols-3">
        {[
          ['none', 'No departments'],
          ['selected', 'Selected departments'],
          ['all', 'All departments'],
        ].map(([mode, label]) => (
          <button
            key={mode}
            type="button"
            disabled={!canManage || saving}
            onClick={() => onUpdate(index, {
              department_scope_mode: mode,
              department_scope_ids: mode === 'selected' ? row.department_scope_ids || [] : [],
            })}
            className={cn(
              'rounded-xl border px-3 py-2.5 text-left text-sm transition-all',
              row.department_scope_mode === mode
                ? 'border-brand/60 bg-brand/5 text-brand ring-2 ring-brand/15'
                : 'border-border/70 bg-background hover:bg-muted/20',
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {row.department_scope_mode === 'selected' ? (
        <div className="max-h-44 space-y-2 overflow-y-auto rounded-xl border border-border/70 bg-background p-3">
          {departments.length === 0 ? (
            <p className="text-sm text-muted-foreground">No departments found under this division.</p>
          ) : (
            departments.map((department) => (
              <label key={department.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-muted/30">
                <input
                  type="checkbox"
                  className="size-4 rounded border-border accent-brand"
                  checked={selectedIds.includes(String(department.id))}
                  disabled={!canManage || saving}
                  onChange={() => toggleDepartment(department.id)}
                />
                <span className="text-sm text-foreground">{department.name}</span>
              </label>
            ))
          )}
        </div>
      ) : null}

      <div className="space-y-2">
        <Label className="text-sm font-semibold text-foreground">Apply to request type</Label>
        <select
          className="h-10 w-full rounded-xl border border-border/80 bg-background px-3 text-sm shadow-sm dark:bg-input/35"
          value={row.scope_request_type || 'all'}
          disabled={!canManage || saving}
          onChange={(event) => onUpdate(index, { scope_request_type: event.target.value })}
        >
          {SCOPE_REQUEST_TYPES.map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
      </div>
    </div>
  )
}

function LeadershipAssignmentCard({
  row,
  index,
  canManage,
  saving,
  positionTypes,
  roster,
  legacyType,
  departments,
  onUpdate,
  onRemove,
}) {
  const selectedEmployee = findEmployee(roster, row.employee_id)
  const displayName = selectedEmployee
    ? formatEmployeeName(selectedEmployee, 'employee')
    : row.employee_name || 'Unassigned employee'
  const roleName =
    row.position_name ||
    positionTypes.find((type) => String(type.id) === String(row.position_type_id))?.position_name ||
    'Head role'

  return (
    <article
      className={cn(
        'overflow-hidden rounded-2xl border bg-card shadow-sm',
        row.is_active ? 'border-border/80' : 'border-border/60 bg-muted/10 opacity-90',
      )}
    >
      <div className="space-y-4 p-4 @md:p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <Avatar className="size-11 border border-background shadow-sm">
              {selectedEmployee ? (
                <>
                  <AvatarImage src={profileImageUrl(selectedEmployee.profile_image)} alt="" className="object-cover" />
                  <AvatarFallback className="bg-brand/10 text-xs font-bold text-brand">
                    {employeeInitials(displayName)}
                  </AvatarFallback>
                </>
              ) : (
                <AvatarFallback className="bg-muted">
                  <UserRound className="size-5 text-muted-foreground" />
                </AvatarFallback>
              )}
            </Avatar>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="truncate text-sm font-bold text-foreground">{displayName}</h4>
                <Badge variant="outline" className="rounded-full px-2 py-0 text-[11px] font-semibold uppercase tracking-wide">
                  {roleName}
                </Badge>
                <Badge
                  variant={row.is_active ? 'default' : 'secondary'}
                  className={cn('rounded-full px-2 py-0', row.is_active && 'bg-emerald-600 hover:bg-emerald-600 dark:bg-emerald-700')}
                >
                  {row.is_active ? 'Active' : 'Inactive'}
                </Badge>
              </div>
              <p className="mt-0.5 truncate text-xs text-muted-foreground">
                {selectedEmployee
                  ? [selectedEmployee.employee_code, selectedEmployee.company_name].filter(Boolean).join(' · ')
                  : 'Select a head role and employee below'}
              </p>
            </div>
          </div>
          {canManage ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-9 rounded-xl text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => onRemove(index)}
              disabled={saving}
            >
              <Trash2 className="mr-1.5 size-4" />
              Remove
            </Button>
          ) : null}
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <div className="space-y-2">
            <Label className="text-sm font-semibold text-foreground">Head role</Label>
            {canManage ? (
              <select
                className="h-11 w-full rounded-xl border border-border/80 bg-background px-3 text-sm shadow-sm outline-none transition-colors focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
                value={row.position_type_id}
                onChange={(event) => onUpdate(index, { position_type_id: event.target.value })}
                disabled={saving}
              >
                <option value="">Select role</option>
                {positionTypes.map((type) => (
                  <option key={type.id} value={type.id}>
                    {type.position_name}
                  </option>
                ))}
              </select>
            ) : (
              <p className="text-sm font-medium text-foreground">{roleName}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label className="text-sm font-semibold text-foreground">Employee</Label>
            {canManage ? (
              <EmployeeSearchSelect
                value={row.employee_id}
                onChange={(employeeId) => onUpdate(index, { employee_id: employeeId })}
                roster={roster}
                disabled={saving}
              />
            ) : (
              <p className="text-sm font-medium text-foreground">{displayName}</p>
            )}
          </div>
        </div>

        {canManage ? (
          <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/15 px-3 py-3">
            <div>
              <Label className="text-sm font-semibold text-foreground">Active assignment</Label>
              <p className="text-xs text-muted-foreground">Inactive leaders are skipped in approval routing.</p>
            </div>
            <Switch
              checked={Boolean(row.is_active)}
              onCheckedChange={(checked) => onUpdate(index, { is_active: checked })}
              disabled={saving}
            />
          </div>
        ) : null}

        {canManage ? (
          <div className="space-y-2">
            <Label className="text-sm font-semibold text-foreground">Remarks</Label>
            <Textarea
              value={row.remarks}
              onChange={(event) => onUpdate(index, { remarks: event.target.value })}
              placeholder="Optional notes (e.g. acting head, shared assignment)"
              rows={2}
              className="min-h-[72px] rounded-xl border-border/80 bg-background shadow-sm dark:bg-input/35"
              disabled={saving}
            />
          </div>
        ) : row.remarks ? (
          <div className="space-y-1">
            <Label className="text-sm font-semibold text-foreground">Remarks</Label>
            <p className="rounded-xl border border-border/70 bg-muted/15 px-3 py-2 text-sm text-muted-foreground">
              {row.remarks}
            </p>
          </div>
        ) : null}

        {rowSupportsDepartmentScope(legacyType, row, positionTypes) ? (
          <DepartmentApprovalScopeEditor
            row={row}
            index={index}
            departments={departments}
            canManage={canManage}
            saving={saving}
            onUpdate={onUpdate}
          />
        ) : null}
      </div>
    </article>
  )
}

const LeadershipPositionsSection = forwardRef(function LeadershipPositionsSection({
  legacyType,
  legacyId,
  canManage = false,
  title = 'Leadership / Assign Head',
  employeeOptions = null,
}, ref) {
  const { toast } = useToast()
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [payload, setPayload] = useState(null)
  const [rows, setRows] = useState([])
  const [employees, setEmployees] = useState([])
  const savedSnapshotRef = useRef('[]')

  const positionTypes = payload?.position_types || []

  const applyRows = useCallback((nextRows) => {
    setRows(nextRows)
    savedSnapshotRef.current = JSON.stringify(normalizeRowsForCompare(nextRows))
  }, [])

  const load = useCallback(async () => {
    if (!legacyType || !legacyId) return
    setLoading(true)
    try {
      const [leadership, roster] = await Promise.all([
        getOrganizationLeadership(legacyType, legacyId),
        employeeOptions
          ? Promise.resolve({ employees: employeeOptions })
          : getEmployees({ for_leadership_assignment: true, per_page: 'all' }),
      ])
      setPayload(leadership)
      applyRows(mapAssignmentRows(leadership.assignments))
      if (!employeeOptions) {
        setEmployees(Array.isArray(roster?.employees) ? roster.employees : roster?.data || [])
      }
    } catch (error) {
      toast({ variant: 'destructive', title: 'Failed to load leadership positions', description: error.message })
    } finally {
      setLoading(false)
    }
  }, [applyRows, employeeOptions, legacyId, legacyType, toast])

  useEffect(() => {
    load()
  }, [load])

  const roster = useMemo(
    () => (employeeOptions ? employeeOptions : employees),
    [employeeOptions, employees],
  )

  const activeCount = useMemo(() => rows.filter((row) => row.is_active).length, [rows])

  const departments = payload?.departments || []

  const addRow = () => {
    const defaultType = positionTypes[0]
    setRows((prev) => [
      ...prev,
      {
        ...EMPTY_ROW,
        position_type_id: defaultType ? String(defaultType.id) : '',
      },
    ])
  }

  const updateRow = (index, patch) => {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  const removeRow = (index) => {
    setRows((prev) => prev.filter((_, i) => i !== index))
  }

  const save = useCallback(async () => {
    if (!canManage) return false
    setSaving(true)
    try {
      const assignments = buildAssignmentsPayload(rows, positionTypes)
      const response = await updateOrganizationLeadership(legacyType, legacyId, { assignments })
      setPayload(response)
      applyRows(mapAssignmentRows(response.assignments))
      toast({ title: 'Leadership positions saved' })
      return true
    } catch (error) {
      toast({ variant: 'destructive', title: 'Failed to save leadership positions', description: error.message })
      return false
    } finally {
      setSaving(false)
    }
  }, [applyRows, canManage, legacyId, legacyType, positionTypes, rows, toast])

  useImperativeHandle(ref, () => ({
    save,
    isDirty: () => JSON.stringify(normalizeRowsForCompare(rows)) !== savedSnapshotRef.current,
  }), [rows, save])

  if (!legacyType || !legacyId) return null

  return (
    <section className="overflow-hidden rounded-2xl border border-border/80 bg-muted/15 shadow-sm">
      <div className="border-b border-border/70 bg-card/80 px-4 py-4 @md:px-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex min-w-0 items-start gap-3">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand shadow-sm">
              <Crown className="size-5" />
            </div>
            <div className="min-w-0">
              <h3 className="text-base font-bold text-foreground @md:text-lg">{title}</h3>
              <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Assign multiple heads or acting leaders from any company. Cross-company and shared leadership is allowed.
              </p>
              {!loading && rows.length > 0 ? (
                <div className="mt-3 flex flex-wrap gap-2">
                  <Badge variant="secondary" className="rounded-full px-2.5">
                    {rows.length} assigned
                  </Badge>
                  <Badge variant="secondary" className="rounded-full px-2.5">
                    {activeCount} active
                  </Badge>
                </div>
              ) : null}
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={load}
              disabled={loading || saving}
              className="h-9 rounded-xl border-border/80"
            >
              <RefreshCw className={cn('mr-2 size-4', loading && 'animate-spin')} />
              Refresh
            </Button>
            {canManage ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={addRow}
                  className="h-9 rounded-xl border-border/80"
                >
                  <Plus className="mr-2 size-4" />
                  Add head
                </Button>
                <Button
                  type="button"
                  size="sm"
                  onClick={save}
                  disabled={saving || loading}
                  className="h-9 rounded-xl bg-brand text-brand-foreground shadow-[0_6px_18px_rgba(249,115,22,0.22)] hover:bg-brand-strong"
                >
                  {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}
                  Save
                </Button>
              </>
            ) : null}
          </div>
        </div>
      </div>

      <div className="p-4 @md:p-5">
        {loading ? (
          <div className="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-border/80 bg-background/60 py-12 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            Loading leadership positions…
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border/80 bg-background/60 px-6 py-12 text-center">
            <div className="flex size-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
              <Crown className="size-7" />
            </div>
            <h4 className="mt-4 text-base font-semibold text-foreground">No leadership assigned yet</h4>
            <p className="mt-2 max-w-md text-sm text-muted-foreground">
              Add department heads, acting leaders, or shared approvers. You can assign employees from any company.
            </p>
            {canManage ? (
              <Button type="button" onClick={addRow} className="mt-5 rounded-xl">
                <Plus className="mr-2 size-4" />
                Add first head
              </Button>
            ) : null}
          </div>
        ) : (
          <div className="space-y-4">
            {legacyType === 'division' && rows.length > 0 ? (
              <div className="overflow-x-auto rounded-2xl border border-border/70 bg-background shadow-sm">
                <table className="min-w-full text-sm">
                  <thead className="border-b border-border/70 bg-muted/20 text-left">
                    <tr>
                      <th className="px-4 py-3 font-semibold">Head Name</th>
                      <th className="px-4 py-3 font-semibold">Position</th>
                      <th className="px-4 py-3 font-semibold">Priority</th>
                      <th className="px-4 py-3 font-semibold">Can Approve</th>
                      <th className="px-4 py-3 font-semibold">Department Approval Scope</th>
                      <th className="px-4 py-3 font-semibold">Request Types</th>
                      <th className="px-4 py-3 font-semibold">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row, index) => {
                      const type = positionTypeFor(row, positionTypes)
                      const employee = findEmployee(roster, row.employee_id)
                      return (
                        <tr key={`summary-${row.id || index}`} className="border-b border-border/60 last:border-b-0">
                          <td className="px-4 py-3">{employee ? formatEmployeeName(employee, 'employee') : row.employee_name || '—'}</td>
                          <td className="px-4 py-3">{type?.position_name || row.position_name || '—'}</td>
                          <td className="px-4 py-3">{row.approval_priority ?? type?.approval_priority ?? '—'}</td>
                          <td className="px-4 py-3">{type?.can_approve === false ? 'No' : 'Yes'}</td>
                          <td className="px-4 py-3">{rowSupportsDepartmentScope(legacyType, row, positionTypes) ? scopeSummaryLabel(row) : '—'}</td>
                          <td className="px-4 py-3">{rowSupportsDepartmentScope(legacyType, row, positionTypes) ? requestTypeLabel(row.scope_request_type || 'all') : '—'}</td>
                          <td className="px-4 py-3">{row.is_active ? 'Active' : 'Inactive'}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ) : null}

            {rows.map((row, index) => (
              <LeadershipAssignmentCard
                key={`${row.id || 'new'}-${index}`}
                row={row}
                index={index}
                canManage={canManage}
                saving={saving}
                positionTypes={positionTypes}
                roster={roster}
                legacyType={legacyType}
                departments={departments}
                onUpdate={updateRow}
                onRemove={removeRow}
              />
            ))}
          </div>
        )}
      </div>
    </section>
  )
})

export default LeadershipPositionsSection
