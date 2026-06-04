import { forwardRef, memo, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
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
  profileImageUrl,
  updateOrganizationLeadership,
} from '@/api'
import { formatEmployeeName } from '@/lib/employeeSort'
import { employeeDisplayName, headAssignmentPrimaryLine, headAssignmentSecondaryLine, normalizeLeaderUserId } from '@/lib/employeeSearch'
import { useHeadAssignmentEmployeeSearch } from '@/hooks/useHeadAssignmentEmployeeSearch'
import { cn } from '@/lib/utils'

const EMPTY_ROW = {
  position_type_id: '',
  employee_id: '',
  is_active: true,
  remarks: '',
  approval_scope_type: '',
  approval_scope_mode: 'none',
  approval_scope_ids: [],
  department_scope_mode: 'none',
  department_scope_ids: [],
  scope_request_type: 'all',
}

const SCOPE_REQUEST_TYPES = [
  ['all', 'All request types'],
  ['leave', 'Leave'],
  ['overtime', 'Overtime'],
  ['attendance_correction', 'Attendance Correction'],
  ['official_business', 'Official Business'],
  ['change_schedule', 'Change Schedule'],
  ['payroll_approval', 'Payroll Approval'],
]

const SCOPE_TYPE_LABELS = {
  company: 'Company',
  area: 'Area',
  branch: 'Branch',
  division: 'Division',
  department: 'Department',
  section_unit: 'Section',
}

const SCOPE_TYPE_PLURAL_LABELS = {
  company: 'Companies',
  area: 'Areas',
  branch: 'Branches',
  division: 'Divisions',
  department: 'Departments',
  section_unit: 'Sections',
}

const DEFAULT_SCOPE_TYPES_BY_LEGACY_TYPE = {
  company: ['company', 'area', 'branch', 'division', 'department', 'section_unit'],
  area: ['area', 'branch', 'division', 'department', 'section_unit'],
  branch: ['branch', 'division', 'department', 'section_unit'],
  division: ['department'],
}

function normalizeScopeRequestType(value) {
  const key = String(value || 'all')
  return SCOPE_REQUEST_TYPES.some(([optionValue]) => optionValue === key) ? key : 'all'
}

function normalizeRowsForCompare(rows, legacyType = '') {
  return (rows || [])
    .filter((row) => row.position_type_id && row.employee_id)
    .map((row) => ({
      position_type_id: String(row.position_type_id),
      employee_id: String(row.employee_id),
      is_active: Boolean(row.is_active),
      remarks: String(row.remarks || '').trim(),
      approval_scope_type: resolveApprovalScopeType(row, legacyType),
      approval_scope_mode: row.approval_scope_mode || row.department_scope_mode || 'none',
      approval_scope_ids: normalizeScopeIds(row).sort((a, b) => a - b),
      department_scope_mode: row.department_scope_mode || 'none',
      department_scope_ids: (Array.isArray(row.department_scope_ids) ? row.department_scope_ids : [])
        .map((id) => Number(id))
        .filter((id) => id > 0)
        .sort((a, b) => a - b),
      scope_request_type: normalizeScopeRequestType(row.scope_request_type),
    }))
}

function normalizeScopeIds(row) {
  const raw = Array.isArray(row.approval_scope_ids)
    ? row.approval_scope_ids
    : Array.isArray(row.department_scope_ids)
      ? row.department_scope_ids
      : []

  return raw.map((id) => Number(id)).filter((id) => id > 0)
}

function resolveApprovalScopeType(row, legacyType) {
  const mode = row.approval_scope_mode || row.department_scope_mode || 'none'
  if (mode === 'none') {
    return 'none'
  }

  const explicit = row.approval_scope_type
  if (explicit && explicit !== 'none') {
    return explicit
  }

  if (row.department_scope_mode && row.department_scope_mode !== 'none') {
    return 'department'
  }

  return DEFAULT_SCOPE_TYPES_BY_LEGACY_TYPE[legacyType]?.[0] || 'department'
}

function inferScopeTypeFromIds(scopeIds, optionsByType, allowedTypes) {
  if (!Array.isArray(scopeIds) || scopeIds.length === 0) {
    return ''
  }

  const idSet = new Set(scopeIds.map((id) => String(id)))
  for (const type of allowedTypes) {
    const options = optionsByType[type] || []
    if (options.length === 0) {
      continue
    }
    if ([...idSet].every((id) => options.some((option) => String(option.id) === id))) {
      return type
    }
  }

  return ''
}

function resolvePositionTypeId(row, positionTypes) {
  const id = String(row.position_type_id || '')
  if (id && positionTypes.some((type) => String(type.id) === id)) {
    return id
  }
  const byName = positionTypes.find(
    (type) => type.position_name && row.position_name && type.position_name === row.position_name,
  )
  if (byName) {
    return String(byName.id)
  }
  if (positionTypes.length === 1) {
    return String(positionTypes[0].id)
  }
  return id
}

function mapAssignmentRows(assignments, positionTypes = [], legacyType = '') {
  return (assignments || []).map((row) => {
    const approvalScopeMode = row.approval_scope_mode || row.department_scope_mode || 'none'
    const approvalScopeType = resolveApprovalScopeType(row, legacyType)
    const approvalScopeIds = normalizeScopeIds(row)
    const isDepartmentScope = approvalScopeType === 'department'

    return {
      ...row,
      position_type_id: resolvePositionTypeId(row, positionTypes),
      employee_id: String(row.employee_id || ''),
      remarks: row.remarks || '',
      approval_scope_type: approvalScopeType,
      approval_scope_mode: approvalScopeMode,
      approval_scope_ids: approvalScopeIds,
      approval_scope_labels: row.approval_scope_labels || row.department_scope_labels || [],
      department_scope_mode: isDepartmentScope ? approvalScopeMode : 'none',
      department_scope_ids: isDepartmentScope ? approvalScopeIds : [],
      scope_request_type: normalizeScopeRequestType(row.scope_request_type),
      department_scope_labels: isDepartmentScope ? (row.department_scope_labels || row.approval_scope_labels || []) : [],
    }
  })
}

function buildAssignmentsPayload(rows, positionTypes, legacyType) {
  return rows
    .filter((row) => row.position_type_id && row.employee_id)
    .map((row) => {
      const positionType = positionTypes.find(
        (type) => String(type.id) === String(row.position_type_id),
      )
      const mode = row.approval_scope_mode || row.department_scope_mode || 'none'
      const scopeType = resolveApprovalScopeType(row, legacyType)
      const scopeIds = normalizeScopeIds(row)
      const departmentScopeIds = scopeType === 'department'
        ? scopeIds
        : []

      if (mode === 'selected' && scopeIds.length === 0) {
        throw new Error('Select at least one item for the selected approval scope.')
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
        approval_scope_type: scopeType,
        approval_scope_mode: mode,
        approval_scope_ids: scopeIds,
        department_scope_mode: scopeType === 'department' ? mode : 'none',
        department_scope_ids: departmentScopeIds,
        scope_request_type: normalizeScopeRequestType(row.scope_request_type),
      }
    })
}

function scopeSummaryLabel(row) {
  const mode = row.approval_scope_mode || row.department_scope_mode || 'none'
  if (mode === 'none' || row.approval_scope_type === 'none') return 'No approval scope'
  const scopeType = row.approval_scope_type || 'department'
  const label = SCOPE_TYPE_LABELS[scopeType]?.toLowerCase() || 'items'
  if (mode === 'all') return scopeType === 'company' || scopeType === 'area' || scopeType === 'branch' ? `Entire ${label}` : `All ${label}s`
  const labels = row.approval_scope_labels || row.department_scope_labels || []
  return labels.length > 0 ? labels.join(', ') : 'None selected'
}

function requestTypeLabel(value) {
  return SCOPE_REQUEST_TYPES.find(([key]) => key === value)?.[1] || 'All request types'
}

function positionTypeFor(row, positionTypes) {
  return positionTypes.find((type) => String(type.id) === String(row.position_type_id)) || null
}

function rowSupportsApprovalScope(legacyType, row, positionTypes) {
  return Boolean(DEFAULT_SCOPE_TYPES_BY_LEGACY_TYPE[legacyType]) && Boolean(positionTypeFor(row, positionTypes)?.can_approve ?? true)
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

function EmployeeSearchSelect({ value, onChange, roster, disabled, searchFilters = {}, assignmentHint = null }) {
  const selectedFromRoster = useMemo(() => findEmployee(roster, value), [roster, value])
  const selectedEmployee = useMemo(() => {
    if (selectedFromRoster) return selectedFromRoster
    if (!value) return null
    const hintId = normalizeLeaderUserId(assignmentHint?.id ?? assignmentHint?.employee_id ?? value)
    if (!hintId) return null
    return {
      id: hintId,
      employee_id: hintId,
      name: assignmentHint?.employee_name || assignmentHint?.name || assignmentHint?.display_name || 'Assigned employee',
      display_name: assignmentHint?.employee_name || assignmentHint?.display_name || assignmentHint?.name || 'Assigned employee',
    }
  }, [assignmentHint, selectedFromRoster, value])

  const { query, setQuery, results, loading, error } = useHeadAssignmentEmployeeSearch({
    enabled: !disabled,
    searchFilters: {
      include_cross_company: searchFilters.include_cross_company !== false,
      active_only: searchFilters.active_only !== false,
      ...searchFilters,
    },
    selectedEmployee,
  })

  const selectedInResults = useMemo(
    () => results.some((employee) => normalizeLeaderUserId(employee.id ?? employee.employee_id) === normalizeLeaderUserId(value)),
    [results, value],
  )

  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search all active employees…"
          className="h-10 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/35"
          disabled={disabled}
        />
        {loading ? (
          <Loader2 className="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
        ) : null}
      </div>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
      <select
        className="h-11 w-full rounded-xl border border-border/80 bg-background px-3 text-sm shadow-sm outline-none transition-colors focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled}
      >
        <option value="">Select employee</option>
        {value && !selectedInResults && selectedEmployee ? (
          <option value={normalizeLeaderUserId(value)}>
            {headAssignmentPrimaryLine(selectedEmployee)}
            {headAssignmentSecondaryLine(selectedEmployee) ? ` — ${headAssignmentSecondaryLine(selectedEmployee)}` : ''}
          </option>
        ) : null}
        {results.map((employee) => {
          const employeeId = normalizeLeaderUserId(employee.id ?? employee.employee_id)
          return (
            <option key={employeeId} value={employeeId}>
              {headAssignmentPrimaryLine(employee)}
              {headAssignmentSecondaryLine(employee) ? ` — ${headAssignmentSecondaryLine(employee)}` : ''}
            </option>
          )
        })}
      </select>
      {!query.trim() && results.length === 0 && !loading ? (
        <p className="text-xs text-muted-foreground">Type a name, employee number, or email to search.</p>
      ) : null}
    </div>
  )
}

const ApprovalScopeEditor = memo(function ApprovalScopeEditor({ row, index, legacyType, scopeOptions, canManage, saving, onUpdate }) {
  const configuredTypes = DEFAULT_SCOPE_TYPES_BY_LEGACY_TYPE[legacyType] || []
  const optionTypes = Object.keys(scopeOptions || {}).filter((type) => SCOPE_TYPE_LABELS[type])
  const allowedTypes = [...new Set([...configuredTypes, ...optionTypes])]
  const optionsByType = scopeOptions || {}
  const currentMode = row.approval_scope_mode || row.department_scope_mode || 'none'
  const selectedIds = normalizeScopeIds(row).map(String)
  const inferredType = inferScopeTypeFromIds(selectedIds, optionsByType, allowedTypes)
  const savedType = row.approval_scope_type && row.approval_scope_type !== 'none' && allowedTypes.includes(row.approval_scope_type)
    ? row.approval_scope_type
    : inferredType
  const currentType = savedType || allowedTypes[0] || 'department'
  const currentOptions = optionsByType[currentType] || []
  const currentPluralLabel = SCOPE_TYPE_PLURAL_LABELS[currentType] || `${SCOPE_TYPE_LABELS[currentType] || 'Item'}s`

  const updateScope = (patch) => {
    const nextMode = patch.approval_scope_mode ?? currentMode
    const nextType = nextMode === 'none'
      ? 'none'
      : patch.approval_scope_type || currentType
    const nextIds = patch.approval_scope_ids ?? (nextType === currentType ? selectedIds.map(Number) : [])
    onUpdate(index, {
      approval_scope_type: nextType,
      approval_scope_mode: nextMode,
      approval_scope_ids: nextIds,
      department_scope_mode: nextType === 'department' ? nextMode : 'none',
      department_scope_ids: nextType === 'department' ? nextIds : [],
    })
  }

  const toggleItem = (scopeId) => {
    const id = String(scopeId)
    const next = selectedIds.includes(id)
      ? selectedIds.filter((value) => value !== id)
      : [...selectedIds, id]
    updateScope({
      approval_scope_mode: 'selected',
      approval_scope_ids: next.map(Number),
    })
  }

  return (
    <div className="space-y-4 rounded-xl border border-border/70 bg-muted/10 p-4">
      <div>
        <Label className="text-sm font-semibold text-foreground">Approval Scope</Label>
        <p className="mt-1 text-xs text-muted-foreground">
          Limit when this head is used in approval routing. Inactive leaders, duplicate approvers, and self-approval are skipped by the resolver.
        </p>
      </div>

      {allowedTypes.length > 1 ? (
        <div className="space-y-2">
          <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Scope level ({legacyType === 'company' ? 'Company / Area / Branch / Division / Department / Section' : legacyType === 'branch' ? 'Branch / Division / Department / Section' : 'Area / Branch / Division / Department / Section'})
          </Label>
          <select
            className="h-10 w-full rounded-xl border border-border/80 bg-background px-3 text-sm shadow-sm dark:bg-input/35"
            value={currentType}
            disabled={!canManage || saving}
            onChange={(event) => updateScope({
              approval_scope_type: event.target.value,
              approval_scope_mode: event.target.value === currentType ? currentMode : 'all',
              approval_scope_ids: [],
            })}
          >
            {allowedTypes.map((type) => (
              <option key={type} value={type}>
                {type === legacyType ? `Entire ${SCOPE_TYPE_LABELS[type]?.toLowerCase() || type}` : SCOPE_TYPE_PLURAL_LABELS[type] || `${SCOPE_TYPE_LABELS[type] || type}s`}
              </option>
            ))}
          </select>
          <div className="flex flex-wrap gap-1.5">
            {allowedTypes.map((type) => (
              <button
                key={`scope-chip-${type}`}
                type="button"
                disabled={!canManage || saving}
                onClick={() => updateScope({
                  approval_scope_type: type,
                  approval_scope_mode: type === currentType ? currentMode : 'all',
                  approval_scope_ids: [],
                })}
                className={cn(
                  'rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors',
                  currentType === type
                    ? 'border-brand/60 bg-brand/10 text-brand'
                    : 'border-border/70 bg-background text-muted-foreground hover:bg-muted/30',
                )}
              >
                {type === legacyType ? `Entire ${SCOPE_TYPE_LABELS[type]}` : SCOPE_TYPE_PLURAL_LABELS[type]}
              </button>
            ))}
          </div>
        </div>
      ) : null}

      <div className="grid gap-2 sm:grid-cols-3">
        {[
          ['none', 'No approval scope'],
          ['selected', `Selected ${currentPluralLabel.toLowerCase()}`],
          ['all', currentType === legacyType ? `Entire ${SCOPE_TYPE_LABELS[currentType]?.toLowerCase() || currentType}` : `All ${currentPluralLabel.toLowerCase()}`],
        ].map(([mode, label]) => (
          <button
            key={mode}
            type="button"
            disabled={!canManage || saving}
            onClick={() => updateScope({
              approval_scope_mode: mode,
              approval_scope_ids: mode === 'selected' ? selectedIds.map(Number) : [],
            })}
            className={cn(
              'rounded-xl border px-3 py-2.5 text-left text-sm transition-all',
              currentMode === mode
                ? 'border-brand/60 bg-brand/5 text-brand ring-2 ring-brand/15'
                : 'border-border/70 bg-background hover:bg-muted/20',
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {currentMode === 'selected' ? (
        <div className="max-h-44 space-y-2 overflow-y-auto rounded-xl border border-border/70 bg-background p-3">
          {currentOptions.length === 0 ? (
            <p className="text-sm text-muted-foreground">No {currentPluralLabel.toLowerCase()} found for this unit.</p>
          ) : (
            currentOptions.map((option) => (
              <label key={option.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-muted/30">
                <input
                  type="checkbox"
                  className="size-4 rounded border-border accent-brand"
                  checked={selectedIds.includes(String(option.id))}
                  disabled={!canManage || saving}
                  onChange={() => toggleItem(option.id)}
                />
                <span className="text-sm text-foreground">{option.name}</span>
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
})

const LeadershipAssignmentCard = memo(function LeadershipAssignmentCard({
  row,
  index,
  canManage,
  saving,
  positionTypes,
  roster,
  legacyType,
  scopeOptions,
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
                assignmentHint={{
                  id: row.employee_id,
                  employee_name: row.employee_name,
                  name: row.employee_name,
                }}
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

        {rowSupportsApprovalScope(legacyType, row, positionTypes) ? (
          <ApprovalScopeEditor
            row={row}
            index={index}
            legacyType={legacyType}
            scopeOptions={scopeOptions}
            canManage={canManage}
            saving={saving}
            onUpdate={onUpdate}
          />
        ) : null}
      </div>
    </article>
  )
})

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
    savedSnapshotRef.current = JSON.stringify(normalizeRowsForCompare(nextRows, legacyType))
  }, [legacyType])

  const load = useCallback(async () => {
    if (!legacyType || !legacyId) return
    setLoading(true)
    try {
      const leadership = await getOrganizationLeadership(legacyType, legacyId)
      setPayload(leadership)
      applyRows(mapAssignmentRows(leadership.assignments, leadership.position_types || [], legacyType))
      if (employeeOptions) {
        setEmployees(Array.isArray(employeeOptions) ? employeeOptions : [])
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

  const scopeOptions = useMemo(() => {
    if (payload?.approval_scope_options && typeof payload.approval_scope_options === 'object') {
      return payload.approval_scope_options
    }
    return {
      department: payload?.departments || [],
    }
  }, [payload])

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

  const updateRow = useCallback((index, patch) => {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }, [])

  const removeRow = (index) => {
    setRows((prev) => prev.filter((_, i) => i !== index))
  }

  const save = useCallback(async () => {
    if (!canManage) return false
    setSaving(true)
    try {
      const assignments = buildAssignmentsPayload(rows, positionTypes, legacyType)
      const response = await updateOrganizationLeadership(legacyType, legacyId, { assignments })
      setPayload(response)
      applyRows(mapAssignmentRows(response.assignments, response.position_types || positionTypes, legacyType))
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
    isDirty: () => JSON.stringify(normalizeRowsForCompare(rows, legacyType)) !== savedSnapshotRef.current,
  }), [legacyType, rows, save])

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
            {rowSupportsApprovalScope(legacyType, rows[0], positionTypes) && rows.length > 0 ? (
              <div className="overflow-x-auto rounded-2xl border border-border/70 bg-background shadow-sm">
                <table className="min-w-full text-sm">
                  <thead className="border-b border-border/70 bg-muted/20 text-left">
                    <tr>
                      <th className="px-4 py-3 font-semibold">Head Name</th>
                      <th className="px-4 py-3 font-semibold">Position</th>
                      <th className="px-4 py-3 font-semibold">Priority</th>
                      <th className="px-4 py-3 font-semibold">Can Approve</th>
                      <th className="px-4 py-3 font-semibold">Approval Scope</th>
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
                          <td className="px-4 py-3">{rowSupportsApprovalScope(legacyType, row, positionTypes) ? scopeSummaryLabel(row) : '—'}</td>
                          <td className="px-4 py-3">{rowSupportsApprovalScope(legacyType, row, positionTypes) ? requestTypeLabel(row.scope_request_type || 'all') : '—'}</td>
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
                scopeOptions={scopeOptions}
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
