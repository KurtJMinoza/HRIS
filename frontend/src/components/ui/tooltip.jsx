import * as React from 'react'
import { Tooltip as TooltipPrimitive } from 'radix-ui'
import { cn } from '@/lib/utils'

function TooltipProvider({ delayDuration = 200, skipDelayDuration = 200, children, ...props }) {
  return (
    <TooltipPrimitive.Provider delayDuration={delayDuration} skipDelayDuration={skipDelayDuration} {...props}>
      {children}
    </TooltipPrimitive.Provider>
  )
}

const Tooltip = TooltipPrimitive.Root

const TooltipTrigger = TooltipPrimitive.Trigger

const TooltipContent = React.forwardRef(({ className, sideOffset = 6, ...props }, ref) => (
  <TooltipPrimitive.Portal>
    <TooltipPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 max-w-xs overflow-hidden rounded-md border border-border/60 bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95',
        className,
      )}
      {...props}
    />
  </TooltipPrimitive.Portal>
))
TooltipContent.displayName = 'TooltipContent'

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider }
