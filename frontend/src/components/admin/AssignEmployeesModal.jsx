import {
  Building2,
  Check,
  ChevronRight,
  Loader2,
  Network,
  Search,
  Trash2,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { RoleBadge } from '@/components/RoleBadge'
import { profileImageUrl } from '@/api'
import { cn } from '@/lib/utils'
export function FilterTabs({ value, onChange, counts }) {
  const tabs = [
    { key: 'available', label: 'Available', count: counts.available },
    { key: 'assigned', label: 'In dept', count: counts.assigned },
    { key: 'all', label: 'All', count: counts.total },
  ]
  return (
    <div className="grid grid-cols-3 rounded-xl border border-border/80 bg-background p-1 dark:bg-input/25" role="tablist">
      {tabs.map(({ key, label, count }) => {
        const active = value === key
        return (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(key)}
            className={cn(
              'relative flex min-h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-center text-sm font-semibold transition-all duration-200',
              active
                ? 'border border-brand/70 bg-brand/5 text-brand shadow-sm dark:bg-brand/10'
                : 'text-muted-foreground hover:text-foreground',
            )}
          >
            <span className="min-w-0 truncate">{label}</span>
            <span
              className={cn(
                'tabular-nums text-xs font-bold',
                active ? 'text-brand' : 'opacity-70',
              )}
            >
              {count}
            </span>
          </button>
        )
      })}
    </div>
  )
}

export function EmployeeRow({ row, onToggle, onOpenProfile, initialsFn }) {
  const { emp, status, checked, checkboxDisabled, isInactive, assignedElsewhere } = row
  return (
    <li
      className={cn(
        'group flex min-w-0 items-center gap-3 border-b border-border/70 bg-card px-4 py-4 transition-all duration-200 last:border-b-0 dark:bg-card @md:gap-4 @md:px-5',
        checkboxDisabled ? 'opacity-65' : 'cursor-pointer hover:bg-muted/30 dark:hover:bg-input/25',
        checked && !checkboxDisabled && 'bg-brand/5 shadow-[inset_3px_0_0_0_rgb(var(--brand))] dark:bg-brand/10',
      )}
      onClick={() => {
        if (!checkboxDisabled) onToggle(emp.id)
      }}
    >
      <div onClick={(e) => e.stopPropagation()}>
        <input
          type="checkbox"
          checked={checked}
          onChange={() => {
            if (!checkboxDisabled) onToggle(emp.id)
          }}
          disabled={checkboxDisabled}
          className="size-5 rounded border-border accent-orange-600 transition focus:ring-ring/40"
          aria-label={`Select ${emp.name || 'employee'}`}
        />
      </div>
      <button
        type="button"
        className={cn(
          'shrink-0 rounded-full transition duration-200 hover:ring-2 hover:ring-brand/25 active:scale-[0.98]',
        )}
        onClick={(e) => {
          e.stopPropagation()
          onOpenProfile(emp.id)
        }}
        title="View profile"
      >
        <Avatar className="size-12 border-2 border-background bg-brand/10 shadow-sm dark:border-border dark:shadow-none">
          <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" />
          <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">
            {initialsFn(emp.name)}
          </AvatarFallback>
        </Avatar>
      </button>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
          <span className="truncate text-base font-extrabold tracking-tight text-foreground">
            {emp.name}
          </span>
          <RoleBadge management_role={emp.management_role} />
        </div>
        <p className="mt-1 truncate text-sm text-muted-foreground">
          {[emp.employee_code, emp.position].filter(Boolean).join(' · ') || '—'}
        </p>
        <p className="mt-0.5 truncate text-xs text-muted-foreground">
          {emp.department ? (
            <span title={emp.department}>{emp.department}</span>
          ) : (
            <span className="italic">No department</span>
          )}
        </p>
      </div>
      <div className="ml-auto shrink-0">
        {status === 'available' && (
          <span className="inline-flex rounded-full border border-brand/70 bg-brand/5 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-brand dark:bg-brand/10">
            Open
          </span>
        )}
        {status === 'assigned' && (
          <span className="inline-flex items-center gap-1 rounded-full border border-brand/50 bg-brand/5 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand dark:bg-brand/10">
            <Check className="size-3" aria-hidden />
            In dept
          </span>
        )}
        {status === 'unavailable' && assignedElsewhere && (
          <span
            className="inline-flex max-w-28 truncate rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
            title={[emp.company_name, emp.branch_name, emp.department].filter(Boolean).join(' → ')}
          >
            Employed
          </span>
        )}
        {status === 'unavailable' && isInactive && (
          <span className="inline-flex rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
            Inactive
          </span>
        )}
      </div>
    </li>
  )
}

export function EmptyState({
  assignSearchQuery,
  assignFilter,
  assignCounts,
  onResetFilters,
  onGoEmployees,
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 px-6 py-16 text-center">
      <div className="flex size-16 items-center justify-center rounded-2xl border border-dashed border-border/60 bg-card shadow-inner">
        {assignSearchQuery ? (
          <Search className="size-7 text-muted-foreground/50" />
        ) : (
          <Users className="size-7 text-muted-foreground/50" />
        )}
      </div>
      <div className="max-w-sm space-y-2">
        <p className="text-base font-semibold text-foreground">
          {assignSearchQuery
            ? 'No matches'
            : assignFilter === 'available'
              ? assignCounts.total === 0
                ? 'No employees yet'
                : assignCounts.available === 0
                  ? 'No one else available'
                  : 'Nothing in this tab'
              : assignFilter === 'assigned'
                ? 'No members in this department'
                : 'No employees in this view'}
        </p>
        <p className="text-sm leading-relaxed text-muted-foreground">
          {assignSearchQuery
            ? 'Try another keyword.'
            : assignCounts.total === 0
              ? 'Create employees under this company first.'
              : assignFilter === 'available' && assignCounts.available === 0 && assignCounts.assigned > 0
                ? 'Try “In this dept” or “All”, or clear search.'
                : 'Switch tabs or clear search.'}
        </p>
      </div>
      {(assignSearchQuery || assignFilter !== 'available') && (
        <Button type="button" variant="outline" size="sm" className="rounded-xl" onClick={onResetFilters}>
          Reset filters
        </Button>
      )}
      {assignCounts.total === 0 && !assignSearchQuery && (
        <Button type="button" size="sm" className="rounded-xl bg-brand text-brand-foreground hover:bg-brand-strong" onClick={onGoEmployees}>
          <UserPlus className="size-4" />
          Go to Employees
        </Button>
      )}
    </div>
  )
}

export function SelectedPanelHeader({ department, branchName, companyName, selectedCount, newCount }) {
  return (
    <div className="shrink-0 border-b border-border/80 bg-card px-6 py-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-base font-extrabold uppercase tracking-normal text-foreground">
            Selected for{' '}
            <span className="text-primary">{department?.name ?? '…'}</span>
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            {selectedCount} selected
            {newCount > 0 && (
              <span className="font-medium text-foreground"> · {newCount} new</span>
            )}
          </p>
        </div>
        {(branchName || companyName) && (
          <div className="flex max-w-full flex-wrap items-center gap-2 text-xs text-muted-foreground">
            {branchName && (
              <span className="inline-flex items-center gap-1.5 rounded-lg border border-border/80 bg-background px-3 py-2 font-semibold text-foreground dark:bg-input/25">
                <Network className="size-3.5 text-brand" />
                {branchName}
              </span>
            )}
            {companyName && (
              <span className="inline-flex items-center gap-1.5 rounded-lg border border-border/80 bg-background px-3 py-2 font-semibold text-foreground dark:bg-input/25">
                <Building2 className="size-3.5 text-brand" />
                {companyName}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export function SelectedEmptyState({ assignIdsLength, assignCounts, loading }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border/60 bg-muted/20 px-5 py-14 text-center">
      <div className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
        <Users className="size-7 text-muted-foreground/50" />
      </div>
      {loading ? (
        <>
          <p className="text-sm font-semibold text-foreground">Loading roster…</p>
          <p className="mt-2 max-w-xs text-sm text-muted-foreground">Fetching employees for this department.</p>
        </>
      ) : assignIdsLength > 0 ? (
        <>
          <p className="text-sm font-semibold text-foreground">Could not load selection</p>
          <p className="mt-2 max-w-xs text-sm text-muted-foreground">Close and reopen this dialog, or try again.</p>
        </>
      ) : assignCounts.total === 0 ? (
        <>
          <p className="text-sm font-semibold text-foreground">No employees available</p>
          <p className="mt-2 max-w-xs text-sm text-muted-foreground">Add people under this company or check your access.</p>
        </>
      ) : assignCounts.available > 0 ? (
        <>
          <p className="text-sm font-semibold text-foreground">Select employees on the left</p>
          <p className="mt-2 max-w-xs text-sm leading-relaxed text-muted-foreground">
            Use checkboxes to add people to this department, then tap <strong className="text-foreground">Assign selected</strong>.
          </p>
        </>
      ) : assignCounts.assigned > 0 && assignCounts.available === 0 ? (
        <>
          <p className="text-sm font-semibold text-foreground">Everyone is placed</p>
          <p className="mt-2 max-w-xs text-sm text-muted-foreground">
            No additional eligible employees. Check <strong className="text-foreground">In this dept</strong> on the left.
          </p>
        </>
      ) : (
        <>
          <p className="text-sm font-semibold text-foreground">Nothing selected</p>
          <p className="mt-2 max-w-xs text-sm text-muted-foreground">Choose employees from the list on the left.</p>
        </>
      )}
    </div>
  )
}

export function SelectedEmployeeCard({ emp, isAlreadyInDept, onOpenProfile, onRemove, initialsFn }) {
  return (
    <li className="flex items-center gap-4 rounded-xl border border-border/80 bg-card p-4 shadow-sm transition duration-200 dark:bg-input/20">
      <button
        type="button"
        className="shrink-0 rounded-full transition hover:ring-2 hover:ring-brand/20"
        onClick={() => onOpenProfile(emp.id)}
      >
        <Avatar className="size-12 border-2 border-background bg-brand/10 shadow dark:border-border">
          <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" />
          <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">{initialsFn(emp.name)}</AvatarFallback>
        </Avatar>
      </button>
      <div className="min-w-0 flex-1">
        <p className="truncate text-base font-extrabold text-foreground">{emp.name}</p>
        <p className="truncate text-xs text-muted-foreground">{emp.employee_code || emp.position || '—'}</p>
        <div className="mt-1.5">
          {isAlreadyInDept ? (
            <Badge variant="secondary" className="rounded-lg px-2 py-0 text-[10px] font-medium">
              Current member
            </Badge>
          ) : (
            <Badge className="rounded-lg border-0 bg-brand/10 px-2 py-0 text-[10px] font-semibold text-brand">
              Adding
            </Badge>
          )}
        </div>
      </div>
      {!isAlreadyInDept && (
        <button
          type="button"
          onClick={() => onRemove(emp.id)}
          title="Remove from selection"
          className="shrink-0 rounded-xl p-2 text-brand transition hover:bg-destructive/10 hover:text-destructive"
        >
          <Trash2 className="size-4" />
        </button>
      )}
    </li>
  )
}

export default function AssignEmployeesModal({
  open,
  onOpenChange,
  department,
  loading,
  assignRows,
  assignSearchQuery,
  onSearchChange,
  assignFilter,
  onFilterChange,
  assignCounts,
  assignExcludedCount,
  assignSelectableIds,
  allSelectableChecked,
  onToggleSelectAll,
  onToggleAssignId,
  onSubmit,
  submitting,
  assignIds,
  selectedEmployeesPreview,
  assignNewToDeptCount,
  navigate,
  initialsFn,
  footerStats,
  onGoEmployees,
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        surfaceStyle={{
          width: 'min(calc(100vw - 1rem), 88rem)',
          maxWidth: 'none',
        }}
        className="max-h-[min(92vh,62rem)] min-w-0 !max-w-none sm:!max-w-none rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
        innerClassName="flex min-h-0 flex-1 flex-col !gap-0 !overflow-hidden !p-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-border/80 bg-background text-foreground hover:bg-muted"
        overlayClassName="bg-black/55 backdrop-blur-sm"
        aria-describedby="dept-assign-employees-desc"
      >
        <DialogHeader className="border-b border-border/80 px-6 pb-6 pt-7 pr-16 text-left @md:px-8">
          <div className="flex items-start gap-5">
            <div className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
              <Users className="size-7" />
            </div>
            <div className="min-w-0">
          <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">Assign employees</DialogTitle>
          <DialogDescription id="dept-assign-employees-desc" className="mt-3 max-w-3xl text-base leading-7 text-muted-foreground">
            Select people to add to this department. Company Heads and Branch Managers are not listed — they sit above
            department level.
          </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <form onSubmit={onSubmit} className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {/* Two columns from sm (640px); below that stack. minmax(0,fr) avoids clipping the right column. */}
          <div className="grid min-h-0 min-w-0 flex-1 grid-cols-1 divide-y divide-border/80 overflow-hidden sm:grid-cols-[minmax(0,1.2fr)_minmax(0,0.85fr)] sm:divide-x sm:divide-y-0">
            <div className="flex min-h-0 min-w-0 flex-col bg-card sm:min-h-[34rem]">
              <div className="shrink-0 space-y-4 px-5 py-5 @md:px-6 @xl:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div>
                    <h3 className="text-sm font-extrabold uppercase tracking-wide text-foreground">Available employees</h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {loading
                        ? 'Loading…'
                        : assignFilter === 'available'
                          ? `${assignCounts.available} employee${assignCounts.available !== 1 ? 's' : ''} available`
                          : `${assignRows.length} in this view`}
                    </p>
                  </div>
                  {!loading && (
                    <span className="rounded-full border border-brand/70 bg-brand/5 px-4 py-2 text-sm font-bold tabular-nums text-brand shadow-sm">
                      {assignFilter === 'available' ? assignCounts.available : assignRows.length}
                    </span>
                  )}
                </div>

                <div className="relative">
                  <Search className="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-slate-400" />
                  <Input
                    type="text"
                    placeholder="Search name, email, code, or position…"
                    value={assignSearchQuery}
                    onChange={(e) => onSearchChange(e.target.value)}
                    className="h-12 rounded-xl border-border/80 bg-background pl-11 pr-11 text-sm shadow-sm transition focus-visible:ring-brand/20 dark:bg-input/25"
                  />
                  {assignSearchQuery && (
                    <button
                      type="button"
                      onClick={() => onSearchChange('')}
                      className="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                      aria-label="Clear search"
                    >
                      <X className="size-4" />
                    </button>
                  )}
                </div>

                <FilterTabs value={assignFilter} onChange={onFilterChange} counts={assignCounts} />

                {assignExcludedCount > 0 && (
                  <div className="rounded-xl border border-amber-200/80 bg-amber-50/90 px-3 py-2.5 text-xs leading-snug text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
                    <span className="font-semibold">{assignExcludedCount}</span> excluded — Company Heads and Branch Managers are not assignable to departments.
                  </div>
                )}
              </div>

              {!loading && assignSelectableIds.length > 0 && (
                <div className="flex shrink-0 items-center border-y border-border/80 bg-background px-5 py-3 dark:bg-input/15 @md:px-6 @xl:px-8">
                  <label className="flex cursor-pointer items-center gap-2.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">
                    <input
                      type="checkbox"
                      className="size-5 rounded border-border accent-orange-600"
                      checked={allSelectableChecked}
                      onChange={onToggleSelectAll}
                    />
                    {allSelectableChecked ? 'Deselect all in view' : 'Select all in view'}
                    <span className="text-muted-foreground/80">({assignSelectableIds.length})</span>
                  </label>
                </div>
              )}

              <div className="min-h-0 flex-1 overflow-y-auto bg-card">
                {loading ? (
                  <div className="space-y-0 divide-y divide-border/40">
                    {[...Array(6)].map((_, i) => (
                      <div key={i} className="flex animate-pulse items-center gap-4 px-5 py-5 @md:px-6 @xl:px-8">
                        <div className="size-4 rounded bg-muted" />
                        <div className="size-12 shrink-0 rounded-2xl bg-muted" />
                        <div className="flex-1 space-y-2">
                          <div className="h-4 w-40 rounded-lg bg-muted" />
                          <div className="h-3 w-28 rounded bg-muted/70" />
                        </div>
                      </div>
                    ))}
                  </div>
                ) : assignRows.length === 0 ? (
                  <EmptyState
                    assignSearchQuery={assignSearchQuery}
                    assignFilter={assignFilter}
                    assignCounts={assignCounts}
                    onResetFilters={() => {
                      onSearchChange('')
                      onFilterChange('available')
                    }}
                    onGoEmployees={onGoEmployees}
                  />
                ) : (
                  <ul>
                    {assignRows.map((row) => (
                      <EmployeeRow
                        key={row.emp.id}
                        row={row}
                        onToggle={onToggleAssignId}
                        onOpenProfile={(id) => navigate(`/admin/employees/${id}`)}
                        initialsFn={initialsFn}
                      />
                    ))}
                  </ul>
                )}
              </div>
            </div>

            <div className="flex min-h-[min(42vh,26rem)] min-w-0 flex-col bg-card sm:min-h-0">
              <SelectedPanelHeader
                department={department}
                branchName={department?.branch_name}
                companyName={department?.company_name}
                selectedCount={assignIds.length}
                newCount={assignNewToDeptCount}
              />

              <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5 @md:px-6 @xl:px-8">
                {selectedEmployeesPreview.length === 0 ? (
                  <SelectedEmptyState assignIdsLength={assignIds.length} assignCounts={assignCounts} loading={loading} />
                ) : (
                  <ul className="space-y-3">
                    {selectedEmployeesPreview.map((emp) => {
                      const isAlreadyInDept =
                        String(emp.department_id ?? '') === String(department?.id ?? '') ||
                        String(emp.department || '')
                          .trim()
                          .toLowerCase() === String(department?.name || '').trim().toLowerCase()
                      return (
                        <SelectedEmployeeCard
                          key={emp.id}
                          emp={emp}
                          isAlreadyInDept={isAlreadyInDept}
                          onOpenProfile={(id) => navigate(`/admin/employees/${id}`)}
                          onRemove={onToggleAssignId}
                          initialsFn={initialsFn}
                        />
                      )
                    })}
                  </ul>
                )}
              </div>
            </div>
          </div>

          <div className="flex shrink-0 flex-col gap-4 border-t border-border/80 px-6 py-5 @md:flex-row @md:items-center @md:justify-between @md:px-8">
            <div className="min-w-0 space-y-1 text-sm text-muted-foreground">
              <p>
                <span className="font-semibold text-foreground">
                  {assignIds.length} employee{assignIds.length !== 1 ? 's' : ''} selected
                </span>
                {footerStats.newlyAdded > 0 && (
                  <>
                    <ChevronRight className="mx-1 inline size-4 align-middle text-border" aria-hidden />
                    <span className="text-foreground/90">
                      {footerStats.afterSave} in department after save
                    </span>
                    <span className="ml-1 text-brand">(+{footerStats.newlyAdded} new)</span>
                  </>
                )}
              </p>
              {footerStats.newlyAdded > 0 && department && (
                <p className="text-xs text-muted-foreground">Updates reporting under {department.name}.</p>
              )}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-3">
              <Button
                type="button"
                variant="outline"
                className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting || assignIds.length === 0}
                className="h-11 min-w-[190px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong"
              >
                {submitting ? <Loader2 className="size-4 animate-spin" /> : <UserPlus className="size-4" />}
                {assignIds.length === 0 ? 'Assign selected' : `Assign selected (${assignIds.length})`}
              </Button>
            </div>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
