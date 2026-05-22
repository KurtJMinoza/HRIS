import { useCallback, useEffect, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { useToast } from '@/components/ui/use-toast'
import { getEmployeeApprovalRoutePreview } from '@/api'

export default function ApprovalRoutePreviewSection({ employeeId, requestType = 'leave' }) {
  const { toast } = useToast()
  const [loading, setLoading] = useState(false)
  const [preview, setPreview] = useState(null)
  const [moduleType, setModuleType] = useState(requestType)

  const load = useCallback(async () => {
    if (!employeeId) return
    setLoading(true)
    try {
      const data = await getEmployeeApprovalRoutePreview(employeeId, { request_type: moduleType })
      setPreview(data)
    } catch (error) {
      toast({ variant: 'destructive', title: 'Failed to load approval route', description: error.message })
    } finally {
      setLoading(false)
    }
  }, [employeeId, moduleType, toast])

  useEffect(() => {
    load()
  }, [load])

  if (!employeeId) return null

  return (
    <section className="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-foreground">Approval Route Preview</h3>
          <p className="text-sm text-muted-foreground">Immediate approver, then Admin HR final approval.</p>
        </div>
        <Button type="button" variant="outline" size="sm" onClick={load} disabled={loading}>
          <RefreshCw className={`mr-2 size-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </Button>
      </div>

      <div className="mb-4 grid gap-2 md:grid-cols-[160px_1fr] md:items-center">
        <Label htmlFor={`approval-preview-type-${employeeId}`}>Request type</Label>
        <select
          id={`approval-preview-type-${employeeId}`}
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={moduleType}
          onChange={(event) => setModuleType(event.target.value)}
        >
          <option value="leave">Leave</option>
          <option value="overtime">Overtime</option>
          <option value="attendance_correction">Attendance correction</option>
          <option value="change_schedule">Change schedule</option>
          <option value="reports_request">Reports request</option>
          <option value="schedule">Schedule (legacy)</option>
        </select>
      </div>

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading approval route…</p>
      ) : (
        <div className="space-y-3">
          {(preview?.approval_chain || []).map((step) => (
            <div key={`${step.sequence_order}-${step.approver_id}`} className="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 px-3 py-2">
              <Badge variant="outline">{step.sequence_order}</Badge>
              <span className="text-sm font-medium text-foreground">{step.approver_name}</span>
              <span className="text-sm text-muted-foreground">
                {step.approval_label || step.approval_level}
              </span>
              {Array.isArray(step.eligible_approver_ids) && step.eligible_approver_ids.length > 1 ? (
                <Badge variant="secondary">{step.eligible_approver_ids.length} eligible approvers</Badge>
              ) : null}
            </div>
          ))}
          {!preview?.approval_chain?.length ? (
            <p className="text-sm text-muted-foreground">No approval route could be resolved.</p>
          ) : null}
        </div>
      )}
    </section>
  )
}
