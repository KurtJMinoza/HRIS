import { cn } from '@/lib/utils'

/** Shared layout for leave / overtime request listing tables (admin + employee). */
export const requestModuleTableClass =
  'w-full table-fixed border-collapse text-sm'

export const leaveEmployeeTableClass =
  'w-full min-w-[82rem] table-fixed border-collapse text-sm'

export const leaveAdminTableClass =
  'w-full min-w-[108rem] table-fixed border-collapse text-sm'

export const overtimeAdminTableClass =
  'w-full min-w-[104rem] table-fixed border-collapse text-sm'

export const overtimeEmployeeTableClass =
  'w-full min-w-[88rem] table-fixed border-collapse text-sm'

export const requestModuleHeadRowClass =
  'border-b border-border/70 bg-muted/30 text-left dark:border-white/10 dark:bg-card/80'

export const requestModuleThClass =
  'px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-normal leading-snug'

export const requestModuleThRightClass =
  'px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-normal leading-snug'

export const requestModuleTdClass =
  'px-4 py-4 align-middle text-sm text-foreground'

export const requestModuleTdMutedClass =
  'px-4 py-4 align-middle text-sm tabular-nums text-muted-foreground'

export const requestModuleActionsTdClass =
  'px-4 py-4 align-middle text-right'

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
  'h-9 gap-1.5 rounded-lg px-3 text-xs font-semibold shrink-0'
