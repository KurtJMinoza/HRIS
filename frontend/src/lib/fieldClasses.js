/**
 * Shared native control classes — design tokens (index.css), aligned with Admin Daily computation / Employees.
 */

export const FIELD_SELECT_CLASS =
  'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm text-foreground shadow-xs dark:border-border/50 dark:bg-input/30 dark:[color-scheme:dark] focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

/** Compact native select (toolbar filters) */
export const FIELD_SELECT_CLASS_H8 =
  'h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground shadow-xs dark:border-border/50 dark:bg-input/30 dark:[color-scheme:dark] focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

export const FIELD_SELECT_CLASS_H10 =
  'flex h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm text-foreground ring-offset-background shadow-xs dark:border-border/50 dark:bg-input/30 dark:[color-scheme:dark] focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50'

export const FIELD_TEXTAREA_CLASS =
  'flex min-h-[72px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm text-foreground ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-none dark:border-border/50 dark:bg-input/30 dark:[color-scheme:dark]'

export const FIELD_TEXTAREA_CLASS_SM =
  'flex min-h-[70px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm text-foreground ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-none dark:border-border/50 dark:bg-input/30 dark:[color-scheme:dark]'

/**
 * Legacy modal panel (flat card). For admin CRUD/forms, prefer the Holiday-aligned shell in
 * `adminFormDialogStyles.js` (`adminFormDialogContentClass`, header/body/footer classes).
 */
export const DIALOG_CONTENT_CLASS =
  'max-w-md border border-border/60 shadow-xl dark:border-border/50 bg-card'
