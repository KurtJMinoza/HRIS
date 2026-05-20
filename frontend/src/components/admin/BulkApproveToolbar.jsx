import { FileDown, Loader2, CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { BulkSelectionBanner } from '@/components/admin/BulkSelectionBanner'
import { cn } from '@/lib/utils'

const controlClass = 'h-10 rounded-lg text-sm'
const labelClass = 'text-xs font-medium text-muted-foreground'

/**
 * Enterprise-style bulk action toolbar: date filters, export, remarks, and bulk approve.
 */
export function BulkApproveToolbar({
  className,
  idPrefix = 'bulk',
  dateFrom = '',
  dateTo = '',
  onDateFromChange,
  onDateToChange,
  onApplyFilters,
  applyingFilters = false,
  onExportCsv,
  exportingCsv = false,
  exportDisabled = false,
  showBulkActions = true,
  remarks = '',
  onRemarksChange,
  selectedCount = 0,
  selectAllMatching = false,
  pageSelectableCount = 0,
  totalMatchingCount = 0,
  showPageSelectAllBanner = false,
  onSelectAllMatching,
  onClearSelection,
  entityLabel = 'requests',
  onApproveClick,
  approving = false,
  leftExtra = null,
  selectionBanner = null,
}) {
  const count = Number(selectedCount) || 0
  const remarksId = `${idPrefix}-remarks`
  const approveLabel =
    count > 0
      ? selectAllMatching
        ? `Approve ${count} ${entityLabel}`
        : count === 1
          ? 'Approve selected'
          : `Approve ${count} selected`
      : 'Approve selected'

  return (
    <div
      className={cn(
        'rounded-xl border border-border/70 bg-muted/20 p-4 shadow-sm dark:bg-muted/10',
        className,
      )}
      aria-label="Bulk actions and filters"
    >
      {selectionBanner ?? (
        <BulkSelectionBanner
          className="mb-4"
          pageCount={pageSelectableCount}
          totalCount={totalMatchingCount}
          selectAllMatching={selectAllMatching}
          showPageSelectAllBanner={showPageSelectAllBanner}
          onSelectAllMatching={onSelectAllMatching}
          onClearSelection={onClearSelection}
          entityLabel={entityLabel}
        />
      )}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="flex min-w-0 flex-1 flex-col gap-1.5 sm:max-w-[9.5rem]">
            <Label htmlFor={`${idPrefix}-from`} className={labelClass}>
              Date from
            </Label>
            <Input
              id={`${idPrefix}-from`}
              type="date"
              value={dateFrom}
              onChange={(e) => onDateFromChange?.(e.target.value)}
              className={cn(controlClass, 'w-full min-w-0')}
            />
          </div>
          <div className="flex min-w-0 flex-1 flex-col gap-1.5 sm:max-w-[9.5rem]">
            <Label htmlFor={`${idPrefix}-to`} className={labelClass}>
              Date to
            </Label>
            <Input
              id={`${idPrefix}-to`}
              type="date"
              value={dateTo}
              onChange={(e) => onDateToChange?.(e.target.value)}
              className={cn(controlClass, 'w-full min-w-0')}
            />
          </div>
          <Button
            type="button"
            variant="secondary"
            className={cn(controlClass, 'w-full sm:w-auto')}
            onClick={onApplyFilters}
            disabled={applyingFilters}
          >
            {applyingFilters ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
            Apply filters
          </Button>
          {onExportCsv ? (
            <Button
              type="button"
              variant="outline"
              className={cn(controlClass, 'w-full gap-2 sm:w-auto')}
              onClick={onExportCsv}
              disabled={exportingCsv || exportDisabled}
            >
              {exportingCsv ? (
                <Loader2 className="size-4 animate-spin" aria-hidden />
              ) : (
                <FileDown className="size-4" aria-hidden />
              )}
              Export CSV
            </Button>
          ) : null}
          {leftExtra}
        </div>

        {showBulkActions ? (
          <div className="flex w-full min-w-0 flex-col gap-3 border-t border-border/50 pt-4 lg:w-auto lg:max-w-2xl lg:flex-row lg:items-center lg:gap-3 lg:border-0 lg:border-l lg:border-border/50 lg:pl-4 lg:pt-0">
            <div className="flex min-w-0 flex-1 flex-col gap-1.5 lg:min-w-[12rem] lg:max-w-xs">
              <Label htmlFor={remarksId} className={labelClass}>
                Approval remarks
              </Label>
              <Textarea
                id={remarksId}
                value={remarks}
                onChange={(e) => onRemarksChange?.(e.target.value)}
                rows={1}
                placeholder="Optional remarks applied to approved requests"
                disabled={approving}
                className="min-h-10 max-h-16 resize-none py-2 text-sm leading-snug"
              />
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-3 sm:justify-end">
              <span
                className="text-sm font-medium tabular-nums text-muted-foreground"
                aria-live="polite"
              >
                <span className="font-semibold text-foreground">{count}</span> selected
              </span>
              <Button
                type="button"
                className={cn(controlClass, 'w-full gap-2 sm:w-auto')}
                disabled={approving || count === 0}
                onClick={onApproveClick}
              >
                {approving ? (
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                ) : (
                  <CheckCircle2 className="size-4" aria-hidden />
                )}
                {approveLabel}
              </Button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
