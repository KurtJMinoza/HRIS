import * as React from 'react'

import { cn } from '@/lib/utils'

/**
 * Accessible progress bar (shadcn-style). Pass `indicatorClassName` for dynamic fill color.
 * `value` is 0–100.
 */
function Progress({ className, value = 0, indicatorClassName, ...props }) {
  const pct = Math.min(100, Math.max(0, Number(value) || 0))

  return (
    <div
      data-slot="progress"
      role="progressbar"
      aria-valuenow={Math.round(pct)}
      aria-valuemin={0}
      aria-valuemax={100}
      className={cn('relative h-3 w-full min-w-0 overflow-hidden rounded-full bg-muted/70 dark:bg-muted/40', className)}
      {...props}
    >
      <div
        className={cn('h-full rounded-full transition-[width] duration-500 ease-out', indicatorClassName)}
        style={{ width: `${pct}%` }}
      />
    </div>
  )
}

export { Progress }
