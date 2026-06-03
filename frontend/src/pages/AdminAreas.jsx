import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Loader2, MapPin, Network, Pencil, Plus, Search, Trash2, Users } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import LeadershipPositionsSection from '@/components/organization/LeadershipPositionsSection'
import {
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
} from '@/lib/adminFormDialogStyles'
import { createArea, deleteArea, getAreaBranches, getAreas, getBranches, getCompanies, getEmployees, updateArea } from '@/api'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const EMPTY_FORM = {
  company_id: '',
  area_name: '',
  area_code: '',
  description: '',
  status: 'active',
}

export default function AdminAreas() {
  const { toast } = useToast()
  const [areas, setAreas] = useState([])
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [companyFilter, setCompanyFilter] = useState('')
  const [search, setSearch] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [selectedBranchIds, setSelectedBranchIds] = useState([])
  const [submitting, setSubmitting] = useState(false)
  const [deactivateTarget, setDeactivateTarget] = useState(null)
  const [deactivating, setDeactivating] = useState(false)
  const leadershipRef = useRef(null)
  const branchAssignmentsLoadingRef = useRef(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [areaRes, companyRes, branchRes, employeeRes] = await Promise.all([
        getAreas(companyFilter ? { company_id: companyFilter } : {}),
        getCompanies(),
        getBranches({ fresh: true }),
        getEmployees({ for_leadership_assignment: true, per_page: 'all' }),
      ])
      setAreas(areaRes.areas || [])
      setCompanies(companyRes.companies || [])
      setBranches(branchRes.branches || [])
      setEmployees(employeeRes.employees || [])
    } catch (error) {
      toast({ title: 'Failed to load areas', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }, [companyFilter, toast])

  useEffect(() => {
    void load()
  }, [load])

  const filteredAreas = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return areas
    return areas.filter((area) =>
      `${area.area_name || ''} ${area.area_code || ''} ${area.area_manager_name || ''} ${area.company_name || ''}`
        .toLowerCase()
        .includes(query),
    )
  }, [areas, search])

  const branchesForCompany = useMemo(() => {
    if (!form.company_id) return []
    return branches.filter((branch) => String(branch.company_id) === String(form.company_id))
  }, [branches, form.company_id])

  const selectedBranchSet = useMemo(() => new Set(selectedBranchIds.map(String)), [selectedBranchIds])

  const employeesUnderSelectedBranches = useMemo(() => {
    if (selectedBranchIds.length === 0) return []
    const ids = new Set(selectedBranchIds.map(String))
    return employees.filter((employee) => ids.has(String(employee.branch_id)))
  }, [employees, selectedBranchIds])

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY_FORM, company_id: companyFilter || '' })
    setSelectedBranchIds([])
    setDialogOpen(true)
  }

  const openEdit = async (area) => {
    setEditing(area)
    setForm({
      company_id: String(area.company_id || ''),
      area_name: area.area_name || '',
      area_code: area.area_code || '',
      description: area.description || '',
      status: area.status || 'active',
    })
    const cachedBranchIds = branches
      .filter((branch) => String(branch.area_id || '') === String(area.id))
      .map((branch) => Number(branch.id))
      .filter((id) => id > 0)
    setSelectedBranchIds(cachedBranchIds)
    setDialogOpen(true)
    branchAssignmentsLoadingRef.current = true

    try {
      const data = await getAreaBranches(area.id)
      const assignedBranches = Array.isArray(data.branches) ? data.branches : []
      setSelectedBranchIds(assignedBranches.map((branch) => Number(branch.id)).filter((id) => id > 0))
      setBranches((current) => {
        const assignedIds = new Set(assignedBranches.map((branch) => String(branch.id)))
        return current.map((branch) => {
          if (String(branch.area_id || '') === String(area.id) && !assignedIds.has(String(branch.id))) {
            return { ...branch, area_id: null, area_name: null }
          }
          if (assignedIds.has(String(branch.id))) {
            return { ...branch, area_id: area.id, area_name: area.area_name }
          }
          return branch
        })
      })
    } catch (error) {
      setSelectedBranchIds(
        branches
          .filter((branch) => String(branch.area_id || '') === String(area.id))
          .map((branch) => Number(branch.id)),
      )
      toast({ title: 'Failed to load assigned branches', description: error.message, variant: 'error' })
    } finally {
      branchAssignmentsLoadingRef.current = false
    }
  }

  const toggleBranch = (branchId) => {
    const id = Number(branchId)
    setSelectedBranchIds((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    )
  }

  const submit = async (event) => {
    event.preventDefault()
    if (!form.company_id || !form.area_name.trim()) {
      toast({ title: 'Company and area name are required', variant: 'error' })
      return
    }
    if (editing?.id && branchAssignmentsLoadingRef.current) {
      toast({ title: 'Branch assignments are still loading', description: 'Wait a moment, then save again.', variant: 'error' })
      return
    }
    setSubmitting(true)
    const wasEdit = Boolean(editing?.id)
    try {
      const payload = {
        company_id: Number(form.company_id),
        area_name: form.area_name.trim(),
        area_code: form.area_code.trim() || null,
        description: form.description.trim() || null,
        status: form.status || 'active',
        branch_ids: selectedBranchIds,
      }
      const response = wasEdit ? await updateArea(editing.id, payload) : await createArea(payload)
      const savedArea = response.area
      const areaId = wasEdit ? editing.id : savedArea?.id
      if (areaId) {
        setBranches((current) => {
          const selected = new Set(selectedBranchIds.map(String))
          const targetCompanyId = String(payload.company_id)
          return current.map((branch) => {
            if (selected.has(String(branch.id))) {
              return { ...branch, area_id: areaId, area_name: payload.area_name }
            }
            if (String(branch.company_id) === targetCompanyId && String(branch.area_id || '') === String(areaId)) {
              return { ...branch, area_id: null, area_name: null }
            }
            return branch
          })
        })
      }
      if (!wasEdit && savedArea) {
        setEditing(savedArea)
        setAreas((current) => {
          const without = current.filter((row) => row.id !== savedArea.id)
          return [...without, savedArea].sort((a, b) => String(a.area_name || '').localeCompare(String(b.area_name || '')))
        })
      }
      const leadershipAreaId = wasEdit ? editing.id : savedArea?.id
      if (leadershipAreaId && leadershipRef.current?.isDirty?.()) {
        const leadershipSaved = await leadershipRef.current.save()
        if (!leadershipSaved) {
          return
        }
      }
      await load()
      if (wasEdit) {
        setDialogOpen(false)
        setEditing(null)
        toast({ title: 'Area updated', variant: 'success' })
      } else {
        toast({
          title: 'Area created',
          description: 'Assign Area Heads on the right if needed, then save again to close.',
          variant: 'success',
        })
      }
    } catch (error) {
      const message = error.message || ''
      const duplicateCode = !wasEdit && /area code.*already|already been taken/i.test(message)
      if (duplicateCode) {
        const trimmedCode = form.area_code.trim()
        const existing = areas.find(
          (row) =>
            String(row.company_id) === String(form.company_id)
            && trimmedCode
            && String(row.area_code || '').toLowerCase() === trimmedCode.toLowerCase(),
        )
        if (existing) {
          toast({
            title: 'Area already exists',
            description: 'Opening the existing area so you can update branches and status.',
            variant: 'error',
          })
          await openEdit(existing)
          return
        }
      }
      toast({ title: wasEdit ? 'Failed to update area' : 'Failed to create area', description: message, variant: 'error' })
    } finally {
      setSubmitting(false)
    }
  }

  const confirmDeactivate = async () => {
    if (!deactivateTarget) return
    setDeactivating(true)
    try {
      const response = await deleteArea(deactivateTarget.id)
      const wasInactive = deactivateTarget.status === 'inactive'
      setDeactivateTarget(null)
      await load()
      toast({ title: wasInactive ? 'Area deleted' : 'Area deactivated', description: response.message, variant: 'success' })
    } catch (error) {
      toast({ title: 'Failed to update area', description: error.message, variant: 'error' })
    } finally {
      setDeactivating(false)
    }
  }

  const branchSelectionPanel = (
    <div className="space-y-3 rounded-2xl border border-border/70 bg-background p-4 shadow-sm">
      <div className="flex items-center justify-between gap-3">
        <div>
          <Label className="text-base font-semibold">Selected branches</Label>
          <p className="mt-1 text-xs text-muted-foreground">An area can contain multiple branches from the selected company.</p>
        </div>
        <Badge variant="secondary">{selectedBranchIds.length} selected</Badge>
      </div>
      <div className="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-border/70 bg-muted/10 p-3">
        {branchesForCompany.length === 0 ? (
          <p className="text-sm text-muted-foreground">No branches found for this company.</p>
        ) : branchesForCompany.map((branch) => (
          <label key={branch.id} className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40">
            <span className="flex items-center gap-2">
              <input type="checkbox" className="size-4 accent-brand" checked={selectedBranchSet.has(String(branch.id))} onChange={() => toggleBranch(branch.id)} />
              <span>{branch.name}</span>
            </span>
            {branch.area_name && String(branch.area_id) !== String(editing?.id || '') ? (
              <span className="text-xs text-muted-foreground">Currently: {branch.area_name}</span>
            ) : null}
          </label>
        ))}
      </div>
      <div className="rounded-xl border border-border/70 bg-muted/10 p-3 text-sm">
        <div className="flex items-center gap-2 font-semibold"><Users className="size-4" /> Employees under selected branches</div>
        <p className="mt-1 text-muted-foreground">{employeesUnderSelectedBranches.length} employee{employeesUnderSelectedBranches.length === 1 ? '' : 's'} visible through the selected branches.</p>
      </div>
    </div>
  )

  return (
    <div className="min-h-full bg-background px-4 py-6 text-foreground @md:px-6 @lg:px-8">
      <div className="mb-6 flex flex-col gap-4 @md:flex-row @md:items-center @md:justify-between">
        <div className="flex items-center gap-4">
          <div className="flex size-14 items-center justify-center rounded-xl bg-brand/10 text-brand">
            <Network className="size-7" />
          </div>
          <div>
            <h1 className="text-[30px] font-extrabold leading-tight tracking-normal">Areas</h1>
            <p className="mt-1 text-sm text-muted-foreground">Group branches under companies and assign Area Heads using the same leadership flow as Divisions.</p>
          </div>
        </div>
        <Button onClick={openCreate} className="h-11 rounded-xl bg-brand px-5 font-bold text-brand-foreground hover:bg-brand-strong">
          <Plus className="size-4" />
          Add Area
        </Button>
      </div>

      <div className="mb-5 flex flex-col gap-3 @md:flex-row">
        <select className="h-11 rounded-xl border border-border bg-background px-3 text-sm" value={companyFilter} onChange={(event) => setCompanyFilter(event.target.value)}>
          <option value="">All companies</option>
          {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
        </select>
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search area, code, company, or head" className="h-11 rounded-xl pl-9" />
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="border-b border-border/70 bg-muted/30 text-left">
              <tr>
                <th className="px-4 py-3 font-semibold">Area</th>
                <th className="px-4 py-3 font-semibold">Company</th>
                <th className="px-4 py-3 font-semibold">Area Heads</th>
                <th className="px-4 py-3 font-semibold">Selected Branches</th>
                <th className="px-4 py-3 font-semibold">Employees</th>
                <th className="px-4 py-3 font-semibold">Status</th>
                <th className="px-4 py-3 text-right font-semibold">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">Loading areas...</td></tr>
              ) : filteredAreas.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">No areas found.</td></tr>
              ) : filteredAreas.map((area) => (
                <tr key={area.id} className="border-b border-border/60 last:border-b-0">
                  <td className="px-4 py-3">
                    <div className="font-bold">{area.area_name}</div>
                    {area.area_code ? <Badge variant="secondary" className="mt-1">{area.area_code}</Badge> : null}
                  </td>
                  <td className="px-4 py-3">{area.company_name || '—'}</td>
                  <td className="px-4 py-3">{area.area_manager_name || 'Use Assign Head'}</td>
                  <td className="px-4 py-3">{area.branches_count || 0}</td>
                  <td className="px-4 py-3">{area.employees_count || 0}</td>
                  <td className="px-4 py-3"><Badge variant={area.status === 'inactive' ? 'outline' : 'secondary'} className="capitalize">{area.status || 'active'}</Badge></td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-2">
                      <Button variant="ghost" size="icon" className="size-8" onClick={() => openEdit(area)}><Pencil className="size-4" /></Button>
                      <Button variant="ghost" size="icon" className="size-8 text-destructive" onClick={() => setDeactivateTarget(area)}><Trash2 className="size-4" /></Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={(open) => { setDialogOpen(open); if (!open) setEditing(null) }}>
        <DialogContent
          showCloseButton
          surfaceStyle={{
            width: 'min(calc(100vw - 1.5rem), 88rem)',
            maxWidth: 'none',
            height: 'min(92vh, 52rem)',
          }}
          className="max-h-[min(92vh,52rem)] min-h-0 min-w-0 max-w-none! rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="flex min-h-0 flex-1 flex-col !gap-0 !overflow-hidden !p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="area-edit-desc"
        >
          <div className={cn(ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS, 'shrink-0')}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>{editing ? 'Edit Area' : 'Create Area'}</DialogTitle>
              <p id="area-edit-desc" className="mt-2 text-sm text-muted-foreground">
                Maintain area details, selected branches, employee visibility, and Area Head approval scope.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col overflow-hidden text-foreground">
            <div className="grid min-h-0 flex-1 grid-cols-1 divide-y divide-border/80 overflow-hidden lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:divide-x lg:divide-y-0">
              <div className="min-h-0 space-y-5 overflow-y-auto px-6 py-5 md:px-8">
                <div className="space-y-2">
                  <Label className="text-base font-semibold">Company <span className="text-brand">*</span></Label>
                  <select className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-sm" value={form.company_id} onChange={(event) => { setForm((current) => ({ ...current, company_id: event.target.value })); setSelectedBranchIds([]) }}>
                    <option value="">Select company</option>
                    {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                  </select>
                </div>
                <div className="grid gap-4 @md:grid-cols-2">
                  <div className="space-y-2">
                    <Label className="text-base font-semibold">Area name <span className="text-brand">*</span></Label>
                    <Input value={form.area_name} onChange={(event) => setForm((current) => ({ ...current, area_name: event.target.value }))} className="h-12 rounded-xl" />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-base font-semibold">Area code</Label>
                    <Input value={form.area_code} onChange={(event) => setForm((current) => ({ ...current, area_code: event.target.value }))} className="h-12 rounded-xl" />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label className="text-base font-semibold">Status</Label>
                  <select className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-sm" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
                <div className="space-y-2">
                  <Label className="text-base font-semibold">Description</Label>
                  <textarea value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} rows={3} className="min-h-[88px] w-full rounded-xl border border-border/80 bg-background px-3 py-2 text-sm" />
                </div>
                {branchSelectionPanel}
              </div>
              {editing?.id ? (
                <div className="min-h-0 overflow-y-auto bg-muted/10 px-4 py-5 md:px-6">
                  <LeadershipPositionsSection ref={leadershipRef} legacyType="area" legacyId={editing.id} employeeOptions={employees} canManage />
                </div>
              ) : (
                <div className="min-h-0 overflow-y-auto bg-muted/10 px-4 py-5 md:px-6">
                  <div className="rounded-2xl border border-dashed border-border/80 bg-background p-6 text-center">
                    <MapPin className="mx-auto size-10 text-muted-foreground" />
                    <h3 className="mt-4 font-bold">Save area before assigning heads</h3>
                    <p className="mt-2 text-sm text-muted-foreground">Select branches on the left, save once to create the area, then assign Area Heads here.</p>
                  </div>
                </div>
              )}
            </div>
            <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'shrink-0')}>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={submitting} className="bg-brand text-brand-foreground hover:bg-brand-strong">
                {submitting ? <Loader2 className="size-4 animate-spin" /> : 'Save changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!deactivateTarget} onOpenChange={(open) => { if (!open) setDeactivateTarget(null) }}>
        <DialogContent showCloseButton className="max-w-md rounded-2xl" aria-describedby="area-deactivate-desc">
          <DialogHeader>
            <DialogTitle>{deactivateTarget?.status === 'inactive' ? 'Delete area permanently?' : 'Deactivate area?'}</DialogTitle>
            <p id="area-deactivate-desc" className="text-sm text-muted-foreground">
              {deactivateTarget?.status === 'inactive' ? (
                <>
                  This permanently deletes <strong className="text-foreground">{deactivateTarget.area_name}</strong>.
                  This is only allowed when no branches are assigned.
                </>
              ) : deactivateTarget ? (
                <>
                  This marks <strong className="text-foreground">{deactivateTarget.area_name}</strong> as inactive.
                  Branch links stay in place and you can set the area back to active when editing.
                </>
              ) : null}
            </p>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => setDeactivateTarget(null)} disabled={deactivating}>Cancel</Button>
            <Button type="button" variant="destructive" onClick={() => void confirmDeactivate()} disabled={deactivating}>
              {deactivating ? <Loader2 className="size-4 animate-spin" /> : deactivateTarget?.status === 'inactive' ? 'Delete permanently' : 'Deactivate'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
