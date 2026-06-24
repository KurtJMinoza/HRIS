import { useCallback, useEffect, useMemo, useState } from 'react'
import { Label } from '@/components/ui/label'
import { ScheduleEditorDialog } from '@/components/schedules/ScheduleEditorDialog'
import {
  assignWorkingSchedule,
  createWorkingSchedule,
  getWorkingSchedules,
  updateEmployeeSchedule,
  updateWorkingSchedule,
} from '@/api'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import {
  buildWorkingSchedulePayload,
  createDefaultScheduleForm,
  scheduleRecordToForm,
  validateScheduleName,
} from '@/lib/workScheduleForm'
import { toast } from 'sonner'

function hasAssignedSchedule(employee) {
  if (!employee || typeof employee !== 'object') return false
  if (employee.schedule && typeof employee.schedule === 'object') {
    const hasDay = Object.values(employee.schedule).some((v) => v && v.in && v.out)
    if (hasDay) return true
  }
  return employee.working_schedule_id !== null && employee.working_schedule_id !== undefined && employee.working_schedule_id !== ''
}

export function EmployeeScheduleAssignDialog({
  open,
  onOpenChange,
  employee,
  bulkEmployeeIds = [],
  employees = [],
  workingSchedules,
  onWorkingSchedulesUpdated,
  onSuccess,
  canManageSchedules = false,
}) {
  const [editForm, setEditForm] = useState(createDefaultScheduleForm)
  const [editingSchedule, setEditingSchedule] = useState(null)
  const [selectedTemplateId, setSelectedTemplateId] = useState('new')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  const targetIds = useMemo(() => {
    if (bulkEmployeeIds.length > 0) return bulkEmployeeIds.map((id) => Number(id))
    if (employee?.id) return [Number(employee.id)]
    return []
  }, [bulkEmployeeIds, employee?.id])

  const assignLabel = useMemo(() => {
    if (bulkEmployeeIds.length > 0) {
      return `${bulkEmployeeIds.length} employee${bulkEmployeeIds.length === 1 ? '' : 's'}`
    }
    return employee?.name || 'Employee'
  }, [bulkEmployeeIds.length, employee?.name])

  const hasCurrentSchedule = useMemo(() => {
    if (bulkEmployeeIds.length > 0) {
      return employees.some((emp) => targetIds.includes(Number(emp.id)) && hasAssignedSchedule(emp))
    }
    return hasAssignedSchedule(employee)
  }, [bulkEmployeeIds.length, employee, employees, targetIds])

  const resetFormFromTemplate = useCallback(
    (templateId) => {
      if (templateId === 'new') {
        setEditingSchedule(null)
        setEditForm(createDefaultScheduleForm())
        return
      }
      const template = workingSchedules.find((item) => String(item.id) === String(templateId))
      if (template) {
        setEditingSchedule(template)
        setEditForm(scheduleRecordToForm(template))
      } else {
        setEditingSchedule(null)
        setEditForm(createDefaultScheduleForm())
      }
    },
    [workingSchedules]
  )

  useEffect(() => {
    if (!open) return
    setError(null)
    setSubmitting(false)

    const existingId = employee?.working_schedule_id
    if (existingId != null && existingId !== '') {
      const id = String(existingId)
      setSelectedTemplateId(id)
      resetFormFromTemplate(id)
      return
    }

    if (canManageSchedules) {
      setSelectedTemplateId('new')
      resetFormFromTemplate('new')
      return
    }

    const firstTemplate = workingSchedules[0]
    if (firstTemplate?.id != null) {
      const id = String(firstTemplate.id)
      setSelectedTemplateId(id)
      resetFormFromTemplate(id)
    } else {
      setSelectedTemplateId('')
      setEditingSchedule(null)
      setEditForm(createDefaultScheduleForm())
    }
  }, [open, employee, canManageSchedules, workingSchedules, resetFormFromTemplate])

  function handleTemplateChange(nextId) {
    setSelectedTemplateId(nextId)
    setError(null)
    resetFormFromTemplate(nextId)
  }

  async function refreshWorkingSchedules() {
    try {
      const data = await getWorkingSchedules()
      const list = Array.isArray(data.schedules) ? data.schedules : []
      onWorkingSchedulesUpdated?.(list)
      return list
    } catch {
      return workingSchedules
    }
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (targetIds.length === 0) return

    if (!canManageSchedules) {
      if (!selectedTemplateId || selectedTemplateId === 'new') {
        setError('Select an existing work schedule template to assign.')
        return
      }
    } else {
      const nameError = validateScheduleName(editForm.name)
      if (nameError) {
        setError(nameError)
        return
      }
    }

    setSubmitting(true)
    setError(null)

    try {
      let scheduleId = editingSchedule?.id ?? null

      if (canManageSchedules) {
        const payload = buildWorkingSchedulePayload(editForm)
        if (editingSchedule?.id) {
          await updateWorkingSchedule(editingSchedule.id, payload)
          scheduleId = editingSchedule.id
        } else {
          const created = await createWorkingSchedule(payload)
          scheduleId = created?.schedule?.id ?? created?.id ?? null
        }
        if (!scheduleId) {
          throw new Error('Failed to resolve schedule template ID.')
        }
        await refreshWorkingSchedules()
      } else {
        scheduleId = Number(selectedTemplateId)
        if (!scheduleId) {
          throw new Error('Select a work schedule template.')
        }
      }

      await assignWorkingSchedule(scheduleId, {
        employee_ids: targetIds.map((id) => Number(id)),
        mode: 'assign_only',
      })

      window.dispatchEvent(new Event('hr:schedules-changed'))
      toast.success('Schedule assigned', {
        description:
          bulkEmployeeIds.length > 0
            ? `Work schedule applied to ${bulkEmployeeIds.length} employee(s).`
            : `${assignLabel} is now on ${editForm.name || 'the selected schedule'}.`,
      })
      onOpenChange(false)
      onSuccess?.()
    } catch (err) {
      const msg = err.conflicts?.length
        ? `Employee already assigned: ${err.conflicts
            .map((c) => `${c.employee_name} (${c.current_schedule})`)
            .join('; ')}. Unassign first.`
        : err.message || 'Failed to assign schedule'
      setError(msg)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleClearSchedule() {
    if (targetIds.length === 0) return
    setSubmitting(true)
    setError(null)
    try {
      await Promise.all(targetIds.map((id) => updateEmployeeSchedule(id, { schedule: null })))
      window.dispatchEvent(new Event('hr:schedules-changed'))
      toast.success('Schedule cleared', {
        description:
          bulkEmployeeIds.length > 0
            ? `Schedule removed from ${bulkEmployeeIds.length} employee(s).`
            : `${assignLabel} no longer has a work schedule.`,
      })
      onOpenChange(false)
      onSuccess?.()
    } catch (err) {
      setError(err.message || 'Failed to clear schedule')
    } finally {
      setSubmitting(false)
    }
  }

  const headerExtra = (
    <div className="mt-4 space-y-3">
      <p className="text-sm text-foreground">
        Assigning to <span className="font-semibold">{assignLabel}</span>
      </p>
      <div className="space-y-1.5">
        <Label htmlFor="employee-schedule-template">Work schedule template</Label>
        <select
          id="employee-schedule-template"
          className={FIELD_SELECT_CLASS}
          value={selectedTemplateId}
          onChange={(e) => handleTemplateChange(e.target.value)}
          disabled={submitting || (!canManageSchedules && workingSchedules.length === 0)}
        >
          {canManageSchedules ? <option value="new">Create new template</option> : null}
          {workingSchedules.length === 0 ? (
            <option value="">No templates available</option>
          ) : (
            workingSchedules.map((schedule) => (
              <option key={schedule.id} value={String(schedule.id)}>
                {schedule.name}
              </option>
            ))
          )}
        </select>
        {!canManageSchedules ? (
          <p className="text-xs text-muted-foreground">
            Choose an existing template. Creating or editing templates requires schedule manage permission.
          </p>
        ) : null}
      </div>
    </div>
  )

  return (
    <ScheduleEditorDialog
      open={open}
      onOpenChange={onOpenChange}
      editingSchedule={editingSchedule}
      editForm={editForm}
      setEditForm={setEditForm}
      onSubmit={handleSubmit}
      submitting={submitting}
      error={error}
      title="Assign work schedule"
      description="Use the same shift template fields as Work schedules. Night shift: set time out earlier than time in (e.g. 10:00 PM → 6:00 AM)."
      submitLabel="Assign schedule"
      headerExtra={headerExtra}
      readOnly={!canManageSchedules}
      secondaryAction={
        hasCurrentSchedule
          ? {
              label: 'Clear schedule',
              onClick: handleClearSchedule,
              disabled: submitting,
              variant: 'outline',
              className: 'text-destructive hover:bg-destructive/10 hover:text-destructive',
            }
          : undefined
      }
    />
  )
}
