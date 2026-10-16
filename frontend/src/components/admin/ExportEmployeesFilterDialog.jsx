import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight, Download, Loader2, Search } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

const NO_COMPANY_KEY = 'none'

/**
 * Pick companies (whole) and/or specific branches before exporting the employees workbook.
 * A company with any branch ticked exports only those branches; no selection exports everything.
 */
export default function ExportEmployeesFilterDialog({ open, onOpenChange, companies, branches, exporting, onExport }) {
  const [selectedCompanies, setSelectedCompanies] = useState(() => new Set())
  const [selectedBranches, setSelectedBranches] = useState(() => new Set())
  const [expanded, setExpanded] = useState(() => new Set())
  const [search, setSearch] = useState('')

  const groups = useMemo(() => {
    const byCompany = new Map()
    for (const company of companies) {
      byCompany.set(String(company.id), { key: String(company.id), id: company.id, name: company.name || 'Unnamed company', branches: [] })
    }
    for (const branch of branches) {
      const key = branch.company_id != null && byCompany.has(String(branch.company_id)) ? String(branch.company_id) : NO_COMPANY_KEY
      if (!byCompany.has(key)) {
        byCompany.set(key, { key, id: null, name: 'Branches without a company', branches: [] })
      }
      byCompany.get(key).branches.push({ id: branch.id, name: branch.name || 'Unnamed branch' })
    }
    const list = [...byCompany.values()]
    list.forEach((g) => g.branches.sort((a, b) => a.name.localeCompare(b.name)))
    return list.sort((a, b) => {
      if (a.key === NO_COMPANY_KEY) return 1
      if (b.key === NO_COMPANY_KEY) return -1
      return a.name.localeCompare(b.name)
    })
  }, [companies, branches])

  const visibleGroups = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return groups
    return groups
      .map((g) => {
        if (g.name.toLowerCase().includes(q)) return g
        const matched = g.branches.filter((b) => b.name.toLowerCase().includes(q))
        return matched.length > 0 ? { ...g, branches: matched, forceExpanded: true } : null
      })
      .filter(Boolean)
  }, [groups, search])

  const selectedBranchCount = (group) => group.branches.filter((b) => selectedBranches.has(b.id)).length

  const isGroupChecked = (group) =>
    (group.id != null && selectedCompanies.has(group.id)) || selectedBranchCount(group) > 0

  const toggleGroup = (group) => {
    const nextCompanies = new Set(selectedCompanies)
    const nextBranches = new Set(selectedBranches)
    if (isGroupChecked(group)) {
      if (group.id != null) nextCompanies.delete(group.id)
      group.branches.forEach((b) => nextBranches.delete(b.id))
    } else if (group.id != null) {
      nextCompanies.add(group.id)
    } else {
      group.branches.forEach((b) => nextBranches.add(b.id))
    }
    setSelectedCompanies(nextCompanies)
    setSelectedBranches(nextBranches)
  }

  const toggleBranch = (group, branchId) => {
    const nextBranches = new Set(selectedBranches)
    if (nextBranches.has(branchId)) nextBranches.delete(branchId)
    else nextBranches.add(branchId)
    // Ticking branches narrows the company, so drop its "whole company" flag.
    if (group.id != null && selectedCompanies.has(group.id)) {
      const nextCompanies = new Set(selectedCompanies)
      nextCompanies.delete(group.id)
      setSelectedCompanies(nextCompanies)
    }
    setSelectedBranches(nextBranches)
  }

  const toggleExpanded = (key) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const selectAll = () => {
    setSelectedCompanies(new Set(groups.filter((g) => g.id != null).map((g) => g.id)))
    const orphan = groups.find((g) => g.key === NO_COMPANY_KEY)
    setSelectedBranches(new Set(orphan ? orphan.branches.map((b) => b.id) : []))
  }

  const clearAll = () => {
    setSelectedCompanies(new Set())
    setSelectedBranches(new Set())
  }

  const payload = useMemo(() => {
    const companyIds = []
    const branchIds = []
    for (const group of groups) {
      const ticked = group.branches.filter((b) => selectedBranches.has(b.id)).map((b) => b.id)
      if (ticked.length > 0) branchIds.push(...ticked)
      else if (group.id != null && selectedCompanies.has(group.id)) companyIds.push(group.id)
    }
    return { companyIds, branchIds }
  }, [groups, selectedCompanies, selectedBranches])

  const nothingSelected = payload.companyIds.length === 0 && payload.branchIds.length === 0
  const selectedGroupCount = groups.filter(isGroupChecked).length

  return (
    <Dialog open={open} onOpenChange={(next) => !exporting && onOpenChange(next)}>
      <DialogContent className="flex max-h-[min(88dvh,760px)] w-[calc(100vw-1.5rem)] max-w-xl flex-col">
        <DialogHeader className="shrink-0 text-left">
          <DialogTitle>Export by Company</DialogTitle>
          <DialogDescription>
            Choose which companies and branches to include. Expand a company to export only some of its branches.
            Leave everything unticked to export all employees.
          </DialogDescription>
        </DialogHeader>

        <div className="flex shrink-0 items-center gap-2">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search company or branch..."
              className="h-9 pl-9 text-sm"
            />
          </div>
          <Button type="button" variant="outline" size="sm" className="h-9" onClick={selectAll} disabled={exporting}>
            Select all
          </Button>
          <Button type="button" variant="ghost" size="sm" className="h-9" onClick={clearAll} disabled={exporting || nothingSelected}>
            Clear
          </Button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto rounded-lg border border-border/70">
          {visibleGroups.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">No companies or branches match your search.</p>
          ) : (
            <ul className="divide-y divide-border/60">
              {visibleGroups.map((group) => {
                const isOpen = group.forceExpanded || expanded.has(group.key)
                const tickedBranches = selectedBranchCount(group)
                const checked = isGroupChecked(group)
                const scopeLabel = !checked
                  ? `${group.branches.length} ${group.branches.length === 1 ? 'branch' : 'branches'}`
                  : tickedBranches > 0
                    ? `${tickedBranches} of ${group.branches.length} branches`
                    : 'All branches'
                return (
                  <li key={group.key}>
                    <div className="flex items-center gap-2 px-3 py-2.5">
                      <button
                        type="button"
                        className="flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted disabled:opacity-30"
                        onClick={() => toggleExpanded(group.key)}
                        disabled={group.branches.length === 0}
                        aria-label={isOpen ? `Collapse ${group.name}` : `Expand ${group.name}`}
                      >
                        {isOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                      </button>
                      <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5">
                        <Checkbox checked={checked} onCheckedChange={() => toggleGroup(group)} disabled={exporting} />
                        <span className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">{group.name}</span>
                        <span className="shrink-0 text-xs text-muted-foreground">{scopeLabel}</span>
                      </label>
                    </div>
                    {isOpen && group.branches.length > 0 ? (
                      <ul className="border-t border-border/50 bg-muted/20 py-1">
                        {group.branches.map((branch) => (
                          <li key={branch.id}>
                            <label className="flex cursor-pointer items-center gap-2.5 py-1.5 pl-14 pr-3">
                              <Checkbox
                                checked={selectedBranches.has(branch.id)}
                                onCheckedChange={() => toggleBranch(group, branch.id)}
                                disabled={exporting}
                              />
                              <span className="min-w-0 truncate text-sm text-foreground">{branch.name}</span>
                            </label>
                          </li>
                        ))}
                      </ul>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          )}
        </div>

        <DialogFooter className="shrink-0 items-center gap-2 sm:justify-between">
          <p className="text-xs text-muted-foreground">
            {nothingSelected
              ? 'No filter: all companies and branches will be exported.'
              : `${selectedGroupCount} ${selectedGroupCount === 1 ? 'company' : 'companies'} selected`}
          </p>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={exporting}>
              Cancel
            </Button>
            <Button
              type="button"
              className="bg-brand text-brand-foreground hover:bg-brand-strong"
              onClick={() => onExport(payload)}
              disabled={exporting}
            >
              {exporting ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Download className="mr-1.5 size-4" />}
              {nothingSelected ? 'Export all' : 'Export selected'}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
