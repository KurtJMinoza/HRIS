import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Plus, MapPin, Loader2, MoreVertical, Pencil, Trash2, Building2, Layers, Users, ExternalLink, ChevronRight, Search, ChevronDown } from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import { FIELD_SELECT_CLASS_H8, FIELD_SELECT_CLASS_H10 } from '@/lib/fieldClasses'
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
  ADMIN_FORM_DIALOG_MAX_W_XL,
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
    if (String(b.id) === String(excludeBranchId)) continue // editing this branch — manager can stay
    map.set(String(b.branch_manager_id), {
      companyName: b.company_name || '',
      branchName: b.name || '',
    })
  }
  return map
}

/** Searchable Branch Manager picker — QA spec: search, avatars, position, assignment status. */
function BranchManagerPicker({ value, onChange, employees, branches, companies, companyId, excludeBranchId, disabled }) {
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
          className="flex h-10 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm text-left transition-colors hover:bg-muted/50 disabled:opacity-50 disabled:pointer-events-none dark:border-white/10 dark:bg-slate-900/50 dark:hover:bg-slate-800/60"
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
              placeholder="Search by name, employee ID, position…"
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
              <p className="text-[11px] text-muted-foreground/70">Branch Manager must be from the same company</p>
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
                // Cross-company: employee has a company_id that doesn't match the target branch's company
                const inDifferentCompany = !companyHeadOf && !branchAssignment
                  && emp.company_id && companyId && Number(emp.company_id) !== Number(companyId)
                const isDisabled = !!branchAssignment || !!companyHeadOf || !!inDifferentCompany
                const crossCompanyLabel = inDifferentCompany
                  ? [emp.company_name, emp.branch_name, emp.department].filter(Boolean).join(' → ') || 'Another company'
                  : null
                return (
                  <button
                    key={emp.id}
                    type="button"
                    disabled={isDisabled}
                    onClick={() => { if (!isDisabled) { onChange(String(emp.id)); setOpen(false) } }}
                    title={
                      companyHeadOf
                        ? `Company Head of ${companyHeadOf}`
                        : branchAssignment
                        ? `Assigned to ${branchAssignment.companyName} → ${branchAssignment.branchName}`
                        : crossCompanyLabel
                        ? `Assigned to ${crossCompanyLabel}`
                        : undefined
                    }
                    className={`flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition-colors ${!isDisabled ? 'hover:bg-slate-100 dark:hover:bg-slate-800/80 cursor-pointer' : 'opacity-60 cursor-not-allowed'} ${value === String(emp.id) ? 'bg-slate-100 dark:bg-slate-800/60 dark:border-l-2 dark:border-l-teal-500' : ''} ${idx % 2 === 1 ? 'dark:bg-slate-900/30' : ''}`}
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
                        {emp.position || emp.department || '—'}
                      </p>
                      {companyHeadOf && (
                        <Badge variant="secondary" className="mt-1 h-5 text-[10px] bg-rose-500/15 text-rose-700 dark:bg-rose-400/20 dark:text-rose-300 border-0">
                          Company Head — {companyHeadOf}
                        </Badge>
                      )}
                      {!companyHeadOf && branchAssignment && (
                        <Badge variant="secondary" className="mt-1 h-5 text-[10px] bg-amber-500/20 text-amber-700 dark:bg-amber-400/20 dark:text-amber-300 border-0">
                          Branch Manager — {branchAssignment.companyName} › {branchAssignment.branchName}
                        </Badge>
                      )}
                      {crossCompanyLabel && (
                        <Badge variant="secondary" className="mt-1 h-5 text-[10px] bg-rose-500/15 text-rose-700 dark:bg-rose-400/20 dark:text-rose-300 border-0">
                          🔴 Assigned: {crossCompanyLabel}
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
      const params = { per_page: 100 }
      if (companyId) params.assignable_to_company_id = companyId
      const data = await getEmployees(params)
      setAllEmployees(data.employees || [])
    } catch {
      setAllEmployees([])
    }
  }, [])

  // Companies list (for filter dropdown) — load once when ready
  useEffect(() => {
    void fetchCompanies()
  }, [fetchCompanies])

  // Branches — refetch whenever company filter changes (includes initial mount)
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
  const useCardLayout = !loading && branches.length > 0 && branches.length < 10

  return (
    <div className="space-y-6 p-4 @md:p-6">
      {/* Breadcrumb — always visible, reinforces hierarchy */}
      <nav className="flex items-center gap-2 text-sm font-medium" aria-label="Breadcrumb">
        <button type="button" onClick={() => navigate('/admin/companies')} className="text-muted-foreground hover:text-foreground transition-colors">Companies</button>
        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        {activeCompany ? (
          <>
            <button type="button" onClick={() => navigate('/admin/companies')} className="text-muted-foreground hover:text-foreground transition-colors truncate max-w-[140px]">{activeCompany.name}</button>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            <span className="font-bold text-base text-foreground">Branches</span>
          </>
        ) : (
          <span className="font-bold text-base text-foreground">Branches</span>
        )}
      </nav>

      <Card className="border-0 dark:border dark:bg-slate-900/50 dark:border-slate-700/50 dark:shadow-[0_4px_14px_rgba(0,0,0,0.25)]">
        <CardHeader className="space-y-1">
          <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
            <div>
              <CardTitle className="flex items-center gap-2 text-xl">
                <MapPin className="size-5" />
                Branches
                {activeCompany && (
                  <Badge variant="secondary" className="ml-1 text-xs dark:bg-slate-700/50 dark:border-slate-600 dark:text-slate-200">
                    {activeCompany.name}
                  </Badge>
                )}
              </CardTitle>
              <CardDescription>
                {activeCompany
                  ? `Showing branches under ${activeCompany.name}`
                  : 'Branches represent physical or operational locations of your company.'}
              </CardDescription>
            </div>
            <div className="flex items-center gap-2">
              {companyFilter && (
                <Button variant="outline" size="sm" onClick={() => navigate('/admin/companies')}>
                  <Building2 className="size-3.5 mr-1.5" />View Company
                </Button>
              )}
              <Button className="bg-black hover:bg-black/85 text-white dark:bg-white dark:hover:bg-white/90 dark:text-black" onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAddress(''); setCreateManagerId('') }}>
                <Plus className="size-4" />
                Add Branch
              </Button>
            </div>
          </div>
          {/* Filter bar */}
          <div className="flex flex-wrap items-center gap-2 pt-1">
            <Label className="text-muted-foreground text-sm shrink-0">Filter:</Label>
            <select
              className={cn('min-w-[180px]', FIELD_SELECT_CLASS_H8)}
              value={companyFilter}
              onChange={(e) => setCompanyFilter(e.target.value)}
            >
              <option value="">All companies</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            {companyFilter && (
              <Button variant="ghost" size="sm" className="h-8 px-2 text-xs" onClick={() => setCompanyFilter('')}>
                Clear filter
              </Button>
            )}
          </div>
        </CardHeader>
        <CardContent>
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
            <div className="flex flex-col items-center justify-center py-20 px-8 text-center rounded-xl border-2 border-dashed border-border/60 dark:border-slate-700/50">
              <div className="mb-5 flex size-20 items-center justify-center rounded-2xl bg-muted/80 dark:bg-slate-800/60">
                <MapPin className="size-10 text-muted-foreground" />
              </div>
              <h3 className="text-xl font-bold text-foreground">No branches yet</h3>
              <p className="mt-2 max-w-md text-sm text-muted-foreground leading-relaxed">
                {activeCompany
                  ? `Start organizing ${activeCompany.name} by adding a branch. Branches group departments and employees by location.`
                  : 'Add a branch to get started. Select a company above to filter, or add a branch for any company.'}
              </p>
              <Button className="mt-6 bg-black hover:bg-black/85 text-white dark:bg-white dark:hover:bg-white/90 dark:text-black shadow-md hover:shadow-lg transition-shadow" onClick={() => { setCreateOpen(true); setCreateName(''); setCreateCompanyId(companyFilter || ''); setCreateAddress(''); setCreateManagerId('') }}>
                <Plus className="size-4" />
                Add Branch
              </Button>
            </div>
          ) : useCardLayout ? (
            <div className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
              {branches.map((branch) => {
                return (
                  <div
                    key={branch.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => navigate(`/admin/departments?branch_id=${branch.id}`)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault()
                        navigate(`/admin/departments?branch_id=${branch.id}`)
                      }
                    }}
                    className="group cursor-pointer rounded-xl border-2 border-border/60 dark:border-slate-600/50 bg-card dark:bg-slate-800 dark:shadow-[0_4px_14px_rgba(0,0,0,0.25)] p-5 transition-all duration-200 hover:scale-[1.02] hover:shadow-xl hover:border-primary/30 hover:-translate-y-0.5 active:scale-[0.99] dark:hover:shadow-[0_8px_30px_rgba(0,0,0,0.4)] dark:hover:border-slate-500"
                  >
                    <div className="flex gap-4">
                      <div className="shrink-0 flex size-14 items-center justify-center overflow-hidden rounded-xl border border-border/60 bg-muted/40 dark:bg-slate-700/50 shadow-sm">
                        {departmentLogoUrl(branch) ? (
                          <img src={departmentLogoUrl(branch)} alt="" className="size-full object-cover" />
                        ) : (
                          <Building2 className="size-6 text-muted-foreground" />
                        )}
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-lg font-bold text-foreground truncate group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">{branch.name}</h3>
                        {branch.company_name && (
                          <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); navigate('/admin/companies') }}
                            className="mt-0.5 flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                          >
                            <Building2 className="size-3.5 shrink-0" />
                            <span>{branch.company_name} (Company)</span>
                          </button>
                        )}
                        <div className="mt-2.5 flex items-center gap-2">
                          {branch.branch_manager_name ? (
                            <div className="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 dark:bg-teal-950/50 dark:border-teal-700/40 px-2.5 py-1 w-fit">
                              <span className="text-[10px] font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">Manager</span>
                              <Avatar className="size-6 shrink-0">
                                <AvatarImage src={profileImageUrl(branch.branch_manager_profile_image)} />
                                <AvatarFallback className="text-[9px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400/90 dark:text-teal-950">{initials(branch.branch_manager_name)}</AvatarFallback>
                              </Avatar>
                              <span className="truncate text-sm font-medium text-foreground max-w-[140px]">{branch.branch_manager_name}</span>
                            </div>
                          ) : (
                            <span className="rounded-lg border border-amber-200 bg-amber-50/80 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300">No manager assigned</span>
                          )}
                        </div>
                        <div className="mt-3 pt-3 border-t border-border/40 flex flex-wrap items-center gap-2" onClick={(e) => e.stopPropagation()}>
                          <button
                            type="button"
                            onClick={() => navigate(`/admin/departments?branch_id=${branch.id}`)}
                            className="inline-flex items-center gap-1 rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-medium text-cyan-700 transition-colors hover:bg-cyan-100 dark:bg-cyan-950/50 dark:text-cyan-400 dark:border-cyan-700/30 dark:hover:bg-cyan-950/60 cursor-pointer"
                            title="View departments"
                          >
                            <Layers className="size-3 shrink-0" />
                            {branch.departments_count ?? 0} Departments
                          </button>
                          <span
                            className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 dark:border-emerald-700/30"
                            title={`${branch.employees_count ?? 0} employee(s) in this branch`}
                          >
                            <Users className="size-3 shrink-0" />
                            {branch.employees_count ?? 0} Employees
                          </span>
                          <div className="ml-auto">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                                <Button variant="ghost" size="icon" className="size-8 rounded-full bg-muted/70 dark:bg-slate-700 dark:hover:bg-slate-600 transition-all hover:scale-105 opacity-90 hover:opacity-100 group-hover:opacity-100" aria-label="Branch actions">
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
          ) : (
            <div className="overflow-x-auto rounded-lg border border-border/50 dark:border-white/10 dark:shadow-[0_4px_14px_rgba(0,0,0,0.15)]">
              <table className="w-full min-w-[700px]">
                <thead className="bg-muted/40 dark:bg-slate-800/80">
                  <tr>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Branch</th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Company</th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Manager</th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Address</th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Depts</th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Employees</th>
                    <th className="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground dark:text-slate-300">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/30 dark:divide-white/5">
                  {branches.map((branch, idx) => {
                    return (
                      <tr key={branch.id} className={['group transition-colors hover:bg-muted/40 dark:hover:bg-slate-700/60', idx % 2 === 1 ? 'dark:bg-slate-800/40' : ''].join(' ')}>
                        <td className="px-5 py-4 align-middle">
                          <div className="flex items-center gap-2">
                            <div className="shrink-0 flex size-8 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40 dark:bg-slate-700/50">
                              {departmentLogoUrl(branch) ? (
                                <img src={departmentLogoUrl(branch)} alt="" className="size-full object-cover" />
                              ) : (
                                <Building2 className="size-4 text-muted-foreground" />
                              )}
                            </div>
                            <p className="font-semibold text-foreground">{branch.name}</p>
                          </div>
                        </td>
                        <td className="px-5 py-4 align-middle">
                          {branch.company_name ? (
                            <button
                              type="button"
                              onClick={() => navigate('/admin/companies')}
                              className="flex items-center gap-1.5 text-sm text-primary hover:underline"
                            >
                              <Building2 className="size-3.5 shrink-0 text-muted-foreground" />
                              {branch.company_name}
                            </button>
                          ) : <span className="text-sm text-muted-foreground">—</span>}
                        </td>
                        <td className="px-5 py-4 align-middle">
                          {branch.branch_manager_name ? (
                            <div className="flex items-center gap-2">
                              <Avatar className="size-7 shrink-0">
                                <AvatarImage src={profileImageUrl(branch.branch_manager_profile_image)} />
                                <AvatarFallback className="text-[10px] font-bold bg-teal-500/20 text-teal-700 dark:bg-teal-400 dark:text-teal-950">{initials(branch.branch_manager_name)}</AvatarFallback>
                              </Avatar>
                              <span className="text-sm text-foreground truncate max-w-[110px]">{branch.branch_manager_name}</span>
                            </div>
                          ) : <span className="rounded-full border border-slate-300/60 bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-400">Not assigned</span>}
                        </td>
                        <td className="px-5 py-4 align-middle">
                          {branch.address ? (
                            <span className="flex items-center gap-1 text-sm text-muted-foreground max-w-[160px] truncate" title={branch.address}>
                              <MapPin className="size-3 shrink-0" />{branch.address}
                            </span>
                          ) : <span className="text-sm text-muted-foreground">—</span>}
                        </td>
                        <td className="px-5 py-4 align-middle">
                          <button
                            type="button"
                            onClick={() => navigate(`/admin/departments?branch_id=${branch.id}`)}
                            className="inline-flex items-center gap-1 rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-0.5 text-xs font-semibold text-cyan-700 transition-colors hover:bg-cyan-100 dark:bg-cyan-950/50 dark:text-cyan-400 dark:border-cyan-700/30 dark:hover:bg-cyan-950/60 cursor-pointer"
                            title={`${branch.departments_count ?? 0} department(s) — one functional unit each. Click to view.`}
                          >
                            <Layers className="size-3" />
                            {branch.departments_count ?? 0}
                          </button>
                        </td>
                        <td className="px-5 py-4 align-middle">
                          <span
                            className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 dark:border-emerald-700/30"
                            title={`${branch.employees_count ?? 0} employee(s) in this branch`}
                          >
                            <Users className="size-3" />
                            {branch.employees_count ?? 0}
                          </span>
                        </td>
                        <td className="px-5 py-4 text-right">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="icon" className="size-8 rounded-md bg-muted/60 dark:bg-slate-700 dark:hover:bg-slate-600 opacity-80 group-hover:opacity-100 transition-opacity">
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
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* ── Create Branch ── */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="branch-create-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Create Branch</DialogTitle>
              <p id="branch-create-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Branches represent physical or operational locations of your company. Add departments after creation.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleCreate} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div>
              <Label>Company *</Label>
              {companyFilter ? (
                <div className="mt-1 flex h-10 w-full items-center gap-2 rounded-md border border-input bg-muted/50 px-3 py-2 text-sm">
                  <Building2 className="size-4 shrink-0 text-muted-foreground" />
                  <span className="font-medium">{companies.find((c) => String(c.id) === String(companyFilter))?.name || 'Company'}</span>
                  <span className="text-muted-foreground">(from current view)</span>
                </div>
              ) : (
                <select
                  className={cn('mt-1', FIELD_SELECT_CLASS_H10)}
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
            <div>
              <Label htmlFor="create-branch-name">Branch Name *</Label>
              <Input id="create-branch-name" value={createName} onChange={(e) => setCreateName(e.target.value)} placeholder="e.g. Davao Branch" className="mt-1" required />
            </div>
            <div>
              <Label htmlFor="create-branch-address" className="flex items-center gap-1.5">
                <MapPin className="size-3.5 text-muted-foreground" />
                Address (optional)
              </Label>
              <Input id="create-branch-address" value={createAddress} onChange={(e) => setCreateAddress(e.target.value)} placeholder="Full branch address" className="mt-1" />
            </div>
            <div>
              <Label>Branch Manager (optional)</Label>
              <div className="mt-1">
                <BranchManagerPicker
                  value={createManagerId}
                  onChange={setCreateManagerId}
                  employees={allEmployees}
                  branches={branches}
                  companies={companies}
                  companyId={createCompanyId}
                  excludeBranchId={null}
                  disabled={createSubmitting}
                />
              </div>
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={createSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {createSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Create Branch'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* ── Edit Branch ── */}
      <Dialog open={editOpen} onOpenChange={(open) => { setEditOpen(open); if (!open) setEditBranch(null) }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_XL)}
          aria-describedby="branch-edit-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <div className="flex items-center gap-3">
                <div className="shrink-0 flex size-12 items-center justify-center overflow-hidden rounded-xl border border-border/60 bg-muted/40 dark:bg-slate-700/50">
                  {departmentLogoUrl(editBranch) ? (
                    <img src={departmentLogoUrl(editBranch)} alt="" className="size-full object-cover" />
                  ) : (
                    <MapPin className="size-6 text-muted-foreground" />
                  )}
                </div>
                <div className="min-w-0">
                  <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Edit Branch</DialogTitle>
                  <p id="branch-edit-desc" className={cn(ADMIN_FORM_DIALOG_DESC_CLASS, 'mt-0.5')}>
                    {editBranch ? `${companies.find((c) => String(c.id) === String(editBranch.company_id))?.name || '—'} → ${editBranch.name}` : ''}
                  </p>
                </div>
              </div>
            </DialogHeader>
          </div>
          <form onSubmit={handleEdit} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div>
              <Label>Company *</Label>
              <select
                className={cn('mt-1', FIELD_SELECT_CLASS_H10)}
                value={editCompanyId}
                onChange={(e) => { setEditCompanyId(e.target.value); setEditManagerId('') }}
              >
                <option value="">Select company</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div>
              <Label>Branch Name *</Label>
              <Input value={editName} onChange={(e) => setEditName(e.target.value)} className="mt-1" required />
            </div>
            <div>
              <Label className="flex items-center gap-1.5">
                <MapPin className="size-3.5 text-muted-foreground" />
                Address (optional)
              </Label>
              <Input value={editAddress} onChange={(e) => setEditAddress(e.target.value)} className="mt-1" placeholder="Full branch address" />
            </div>
            <div>
              <Label>Branch Manager (optional)</Label>
              <div className="mt-1">
                <BranchManagerPicker
                  value={editManagerId}
                  onChange={setEditManagerId}
                  employees={allEmployees}
                  branches={branches}
                  companies={companies}
                  companyId={editCompanyId}
                  excludeBranchId={editBranch?.id}
                  disabled={editSubmitting}
                />
              </div>
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={editSubmitting} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {editSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* ── Delete ── */}
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
                Delete &quot;{deleteConfirm?.name}&quot;? Deletion will fail if the branch has departments — remove them first.
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
