import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ClipboardList,
  FileSpreadsheet,
  Info,
  Loader2,
  MoreVertical,
  Save,
  ShieldCheck,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/components/ui/use-toast'
import { getEvaluationWorkflowSettings, updateEvaluationWorkflowSettings } from '@/api'
import { cn } from '@/lib/utils'

const HIERARCHY_STEP_FLAGS = [
  { key: 'include_section_head', label: 'Section / Team' },
  { key: 'include_department_head', label: 'Department' },
  { key: 'include_division_head', label: 'Division' },
  { key: 'include_branch_head', label: 'Branch' },
  { key: 'include_area_head', label: 'Area' },
  { key: 'include_company_head', label: 'Company' },
]

const WORKFLOW_STEP_FLAGS = [
  ...HIERARCHY_STEP_FLAGS,
  { key: 'include_admin_hr', label: 'Admin HR', final: true },
]

const CHAIN_MODE_OPTIONS = [
  { value: 'nearest_plus_admin', label: 'Nearest Approver + Admin HR' },
  { value: 'full_hierarchy', label: 'Full Hierarchy + Admin HR' },
  { value: 'custom_selected_steps', label: 'Custom Selected Steps' },
]

function formatUpdatedAt(value) {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
    }).format(new Date(value))
  } catch {
    return '—'
  }
}

function splitDateTime(value) {
  const formatted = formatUpdatedAt(value)
  if (formatted === '—') return ['—', '']
  const commaParts = formatted.split(', ')
  if (commaParts.length >= 3) {
    return [`${commaParts[0]}, ${commaParts[1]}`, commaParts.slice(2).join(', ')]
  }
  return [formatted, '']
}

function snapshotRow(row) {
  return {
    request_type: row.request_type,
    use_hierarchy_approval: Boolean(row.use_hierarchy_approval),
    fallback_to_parent_approver: Boolean(row.fallback_to_parent_approver),
    approval_chain_mode: row.approval_chain_mode || 'custom_selected_steps',
    max_org_approval_steps: row.max_org_approval_steps ?? null,
    include_section_head: Boolean(row.include_section_head),
    include_department_head: Boolean(row.include_department_head),
    include_division_head: Boolean(row.include_division_head),
    include_branch_head: Boolean(row.include_branch_head),
    include_area_head: Boolean(row.include_area_head),
    include_company_head: Boolean(row.include_company_head),
    include_admin_hr: row.include_admin_hr !== false,
    is_active: row.is_active !== false,
  }
}

function toggleText(enabled) {
  return enabled ? 'ON' : 'OFF'
}

function ModuleIcon() {
  return (
    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/20">
      <FileSpreadsheet className="size-5" />
    </span>
  )
}

function WorkflowSwitch({ checked, disabled, label, onCheckedChange }) {
  return (
    <div className="flex items-center gap-3">
      <Switch
        checked={checked}
        disabled={disabled}
        onCheckedChange={onCheckedChange}
        aria-label={label}
        className="data-[state=checked]:bg-brand data-[state=unchecked]:bg-muted dark:data-[state=unchecked]:bg-input/80"
      />
      <span className={cn('text-xs font-extrabold tracking-wide', checked ? 'text-foreground' : 'text-muted-foreground')}>
        {toggleText(checked)}
      </span>
    </div>
  )
}

function StepSwitch({ row, step, hierarchyOn, onToggle }) {
  const checked = step.final ? row[step.key] !== false : Boolean(row[step.key])
  const disabled = !step.final && !hierarchyOn

  return (
    <label
      className={cn(
        'flex items-center justify-between gap-3 rounded-lg border border-border/70 bg-background px-3 py-2 dark:bg-input/15',
        disabled && 'opacity-60',
      )}
    >
      <span className="text-xs font-bold text-foreground">{step.label}</span>
      <Switch
        checked={checked}
        disabled={disabled}
        onCheckedChange={(value) => onToggle(step.key, value)}
        aria-label={`${step.label} step for Performance Evaluation`}
        className="data-[state=checked]:bg-brand data-[state=unchecked]:bg-muted dark:data-[state=unchecked]:bg-input/80"
      />
    </label>
  )
}

export default function AdminEvaluationWorkflowSettings() {
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [helperText, setHelperText] = useState('')
  const [rows, setRows] = useState([])
  const [savedSnapshot, setSavedSnapshot] = useState('[]')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getEvaluationWorkflowSettings()
      const settings = Array.isArray(data.settings) ? data.settings : []
      setRows(settings)
      setHelperText(data.helper_text || '')
      setSavedSnapshot(JSON.stringify(settings.map(snapshotRow)))
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Failed to load evaluation workflow settings',
        description: error.message,
      })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    void load()
  }, [load])

  const isDirty = useMemo(() => {
    return JSON.stringify(rows.map(snapshotRow)) !== savedSnapshot
  }, [rows, savedSnapshot])

  const updateRow = (requestType, patch) => {
    setRows((prev) => prev.map((row) => (
      row.request_type === requestType ? { ...row, ...patch } : row
    )))
  }

  const resetRow = (row) => {
    updateRow(row.request_type, {
      use_hierarchy_approval: true,
      fallback_to_parent_approver: false,
      approval_chain_mode: 'custom_selected_steps',
      max_org_approval_steps: null,
      include_section_head: true,
      include_department_head: true,
      include_division_head: true,
      include_branch_head: true,
      include_area_head: true,
      include_company_head: true,
      include_admin_hr: true,
      is_active: true,
    })
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const data = await updateEvaluationWorkflowSettings({
        settings: rows.map((row) => ({
          request_type: row.request_type,
          use_hierarchy_approval: Boolean(row.use_hierarchy_approval),
          fallback_to_parent_approver: Boolean(row.fallback_to_parent_approver),
          approval_chain_mode: row.approval_chain_mode || 'custom_selected_steps',
          max_org_approval_steps: row.max_org_approval_steps === '' || row.max_org_approval_steps == null
            ? null
            : Number(row.max_org_approval_steps),
          include_section_head: Boolean(row.include_section_head),
          include_department_head: Boolean(row.include_department_head),
          include_division_head: Boolean(row.include_division_head),
          include_branch_head: Boolean(row.include_branch_head),
          include_area_head: Boolean(row.include_area_head),
          include_company_head: Boolean(row.include_company_head),
          include_admin_hr: row.include_admin_hr !== false,
          is_active: row.is_active !== false,
        })),
      })
      const settings = Array.isArray(data.settings) ? data.settings : rows
      setRows(settings)
      setHelperText(data.helper_text || helperText)
      setSavedSnapshot(JSON.stringify(settings.map(snapshotRow)))
      toast({ title: 'Evaluation workflow settings saved', variant: 'success' })
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Could not save evaluation workflow settings',
        description: error.message,
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="w-full space-y-5 px-4 py-6 md:px-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <div className="flex items-center gap-3">
            <span className="inline-flex size-10 items-center justify-center rounded-2xl bg-brand/10 text-brand ring-1 ring-brand/20">
              <ShieldCheck className="size-5" />
            </span>
            <h1 className="text-3xl font-extrabold tracking-tight text-foreground">Evaluation Workflow</h1>
          </div>
          <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-muted-foreground">
            Configure the evaluation approval ladder, including Section/Team Head, Department Head, Branch Head,
            Area Head, Company Head, and HR/Admin final approval.
          </p>
        </div>
        <Button
          type="button"
          onClick={handleSave}
          disabled={!isDirty || saving || loading}
          className="h-11 gap-2 rounded-xl bg-brand px-5 font-bold text-brand-foreground shadow-sm shadow-brand/20 hover:bg-brand-strong disabled:bg-brand/45"
        >
          {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
          Save Settings
        </Button>
      </div>

      <section className="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm shadow-black/5 dark:bg-card/95 dark:shadow-black/20">
        <div className="border-b border-border/70 px-5 py-5 md:px-6">
          <h2 className="text-lg font-extrabold text-foreground">Evaluation module settings</h2>
          <p className="mt-2 max-w-4xl text-sm font-medium leading-6 text-muted-foreground">
            {helperText}
          </p>
        </div>

        {loading ? (
          <div className="flex min-h-72 items-center justify-center text-sm font-semibold text-muted-foreground">
            <Loader2 className="mr-2 size-5 animate-spin text-brand" />
            Loading evaluation workflow settings...
          </div>
        ) : rows.length === 0 ? (
          <div className="flex min-h-72 flex-col items-center justify-center px-6 text-center">
            <ClipboardList className="size-10 text-muted-foreground" />
            <p className="mt-3 text-sm font-bold text-foreground">No workflow settings found</p>
            <p className="mt-1 text-sm text-muted-foreground">Run the database migrations, then reload this page.</p>
          </div>
        ) : (
          <div className="overflow-x-auto px-4 pb-5 pt-4 md:px-5">
            <div className="min-w-[1560px] overflow-hidden rounded-xl border border-border/70 bg-background dark:bg-input/15">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-border/70 bg-muted/35 text-[12px] font-extrabold text-foreground dark:bg-input/25">
                    <th className="px-4 py-4">Module / Request Type</th>
                    <th className="px-4 py-4">Use Hierarchy Approval</th>
                    <th className="px-4 py-4">First Approver Source</th>
                    <th className="px-4 py-4">Approval Chain Mode</th>
                    <th className="px-4 py-4">Enabled Approval Steps</th>
                    <th className="px-4 py-4">Fallback To Parent</th>
                    <th className="px-4 py-4">Final Approver</th>
                    <th className="px-4 py-4">Status</th>
                    <th className="px-4 py-4">Last Updated</th>
                    <th className="w-12 px-3 py-4" aria-label="Actions" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/70">
                  {rows.map((row) => {
                    const hierarchyOn = Boolean(row.use_hierarchy_approval)
                    const fallbackOn = Boolean(row.fallback_to_parent_approver)
                    const [updatedDate, updatedTime] = splitDateTime(row.updated_at)

                    return (
                      <tr key={row.request_type} className="bg-card transition-colors hover:bg-muted/20 dark:bg-card/60 dark:hover:bg-input/25">
                        <td className="px-4 py-4 align-middle">
                          <div className="flex items-center gap-3">
                            <ModuleIcon />
                            <div className="min-w-0">
                              <p className="truncate text-sm font-extrabold text-foreground">{row.request_type_label}</p>
                              <p className="mt-0.5 text-xs font-medium text-muted-foreground">{row.request_type}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-4 align-middle">
                          <WorkflowSwitch
                            checked={hierarchyOn}
                            label={`Use hierarchy approval for ${row.request_type_label}`}
                            onCheckedChange={(checked) => updateRow(row.request_type, { use_hierarchy_approval: checked })}
                          />
                        </td>
                        <td className="max-w-[180px] px-4 py-4 align-middle">
                          {hierarchyOn ? (
                            <div className="flex items-start gap-1.5">
                              <span className="text-xs font-bold leading-5 text-foreground">
                                {row.first_approver_source_label || 'Nearest leader'}
                              </span>
                              <Info className="mt-0.5 size-3.5 shrink-0 text-brand" />
                            </div>
                          ) : (
                            <span className="text-sm font-semibold text-muted-foreground">—</span>
                          )}
                        </td>
                        <td className="w-[260px] px-4 py-4 align-middle">
                          <div className="space-y-2">
                            <select
                              className="h-10 w-full rounded-lg border border-border/80 bg-background px-3 text-xs font-semibold text-foreground shadow-sm disabled:opacity-60 dark:bg-input/35 dark:scheme-dark"
                              value={row.approval_chain_mode || 'custom_selected_steps'}
                              disabled={!hierarchyOn}
                              onChange={(event) => {
                                const mode = event.target.value
                                updateRow(row.request_type, {
                                  approval_chain_mode: mode,
                                  max_org_approval_steps: mode === 'nearest_plus_admin' ? 1 : row.max_org_approval_steps ?? '',
                                })
                              }}
                            >
                              {CHAIN_MODE_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                              ))}
                            </select>
                            <div className="flex items-center gap-2">
                              <span className="shrink-0 text-[11px] font-bold text-muted-foreground">Max org steps</span>
                              <Input
                                type="number"
                                min="0"
                                max="6"
                                disabled={!hierarchyOn || row.approval_chain_mode === 'nearest_plus_admin' || row.approval_chain_mode === 'full_hierarchy'}
                                value={row.approval_chain_mode === 'nearest_plus_admin' ? 1 : row.max_org_approval_steps ?? ''}
                                onChange={(event) => updateRow(row.request_type, { max_org_approval_steps: event.target.value })}
                                placeholder="No limit"
                                className="h-9 rounded-lg border-border/80 bg-background text-xs font-semibold dark:bg-input/35"
                              />
                            </div>
                          </div>
                        </td>
                        <td className="w-[420px] px-4 py-4 align-middle">
                          <div className="grid grid-cols-2 gap-2">
                            {WORKFLOW_STEP_FLAGS.map((step) => (
                              <StepSwitch
                                key={step.key}
                                row={row}
                                step={step}
                                hierarchyOn={hierarchyOn}
                                onToggle={(key, checked) => updateRow(row.request_type, { [key]: checked })}
                              />
                            ))}
                          </div>
                          {!hierarchyOn ? (
                            <p className="mt-2 text-[11px] font-medium leading-4 text-muted-foreground">
                              Turn on hierarchy approval to use organization head steps. Admin HR can still be used as the direct final approver.
                            </p>
                          ) : null}
                        </td>
                        <td className="max-w-[240px] px-4 py-4 align-middle">
                          {hierarchyOn ? (
                            <div className="space-y-1.5">
                              <WorkflowSwitch
                                checked={fallbackOn}
                                label={`Fallback to parent approver for ${row.request_type_label}`}
                                onCheckedChange={(checked) => updateRow(row.request_type, { fallback_to_parent_approver: checked })}
                              />
                              <p className="max-w-[230px] text-[11px] font-medium leading-4 text-muted-foreground">
                                When OFF, Department Head is skipped if no team/section leader is found.
                              </p>
                            </div>
                          ) : (
                            <span className="text-sm font-semibold text-muted-foreground">—</span>
                          )}
                        </td>
                        <td className="px-4 py-4 align-middle">
                          <span
                            className={cn(
                              'inline-flex rounded-full px-3 py-1 text-xs font-extrabold ring-1',
                              row.include_admin_hr === false
                                ? 'bg-muted text-muted-foreground ring-border/70'
                                : 'bg-muted/60 text-foreground ring-border/70 dark:bg-input/35',
                            )}
                          >
                            {row.include_admin_hr === false ? 'Admin HR disabled' : row.final_approver_label || 'HR/Admin'}
                          </span>
                        </td>
                        <td className="px-4 py-4 align-middle">
                          <Badge
                            variant="outline"
                            className={cn(
                              'gap-1.5 border-transparent px-2.5 py-1 text-[11px] font-extrabold',
                              row.is_active === false
                                ? 'bg-muted text-muted-foreground'
                                : 'bg-zinc-950 text-white dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-1 dark:ring-emerald-400/25',
                            )}
                          >
                            <span className={cn('size-1.5 rounded-full', row.is_active === false ? 'bg-muted-foreground' : 'bg-emerald-500')} />
                            {row.is_active === false ? 'Inactive' : 'Active'}
                          </Badge>
                        </td>
                        <td className="px-4 py-4 align-middle">
                          <div className="text-xs font-semibold leading-5 text-muted-foreground">
                            <div>{updatedDate}</div>
                            {updatedTime ? <div>{updatedTime}</div> : null}
                            {row.updated_by_name ? <div className="mt-1 text-[11px]">{row.updated_by_name}</div> : null}
                          </div>
                        </td>
                        <td className="px-3 py-4 text-right align-middle">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                aria-label={`Open actions for ${row.request_type_label}`}
                              >
                                <MoreVertical className="size-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                              <DropdownMenuItem onClick={() => resetRow(row)}>
                                Reset module defaults
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                onClick={() => updateRow(row.request_type, { is_active: row.is_active === false })}
                              >
                                {row.is_active === false ? 'Mark as active' : 'Mark as inactive'}
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </section>
    </div>
  )
}
