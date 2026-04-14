import * as React from 'react'
import { RadioGroup as RadioGroupPrimitive } from 'radix-ui'
import { Circle } from 'lucide-react'
import { cn } from '@/lib/utils'

const RadioGroup = React.forwardRef(({ className, ...props }, ref) => (
  <RadioGroupPrimitive.Root className={cn('grid gap-2', className)} {...props} ref={ref} />
))
RadioGroup.displayName = 'RadioGroup'

const RadioGroupItem = React.forwardRef(({ className, ...props }, ref) => (
  <RadioGroupPrimitive.Item
    ref={ref}
    className={cn(
      'aspect-square size-4 rounded-full border border-primary text-primary shadow-xs focus:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
      className,
    )}
    {...props}
  >
    <RadioGroupPrimitive.Indicator className="flex items-center justify-center">
      <Circle className="size-2.5 fill-primary text-primary" />
    </RadioGroupPrimitive.Indicator>
  </RadioGroupPrimitive.Item>
))
RadioGroupItem.displayName = 'RadioGroupItem'

export { RadioGroup, RadioGroupItem }
