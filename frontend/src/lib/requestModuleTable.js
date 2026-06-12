import { cn } from '@/lib/utils'

/** Shared layout for leave / overtime request listing tables (admin + employee). */
export const requestModuleTableClass =
  'w-full min-w-0 table-fixed border-collapse text-sm'

export const leaveEmployeeTableClass =
  requestModuleTableClass

export const leaveAdminTableClass =
  requestModuleTableClass

export const overtimeAdminTableClass =
  requestModuleTableClass

export const overtimeEmployeeTableClass =
  requestModuleTableClass

export const requestModuleHeadRowClass =
  'border-b border-border/70 bg-muted/30 text-left dark:border-white/10 dark:bg-card/80'

export const requestModuleThClass =
  'px-2.5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground whitespace-normal leading-snug @xl:px-3'

export const requestModuleThRightClass =
  'px-2.5 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-muted-foreground whitespace-normal leading-snug @xl:px-3'

export const requestModuleTdClass =
  'px-2.5 py-3.5 align-middle text-[13px] leading-snug text-foreground @xl:px-3'

export const requestModuleTdMutedClass =
  'px-2.5 py-3.5 align-middle text-[13px] leading-snug tabular-nums text-muted-foreground @xl:px-3'

export const requestModuleActionsTdClass =
  'px-2.5 py-3.5 align-middle text-right @xl:px-3'

export const requestModuleActionsWrapClass =
  'inline-flex flex-col items-end justify-center gap-1'

/** Horizontal action buttons that wrap on narrow columns. */
export const requestModuleActionsWrapRowClass =
  'flex flex-wrap items-center justify-end gap-1.5'

export function requestModuleRowClass(rowIdx, extra = '') {
  return cn(
    'border-b border-border/55 transition-colors duration-150 hover:bg-brand/5 dark:hover:bg-white/[0.045]',
    rowIdx % 2 === 0 ? 'bg-card' : 'bg-muted/20 dark:bg-white/[0.02]',
    extra,
  )
}

export const requestModuleCompactButtonClass =
  'h-8 gap-1 rounded-lg px-2 text-[11px] font-semibold shrink-0'
