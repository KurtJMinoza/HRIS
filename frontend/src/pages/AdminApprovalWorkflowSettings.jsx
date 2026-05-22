import { useCallback, useEffect, useMemo, useState } from 'react'
import { Loader2, Save, ShieldCheck } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/components/ui/use-toast'
import { getApprovalWorkflowSettings, updateApprovalWorkflowSettings } from '@/api'

const PARENT_FALLBACK_TYPES = new Set(['leave', 'overtime'])

function formatUpdatedAt(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return '—'
  }
}

function snapshotRow(row) {
  return {
    request_type: row.request_type,
    use_hierarchy_approval: Boolean(row.use_hierarchy_approval),
    fallback_to_parent_approver: Boolean(row.fallback_to_parent_approver),
    is_active: row.is_active !== false,
  }
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
    <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <ShieldCheck className="size-6 text-brand" />
            <h1 className="text-2xl font-bold tracking-tight text-foreground">Approval Workflow</h1>
          </div>
          <p className="max-w-3xl text-sm text-muted-foreground">
            Configure whether each request module uses hierarchy approval before HR/Admin final approval.
          </p>
        </div>
        <Button
          type="button"
          onClick={handleSave}
          disabled={!isDirty || saving || loading}
          className="rounded-xl"
        >
          {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
          Save settings
        </Button>
      </div>

      <Card className="rounded-2xl border-border/80 shadow-sm">
        <CardHeader className="space-y-2">
          <CardTitle className="text-lg">Request module settings</CardTitle>
          <CardDescription>{helperText}</CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex items-center justify-center py-16 text-muted-foreground">
              <Loader2 className="mr-2 size-5 animate-spin" />
              Loading approval workflow settings…
            </div>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-border/70">
              <table className="min-w-full divide-y divide-border/70 text-sm">
                <thead className="bg-muted/30">
                  <tr>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Module / Request Type</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Use Hierarchy Approval</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">First Approver Source</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Fallback To Parent</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Final Approver</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Status</th>
                    <th className="px-4 py-3 text-left font-semibold text-foreground">Last Updated</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/70 bg-background">
                  {rows.map((row) => (
                    <tr key={row.request_type}>
                      <td className="px-4 py-4 align-middle">
                        <div className="font-medium text-foreground">{row.request_type_label}</div>
                        <div className="text-xs text-muted-foreground">{row.request_type}</div>
                      </td>
                      <td className="px-4 py-4 align-middle">
                        <div className="flex items-center gap-3">
                          <Switch
                            checked={Boolean(row.use_hierarchy_approval)}
                            onCheckedChange={(checked) => updateRow(row.request_type, { use_hierarchy_approval: checked })}
                            aria-label={`Use hierarchy approval for ${row.request_type_label}`}
                          />
                          <span className="text-sm font-medium text-foreground">
                            {row.use_hierarchy_approval ? 'ON' : 'OFF'}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-4 align-middle text-muted-foreground">
                        {row.use_hierarchy_approval
                          ? (row.first_approver_source_label || 'Team Lead / Section-Unit Head')
                          : '—'}
                      </td>
                      <td className="px-4 py-4 align-middle">
                        {PARENT_FALLBACK_TYPES.has(row.request_type) && row.use_hierarchy_approval ? (
                          <div className="space-y-1">
                            <div className="flex items-center gap-3">
                              <Switch
                                checked={Boolean(row.fallback_to_parent_approver)}
                                onCheckedChange={(checked) => updateRow(row.request_type, { fallback_to_parent_approver: checked })}
                                aria-label={`Fallback to parent approver for ${row.request_type_label}`}
                              />
                              <span className="text-sm font-medium text-foreground">
                                {row.fallback_to_parent_approver ? 'ON' : 'OFF'}
                              </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                              When OFF, Department Head is skipped if no team/section leader is found.
                            </p>
                          </div>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-4 py-4 align-middle">
                        <Badge variant="secondary">{row.final_approver_label || 'HR/Admin'}</Badge>
                      </td>
                      <td className="px-4 py-4 align-middle">
                        <Badge variant={row.is_active === false ? 'outline' : 'default'}>
                          {row.is_active === false ? 'Inactive' : 'Active'}
                        </Badge>
                      </td>
                      <td className="px-4 py-4 align-middle text-muted-foreground">
                        <div>{formatUpdatedAt(row.updated_at)}</div>
                        {row.updated_by_name ? (
                          <div className="text-xs">{row.updated_by_name}</div>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
