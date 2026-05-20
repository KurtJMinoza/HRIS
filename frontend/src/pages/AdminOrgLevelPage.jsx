import { useCallback, useEffect, useMemo, useState } from 'react'
import { Download, Pencil, Plus, RefreshCw, Search, Trash2, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/components/ui/use-toast'
import {
  createDivision,
  createSectionOrUnit,
  deleteDivision,
  deleteSectionOrUnit,
  getBranches,
  getCompanies,
  getDepartments,
  getDivisions,
  getEmployees,
  getSectionsOrUnits,
  updateDivision,
  updateSectionOrUnit,
} from '@/api'

const CONFIG = {
  divisions: {
    title: 'Divisions',
    singular: 'Division',
    listKey: 'divisions',
    headField: 'division_head_id',
    headNameField: 'division_head_name',
    headLabel: 'Division Head',
    getRows: getDivisions,
    create: createDivision,
    update: updateDivision,
    remove: deleteDivision,
  },
  sections: {
    title: 'Sections / Units',
    singular: 'Section/Unit',
    listKey: 'sections_or_units',
    headField: 'section_unit_head_id',
    headNameField: 'section_unit_head_name',
    headLabel: 'Section/Unit Head',
    getRows: getSectionsOrUnits,
    create: createSectionOrUnit,
    update: updateSectionOrUnit,
    remove: deleteSectionOrUnit,
  },
}

const EMPTY_FORM = {
  name: '',
  code: '',
  company_id: '',
  branch_id: '',
  department_id: '',
  division_id: '',
  head_id: '',
  status: 'active',
  description: '',
}

function numberOrNull(value) {
  return value === '' || value == null ? null : Number(value)
}

function csvEscape(value) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`
}

export default function AdminOrgLevelPage({ type }) {
  const config = CONFIG[type] || CONFIG.divisions
  const isSection = type === 'sections'
  const { toast } = useToast()
  const [rows, setRows] = useState([])
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [divisions, setDivisions] = useState([])
  const [employees, setEmployees] = useState([])
  const [filters, setFilters] = useState({ search: '', company_id: '', branch_id: '', department_id: '', division_id: '', status: '' })
  const [form, setForm] = useState(EMPTY_FORM)
  const [editing, setEditing] = useState(null)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)

  const loadReferences = useCallback(async () => {
    const [companyData, branchData, departmentData, divisionData, employeeData] = await Promise.all([
      getCompanies({ fresh: true }),
      getBranches({ fresh: true }),
      getDepartments({ fresh: true }),
      getDivisions({ fresh: true }),
      getEmployees({ per_page: 'all', active_filter: 'active', fresh: true }),
    ])
    setCompanies(companyData.companies || [])
    setBranches(branchData.branches || [])
    setDepartments(departmentData.departments || [])
    setDivisions(divisionData.divisions || [])
    setEmployees(employeeData.employees || [])
  }, [])

  const loadRows = useCallback(async () => {
    setLoading(true)
    try {
      const data = await config.getRows({ ...filters, fresh: true })
      setRows(data[config.listKey] || [])
    } catch (error) {
      toast({ title: 'Load failed', description: error.message, variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [config, filters, toast])

  useEffect(() => {
    loadReferences().catch((error) => toast({ title: 'References failed', description: error.message, variant: 'destructive' }))
  }, [loadReferences, toast])

  useEffect(() => {
    loadRows()
  }, [loadRows])

  const visibleBranches = useMemo(() => {
    if (!form.company_id) return branches
    return branches.filter((branch) => String(branch.company_id) === String(form.company_id))
  }, [branches, form.company_id])

  const visibleDepartments = useMemo(() => {
    if (!form.branch_id) return departments
    return departments.filter((department) => String(department.branch_id) === String(form.branch_id))
  }, [departments, form.branch_id])

  const visibleDivisions = useMemo(() => {
    if (!form.department_id) return divisions
    return divisions.filter((division) => String(division.department_id) === String(form.department_id))
  }, [divisions, form.department_id])

  const resetForm = () => {
    setEditing(null)
    setForm(EMPTY_FORM)
  }

  const editRow = (row) => {
    setEditing(row)
    setForm({
      name: row.name || '',
      code: row.code || '',
      company_id: row.company_id || '',
      branch_id: row.branch_id || '',
      department_id: row.department_id || '',
      division_id: row.division_id || '',
      head_id: row[config.headField] || '',
      status: row.status || 'active',
      description: row.description || '',
    })
  }

  const submitForm = async (event) => {
    event.preventDefault()
    setBusy(true)
    try {
      const payload = {
        name: form.name.trim(),
        code: form.code.trim() || null,
        company_id: numberOrNull(form.company_id),
        branch_id: numberOrNull(form.branch_id),
        department_id: numberOrNull(form.department_id),
        ...(isSection ? { division_id: numberOrNull(form.division_id) } : {}),
        [config.headField]: numberOrNull(form.head_id),
        status: form.status,
        description: form.description.trim() || null,
      }
      if (editing) {
        await config.update(editing.id, payload)
      } else {
        await config.create(payload)
      }
      toast({ title: `${config.singular} saved` })
      resetForm()
      await Promise.all([loadRows(), loadReferences()])
    } catch (error) {
      toast({ title: 'Save failed', description: error.message, variant: 'destructive' })
    } finally {
      setBusy(false)
    }
  }

  const removeRow = async (row) => {
    if (!window.confirm(`Delete ${row.name}?`)) return
    setBusy(true)
    try {
      await config.remove(row.id)
      toast({ title: `${config.singular} deleted` })
      await loadRows()
    } catch (error) {
      toast({ title: 'Delete failed', description: error.message, variant: 'destructive' })
    } finally {
      setBusy(false)
    }
  }

  const exportRows = () => {
    const header = ['Name', 'Code', 'Company', 'Branch', 'Department', 'Division', config.headLabel, 'Status', 'Employees', 'Remarks']
    const lines = [header.map(csvEscape).join(',')]
    for (const row of rows) {
      lines.push([
        row.name,
        row.code,
        row.company_name,
        row.branch_name,
        row.department_name,
        row.division_name,
        row[config.headNameField],
        row.status,
        row.total_employees,
        row.description,
      ].map(csvEscape).join(','))
    }
    const blob = new Blob([`${lines.join('\n')}\n`], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${config.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-col gap-5 p-4 md:p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-normal text-foreground">{config.title}</h1>
          <p className="text-sm text-muted-foreground">Company / Branch / Department / Division hierarchy</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={loadRows} disabled={loading}>
            <RefreshCw className="mr-2 size-4" /> Refresh
          </Button>
          <Button type="button" variant="outline" onClick={exportRows} disabled={!rows.length}>
            <Download className="mr-2 size-4" /> Export
          </Button>
          <Button type="button" onClick={resetForm}>
            <Plus className="mr-2 size-4" /> New {config.singular}
          </Button>
        </div>
      </div>

      <form onSubmit={submitForm} className="grid gap-4 rounded-lg border bg-card p-4 md:grid-cols-6">
        <div className="md:col-span-2">
          <Label>{config.singular} name</Label>
          <Input value={form.name} onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))} required />
        </div>
        <div>
          <Label>Code</Label>
          <Input value={form.code} onChange={(event) => setForm((prev) => ({ ...prev, code: event.target.value }))} />
        </div>
        <div>
          <Label>Status</Label>
          <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.status} onChange={(event) => setForm((prev) => ({ ...prev, status: event.target.value }))}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div>
          <Label>Company</Label>
          <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.company_id} onChange={(event) => setForm((prev) => ({ ...prev, company_id: event.target.value, branch_id: '', department_id: '', division_id: '' }))} required>
            <option value="">Select</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </select>
        </div>
        <div>
          <Label>Branch</Label>
          <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.branch_id} onChange={(event) => setForm((prev) => ({ ...prev, branch_id: event.target.value, department_id: '', division_id: '' }))} required={isSection}>
            <option value="">Select</option>
            {visibleBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
          </select>
        </div>
        <div>
          <Label>Department</Label>
          <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.department_id} onChange={(event) => setForm((prev) => ({ ...prev, department_id: event.target.value, division_id: '' }))} required={isSection}>
            <option value="">Select</option>
            {visibleDepartments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
          </select>
        </div>
        {isSection ? (
          <div>
            <Label>Division</Label>
            <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.division_id} onChange={(event) => setForm((prev) => ({ ...prev, division_id: event.target.value }))}>
              <option value="">None</option>
              {visibleDivisions.map((division) => <option key={division.id} value={division.id}>{division.name}</option>)}
            </select>
          </div>
        ) : null}
        <div className="md:col-span-2">
          <Label>{config.headLabel}</Label>
          <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={form.head_id} onChange={(event) => setForm((prev) => ({ ...prev, head_id: event.target.value }))}>
            <option value="">Unassigned</option>
            {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name || employee.display_name}</option>)}
          </select>
        </div>
        <div className="md:col-span-4">
          <Label>Description / remarks</Label>
          <Textarea value={form.description} onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))} rows={2} />
        </div>
        <div className="flex items-end gap-2 md:col-span-2">
          <Button type="submit" disabled={busy}>{editing ? 'Update' : 'Create'}</Button>
          {editing ? (
            <Button type="button" variant="outline" onClick={resetForm}>
              <X className="mr-2 size-4" /> Cancel
            </Button>
          ) : null}
        </div>
      </form>

      <div className="grid gap-3 rounded-lg border bg-card p-4 md:grid-cols-6">
        <div className="relative md:col-span-2">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="pl-9" placeholder="Search" value={filters.search} onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))} />
        </div>
        <select className="h-10 rounded-md border bg-background px-3 text-sm" value={filters.company_id} onChange={(event) => setFilters((prev) => ({ ...prev, company_id: event.target.value, branch_id: '', department_id: '', division_id: '' }))}>
          <option value="">All companies</option>
          {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
        </select>
        <select className="h-10 rounded-md border bg-background px-3 text-sm" value={filters.branch_id} onChange={(event) => setFilters((prev) => ({ ...prev, branch_id: event.target.value, department_id: '', division_id: '' }))}>
          <option value="">All branches</option>
          {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
        </select>
        <select className="h-10 rounded-md border bg-background px-3 text-sm" value={filters.department_id} onChange={(event) => setFilters((prev) => ({ ...prev, department_id: event.target.value, division_id: '' }))}>
          <option value="">All departments</option>
          {departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
        </select>
        {isSection ? (
          <select className="h-10 rounded-md border bg-background px-3 text-sm" value={filters.division_id} onChange={(event) => setFilters((prev) => ({ ...prev, division_id: event.target.value }))}>
            <option value="">All divisions</option>
            {divisions.map((division) => <option key={division.id} value={division.id}>{division.name}</option>)}
          </select>
        ) : (
          <select className="h-10 rounded-md border bg-background px-3 text-sm" value={filters.status} onChange={(event) => setFilters((prev) => ({ ...prev, status: event.target.value }))}>
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        )}
      </div>

      <div className="overflow-hidden rounded-lg border bg-card">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[980px] text-sm">
            <thead className="border-b bg-muted/60 text-left text-xs uppercase tracking-normal text-muted-foreground">
              <tr>
                <th className="px-4 py-3">Name</th>
                <th className="px-4 py-3">Hierarchy</th>
                <th className="px-4 py-3">{config.headLabel}</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3 text-right">Employees</th>
                <th className="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td className="px-4 py-8 text-center text-muted-foreground" colSpan={6}>Loading...</td></tr>
              ) : rows.length === 0 ? (
                <tr><td className="px-4 py-8 text-center text-muted-foreground" colSpan={6}>No records found.</td></tr>
              ) : rows.map((row) => (
                <tr key={row.id} className="border-b last:border-0">
                  <td className="px-4 py-3">
                    <div className="font-medium text-foreground">{row.name}</div>
                    <div className="text-xs text-muted-foreground">{row.code || 'No code'}</div>
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">
                    {[row.company_name, row.branch_name, row.department_name, row.division_name].filter(Boolean).join(' / ') || 'Unassigned'}
                  </td>
                  <td className="px-4 py-3">{row[config.headNameField] || <span className="text-muted-foreground">Unassigned</span>}</td>
                  <td className="px-4 py-3">
                    <Badge variant={row.status === 'active' ? 'default' : 'secondary'}>{row.status || 'active'}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">{row.total_employees ?? 0}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => editRow(row)}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button type="button" size="sm" variant="destructive" onClick={() => removeRow(row)} disabled={busy}>
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
