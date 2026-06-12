import { cn } from '@/lib/utils'

/** Shared layout for leave / overtime request listing tables (admin + employee). */
export const requestModuleTableClass =
  'w-full min-w-0 table-fixed border-collapse text-[12px]'

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
  'px-1.5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-normal text-muted-foreground whitespace-normal leading-snug @xl:px-2'

export const requestModuleThRightClass =
  'px-1.5 py-2.5 text-right text-[10px] font-semibold uppercase tracking-normal text-muted-foreground whitespace-normal leading-snug @xl:px-2'

export const requestModuleTdClass =
  'px-1.5 py-2.5 align-middle text-[12px] leading-snug text-foreground @xl:px-2'

export const requestModuleTdMutedClass =
  'px-1.5 py-2.5 align-middle text-[12px] leading-snug tabular-nums text-muted-foreground @xl:px-2'

export const requestModuleActionsTdClass =
  'px-1.5 py-2.5 align-middle text-right @xl:px-2'

export const requestModuleActionsWrapClass =
  'inline-flex flex-col items-end justify-center gap-1'

/** Horizontal action buttons that wrap on narrow columns. */
export const requestModuleActionsWrapRowClass =
  'flex flex-wrap items-center justify-end gap-1'

export function requestModuleRowClass(rowIdx, extra = '') {
  return cn(
    'border-b border-border/55 transition-colors duration-150 hover:bg-brand/5 dark:hover:bg-white/[0.045]',
    rowIdx % 2 === 0 ? 'bg-card' : 'bg-muted/20 dark:bg-white/[0.02]',
    extra,
  )
}

export const requestModuleCompactButtonClass =
  'h-7 gap-1 rounded-md px-1.5 text-[10px] font-semibold shrink-0'
