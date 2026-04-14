'use client'

import {
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_FOOTER,
  APP_MODAL_FOOTER_ACTIONS,
  APP_MODAL_HEADER,
  APP_MODAL_TITLE_CLASS,
} from '@/lib/appModalStyles'

export function AppModalHeader({ className, children, ...props }) {
  return (
    <DialogHeader className={cn(APP_MODAL_HEADER, className)} {...props}>
      {children}
    </DialogHeader>
  )
}

export function AppModalTitle({ className, ...props }) {
  return <DialogTitle className={cn(APP_MODAL_TITLE_CLASS, className)} {...props} />
}

export function AppModalDescription({ className, ...props }) {
  return <DialogDescription className={cn(APP_MODAL_DESCRIPTION_CLASS, className)} {...props} />
}

/**
 * Footer with cancel-left / actions-right layout. Pass optional `leading` for summary text.
 */
export function AppModalFooter({ className, leading, actions, children, ...props }) {
  if (children) {
    return (
      <DialogFooter className={cn(APP_MODAL_FOOTER, className)} {...props}>
        {children}
      </DialogFooter>
    )
  }
  return (
    <DialogFooter className={cn(APP_MODAL_FOOTER, className)} {...props}>
      {leading ? <div className="min-w-0 text-sm text-muted-foreground">{leading}</div> : <span />}
      <div className={cn(APP_MODAL_FOOTER_ACTIONS)}>{actions}</div>
    </DialogFooter>
  )
}
