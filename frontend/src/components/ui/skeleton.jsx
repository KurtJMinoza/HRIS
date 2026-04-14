import * as React from 'react'
import { cn } from '@/lib/utils'

function Skeleton({ className, ...props }) {
  return (
    <div
      className={cn(
        'animate-pulse rounded-md',
        // Light mode: darker neutral so skeleton is clearly visible on light backgrounds
        'bg-neutral-200 dark:bg-muted',
        className
      )}
      data-slot="skeleton"
      {...props}
    />
  )
}

export { Skeleton }
