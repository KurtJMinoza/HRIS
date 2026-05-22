import { useMemo, useState } from 'react'
import { Loader2, Search, UserMinus, UserPlus, X } from 'lucide-react'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { profileImageUrl } from '@/api'
import { cn } from '@/lib/utils'
import {
  employeeDisplayName,
  filterEmployeesByQuery,
  normalizeLeaderUserId,
  toDisplayText,
} from '@/lib/employeeSearch'

const MULTI_HEAD_INFO =
  'This employee is already assigned to another head role, but multiple leadership assignments are allowed.'

export default function AssignOrgHeadModal({
  open,
  onOpenChange,
  title,
  unitName,
  fieldLabel,
  loading,
  loadingMessage = 'Loading members…',
  loadError,
  onRetry,
  employees = [],
  currentHeadId,
  currentHead = null,
  headId,
  onHeadIdChange,
  headRoleNotes,
  submitting,
  onSubmit,
  initialsFn,
  ariaDescribedBy = 'org-head-desc',
}) {
  const [searchQuery, setSearchQuery] = useState('')
  const roleNotes = headRoleNotes instanceof Map ? headRoleNotes : new Map()

  const handleOpenChange = (nextOpen) => {
    if (!nextOpen) setSearchQuery('')
    onOpenChange(nextOpen)
  }

  const normalizedCurrentHead = useMemo(() => {
    if (!currentHead) return null
    const id = normalizeLeaderUserId(currentHead.id)
    if (!id) return null
    const name = employeeDisplayName(currentHead)
    if (!name || name === 'Unknown') return null
    return {
      id,
      name,
      profile_image_url: toDisplayText(currentHead.profile_image_url || currentHead.profile_image) || null,
      employee_code: toDisplayText(currentHead.employee_code),
      position: toDisplayText(currentHead.position),
    }
  }, [currentHead])

  const rosterEmployees = useMemo(() => {
    if (!normalizedCurrentHead) return employees
    if (employees.some((e) => normalizeLeaderUserId(e.id) === normalizedCurrentHead.id)) return employees
    return [
      {
        id: normalizedCurrentHead.id,
        name: normalizedCurrentHead.name,
        profile_image_url: normalizedCurrentHead.profile_image_url,
        profile_image: normalizedCurrentHead.profile_image_url,
        employee_code: normalizedCurrentHead.employee_code,
        position: normalizedCurrentHead.position,
        is_active: true,
      },
      ...employees,
    ]
  }, [employees, normalizedCurrentHead])

  const filteredEmployees = useMemo(
    () => filterEmployeesByQuery(rosterEmployees, searchQuery),
    [rosterEmployees, searchQuery],
  )

  const showEmptySearch =
    !loading &&
    !loadError &&
    rosterEmployees.length > 0 &&
    searchQuery.trim() &&
    filteredEmployees.length === 0

  const assignedHeadName = normalizedCurrentHead?.name || ''
  const assignedHeadMeta = [normalizedCurrentHead?.employee_code, normalizedCurrentHead?.position]
    .filter(Boolean)
    .join(' · ')

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton
        className="max-w-[min(100vw-1.5rem,48rem)] rounded-2xl border-border/80 bg-card shadow-2xl shadow-black/20 dark:shadow-black/60"
        innerClassName="flex min-h-0 flex-1 flex-col !gap-0 !overflow-hidden !p-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-border/80 bg-background text-foreground hover:bg-muted"
        overlayClassName="bg-black/55 backdrop-blur-sm"
        aria-describedby={ariaDescribedBy}
      >
        <div className="shrink-0 border-b border-border/80 px-6 pb-5 pt-7 pr-16 @md:px-8">
          <DialogHeader className="flex-row items-start gap-5 space-y-0 text-left">
            <div className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
              <UserPlus className="size-7" />
            </div>
            <div className="min-w-0 pt-1">
              <DialogTitle className="text-2xl font-extrabold leading-tight tracking-normal text-foreground">
                {title}
              </DialogTitle>
              {unitName ? (
                <p id={ariaDescribedBy} className="mt-3 max-w-2xl text-base leading-7 text-muted-foreground">
                  Select the head for{' '}
                  <strong className="font-extrabold uppercase text-brand">{toDisplayText(unitName)}</strong>.
                </p>
              ) : null}
            </div>
          </DialogHeader>
        </div>

        <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5 @md:px-8">
            <div className="space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <Label className="text-base font-semibold text-foreground">{fieldLabel}</Label>
                {headId !== '' && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 rounded-xl border-destructive/40 px-3 text-xs font-semibold text-destructive hover:bg-destructive/10"
                    onClick={() => onHeadIdChange('')}
                  >
                    <UserMinus className="mr-1.5 size-3.5" />
                    Remove employee
                  </Button>
                )}
              </div>

              {normalizedCurrentHead?.id && assignedHeadName ? (
                <div className="rounded-xl border border-brand/30 bg-brand/5 px-4 py-3.5 dark:bg-brand/10">
                  <p className="text-[11px] font-bold uppercase tracking-wide text-brand">Currently assigned</p>
                  <div className="mt-2.5 flex items-center gap-3">
                    <Avatar className="size-11 shrink-0">
                      <AvatarImage
                        src={profileImageUrl(normalizedCurrentHead.profile_image_url)}
                        alt={assignedHeadName}
                      />
                      <AvatarFallback className="bg-brand/15 text-sm font-bold text-brand">
                        {initialsFn?.(assignedHeadName) ?? '?'}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-base font-extrabold text-foreground">{assignedHeadName}</p>
                      {assignedHeadMeta ? (
                        <p className="truncate text-xs text-muted-foreground">{assignedHeadMeta}</p>
                      ) : null}
                    </div>
                    {normalizeLeaderUserId(headId) === normalizedCurrentHead.id ? (
                      <span className="shrink-0 rounded-full bg-brand/15 px-2.5 py-1 text-[10px] font-bold text-brand">
                        Selected
                      </span>
                    ) : null}
                  </div>
                </div>
              ) : null}

              {!loading && !loadError && rosterEmployees.length > 0 && (
                <div className="relative">
                  <Search className="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-muted-foreground" />
                  <Input
                    type="text"
                    placeholder="Search name, email, code, or position…"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="h-11 rounded-xl border-border/80 bg-background pl-11 pr-11 text-sm shadow-sm transition focus-visible:ring-brand/20 dark:bg-input/25"
                  />
                  {searchQuery && (
                    <button
                      type="button"
                      onClick={() => setSearchQuery('')}
                      className="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                      aria-label="Clear search"
                    >
                      <X className="size-4" />
                    </button>
                  )}
                </div>
              )}

              {loading ? (
                <div className="flex items-center justify-center gap-2 rounded-xl border border-border/70 py-10 text-sm text-muted-foreground dark:bg-input/20">
                  <Loader2 className="size-5 shrink-0 animate-spin text-brand" />
                  {loadingMessage}
                </div>
              ) : loadError ? (
                <div className="rounded-xl border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                  <p>{toDisplayText(loadError) || 'Could not load members.'}</p>
                  {onRetry ? (
                    <Button type="button" variant="outline" size="sm" className="mt-3" onClick={onRetry}>
                      Try again
                    </Button>
                  ) : null}
                </div>
              ) : (
                <div className="max-h-[min(48vh,24rem)] overflow-y-auto rounded-xl border border-border/80 dark:border-border/70">
                  <label
                    className={cn(
                      'flex cursor-pointer items-center gap-4 bg-background px-4 py-3 transition-colors hover:bg-muted/40 dark:bg-input/20 dark:hover:bg-input/35',
                      headId === '' && 'bg-brand/5 dark:bg-brand/10',
                    )}
                  >
                    <input
                      type="radio"
                      name="head-select"
                      value=""
                      checked={headId === ''}
                      onChange={() => onHeadIdChange('')}
                      className="size-5 accent-orange-600"
                    />
                    <span className="flex size-10 items-center justify-center rounded-full border border-dashed border-border/60 dark:border-white/15">
                      <UserMinus className="size-3.5 text-muted-foreground" />
                    </span>
                    <span className="text-sm italic text-muted-foreground">— Remove head —</span>
                  </label>

                  {rosterEmployees.length === 0 && (
                    <p className="px-3 py-4 text-center text-sm text-muted-foreground">No active employees available.</p>
                  )}

                  {showEmptySearch && (
                    <p className="border-t border-border/70 px-3 py-4 text-center text-sm text-muted-foreground dark:border-border/60">
                      No employees match your search.
                    </p>
                  )}

                  {filteredEmployees.map((emp) => {
                    const empId = normalizeLeaderUserId(emp.id)
                    const isCurrentHead = empId !== '' && empId === normalizeLeaderUserId(currentHeadId)
                    const roleNote = roleNotes.get(empId)
                    const isDisabled = emp.is_active === false && !isCurrentHead
                    const displayName = employeeDisplayName(emp)
                    const meta = [toDisplayText(emp.employee_code), toDisplayText(emp.position)].filter(Boolean).join(' · ')
                    const isSelected = normalizeLeaderUserId(headId) === empId
                    return (
                      <label
                        key={empId || displayName}
                        className={cn(
                          'flex items-center gap-4 border-t border-border/70 bg-background px-4 py-3 transition-colors dark:border-border/60 dark:bg-input/20',
                          !isDisabled && 'cursor-pointer hover:bg-muted/40 dark:hover:bg-white/5',
                          isDisabled && 'cursor-not-allowed opacity-60',
                          isSelected && 'bg-brand/5 dark:bg-brand/10',
                        )}
                      >
                        <input
                          type="radio"
                          name="head-select"
                          value={empId}
                          checked={isSelected}
                          onChange={() => {
                            if (!isDisabled) onHeadIdChange(empId)
                          }}
                          disabled={isDisabled}
                          className="size-5 accent-orange-600 disabled:cursor-not-allowed"
                        />
                        <Avatar className="size-12 shrink-0">
                          <AvatarImage
                            src={profileImageUrl(emp.profile_image_url || emp.profile_image)}
                            alt={displayName}
                          />
                          <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">
                            {initialsFn?.(displayName) ?? '?'}
                          </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-base font-extrabold text-foreground">{displayName}</p>
                          <p className="truncate text-xs text-muted-foreground">{meta || '—'}</p>
                          {roleNote && (
                            <p
                              className="mt-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400"
                              title={MULTI_HEAD_INFO}
                            >
                              {roleNote}
                            </p>
                          )}
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1">
                          {isCurrentHead ? (
                            <span className="rounded-full bg-brand/15 px-2.5 py-1 text-[10px] font-bold text-brand">
                              Currently assigned
                            </span>
                          ) : null}
                          {isSelected && !isDisabled ? (
                            <span className="rounded-full bg-brand/10 px-2.5 py-1 text-[10px] font-bold text-brand">
                              Selected
                            </span>
                          ) : null}
                        </div>
                      </label>
                    )
                  })}
                </div>
              )}
            </div>
          </div>

          <DialogFooter className="shrink-0 gap-3 border-t border-border/80 px-6 py-5 @md:px-8">
            <Button
              type="button"
              variant="outline"
              className="h-11 min-w-[120px] rounded-xl border-border/80 bg-background px-6 text-sm font-semibold text-foreground hover:bg-muted"
              onClick={() => handleOpenChange(false)}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={submitting || loading}
              className="h-11 min-w-[130px] rounded-xl bg-brand px-6 text-sm font-bold text-brand-foreground shadow-[0_8px_24px_rgba(249,115,22,0.28)] hover:bg-brand-strong"
            >
              {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
