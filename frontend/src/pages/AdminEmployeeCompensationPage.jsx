import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Building2, CirclePlus, Eye, Save, Search, Trash2, UserCircle2, Wallet } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import {
  assignEmployeeCompensation,
  deleteEmployeeCompensation,
  getEmployeeCompensation,
  getEmployees,
  getPayComponents,
  updateEmployeeCompensation,
} from '@/api'

const EMPTY_FORM = {
  pay_component_id: '',
  name: '',
  code: '',
  type: 'earning',
  category: 'Fixed Allowance',
  calculation_type: 'fixed_amount',
  value: '0',
  hourly_rate: '',
  hours: '',
  formula: '',
  is_taxable: true,
  contributes_sss: false,
  contributes_philhealth: false,
  contributes_pagibig: false,
  is_proratable: false,
  show_on_payslip: true,
  is_custom: false,
}

export default function AdminEmployeeCompensationPage() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const hrBase = useHrBasePath()
  const [employees, setEmployees] = useState([])
  const [components, setComponents] = useState([])
  const [compensationData, setCompensationData] = useState([])
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [effectiveFrom] = useState(new Date().toISOString().slice(0, 10))
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [activeEmployeeId, setActiveEmployeeId] = useState(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [draftForm, setDraftForm] = useState(EMPTY_FORM)
  const [pendingAssignments, setPendingAssignments] = useState([])
  const [removeDialogOpen, setRemoveDialogOpen] = useState(false)
  const [assignmentToRemove, setAssignmentToRemove] = useState(null)
  const [removing, setRemoving] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [editValue, setEditValue] = useState('')
  const [updatingValue, setUpdatingValue] = useState(false)
  const preselectedEmployeeId = Number(searchParams.get('employee_id') || 0)

  const loadLookups = useCallback(async (searchTerm = employeeSearch) => {
    setLoading(true)
    try {
      const [employeeRes, componentRes] = await Promise.all([
        getEmployees({ q: searchTerm, per_page: 100 }),
        getPayComponents({ all: true }),
      ])
      const employeeRows = Array.isArray(employeeRes?.employees) ? employeeRes.employees : []
      setEmployees(employeeRows)
      setComponents(Array.isArray(componentRes?.components) ? componentRes.components : [])
      if (employeeRows.length > 0) {
        if (preselectedEmployeeId > 0 && employeeRows.some((row) => Number(row.id) === preselectedEmployeeId)) {
          setActiveEmployeeId(preselectedEmployeeId)
        } else if (activeEmployeeId == null) {
          setActiveEmployeeId(employeeRows[0].id)
        }
      }
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to load compensation data',
        variant: 'destructive',
      })
    } finally {
      setLoading(false)
    }
  }, [activeEmployeeId, employeeSearch, preselectedEmployeeId, toast])

  const refreshCompensation = useCallback(async (employeeId = activeEmployeeId) => {
    const ids = employeeId ? [employeeId] : []
    if (ids.length === 0) {
      setCompensationData([])
      return
    }
    try {
      const data = await getEmployeeCompensation({
        employee_ids: ids,
        as_of_date: effectiveFrom || undefined,
      })
      setCompensationData(Array.isArray(data?.employees) ? data.employees : [])
      if (data?.recalculation_queued) {
        toast({
          title: 'Recalculating compensation',
          description: 'Updated totals will appear shortly after the background job finishes.',
        })
        const id = ids[0]
        setTimeout(() => {
          void getEmployeeCompensation({
            employee_ids: [id],
            as_of_date: effectiveFrom || undefined,
          })
            .then((d) => {
              if (Array.isArray(d?.employees)) setCompensationData(d.employees)
            })
            .catch(() => {})
        }, 3500)
      }
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to load selected employee compensation',
        variant: 'destructive',
      })
    }
  }, [activeEmployeeId, effectiveFrom, toast])

  useEffect(() => {
    loadLookups()
  }, [loadLookups])

  useEffect(() => {
    const timer = setTimeout(() => {
      loadLookups(employeeSearch)
    }, 260)
    return () => clearTimeout(timer)
  }, [employeeSearch, loadLookups])

  useEffect(() => {
    refreshCompensation()
  }, [activeEmployeeId, effectiveFrom, refreshCompensation])

  const activeEmployee = useMemo(
    () => employees.find((employee) => employee.id === activeEmployeeId) || null,
    [activeEmployeeId, employees],
  )

  const activeCompensation = useMemo(
    () => compensationData.find((entry) => entry.employee?.id === activeEmployeeId) || null,
    [activeEmployeeId, compensationData],
  )

  const summary = activeCompensation?.summary || {}
  const earnings = summary.earnings || []
  const deductions = summary.deductions || []
  const gross = summary?.totals?.gross_earnings || 0
  const summaryPending = Boolean(summary?._summary_pending) || (Array.isArray(earnings) && earnings.length === 0 && Number(gross) === 0)

  async function handleRefreshPreview() {
    await refreshCompensation()
    toast({
      title: 'Preview refreshed',
      description: 'Gross pay preview updated from the latest compensation assignments.',
    })
  }
  function selectEmployee(employee) {
    setActiveEmployeeId(employee.id)
  }

  function openNewAssignmentDialog() {
    if (!activeEmployee) {
      toast({ title: 'Employee compensation', description: 'Select an employee first.', variant: 'destructive' })
      return
    }
    setDraftForm(EMPTY_FORM)
    setDialogOpen(true)
  }

  function applyMasterComponent(componentId) {
    const master = components.find((item) => String(item.id) === String(componentId))
    if (!master) {
      setDraftForm(EMPTY_FORM)
      return
    }
    const meta = master.metadata && typeof master.metadata === 'object' ? master.metadata : {}
    const calc = master.calculation_type || 'fixed_amount'
    let initValue = String(master.default_value ?? 0)
    let initHourlyRate = ''
    let initHours = ''
    if (calc === 'hourly') {
      initHourlyRate = meta.default_hourly_rate != null ? String(meta.default_hourly_rate) : String(master.default_value ?? '')
      initHours = meta.default_hours != null ? String(meta.default_hours) : ''
      initValue = initHourlyRate || '0'
    } else if (calc === 'daily_rate') {
      initHours = meta.default_days != null ? String(meta.default_days) : ''
    } else if (calc === 'percent_basic' || calc === 'percent_gross') {
      initValue = meta.default_percent != null ? String(meta.default_percent) : String(master.default_value ?? 0)
    }
    setDraftForm({
      pay_component_id: master.id,
      name: master.name,
      code: master.code,
      type: master.type,
      category: master.category || 'Fixed Allowance',
      calculation_type: calc,
      value: initValue,
      hourly_rate: initHourlyRate,
      hours: initHours,
      formula: master.formula || '',
      is_taxable: Boolean(master.is_taxable),
      contributes_sss: Boolean(master.contributes_sss),
      contributes_philhealth: Boolean(master.contributes_philhealth),
      contributes_pagibig: Boolean(master.contributes_pagibig),
      is_proratable: Boolean(master.is_proratable),
      show_on_payslip: true,
      is_custom: false,
    })
  }

  function addPendingAssignment(e) {
    e.preventDefault()
    if (!activeEmployee) return
    if (!draftForm.pay_component_id) {
      toast({
        title: 'Employee compensation',
        description: 'Select a pay component before adding it to pending changes.',
        variant: 'destructive',
      })
      return
    }
    setPendingAssignments((prev) => [
      ...prev,
      {
        employeeId: activeEmployee.id,
        employeeName: activeEmployee.name,
        ...draftForm,
        value: Number(draftForm.value || 0),
        hourly_rate: draftForm.hourly_rate !== '' && draftForm.hourly_rate !== null
          ? Number(draftForm.hourly_rate)
          : null,
        hours: draftForm.hours !== '' && draftForm.hours !== null
          ? Number(draftForm.hours)
          : null,
        formula: draftForm.formula || null,
        pay_component_id: draftForm.pay_component_id || null,
        show_on_payslip: true,
        is_custom: false,
      },
    ])
    setDialogOpen(false)
    setDraftForm(EMPTY_FORM)
  }

  async function savePendingAssignments() {
    if (pendingAssignments.length === 0) return
    setSaving(true)
    try {
      const grouped = pendingAssignments.reduce((acc, item) => {
        const key = [
          item.employeeId,
          item.effective_from || '',
          item.effective_to || '',
        ].join(':')
        acc[key] = acc[key] || {
          employeeId: item.employeeId,
          effective_from: item.effective_from || null,
          effective_to: item.effective_to || null,
          items: [],
        }
        acc[key].items.push(item)
        return acc
      }, {})

      for (const group of Object.values(grouped)) {
        await assignEmployeeCompensation({
          employee_ids: [Number(group.employeeId)],
          structure_name: null,
          effective_from: group.effective_from,
          effective_to: group.effective_to,
          components: group.items.map((item) => ({
            pay_component_id: item.pay_component_id,
            name: item.name,
            code: item.code,
            type: item.type,
            category: item.category,
            calculation_type: item.calculation_type,
            value: Number(item.value || 0),
            hourly_rate: item.hourly_rate != null ? Number(item.hourly_rate) : null,
            hours: item.hours != null ? Number(item.hours) : null,
            formula: item.formula || null,
            is_taxable: item.is_taxable,
            contributes_sss: item.contributes_sss,
            contributes_philhealth: item.contributes_philhealth,
            contributes_pagibig: item.contributes_pagibig,
            is_proratable: item.is_proratable,
            is_custom: item.is_custom,
          })),
        })
      }

      toast({ title: 'Employee compensation', description: 'Compensation changes saved successfully.' })
      setPendingAssignments([])
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to save pending compensation changes',
        variant: 'destructive',
      })
    } finally {
      setSaving(false)
    }
  }

  function requestRemoveAssignment(employeeId, assignment) {
    setAssignmentToRemove({ employeeId, assignment })
    setRemoveDialogOpen(true)
  }

  async function confirmRemoveAssignment() {
    if (!assignmentToRemove) return
    const { employeeId, assignment } = assignmentToRemove
    setRemoving(true)
    try {
      await deleteEmployeeCompensation(employeeId, assignment.id)
      toast({ title: 'Employee compensation', description: 'Assignment removed.' })
      setRemoveDialogOpen(false)
      setAssignmentToRemove(null)
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      const msg = String(error?.message || '')
      toast({
        title: 'Employee compensation',
        description: msg || 'Failed to remove assignment',
        variant: 'destructive',
      })
      if (msg.includes('no longer available') || msg.includes('No query results')) {
        await refreshCompensation(employeeId)
      }
    } finally {
      setRemoving(false)
    }
  }

  function startEditing(item) {
    setEditingId(item.id)
    setEditValue(String(item.configured_value ?? item.computed_amount ?? 0))
  }

  function cancelEditing() {
    setEditingId(null)
    setEditValue('')
  }

  async function saveEditedValue(item) {
    if (!activeEmployee || !item?.id) return
    const newValue = Number(editValue || 0)
    setUpdatingValue(true)
    try {
      const payload = { value: newValue }
      if (item.calculation_type === 'hourly') {
        payload.hourly_rate = newValue
      }
      await updateEmployeeCompensation(activeEmployee.id, item.id, payload)
      const formatted = `₱${newValue.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
      const calc = item.calculation_type
      const label = calc === 'percent_basic' || calc === 'percent_gross'
        ? `${newValue}%`
        : (calc === 'hourly' ? `${formatted}/hr` : formatted)
      toast({ title: 'Compensation updated', description: `${item.name} value updated to ${label}.` })
      setEditingId(null)
      setEditValue('')
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      toast({
        title: 'Update failed',
        description: error.message || 'Failed to update compensation value',
        variant: 'destructive',
      })
    } finally {
      setUpdatingValue(false)
    }
  }

  const pendingForActive = pendingAssignments.filter((item) => item.employeeId === activeEmployeeId)

  return (
    <div className="w-full min-w-0 max-w-none space-y-4 bg-white px-3 py-4 text-[#0A0A0A] sm:space-y-5 sm:px-4 md:px-5 lg:space-y-6 lg:px-6 lg:py-5 3xl:space-y-8 3xl:px-10 3xl:py-6 dark:bg-background dark:text-foreground">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
              <Wallet className="size-3.5" />
              Compensation
            </div>
            <h1 className="hr-page-title mt-3 text-slate-900">Employee Compensation</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-600">
              Select employees, review their assigned compensation, and queue updates before saving.
            </p>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <SimpleStat label="Selected" value={activeEmployee ? 1 : 0} />
            <SimpleStat label="Pending" value={pendingAssignments.length} />
            <SimpleStat label="Effective Date" value={effectiveFrom || '—'} compact />
          </div>
        </div>
      </section>

      {pendingAssignments.length > 0 ? (
        <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <p className="text-sm font-semibold text-slate-900">Pending Changes</p>
              <p className="mt-1 text-sm text-slate-600">
                {pendingAssignments.length} item{pendingAssignments.length === 1 ? '' : 's'} queued across {new Set(pendingAssignments.map((item) => item.employeeId)).size} employee{new Set(pendingAssignments.map((item) => item.employeeId)).size === 1 ? '' : 's'}.
              </p>
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" className="rounded-xl" onClick={() => setPendingAssignments([])}>
                Clear
              </Button>
              <Button type="button" className="rounded-xl bg-slate-900 text-white hover:bg-slate-800" onClick={savePendingAssignments} disabled={saving}>
                <Save className="mr-2 size-4" />
                {saving ? 'Saving...' : 'Save Changes'}
              </Button>
            </div>
          </div>
        </section>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
        <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-slate-900">Employees</h2>
              <p className="text-sm text-slate-500">Search and choose one</p>
            </div>
            <Badge className="rounded-full bg-slate-100 text-slate-700">{activeEmployee ? 1 : 0}</Badge>
          </div>

          <label className="relative mt-4 block">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input
              value={employeeSearch}
              onChange={(e) => setEmployeeSearch(e.target.value)}
              className={`${inputClass} pl-10`}
              placeholder="Search employee"
            />
          </label>

          <div className="mt-4 max-h-[720px] space-y-2 overflow-y-auto pr-1">
            {loading ? (
              Array.from({ length: 8 }).map((_, index) => (
                <div key={index} className="h-20 animate-pulse rounded-xl bg-slate-100" />
              ))
            ) : employees.length === 0 ? (
              <div className="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-500">
                No employees matched your search.
              </div>
            ) : (
              employees.map((employee) => {
                const active = activeEmployeeId === employee.id
                return (
                  <button
                    key={employee.id}
                    type="button"
                    onClick={() => selectEmployee(employee)}
                    className={`w-full rounded-xl border px-3 py-3 text-left transition ${
                      active
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-200 bg-white hover:bg-slate-50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <Avatar className="size-11 border border-white/20">
                        <AvatarImage src={employee.profile_image || undefined} alt={employee.name} />
                        <AvatarFallback className={active ? 'bg-white/10 text-white' : 'bg-slate-100 text-slate-700'}>
                          {initials(employee.name)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <p className={`truncate text-sm font-semibold ${active ? 'text-white' : 'text-slate-900'}`}>{employee.name}</p>
                        <p className={`mt-1 truncate text-xs ${active ? 'text-slate-300' : 'text-slate-500'}`}>
                          {employee.position || 'No position'}
                        </p>
                        <p className={`mt-1 truncate text-[11px] ${active ? 'text-slate-300' : 'text-slate-500'}`}>
                          {employee.employee_code || 'No code'}
                        </p>
                      </div>
                    </div>
                  </button>
                )
              })
            )}
          </div>
        </aside>

        <main className="space-y-6">
          {activeEmployee ? (
            <>
              <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                  <div className="flex items-center gap-4">
                    <Avatar className="size-16 border border-slate-200">
                      <AvatarImage src={activeEmployee.profile_image || undefined} alt={activeEmployee.name} />
                      <AvatarFallback className="bg-slate-100 text-slate-700">
                        {initials(activeEmployee.name)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-2xl font-semibold text-slate-900">{activeEmployee.name}</h2>
                        <Badge className="rounded-full bg-slate-100 text-slate-700">{activeEmployee.employee_code || 'No code'}</Badge>
                      </div>
                      <p className="mt-1 text-sm text-slate-500">{activeEmployee.position || 'No position assigned'}</p>
                      <div className="mt-2 flex items-center gap-2 text-sm text-slate-500">
                        <Building2 className="size-4" />
                        <span>{activeEmployee.department || 'No department info'}</span>
                      </div>
                    </div>
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2">
                    <SummaryCard label="Basic Salary" value={summary.basic_salary} />
                    <SummaryCard label="Gross Pay" value={gross} />
                  </div>
                </div>

                <div className="mt-6 flex items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-xl"
                      onClick={handleRefreshPreview}
                      disabled={loading}
                    >
                      <Save className="mr-2 size-4" />
                      Refresh Preview
                    </Button>
                    {summaryPending ? (
                      <span className="text-xs text-slate-500">
                        Preview is warming up. Click Refresh if values still show ₱0.00.
                      </span>
                    ) : null}
                  </div>
                  <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-xl"
                      onClick={() => navigate(hrPanelPath(hrBase, `employees/${activeEmployee.id}`))}
                    >
                      <Eye className="mr-2 size-4" />
                      View Profile
                    </Button>
                    <Button type="button" onClick={openNewAssignmentDialog} className="rounded-xl bg-slate-900 text-white hover:bg-slate-800">
                      <CirclePlus className="mr-2 size-4" />
                      Add Component
                    </Button>
                  </div>
                </div>
              </section>

              {pendingForActive.length > 0 ? (
                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <p className="text-sm font-semibold text-slate-900">Pending for {activeEmployee.name}</p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {pendingForActive.map((item, index) => (
                      <Badge key={`${item.code}-${index}`} className="rounded-full bg-slate-100 text-slate-700">
                        {item.name}
                      </Badge>
                    ))}
                  </div>
                </section>
              ) : null}

              <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <Tabs defaultValue="earnings" className="w-full">
                  <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <h3 className="text-lg font-semibold text-slate-900">Compensation Components</h3>
                      <p className="mt-1 text-sm text-slate-500">Review earnings and deductions for the selected employee.</p>
                    </div>
                    <TabsList className="h-auto rounded-xl bg-slate-100 p-1">
                      <TabsTrigger value="earnings" className="rounded-lg px-4 py-2 text-sm font-medium data-[state=active]:bg-white">
                        Earnings
                      </TabsTrigger>
                      <TabsTrigger value="deductions" className="rounded-lg px-4 py-2 text-sm font-medium data-[state=active]:bg-white">
                        Deductions
                      </TabsTrigger>
                    </TabsList>
                  </div>

                  <TabsContent value="earnings" className="mt-5">
                    <CompTable
                      items={earnings}
                      emptyLabel="No earning components assigned yet."
                      amountTone="earning"
                      onRemove={(assignment) => requestRemoveAssignment(activeEmployee.id, assignment)}
                      editingId={editingId}
                      editValue={editValue}
                      onEditStart={startEditing}
                      onEditChange={setEditValue}
                      onEditSave={saveEditedValue}
                      onEditCancel={cancelEditing}
                      updatingValue={updatingValue}
                    />
                  </TabsContent>

                  <TabsContent value="deductions" className="mt-5">
                    <CompTable
                      items={deductions}
                      emptyLabel="No deduction components assigned yet."
                      amountTone="deduction"
                      onRemove={(assignment) => requestRemoveAssignment(activeEmployee.id, assignment)}
                      editingId={editingId}
                      editValue={editValue}
                      onEditStart={startEditing}
                      onEditChange={setEditValue}
                      onEditSave={saveEditedValue}
                      onEditCancel={cancelEditing}
                      updatingValue={updatingValue}
                    />
                  </TabsContent>
                </Tabs>
              </section>
            </>
          ) : (
            <section className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm">
              <UserCircle2 className="mx-auto size-12 text-slate-300" />
              <h2 className="mt-4 text-xl font-semibold text-slate-900">Select an employee</h2>
              <p className="mt-2 text-sm text-slate-500">
                Choose someone from the list to review and update compensation.
              </p>
            </section>
          )}
        </main>
      </div>

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          setDialogOpen(open)
          if (!open) setDraftForm(EMPTY_FORM)
        }}
      >
        <DialogContent className="max-w-4xl rounded-3xl border-slate-200 p-0">
          <div className="border-b border-slate-200 px-6 py-5">
            <DialogHeader>
              <DialogTitle className="text-xl font-semibold text-slate-900">
                Add Component to {activeEmployee?.name || 'Employee'}
              </DialogTitle>
              <DialogDescription className="text-sm text-slate-500">
                Select a pay component from the catalog, then set the employee-specific amount.
              </DialogDescription>
            </DialogHeader>
          </div>

          <form onSubmit={addPendingAssignment} className="space-y-6 px-7 py-7">
            <div className="grid grid-cols-1 gap-5">
              <Field label="Pay Component">
                <select value={draftForm.pay_component_id} onChange={(e) => applyMasterComponent(e.target.value)} className={inputClass} required>
                  <option value="" disabled>Select a pay component</option>
                  {components.map((component) => (
                    <option key={component.id} value={component.id}>
                      {component.name} ({component.code})
                    </option>
                  ))}
                </select>
              </Field>

              {draftForm.pay_component_id ? (
                <div className="grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm sm:grid-cols-3">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">Type</p>
                    <p className="mt-1 font-semibold text-slate-900 capitalize">{draftForm.type}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">Category</p>
                    <p className="mt-1 font-semibold text-slate-900">{draftForm.category || '—'}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">Calculation</p>
                    <p className="mt-1 font-semibold text-slate-900">{formatCalculationType(draftForm.calculation_type)}</p>
                  </div>
                </div>
              ) : null}

              {draftForm.calculation_type === 'fixed_amount' ? (
                <Field label="Amount (₱)">
                  <input
                    value={draftForm.value}
                    onChange={(e) => setDraftForm((prev) => ({ ...prev, value: e.target.value }))}
                    className={inputClass}
                    inputMode="decimal"
                    placeholder="0.00"
                    required
                  />
                </Field>
              ) : null}

              {draftForm.calculation_type === 'percent_basic' || draftForm.calculation_type === 'percent_gross' ? (
                <Field label={draftForm.calculation_type === 'percent_basic' ? 'Percentage of Basic (%)' : 'Percentage of Gross (%)'}>
                  <input
                    value={draftForm.value}
                    onChange={(e) => setDraftForm((prev) => ({ ...prev, value: e.target.value }))}
                    className={inputClass}
                    inputMode="decimal"
                    placeholder="e.g. 5"
                    required
                  />
                </Field>
              ) : null}

              {draftForm.calculation_type === 'hourly' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Hourly Rate (₱)">
                    <input
                      value={draftForm.hourly_rate}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, hourly_rate: e.target.value, value: e.target.value }))}
                      className={inputClass}
                      inputMode="decimal"
                      placeholder="0.00"
                      required
                    />
                  </Field>
                  <Field label="Hours per Pay Period">
                    <input
                      value={draftForm.hours}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, hours: e.target.value }))}
                      className={inputClass}
                      inputMode="decimal"
                      placeholder="e.g. 40"
                      required
                    />
                  </Field>
                </div>
              ) : null}

              {draftForm.calculation_type === 'daily_rate' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Daily Rate (₱)">
                    <input
                      value={draftForm.value}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, value: e.target.value }))}
                      className={inputClass}
                      inputMode="decimal"
                      placeholder="0.00"
                      required
                    />
                  </Field>
                  <Field label="Days per Pay Period">
                    <input
                      value={draftForm.hours}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, hours: e.target.value }))}
                      className={inputClass}
                      inputMode="decimal"
                      placeholder="e.g. 22"
                    />
                  </Field>
                </div>
              ) : null}

              {draftForm.calculation_type === 'formula' ? (
                <>
                  <Field label="Default value (DEFAULT_VALUE token)">
                    <input
                      value={draftForm.value}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, value: e.target.value }))}
                      className={inputClass}
                      inputMode="decimal"
                      placeholder="0.00"
                    />
                  </Field>
                  <Field label="Formula">
                    <input
                      value={draftForm.formula}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, formula: e.target.value }))}
                      className={`${inputClass} font-mono text-xs`}
                      placeholder="(BASIC * 0.05) + DEFAULT_VALUE"
                    />
                  </Field>
                </>
              ) : null}
            </div>

            <div className="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
              Component definition (calculation type, taxability, contributions) is set in the Pay Components catalog. Use this dialog to capture the employee-specific amount or rate.
            </div>

            <DialogFooter className="border-t border-slate-200 pt-5">
              <Button type="button" variant="outline" className="rounded-xl" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" className="rounded-xl bg-slate-900 text-white hover:bg-slate-800">
                Add to Pending Changes
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog
        open={removeDialogOpen}
        onOpenChange={(open) => {
          if (!removing) {
            setRemoveDialogOpen(open)
            if (!open) setAssignmentToRemove(null)
          }
        }}
      >
        <DialogContent className="max-w-md rounded-2xl border-slate-200">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">Remove compensation component</DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Remove "{assignmentToRemove?.assignment?.name || 'this component'}" from {activeEmployee?.name || 'this employee'}?
            </DialogDescription>
          </DialogHeader>

          <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            This action removes the assigned component from the employee compensation record.
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              className="rounded-xl"
              onClick={() => {
                setRemoveDialogOpen(false)
                setAssignmentToRemove(null)
              }}
              disabled={removing}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="rounded-xl bg-rose-600 text-white hover:bg-rose-700"
              onClick={confirmRemoveAssignment}
              disabled={removing}
            >
              {removing ? 'Removing...' : 'Remove'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SimpleStat({ label, value, compact = false }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-2 font-semibold text-slate-900 ${compact ? 'text-base' : 'text-2xl'}`}>{value}</p>
    </div>
  )
}

function SummaryCard({ label, value }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-900">{formatPeso(value)}</p>
    </div>
  )
}

function CompTable({ items, emptyLabel, amountTone, onRemove, editingId, editValue, onEditStart, onEditChange, onEditSave, onEditCancel, updatingValue }) {
  return (
    <div className="overflow-x-auto">
      <Table className="min-w-[820px]">
        <TableHeader className="[&_tr]:border-b-0">
          <TableRow>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Component</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Category</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Calculation</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Taxability</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Contributory</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</TableHead>
            <TableHead className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.length === 0 ? (
            <TableRow>
              <TableCell colSpan={7} className="px-3 py-10 text-center text-sm text-slate-500">
                {emptyLabel}
              </TableCell>
            </TableRow>
          ) : (
            items.map((item) => {
              const isEditing = editingId === item.id
              return (
                <TableRow key={item.id} className="border-b border-slate-100 transition hover:bg-slate-50/80">
                  <TableCell className="px-3 py-3.5">
                    <div className="font-medium text-slate-900">{item.name}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span>{item.code}{item.structure_name ? ` • ${item.structure_name}` : ''}</span>
                      <span className={`inline-flex rounded-full px-2 py-0.5 font-medium ${getAssignmentSourceStyles(item).className}`}>
                        {getAssignmentSourceStyles(item).label}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="px-3 py-3.5 text-slate-600">{item.category || '—'}</TableCell>
                  <TableCell className="px-3 py-3.5">{formatCalculationType(item.calculation_type)}</TableCell>
                  <TableCell className="px-3 py-3.5">{item.is_taxable ? 'Taxable' : 'Non-taxable'}</TableCell>
                  <TableCell className="px-3 py-3.5">{describeContributions(item)}</TableCell>
                  <TableCell className="px-3 py-3.5">
                    {isEditing ? (
                      <div className="flex items-center gap-1.5">
                        <span className="text-sm text-slate-500">₱</span>
                        <input
                          autoFocus
                          value={editValue}
                          onChange={(e) => onEditChange(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') onEditSave(item)
                            if (e.key === 'Escape') onEditCancel()
                          }}
                          className="w-28 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                          inputMode="decimal"
                          disabled={updatingValue}
                        />
                      </div>
                    ) : (
                      <button
                        type="button"
                        onClick={() => item?.id && onEditStart(item)}
                        className={`group inline-flex items-center gap-1.5 rounded-lg px-2 py-1 transition ${item?.id ? 'cursor-pointer hover:bg-slate-100' : 'cursor-default'}`}
                        disabled={!item?.id}
                        title={item?.id ? 'Click to edit value' : undefined}
                      >
                        <span className={amountTone === 'deduction' ? 'font-medium text-rose-700' : 'font-medium text-emerald-700'}>
                          {formatPeso(item.computed_amount)}
                        </span>
                        {item?.id ? (
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" className="size-3.5 text-slate-400 opacity-0 transition group-hover:opacity-100">
                            <path d="M13.488 2.513a1.75 1.75 0 0 0-2.475 0L6.75 6.774a2.75 2.75 0 0 0-.596.892l-.848 2.047a.75.75 0 0 0 .98.98l2.047-.848a2.75 2.75 0 0 0 .892-.596l4.261-4.262a1.75 1.75 0 0 0 0-2.474Z" />
                            <path d="M4.75 3.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h6.5c.69 0 1.25-.56 1.25-1.25V9A.75.75 0 0 1 14 9v2.25A2.75 2.75 0 0 1 11.25 14h-6.5A2.75 2.75 0 0 1 2 11.25v-6.5A2.75 2.75 0 0 1 4.75 2H7a.75.75 0 0 1 0 1.5H4.75Z" />
                          </svg>
                        ) : null}
                      </button>
                    )}
                  </TableCell>
                  <TableCell className="px-3 py-3.5 text-right">
                    {isEditing ? (
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="rounded-lg px-3 text-slate-600 hover:bg-slate-100"
                          onClick={onEditCancel}
                          disabled={updatingValue}
                        >
                          Cancel
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          className="rounded-lg bg-slate-900 px-3 text-white hover:bg-slate-800"
                          onClick={() => onEditSave(item)}
                          disabled={updatingValue}
                        >
                          {updatingValue ? 'Saving...' : 'Save'}
                        </Button>
                      </div>
                    ) : item?.id ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="rounded-lg border-0 bg-rose-50 px-3 text-rose-600 hover:bg-rose-100 hover:text-rose-700"
                        onClick={() => onRemove(item)}
                      >
                        <Trash2 className="mr-2 size-4" />
                        Remove
                      </Button>
                    ) : (
                      <span className="inline-flex rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-500">
                        System item
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })
          )}
        </TableBody>
      </Table>
    </div>
  )
}

function Field({ label, children }) {
  return (
    <label className="block text-sm font-medium text-slate-700">
      <span className="mb-1.5 block">{label}</span>
      {children}
    </label>
  )
}

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'HR'
  const first = parts[0]?.[0] || ''
  const second = parts[1]?.[0] || first
  return `${first}${second}`.toUpperCase()
}

function describeContributions(item) {
  const values = []
  if (item.contributes_sss) values.push('SSS')
  if (item.contributes_philhealth) values.push('PhilHealth')
  if (item.contributes_pagibig) values.push('Pag-IBIG')
  return values.length > 0 ? values.join(', ') : 'None'
}

function formatCalculationType(value) {
  const map = {
    fixed_amount: 'Fixed Amount',
    percent_basic: '% of Basic',
    percent_gross: '% of Gross',
    daily_rate: 'Daily Rate',
    formula: 'Formula',
    hourly: 'Hourly',
  }
  return map[value] || value
}

function formatPeso(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function getAssignmentSourceStyles(item) {
  const source = item?.metadata?.assignment_source

  if (source === 'auto_apply_all') {
    return {
      label: 'Auto-applied',
      className: 'bg-indigo-50 text-indigo-700',
    }
  }

  if (source === 'manual_override') {
    return {
      label: 'Manual override',
      className: 'bg-amber-50 text-amber-700',
    }
  }

  if (!item?.id) {
    return {
      label: 'System item',
      className: 'bg-slate-100 text-slate-600',
    }
  }

  return {
    label: 'Manual',
    className: 'bg-emerald-50 text-emerald-700',
  }
}

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-slate-400 focus:ring-4 focus:ring-slate-100'
