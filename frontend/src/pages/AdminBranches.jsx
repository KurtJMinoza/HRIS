import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Plus, MapPin, Loader2, MoreVertical, Pencil, Trash2, Building2, Layers, Users, ExternalLink, ChevronRight, ChevronLeft, Search, ChevronDown } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import LeadershipPositionsSection from '@/components/organization/LeadershipPositionsSection'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { getBranches, getCompanies, getEmployees, createBranch, updateBranch, deleteBranch, profileImageUrl, departmentLogoUrl } from '@/api'
import { isRosterStaffMember } from '@/lib/rosterStaff'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'

function initials(name) {
  return (name || '?').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '?'
}

/**
 * Build map: userId -> { companyName, branchName } for employees who are branch managers.
 * excludeBranchId: when editing a branch, its current manager is not "already assigned" elsewhere.
 */
function buildBranchManagerMap(branches, excludeBranchId) {
  const map = new Map()
  for (const b of branches || []) {
    if (!b.branch_manager_id) continue
    if (String(b.id) === String(excludeBranchId)) continue // editing this branch - manager can stay
    map.set(String(b.branch_manager_id), {
      companyName: b.company_name || '',
      branchName: b.name || '',
    })
  }
  return map
}

/** Searchable Branch Manager picker - QA spec: search, avatars, position, assignment status. */
function BranchManagerPicker({ value, onChange, employees, branches, companies, companyId, excludeBranchId, disabled, triggerClassName }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const branchManagerMap = useMemo(() => buildBranchManagerMap(branches, excludeBranchId), [branches, excludeBranchId])
  /** Map: userId -> companyName for employees who are company heads */
  const companyHeadMap = useMemo(() => {
    const map = new Map()
    for (const c of companies || []) {
      if (c.company_head_id) map.set(String(c.company_head_id), c.name || 'a company')
    }
    return map
  }, [companies])
  const filtered = useMemo(() => {
    const list = (employees || []).filter((e) => isRosterStaffMember(e))
    const q = search.trim().toLowerCase()
    if (!q) return list
    const haystack = (emp) => `${emp.name || ''} ${emp.employee_code || ''} ${emp.email || ''} ${emp.position || ''} ${emp.department || ''}`.toLowerCase()
    return list.filter((emp) => haystack(emp).includes(q))
  }, [employees, search])
  const selected = (employees || []).find((e) => String(e.id) === String(value))
  const needsCompany = !companyId
  const emptySearch = filtered.length === 0 && !needsCompany

  return (
    <Popover open={open} onOpenChange={(o) => { setOpen(o); if (!o) setSearch('') }}>
      <PopoverTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className={cn(
            'flex h-10 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-left text-sm transition-colors hover:bg-muted/50 disabled:pointer-events-none disabled:opacity-50 dark:border-white/10 dark:bg-slate-900/50 dark:hover:bg-slate-800/60',
            triggerClassName,
          )}
        >
          {selected ? (
            <div className="flex min-w-0 flex-1 items-center gap-2">
              <Avatar className="size-7 shrink-0">
                <AvatarImage src={profileImageUrl(selected.profile_image)} />
                <AvatarFallback className="text-[10px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">
                  {initials(selected.name)}
                </AvatarFallback>
              </Avatar>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-foreground">{selected.name}{selected.employee_code ? ` (${selected.employee_code})` : ''}</p>
                {selected.position && (
                  <p className="truncate text-[11px] text-muted-foreground">{selected.position}</p>
                )}
              </div>
            </div>
          ) : (
            <span className="text-muted-foreground">No employee selected</span>
          )}
          <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-[320px] p-0 dark:border-slate-700 dark:bg-slate-900 shadow-xl" align="start">
        <div className="border-b border-border/60 p-2 dark:border-slate-700">
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search by name, employee ID, position..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-9 pl-8 dark:bg-slate-800/60 dark:border-slate-600"
              autoFocus
              disabled={needsCompany}
            />
          </div>
        </div>
        <div className="max-h-[260px] overflow-y-auto">
          {needsCompany ? (
            <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
              <p className="text-sm text-muted-foreground">Select a company first</p>
              <p className="text-[11px] text-muted-foreground/70">Any active employee can be assigned, including cross-company leaders.</p>
            </div>
          ) : (
            <>
              {value ? (
                <div className="border-b border-border/60 p-2 dark:border-slate-700">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 w-full border-destructive/40 text-destructive hover:bg-destructive/10"
                    onClick={() => { onChange(''); setOpen(false) }}
                  >
                    Remove employee
                  </Button>
                </div>
              ) : null}
              <button
                type="button"
                onClick={() => { onChange(''); setOpen(false) }}
                className={`flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition-colors hover:bg-slate-100 dark:hover:bg-slate-800/80 ${!value ? 'bg-slate-100 dark:bg-slate-800/60 dark:border-l-2 dark:border-l-teal-500' : ''}`}
              >
                <span className="text-muted-foreground">No employee selected</span>
              </button>
              {filtered.map((emp, idx) => {
                const branchAssignment = branchManagerMap.get(String(emp.id))
                const companyHeadOf = companyHeadMap.get(String(emp.id))
                const isInactive = emp.is_active === false
                return (
                  <button
                    key={emp.id}
                    type="button"
                    disabled={isInactive}
                    onClick={() => { if (!isInactive) { onChange(String(emp.id)); setOpen(false) } }}
                    title={
                      branchAssignment
                        ? `Also Branch Manager — ${branchAssignment.companyName} / ${branchAssignment.branchName}`
                        : companyHeadOf
                        ? `Also Company Head of ${companyHeadOf}`
                        : undefined
                    }
                    className={`flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition-colors ${!isInactive ? 'hover:bg-slate-100 dark:hover:bg-slate-800/80 cursor-pointer' : 'opacity-60 cursor-not-allowed'} ${value === String(emp.id) ? 'bg-slate-100 dark:bg-slate-800/60 dark:border-l-2 dark:border-l-teal-500' : ''} ${idx % 2 === 1 ? 'dark:bg-slate-900/30' : ''}`}
                  >
                    <Avatar className="size-8 shrink-0">
                      <AvatarImage src={profileImageUrl(emp.profile_image)} />
                      <AvatarFallback className="text-[11px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">
                        {initials(emp.name)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-foreground">{emp.name}{emp.employee_code ? ` (${emp.employee_code})` : ''}</p>
                      <p className="truncate text-[11px] text-muted-foreground">
                        {emp.position || emp.department || '-'}
                      </p>
                      {companyHeadOf && (
                        <Badge variant="secondary" className="mt-1 h-5 text-[10px] bg-amber-500/20 text-amber-700 dark:bg-amber-400/20 dark:text-amber-300 border-0">
                          Company Head — {companyHeadOf}
                        </Badge>
                      )}
                      {!companyHeadOf && branchAssignment && (
                        <Badge variant="secondary" className="mt-1 h-5 text-[10px] bg-amber-500/20 text-amber-700 dark:bg-amber-400/20 dark:text-amber-300 border-0">
                          Branch Manager — {branchAssignment.companyName} / {branchAssignment.branchName}
                        </Badge>
                      )}
                    </div>
                  </button>
                )
              })}
              {emptySearch && (
                <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                  <p className="text-sm text-muted-foreground">No employees found</p>
                  <p className="text-[11px] text-muted-foreground/70">Try a different name, ID, or position</p>
                </div>
              )}
            </>
          )}
        </div>
      </PopoverContent>
    </Popover>
  )
}

export default function AdminBranches() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()

  const [branches, setBranches] = useState([])
  const [companies, setCompanies] = useState([])
  const [allEmployees, setAllEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)

  // Read initial company filter from URL
  const [companyFilter, setCompanyFilter] = useState(() => searchParams.get('company_id') || '')

  const [createOpen, setCreateOpen] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createCompanyId, setCreateCompanyId] = useState('')
  const [createAddress, setCreateAddress] = useState('')
  const [createManagerId, setCreateManagerId] = useState('')
  const [createSubmitting, setCreateSubmitting] = useState(false)

  const [editOpen, setEditOpen] = useState(false)
  const [editBranch, setEditBranch] = useState(null)
  const [editName, setEditName] = useState('')
  const [editCompanyId, setEditCompanyId] = useState('')
  const [editAddress, setEditAddress] = useState('')
  const [editManagerId, setEditManagerId] = useState('')
  const [editSubmitting, setEditSubmitting] = useState(false)

  const [deleteConfirm, setDeleteConfirm] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  // Sync URL param when filter changes
  useEffect(() => {
    if (companyFilter) {
      setSearchParams({ company_id: companyFilter }, { replace: true })
    } else {
      setSearchParams({}, { replace: true })
    }
  }, [companyFilter, setSearchParams])

  useEffect(() => {
    setPage(1)
  }, [companyFilter])

  const fetchBranches = useCallback(async () => {
    try {
      const params = companyFilter ? { company_id: companyFilter } : {}
      const data = await getBranches(params)
      setBranches(data.branches || [])
    } catch (e) {
      setBranches([])
      toast({ title: 'Failed to load branches', description: e?.message || 'Please try again.', variant: 'error' })
    } finally {
      setLoading(false)
    }
  }, [companyFilter, toast])

  const fetchCompanies = useCallback(async () => {
    try {
      const data = await getCompanies()
      setCompanies(data.companies || [])
    } catch (e) {
      setCompanies([])
      toast({ title: 'Failed to load companies', description: e.message, variant: 'error' })
    }
  }, [toast])

  const fetchEmployees = useCallback(async (companyId) => {
    try {
      const params = { for_leadership_assignment: true, per_page: 'all' }
      const data = await getEmployees(params)
      setAllEmployees(data.employees || [])
    } catch {
      setAllEmployees([])
    }
  }, [])

  // Companies list (for filter dropdown) - load once when ready
  useEffect(() => {
    void fetchCompanies()
  }, [fetchCompanies])

  // Branches - refetch whenever company filter changes (includes initial mount)
  useEffect(() => {
    setLoading(true)
    void fetchBranches()
  }, [fetchBranches])

  // Fetch employees when modal opens; refetch when company changes (filter by assignable_to_company_id)
  const managerCompanyId = createOpen ? createCompanyId : editOpen ? editCompanyId : ''
  useEffect(() => {
    if (!createOpen && !editOpen) return
    fetchEmployees(managerCompanyId || null)
  }, [createOpen, editOpen, managerCompanyId]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleCreate = async (e) => {
    e.preventDefault()
    if (!createName.trim()) { toast({ title: 'Branch name is required', variant: 'error' }); return }
    if (!createCompanyId) { toast({ title: 'Please select a company', variant: 'error' }); return }
    setCreateSubmitting(true)
    const name = createName.trim()
    const createdCompanyId = parseInt(createCompanyId, 10)
    try {
      await createBranch({
        name,
        company_id: createdCompanyId,
        address: createAddress.trim() || undefined,
        branch_manager_id: createManagerId ? parseInt(createManagerId, 10) : null,
      })
      setCreateOpen(false)
      setCreateName(''); setCreateCompanyId(''); setCreateAddress(''); setCreateManagerId('')
      await fetchBranches()
      await fetchEmployees(createdCompanyId)
      toast({ title: `${name} created! Add departments next?`, variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to create branch', description: e.message, variant: 'error' })
    } finally {
      setCreateSubmitting(false)
    }
  }

  const openEdit = (branch) => {
    setEditBranch(branch)
    setEditName(branch.name)
    setEditCompanyId(branch.company_id ? String(branch.company_id) : '')
    setEditAddress(branch.address || '')
    setEditManagerId(branch.branch_manager_id ? String(branch.branch_manager_id) : '')
    setEditOpen(true)
  }

  const handleEdit = async (e) => {
    e.preventDefault()
    if (!editBranch || !editName.trim()) { toast({ title: 'Branch name is required', variant: 'error' }); return }
    setEditSubmitting(true)
    try {
      const companyIdForList = editCompanyId ? parseInt(editCompanyId, 10) : editBranch.company_id
      await updateBranch(editBranch.id, {
        name: editName.trim(),
        company_id: companyIdForList,
        address: editAddress.trim() || null,
        branch_manager_id: editManagerId ? parseInt(editManagerId, 10) : null,
      })
      setEditOpen(false)
      setEditBranch(null)
      await fetchBranches()
      await fetchEmployees(companyIdForList || null)
      toast({ title: 'Branch updated', variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to update branch', description: e.message, variant: 'error' })
    } finally {
      setEditSubmitting(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteConfirm) return
    setDeleteSubmitting(true)
    try {
      await deleteBranch(deleteConfirm.id)
      setDeleteConfirm(null)
      await fetchBranches()
      toast({ title: 'Branch deleted', variant: 'success' })
    } catch (e) {
      toast({ title: 'Failed to delete branch', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const activeCompany = companies.find((c) => String(c.id) === String(companyFilter))
  const pageSize = 6
  const totalBranches = branches.length
  const pageCount = Math.max(1, Math.ceil(totalBranches / pageSize))
  const currentPage = Math.min(page, pageCount)
  const pagedBranches = branches.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const rangeStart = totalBranches > 0 ? (currentPage - 1) * pageSize + 1 : 0
  const rangeEnd = Math.min(currentPage * pageSize, totalBranches)

  useEffect(() => {
    if (page > pageCount) setPage(pageCount)
  }, [page, pageCount])

  return (
    <div className="min-h-full bg-background px-4 py-6 text-foreground @md:px-6 @lg:px-8">
      <div className="space-y-6">
      {/* Breadcrumb - always visible, reinforces hierarchy */}
      <nav className="flex items-center gap-2 text-sm font-semibold" aria-label="Breadcrumb">
        <button type="button" onClick={() => navigate('/admin/companies')} className="text-brand transition-colors hover:text-brand-strong">Companies</button>
        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        <span className="text-foreground">Branches</span>
      </nav>

      <section className="flex flex-col gap-5 @md:flex-row @md:items-center @md:justify-between">
        <div className="flex items-center gap-4">
          <div className="flex size-16 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
            <MapPin className="size-8" />
          </div>
          <div>
            <h1 className="text-[30px] font-extrabold leading-tight tracking-normal text-foreground">Branches</h1>
            <p className="mt-1 text-base font-medium text-muted-foreground">
              Branches represent physical or operational locations of your company.
            </p>
          </div>
        </div>
        <Button
          className="h-12 rounded-xl bg-brand px-6 text-base font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong"
          onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAddress(''); setCreateManagerId('') }}
        >
          <Plus className="size-5" />
          Add Branch
        </Button>
      </section>

      <div className="flex flex-wrap items-center gap-3">
        <Label className="text-base font-medium text-muted-foreground">Filter by company</Label>
        <select
          className="h-11 min-w-[260px] rounded-xl border border-border/80 bg-background px-4 text-sm font-semibold text-foreground shadow-sm dark:bg-input/35 dark:[color-scheme:dark]"
          value={companyFilter}
          onChange={(e) => setCompanyFilter(e.target.value)}
        >
          <option value="">All companies</option>
          {companies.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
        {companyFilter && (
          <Button variant="ghost" size="sm" className="h-9 rounded-xl px-3 text-xs font-semibold text-muted-foreground hover:text-foreground" onClick={() => setCompanyFilter('')}>
            Clear filter
          </Button>
        )}
      </div>

      <div>
          {loading ? (
            <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
              {[...Array(6)].map((_, i) => (
                <div key={i} className="rounded-xl border border-border/50 dark:border-white/10 dark:bg-slate-800/60 p-4">
                  <div className="flex gap-3">
                    <Skeleton className="size-12 shrink-0 rounded-xl" />
                    <div className="flex-1 space-y-2">
                      <Skeleton className="h-5 w-3/4" />
                      <Skeleton className="h-4 w-1/2" />
                      <div className="mt-2 flex gap-2">
                        <Skeleton className="h-6 w-16 rounded-full" />
                        <Skeleton className="h-6 w-14 rounded-full" />
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : branches.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-border/70 px-8 py-20 text-center dark:bg-card/40">
              <div className="mb-5 flex size-20 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                <MapPin className="size-10 text-muted-foreground" />
              </div>
              <h3 className="text-xl font-bold text-foreground">No branches yet</h3>
              <p className="mt-2 max-w-md text-sm text-muted-foreground leading-relaxed">
                {activeCompany
                  ? `Start organizing ${activeCompany.name} by adding a branch. Branches group departments and employees by location.`
                  : 'Add a branch to get started. Select a company above to filter, or add a branch for any company.'}
              </p>
              <Button className="mt-6 rounded-xl bg-brand font-bold text-brand-foreground shadow-md transition-shadow hover:bg-brand-strong hover:shadow-lg" onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAddress(''); setCreateManagerId('') }}>
                <Plus className="size-4" />
                Add Branch
              </Button>
            </div>
          ) : (
            <>
            <div className="grid gap-5 @md:grid-cols-2 @xl:grid-cols-3">
              {pagedBranches.map((branch) => {
                return (
                  <div
                    key={branch.id}
                    className="group rounded-2xl border border-border/80 bg-card p-5 shadow-sm shadow-slate-900/[0.03] transition-all duration-200 hover:-translate-y-0.5 hover:border-brand/30 hover:shadow-lg dark:shadow-black/25"
                  >
                    <div className="flex gap-4">
                      <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border/80 bg-background shadow-sm dark:bg-input/35">
                        {departmentLogoUrl(branch) ? (
                          <img src={departmentLogoUrl(branch)} alt="" className="size-full object-cover" />
                        ) : (
                          <Building2 className="size-6 text-muted-foreground" />
                        )}
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="truncate text-xl font-extrabold text-foreground transition-colors group-hover:text-brand">{branch.name}</h3>
                        {branch.company_name && (
                          <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); navigate('/admin/companies') }}
                            className="mt-1 flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                          >
                            <Building2 className="size-3.5 shrink-0" />
                            <span>{branch.company_name} (Company)</span>
                          </button>
                        )}
                        <div className="mt-2.5 flex items-center gap-2">
                          {branch.branch_manager_name ? (
                            <div className="inline-flex w-fit items-center gap-2 rounded-full border border-brand/25 bg-brand/10 px-2.5 py-1">
                              <span className="text-[10px] font-semibold uppercase tracking-normal text-brand">Manager</span>
                              <Avatar className="size-6 shrink-0">
                                <AvatarImage src={profileImageUrl(branch.branch_manager_profile_image)} />
                                <AvatarFallback className="text-[9px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">{initials(branch.branch_manager_name)}</AvatarFallback>
                              </Avatar>
                              <span className="truncate text-sm font-medium text-foreground max-w-[140px]">{branch.branch_manager_name}</span>
                            </div>
                          ) : (
                            <span className="rounded-full border border-brand/25 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">No manager assigned</span>
                          )}
                        </div>
                        <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-border/70 pt-4">
                          <button
                            type="button"
                            onClick={() => navigate(`/admin/departments?branch_id=${branch.id}`)}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-brand/10 px-4 py-2 text-sm font-bold text-brand transition-colors hover:bg-brand/15"
                            title="View departments"
                          >
                            <Layers className="size-3 shrink-0" />
                            {branch.departments_count ?? 0} Departments
                          </button>
                          <span
                            className="inline-flex items-center gap-1.5 rounded-xl bg-muted px-4 py-2 text-sm font-bold text-foreground"
                            title={`${branch.employees_count ?? 0} employee(s) in this branch`}
                          >
                            <Users className="size-3 shrink-0" />
                            {branch.employees_count ?? 0} Employees
                          </span>
                          <div className="ml-auto">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                                <Button variant="ghost" size="icon" className="size-9 rounded-full hover:bg-muted" aria-label="Branch actions">
                                  <MoreVertical className="size-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem onClick={() => navigate(`/admin/departments?branch_id=${branch.id}`)}>
                                  <ExternalLink className="size-4" /><span>View Departments</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openEdit(branch)}>
                                  <Pencil className="size-4" /><span>Edit</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" onClick={() => setDeleteConfirm(branch)}>
                                  <Trash2 className="size-4" /><span>Delete</span>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
            <div className="mt-5 flex flex-col gap-3 border-t border-border/80 pt-5 text-sm text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between">
              <span>
                Showing {rangeStart} to {rangeEnd} of {totalBranches} branch{totalBranches === 1 ? '' : 'es'}
              </span>
              <div className="flex items-center justify-end gap-2">
                <Button type="button" variant="ghost" size="icon" className="size-9 rounded-full" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={currentPage <= 1}>
                  <ChevronLeft className="size-4" />
                </Button>
                <span className="flex size-10 items-center justify-center rounded-xl border border-brand bg-brand/10 text-sm font-bold text-brand">
                  {currentPage}
                </span>
                <Button type="button" variant="ghost" size="icon" className="size-9 rounded-full" onClick={() => setPage((p) => Math.min(pageCount, p + 1))} disabled={currentPage >= pageCount}>
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
            </>
          )}
        </div>
      </div>
      {/* Create Branch */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent
          showCloseButton
          className="max-w-[min(100vw-1.5rem,42rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="branch-create-desc"
        >
          <form onSubmit={handleCreate} className="flex min-h-0 flex-1 flex-col">
            <div className="border-b border-border/80 px-6 pb-5 pt-7 pr-16 @md:px-8">
              <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
                <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
                  <MapPin className="size-7" />
                </div>
                <div className="min-w-0 pt-1">
                  <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                    Create Branch
                  </DialogTitle>
                  <p id="branch-create-desc" className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                    Branches represent physical or operational locations of your company. Add departments after creation.
                  </p>
                </div>
              </DialogHeader>
            </div>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6 @md:px-8">
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Company <span className="text-brand">*</span></Label>
              {companyFilter ? (
                <div className="flex h-12 w-full items-center gap-3 rounded-xl border border-brand/60 bg-background px-4 text-sm text-foreground shadow-sm dark:bg-input/35">
                  <Building2 className="size-4 shrink-0 text-muted-foreground" />
                  <span className="font-medium">{companies.find((c) => String(c.id) === String(companyFilter))?.name || 'Company'}</span>
                  <span className="text-muted-foreground">(from current view)</span>
                </div>
              ) : (
                <select
                  className="h-12 w-full rounded-xl border border-brand/60 bg-background px-4 text-sm text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35 dark:[color-scheme:dark]"
                  value={createCompanyId}
                  onChange={(e) => { setCreateCompanyId(e.target.value); setCreateManagerId('') }}
                  required
                >
                  <option value="">Select company</option>
                  {companies.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              )}
              {companies.length === 0 && !companyFilter && (
                <p className="mt-1.5 text-xs text-muted-foreground">
                  No companies yet. <button type="button" className="text-primary underline" onClick={() => { setCreateOpen(false); navigate('/admin/companies') }}>Create a company</button> first.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="create-branch-name" className="text-base font-semibold text-foreground">Branch Name <span className="text-brand">*</span></Label>
              <Input id="create-branch-name" value={createName} onChange={(e) => setCreateName(e.target.value)} placeholder="e.g. Davao Branch" className="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35" required />
            </div>
            <div className="space-y-2">
              <Label htmlFor="create-branch-address" className="flex items-center gap-2 text-base font-semibold text-foreground">
                <MapPin className="size-4 text-foreground" />
                Address (optional)
              </Label>
              <Input id="create-branch-address" value={createAddress} onChange={(e) => setCreateAddress(e.target.value)} placeholder="Full branch address" className="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35" />
            </div>
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Branch Manager (optional)</Label>
              <div>
                <BranchManagerPicker
                  value={createManagerId}
                  onChange={setCreateManagerId}
                  employees={allEmployees}
                  branches={branches}
                  companies={companies}
                  companyId={createCompanyId}
                  excludeBranchId={null}
                  disabled={createSubmitting}
                  triggerClassName="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35"
                />
              </div>
            </div>
            </div>
            <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5 @md:px-8">
              <Button type="button" variant="outline" onClick={() => setCreateOpen(false)} className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted">
                Cancel
              </Button>
              <Button type="submit" disabled={createSubmitting} className="h-11 min-w-[160px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong">
                {createSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Create Branch'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit Branch */}
      <Dialog open={editOpen} onOpenChange={(open) => { setEditOpen(open); if (!open) setEditBranch(null) }}>
        <DialogContent
          showCloseButton
          className="max-w-[min(100vw-1.5rem,42rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="branch-edit-desc"
        >
          <form onSubmit={handleEdit} className="flex min-h-0 flex-1 flex-col">
            <div className="border-b border-border/80 px-6 pb-5 pt-7 pr-16 @md:px-8">
              <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
                <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-brand/20 bg-brand/10 text-brand">
                  {departmentLogoUrl(editBranch) ? (
                    <img src={departmentLogoUrl(editBranch)} alt="" className="size-full object-cover" />
                  ) : (
                    <MapPin className="size-7" />
                  )}
                </div>
                <div className="min-w-0 pt-1">
                  <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                    Edit Branch
                  </DialogTitle>
                  <p id="branch-edit-desc" className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                    {editBranch ? `Update ${editBranch.name} branch details and manager assignment.` : 'Update branch details and manager assignment.'}
                  </p>
                </div>
              </DialogHeader>
            </div>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6 @md:px-8">
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Company <span className="text-brand">*</span></Label>
              <select
                className="h-12 w-full rounded-xl border border-brand/60 bg-background px-4 text-sm text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35 dark:[color-scheme:dark]"
                value={editCompanyId}
                onChange={(e) => { setEditCompanyId(e.target.value); setEditManagerId('') }}
              >
                <option value="">Select company</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Branch Name <span className="text-brand">*</span></Label>
              <Input value={editName} onChange={(e) => setEditName(e.target.value)} className="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35" required />
            </div>
            <div className="space-y-2">
              <Label className="flex items-center gap-2 text-base font-semibold text-foreground">
                <MapPin className="size-4 text-foreground" />
                Address (optional)
              </Label>
              <Input value={editAddress} onChange={(e) => setEditAddress(e.target.value)} className="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35" placeholder="Full branch address" />
            </div>
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Branch Manager (optional)</Label>
              <div>
                <BranchManagerPicker
                  value={editManagerId}
                  onChange={setEditManagerId}
                  employees={allEmployees}
                  branches={branches}
                  companies={companies}
                  companyId={editCompanyId}
                  excludeBranchId={editBranch?.id}
                  disabled={editSubmitting}
                  triggerClassName="h-12 rounded-xl border-border/80 bg-background px-4 text-sm shadow-sm dark:bg-input/35"
                />
              </div>
            </div>
            {editBranch?.id ? (
              <LeadershipPositionsSection
                legacyType="branch"
                legacyId={editBranch.id}
                employeeOptions={allEmployees}
                canManage
              />
            ) : null}
            </div>
            <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5 @md:px-8">
              <Button type="button" variant="outline" onClick={() => setEditOpen(false)} className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted">
                Cancel
              </Button>
              <Button type="submit" disabled={editSubmitting} className="h-11 min-w-[160px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong">
                {editSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete */}
      <Dialog open={!!deleteConfirm} onOpenChange={(open) => !open && setDeleteConfirm(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="branch-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete Branch</DialogTitle>
              <p id="branch-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Delete &quot;{deleteConfirm?.name}&quot;? Deletion will fail if the branch has departments - remove them first.
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button type="button" variant="outline" onClick={() => setDeleteConfirm(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
