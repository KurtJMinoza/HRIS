import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Loader2,
  Plus,
  Pencil,
  Trash2,
  Eye,
  Search,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  getEmployeeBenefits,
  getBenefitCatalogs,
  assignEmployeeBenefit,
  updateEmployeeBenefit,
  removeEmployeeBenefit,
  getEmployees,
} from '@/api'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'

const BENEFIT_TYPE_LABELS = {
  health_insurance: 'Health Insurance',
  retirement_plan: 'Retirement Plan',
  leave_benefits: 'Leave Benefits',
  allowance: 'Allowance',
  other: 'Other Company Benefits',
}

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
]

const PAGE_SIZE = 8

function formatDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function getCoverageDetails(benefit) {
  const source =
    benefit?.metadata && typeof benefit.metadata === 'object'
      ? benefit.metadata
      : benefit?.catalog?.metadata && typeof benefit.catalog.metadata === 'object'
        ? benefit.catalog.metadata
        : null
  if (!source) return '—'
  const parts = []
  if (source.amount != null && source.amount !== '') parts.push(`P${Number(source.amount).toLocaleString()}`)
  if (source.frequency) parts.push(source.frequency)
  if (source.coverage) parts.push(source.coverage)
  if (source.provider) parts.push(source.provider)
  if (source.contribution) parts.push(source.contribution)
  if (source.leave_days) parts.push(`${source.leave_days} days`)
  return parts.filter(Boolean).join(' · ') || '—'
}

function getStatusBadgeClass(status) {
  if (status === 'active') return 'bg-emerald-100 text-emerald-700 border-emerald-200'
  if (status === 'inactive') return 'bg-slate-100 text-slate-700 border-slate-200'
  if (status === 'suspended') return 'bg-amber-100 text-amber-700 border-amber-200'
  return 'bg-muted text-muted-foreground border-border'
}

export default function AdminEmployeeBenefits() {
  const [searchParams, setSearchParams] = useSearchParams()
  const employeeIdParam = searchParams.get('employeeId')
  const [employeeId, setEmployeeId] = useState(employeeIdParam ? Number(employeeIdParam) : null)
  const [employees, setEmployees] = useState([])
  const [selectedEmployee, setSelectedEmployee] = useState(null)
  const [benefits, setBenefits] = useState([])
  const [catalogs, setCatalogs] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [assignOpen, setAssignOpen] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [removeConfirmOpen, setRemoveConfirmOpen] = useState(false)
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [assignForm, setAssignForm] = useState({
    benefit_catalog_id: '',
    effective_date: new Date().toISOString().slice(0, 10),
    status: 'active',
  })
  const [editTarget, setEditTarget] = useState(null)
  const [editForm, setEditForm] = useState({ effective_date: '', status: 'active' })
  const [removeTarget, setRemoveTarget] = useState(null)
  const [detailsTarget, setDetailsTarget] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    if (employeeIdParam) setEmployeeId(Number(employeeIdParam))
  }, [employeeIdParam])

  const fetchBenefits = useCallback(async (eid) => {
    if (!eid) {
      setBenefits([])
      setSelectedEmployee(null)
      return
    }
    setError(null)
    try {
      const data = await getEmployeeBenefits(eid)
      setBenefits(data.benefits || [])
      setSelectedEmployee({ id: data.employee_id, name: data.employee_name, department_id: data.department_id })
    } catch (e) {
      setError(e.message)
      setBenefits([])
      setSelectedEmployee(null)
    }
  }, [])

  const fetchCatalogs = useCallback(async (departmentId) => {
    if (!departmentId) {
      setCatalogs([])
      return
    }
    try {
      const data = await getBenefitCatalogs({ department_id: departmentId })
      setCatalogs(data.catalogs || [])
    } catch {
      setCatalogs([])
    }
  }, [])

  useEffect(() => {
    if (!employeeId) {
      setLoading(true)
      getEmployees({ per_page: 500 })
        .then((d) => {
          const list = Array.isArray(d.employees) ? d.employees : []
          setEmployees(list)
          if (list.length > 0) {
            const firstEmployeeId = Number(list[0].id)
            setEmployeeId(firstEmployeeId)
            setSearchParams({ employeeId: String(firstEmployeeId) })
            return
          }
          setBenefits([])
          setSelectedEmployee(null)
          setCatalogs([])
        })
        .catch((e) => {
          setError(e.message || 'Failed to load employees')
          setBenefits([])
          setSelectedEmployee(null)
          setCatalogs([])
        })
        .finally(() => setLoading(false))
      return
    }
    setLoading(true)
    Promise.all([
      getEmployeeBenefits(employeeId),
      getEmployees({ per_page: 500 }).then((d) => setEmployees(d.employees || [])),
    ])
      .then(([benefitData]) => {
        setBenefits(benefitData.benefits || [])
        setSelectedEmployee({
          id: benefitData.employee_id,
          name: benefitData.employee_name,
          department_id: benefitData.department_id,
        })
        if (benefitData.department_id) {
          return getBenefitCatalogs({ department_id: benefitData.department_id }).then((c) =>
            setCatalogs(c.catalogs || [])
          )
        }
        setCatalogs([])
      })
      .catch((e) => {
        setError(e.message)
        setBenefits([])
        setSelectedEmployee(null)
        setCatalogs([])
      })
      .finally(() => setLoading(false))
  }, [employeeId, setSearchParams])

  useEffect(() => {
    if (selectedEmployee?.department_id) fetchCatalogs(selectedEmployee.department_id)
  }, [selectedEmployee?.department_id, fetchCatalogs])

  const openAssign = () => {
    setAssignForm({
      benefit_catalog_id: '',
      effective_date: new Date().toISOString().slice(0, 10),
      status: 'active',
    })
    setAssignOpen(true)
  }

  const handleAssign = async () => {
    if (!employeeId || !assignForm.benefit_catalog_id || !assignForm.effective_date) {
      toast.error('Select a plan and effective date.')
      return
    }
    setSubmitting(true)
    try {
      await assignEmployeeBenefit(employeeId, {
        benefit_catalog_id: Number(assignForm.benefit_catalog_id),
        effective_date: assignForm.effective_date,
        status: assignForm.status,
      })
      await fetchBenefits(employeeId)
      setAssignOpen(false)
      toast.success('Benefit assigned successfully.')
    } catch (e) {
      toast.error(e.message || 'Failed to assign benefit')
    } finally {
      setSubmitting(false)
    }
  }

  const openEdit = (benefit) => {
    setEditTarget(benefit)
    setEditForm({
      effective_date: benefit.effective_date || '',
      status: benefit.status || 'active',
    })
    setEditOpen(true)
  }

  const handleEdit = async () => {
    if (!employeeId || !editTarget) return
    setSubmitting(true)
    try {
      await updateEmployeeBenefit(employeeId, editTarget.id, {
        effective_date: editForm.effective_date,
        status: editForm.status,
      })
      await fetchBenefits(employeeId)
      setEditOpen(false)
      setEditTarget(null)
      toast.success('Benefit updated successfully.')
    } catch (e) {
      toast.error(e.message || 'Failed to update')
    } finally {
      setSubmitting(false)
    }
  }

  const openRemoveConfirm = (benefit) => {
    setRemoveTarget(benefit)
    setRemoveConfirmOpen(true)
  }

  const openDetails = (benefit) => {
    setDetailsTarget(benefit)
    setDetailsOpen(true)
  }

  const handleRemove = async () => {
    if (!employeeId || !removeTarget) return
    setSubmitting(true)
    try {
      await removeEmployeeBenefit(employeeId, removeTarget.id)
      await fetchBenefits(employeeId)
      setRemoveConfirmOpen(false)
      setRemoveTarget(null)
      toast.success('Benefit removed from employee.')
    } catch (e) {
      toast.error(e.message || 'Failed to remove benefit')
    } finally {
      setSubmitting(false)
    }
  }

  const benefitsList = useMemo(() => (Array.isArray(benefits) ? benefits : []), [benefits])
  const catalogsList = useMemo(() => (Array.isArray(catalogs) ? catalogs : []), [catalogs])
  const assignedCatalogIds = new Set(benefitsList.map((b) => b.benefit_catalog_id))

  const filteredBenefits = useMemo(() => {
    const q = searchQuery.trim().toLowerCase()
    return benefitsList.filter((b) => {
      if (!q) return true
      const type = b.catalog?.type || 'other'
      const searchTarget = [
        BENEFIT_TYPE_LABELS[type] || type,
        b.catalog?.name || '',
        getCoverageDetails(b),
        formatDate(b.effective_date),
        b.status || '',
      ]
        .join(' ')
        .toLowerCase()
      return searchTarget.includes(q)
    })
  }, [benefitsList, searchQuery])

  const totalPages = Math.max(1, Math.ceil(filteredBenefits.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const paginatedBenefits = filteredBenefits.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  useEffect(() => {
    setPage(1)
  }, [employeeId, searchQuery])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-semibold tracking-tight">Benefits</h2>
          <p className="text-sm text-muted-foreground">
            {selectedEmployee ? `${selectedEmployee.name} — assign and manage benefits.` : 'Benefits data table'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={openAssign} disabled={!selectedEmployee?.department_id}>
            <Plus className="mr-2 size-4" />
            Assign Benefit
          </Button>
        </div>
      </div>

      {loading ? (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <tbody>
              <TableBodySkeleton rows={6} cols={5} />
            </tbody>
          </table>
        </div>
      ) : error ? (
        <Card>
          <CardContent className="py-6">
            <p className="text-destructive">{error}</p>
            <Button variant="outline" className="mt-2" onClick={() => fetchBenefits(employeeId)}>
              Retry
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          {!selectedEmployee?.department_id && (
            <Card className="border-amber-500/50">
              <CardContent className="py-4 text-sm text-amber-700 dark:text-amber-400">
                This employee has no company assigned. Assign a company in their profile to assign company benefits.
              </CardContent>
            </Card>
          )}

          <Card className="border-0 bg-card shadow-sm overflow-hidden">
            <CardHeader className="border-b border-border/40 bg-white">
              <div className="flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
                <div>
                  <CardTitle className="text-lg font-semibold">Assigned Benefits</CardTitle>
                  <CardDescription>
                    {filteredBenefits.length} of {benefitsList.length} benefit(s)
                  </CardDescription>
                </div>
                <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-end">
                  <div className="relative w-full @md:w-64">
                    <Input
                      type="text"
                      className="h-9 pl-8 text-sm"
                      placeholder="Search benefits..."
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                    />
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  </div>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[920px] text-sm">
                  <thead className="sticky top-0 z-10 border-b border-border/40 bg-white shadow-[0_1px_0_0_var(--border)] dark:bg-card">
                    <tr>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Benefit Type
                      </th>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Plan / Benefit Name
                      </th>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Coverage / Details
                      </th>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Effective Date
                      </th>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Status
                      </th>
                      <th className="text-left px-5 py-4 text-xs font-semibold uppercase tracking-wider text-[#0a0a0a] dark:text-foreground">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border/40">
                    {paginatedBenefits.length === 0 ? (
                      <tr className="odd:bg-background even:bg-muted/5">
                        <td colSpan={6} className="px-5 py-14 text-center text-muted-foreground">
                          {(Array.isArray(employees) ? employees : []).length === 0
                            ? 'No employees found. Add an employee first to assign benefits.'
                            : benefitsList.length === 0
                              ? 'No benefits assigned yet. Use Assign Benefit to add one.'
                              : 'No matching benefits found for your search/filter.'}
                        </td>
                      </tr>
                    ) : (
                      paginatedBenefits.map((b) => {
                        const type = b.catalog?.type || 'other'
                        return (
                          <tr
                            key={b.id}
                            className="group transition-colors duration-200 odd:bg-background even:bg-muted/5 hover:bg-gray-100 dark:odd:bg-card dark:even:bg-card dark:hover:bg-[#1F2937]"
                          >
                            <td className="px-5 py-2.5 text-muted-foreground">{BENEFIT_TYPE_LABELS[type] || type}</td>
                            <td className="px-5 py-2.5">
                              <p className="font-medium text-foreground">{b.catalog?.name || '—'}</p>
                            </td>
                            <td className="px-5 py-2.5 text-muted-foreground max-w-[280px]">
                              <span className="block truncate" title={getCoverageDetails(b)}>
                                {getCoverageDetails(b)}
                              </span>
                            </td>
                            <td className="px-5 py-2.5 text-muted-foreground">{formatDate(b.effective_date)}</td>
                            <td className="px-5 py-2.5">
                              <Badge className={`border capitalize ${getStatusBadgeClass(b.status)}`}>{b.status || 'unknown'}</Badge>
                            </td>
                            <td className="px-5 py-2.5">
                              <div className="flex items-center gap-1">
                                <Button variant="ghost" size="sm" onClick={() => openDetails(b)}>
                                  <Eye className="mr-1 size-4" />
                                  View
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => openEdit(b)}>
                                  <Pencil className="mr-1 size-4" />
                                  Edit
                                </Button>
                                <Button variant="ghost" size="sm" className="text-destructive" onClick={() => openRemoveConfirm(b)}>
                                  <Trash2 className="mr-1 size-4" />
                                  Remove
                                </Button>
                              </div>
                            </td>
                          </tr>
                        )
                      })
                    )}
                  </tbody>
                </table>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/40 px-4 py-3">
                <p className="text-xs text-muted-foreground">
                  Page {currentPage} of {totalPages}
                </p>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-xs"
                    disabled={currentPage <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="mr-1 size-3.5" />
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-xs"
                    disabled={currentPage >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  >
                    Next
                    <ChevronRight className="ml-1 size-3.5" />
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        </>
      )}

      <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="benefit-assign-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Assign Benefit</DialogTitle>
              <p id="benefit-assign-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Choose a benefit from the company catalog and set effective date.
              </p>
            </DialogHeader>
          </div>
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label>Plan / Option</Label>
              <select
                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={assignForm.benefit_catalog_id}
                onChange={(e) => setAssignForm((f) => ({ ...f, benefit_catalog_id: e.target.value }))}
              >
                <option value="">Select plan…</option>
                {(catalogsList)
                  .filter((c) => !assignedCatalogIds.has(c.id))
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({BENEFIT_TYPE_LABELS[c.type] || c.type})
                    </option>
                  ))}
                {catalogsList.filter((c) => !assignedCatalogIds.has(c.id)).length === 0 && catalogsList.length > 0 && (
                  <option value="" disabled>
                    All available plans already assigned
                  </option>
                )}
              </select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="assign-effective_date">Effective Date</Label>
              <Input
                id="assign-effective_date"
                type="date"
                value={assignForm.effective_date}
                onChange={(e) => setAssignForm((f) => ({ ...f, effective_date: e.target.value }))}
              />
            </div>
            <div className="space-y-2">
              <Label>Status</Label>
              <select
                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={assignForm.status}
                onChange={(e) => setAssignForm((f) => ({ ...f, status: e.target.value }))}
              >
                {STATUS_OPTIONS.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" onClick={() => setAssignOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleAssign}
              disabled={submitting || !assignForm.benefit_catalog_id || !assignForm.effective_date}
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
            >
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Assign Benefit'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="benefit-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit Benefit Assignment</DialogTitle>
              <p id="benefit-edit-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {editTarget?.catalog?.name && `Update effective date and status for ${editTarget.catalog.name}.`}
              </p>
            </DialogHeader>
          </div>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label htmlFor="edit-effective_date">Effective Date</Label>
              <Input
                id="edit-effective_date"
                type="date"
                value={editForm.effective_date}
                onChange={(e) => setEditForm((f) => ({ ...f, effective_date: e.target.value }))}
              />
            </div>
            <div className="space-y-2">
              <Label>Status</Label>
              <select
                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={editForm.status}
                onChange={(e) => setEditForm((f) => ({ ...f, status: e.target.value }))}
              >
                {STATUS_OPTIONS.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" onClick={() => setEditOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleEdit} disabled={submitting || !editForm.effective_date} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={removeConfirmOpen} onOpenChange={setRemoveConfirmOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="benefit-remove-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Remove Benefit</DialogTitle>
              <p id="benefit-remove-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Remove &quot;{removeTarget?.catalog?.name}&quot; from this employee? This cannot be undone.
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button variant="outline" onClick={() => setRemoveConfirmOpen(false)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleRemove} disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Remove'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="benefit-details-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Benefit Details</DialogTitle>
              <p id="benefit-details-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Review assignment details for this employee benefit.
              </p>
            </DialogHeader>
          </div>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-2 text-sm')}>
            <p>
              <span className="text-muted-foreground">Type: </span>
              <span className="font-medium">
                {BENEFIT_TYPE_LABELS[detailsTarget?.catalog?.type || 'other'] || detailsTarget?.catalog?.type || '—'}
              </span>
            </p>
            <p>
              <span className="text-muted-foreground">Plan / Benefit Name: </span>
              <span className="font-medium">{detailsTarget?.catalog?.name || '—'}</span>
            </p>
            <p>
              <span className="text-muted-foreground">Coverage / Details: </span>
              <span className="font-medium">{detailsTarget ? getCoverageDetails(detailsTarget) : '—'}</span>
            </p>
            <p>
              <span className="text-muted-foreground">Effective Date: </span>
              <span className="font-medium">{formatDate(detailsTarget?.effective_date)}</span>
            </p>
            <p>
              <span className="text-muted-foreground">Status: </span>
              <span className="font-medium capitalize">{detailsTarget?.status || '—'}</span>
            </p>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" onClick={() => setDetailsOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
