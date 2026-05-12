import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Plus, Building2, Loader2, UserPlus, Users, Trash2, Eye, UserMinus, X, MoreVertical, Search, ChevronUp, ChevronDown, QrCode, GitBranch, Check, Network, AlertCircle } from 'lucide-react'
import { TableBodySkeleton } from '@/components/skeletons'
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
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '@/components/ui/sheet'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { getDepartments, createDepartment, updateDepartment, deleteDepartment, assignEmployeesToDepartment, unassignEmployeesFromDepartment, getEmployees, getDepartmentEmployees, getBranches, getCompanies, departmentLogoUrl, profileImageUrl } from '@/api'
import { RoleBadge } from '@/components/RoleBadge'
import { useToast } from '@/components/ui/use-toast'
import { hasEmoji, hasFancyUnicode } from '@/validation'
import { cn } from '@/lib/utils'
import { isRosterStaffMember } from '@/lib/rosterStaff'
import { FIELD_SELECT_CLASS_H8, FIELD_SELECT_CLASS_H10, FIELD_TEXTAREA_CLASS_SM } from '@/lib/fieldClasses'
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

function hasWorkingDays(schedule) {
  if (!schedule || typeof schedule !== 'object') return false
  return Object.values(schedule).some((v) => v && v.in && v.out)
}

function validateDepartmentName(value) {
  const trimmed = value.trim()
  if (!trimmed) return 'Department name is required.'
  if (hasEmoji(trimmed)) return 'Emojis are not allowed in department names.'
  if (hasFancyUnicode(trimmed)) {
    return 'Please use standard letters/numbers only (no styled fonts or special symbols) in department names.'
  }
  if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) {
    return 'Department name may only contain letters, numbers, spaces, hyphens, and apostrophes.'
  }
  if (trimmed.length > 100) return 'Department name must be 100 characters or less.'
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

export default function AdminDepartments() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [departments, setDepartments] = useState([])
  const [branches, setBranches] = useState([])
  const [companies, setCompanies] = useState([])
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [branchFilter, setBranchFilter] = useState(() => searchParams.get('branch_id') || '')
  const [companyFilter, setCompanyFilter] = useState(() => searchParams.get('company_id') || '')

  const [createOpen, setCreateOpen] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createBranchId, setCreateBranchId] = useState('')
  const [createOfficeLocation, setCreateOfficeLocation] = useState('')
  const [createSubmitting, setCreateSubmitting] = useState(false)

  const [viewEmployeesOpen, setViewEmployeesOpen] = useState(false)
  const [viewEmployeesDept, setViewEmployeesDept] = useState(null)
  const [viewEmployeesList, setViewEmployeesList] = useState([])
  const [viewEmployeesLoading, setViewEmployeesLoading] = useState(false)
  const [viewEmployeesSelectedIds, setViewEmployeesSelectedIds] = useState([])
  const [unassigningId, setUnassigningId] = useState(null)
  const [unassignConfirm, setUnassignConfirm] = useState(null)

  const [headOpen, setHeadOpen] = useState(false)
  const [headDepartment, setHeadDepartment] = useState(null)
  const [headId, setHeadId] = useState('')
  const [headSubmitting, setHeadSubmitting] = useState(false)
  /** Roster candidates for Assign Head — loaded per department via API (avoid paginated global `employees`). */
  const [headModalEmployees, setHeadModalEmployees] = useState([])
  const [headModalLoading, setHeadModalLoading] = useState(false)
  const [headModalLoadError, setHeadModalLoadError] = useState(null)
  const headLoadSeqRef = useRef(0)

  const [assignOpen, setAssignOpen] = useState(false)
  const [assignDepartment, setAssignDepartment] = useState(null)
  const [assignIds, setAssignIds] = useState([])
  const [assignModalEmployees, setAssignModalEmployees] = useState([])
  const [assignSubmitting, setAssignSubmitting] = useState(false)
  const [assignFilter, setAssignFilter] = useState('available')
  const [assignDepartmentFilter, setAssignDepartmentFilter] = useState('all')

  const [deleteConfirmDept, setDeleteConfirmDept] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [previewOpen, setPreviewOpen] = useState(false)
  const [previewDept, setPreviewDept] = useState(null)
  /** Members loaded from API when opening the preview sheet (same source as View Employees). */
  const [previewMembers, setPreviewMembers] = useState([])
  const [previewMembersLoading, setPreviewMembersLoading] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')

  const [hoveredDept, setHoveredDept] = useState(null)
  const [hoveredRowRect, setHoveredRowRect] = useState(null)
  const hoverCardLeaveTimerRef = useRef(null)
  const [assignSearchQuery, setAssignSearchQuery] = useState('')
  const [assignModalLoading, setAssignModalLoading] = useState(false)

  const [sortCol, setSortCol] = useState('name')
  const [sortDir, setSortDir] = useState('asc')
  const [filterNoHead, setFilterNoHead] = useState(false)
  const [filterHasQr, setFilterHasQr] = useState(false)
  const [createDescription, setCreateDescription] = useState('')
  const [createBranchPickerOpen, setCreateBranchPickerOpen] = useState(false)

  const sortedBranchesForPicker = useMemo(
    () =>
      [...branches].sort((a, b) => {
        const ca = (a.company_name || '').localeCompare(b.company_name || '', undefined, { sensitivity: 'base' })
        if (ca !== 0) return ca
        return (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
      }),
    [branches]
  )

  const selectedCreateBranch = useMemo(
    () => branches.find((b) => String(b.id) === String(createBranchId)),
    [branches, createBranchId]
  )

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

  const fetchDepartments = useCallback(async () => {
    setError(null)
    try {
      const params = {}
      if (branchFilter) params.branch_id = branchFilter
      if (companyFilter) params.company_id = companyFilter
      const data = await getDepartments(params)
      setDepartments(data.departments || [])
    } catch (e) {
      setError(e.message)
      setDepartments([])
    } finally {
      setLoading(false)
    }
  }, [branchFilter, companyFilter])

  const fetchEmployees = useCallback(async () => {
    try {
      const data = await getEmployees()
      setEmployees(data.employees || [])
    } catch {
      setEmployees([])
    }
  }, [])

  const openDepartmentPreview = useCallback(async (dept) => {
    setPreviewDept(dept)
    setPreviewOpen(true)
    setPreviewMembers([])
    setPreviewMembersLoading(true)
    try {
      const data = await getDepartmentEmployees(dept.id)
      setPreviewMembers(data.employees || [])
    } catch {
      setPreviewMembers([])
    } finally {
      setPreviewMembersLoading(false)
    }
  }, [])

  // Run branches + departments + companies in parallel on mount; employees deferred until modal
  useEffect(() => {
    setLoading(true)
    Promise.all([fetchDepartments(), fetchBranches(), fetchCompanies()])
  }, []) // eslint-disable-line react-hooks/exhaustive-deps -- intentional one-time mount fetch

  // Re-fetch departments when filters change (after initial mount)
  const _deptsFirstRender = useState(true)
  useEffect(() => {
    if (_deptsFirstRender[0]) { _deptsFirstRender[0] = false; return }
    setLoading(true)
    fetchDepartments()
  }, [branchFilter, companyFilter]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const params = {}
    if (branchFilter) params.branch_id = branchFilter
    if (companyFilter) params.company_id = companyFilter
    setSearchParams(params, { replace: true })
  }, [branchFilter, companyFilter, setSearchParams])

  // Employees are heavy — only fetch when assign/head modal opens, and only once
  useEffect(() => {
    if ((headOpen || assignOpen) && employees.length === 0) fetchEmployees()
  }, [headOpen, assignOpen]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleCreate = async (e) => {
    e.preventDefault()
    const nameError = validateDepartmentName(createName)
    if (nameError) {
      setError(nameError)
      toast({ title: 'Invalid department name', description: nameError, variant: 'error' })
      return
    }
    if (!createBranchId) {
      toast({ title: 'Please select a branch', variant: 'error' })
      return
    }
    setCreateSubmitting(true)
    setError(null)
    try {
      const data = await createDepartment({
        name: createName.trim(),
        branch_id: parseInt(createBranchId, 10),
        office_location: createOfficeLocation.trim() || undefined,
      })
      const savedName = createName.trim()
      if (data?.department?.id != null) {
        setDepartments((prev) => {
          const id = String(data.department.id)
          if (!prev.some((d) => String(d.id) === id)) {
            const next = [...prev, data.department]
            next.sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' }))
            return next
          }
          return prev.map((d) => (String(d.id) === id ? { ...d, ...data.department } : d))
        })
      }
      setCreateName('')
      setCreateBranchId('')
      setCreateOfficeLocation('')
      setCreateDescription('')
      setCreateOpen(false)
      await fetchDepartments()
      toast({ title: `Department '${savedName}' created`, description: 'Assign a head next to complete the setup.', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to create department', description: e.message, variant: 'error' })
    } finally {
      setCreateSubmitting(false)
    }
  }

  const openViewEmployees = async (dept) => {
    setViewEmployeesDept(dept)
    setViewEmployeesOpen(true)
    setViewEmployeesList([])
    setViewEmployeesLoading(true)
    setUnassigningId(null)
    try {
      const data = await getDepartmentEmployees(dept.id)
      setViewEmployeesList(data.employees || [])
    } catch (e) {
      setViewEmployeesList([])
      toast({
        title: 'Could not load department members',
        description: e?.message || 'Try again or refresh the page.',
        variant: 'error',
      })
    } finally {
      setViewEmployeesLoading(false)
    }
  }

  const refreshViewEmployeesList = useCallback(async () => {
    if (!viewEmployeesDept) return
    try {
      const data = await getDepartmentEmployees(viewEmployeesDept.id)
      setViewEmployeesList(data.employees || [])
    } catch {
      setViewEmployeesList([])
    }
  }, [viewEmployeesDept])

  const handleUnassignFromView = (emp) => {
    if (!viewEmployeesDept) return
    setUnassignConfirm({ employee: emp, department: viewEmployeesDept, bulkIds: null })
  }

  const handleUnassignBulkFromView = () => {
    if (!viewEmployeesDept || viewEmployeesSelectedIds.length === 0) return
    setUnassignConfirm({ employee: null, department: viewEmployeesDept, bulkIds: [...viewEmployeesSelectedIds] })
  }

  const handleUnassignConfirm = async () => {
    if (!unassignConfirm) return
    const { employee, department, bulkIds } = unassignConfirm
    const idsToUnassign = bulkIds?.length ? bulkIds : [employee.id]
    setUnassigningId(bulkIds?.length ? 'bulk' : employee.id)
    setError(null)
    try {
      const data = await unassignEmployeesFromDepartment(department.id, idsToUnassign)
      setUnassignConfirm(null)
      setViewEmployeesSelectedIds((prev) =>
        prev.filter((id) => !idsToUnassign.some((u) => sameUserId(u, id)))
      )
      if (data?.department?.id != null) {
        setDepartments((prev) =>
          prev.map((d) => (sameUserId(d.id, data.department.id) ? { ...d, ...data.department } : d)),
        )
      }
      await refreshViewEmployeesList()
      await fetchDepartments()
      toast({
        title: idsToUnassign.length === 1
          ? `${employee?.name || 'Employee'} unassigned from ${department.name}`
          : `${idsToUnassign.length} employees unassigned from ${department.name}`,
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
    if (!viewEmployeesDept) return
    setViewEmployeesOpen(false)
    openAssignDialog(viewEmployeesDept)
  }

  const loadHeadCandidates = useCallback(async (dept) => {
    if (dept?.id == null) return
    headLoadSeqRef.current += 1
    const seq = headLoadSeqRef.current
    const resolvedDeptId = dept.id
    setHeadModalEmployees([])
    setHeadModalLoadError(null)
    setHeadModalLoading(true)
    try {
      const data = await getEmployees({
        department_id: resolvedDeptId,
        for_schedule_assignment: true,
        fresh: true,
      })
      if (seq !== headLoadSeqRef.current) return
      let list = (data.employees || []).filter((e) => {
        if (!isRosterStaffMember(e)) return false
        if (!sameUserId(e.department_id, resolvedDeptId)) return false
        const isCurrentHead =
          dept.department_head_id != null && dept.department_head_id !== '' && sameUserId(e.id, dept.department_head_id)
        if (!isCurrentHead && e.is_active === false) return false
        if (
          dept.branch_id != null &&
          dept.branch_id !== '' &&
          e.branch_id != null &&
          String(e.branch_id) !== String(dept.branch_id)
        ) {
          return false
        }
        if (
          dept.company_id != null &&
          dept.company_id !== '' &&
          e.company_id != null &&
          Number(e.company_id) !== Number(dept.company_id)
        ) {
          return false
        }
        return true
      })
      list.sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' }))
      setHeadModalEmployees(list)
    } catch (err) {
      if (seq !== headLoadSeqRef.current) return
      setHeadModalEmployees([])
      setHeadModalLoadError(err?.message || 'Could not load employees for this department.')
    } finally {
      if (seq === headLoadSeqRef.current) {
        setHeadModalLoading(false)
      }
    }
  }, [])

  const openHeadDialog = (dept) => {
    if (dept?.id == null) return
    setHeadDepartment(dept)
    setHeadId(dept.department_head_id ? String(dept.department_head_id) : '')
    setHeadModalEmployees([])
    setHeadModalLoadError(null)
    setHeadOpen(true)
    void loadHeadCandidates(dept)
  }

  const handleAssignHead = async (e) => {
    e.preventDefault()
    if (!headDepartment) return
    setHeadSubmitting(true)
    setError(null)
    try {
      const data = await updateDepartment(headDepartment.id, {
        department_head_id: headId ? parseInt(headId, 10) : null,
      })
      if (data?.department?.id != null) {
        setDepartments((prev) =>
          prev.map((d) => (sameUserId(d.id, data.department.id) ? { ...d, ...data.department } : d)),
        )
      }
      setHeadOpen(false)
      setHeadDepartment(null)
      setHeadModalEmployees([])
      setHeadModalLoadError(null)
      await fetchDepartments()
      await fetchEmployees()
      toast({ title: 'Department head updated', variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Cannot assign head', description: e.message, variant: 'error' })
    } finally {
      setHeadSubmitting(false)
    }
  }

  const openAssignDialog = async (dept) => {
    setAssignDepartment(dept)
    setAssignOpen(true)
    setAssignFilter('available')
    setAssignSearchQuery('')
    setAssignDepartmentFilter('all')
    setAssignModalEmployees([])
    setAssignModalLoading(true)
    try {
      const params = { per_page: 100, for_department_assignment: true }
      const companyId = dept?.company_id ?? dept?.branch?.company_id
      if (companyId) params.assignable_to_company_id = companyId
      if (dept?.branch_id != null && dept.branch_id !== '') params.assignment_branch_id = dept.branch_id
      const data = await getEmployees(params)
      const list = data.employees || []
      setAssignModalEmployees(list)
      const inDept = list.filter((emp) => String(emp.department_id ?? '') === String(dept.id)).map((e) => e.id)
      setAssignIds(inDept)
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
    if (!assignDepartment) return
    setAssignSubmitting(true)
    setError(null)
    try {
      const data = await assignEmployeesToDepartment(assignDepartment.id, assignIds)
      if (data?.department?.id != null) {
        setDepartments((prev) =>
          prev.map((d) => (sameUserId(d.id, data.department.id) ? { ...d, ...data.department } : d)),
        )
      }
      setAssignOpen(false)
      setAssignDepartment(null)
      setAssignModalEmployees([])
      await fetchDepartments()
      await fetchEmployees()
      toast({ title: 'Employees assigned', description: assignDepartment.name, variant: 'success' })
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to assign employees', description: e.message, variant: 'error' })
    } finally {
      setAssignSubmitting(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteConfirmDept) return
    setDeleteSubmitting(true)
    setError(null)
    try {
      await deleteDepartment(deleteConfirmDept.id)
      setDeleteConfirmDept(null)
      await fetchDepartments()
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to delete department', description: e.message, variant: 'error' })
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

  /** Company Heads and Branch Managers cannot be assigned to departments — omit from pool entirely */
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

  const assignRows = useMemo(() => {
    return assignList
      .filter((e) => isRosterStaffMember(e))
      .filter((emp) => !isExcludedFromAssignPool(emp))
      .filter((emp) => {
        const q = assignSearchQuery.trim().toLowerCase()
        if (!q) return true
        const haystack = `${emp.name || ''} ${emp.employee_code || ''} ${emp.email || ''} ${emp.position || ''} ${emp.department || ''} ${emp.management_role || ''}`.toLowerCase()
        return haystack.includes(q)
      })
      .filter((emp) => {
        const departmentLabel = emp.department || 'Unassigned'
        if (assignDepartmentFilter !== 'all' && departmentLabel !== assignDepartmentFilter) return false
        const assignedToCurrent =
          String(emp.department_id ?? '') === String(assignDepartment?.id ?? '')
        const assignedElsewhere = (emp.department_id != null && emp.department_id !== '') && !assignedToCurrent
        const isInactive = !emp.is_active
        const status = assignedToCurrent ? 'assigned' : (isInactive || assignedElsewhere ? 'unavailable' : 'available')
        if (assignFilter === 'available') return status === 'available'
        if (assignFilter === 'assigned') return status === 'assigned'
        return true
      })
      .map((emp) => {
        const assignedToCurrent =
          String(emp.department_id ?? '') === String(assignDepartment?.id ?? '')
        const assignedElsewhere = (emp.department_id != null && emp.department_id !== '') && !assignedToCurrent
        const isInactive = !emp.is_active
        const status = assignedToCurrent
          ? 'assigned'
          : (isInactive || assignedElsewhere ? 'unavailable' : 'available')
        const checked = assignedToCurrent || assignIds.some((id) => sameUserId(id, emp.id))
        const checkboxDisabled = status !== 'available'
        return { emp, status, checked, checkboxDisabled, isInactive, assignedElsewhere }
      })
  }, [
    assignList,
    assignSearchQuery,
    assignDepartmentFilter,
    assignDepartment,
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

  /** Employees in the selection who are not yet in this department (for footer hint). */
  const assignNewToDeptCount = useMemo(() => {
    if (!assignDepartment) return 0
    return assignIds.filter((id) => {
      const emp = assignList.find((e) => sameUserId(e.id, id))
      return emp && String(emp.department_id ?? '') !== String(assignDepartment.id)
    }).length
  }, [assignIds, assignList, assignDepartment])

  const assignFooterStats = useMemo(() => {
    if (!assignDepartment) return { current: 0, newlyAdded: 0, afterSave: 0 }
    const current = assignList.filter((e) => String(e.department_id) === String(assignDepartment.id)).length
    const newlyAdded = selectedEmployeesPreview.filter(
      (e) => String(e.department_id ?? '') !== String(assignDepartment.id)
    ).length
    return { current, newlyAdded, afterSave: current + newlyAdded }
  }, [assignDepartment, assignList, selectedEmployeesPreview])

  const assignCounts = useMemo(() => {
    let available = 0, assigned = 0, unavailable = 0
    assignList
      .filter((e) => isRosterStaffMember(e))
      .filter((emp) => !isExcludedFromAssignPool(emp))
      .forEach((emp) => {
        const assignedToCurrent = String(emp.department_id ?? '') === String(assignDepartment?.id ?? '')
        const assignedElsewhere = (emp.department_id != null && emp.department_id !== '') && !assignedToCurrent
        const isInactive = !emp.is_active
        const status = assignedToCurrent ? 'assigned' : (isInactive || assignedElsewhere ? 'unavailable' : 'available')
        if (status === 'available') available++
        else if (status === 'assigned') assigned++
        else unavailable++
      })
    return { available, assigned, unavailable, total: available + assigned + unavailable }
  }, [assignList, assignDepartment, isExcludedFromAssignPool])

  const toggleSelectAllAssignable = () => {
    setAssignIds((prev) => {
      if (assignSelectableIds.length === 0) return prev
      if (allSelectableChecked) {
        return prev.filter((id) => !assignSelectableIds.some((sid) => sameUserId(id, sid)))
      }
      return Array.from(new Set([...prev, ...assignSelectableIds]))
    })
  }

  const filteredDepts = useMemo(() => {
    let list = [...departments]
    const q = searchQuery.trim().toLowerCase()
    if (q) {
      list = list.filter(
        (d) =>
          (d.name || '').toLowerCase().includes(q) ||
          (d.department_head_name || '').toLowerCase().includes(q)
      )
    }
    if (filterNoHead) list = list.filter((d) => !d.department_head_id)
    if (filterHasQr) {
      list = list.filter((d) => employees.some((e) => sameUserId(e.department_id, d.id) && e.has_qr))
    }
    list.sort((a, b) => {
      if (sortCol === 'total') {
        const aT = employees.filter((e) => sameUserId(e.department_id, a.id)).length || a.total_employees || 0
        const bT = employees.filter((e) => sameUserId(e.department_id, b.id)).length || b.total_employees || 0
        return sortDir === 'asc' ? aT - bT : bT - aT
      }
      const an = (a.name || '').toLowerCase()
      const bn = (b.name || '').toLowerCase()
      return sortDir === 'asc' ? an.localeCompare(bn) : bn.localeCompare(an)
    })
    return list
  }, [departments, searchQuery, filterNoHead, filterHasQr, employees, sortCol, sortDir])

  function toggleSort(col) {
    if (sortCol === col) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    else { setSortCol(col); setSortDir('asc') }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Departments</h2>
          <CardDescription>Organize departments by branch. Create departments, assign heads, and assign employees.</CardDescription>
        </div>
        <Button
          onClick={() => {
            setCreateOpen(true)
            setCreateName('')
            setCreateBranchId(branchFilter || '')
            setCreateOfficeLocation('')
            setCreateDescription('')
            setError(null)
          }}
        >
          <Plus className="size-4 mr-2" />
          Create Department
        </Button>
      </div>

      {error && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <Card className="overflow-hidden border border-border/60 bg-card shadow-md dark:border-border/50">
        <CardHeader className="border-b border-border/40 bg-muted/20 dark:border-border/50">
          <div className="flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
            <div>
              <CardTitle className="text-lg font-semibold">Department Directory</CardTitle>
              <CardDescription>
                {filteredDepts.length} of {departments.length} department(s)
              </CardDescription>
            </div>
            <div className="relative w-full @md:w-64">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="text"
                placeholder="Search departments..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="h-9 pl-8 text-sm"
              />
            </div>
          </div>

          {/* Filter chips */}
          <div className="flex flex-wrap items-center gap-2 pt-1">
            <span className="text-xs text-muted-foreground">Branch:</span>
            <select
              className={cn('min-w-[160px] text-xs', FIELD_SELECT_CLASS_H8)}
              value={branchFilter}
              onChange={(e) => { setBranchFilter(e.target.value); setLoading(true) }}
            >
              <option value="">All branches</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>{b.name}{b.company_name ? ` (${b.company_name})` : ''}</option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => setFilterNoHead((v) => !v)}
              className={[
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                filterNoHead
                  ? 'border-amber-400/50 bg-amber-500/15 text-amber-600 dark:text-amber-400'
                  : 'border-border/50 bg-muted/30 text-muted-foreground hover:text-foreground dark:border-white/8',
              ].join(' ')}
            >
              <UserPlus className="size-3" />
              No head assigned
              {filterNoHead && <X className="size-3 ml-0.5" />}
            </button>
            <button
              type="button"
              onClick={() => setFilterHasQr((v) => !v)}
              className={[
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                filterHasQr
                  ? 'border-sky-400/50 bg-sky-500/15 text-sky-600 dark:text-sky-400'
                  : 'border-border/50 bg-muted/30 text-muted-foreground hover:text-foreground dark:border-white/8',
              ].join(' ')}
            >
              <QrCode className="size-3" />
              Has QR
              {filterHasQr && <X className="size-3 ml-0.5" />}
            </button>
            {(filterNoHead || filterHasQr || searchQuery || branchFilter || companyFilter) && (
              <button
                type="button"
                onClick={() => { setFilterNoHead(false); setFilterHasQr(false); setSearchQuery(''); setBranchFilter(''); setCompanyFilter('') }}
                className="inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <X className="size-3" />Clear
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
          ) : departments.length === 0 ? (
            <div className="flex flex-col items-center gap-4 py-16 px-6 text-center">
              <div className="flex size-16 items-center justify-center rounded-2xl border border-border/50 bg-muted/30 dark:border-border/50 dark:bg-muted/40">
                <Building2 className="size-8 text-muted-foreground/50" />
              </div>
              <div className="space-y-1">
                <p className="font-semibold text-foreground">No departments yet</p>
                <p className="text-sm text-muted-foreground">Create your first department to start organizing employees, assigning heads, and tracking QR coverage.</p>
              </div>
              <Button
                size="sm"
                onClick={() => {
                  setCreateOpen(true)
                  setCreateName('')
                  setCreateBranchId(branchFilter || '')
                  setCreateOfficeLocation('')
                  setCreateDescription('')
                  setError(null)
                }}
              >
                <Plus className="mr-1.5 size-4" />
                Create first department
              </Button>
            </div>
          ) : filteredDepts.length === 0 ? (
            <p className="py-10 text-center text-sm text-muted-foreground">No companies match your filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border/40 bg-muted/40 dark:border-border/50 dark:bg-muted/30">
                    <th className="w-20 px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Logo
                    </th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      <button
                        type="button"
                        onClick={() => toggleSort('name')}
                        className="inline-flex items-center gap-1 hover:text-foreground transition-colors"
                      >
                        Department
                        {sortCol === 'name'
                          ? (sortDir === 'asc' ? <ChevronUp className="size-3" /> : <ChevronDown className="size-3" />)
                          : <ChevronUp className="size-3 opacity-30" />}
                      </button>
                    </th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Branch
                    </th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Company
                    </th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Head
                    </th>
                    <th className="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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
                    <th className="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/30 dark:divide-white/5">
                  {filteredDepts.map((dept, idx) => {
                    const stats = (() => {
                      let total = 0, active = 0, inactive = 0, withQr = 0, schedulesAssigned = 0
                      employees.forEach((emp) => {
                        if (sameUserId(emp.department_id, dept.id)) {
                          total += 1
                          if (emp.is_active) active += 1
                          else inactive += 1
                          if (emp.has_qr) withQr += 1
                          if (hasWorkingDays(emp.schedule)) schedulesAssigned += 1
                        }
                      })
                      return { total: total || (dept.total_employees ?? 0), active, inactive, withQr, schedulesAssigned }
                    })()

                    const deptInitials = (dept.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'
                    const qrPct = stats.total > 0 ? Math.round((stats.withQr / stats.total) * 100) : 0
                    const qrBarColor = qrPct === 100 ? '#10b981' : qrPct >= 50 ? '#f59e0b' : '#64748b'

                    return (
                      <tr
                        key={dept.id}
                        onClick={() => openDepartmentPreview(dept)}
                        onMouseEnter={(e) => {
                          if (hoverCardLeaveTimerRef.current) clearTimeout(hoverCardLeaveTimerRef.current)
                          setHoveredDept(dept)
                          setHoveredRowRect(e.currentTarget.getBoundingClientRect())
                        }}
                        onMouseLeave={() => {
                          hoverCardLeaveTimerRef.current = setTimeout(() => {
                            setHoveredDept(null); setHoveredRowRect(null)
                          }, 100)
                        }}
                        className={[
                          'group cursor-pointer transition-all hover:bg-muted/30 dark:hover:bg-muted/40 dark:hover:shadow-[inset_3px_0_0_rgba(20,184,166,0.45)]',
                          idx % 2 === 0 ? 'bg-white dark:bg-card' : 'bg-muted/30 dark:bg-muted/25',
                        ].join(' ')}
                      >
                        {/* Logo */}
                        <td className="px-5 py-4 align-middle">
                          {departmentLogoUrl(dept) ? (
                            <div className="flex h-12 w-12 items-center justify-center overflow-hidden rounded-xl border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/4">
                              <img src={departmentLogoUrl(dept)} alt="" className="h-10 w-10 rounded-lg object-cover" key={dept.logo || dept.id} />
                            </div>
                          ) : (
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-border/60 bg-gradient-to-br from-muted to-muted/40 dark:border-border/50 dark:from-muted/50 dark:to-card">
                              <span className="text-xs font-bold text-muted-foreground">{deptInitials}</span>
                            </div>
                          )}
                        </td>

                        {/* Department name + date */}
                        <td className="px-5 py-4 align-middle">
                          <div className="space-y-0.5">
                            <p className="font-semibold text-foreground">{dept.name}</p>
                            {dept.office_location && (
                              <p className="text-[11px] text-muted-foreground/80">{dept.office_location}</p>
                            )}
                            {dept.created_at && (
                              <p className="text-[11px] text-muted-foreground/60">
                                Created {relativeDate(dept.created_at)}
                              </p>
                            )}
                          </div>
                        </td>

                        {/* Branch */}
                        <td className="px-5 py-4 align-middle">
                          {dept.branch_name ? (
                            <span className="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-300 dark:border-blue-800">
                              <GitBranch className="size-3" />{dept.branch_name}
                            </span>
                          ) : (
                            <span className="text-xs italic text-muted-foreground/50">—</span>
                          )}
                        </td>

                        {/* Company */}
                        <td className="px-5 py-4 align-middle">
                          {dept.company_name ? (
                            <span className="text-sm text-foreground">{dept.company_name}</span>
                          ) : (
                            <span className="text-xs italic text-muted-foreground/50">—</span>
                          )}
                        </td>

                        {/* Head */}
                        <td className="px-5 py-4 align-middle">
                          {dept.department_head_name ? (
                            <div className="flex items-center gap-2">
                              <Avatar className="size-7 shrink-0">
                                <AvatarImage
                                  src={profileImageUrl(dept.department_head_profile_image)}
                                  alt={dept.department_head_name}
                                />
                                <AvatarFallback className="bg-teal-500/20 text-[10px] font-bold text-teal-700 dark:text-teal-300">
                                  {dept.department_head_name.trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)}
                                </AvatarFallback>
                              </Avatar>
                              <span className="max-w-[140px] truncate text-sm text-foreground">{dept.department_head_name}</span>
                            </div>
                          ) : (
                            <span className="text-xs italic text-muted-foreground/50">Not assigned</span>
                          )}
                        </td>

                        {/* Employee stats + QR bar */}
                        <td className="px-5 py-4 align-middle">
                          <div className="space-y-1.5">
                            <p className="text-[13px] font-semibold text-foreground">
                              {stats.total} {stats.total === 1 ? 'employee' : 'employees'}
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                <span className="size-1.5 rounded-full bg-emerald-500" />{stats.active} active
                              </span>
                              {stats.inactive > 0 && (
                                <span className="inline-flex items-center gap-1 text-[11px] font-medium text-rose-600 dark:text-rose-400">
                                  <span className="size-1.5 rounded-full bg-rose-500" />{stats.inactive} inactive
                                </span>
                              )}
                              <span className="inline-flex items-center gap-1 text-[11px] font-medium text-sky-600 dark:text-sky-400">
                                <span className="size-1.5 rounded-full bg-sky-500" />{stats.withQr} QR
                              </span>
                            </div>
                            {stats.total > 0 && (
                              <div className="flex items-center gap-1.5">
                                <div
                                  className="h-1 w-16 overflow-hidden rounded-full bg-border/40 dark:bg-white/8"
                                  title={`QR issuance: ${stats.withQr} of ${stats.total} employees have QR codes for attendance check-in`}
                                >
                                  <div
                                    className="h-full rounded-full transition-all"
                                    style={{ width: `${qrPct}%`, background: qrBarColor }}
                                  />
                                </div>
                                <span
                                  className="text-[10px] tabular-nums text-muted-foreground cursor-default"
                                  title={`${stats.withQr} of ${stats.total} employees issued QR codes`}
                                >
                                  {qrPct}% QR
                                </span>
                              </div>
                            )}
                          </div>
                        </td>

                        {/* Actions — inline quick actions + kebab */}
                        <td className="px-4 py-4" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            {/* Quick action buttons — visible on row hover */}
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-7 gap-1 px-2.5 text-xs opacity-0 transition-opacity group-hover:opacity-100 text-muted-foreground hover:text-foreground dark:hover:bg-white/8"
                              onClick={(e) => { e.stopPropagation(); openAssignDialog(dept) }}
                              title="Assign employees to this department"
                            >
                              <UserPlus className="size-3.5" />
                              Assign
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-7 gap-1 px-2.5 text-xs opacity-0 transition-opacity group-hover:opacity-100 text-muted-foreground hover:text-foreground dark:hover:bg-white/8"
                              onClick={(e) => { e.stopPropagation(); openViewEmployees(dept) }}
                              title="View this department's employees"
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
                                  className="size-8 rounded-full opacity-0 transition-opacity group-hover:opacity-100 hover:opacity-100 dark:hover:bg-white/8"
                                  aria-label="More actions"
                                  onClick={(e) => e.stopPropagation()}
                                >
                                  <MoreVertical className="size-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuItem onClick={() => openViewEmployees(dept)}>
                                  <Eye className="size-4" /><span>View employees</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openHeadDialog(dept)}>
                                  <UserPlus className="size-4" /><span>Assign head</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => openAssignDialog(dept)}>
                                  <Users className="size-4" /><span>Assign employees</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" onClick={() => setDeleteConfirmDept(dept)}>
                                  <Trash2 className="size-4" /><span>Delete department</span>
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

              {/* Pagination hint */}
              {filteredDepts.length > 0 && (
                <div className="border-t border-border/30 px-5 py-2.5 text-xs text-muted-foreground dark:border-white/5">
                  Showing {filteredDepts.length} of {departments.length} {departments.length === 1 ? 'department' : 'departments'}
                </div>
              )}

              {hoveredDept && hoveredRowRect && (() => {
                const dept = hoveredDept
                let total = 0, active = 0, inactive = 0, withQr = 0, schedulesAssigned = 0
                employees.forEach((emp) => {
                  if (sameUserId(emp.department_id, dept.id)) {
                    total += 1
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
                    onMouseLeave={() => { setHoveredDept(null); setHoveredRowRect(null) }}
                  >
                    <p className="mb-2 font-semibold text-foreground">{dept.name}</p>
                    {dept.created_at && (
                      <p className="mb-2 text-[11px] text-muted-foreground">Created {relativeDate(dept.created_at)} · {new Date(dept.created_at).toLocaleDateString()}</p>
                    )}
                    <dl className="space-y-1 text-muted-foreground">
                      <div className="flex justify-between gap-4"><dt>Total:</dt><dd className="font-medium text-foreground tabular-nums">{total}</dd></div>
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

      {/* Department details drawer — row click */}
      <Sheet
        open={previewOpen}
        onOpenChange={(open) => {
          setPreviewOpen(open)
          if (!open) {
            setPreviewDept(null)
            setPreviewMembers([])
          }
        }}
      >
        <SheetContent side="right" className="flex w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-md">
          <SheetHeader className="border-b border-border/50 bg-muted/30 px-6 py-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Department Profile</p>
            {previewDept && (
              <div className="flex items-center gap-4">
                {departmentLogoUrl(previewDept) ? (
                  <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40">
                    <img src={departmentLogoUrl(previewDept)} alt="" className="h-12 w-12 rounded-md object-cover" key={previewDept.logo || previewDept.id} />
                  </div>
                ) : (
                  <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/60">
                    <span className="text-sm font-semibold text-muted-foreground">
                      {(previewDept.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'}
                    </span>
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <SheetTitle className="text-lg font-semibold tracking-tight text-foreground">
                    {previewDept.name}
                  </SheetTitle>
                  <SheetDescription className="mt-0.5 text-sm text-muted-foreground">
                    {previewDept.department_head_name ? `Head: ${previewDept.department_head_name}` : 'Head: Not assigned'}
                  </SheetDescription>
                </div>
              </div>
            )}
          </SheetHeader>
          {previewDept && (
            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
              <div className="space-y-4">
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Company logo</h3>
                  <p className="mb-2 text-[11px] text-muted-foreground">Departments inherit the logo from their Company.</p>
                  <div className="flex items-center gap-3">
                    {departmentLogoUrl(previewDept) ? (
                      <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40">
                        <img src={departmentLogoUrl(previewDept)} alt="" className="h-12 w-12 rounded-md object-cover" key={previewDept.logo || previewDept.id} />
                      </div>
                    ) : (
                      <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/60">
                        <span className="text-sm font-semibold text-muted-foreground">
                          {(previewDept.name || '').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2) || '—'}
                        </span>
                      </div>
                    )}
                  </div>
                </section>
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Department Head</h3>
                  <p className="text-sm font-medium text-foreground">
                    {previewDept.department_head_name || 'Not assigned'}
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    className="mt-2 h-8 text-xs"
                    onClick={() => {
                      setPreviewOpen(false)
                      openHeadDialog(previewDept)
                    }}
                  >
                    <UserPlus className="size-3.5 mr-1.5" />
                    Assign head
                  </Button>
                </section>
                <section className="rounded-lg border border-border/50 bg-muted/20 px-4 py-3">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Employees</h3>
                  {(() => {
                    const deptEmployeesCached = employees.filter((e) => sameUserId(e.department_id, previewDept.id))
                    const memberCount =
                      previewMembersLoading
                        ? null
                        : previewMembers.length > 0
                          ? previewMembers.length
                          : (previewDept.total_employees ?? deptEmployeesCached.length)
                    const showDetailStats = deptEmployeesCached.length > 0
                    const active = showDetailStats ? deptEmployeesCached.filter((e) => e.is_active).length : null
                    const withQr = showDetailStats ? deptEmployeesCached.filter((e) => e.has_qr).length : null
                    return (
                      <>
                        <p className="text-sm font-medium text-foreground">
                          {previewMembersLoading ? (
                            <span className="inline-flex items-center gap-2 text-muted-foreground">
                              <Loader2 className="size-4 animate-spin" />
                              Loading members…
                            </span>
                          ) : (
                            <>
                              {memberCount} member{memberCount === 1 ? '' : 's'}
                            </>
                          )}
                        </p>
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
                            Active: {active} • Inactive: {deptEmployeesCached.length - active} • With QR: {withQr}
                          </p>
                        )}
                        {!showDetailStats && !previewMembersLoading && memberCount > 0 && (
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
                            openViewEmployees(previewDept)
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
                  {previewDept.created_at && (
                    <p className="text-sm text-muted-foreground">
                      Created {new Date(previewDept.created_at).toLocaleDateString()}
                    </p>
                  )}
                  {!previewDept.created_at && (
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

      {/* Create Department */}
      <Dialog
        open={createOpen}
        onOpenChange={(open) => {
          setCreateOpen(open)
          if (!open) setCreateBranchPickerOpen(false)
        }}
      >
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="dept-create-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <Building2 className="size-5 text-foreground dark:text-foreground" />
                Create Department
              </DialogTitle>
              <p id="dept-create-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Add a new department. Name is required — everything else is optional.
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleCreate} className="flex min-h-0 flex-1 flex-col text-foreground dark:text-zinc-50">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-3">
                <div className="space-y-1.5">
                  <Label htmlFor="dept-name">Department name <span className="text-destructive">*</span></Label>
                  <Input
                    id="dept-name"
                    value={createName}
                    onChange={(e) => setCreateName(e.target.value)}
                    placeholder="e.g. Engineering"
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <Label id="create-branch-picker-label">Branch *</Label>
                  <Popover open={createBranchPickerOpen} onOpenChange={setCreateBranchPickerOpen}>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        id="create-branch-picker"
                        aria-labelledby="create-branch-picker-label"
                        aria-expanded={createBranchPickerOpen}
                        className={cn(
                          FIELD_SELECT_CLASS_H10,
                          'h-auto min-h-10 w-full justify-between gap-2 py-2 font-normal hover:bg-transparent'
                        )}
                      >
                        {selectedCreateBranch ? (
                          <span className="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <Avatar className="size-8 shrink-0 rounded-md border border-border/50">
                              <AvatarImage src={selectedCreateBranch.logo_url || undefined} alt="" />
                              <AvatarFallback className="rounded-md text-[10px]">
                                {initials(selectedCreateBranch.company_name || selectedCreateBranch.name)}
                              </AvatarFallback>
                            </Avatar>
                            <span className="flex min-w-0 flex-col items-start">
                              <span className="w-full truncate text-sm font-medium leading-tight">{selectedCreateBranch.name}</span>
                              {selectedCreateBranch.company_name ? (
                                <span className="w-full truncate text-xs text-muted-foreground">{selectedCreateBranch.company_name}</span>
                              ) : null}
                            </span>
                          </span>
                        ) : (
                          <span className="text-muted-foreground">Select branch</span>
                        )}
                        <ChevronDown className="size-4 shrink-0 opacity-50" aria-hidden />
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent
                      align="start"
                      className="w-[var(--radix-popover-trigger-width)] min-w-[min(100vw-2rem,24rem)] p-0"
                      onOpenAutoFocus={(e) => e.preventDefault()}
                    >
                      {sortedBranchesForPicker.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">No branches available. Create a branch first.</p>
                      ) : (
                        <div className="max-h-[min(60vh,320px)] overflow-y-auto p-1">
                          {sortedBranchesForPicker.map((b) => {
                            const selected = String(createBranchId) === String(b.id)
                            return (
                              <button
                                key={b.id}
                                type="button"
                                onClick={() => {
                                  setCreateBranchId(String(b.id))
                                  setCreateBranchPickerOpen(false)
                                }}
                                className={cn(
                                  'flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-muted',
                                  selected && 'bg-muted'
                                )}
                              >
                                <Avatar className="size-9 shrink-0 rounded-md border border-border/50">
                                  <AvatarImage src={b.logo_url || undefined} alt="" />
                                  <AvatarFallback className="rounded-md text-[10px]">{initials(b.company_name || b.name)}</AvatarFallback>
                                </Avatar>
                                <span className="min-w-0 flex-1">
                                  <span className="block truncate font-medium leading-tight">{b.name}</span>
                                  {b.company_name ? (
                                    <span className="block truncate text-xs text-muted-foreground">{b.company_name}</span>
                                  ) : null}
                                </span>
                                {selected ? <Check className="size-4 shrink-0 text-primary" aria-hidden /> : null}
                              </button>
                            )
                          })}
                        </div>
                      )}
                    </PopoverContent>
                  </Popover>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="dept-office-location">Office location (optional)</Label>
                  <Input
                    id="dept-office-location"
                    value={createOfficeLocation}
                    onChange={(e) => setCreateOfficeLocation(e.target.value)}
                    placeholder="e.g. Makati HQ, Floor 3"
                    maxLength={255}
                  />
                </div>
            </div>

            {/* Description */}
            <div className="space-y-1.5">
              <Label htmlFor="dept-description">Description <span className="text-xs font-normal text-muted-foreground">(optional)</span></Label>
              <textarea
                id="dept-description"
                value={createDescription}
                onChange={(e) => setCreateDescription(e.target.value)}
                placeholder="Brief description of this department's role or scope…"
                rows={2}
                maxLength={500}
                className={cn(FIELD_TEXTAREA_CLASS_SM, '!min-h-[60px]')}
              />
            </div>
            </div>

            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button
                type="button"
                variant="outline"
                className="dark:border-white/10 dark:text-slate-300"
                onClick={() => setCreateOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={createSubmitting || !createName.trim()} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {createSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4 mr-1" />}
                Create Department
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
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD, 'max-h-[min(92vh,85vh)]')}
          aria-describedby="dept-view-employees-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>View Employees</DialogTitle>
              <p id="dept-view-employees-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {viewEmployeesDept && (
                  <>Employees in <strong>{viewEmployeesDept.name}</strong>. Unassign or assign more below.</>
                )}
              </p>
            </DialogHeader>
          </div>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'flex min-h-0 flex-1 flex-col py-2')}>
            {viewEmployeesLoading ? (
              <div className="flex justify-center py-8">
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
              </div>
            ) : viewEmployeesList.length === 0 ? (
              <p className="text-center text-muted-foreground py-6">No employees assigned to this department.</p>
            ) : (
              <>
                {/* Select-all row */}
                <div className="flex items-center gap-3 rounded-lg border border-border/50 bg-muted/30 dark:bg-white/5 px-3 py-2 mb-2">
                  <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground select-none">
                    <input
                      type="checkbox"
                      checked={
                        viewEmployeesList.length > 0 &&
                        viewEmployeesList.every((emp) =>
                          viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                        )
                      }
                      onChange={toggleViewEmployeesSelectAll}
                      className="rounded border-input accent-primary"
                    />
                    {viewEmployeesList.length > 0 &&
                    viewEmployeesList.every((emp) =>
                      viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                    )
                      ? 'Deselect all'
                      : 'Select all'}
                  </label>
                  {viewEmployeesSelectedIds.length > 0 && (
                    <span className="text-xs text-muted-foreground tabular-nums">
                      {viewEmployeesSelectedIds.length} selected
                    </span>
                  )}
                </div>
                <ul className="space-y-2">
                  {viewEmployeesList.map((emp) => (
                    <li
                      key={emp.id}
                      className={`flex items-center gap-3 rounded-lg border px-3 py-2 transition-colors ${
                        viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))
                          ? 'border-primary/50 bg-primary/5 dark:bg-primary/10'
                          : 'border-border/60'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={viewEmployeesSelectedIds.some((sid) => sameUserId(sid, emp.id))}
                        onChange={() => toggleViewEmployeeSelection(emp.id)}
                        className="rounded border-input accent-primary shrink-0"
                      />
                      <Avatar className="size-10 shrink-0">
                        <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" />
                        <AvatarFallback className="text-sm">{initials(emp.name)}</AvatarFallback>
                      </Avatar>
                      <span className="font-medium flex-1 min-w-0 truncate">{emp.name}</span>
                      <Button
                        variant="outline"
                        size="sm"
                        className="shrink-0 gap-1.5"
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
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'flex-wrap')}>
            {viewEmployeesSelectedIds.length > 0 && (
              <Button
                variant="destructive"
                size="sm"
                disabled={!!unassigningId}
                onClick={handleUnassignBulkFromView}
                className="mr-auto"
              >
                {unassigningId === 'bulk' ? <Loader2 className="size-4 animate-spin mr-2" /> : <UserMinus className="size-4 mr-2" />}
                Unassign selected ({viewEmployeesSelectedIds.length})
              </Button>
            )}
            <Button variant="outline" onClick={() => setViewEmployeesOpen(false)}>
              Close
            </Button>
            {viewEmployeesDept && (
              <Button
                type="button"
                onClick={openAssignFromViewModal}
                className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
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
                      <strong>{unassignConfirm.department.name}</strong>?
                    </>
                  ) : (
                    <>
                      Are you sure you want to unassign <strong>{unassignConfirm.employee?.name}</strong> from{' '}
                      <strong>{unassignConfirm.department.name}</strong>?
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

      {/* Assign Department Head */}
      <Dialog
        open={headOpen}
        onOpenChange={(open) => {
          setHeadOpen(open)
          if (!open) {
            headLoadSeqRef.current += 1
            setHeadDepartment(null)
            setHeadId('')
            setHeadModalEmployees([])
            setHeadModalLoading(false)
            setHeadModalLoadError(null)
          }
        }}
      >
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="dept-head-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Assign Department Head</DialogTitle>
              <p id="dept-head-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {headDepartment && (
                  <>Select the head for <strong>{headDepartment.name}</strong>.</>
                )}
              </p>
            </DialogHeader>
          </div>
          <form onSubmit={handleAssignHead} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-4')}>
            <div className="space-y-2">
              <Label>Department Head</Label>
              {headId !== '' && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 border-destructive/40 text-destructive hover:bg-destructive/10"
                  onClick={() => setHeadId('')}
                >
                  <UserMinus className="mr-1.5 size-3.5" />
                  Remove employee
                </Button>
              )}
              {headModalLoading ? (
                <div className="flex items-center justify-center gap-2 rounded-lg border border-border/60 py-10 text-sm text-muted-foreground dark:border-white/10">
                  <Loader2 className="size-4 animate-spin shrink-0 text-teal-600" />
                  Loading department members…
                </div>
              ) : headModalLoadError ? (
                <div className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                  <p>{headModalLoadError}</p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-3"
                    onClick={() => headDepartment && loadHeadCandidates(headDepartment)}
                  >
                    Try again
                  </Button>
                </div>
              ) : (() => {
                const listEmps = headModalEmployees
                const currentHeadId = headDepartment?.department_head_id

                const ineligibleReason = new Map()
                for (const c of companies) {
                  if (c.company_head_id) ineligibleReason.set(String(c.company_head_id), `Company Head — ${c.name || 'Company'}`)
                }
                for (const b of branches) {
                  if (b.branch_manager_id) ineligibleReason.set(String(b.branch_manager_id), `Branch Manager — ${b.name || 'Branch'}`)
                }
                for (const d of departments) {
                  if (d.department_head_id && d.id !== headDepartment?.id) {
                    ineligibleReason.set(String(d.department_head_id), `Dept Head — ${d.name || 'Department'}`)
                  }
                }
                const targetCompanyId = headDepartment?.company_id
                if (targetCompanyId) {
                  for (const emp of listEmps) {
                    const empId = String(emp.id)
                    if (!ineligibleReason.has(empId) && emp.company_id && Number(emp.company_id) !== Number(targetCompanyId)) {
                      const parts = [emp.company_name, emp.branch_name, emp.department].filter(Boolean)
                      ineligibleReason.set(empId, `Assigned: ${parts.join(' → ') || 'another company'}`)
                    }
                  }
                }

                return (
                  <div className="max-h-64 overflow-y-auto rounded-lg border border-border/60 dark:border-white/10">
                    <label className={[
                      'flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/40 dark:hover:bg-white/5',
                      headId === '' ? 'bg-muted/30 dark:bg-white/5' : '',
                    ].join(' ')}>
                      <input
                        type="radio"
                        name="head-select"
                        value=""
                        checked={headId === ''}
                        onChange={() => setHeadId('')}
                        className="accent-teal-500"
                      />
                      <span className="flex size-8 items-center justify-center rounded-full border border-dashed border-border/60 dark:border-white/15">
                        <UserMinus className="size-3.5 text-muted-foreground" />
                      </span>
                      <span className="text-sm text-muted-foreground italic">— Remove head —</span>
                    </label>

                    {listEmps.length === 0 && (
                      <p className="px-3 py-4 text-center text-sm text-muted-foreground">
                        No employees assigned to this department yet.
                      </p>
                    )}

                    {listEmps.map((emp) => {
                      const isCurrentHead = String(emp.id) === String(currentHeadId)
                      const reason = ineligibleReason.get(String(emp.id))
                      const isDisabled = !!reason && !isCurrentHead
                      return (
                      <label
                        key={emp.id}
                        className={[
                          'flex items-center gap-3 border-t border-border/40 px-3 py-2.5 transition-colors dark:border-white/6',
                          !isDisabled && 'cursor-pointer hover:bg-muted/40 dark:hover:bg-white/5',
                          isDisabled && 'opacity-60 cursor-not-allowed',
                          String(headId) === String(emp.id) ? 'bg-teal-500/8 dark:bg-teal-500/10' : '',
                        ].join(' ')}
                      >
                        <input
                          type="radio"
                          name="head-select"
                          value={emp.id}
                          checked={String(headId) === String(emp.id)}
                          onChange={() => { if (!isDisabled) setHeadId(String(emp.id)) }}
                          disabled={isDisabled}
                          className="accent-teal-500 disabled:cursor-not-allowed"
                        />
                        <Avatar className="size-8 shrink-0">
                          <AvatarImage src={profileImageUrl(emp.profile_image_url || emp.profile_image)} alt={emp.name} />
                          <AvatarFallback className="bg-teal-500/20 text-[10px] font-bold text-teal-700 dark:text-teal-300">
                            {initials(emp.name)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium text-foreground">{emp.name}</p>
                          <p className="truncate text-[11px] text-muted-foreground">{emp.position || emp.employee_code || ''}</p>
                          {isDisabled && reason && (
                            <p className="mt-0.5 text-[10px] font-medium text-rose-600 dark:text-rose-400" title={reason}>{reason}</p>
                          )}
                        </div>
                        {String(headId) === String(emp.id) && !isDisabled && (
                          <span className="shrink-0 text-[10px] font-semibold text-teal-600 dark:text-teal-400">Selected</span>
                        )}
                      </label>
                      )
                    })}
                  </div>
                )
              })()}
            </div>
            </div>
            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" className="dark:border-white/10 dark:text-slate-300" onClick={() => setHeadOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={headSubmitting || headModalLoading} className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}>
                {headSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
                Save
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <AssignEmployeesModal
        open={assignOpen}
        onOpenChange={(open) => {
          setAssignOpen(open)
          if (!open) {
            setAssignSearchQuery('')
            setAssignFilter('available')
            setAssignDepartmentFilter('all')
          }
        }}
        department={assignDepartment}
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
        assignNewToDeptCount={assignNewToDeptCount}
        navigate={navigate}
        initialsFn={initials}
        footerStats={assignFooterStats}
        onGoEmployees={() => navigate('/admin/employees')}
      />


      {/* Delete confirmation */}
      <Dialog open={!!deleteConfirmDept} onOpenChange={(open) => !open && setDeleteConfirmDept(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="dept-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Delete department?</DialogTitle>
              <p id="dept-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {deleteConfirmDept && (
                  <>
                    Are you sure you want to delete <strong className="text-foreground">{deleteConfirmDept.name}</strong>?
                    Employees in this department will be unassigned.
                  </>
                )}
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button variant="outline" onClick={() => setDeleteConfirmDept(null)} disabled={deleteSubmitting}>Cancel</Button>
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
