/**
 * Shared form-dialog shell (Holiday-style): gradient header, glass `DialogContent`, footer bar, black primary actions.
 * Use for **admin and employee** modals — import from this module in both contexts.
 */

import { cn } from '@/lib/utils'

/** DialogContent base: flex column, no padding, glass + shadow (pair with showCloseButton). */
export const ADMIN_FORM_DIALOG_SHELL_CLASS =
  'flex max-h-[min(92vh,880px)] w-full flex-col gap-0 overflow-hidden p-0 border-border/60 bg-card/95 shadow-2xl backdrop-blur-md dark:bg-card/90'

/** Default max width (~42rem), same as Holiday form. */
export const ADMIN_FORM_DIALOG_MAX_W_DEFAULT = 'max-w-[min(100vw-1rem,42rem)]'

export const ADMIN_FORM_DIALOG_MAX_W_SM = 'max-w-[min(100vw-1rem,24rem)]'
export const ADMIN_FORM_DIALOG_MAX_W_MD = 'max-w-[min(100vw-1rem,28rem)]'
export const ADMIN_FORM_DIALOG_MAX_W_LG = 'max-w-[min(100vw-1rem,32rem)]'
/** ~Tailwind xl (36rem) */
export const ADMIN_FORM_DIALOG_MAX_W_XL = 'max-w-[min(100vw-1rem,36rem)]'

/** Wide request-details modals (corrections, leave) — matches admin Attendance Corrections viewer. */
export const ADMIN_VIEW_REQUEST_DIALOG_MAX = 'max-w-[min(100vw-1rem,56rem)]'

export const ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS =
  'border-b border-border/50 bg-gradient-to-r from-indigo-500/10 via-teal-500/5 to-transparent px-5 py-4 dark:from-indigo-950/40 dark:via-teal-950/20'

export const ADMIN_FORM_DIALOG_HEADER_INNER_CLASS = 'space-y-1 text-left'

export const ADMIN_FORM_DIALOG_TITLE_CLASS = 'hr-dialog-title tracking-tight'

export const ADMIN_FORM_DIALOG_DESC_CLASS = 'hr-helper'

/** Scrollable body between header and footer. */
export const ADMIN_FORM_DIALOG_BODY_CLASS = 'min-h-0 flex-1 overflow-y-auto px-5 py-4'

export const ADMIN_FORM_DIALOG_FOOTER_CLASS =
  'shrink-0 gap-2 border-t border-border/50 bg-muted/20 px-5 py-4 dark:bg-muted/10'

/** Primary submit — black (replaces indigo #4F39F6 family for admin modal actions). */
export const ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS =
  'min-w-[120px] bg-black text-white hover:bg-neutral-900 dark:bg-black dark:text-white dark:hover:bg-neutral-900'

/**
 * @param {string} [maxWidthClass]
 * @param {string} [className]
 */
export function adminFormDialogContentClass(maxWidthClass = ADMIN_FORM_DIALOG_MAX_W_DEFAULT, className) {
  return cn(ADMIN_FORM_DIALOG_SHELL_CLASS, maxWidthClass, className)
}
