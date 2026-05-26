import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  CalendarCheck,
  ClipboardList,
  Clock3,
  FileText,
  Info,
  Loader2,
  MoreVertical,
  Plane,
  RotateCcw,
  Save,
  ShieldCheck,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/components/ui/use-toast'
import { getApprovalWorkflowSettings, updateApprovalWorkflowSettings } from '@/api'
import { cn } from '@/lib/utils'

const PARENT_FALLBACK_TYPES = new Set(['leave', 'overtime'])

const MODULE_META = {
  attendance_correction: {
    icon: CalendarCheck,
    iconClassName: 'bg-orange-50 text-orange-600 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
    default_hierarchy: false,
    default_fallback: false,
  },
  leave: {
    icon: Plane,
    iconClassName: 'bg-orange-50 text-orange-600 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
    default_hierarchy: true,
    default_fallback: false,
  },
  overtime: {
    icon: Clock3,
    iconClassName: 'bg-orange-50 text-orange-600 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
    default_hierarchy: true,
    default_fallback: false,
  },
  change_schedule: {
    icon: RotateCcw,
    iconClassName: 'bg-orange-50 text-orange-600 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
    default_hierarchy: false,
    default_fallback: false,
  },
  reports_request: {
    icon: FileText,
    iconClassName: 'bg-orange-50 text-orange-600 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
    default_hierarchy: false,
    default_fallback: false,
  },
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
    is_active: row.is_active !== false,
  }
}

function toggleText(enabled) {
  return enabled ? 'ON' : 'OFF'
}

function ModuleIcon({ row }) {
  const meta = MODULE_META[row.request_type] || {}
  const Icon = meta.icon || ClipboardList

  return (
    <span className={cn('inline-flex size-10 shrink-0 items-center justify-center rounded-xl ring-1', meta.iconClassName || 'bg-brand/10 text-brand ring-brand/20')}>
      <Icon className="size-5" />
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

export default function AdminApprovalWorkflowSettings() {
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [helperText, setHelperText] = useState('')
  const [rows, setRows] = useState([])
  const [savedSnapshot, setSavedSnapshot] = useState('[]')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getApprovalWorkflowSettings()
      const settings = Array.isArray(data.settings) ? data.settings : []
      setRows(settings)
      setHelperText(data.helper_text || '')
      setSavedSnapshot(JSON.stringify(settings.map(snapshotRow)))
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Failed to load approval workflow settings',
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
    const meta = MODULE_META[row.request_type] || {}
    updateRow(row.request_type, {
      use_hierarchy_approval: Boolean(meta.default_hierarchy),
      fallback_to_parent_approver: Boolean(meta.default_fallback),
      is_active: true,
    })
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const data = await updateApprovalWorkflowSettings({
        settings: rows.map((row) => ({
          request_type: row.request_type,
          use_hierarchy_approval: Boolean(row.use_hierarchy_approval),
          fallback_to_parent_approver: Boolean(row.fallback_to_parent_approver),
          is_active: row.is_active !== false,
        })),
      })
      const settings = Array.isArray(data.settings) ? data.settings : rows
      setRows(settings)
      setHelperText(data.helper_text || helperText)
      setSavedSnapshot(JSON.stringify(settings.map(snapshotRow)))
      toast({ title: 'Approval workflow settings saved', variant: 'success' })
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Could not save approval workflow settings',
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
            <h1 className="text-3xl font-extrabold tracking-tight text-foreground">Approval Workflow</h1>
          </div>
          <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-muted-foreground">
            Configure whether each request module uses hierarchy approval before HR/Admin final approval.
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
          <h2 className="text-lg font-extrabold text-foreground">Request module settings</h2>
          <p className="mt-2 max-w-4xl text-sm font-medium leading-6 text-muted-foreground">
            {helperText}
          </p>
        </div>

        {loading ? (
          <div className="flex min-h-72 items-center justify-center text-sm font-semibold text-muted-foreground">
            <Loader2 className="mr-2 size-5 animate-spin text-brand" />
            Loading approval workflow settings...
          </div>
        ) : rows.length === 0 ? (
          <div className="flex min-h-72 flex-col items-center justify-center px-6 text-center">
            <ClipboardList className="size-10 text-muted-foreground" />
            <p className="mt-3 text-sm font-bold text-foreground">No workflow settings found</p>
            <p className="mt-1 text-sm text-muted-foreground">Run the database migrations, then reload this page.</p>
          </div>
        ) : (
          <div className="overflow-x-auto px-4 pb-5 pt-4 md:px-5">
            <div className="min-w-[1120px] overflow-hidden rounded-xl border border-border/70 bg-background dark:bg-input/15">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-border/70 bg-muted/35 text-[12px] font-extrabold text-foreground dark:bg-input/25">
                    <th className="px-4 py-4">Module / Request Type</th>
                    <th className="px-4 py-4">Use Hierarchy Approval</th>
                    <th className="px-4 py-4">First Approver Source</th>
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
                    const fallbackSupported = PARENT_FALLBACK_TYPES.has(row.request_type)
                    const fallbackOn = Boolean(row.fallback_to_parent_approver)
                    const [updatedDate, updatedTime] = splitDateTime(row.updated_at)

                    return (
                      <tr key={row.request_type} className="bg-card transition-colors hover:bg-muted/20 dark:bg-card/60 dark:hover:bg-input/25">
                        <td className="px-4 py-4 align-middle">
                          <div className="flex items-center gap-3">
                            <ModuleIcon row={row} />
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
                                {row.first_approver_source_label || 'Team Lead / Section-Unit Head'}
                              </span>
                              <Info className="mt-0.5 size-3.5 shrink-0 text-brand" />
                            </div>
                          ) : (
                            <span className="text-sm font-semibold text-muted-foreground">—</span>
                          )}
                        </td>
                        <td className="max-w-[240px] px-4 py-4 align-middle">
                          {fallbackSupported && hierarchyOn ? (
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
                          <span className="inline-flex rounded-full bg-muted/60 px-3 py-1 text-xs font-extrabold text-foreground ring-1 ring-border/70 dark:bg-input/35">
                            {row.final_approver_label || 'HR/Admin'}
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
