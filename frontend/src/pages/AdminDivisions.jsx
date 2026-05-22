import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Plus, Building2, Loader2, UserPlus, Users, Trash2, Eye, UserMinus, X, MoreVertical, Search, ChevronUp, ChevronDown, QrCode, GitBranch, Check, Network, FileText, Pencil, Layers } from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
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
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '@/components/ui/sheet'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { getDivisions, createDivision, updateDivision, deleteDivision, assignEmployeesToDivision, unassignEmployeesFromDivision, getEmployees, getDivisionEmployees, getBranches, getCompanies, getDepartments, departmentLogoUrl, profileImageUrl } from '@/api'
import { RoleBadge } from '@/components/RoleBadge'
import { useToast } from '@/components/ui/use-toast'
import { hasEmoji, hasFancyUnicode } from '@/validation'
import { cn } from '@/lib/utils'
import { isRosterStaffMember } from '@/lib/rosterStaff'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_LG,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'
import AssignEmployeesModal from '@/components/admin/AssignEmployeesModal'
import AssignOrgHeadModal from '@/components/admin/AssignOrgHeadModal'
import { buildOrgCurrentHead, employeeDisplayName } from '@/lib/employeeSearch'
import {
  ASSIGNMENT_MODE_TRANSFER_PRIMARY,
  buildOrgAssignCounts,
  buildOrgAssignRows,
  employeeAssignedToUnit,
  selectedCrossCompanyEmployees,
} from '@/lib/orgEmployeeAssignment'
import { patchOrgUnitEmployeeCount, resolveOrgUnitEmployeeCount } from '@/lib/orgUnitEmployeeSync'

function hasWorkingDays(schedule) {
  if (!schedule || typeof schedule !== 'object') return false
  return Object.values(schedule).some((v) => v && v.in && v.out)
}

function validateDivisionName(value) {
  const trimmed = value.trim()
  if (!trimmed) return 'Division name is required.'
  if (hasEmoji(trimmed)) return 'Emojis are not allowed in division names.'
  if (hasFancyUnicode(trimmed)) {
    return 'Please use standard letters/numbers only (no styled fonts or special symbols) in division names.'
  }
  if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) {
    return 'Division name may only contain letters, numbers, spaces, hyphens, and apostrophes.'
  }
  if (trimmed.length > 100) return 'Division name must be 100 characters or less.'
  return ''
}

/** Normalize user id for comparisons (API may return number or string). */
function sameUserId(a, b) {
  if (a == null || b == null) return false
  return String(a) === String(b)
}

function initials(name) {
  return (name || '?')
    .trim()
    .split(/\s+/)
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '?'
}

function relativeDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  if (Number.isNaN(d.getTime())) return ''
  const days = Math.floor((Date.now() - d.getTime()) / (1000 * 60 * 60 * 24))
  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days}d ago`
  if (days < 30) return `${Math.floor(days / 7)}w ago`
  if (days < 365) return `${Math.floor(days / 30)}mo ago`
  return `${Math.floor(days / 365)}y ago`
}

export default function AdminDivisions() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [divisions, setDivisions] = useState([])
  const [departments, setDepartments] = useState([])
  const [branches, setBranches] = useState([])
  const [companies, setCompanies] = useState([])
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [branchFilter, setBranchFilter] = useState(() => searchParams.get('branch_id') || '')
  const [companyFilter, setCompanyFilter] = useState(() => searchParams.get('company_id') || '')
  const [page, setPage] = useState(1)

  const [createOpen, setCreateOpen] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createCode, setCreateCode] = useState('')
  const [createCompanyId, setCreateCompanyId] = useState('')
  const [createBranchId, setCreateBranchId] = useState('')
  const [createSubmitting, setCreateSubmitting] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [editDivision, setEditDivision] = useState(null)
  const [editName, setEditName] = useState('')
  const [editCode, setEditCode] = useState('')
  const [editCompanyId, setEditCompanyId] = useState('')
  const [editBranchId, setEditBranchId] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [editSubmitting, setEditSubmitting] = useState(false)

  const [viewEmployeesOpen, setViewEmployeesOpen] = useState(false)
  const [viewEmployeesDivision, setViewEmployeesDivision] = useState(null)
  const [viewEmployeesList, setViewEmployeesList] = useState([])
  const [viewEmployeesLoading, setViewEmployeesLoading] = useState(false)
  const [viewEmployeesSelectedIds, setViewEmployeesSelectedIds] = useState([])
  const [unassigningId, setUnassigningId] = useState(null)
  const [unassignConfirm, setUnassignConfirm] = useState(null)

  const [headOpen, setHeadOpen] = useState(false)
  const [headDivision, setHeadDivision] = useState(null)
  const [headId, setHeadId] = useState('')
  const [headSubmitting, setHeadSubmitting] = useState(false)
  /** Roster candidates for Assign Head — loaded per division via API (avoid paginated global `employees`). */
  const [headModalEmployees, setHeadModalEmployees] = useState([])
  const [headModalLoading, setHeadModalLoading] = useState(false)
  const [headModalLoadError, setHeadModalLoadError] = useState(null)
  const headLoadSeqRef = useRef(0)
  const viewEmployeesLoadSeqRef = useRef(0)

  const [assignOpen, setAssignOpen] = useState(false)
  const [assignDivision, setAssignDivision] = useState(null)
  const [assignIds, setAssignIds] = useState([])
  const [assignModalEmployees, setAssignModalEmployees] = useState([])
  const [assignSubmitting, setAssignSubmitting] = useState(false)
  const [assignFilter, setAssignFilter] = useState('available')
  const [deleteConfirmDivision, setDeleteConfirmDivision] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [previewOpen, setPreviewOpen] = useState(false)
  const [previewDivision, setPreviewDivision] = useState(null)
  /** Members loaded from API when opening the preview sheet (same source as View Employees). */
  const [previewMembers, setPreviewMembers] = useState([])
  const [previewMembersLoading, setPreviewMembersLoading] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')

  const [hoveredDivision, setHoveredDivision] = useState(null)
  const [hoveredRowRect, setHoveredRowRect] = useState(null)
  const hoverCardLeaveTimerRef = useRef(null)
  const [assignSearchQuery, setAssignSearchQuery] = useState('')
  const [assignModalLoading, setAssignModalLoading] = useState(false)
  const [assignMode, setAssignMode] = useState(ASSIGNMENT_MODE_TRANSFER_PRIMARY)

  const [sortCol, setSortCol] = useState('name')
  const [sortDir, setSortDir] = useState('asc')
  const [filterNoHead, setFilterNoHead] = useState(false)
  const [filterHasQr, setFilterHasQr] = useState(false)
  const [createDescription, setCreateDescription] = useState('')
  const [createCompanyPickerOpen, setCreateCompanyPickerOpen] = useState(false)
  const [createBranchPickerOpen, setCreateBranchPickerOpen] = useState(false)
  const leadershipRef = useRef(null)

  const sortedCompaniesForPicker = useMemo(
    () =>
      [...companies].sort((a, b) =>
        (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' }),
      ),
    [companies],
  )

  const sortedBranchesForPicker = useMemo(
    () =>
      [...branches].sort((a, b) => {
        const ca = (a.company_name || '').localeCompare(b.company_name || '', undefined, { sensitivity: 'base' })
        if (ca !== 0) return ca
        return (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
      }),
    [branches]
  )

  const branchesForCreateCompany = useMemo(() => {
    if (!createCompanyId) return []
    return sortedBranchesForPicker.filter((b) => String(b.company_id) === String(createCompanyId))
  }, [sortedBranchesForPicker, createCompanyId])

  const selectedCreateCompany = useMemo(
    () => companies.find((c) => String(c.id) === String(createCompanyId)),
    [companies, createCompanyId],
  )

  const selectedCreateBranch = useMemo(
    () => branches.find((b) => String(b.id) === String(createBranchId)),
    [branches, createBranchId]
  )

  const editBranchesForCompany = useMemo(() => {
    if (!editCompanyId) return []
    return sortedBranchesForPicker.filter((b) => String(b.company_id) === String(editCompanyId))
  }, [sortedBranchesForPicker, editCompanyId])

  const fetchBranches = useCallback(async () => {
    try {
      const data = await getBranches()
      setBranches(data.branches || [])
    } catch {
      setBranches([])
    }
  }, [])

  const fetchCompanies = useCallback(async () => {
    try {
      const data = await getCompanies()
      setCompanies(data.companies || [])
    } catch {
      setCompanies([])
    }
  }, [])

  const fetchDepartmentsList = useCallback(async () => {
    try {
      const data = await getDepartments()
      setDepartments(data.departments || [])
    } catch {
      setDepartments([])
    }
  }, [])

  const fetchDivisions = useCallback(async () => {
    setError(null)
    try {
      const params = { fresh: true }
      if (companyFilter) params.company_id = companyFilter
      if (branchFilter) params.branch_id = branchFilter
      const data = await getDivisions(params)
      setDivisions(data.divisions || [])
    } catch (e) {
      setError(e.message)
      setDivisions([])
    } finally {
      setLoading(false)
    }
  }, [branchFilter, companyFilter])

  const syncDivisionEmployeeCount = useCallback((divisionId, count) => {
    setDivisions((prev) => patchOrgUnitEmployeeCount(prev, divisionId, count))
  }, [])

  const fetchEmployees = useCallback(async () => {
    try {
      const data = await getEmployees()
      setEmployees(data.employees || [])
    } catch {
      setEmployees([])
    }
  }, [])

  const openDivisionPreview = useCallback(async (division) => {
    setPreviewDivision(division)
    setPreviewOpen(true)
    setPreviewMembers([])
    setPreviewMembersLoading(true)
    try {
      const data = await getDivisionEmployees(division.id)
      const list = data.employees || []
      setPreviewMembers(list)
      syncDivisionEmployeeCount(division.id, resolveOrgUnitEmployeeCount(data, list))
    } catch {
      setPreviewMembers([])
    } finally {
      setPreviewMembersLoading(false)
    }
  }, [syncDivisionEmployeeCount])

  // Run branches + divisions + companies + departments in parallel on mount; employees deferred until modal
  useEffect(() => {
    setLoading(true)
    Promise.all([fetchDivisions(), fetchBranches(), fetchCompanies(), fetchDepartmentsList()])
  }, []) // eslint-disable-line react-hooks/exhaustive-deps -- intentional one-time mount fetch

  // Re-fetch divisions when filters change (after initial mount)
  const _divisionsFirstRender = useState(true)
  useEffect(() => {
    if (_divisionsFirstRender[0]) { _divisionsFirstRender[0] = false; return }
    setLoading(true)
    fetchDivisions()
  }, [branchFilter, companyFilter]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const params = {}
    if (companyFilter) params.company_id = companyFilter
    if (branchFilter) params.branch_id = branchFilter
    setSearchParams(params, { replace: true })
  }, [branchFilter, companyFilter, setSearchParams])

  useEffect(() => {
    setPage(1)
  }, [branchFilter, companyFilter, searchQuery, filterNoHead, filterHasQr])

  // Employees are heavy — only fetch when assign/head modal opens, and only once
  useEffect(() => {
    if ((headOpen || assignOpen) && employees.length === 0) fetchEmployees()
  }, [headOpen, assignOpen]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleCreate = async (e) => {
    e.preventDefault()
    const nameError = validateDivisionName(createName)
    if (nameError) {
      setError(nameError)
      toast({ title: 'Invalid division name', description: nameError, variant: 'error' })
      return
    }
    if (!createCompanyId) {
      toast({ title: 'Please select a company', variant: 'error' })
      return
    }
    setCreateSubmitting(true)
    setError(null)
    try {
      const payload = {
        name: createName.trim(),
        company_id: parseInt(createCompanyId, 10),
        description: createDescription.trim() || undefined,
      }
      if (createCode.trim()) payload.code = createCode.trim()
      if (createBranchId) payload.branch_id = parseInt(createBranchId, 10)
      const data = await createDivision(payload)
      const savedName = createName.trim()
      if (data?.division?.id != null) {
        setDivisions((prev) => {
          const id = String(data.division.id)
          if (!prev.some((d) => String(d.id) === id)) {
            const next = [...prev, data.division]
            next.sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' }))
            return next
          }
          return prev.map((d) => (String(d.id) === id ? { ...d, ...data.division } : d))
        })
      }
      setCreateName('')
      setCreateCode('')
      setCreateCompanyId('')
      setCreateBranchId('')
      setCreateDescription('')
      setCreateOpen(false)
      await fetchDivisions()
      toast({ title: `Division '${savedName}' created`, description: 'Assign a head next to complete the setup.', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to create division', description: e.message, variant: 'error' })
    } finally {
      setCreateSubmitting(false)
    }
  }

  const openEditDialog = (division) => {
    setEditDivision(division)
    setEditName(division?.name || '')
    setEditCode(division?.code || '')
    setEditCompanyId(division?.company_id != null ? String(division.company_id) : '')
    setEditBranchId(division?.branch_id != null ? String(division.branch_id) : '')
    setEditDescription(division?.description || '')
    setEditOpen(true)
    setError(null)
  }

  const handleEdit = async (e) => {
    e.preventDefault()
    if (!editDivision) return
    const nameError = validateDivisionName(editName)
    if (nameError) {
      setError(nameError)
      toast({ title: 'Invalid division name', description: nameError, variant: 'error' })
      return
    }
    if (!editCompanyId) {
      toast({ title: 'Please select a company', variant: 'error' })
      return
    }
    setEditSubmitting(true)
    setError(null)
    try {
      if (leadershipRef.current?.isDirty?.()) {
        const leadershipSaved = await leadershipRef.current.save()
        if (!leadershipSaved) {
          return
        }
      }

      const payload = {
        name: editName.trim(),
        company_id: parseInt(editCompanyId, 10),
        branch_id: editBranchId ? parseInt(editBranchId, 10) : null,
        code: editCode.trim() || null,
        description: editDescription.trim() || null,
      }
      const data = await updateDivision(editDivision.id, payload)
      if (data?.division?.id != null) {
        setDivisions((prev) =>
          prev.map((d) => (sameUserId(d.id, data.division.id) ? { ...d, ...data.division } : d)),
        )
      }
      setEditOpen(false)
      setEditDivision(null)
      await fetchDivisions()
      toast({ title: `Division '${editName.trim()}' updated`, variant: 'success' })
    } catch (err) {
      setError(err.message)
      toast({ title: 'Failed to update division', description: err.message, variant: 'error' })
    } finally {
      setEditSubmitting(false)
    }
  }

  const openViewEmployees = async (division) => {
    viewEmployeesLoadSeqRef.current += 1
    const seq = viewEmployeesLoadSeqRef.current
    setViewEmployeesDivision(division)
    setViewEmployeesOpen(true)
    setViewEmployeesList([])
    setViewEmployeesLoading(true)
    setUnassigningId(null)
    try {
      const data = await getDivisionEmployees(division.id)
      if (seq !== viewEmployeesLoadSeqRef.current) return
      const list = data.employees || []
      const count = resolveOrgUnitEmployeeCount(data, list)
      setViewEmployeesList(list)
      syncDivisionEmployeeCount(division.id, count)
    } catch (e) {
      if (seq !== viewEmployeesLoadSeqRef.current) return
      setViewEmployeesList([])
      toast({
        title: 'Could not load division members',
        description: e?.message || 'Try again or refresh the page.',
        variant: 'error',
      })
    } finally {
      if (seq === viewEmployeesLoadSeqRef.current) {
        setViewEmployeesLoading(false)
      }
    }
  }

  const refreshViewEmployeesList = useCallback(async () => {
    if (!viewEmployeesDivision) return
    try {
      const data = await getDivisionEmployees(viewEmployeesDivision.id)
      const list = data.employees || []
      const count = resolveOrgUnitEmployeeCount(data, list)
      setViewEmployeesList(list)
      syncDivisionEmployeeCount(viewEmployeesDivision.id, count)
    } catch {
      setViewEmployeesList([])
    }
  }, [syncDivisionEmployeeCount, viewEmployeesDivision])

  const handleUnassignFromView = (emp) => {
    if (!viewEmployeesDivision) return
    setUnassignConfirm({ employee: emp, division: viewEmployeesDivision, bulkIds: null })
  }

  const handleUnassignBulkFromView = () => {
    if (!viewEmployeesDivision || viewEmployeesSelectedIds.length === 0) return
    setUnassignConfirm({ employee: null, division: viewEmployeesDivision, bulkIds: [...viewEmployeesSelectedIds] })
  }

  const handleUnassignConfirm = async () => {
    if (!unassignConfirm) return
    const { employee, division, bulkIds } = unassignConfirm
    const idsToUnassign = bulkIds?.length ? bulkIds : [employee.id]
    setUnassigningId(bulkIds?.length ? 'bulk' : employee.id)
    setError(null)
    try {
      const data = await unassignEmployeesFromDivision(division.id, idsToUnassign)
      setUnassignConfirm(null)
      setViewEmployeesSelectedIds((prev) =>
        prev.filter((id) => !idsToUnassign.some((u) => sameUserId(u, id)))
      )
      if (data?.division?.id != null) {
        setDivisions((prev) =>
          prev.map((d) => (sameUserId(d.id, data.division.id) ? { ...d, ...data.division } : d)),
        )
      }
      await refreshViewEmployeesList()
      await fetchDivisions()
      toast({
        title: idsToUnassign.length === 1
          ? `${employee?.name || 'Employee'} unassigned from ${division.name}`
          : `${idsToUnassign.length} employees unassigned from ${division.name}`,
        variant: 'success',
      })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to unassign', description: e.message, variant: 'error' })
    } finally {
      setUnassigningId(null)
    }
  }

  const toggleViewEmployeeSelection = (id) => {
    setViewEmployeesSelectedIds((prev) =>
      prev.some((x) => sameUserId(x, id)) ? prev.filter((x) => !sameUserId(x, id)) : [...prev, id]
    )
  }

  const toggleViewEmployeesSelectAll = () => {
    const listIds = viewEmployeesList.map((e) => e.id)
    const allSelected =
      listIds.length > 0 &&
      listIds.every((lid) => viewEmployeesSelectedIds.some((sid) => sameUserId(sid, lid)))
    if (allSelected) {
      setViewEmployeesSelectedIds([])
    } else {
      setViewEmployeesSelectedIds(listIds)
    }
  }

  const openAssignFromViewModal = () => {
    if (!viewEmployeesDivision) return
    setViewEmployeesOpen(false)
    openAssignDialog(viewEmployeesDivision)
  }

  const loadHeadCandidates = useCallback(async (division) => {
    if (division?.id == null) return
    headLoadSeqRef.current += 1
    const seq = headLoadSeqRef.current
    const resolvedDivisionId = division.id
    setHeadModalEmployees([])
    setHeadModalLoadError(null)
    setHeadModalLoading(true)
    try {
      const data = await getEmployees({
        for_leadership_assignment: true,
        per_page: 'all',
        fresh: true,
      })
      if (seq !== headLoadSeqRef.current) return
      let list = (data.employees || []).filter((e) => {
        if (!isRosterStaffMember(e)) return false
        const isCurrentHead =
          division.division_head_id != null && division.division_head_id !== '' && sameUserId(e.id, division.division_head_id)
        if (!isCurrentHead && e.is_active === false) return false
        return true
      })
      list.sort((a, b) =>
        employeeDisplayName(a).localeCompare(employeeDisplayName(b), undefined, { sensitivity: 'base' }),
      )
      setHeadModalEmployees(list)
    } catch (err) {
      if (seq !== headLoadSeqRef.current) return
      setHeadModalEmployees([])
      setHeadModalLoadError(err?.message || 'Could not load employees for this division.')
    } finally {
      if (seq === headLoadSeqRef.current) {
        setHeadModalLoading(false)
      }
    }
  }, [])

  const openHeadDialog = (division) => {
    if (division?.id == null) return
    setHeadDivision(division)
    setHeadId(division.division_head_id ? String(division.division_head_id) : '')
    setHeadModalEmployees([])
    setHeadModalLoadError(null)
    setHeadOpen(true)
    void loadHeadCandidates(division)
  }

  const divisionHeadRoleNotes = useMemo(() => {
    if (!headDivision) return new Map()
    const map = new Map()
    for (const div of divisions) {
      if (div.division_head_id && div.id !== headDivision.id) {
        map.set(String(div.division_head_id), `Division Head — ${div.name || 'Division'}`)
      }
    }
    for (const c of companies) {
      if (c.company_head_id) {
        map.set(String(c.company_head_id), `Company Head — ${c.name || 'Company'}`)
      }
    }
    for (const b of branches) {
      if (b.branch_manager_id) {
        map.set(String(b.branch_manager_id), `Branch Manager — ${b.name || 'Branch'}`)
      }
    }
    for (const d of departments) {
      if (d.department_head_id) {
        map.set(String(d.department_head_id), `Dept Head — ${d.name || 'Department'}`)
      }
    }
    return map
  }, [headDivision, divisions, companies, branches, departments])

  const handleAssignHead = async (e) => {
    e.preventDefault()
    if (!headDivision) return
    setHeadSubmitting(true)
    setError(null)
    try {
      const data = await updateDivision(headDivision.id, {
        division_head_id: headId ? parseInt(headId, 10) : null,
      })
      if (data?.division?.id != null) {
        setDivisions((prev) =>
          prev.map((d) => (sameUserId(d.id, data.division.id) ? { ...d, ...data.division } : d)),
        )
      }
      setHeadOpen(false)
      setHeadDivision(null)
      setHeadModalEmployees([])
      setHeadModalLoadError(null)
      await fetchDivisions()
      await fetchEmployees()
      toast({ title: 'Division head updated', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Cannot assign head', description: e.message, variant: 'error' })
    } finally {
      setHeadSubmitting(false)
    }
  }

  const openAssignDialog = async (division) => {
    setAssignDivision(division)
    setAssignOpen(true)
    setAssignFilter('available')
    setAssignSearchQuery('')
    setAssignMode(ASSIGNMENT_MODE_TRANSFER_PRIMARY)
    setAssignModalEmployees([])
    setAssignModalLoading(true)
    try {
      const params = { per_page: 'all', active_filter: 'active', for_organization_assignment: true, fresh: true }
      const data = await getEmployees(params)
      const list = data.employees || []
      setAssignModalEmployees(list)
      const inDivision = list
        .filter((emp) => employeeAssignedToUnit(emp, division, 'division_id'))
        .map((e) => e.id)
      setAssignIds(inDivision)
    } catch (e) {
      setAssignModalEmployees([])
      setAssignIds([])
      toast({
        title: 'Could not load employees',
        description: e?.message || 'Try again or refresh the page.',
        variant: 'error',
      })
    } finally {
      setAssignModalLoading(false)
    }
  }

  const handleAssignEmployees = async (e) => {
    e.preventDefault()
    if (!assignDivision) return
    setAssignSubmitting(true)
    setError(null)
    try {
      const data = await assignEmployeesToDivision(assignDivision.id, assignIds, { assignmentMode: assignMode })
      if (data?.division?.id != null) {
        setDivisions((prev) =>
          prev.map((d) => (sameUserId(d.id, data.division.id) ? { ...d, ...data.division } : d)),
        )
      }
      setAssignOpen(false)
      setAssignDivision(null)
      setAssignModalEmployees([])
      await fetchDivisions()
      await fetchEmployees()
      toast({ title: 'Employees assigned', description: assignDivision.name, variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to assign employees', description: e.message, variant: 'error' })
    } finally {
      setAssignSubmitting(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteConfirmDivision) return
    setDeleteSubmitting(true)
    setError(null)
    try {
      await deleteDivision(deleteConfirmDivision.id)
      setDeleteConfirmDivision(null)
      await fetchDivisions()
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to delete division', description: e.message, variant: 'error' })
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const toggleAssignId = (id) => {
    setAssignIds((prev) => (prev.some((x) => sameUserId(x, id)) ? prev.filter((x) => !sameUserId(x, id)) : [...prev, id]))
  }

  /** Always use the list loaded for this modal (may be empty after load); avoid mixing in stale global `employees`. */
  const assignList = assignOpen ? assignModalEmployees : employees

  /** Map: employeeId (string) -> company name for employees who are company heads */
  const companyHeadMap = useMemo(() => {
    const map = new Map()
    for (const c of companies) {
      if (c.company_head_id) map.set(String(c.company_head_id), c.name || 'a company')
    }
    return map
  }, [companies])

  /** Set of employee IDs who are Branch Managers (from branches.branch_manager_id) — fallback when API omits managed_branch_* */
  const branchManagerIds = useMemo(() => {
    const set = new Set()
    for (const b of branches || []) {
      if (b.branch_manager_id) set.add(String(b.branch_manager_id))
    }
    return set
  }, [branches])

  /** Company Heads and Branch Managers cannot be assigned to divisions — omit from pool entirely */
  const isExcludedFromAssignPool = useCallback(
    (emp) => {
      if (companyHeadMap.get(String(emp.id))) return true
      if (emp.managed_branch_id || emp.managed_branch_name || branchManagerIds.has(String(emp.id))) return true
      return false
    },
    [companyHeadMap, branchManagerIds]
  )

  const assignExcludedCount = useMemo(() => {
    return assignList.filter((e) => isRosterStaffMember(e)).filter((emp) => isExcludedFromAssignPool(emp)).length
  }, [assignList, isExcludedFromAssignPool])

  const assignTargetCompanyId = assignDivision?.company_id ?? null

  const assignRows = useMemo(() => {
    return buildOrgAssignRows({
      assignList,
      targetUnit: assignDivision,
      targetCompanyId: assignTargetCompanyId,
      memberIdField: 'division_id',
      assignSearchQuery,
      assignFilter,
      assignIds,
      isExcludedFromAssignPool,
      isRosterStaffMember,
    })
  }, [
    assignList,
    assignSearchQuery,
    assignDivision,
    assignTargetCompanyId,
    assignFilter,
    assignIds,
    isExcludedFromAssignPool,
  ])

  const assignSelectableIds = useMemo(
    () => assignRows.filter((row) => !row.checkboxDisabled).map((row) => row.emp.id),
    [assignRows]
  )

  const allSelectableChecked = useMemo(
    () =>
      assignSelectableIds.length > 0 &&
      assignSelectableIds.every((sid) => assignIds.some((aid) => sameUserId(aid, sid))),
    [assignSelectableIds, assignIds]
  )

  const selectedEmployeesPreview = useMemo(
    () =>
      assignList.filter((emp) => assignIds.some((id) => sameUserId(id, emp.id))),
    [assignList, assignIds]
  )

  const assignNewToDivisionCount = useMemo(() => {
    if (!assignDivision) return 0
    return assignIds.filter((id) => {
      const emp = assignList.find((e) => sameUserId(e.id, id))
      return emp && !employeeAssignedToUnit(emp, assignDivision, 'division_id')
    }).length
  }, [assignIds, assignList, assignDivision])

  const assignFooterStats = useMemo(() => {
    if (!assignDivision) return { current: 0, newlyAdded: 0, afterSave: 0 }
    const current = assignList.filter((e) => employeeAssignedToUnit(e, assignDivision, 'division_id')).length
    const newlyAdded = selectedEmployeesPreview.filter(
      (e) => !employeeAssignedToUnit(e, assignDivision, 'division_id')
    ).length
    return { current, newlyAdded, afterSave: current + newlyAdded }
  }, [assignDivision, assignList, selectedEmployeesPreview])

  const assignCounts = useMemo(() => {
    return buildOrgAssignCounts(
      assignList,
      assignDivision,
      'division_id',
      isExcludedFromAssignPool,
      isRosterStaffMember,
    )
  }, [assignList, assignDivision, isExcludedFromAssignPool])

  const crossCompanySelectedCount = useMemo(
    () => selectedCrossCompanyEmployees(selectedEmployeesPreview, assignTargetCompanyId).length,
    [selectedEmployeesPreview, assignTargetCompanyId],
  )

  const toggleSelectAllAssignable = () => {
    setAssignIds((prev) => {
      if (assignSelectableIds.length === 0) return prev
      if (allSelectableChecked) {
        return prev.filter((id) => !assignSelectableIds.some((sid) => sameUserId(id, sid)))
      }
      return Array.from(new Set([...prev, ...assignSelectableIds]))
    })
  }

  const filteredDivisions = useMemo(() => {
    let list = [...divisions]
    const q = searchQuery.trim().toLowerCase()
    if (q) {
      list = list.filter(
        (d) =>
          (d.name || '').toLowerCase().includes(q) ||
          (d.code || '').toLowerCase().includes(q) ||
          (d.division_head_name || '').toLowerCase().includes(q)
      )
    }
    if (filterNoHead) list = list.filter((d) => !d.division_head_id)
    if (filterHasQr) {
      list = list.filter((d) => employees.some((e) => sameUserId(e.division_id, d.id) && e.has_qr))
    }
    list.sort((a, b) => {
      if (sortCol === 'total') {
        const aT = a.assigned_employee_count ?? a.total_employees ?? 0
        const bT = b.assigned_employee_count ?? b.total_employees ?? 0
        return sortDir === 'asc' ? aT - bT : bT - aT
      }
      const an = (a.name || '').toLowerCase()
      const bn = (b.name || '').toLowerCase()
      return sortDir === 'asc' ? an.localeCompare(bn) : bn.localeCompare(an)
    })
    return list
  }, [divisions, searchQuery, filterNoHead, filterHasQr, employees, sortCol, sortDir])

  const pageSize = 10
  const totalFilteredDivisions = filteredDivisions.length
  const pageCount = Math.max(1, Math.ceil(totalFilteredDivisions / pageSize))
  const currentPage = Math.min(page, pageCount)
  const pagedDivisions = filteredDivisions.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const rangeStart = totalFilteredDivisions === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const rangeEnd = Math.min(currentPage * pageSize, totalFilteredDivisions)

  useEffect(() => {
    if (page > pageCount) setPage(pageCount)
  }, [page, pageCount])

  function toggleSort(col) {
    if (sortCol === col) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    else { setSortCol(col); setSortDir('asc') }
  }

  return (
    <div className="min-h-full bg-background px-4 py-5 text-foreground @md:px-6">
      <div className="mb-6 flex flex-col gap-4 @md:flex-row @md:items-start @md:justify-between">
        <div className="space-y-1.5">
          <h2 className="text-3xl font-extrabold tracking-normal text-foreground">Divisions</h2>
          <p className="max-w-3xl text-base leading-7 text-muted-foreground">
            Organize divisions under company and branch. Create divisions, assign heads, and assign employees.
          </p>
        </div>
        <Button
          onClick={() => {
            setCreateOpen(true)
            setCreateName('')
            setCreateCode('')
            setCreateCompanyId(companyFilter || '')
            setCreateBranchId(branchFilter || '')
            setCreateDescription('')
            setError(null)
          }}
          className="h-11 rounded-lg bg-brand px-5 text-sm font-bold text-brand-foreground shadow-sm shadow-brand/25 hover:bg-brand/90"
        >
          <Plus className="mr-2 size-4" />
          Create Division
        </Button>
      </div>

      {error && (
        <div className="mb-6 rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
          {error}
        </div>
      )}

      <Card className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-md shadow-slate-950/[0.04] dark:border-border/70 dark:shadow-black/25">
        <CardHeader className="border-b border-border/80 bg-card px-5 py-5 @lg:px-6">
          <div className="flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between">
            <div>
              <h3 className="text-xl font-extrabold tracking-normal text-foreground">Division Directory</h3>
              <p className="mt-1 text-sm text-muted-foreground">
                {filteredDivisions.length} of {divisions.length} division(s)
              </p>
            </div>
            <div className="relative w-full @lg:w-[320px]">
              <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="text"
                placeholder="Search divisions..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="h-11 rounded-xl border-border/80 bg-background pl-11 text-sm shadow-none dark:bg-input/30"
              />
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2.5 pt-3">
            <span className="text-sm text-muted-foreground">Company:</span>
            <select
              className={cn(
                'h-10 min-w-[210px] rounded-xl border border-border/80 bg-background px-3 text-sm font-semibold text-foreground shadow-none outline-none transition-colors focus:border-brand focus:ring-2 focus:ring-brand/20 dark:bg-input/30',
                'disabled:cursor-not-allowed disabled:opacity-60'
              )}
              value={companyFilter}
              onChange={(e) => { setCompanyFilter(e.target.value); setBranchFilter(''); setLoading(true); setPage(1) }}
            >
              <option value="">All companies</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            <span className="text-sm text-muted-foreground">Branch:</span>
            <select
              className={cn(
                'h-10 min-w-[210px] rounded-xl border border-border/80 bg-background px-3 text-sm font-semibold text-foreground shadow-none outline-none transition-colors focus:border-brand focus:ring-2 focus:ring-brand/20 dark:bg-input/30',
                'disabled:cursor-not-allowed disabled:opacity-60'
              )}
              value={branchFilter}
              onChange={(e) => { setBranchFilter(e.target.value); setLoading(true); setPage(1) }}
            >
              <option value="">All branches</option>
              {branches
                .filter((b) => !companyFilter || String(b.company_id) === String(companyFilter))
                .map((b) => (
                <option key={b.id} value={b.id}>{b.name}{b.company_name ? ` (${b.company_name})` : ''}</option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => { setFilterNoHead((v) => !v); setPage(1) }}
              className={[
                'inline-flex h-10 items-center gap-2 rounded-full border px-3.5 text-sm font-medium transition-colors',
                filterNoHead
                  ? 'border-amber-400/50 bg-amber-500/15 text-amber-600 dark:text-amber-400'
                  : 'border-border/80 bg-background text-foreground hover:border-brand/30 hover:bg-brand/5 dark:bg-input/30',
              ].join(' ')}
            >
              <UserPlus className="size-4" />
              No head assigned
              {filterNoHead && <X className="ml-0.5 size-4" />}
            </button>
            <button
              type="button"
              onClick={() => { setFilterHasQr((v) => !v); setPage(1) }}
              className={[
                'inline-flex h-10 items-center gap-2 rounded-full border px-3.5 text-sm font-medium transition-colors',
                filterHasQr
                  ? 'border-sky-400/50 bg-sky-500/15 text-sky-600 dark:text-sky-400'
                  : 'border-border/80 bg-background text-foreground hover:border-brand/30 hover:bg-brand/5 dark:bg-input/30',
              ].join(' ')}
            >
              <QrCode className="size-4" />
              Has QR
              {filterHasQr && <X className="ml-0.5 size-4" />}
            </button>
            {(filterNoHead || filterHasQr || searchQuery || branchFilter || companyFilter) && (
              <button
                type="button"
                onClick={() => { setFilterNoHead(false); setFilterHasQr(false); setSearchQuery(''); setBranchFilter(''); setCompanyFilter(''); setPage(1) }}
                className="inline-flex h-10 items-center gap-2 rounded-full px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <X className="size-4" />Clear
              </button>
            )}
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {loading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody>
                  <TableBodySkeleton rows={5} cols={6} />
                </tbody>
              </table>
            </div>
          ) : divisions.length === 0 ? (
            <div className="flex flex-col items-center gap-4 py-16 px-6 text-center">
              <div className="flex size-16 items-center justify-center rounded-2xl border border-border/50 bg-muted/30 dark:border-border/50 dark:bg-muted/40">
                <Layers className="size-8 text-muted-foreground/50" />
              </div>
              <div className="space-y-1">
                <p className="font-semibold text-foreground">No divisions yet</p>
                <p className="text-sm text-muted-foreground">Create your first division to start organizing employees, assigning heads, and tracking QR coverage.</p>
              </div>
              <Button
                size="sm"
                onClick={() => {
                  setCreateOpen(true)
                  setCreateName('')
                  setCreateCode('')
                  setCreateCompanyId(companyFilter || '')
                  setCreateBranchId(branchFilter || '')
                  setCreateDescription('')
                  setError(null)
                }}
              >
                <Plus className="mr-1.5 size-4" />
                Create first division
              </Button>
            </div>
          ) : filteredDivisions.length === 0 ? (
            <p className="py-16 text-center text-base text-muted-foreground">No divisions match your filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[980px] text-sm">
                <thead>
                  <tr className="border-b border-border/80 bg-muted/30 dark:bg-input/20">
                    <th className="w-20 px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      Logo
                    </th>
                    <th className="px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      <button
                        type="button"
                        onClick={() => toggleSort('name')}
                        className="inline-flex items-center gap-1 hover:text-foreground transition-colors"
                      >
                        Division
                        {sortCol === 'name'
                          ? (sortDir === 'asc' ? <ChevronUp className="size-3" /> : <ChevronDown className="size-3" />)
                          : <ChevronUp className="size-3 opacity-30" />}
                      </button>
                    </th>
                    <th className="px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      Branch
                    </th>
                    <th className="px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      Company
                    </th>
                    <th className="px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      Head
                    </th>
                    <th className="px-5 py-4 text-left text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      <button
                        type="button"
                        onClick={() => toggleSort('total')}
                        className="inline-flex items-center gap-1 hover:text-foreground transition-colors"
                      >
                        Employees
                        {sortCol === 'total'
                          ? (sortDir === 'asc' ? <ChevronUp className="size-3" /> : <ChevronDown className="size-3" />)
                          : <ChevronUp className="size-3 opacity-30" />}
                      </button>
                    </th>
                    <th className="px-5 py-4 text-right text-xs font-bold uppercase tracking-normal text-muted-foreground">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/70 dark:divide-border/60">
                  {pagedDivisions.map((division) => {
                    const assigned = division.assigned_employee_count ?? division.total_employees ?? 0
                    const branchTotal = division.branch_employee_count ?? division.department_employee_count ?? 0
                    const unassigned = division.unassigned_employee_count ?? Math.max(0, branchTotal - assigned)
                    const stats = (() => {
                      let active = 0, inactive = 0, withQr = 0, schedulesAssigned = 0
                      employees.forEach((emp) => {
                        if (sameUserId(emp.division_id, division.id)) {
                          if (emp.is_active) active += 1
                          else inactive += 1
                          if (emp.has_qr) withQr += 1
                          if (hasWorkingDays(emp.schedule)) schedulesAssigned += 1
                        }
                      })
                      return { assigned, branchTotal, unassigned, active, inactive, withQr, schedulesAssigned }
                    })()

                    const divisionInitials = (division.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'
                    const qrPct = stats.assigned > 0 ? Math.round((stats.withQr / stats.assigned) * 100) : 0
                    const qrBarColor = qrPct === 100 ? '#10b981' : qrPct >= 50 ? '#ff5a1f' : '#e5e7eb'

                    return (
                      <tr
                        key={division.id}
                        onClick={() => openDivisionPreview(division)}
                        onMouseEnter={(e) => {
                          if (hoverCardLeaveTimerRef.current) clearTimeout(hoverCardLeaveTimerRef.current)
                          setHoveredDivision(division)
                          setHoveredRowRect(e.currentTarget.getBoundingClientRect())
                        }}
                        onMouseLeave={() => {
                          hoverCardLeaveTimerRef.current = setTimeout(() => {
                            setHoveredDivision(null); setHoveredRowRect(null)
                          }, 100)
                        }}
                        className="group cursor-pointer bg-card transition-colors hover:bg-muted/25 dark:hover:bg-muted/20"
                      >
                        {/* Logo */}
                        <td className="px-5 py-4 align-middle">
                          {departmentLogoUrl(division) ? (
                            <div className="flex size-12 items-center justify-center overflow-hidden rounded-full border border-brand/50 bg-background shadow-sm dark:bg-input/30">
                              <img src={departmentLogoUrl(division)} alt="" className="size-10 rounded-full object-cover" key={division.logo || division.id} />
                            </div>
                          ) : (
                            <div className="flex size-12 items-center justify-center rounded-full border border-brand/40 bg-brand/10">
                              <span className="text-xs font-extrabold text-brand">{divisionInitials}</span>
                            </div>
                          )}
                        </td>

                        {/* Division name + date */}
                        <td className="px-5 py-4 align-middle">
                          <div className="space-y-0.5">
                            <p className="text-base font-extrabold uppercase tracking-normal text-foreground">{division.name}</p>
                            {division.code && (
                              <p className="text-sm text-muted-foreground/80">{division.code}</p>
                            )}
                            {division.created_at && (
                              <p className="text-xs text-muted-foreground">
                                Created {relativeDate(division.created_at)}
                              </p>
                            )}
                          </div>
                        </td>

                        {/* Branch */}
                        <td className="px-5 py-4 align-middle">
                          {division.branch_name ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-brand/35 bg-brand/10 px-3 py-1.5 text-sm font-bold uppercase text-brand dark:bg-brand/15">
                              <GitBranch className="size-3.5" />{division.branch_name}
                            </span>
                          ) : (
                            <span className="text-xs italic text-muted-foreground/50">—</span>
                          )}
                        </td>

                        {/* Company */}
                        <td className="px-5 py-4 align-middle">
                          {division.company_name ? (
                            <span className="text-sm font-medium text-foreground">{division.company_name}</span>
                          ) : (
                            <span className="text-xs italic text-muted-foreground/50">—</span>
                          )}
                        </td>

                        {/* Head */}
                        <td className="px-5 py-4 align-middle">
                          {division.division_head_name ? (
                            <div className="flex items-center gap-2">
                              <Avatar className="size-9 shrink-0">
                                <AvatarImage
                                  src={profileImageUrl(division.division_head_profile_image)}
                                  alt={division.division_head_name}
                                />
                                <AvatarFallback className="bg-brand/15 text-xs font-bold text-brand">
                                  {division.division_head_name.trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)}
                                </AvatarFallback>
                              </Avatar>
                              <span className="max-w-[150px] truncate text-sm font-medium text-foreground">{division.division_head_name}</span>
                            </div>
                          ) : (
                            <span className="text-sm italic text-muted-foreground">Not assigned</span>
                          )}
                        </td>

                        {/* Employee stats + QR bar */}
                        <td className="px-5 py-4 align-middle">
                          <div className="space-y-1.5">
                            <p className="text-base font-extrabold text-foreground">
                              {stats.assigned} assigned
                            </p>
                            <p className="text-xs text-muted-foreground">
                              {stats.branchTotal} in branch · {stats.unassigned} unassigned
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                <span className="size-2 rounded-full bg-emerald-500" />{stats.active} active
                              </span>
                              {stats.inactive > 0 && (
                                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-rose-600 dark:text-rose-400">
                                  <span className="size-2 rounded-full bg-rose-500" />{stats.inactive} inactive
                                </span>
                              )}
                              <span className="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 dark:text-blue-400">
                                <span className="size-2 rounded-full bg-blue-500" />{stats.withQr} QR
                              </span>
                            </div>
                            {stats.assigned > 0 && (
                              <div className="flex items-center gap-2">
                                <div
                                  className="h-1.5 w-20 overflow-hidden rounded-full bg-muted dark:bg-input/50"
                                  title={`QR issuance: ${stats.withQr} of ${stats.assigned} assigned employees have QR codes for attendance check-in`}
                                >
                                  <div
                                    className="h-full rounded-full transition-all"
                                    style={{ width: `${qrPct}%`, background: qrBarColor }}
                                  />
                                </div>
                                <span
                                  className="cursor-default text-xs tabular-nums text-muted-foreground"
                                  title={`${stats.withQr} of ${stats.assigned} assigned employees issued QR codes`}
                                >
                                  {qrPct}% QR
                                </span>
                              </div>
                            )}
                          </div>
                        </td>

                        {/* Actions — inline quick actions + kebab */}
                        <td className="px-5 py-4" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            {/* Quick action buttons — visible on row hover */}
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="hidden"
                              onClick={(e) => { e.stopPropagation(); openAssignDialog(division) }}
                              title="Assign employees to this division"
                            >
                              <UserPlus className="size-3.5" />
                              Assign
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="hidden"
                              onClick={(e) => { e.stopPropagation(); openViewEmployees(division) }}
                              title="View this division's employees"
                            >
                              <Eye className="size-3.5" />
                              View
                            </Button>
                            {/* Kebab for more actions */}
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="size-10 rounded-xl border border-border/80 bg-background text-foreground shadow-sm hover:bg-muted dark:bg-input/30 dark:hover:bg-input/50"
                                  aria-label="More actions"
                                  onClick={(e) => e.stopPropagation()}
                                >
                                  <MoreVertical className="size-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuItem onClick={() => openViewEmployees(division)}>
                                  <Eye className="size-4" /><span>View employees</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openEditDialog(division)}>
                                  <Pencil className="size-4" /><span>Edit division</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openHeadDialog(division)}>
                                  <UserPlus className="size-4" /><span>Assign head</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openAssignDialog(division)}>
                                  <Users className="size-4" /><span>Assign employees</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" onClick={() => setDeleteConfirmDivision(division)}>
                                  <Trash2 className="size-4" /><span>Delete division</span>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>

              {filteredDivisions.length > 0 && (
                <div className="flex flex-col gap-3 border-t border-border/80 px-5 py-4 text-sm text-muted-foreground @md:flex-row @md:items-center @md:justify-between">
                  <span>
                    Showing {rangeStart} to {rangeEnd} of {totalFilteredDivisions} {totalFilteredDivisions === 1 ? 'division' : 'divisions'}
                  </span>
                  <div className="flex items-center gap-3">
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="size-9 rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                      disabled={currentPage <= 1}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      aria-label="Previous page"
                    >
                      <ChevronDown className="size-4 rotate-90" />
                    </Button>
                    {Array.from({ length: pageCount }, (_, index) => index + 1).slice(0, 5).map((pageNumber) => (
                      <Button
                        key={pageNumber}
                        type="button"
                        variant="ghost"
                        className={cn(
                          'size-9 rounded-lg border text-sm font-semibold',
                          pageNumber === currentPage
                            ? 'border-brand bg-brand/5 text-brand hover:bg-brand/10'
                            : 'border-transparent text-muted-foreground hover:border-border hover:bg-muted hover:text-foreground'
                        )}
                        onClick={() => setPage(pageNumber)}
                      >
                        {pageNumber}
                      </Button>
                    ))}
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="size-9 rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                      disabled={currentPage >= pageCount}
                      onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                      aria-label="Next page"
                    >
                      <ChevronDown className="size-4 -rotate-90" />
                    </Button>
                  </div>
                </div>
              )}

              {hoveredDivision && hoveredRowRect && (() => {
                const division = hoveredDivision
                const assigned = division.assigned_employee_count ?? division.total_employees ?? 0
                const branchTotal = division.branch_employee_count ?? division.department_employee_count ?? 0
                const unassigned = division.unassigned_employee_count ?? Math.max(0, branchTotal - assigned)
                let active = 0, inactive = 0, withQr = 0, schedulesAssigned = 0
                employees.forEach((emp) => {
                  if (sameUserId(emp.division_id, division.id)) {
                    if (emp.is_active) active += 1
                    else inactive += 1
                    if (emp.has_qr) withQr += 1
                    if (hasWorkingDays(emp.schedule)) schedulesAssigned += 1
                  }
                })
                return (
                  <div
                    className="fixed z-50 min-w-[220px] rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-xl dark:border-border/50"
                    style={{
                      top: hoveredRowRect.bottom + 6,
                      left: Math.min(hoveredRowRect.left, typeof window !== 'undefined' ? window.innerWidth - 280 : hoveredRowRect.left),
                    }}
                    onMouseEnter={() => {
                      if (hoverCardLeaveTimerRef.current) clearTimeout(hoverCardLeaveTimerRef.current)
                      hoverCardLeaveTimerRef.current = null
                    }}
                    onMouseLeave={() => { setHoveredDivision(null); setHoveredRowRect(null) }}
                  >
                    <p className="mb-2 font-semibold text-foreground">{division.name}</p>
                    {division.created_at && (
                      <p className="mb-2 text-[11px] text-muted-foreground">Created {relativeDate(division.created_at)} · {new Date(division.created_at).toLocaleDateString()}</p>
                    )}
                    <dl className="space-y-1 text-muted-foreground">
                      <div className="flex justify-between gap-4"><dt>Assigned:</dt><dd className="font-medium text-foreground tabular-nums">{assigned}</dd></div>
                      <div className="flex justify-between gap-4"><dt>In branch:</dt><dd className="font-medium text-foreground tabular-nums">{branchTotal}</dd></div>
                      <div className="flex justify-between gap-4"><dt>Unassigned:</dt><dd className="font-medium text-amber-600 dark:text-amber-400 tabular-nums">{unassigned}</dd></div>
                      <div className="flex justify-between gap-4"><dt>Active:</dt><dd className="font-medium text-emerald-600 dark:text-emerald-400 tabular-nums">{active}</dd></div>
                      <div className="flex justify-between gap-4"><dt>Inactive:</dt><dd className="font-medium text-rose-600 dark:text-rose-400 tabular-nums">{inactive}</dd></div>
                      <div className="flex justify-between gap-4"><dt>Scheduled:</dt><dd className="font-medium text-foreground tabular-nums">{schedulesAssigned}</dd></div>
                      <div className="flex justify-between gap-4"><dt>QR issued:</dt><dd className="font-medium text-sky-600 dark:text-sky-400 tabular-nums">{withQr}</dd></div>
                    </dl>
                  </div>
                )
              })()}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Division details drawer — row click */}
      <Sheet
        open={previewOpen}
        onOpenChange={(open) => {
          setPreviewOpen(open)
          if (!open) {
            setPreviewDivision(null)
            setPreviewMembers([])
          }
        }}
      >
        <SheetContent side="right" className="flex w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-md">
          <SheetHeader className="border-b border-border/50 bg-muted/30 px-6 py-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Division Profile</p>
            {previewDivision && (
              <div className="flex items-center gap-4">
                {departmentLogoUrl(previewDivision) ? (
                  <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40">
                    <img src={departmentLogoUrl(previewDivision)} alt="" className="h-12 w-12 rounded-md object-cover" key={previewDivision.logo || previewDivision.id} />
                  </div>
                ) : (
                  <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/60">
                    <span className="text-sm font-semibold text-muted-foreground">
                      {(previewDivision.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'}
                    </span>
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <SheetTitle className="text-lg font-semibold tracking-tight text-foreground">
                    {previewDivision.name}
                  </SheetTitle>
                  <SheetDescription className="mt-0.5 text-sm text-muted-foreground">
                    {previewDivision.division_head_name ? `Head: ${previewDivision.division_head_name}` : 'Head: Not assigned'}
                  </SheetDescription>
                </div>
              </div>
            )}
          </SheetHeader>
          {previewDivision && (
            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
              <div className="space-y-4">
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Company logo</h3>
                  <p className="mb-2 text-[11px] text-muted-foreground">Divisions inherit the logo from their Company.</p>
                  <div className="flex items-center gap-3">
                    {departmentLogoUrl(previewDivision) ? (
                      <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40">
                        <img src={departmentLogoUrl(previewDivision)} alt="" className="h-12 w-12 rounded-md object-cover" key={previewDivision.logo || previewDivision.id} />
                      </div>
                    ) : (
                      <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/60">
                        <span className="text-sm font-semibold text-muted-foreground">
                          {(previewDivision.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'}
                        </span>
                      </div>
                    )}
                  </div>
                </section>
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Division Head</h3>
                  <p className="text-sm font-medium text-foreground">
                    {previewDivision.division_head_name || 'Not assigned'}
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    className="mt-2 h-8 text-xs"
                    onClick={() => {
                      setPreviewOpen(false)
                      openHeadDialog(previewDivision)
                    }}
                  >
                    <UserPlus className="size-3.5 mr-1.5" />
                    Assign head
                  </Button>
                </section>
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Employees</h3>
                  {(() => {
                    const divisionEmployeesCached = employees.filter((e) => sameUserId(e.division_id, previewDivision.id))
                    const assignedCount = previewDivision.assigned_employee_count ?? previewDivision.total_employees ?? divisionEmployeesCached.length
                    const branchCount = previewDivision.branch_employee_count ?? previewDivision.department_employee_count ?? 0
                    const unassignedCount = previewDivision.unassigned_employee_count ?? Math.max(0, branchCount - assignedCount)
                    const showDetailStats = divisionEmployeesCached.length > 0
                    const active = showDetailStats ? divisionEmployeesCached.filter((e) => e.is_active).length : null
                    const withQr = showDetailStats ? divisionEmployeesCached.filter((e) => e.has_qr).length : null
                    return (
                      <>
                        <dl className="space-y-1 text-sm">
                          <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Assigned employees</dt>
                            <dd className="font-medium text-foreground tabular-nums">{assignedCount}</dd>
                          </div>
                          <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Branch employees</dt>
                            <dd className="font-medium text-foreground tabular-nums">{branchCount}</dd>
                          </div>
                          <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Unassigned to division</dt>
                            <dd className="font-medium text-amber-600 dark:text-amber-400 tabular-nums">{unassignedCount}</dd>
                          </div>
                        </dl>
                        {!previewMembersLoading && previewMembers.length > 0 && (
                          <div className="mt-2 flex flex-wrap items-center gap-2">
                            <div className="flex -space-x-2">
                              {previewMembers.slice(0, 8).map((m) => (
                                <Avatar key={m.id} className="size-8 border-2 border-background ring-1 ring-border/60">
                                  <AvatarImage src={profileImageUrl(m.profile_image)} alt="" />
                                  <AvatarFallback className="text-[10px]">{initials(m.name)}</AvatarFallback>
                                </Avatar>
                              ))}
                            </div>
                            {previewMembers.length > 8 && (
                              <span className="text-xs text-muted-foreground">+{previewMembers.length - 8} more</span>
                            )}
                          </div>
                        )}
                        {showDetailStats && (
                          <p className="mt-1 text-xs text-muted-foreground">
                            Active: {active} • Inactive: {divisionEmployeesCached.length - active} • With QR: {withQr}
                          </p>
                        )}
                        {!showDetailStats && !previewMembersLoading && assignedCount > 0 && (
                          <p className="mt-1 text-xs text-muted-foreground">
                            Same list as in Assign employees — open below for actions.
                          </p>
                        )}
                        <Button
                          variant="outline"
                          size="sm"
                          className="mt-2 h-8 text-xs"
                          onClick={() => {
                            setPreviewOpen(false)
                            openViewEmployees(previewDivision)
                          }}
                        >
                          <Eye className="size-3.5 mr-1.5" />
                          View employees
                        </Button>
                      </>
                    )
                  })()}
                </section>
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Activity</h3>
                  {previewDivision.created_at && (
                    <p className="text-sm text-muted-foreground">
                      Created {new Date(previewDivision.created_at).toLocaleDateString()}
                    </p>
                  )}
                  {!previewDivision.created_at && (
                    <p className="text-sm text-muted-foreground">—</p>
                  )}
                </section>
              </div>
            </div>
          )}
          <SheetFooter className="border-t border-border/50 bg-muted/20 px-6 py-4">
            <Button
              variant="outline"
              className="w-full"
              onClick={() => setPreviewOpen(false)}
            >
              Close
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {/* Create Division */}
      <Dialog
        open={createOpen}
        onOpenChange={(open) => {
          setCreateOpen(open)
          if (!open) {
            setCreateCompanyPickerOpen(false)
            setCreateBranchPickerOpen(false)
          }
        }}
      >
        <DialogContent
          showCloseButton
          className="max-w-[min(100vw-1.5rem,42rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="div-create-desc"
        >
          <div className="px-6 pb-5 pt-7 pr-16 @md:px-8">
            <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
                <Building2 className="size-7" />
              </div>
              <div className="min-w-0 pt-1">
              <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                Create Division
              </DialogTitle>
              <p id="div-create-desc" className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                Add a new division. Company and name are required — branch and code are optional.
              </p>
              </div>
            </DialogHeader>
          </div>
          <form onSubmit={handleCreate} className="flex min-h-0 flex-1 flex-col text-foreground">
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5 @md:px-8">
            <div className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="div-name" className="flex items-center gap-2 text-base font-semibold text-foreground">
                    <span className="size-2.5 rounded-full bg-brand" />
                    Division name <span className="text-brand">*</span>
                  </Label>
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-lg bg-brand/10 text-brand">
                      <Building2 className="size-5" />
                    </span>
                  <Input
                    id="div-name"
                    value={createName}
                    onChange={(e) => setCreateName(e.target.value)}
                    placeholder="e.g. Operations"
                    className="h-12 rounded-xl border-brand/70 bg-background pl-14 text-base shadow-sm focus-visible:ring-brand/20 dark:bg-input/35"
                    required
                  />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label id="create-company-picker-label" className="flex items-center gap-2 text-base font-semibold text-foreground">
                    <span className="size-2.5 rounded-full bg-brand" />
                    Company <span className="text-brand">*</span>
                  </Label>
                  <Popover open={createCompanyPickerOpen} onOpenChange={setCreateCompanyPickerOpen}>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        id="create-company-picker"
                        aria-labelledby="create-company-picker-label"
                        aria-expanded={createCompanyPickerOpen}
                        className="h-12 w-full justify-between gap-2 rounded-xl border-border/80 bg-background px-4 py-2 text-base font-normal shadow-sm hover:bg-transparent dark:bg-input/35"
                      >
                        {selectedCreateCompany ? (
                          <span className="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <Avatar className="size-8 shrink-0 rounded-lg border border-border/50">
                              <AvatarImage src={selectedCreateCompany.logo_url || undefined} alt="" />
                              <AvatarFallback className="rounded-md text-[10px]">{initials(selectedCreateCompany.name)}</AvatarFallback>
                            </Avatar>
                            <span className="truncate text-sm font-medium">{selectedCreateCompany.name}</span>
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-3 text-muted-foreground">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
                              <Building2 className="size-5" />
                            </span>
                            Select company
                          </span>
                        )}
                        <ChevronDown className="size-4 shrink-0 opacity-50" aria-hidden />
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] min-w-[min(100vw-2rem,24rem)] p-0" onOpenAutoFocus={(e) => e.preventDefault()}>
                      {sortedCompaniesForPicker.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">No companies available.</p>
                      ) : (
                        <div className="max-h-[min(60vh,320px)] overflow-y-auto p-1">
                          {sortedCompaniesForPicker.map((c) => {
                            const selected = String(createCompanyId) === String(c.id)
                            return (
                              <button
                                key={c.id}
                                type="button"
                                onClick={() => {
                                  setCreateCompanyId(String(c.id))
                                  setCreateBranchId('')
                                  setCreateCompanyPickerOpen(false)
                                }}
                                className={cn('flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-muted', selected && 'bg-muted')}
                              >
                                <Avatar className="size-9 shrink-0 rounded-md border border-border/50">
                                  <AvatarImage src={c.logo_url || undefined} alt="" />
                                  <AvatarFallback className="rounded-md text-[10px]">{initials(c.name)}</AvatarFallback>
                                </Avatar>
                                <span className="min-w-0 flex-1 truncate font-medium">{c.name}</span>
                                {selected ? <Check className="size-4 shrink-0 text-primary" /> : null}
                              </button>
                            )
                          })}
                        </div>
                      )}
                    </PopoverContent>
                  </Popover>
                </div>
                <div className="space-y-2">
                  <Label id="create-branch-picker-label" className="text-base font-semibold text-foreground">
                    Branch <span className="font-normal text-muted-foreground">(optional)</span>
                  </Label>
                  <Popover open={createBranchPickerOpen} onOpenChange={setCreateBranchPickerOpen}>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        disabled={!createCompanyId}
                        className="h-12 w-full justify-between gap-2 rounded-xl border-border/80 bg-background px-4 py-2 text-base font-normal shadow-sm hover:bg-transparent disabled:opacity-60 dark:bg-input/35"
                      >
                        {selectedCreateBranch ? (
                          <span className="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <GitBranch className="size-5 shrink-0 text-brand" />
                            <span className="truncate text-sm font-medium">{selectedCreateBranch.name}</span>
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-3 text-muted-foreground">
                            <GitBranch className="size-5 text-brand" />
                            Select branch
                          </span>
                        )}
                        <ChevronDown className="size-4 shrink-0 opacity-50" />
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] min-w-[min(100vw-2rem,24rem)] p-0" onOpenAutoFocus={(e) => e.preventDefault()}>
                      {branchesForCreateCompany.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">No branches for this company.</p>
                      ) : (
                        <div className="max-h-[min(60vh,320px)] overflow-y-auto p-1">
                          <button type="button" onClick={() => { setCreateBranchId(''); setCreateBranchPickerOpen(false) }} className="flex w-full rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted">— None —</button>
                          {branchesForCreateCompany.map((b) => (
                            <button key={b.id} type="button" onClick={() => { setCreateBranchId(String(b.id)); setCreateBranchPickerOpen(false) }} className={cn('flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm hover:bg-muted', String(createBranchId) === String(b.id) && 'bg-muted')}>
                              <GitBranch className="size-4 text-brand" />
                              <span className="truncate font-medium">{b.name}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </PopoverContent>
                  </Popover>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="div-code" className="text-base font-semibold text-foreground">
                    Code <span className="font-normal text-muted-foreground">(optional)</span>
                  </Label>
                  <Input
                    id="div-code"
                    value={createCode}
                    onChange={(e) => setCreateCode(e.target.value)}
                    placeholder="e.g. OPS-01"
                    maxLength={50}
                    className="h-12 rounded-xl border-border/80 bg-background text-base shadow-sm dark:bg-input/35"
                  />
                </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="div-description" className="text-base font-semibold text-foreground">
                Description <span className="font-normal text-muted-foreground">(optional)</span>
              </Label>
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-3 flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
                  <FileText className="size-5" />
                </span>
              <textarea
                id="div-description"
                value={createDescription}
                onChange={(e) => setCreateDescription(e.target.value)}
                placeholder="Brief description of this division's role or scope…"
                rows={3}
                maxLength={1000}
                className="min-h-[86px] w-full rounded-xl border border-border/80 bg-background py-3 pl-14 pr-4 text-base text-foreground shadow-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
              />
              </div>
            </div>
            </div>

            <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5 @md:px-8">
              <Button type="button" variant="outline" className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted" onClick={() => setCreateOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={createSubmitting || !createName.trim() || !createCompanyId} className="h-11 min-w-[180px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong">
                {createSubmitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Plus className="mr-2 size-4" />}
                Create Division
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit Division */}
      <Dialog
        open={editOpen}
        onOpenChange={(open) => {
          setEditOpen(open)
          if (!open) setEditDivision(null)
        }}
      >
        <DialogContent
          showCloseButton
          surfaceStyle={{
            width: 'min(calc(100vw - 1.5rem), 88rem)',
            maxWidth: 'none',
            height: 'min(92vh, 52rem)',
          }}
          className="max-h-[min(92vh,52rem)] min-h-0 min-w-0 !max-w-none rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="flex min-h-0 flex-1 flex-col !gap-0 !overflow-hidden !p-0"
          closeButtonClassName="right-5 top-5 size-9 rounded-lg border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="div-edit-desc"
        >
          <form onSubmit={handleEdit} className="flex min-h-0 flex-1 flex-col overflow-hidden text-foreground">
            <div className="shrink-0 border-b border-border/80 px-6 pb-5 pt-7 pr-16 md:px-8">
              <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
                <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
                  <Building2 className="size-7" />
                </div>
                <div className="min-w-0 pt-1">
                  <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                    Edit Division
                  </DialogTitle>
                  <p id="div-edit-desc" className="mt-3 max-w-3xl text-base leading-7 text-muted-foreground">
                    Update division details and org placement (company and branch).
                  </p>
                </div>
              </DialogHeader>
            </div>

            <div className="grid min-h-0 flex-1 grid-cols-1 divide-y divide-border/80 overflow-hidden lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:divide-x lg:divide-y-0">
              <div className="min-h-0 overflow-y-auto px-6 py-5 md:px-8">
                <div className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="edit-div-name" className="flex items-center gap-2 text-base font-semibold text-foreground">
                      <span className="size-2.5 rounded-full bg-brand" />
                      Division name <span className="text-brand">*</span>
                    </Label>
                    <div className="relative">
                      <span className="pointer-events-none absolute left-3 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-lg bg-brand/10 text-brand">
                        <Building2 className="size-5" />
                      </span>
                      <Input
                        id="edit-div-name"
                        value={editName}
                        onChange={(e) => setEditName(e.target.value)}
                        placeholder="e.g. Operations"
                        className="h-12 rounded-xl border-brand/70 bg-background pl-14 text-base shadow-sm focus-visible:ring-brand/20 dark:bg-input/35"
                        required
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-div-code" className="text-base font-semibold text-foreground">
                      Code <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                      id="edit-div-code"
                      value={editCode}
                      onChange={(e) => setEditCode(e.target.value)}
                      placeholder="e.g. OPS-01"
                      maxLength={50}
                      className="h-12 rounded-xl border-border/80 bg-background text-base shadow-sm dark:bg-input/35"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-div-company" className="flex items-center gap-2 text-base font-semibold text-foreground">
                      <span className="size-2.5 rounded-full bg-brand" />
                      Company <span className="text-brand">*</span>
                    </Label>
                    <select
                      id="edit-div-company"
                      value={editCompanyId}
                      onChange={(e) => {
                        setEditCompanyId(e.target.value)
                        setEditBranchId('')
                      }}
                      className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-base text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
                      required
                    >
                      <option value="">Select company</option>
                      {sortedCompaniesForPicker.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                      ))}
                    </select>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-div-branch" className="text-base font-semibold text-foreground">
                      Branch <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <select
                      id="edit-div-branch"
                      value={editBranchId}
                      onChange={(e) => setEditBranchId(e.target.value)}
                      disabled={!editCompanyId}
                      className="h-12 w-full rounded-xl border border-border/80 bg-background px-4 text-base text-foreground shadow-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/15 disabled:opacity-60 dark:bg-input/35"
                    >
                      <option value="">— None —</option>
                      {editBranchesForCompany.map((b) => (
                        <option key={b.id} value={b.id}>{b.name}</option>
                      ))}
                    </select>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-div-description" className="text-base font-semibold text-foreground">
                      Description <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <div className="relative">
                      <span className="pointer-events-none absolute left-3 top-3 flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
                        <FileText className="size-5" />
                      </span>
                      <textarea
                        id="edit-div-description"
                        value={editDescription}
                        onChange={(e) => setEditDescription(e.target.value)}
                        placeholder="Brief description of this division's role or scope..."
                        rows={4}
                        maxLength={1000}
                        className="min-h-[96px] w-full rounded-xl border border-border/80 bg-background py-3 pl-14 pr-4 text-base text-foreground shadow-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-input/35"
                      />
                    </div>
                  </div>
                </div>
              </div>

              {editDivision?.id ? (
                <div className="min-h-0 overflow-y-auto bg-muted/10 px-4 py-5 md:px-6">
                  <LeadershipPositionsSection
                    ref={leadershipRef}
                    legacyType="division"
                    legacyId={editDivision.id}
                    canManage
                  />
                </div>
              ) : null}
            </div>

            <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5 md:px-8">
              <Button
                type="button"
                variant="outline"
                className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted"
                onClick={() => setEditOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={editSubmitting || !editName.trim() || !editCompanyId} className="h-11 min-w-[160px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong">
                {editSubmitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Pencil className="mr-2 size-4" />}
                Save Changes
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* View Employees */}
      <Dialog
        open={viewEmployeesOpen}
        onOpenChange={(open) => {
          setViewEmployeesOpen(open)
          if (!open) setViewEmployeesSelectedIds([])
        }}
      >
        <DialogContent
          showCloseButton
          className="max-w-[min(100vw-1.5rem,48rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
          innerClassName="p-0"
          closeButtonClassName="right-5 top-5 size-10 rounded-xl border-border/80 bg-background text-foreground hover:bg-muted"
          overlayClassName="bg-black/55 backdrop-blur-sm"
          aria-describedby="dept-view-employees-desc"
        >
          <div className="border-b border-border/80 px-6 pb-5 pt-7 pr-16 @md:px-8">
            <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                <Users className="size-7" />
              </div>
              <div className="min-w-0 pt-1">
              <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">View Employees</DialogTitle>
              <p id="dept-view-employees-desc" className="mt-3 max-w-2xl text-base leading-7 text-muted-foreground">
                {viewEmployeesDivision && (
                  <>
                    <strong className="font-extrabold tabular-nums text-foreground">{viewEmployeesList.length}</strong>
                    {' '}
                    {viewEmployeesList.length === 1 ? 'employee' : 'employees'} in{' '}
                    <strong className="font-extrabold uppercase text-brand">{viewEmployeesDivision.name}</strong>.
                    {' '}Unassign or assign more below.
                  </>
                )}
              </p>
              </div>
            </DialogHeader>
          </div>
          <div className="flex min-h-0 flex-1 flex-col px-6 py-5 @md:px-8">
            {viewEmployeesLoading ? (
              <div className="flex items-center justify-center gap-2 rounded-xl border border-border/70 py-10 text-sm text-muted-foreground dark:bg-input/20">
                <Loader2 className="size-5 animate-spin text-brand" />
                Loading employees...
              </div>
            ) : viewEmployeesList.length === 0 ? (
              <div className="rounded-xl border border-dashed border-border/80 px-6 py-10 text-center text-sm text-muted-foreground">
                No employees assigned to this division.
              </div>
            ) : (
              <>
                <div className="mb-4 flex items-center gap-4 rounded-xl border border-border/80 bg-background px-4 py-4 dark:bg-input/25">
                  <label className="flex cursor-pointer select-none items-center gap-4 text-base font-medium text-muted-foreground hover:text-foreground">
                    <input
                      type="checkbox"
                      checked={
                        viewEmployeesList.length > 0 &&
                        viewEmployeesList.every((emp) =>
                          viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                        )
                      }
                      onChange={toggleViewEmployeesSelectAll}
                      className="size-5 rounded border-input accent-orange-600"
                    />
                    {viewEmployeesList.length > 0 &&
                    viewEmployeesList.every((emp) =>
                      viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                    )
                      ? 'Deselect all'
                      : 'Select all'}
                  </label>
                  {viewEmployeesSelectedIds.length > 0 && (
                    <span className="ml-auto rounded-full bg-brand/10 px-3 py-1 text-xs font-bold text-brand tabular-nums">
                      {viewEmployeesSelectedIds.length} selected
                    </span>
                  )}
                </div>
                <ul className="max-h-[min(48vh,24rem)] space-y-3 overflow-y-auto pr-1">
                  {viewEmployeesList.map((emp) => (
                    <li
                      key={emp.id}
                      className={`flex items-center gap-4 rounded-xl border px-4 py-3 transition-colors ${
                        viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                          ? 'border-brand/60 bg-brand/5 dark:bg-brand/10'
                          : 'border-border/80 bg-background dark:bg-input/20'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))}
                        onChange={() => toggleViewEmployeeSelection(emp.id)}
                        className="size-5 shrink-0 rounded border-input accent-orange-600"
                      />
                      <Avatar className="size-12 shrink-0 bg-brand/10">
                        <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" />
                        <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">{initials(emp.name)}</AvatarFallback>
                      </Avatar>
                      <span className="min-w-0 flex-1 truncate text-base font-extrabold text-foreground">{emp.name}</span>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-10 shrink-0 gap-2 rounded-xl border-brand/70 px-4 text-sm font-semibold text-brand hover:bg-brand/10 hover:text-brand"
                        disabled={!!unassigningId}
                        onClick={() => handleUnassignFromView(emp)}
                        type="button"
                      >
                        {unassigningId === emp.id ? <Loader2 className="size-4 animate-spin" /> : <UserMinus className="size-4" />}
                        Unassign
                      </Button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
          <DialogFooter className="shrink-0 flex-wrap gap-3 border-t border-border/80 px-6 py-5 @md:px-8">
            {viewEmployeesSelectedIds.length > 0 && (
              <Button
                variant="destructive"
                size="sm"
                disabled={!!unassigningId}
                onClick={handleUnassignBulkFromView}
                className="mr-auto h-11 rounded-xl px-5 font-bold"
              >
                {unassigningId === 'bulk' ? <Loader2 className="size-4 animate-spin mr-2" /> : <UserMinus className="size-4 mr-2" />}
                Unassign selected ({viewEmployeesSelectedIds.length})
              </Button>
            )}
            <Button variant="outline" onClick={() => setViewEmployeesOpen(false)} className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted">
              Close
            </Button>
            {viewEmployeesDivision && (
              <Button
                type="button"
                onClick={openAssignFromViewModal}
                className="h-11 min-w-[190px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong"
              >
                <Users className="size-4 mr-2" />
                Assign employees
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Unassign confirmation */}
      <Dialog open={!!unassignConfirm} onOpenChange={(open) => !open && setUnassignConfirm(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="dept-unassign-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>
                Unassign {unassignConfirm?.bulkIds?.length ? 'Employees' : 'Employee'}
              </DialogTitle>
              <p id="dept-unassign-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {unassignConfirm && (
                  unassignConfirm.bulkIds?.length ? (
                    <>
                      Are you sure you want to unassign <strong>{unassignConfirm.bulkIds.length} employee{unassignConfirm.bulkIds.length > 1 ? 's' : ''}</strong> from{' '}
                      <strong>{unassignConfirm.division.name}</strong>?
                    </>
                  ) : (
                    <>
                      Are you sure you want to unassign <strong>{unassignConfirm.employee?.name}</strong> from{' '}
                      <strong>{unassignConfirm.division.name}</strong>?
                    </>
                  )
                )}
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button variant="outline" onClick={() => setUnassignConfirm(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleUnassignConfirm}
              disabled={!!unassigningId}
            >
              {unassigningId ? <Loader2 className="size-4 animate-spin" /> : 'Confirm'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AssignOrgHeadModal
        open={headOpen}
        onOpenChange={(open) => {
          setHeadOpen(open)
          if (!open) {
            headLoadSeqRef.current += 1
            setHeadDivision(null)
            setHeadId('')
            setHeadModalEmployees([])
            setHeadModalLoading(false)
            setHeadModalLoadError(null)
          }
        }}
        title="Assign Division Head"
        unitName={headDivision?.name}
        fieldLabel="Division Head"
        loading={headModalLoading}
        loadingMessage="Loading division members…"
        loadError={headModalLoadError}
        onRetry={() => headDivision && loadHeadCandidates(headDivision)}
        employees={headModalEmployees}
        currentHeadId={headDivision?.division_head_id}
        currentHead={buildOrgCurrentHead({
          id: headDivision?.division_head_id,
          name: headDivision?.division_head_name,
          profile_image_url: headDivision?.division_head_profile_image,
        })}
        headId={headId}
        onHeadIdChange={setHeadId}
        headRoleNotes={divisionHeadRoleNotes}
        submitting={headSubmitting}
        onSubmit={handleAssignHead}
        initialsFn={initials}
      />

      <AssignEmployeesModal
        open={assignOpen}
        onOpenChange={(open) => {
          setAssignOpen(open)
          if (!open) {
            setAssignSearchQuery('')
            setAssignFilter('available')
          }
        }}
        department={assignDivision}
        memberIdField="division_id"
        orgUnitLabel="division"
        loading={assignModalLoading}
        assignRows={assignRows}
        assignSearchQuery={assignSearchQuery}
        onSearchChange={setAssignSearchQuery}
        assignFilter={assignFilter}
        onFilterChange={setAssignFilter}
        assignCounts={assignCounts}
        assignExcludedCount={assignExcludedCount}
        assignSelectableIds={assignSelectableIds}
        allSelectableChecked={allSelectableChecked}
        onToggleSelectAll={toggleSelectAllAssignable}
        onToggleAssignId={toggleAssignId}
        onSubmit={handleAssignEmployees}
        submitting={assignSubmitting}
        assignIds={assignIds}
        selectedEmployeesPreview={selectedEmployeesPreview}
        assignNewToDeptCount={assignNewToDivisionCount}
        navigate={navigate}
        initialsFn={initials}
        footerStats={assignFooterStats}
        onGoEmployees={() => navigate('/admin/employees')}
        assignmentMode={assignMode}
        onAssignmentModeChange={setAssignMode}
        crossCompanySelectedCount={crossCompanySelectedCount}
      />


      {/* Delete confirmation */}
      <Dialog open={!!deleteConfirmDivision} onOpenChange={(open) => !open && setDeleteConfirmDivision(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="div-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete division?</DialogTitle>
              <p id="div-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {deleteConfirmDivision && (
                  <>
                    Are you sure you want to delete <strong className="text-foreground">{deleteConfirmDivision.name}</strong>?
                    Employees in this division will be unassigned.
                  </>
                )}
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button variant="outline" onClick={() => setDeleteConfirmDivision(null)} disabled={deleteSubmitting}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete} disabled={deleteSubmitting}>
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4 mr-2" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
