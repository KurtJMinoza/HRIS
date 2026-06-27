import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Plus, MapPin, Loader2, MoreVertical, Pencil, Trash2, Building2, Layers, Users, ExternalLink, ChevronRight, ChevronLeft, Search, ChevronDown, Network } from 'lucide-react'
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
import { getAreas, getBranches, getCompanies, getEmployees, createBranch, updateBranch, deleteBranch, profileImageUrl, departmentLogoUrl } from '@/api'
import { useHeadAssignmentEmployeeSearch } from '@/hooks/useHeadAssignmentEmployeeSearch'
import {
  employeeDisplayName,
  headAssignmentPrimaryLine,
  headAssignmentSecondaryLine,
  normalizeLeaderUserId,
} from '@/lib/employeeSearch'
import { useToast } from '@/components/ui/use-toast'
import { useDismissOnRouteChange } from '@/hooks/useDismissOnRouteChange'
import { useOrgModuleLoad } from '@/hooks/useOrgModuleLoad'
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

/** Searchable Branch Manager picker - cross-company active employee search. */
function BranchManagerPicker({ value, onChange, employees, branches, companies, companyId, excludeBranchId, disabled, triggerClassName }) {
  const [open, setOpen] = useState(false)
  const branchManagerMap = useMemo(() => buildBranchManagerMap(branches, excludeBranchId), [branches, excludeBranchId])
  /** Map: userId -> companyName for employees who are company heads */
  const companyHeadMap = useMemo(() => {
    const map = new Map()
    for (const c of companies || []) {
      if (c.company_head_id) map.set(String(c.company_head_id), c.name || 'a company')
    }
    return map
  }, [companies])

  const selectedFromRoster = useMemo(
    () => (employees || []).find((e) => String(e.id) === String(value)) || null,
    [employees, value],
  )

  const searchFilters = useMemo(() => ({ include_cross_company: true, active_only: true }), [])

  const {
    query: search,
    setQuery: setSearch,
    results: filtered,
    loading: searchLoading,
    reset,
  } = useHeadAssignmentEmployeeSearch({
    enabled: open,
    searchFilters,
    selectedEmployee: selectedFromRoster,
  })

  const selected = useMemo(() => {
    const fromResults = filtered.find((e) => String(e.id ?? e.employee_id) === String(value))
    return fromResults || selectedFromRoster
  }, [filtered, selectedFromRoster, value])

  const emptySearch = !searchLoading && filtered.length === 0

  return (
    <Popover open={open} onOpenChange={(o) => { setOpen(o); if (!o) reset() }}>
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
                <AvatarImage src={profileImageUrl(selected.profile_image_url || selected.profile_image)} />
                <AvatarFallback className="text-[10px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">
                  {selected.initials || initials(employeeDisplayName(selected))}
                </AvatarFallback>
              </Avatar>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-foreground">{headAssignmentPrimaryLine(selected)}</p>
                {headAssignmentSecondaryLine(selected) ? (
                  <p className="truncate text-[11px] text-muted-foreground">{headAssignmentSecondaryLine(selected)}</p>
                ) : null}
              </div>
            </div>
          ) : (
            <span className="text-muted-foreground">Search all active employees…</span>
          )}
          <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-[320px] p-0 dark:border-slate-700 dark:bg-slate-900 shadow-xl" align="start">
        <div className="border-b border-border/60 p-2 dark:border-slate-700">
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search all active employees…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-9 pl-8 dark:bg-slate-800/60 dark:border-slate-600"
              autoFocus
            />
          </div>
        </div>
        <div className="max-h-[260px] overflow-y-auto">
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
              {searchLoading ? (
                <div className="flex items-center justify-center gap-2 px-4 py-6 text-sm text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" />
                  Searching…
                </div>
              ) : null}
              {filtered.map((emp, idx) => {
                const empId = normalizeLeaderUserId(emp.id ?? emp.employee_id)
                const branchAssignment = branchManagerMap.get(empId)
                const companyHeadOf = companyHeadMap.get(empId)
                const isInactive = emp.is_active === false
                return (
                  <button
                    key={empId}
                    type="button"
                    disabled={isInactive}
                    onClick={() => { if (!isInactive) { onChange(empId); setOpen(false) } }}
                    title={
                      branchAssignment
                        ? `Also Branch Manager — ${branchAssignment.companyName} / ${branchAssignment.branchName}`
                        : companyHeadOf
                        ? `Also Company Head of ${companyHeadOf}`
                        : undefined
                    }
                    className={`flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition-colors ${!isInactive ? 'hover:bg-slate-100 dark:hover:bg-slate-800/80 cursor-pointer' : 'opacity-60 cursor-not-allowed'} ${value === empId ? 'bg-slate-100 dark:bg-slate-800/60 dark:border-l-2 dark:border-l-teal-500' : ''} ${idx % 2 === 1 ? 'dark:bg-slate-900/30' : ''}`}
                  >
                    <Avatar className="size-8 shrink-0">
                      <AvatarImage src={profileImageUrl(emp.profile_image_url || emp.profile_image)} />
                      <AvatarFallback className="text-[11px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">
                        {emp.initials || initials(employeeDisplayName(emp))}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-foreground">{headAssignmentPrimaryLine(emp)}</p>
                      <p className="truncate text-[11px] text-muted-foreground">
                        {headAssignmentSecondaryLine(emp) || '-'}
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
                  <p className="text-sm text-muted-foreground">
                    {search.trim() ? 'No employees found' : 'Type to search employees'}
                  </p>
                  <p className="text-[11px] text-muted-foreground/70">Cross-company leaders are allowed</p>
                </div>
              )}
          </>
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
  const [areas, setAreas] = useState([])
  const [allEmployees, setAllEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)

  // Read initial company filter from URL
  const [companyFilter, setCompanyFilter] = useState(() => searchParams.get('company_id') || '')

  const [createOpen, setCreateOpen] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createCompanyId, setCreateCompanyId] = useState('')
  const [createAreaId, setCreateAreaId] = useState('')
  const [createAddress, setCreateAddress] = useState('')
  const [createManagerId, setCreateManagerId] = useState('')
  const [createSubmitting, setCreateSubmitting] = useState(false)

  const [editOpen, setEditOpen] = useState(false)
  const [editBranch, setEditBranch] = useState(null)
  const [editName, setEditName] = useState('')
  const [editCompanyId, setEditCompanyId] = useState('')
  const [editAreaId, setEditAreaId] = useState('')
  const [editAddress, setEditAddress] = useState('')
  const [editManagerId, setEditManagerId] = useState('')
  const [editSubmitting, setEditSubmitting] = useState(false)

  const [deleteConfirm, setDeleteConfirm] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)
  const [employeesBranch, setEmployeesBranch] = useState(null)
  const [branchEmployees, setBranchEmployees] = useState([])
  const [branchEmployeesLoading, setBranchEmployeesLoading] = useState(false)
  const [branchEmployeesSearch, setBranchEmployeesSearch] = useState('')

  const dismissOverlays = useCallback(() => {
    setCreateOpen(false)
    setEditOpen(false)
    setDeleteConfirm(null)
    setEmployeesBranch(null)
  }, [])

  useDismissOnRouteChange(dismissOverlays)

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

  const fetchBranches = useCallback(async ({ signal, isStale } = {}) => {
    try {
      const params = companyFilter ? { company_id: companyFilter } : {}
      if (signal) params.signal = signal
      const data = await getBranches(params)
      if (isStale?.()) return
      setBranches(data.branches || [])
    } catch (e) {
      if (isStale?.() || e?.name === 'AbortError') return
      setBranches([])
      toast({ title: 'Failed to load branches', description: e?.message || 'Please try again.', variant: 'error' })
    } finally {
      if (!isStale?.()) setLoading(false)
    }
  }, [companyFilter, toast])

  const fetchAreas = useCallback(async (signal) => {
    try {
      const data = await getAreas(signal ? { signal } : {})
      setAreas(data.areas || [])
    } catch (e) {
      if (e?.name === 'AbortError') return
      setAreas([])
    }
  }, [])

  const fetchEmployees = useCallback(async () => {
    try {
      const params = { for_leadership_assignment: true, per_page: 'all' }
      const data = await getEmployees(params)
      setAllEmployees(data.employees || [])
    } catch {
      setAllEmployees([])
    }
  }, [])

  // Initial list: branches + company filter options in parallel (areas deferred until modal)
  useOrgModuleLoad(async ({ signal, isStale }) => {
    setLoading(true)
    try {
      const branchParams = companyFilter ? { company_id: companyFilter, signal } : { signal }
      const [branchRes, companyRes] = await Promise.all([
        getBranches(branchParams),
        getCompanies({ signal }),
      ])
      if (isStale()) return
      setBranches(branchRes.branches || [])
      setCompanies(companyRes.companies || [])
    } catch (e) {
      if (isStale() || e?.name === 'AbortError') return
      setBranches([])
      toast({ title: 'Failed to load branches', description: e?.message || 'Please try again.', variant: 'error' })
    } finally {
      if (!isStale()) setLoading(false)
    }
  }, [companyFilter, toast])

  useEffect(() => {
    if (!createOpen && !editOpen) return
    const ac = new AbortController()
    void fetchAreas(ac.signal)
    return () => ac.abort()
  }, [createOpen, editOpen, fetchAreas])

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
        area_id: createAreaId ? parseInt(createAreaId, 10) : null,
        address: createAddress.trim() || undefined,
        branch_manager_id: createManagerId ? parseInt(createManagerId, 10) : null,
      })
      setCreateOpen(false)
      setCreateName(''); setCreateCompanyId(''); setCreateAreaId(''); setCreateAddress(''); setCreateManagerId('')
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
    setEditAreaId(branch.area_id ? String(branch.area_id) : '')
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
        area_id: editAreaId ? parseInt(editAreaId, 10) : null,
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

  const openEmployeesModal = async (branch) => {
    setEmployeesBranch(branch)
    setBranchEmployees([])
    setBranchEmployeesSearch('')
    setBranchEmployeesLoading(true)
    try {
      const data = await getEmployees({
        branch_id: branch.id,
        per_page: 'all',
        fresh: true,
      })
      setBranchEmployees(data.employees || [])
    } catch (e) {
      setBranchEmployees([])
      toast({ title: 'Failed to load branch employees', description: e.message, variant: 'error' })
    } finally {
      setBranchEmployeesLoading(false)
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
  const areasForCreateCompany = useMemo(
    () => areas.filter((area) => !createCompanyId || String(area.company_id) === String(createCompanyId)),
    [areas, createCompanyId],
  )
  const areasForEditCompany = useMemo(
    () => areas.filter((area) => !editCompanyId || String(area.company_id) === String(editCompanyId)),
    [areas, editCompanyId],
  )
  const filteredBranchEmployees = useMemo(() => {
    const query = branchEmployeesSearch.trim().toLowerCase()
    if (!query) return branchEmployees
    return branchEmployees.filter((employee) =>
      `${employee.name || ''} ${employee.employee_code || ''} ${employee.email || ''} ${employee.position || ''} ${employee.department || ''}`
        .toLowerCase()
        .includes(query),
    )
  }, [branchEmployees, branchEmployeesSearch])

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
          onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAreaId(''); setCreateAddress(''); setCreateManagerId('') }}
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
              <Button className="mt-6 rounded-xl bg-brand font-bold text-brand-foreground shadow-md transition-shadow hover:bg-brand-strong hover:shadow-lg" onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAreaId(''); setCreateAddress(''); setCreateManagerId('') }}>
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
                        {branch.area_name ? (
                          <p className="mt-1 flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
                            <Network className="size-3.5 shrink-0" />
                            <span>{branch.area_name} (Area)</span>
                          </p>
                        ) : null}
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
                          <button
                            type="button"
                            onClick={() => openEmployeesModal(branch)}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-muted px-4 py-2 text-sm font-bold text-foreground transition-colors hover:bg-muted/80"
                            title={`${branch.employees_count ?? 0} employee(s) in this branch`}
                          >
                            <Users className="size-3 shrink-0" />
                            {branch.employees_count ?? 0} Employees
                          </button>
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
                                <DropdownMenuItem onClick={() => openEmployeesModal(branch)}>
                                  <Users className="size-4" /><span>View Employees</span>
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
          className="max-w-[min(100vw-1.5rem,82rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
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
                  onChange={(e) => { setCreateCompanyId(e.target.value); setCreateAreaId(''); setCreateManagerId('') }}
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
              <Label className="text-base font-semibold text-foreground">Area (optional)</Label>
              <select
                className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-sm text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 disabled:opacity-60 dark:bg-input/35 dark:[color-scheme:dark]"
                value={createAreaId}
                onChange={(e) => setCreateAreaId(e.target.value)}
                disabled={!createCompanyId}
              >
                <option value="">No area</option>
                {areasForCreateCompany.map((area) => (
                  <option key={area.id} value={area.id}>{area.area_name || area.name}</option>
                ))}
              </select>
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
          surfaceStyle={{
            width: 'min(calc(100vw - 1.5rem), 88rem)',
            maxWidth: 'none',
            height: 'min(92vh, 52rem)',
          }}
          className="max-h-[min(92vh,52rem)] min-h-0 min-w-0 max-w-none! rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="flex min-h-0 flex-1 flex-col !gap-0 !overflow-hidden !p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="branch-edit-desc"
        >
          <form onSubmit={handleEdit} className="flex min-h-0 flex-1 flex-col overflow-hidden text-foreground">
            <div className="shrink-0 border-b border-border/80 px-6 pb-5 pt-7 pr-16 @md:px-8">
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

            <div className="grid min-h-0 flex-1 grid-cols-1 divide-y divide-border/80 overflow-hidden lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:divide-x lg:divide-y-0">
              <div className="min-h-0 space-y-5 overflow-y-auto px-6 py-6 @md:px-8">
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Company <span className="text-brand">*</span></Label>
              <select
                className="h-12 w-full rounded-xl border border-brand/60 bg-background px-4 text-sm text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35 dark:[color-scheme:dark]"
                value={editCompanyId}
                onChange={(e) => { setEditCompanyId(e.target.value); setEditAreaId(''); setEditManagerId('') }}
              >
                <option value="">Select company</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="space-y-2">
              <Label className="text-base font-semibold text-foreground">Area (optional)</Label>
              <select
                className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-sm text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 disabled:opacity-60 dark:bg-input/35 dark:[color-scheme:dark]"
                value={editAreaId}
                onChange={(e) => setEditAreaId(e.target.value)}
                disabled={!editCompanyId}
              >
                <option value="">No area</option>
                {areasForEditCompany.map((area) => (
                  <option key={area.id} value={area.id}>{area.area_name || area.name}</option>
                ))}
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
              </div>
            {editBranch?.id ? (
              <div className="min-h-0 overflow-y-auto bg-muted/10 px-4 py-5 md:px-6">
                <LeadershipPositionsSection
                  legacyType="branch"
                  legacyId={editBranch.id}
                  employeeOptions={allEmployees}
                  canManage
                />
              </div>
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

      {/* View Branch Employees */}
      <Dialog open={!!employeesBranch} onOpenChange={(open) => { if (!open) setEmployeesBranch(null) }}>
        <DialogContent
          showCloseButton
          className="max-w-[min(100vw-1.5rem,48rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="flex max-h-[min(88vh,44rem)] min-h-0 flex-col !gap-0 !overflow-hidden !p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="branch-employees-desc"
        >
          <div className="shrink-0 border-b border-border/80 px-6 pb-5 pt-7 pr-16">
            <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
                <Users className="size-7" />
              </div>
              <div className="min-w-0 pt-1">
                <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                  Branch Employees
                </DialogTitle>
                <p id="branch-employees-desc" className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                  {employeesBranch ? `${employeesBranch.name} has ${branchEmployees.length} employee${branchEmployees.length === 1 ? '' : 's'} assigned to this branch.` : 'Employees assigned to this branch.'}
                </p>
              </div>
            </DialogHeader>
          </div>

          <div className="min-h-0 flex-1 overflow-hidden px-6 py-5">
            <div className="relative mb-4">
              <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={branchEmployeesSearch}
                onChange={(e) => setBranchEmployeesSearch(e.target.value)}
                placeholder="Search employees by name, ID, email, position..."
                className="h-11 rounded-xl border-border/80 bg-background pl-10 shadow-sm dark:bg-input/35"
              />
            </div>

            <div className="max-h-96 min-h-56 overflow-y-auto rounded-xl border border-border/70 bg-muted/10">
              {branchEmployeesLoading ? (
                <div className="flex h-56 items-center justify-center gap-2 text-sm font-medium text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" />
                  Loading employees...
                </div>
              ) : filteredBranchEmployees.length === 0 ? (
                <div className="flex h-56 flex-col items-center justify-center px-6 text-center">
                  <Users className="mb-3 size-9 text-muted-foreground" />
                  <p className="font-semibold text-foreground">
                    {branchEmployeesSearch.trim() ? 'No matching employees' : 'No employees assigned'}
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {branchEmployeesSearch.trim() ? 'Try another name, employee ID, or position.' : 'This branch has no employees yet.'}
                  </p>
                </div>
              ) : (
                <div className="divide-y divide-border/70">
                  {filteredBranchEmployees.map((employee) => (
                    <button
                      key={employee.id}
                      type="button"
                      onClick={() => navigate(`/admin/employees/${employee.id}`)}
                      className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50"
                    >
                      <Avatar className="size-10 shrink-0">
                        <AvatarImage src={profileImageUrl(employee.profile_image)} />
                        <AvatarFallback className="text-xs font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">
                          {initials(employee.name)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold text-foreground">
                          {employee.name}{employee.employee_code ? ` (${employee.employee_code})` : ''}
                        </p>
                        <p className="truncate text-sm text-muted-foreground">
                          {employee.position || employee.department || employee.email || '-'}
                        </p>
                      </div>
                      {employee.is_active === false ? (
                        <Badge variant="outline" className="shrink-0">Inactive</Badge>
                      ) : null}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>

          <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5">
            <Button type="button" variant="outline" onClick={() => setEmployeesBranch(null)} className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted">
              Close
            </Button>
          </DialogFooter>
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
