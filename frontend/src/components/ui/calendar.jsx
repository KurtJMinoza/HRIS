import * as React from 'react'
import { DayPicker } from 'react-day-picker'
import { cn } from '@/lib/utils'

import 'react-day-picker/style.css'

/** Single-date picker styled for shadcn + Tailwind; uses react-day-picker v9. */
function Calendar({ className, ...props }) {
  return (
    <DayPicker
      className={cn(
        'rounded-xl border border-border/60 bg-card p-3 shadow-sm [--rdp-accent-color:theme(colors.indigo.600)] [--rdp-background-color:theme(colors.card)] dark:[--rdp-accent-color:theme(colors.teal.500)]',
        className,
      )}
      {...props}
    />
  )
}
Calendar.displayName = 'Calendar'

export { Calendar }
