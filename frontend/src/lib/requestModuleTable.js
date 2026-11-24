import { cn } from '@/lib/utils'

/** Shared layout for leave / overtime request listing tables (admin + employee). */
export const requestModuleTableClass =
  'w-full min-w-0 table-fixed border-collapse text-[12px]'

export const leaveEmployeeTableClass = cn(requestModuleTableClass, '!table-auto')

export const leaveAdminTableClass = cn(requestModuleTableClass, 'min-w-[62rem] !table-auto')

/** OT admin: fixed layout keeps sticky date/actions aligned with headers when scrolling. */
export const overtimeAdminTableClass = cn(
  'w-full min-w-0 border-collapse text-[12px] table-fixed',
  'min-w-[86rem]',
  'request-module-table',
)

export const overtimeEmployeeTableClass = cn(
  'w-full min-w-0 border-collapse text-[12px] table-auto',
  'request-module-table',
)

export const requestModuleHeadRowClass =
  'border-b border-border/70 bg-muted/30 text-left dark:border-white/10 dark:bg-card/80'

export const requestModuleThClass =
  'px-1.5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-normal text-muted-foreground whitespace-normal leading-snug @xl:px-2'

export const requestModuleThRightClass =
  'px-1.5 py-2.5 text-right text-[10px] font-semibold uppercase tracking-normal text-muted-foreground whitespace-normal leading-snug @xl:px-2'

export const requestModuleTdClass =
  'px-1.5 py-2.5 align-middle text-[12px] leading-snug text-foreground @xl:px-2'

export const requestModuleTdMutedClass =
  'px-1.5 py-2.5 align-middle text-[12px] leading-snug tabular-nums text-muted-foreground @xl:px-2'

/** OT date + time range — keep single-line (Nov 17, 2026 / 5:00 PM - 6:00 PM). */
export const requestModuleOtDateColClass = 'w-[8.25rem] min-w-[8.25rem]'
export const requestModuleOtTimeRangeColClass = 'w-[11.75rem] min-w-[11.75rem]'
export const requestModuleOtDateCellClass = cn(requestModuleTdMutedClass, 'whitespace-nowrap')
export const requestModuleOtTimeRangeCellClass = cn(
  requestModuleTdMutedClass,
  'whitespace-nowrap font-mono text-[13px] leading-snug',
)

/** Filed / date-requested column — wide enough for header + date on one line. */
export const requestModuleDateFiledColClass = 'w-[10.75rem] min-w-[10.75rem]'

/** Trailing sticky columns — opaque cells so scrolled content does not bleed through. */
const requestModuleStickyThSurfaceClass =
  'bg-muted/95 shadow-[-6px_0_10px_-8px_rgba(0,0,0,0.12)] backdrop-blur-sm supports-backdrop-filter:bg-muted/85 dark:bg-card/95'

const requestModuleStickyTdSurfaceClass = 'bg-card dark:bg-card'

/** Match `requestModuleActionsColClass` width (10.25rem) so sticky date sits flush left of actions. */
export const requestModuleDateFiledStickyInsetClass = 'right-[10.25rem]'

/** Actions (10.25rem) + date filed (10.75rem) — approver sticks left of the date/actions pair. */
export const requestModuleApproverStickyInsetClass = 'right-[21rem]'

export const requestModuleDateFiledThClass = cn(
  requestModuleThRightClass,
  requestModuleDateFiledColClass,
  'sticky z-[3] whitespace-nowrap',
  requestModuleDateFiledStickyInsetClass,
  requestModuleStickyThSurfaceClass,
)

export const requestModuleDateFiledTdClass = cn(
  requestModuleTdMutedClass,
  requestModuleDateFiledColClass,
  'sticky z-[3] whitespace-nowrap text-right',
  requestModuleDateFiledStickyInsetClass,
  requestModuleStickyTdSurfaceClass,
  'shadow-[-4px_0_8px_-8px_rgba(0,0,0,0.1)]',
)

export const requestModuleApproverColClass = 'w-[11.5rem] min-w-[11.5rem]'

export const requestModuleApproverThClass = cn(
  requestModuleThClass,
  requestModuleApproverColClass,
  'sticky z-[2] whitespace-nowrap',
  requestModuleApproverStickyInsetClass,
  requestModuleStickyThSurfaceClass,
)

export const requestModuleApproverTdClass = cn(
  requestModuleTdClass,
  requestModuleApproverColClass,
  'sticky z-[2] min-w-0',
  requestModuleApproverStickyInsetClass,
  requestModuleStickyTdSurfaceClass,
  'shadow-[-4px_0_8px_-8px_rgba(0,0,0,0.06)]',
)

/** Mine-tab tables: no trailing sticky stack (avoids overlap on narrower layouts). */
export const requestModuleApproverThPlainClass = cn(
  requestModuleThClass,
  requestModuleApproverColClass,
  'whitespace-nowrap',
)

export const requestModuleApproverTdPlainClass = cn(
  requestModuleTdClass,
  requestModuleApproverColClass,
  'min-w-0',
)

export const requestModuleDateFiledThPlainClass = cn(
  requestModuleThRightClass,
  requestModuleDateFiledColClass,
  'whitespace-nowrap',
)

export const requestModuleDateFiledTdPlainClass = cn(
  requestModuleTdMutedClass,
  requestModuleDateFiledColClass,
  'whitespace-nowrap text-right',
)

export const requestModuleOtStatusColClass = 'w-[9.25rem] min-w-[9.25rem]'

export const requestModuleOtStatusCellClass = cn(
  requestModuleTdClass,
  requestModuleOtStatusColClass,
  'min-w-0',
)

/** Admin OT: drop low-priority columns on medium-wide viewports (cards below lg). */
export const requestModuleOtAdminOptionalColClass = 'hidden 2xl:table-cell'

/** Reserved width for dense icon row actions (view / approve / reject / edit / delete). */
export const requestModuleActionsColClass = 'w-[10.25rem] min-w-[10.25rem] max-w-[10.25rem]'

export const requestModuleActionsThClass = cn(
  requestModuleThRightClass,
  'sticky right-0 z-[4]',
  requestModuleActionsColClass,
  'bg-muted/95 shadow-[-6px_0_10px_-8px_rgba(0,0,0,0.18)] backdrop-blur-sm supports-backdrop-filter:bg-muted/85 dark:bg-card/95',
)

export const requestModuleActionsTdClass = cn(
  'sticky right-0 z-[4] whitespace-nowrap px-1 py-2 align-middle text-right @xl:px-1.5',
  requestModuleActionsColClass,
  // ponytail: inherit row tint (e.g. leave pending amber) — avoid forced bg-card on sticky actions
  'bg-inherit',
  'shadow-[-6px_0_10px_-8px_rgba(0,0,0,0.14)]',
)

export const requestModuleActionsThPlainClass = cn(
  requestModuleThRightClass,
  requestModuleActionsColClass,
)

export const requestModuleActionsTdPlainClass = cn(
  'whitespace-nowrap px-1 py-2 align-middle text-right @xl:px-1.5',
  requestModuleActionsColClass,
)

export const requestModuleActionsWrapClass =
  'inline-flex flex-col items-end justify-center gap-1'

/** Horizontal action buttons; nowrap keeps approve/reject/view on one row (icons in dense tables). */
export const requestModuleActionsWrapRowClass =
  'flex w-full max-w-full flex-nowrap items-center justify-end gap-1 overflow-hidden'

/** Wide request tables (preview + many columns): scroll horizontally instead of squashing cells. */
export const requestModuleTableScrollWrapClass =
  'min-w-0 overflow-x-auto overscroll-x-contain'

export const requestModuleOtAdminTableScrollClass = cn(
  requestModuleTableScrollWrapClass,
  'scroll-ps-1 scroll-pe-[11rem] [scrollbar-gutter:stable]',
)

export function requestModuleRowClass(rowIdx, extra = '') {
  return cn(
    'border-b border-border/55',
    rowIdx % 2 === 0 ? 'bg-card' : 'bg-muted/20 dark:bg-white/[0.02]',
    extra,
  )
}

export const requestModuleCompactButtonClass =
  'h-7 gap-1 rounded-md px-1.5 text-[10px] font-semibold shrink-0'
