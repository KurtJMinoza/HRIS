/**
 * Shared admin modal design system — aligns with Pay Cycle / My Schedule wide dialogs:
 * neutral `bg-card`, `border-border/60`, flush inner + px-8/py-7 headers, 5xl/6xl widths.
 *
 * Use with `DialogContent`:
 * - `className={appModalDialogContentClass({ size: 'md' | 'lg' | 'sm' })}`
 * - `innerClassName={APP_MODAL_INNER_FLUSH}` when the shell controls padding (forms with custom body/footer).
 */

import { cn } from '@/lib/utils'

/** Outer motion.shell: no inner padding (children manage layout). */
export const APP_MODAL_SHELL =
  'flex max-h-[min(92vh,880px)] w-full flex-col overflow-hidden border-border/60 bg-card p-0 shadow-2xl dark:border-border/50'

/** ~max-w-5xl — default forms / previews. */
export const APP_MODAL_SIZE_MD =
  'max-w-[min(100vw-1.25rem,64rem)] sm:max-w-[min(100vw-2rem,64rem)]'

/** ~max-w-6xl — two-column configurators (pay cycle, assign employees). */
export const APP_MODAL_SIZE_LG =
  'max-w-[min(100vw-1.25rem,72rem)] sm:max-w-[min(100vw-2rem,72rem)]'

/** Compact confirms / destructive dialogs. */
export const APP_MODAL_SIZE_SM =
  'max-w-[min(100vw-1.25rem,28rem)] sm:max-w-[min(100vw-2rem,28rem)]'

export const APP_MODAL_INNER_FLUSH = 'gap-0 overflow-hidden p-0 pr-0'

/** Header band under close button (title + description). */
export const APP_MODAL_HEADER = 'border-b border-border/60 px-8 py-7'

export const APP_MODAL_TITLE_CLASS = 'text-2xl font-semibold tracking-tight text-foreground'

export const APP_MODAL_DESCRIPTION_CLASS = 'max-w-2xl text-sm leading-relaxed text-muted-foreground'

/** Single-column form body (scrollable region between header and footer). */
export const APP_MODAL_FORM_BODY = 'min-h-0 flex-1 space-y-6 overflow-y-auto px-8 py-7'

/** Right-hand preview column shell (no padding — use when inner sections manage px). */
export const APP_MODAL_PREVIEW_COLUMN_FRAME =
  'min-h-0 border-t border-border/60 bg-muted/15 lg:border-t-0 lg:border-l dark:border-border/50 dark:bg-muted/10'

/** Right-hand preview column (Pay Cycle — padded). */
export const APP_MODAL_PREVIEW_COLUMN = cn(APP_MODAL_PREVIEW_COLUMN_FRAME, 'p-8')

/**
 * Footer: meta/summary on the left, actions on the right (Cancel + primary).
 * Pair action buttons with `APP_MODAL_FOOTER_ACTIONS`.
 */
export const APP_MODAL_FOOTER =
  'shrink-0 flex flex-col gap-3 border-t border-border/60 bg-muted/15 px-8 py-6 sm:flex-row sm:items-center sm:justify-between dark:border-border/50 dark:bg-muted/10'

/** Group: Cancel + primary grouped on the right (when no left meta). */
export const APP_MODAL_FOOTER_ACTIONS = 'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-2'

/** Matches Pay Cycle / admin configurators (black primary). */
export const APP_MODAL_PRIMARY_BUTTON_CLASS =
  'min-w-[120px] rounded-lg bg-black text-white shadow-sm hover:bg-neutral-900 dark:bg-black dark:text-white dark:hover:bg-neutral-900'

export const APP_MODAL_OUTLINE_BUTTON_CLASS =
  'min-w-[100px] rounded-lg border-border/60 bg-background shadow-sm hover:bg-muted/50'

/**
 * @param {{ size?: 'sm' | 'md' | 'lg', className?: string }} [opts]
 */
export function appModalDialogContentClass({ size = 'md', className } = {}) {
  const sz =
    size === 'lg' ? APP_MODAL_SIZE_LG : size === 'sm' ? APP_MODAL_SIZE_SM : APP_MODAL_SIZE_MD
  return cn(APP_MODAL_SHELL, sz, className)
}
