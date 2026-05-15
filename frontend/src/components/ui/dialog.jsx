"use client"

import * as React from "react"
import { motion } from "framer-motion"
import { Dialog as DialogPrimitive } from "radix-ui"
import { XIcon } from "lucide-react"

import { cn } from "@/lib/utils"

const DURATION = 0.2
const EASE = [0.23, 1, 0.32, 1]

function Dialog(props) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger(props) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal(props) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogOverlay({ className, ...props }) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-black/45 backdrop-blur-[2px] data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

function DialogContent({
  className,
  children,
  showCloseButton = true,
  innerClassName,
  closeButtonClassName,
  overlayClassName,
  /** Applied to the animated modal surface (motion.div), e.g. width/maxWidth when Tailwind conflicts with defaults. */
  surfaceStyle,
  ...props
}) {
  return (
    <DialogPortal>
      <DialogOverlay className={overlayClassName} />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed inset-0 z-50 flex items-center justify-center px-4",
          "sm:px-0"
        )}
        {...props}
      >
        {React.createElement(
          motion.div,
          {
            style: surfaceStyle,
            initial: { opacity: 0, scale: 0.96 },
            animate: { opacity: 1, scale: 1 },
            transition: { duration: DURATION, ease: EASE },
            className: cn(
              "relative mx-auto flex max-h-[90vh] min-h-0 w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-border/80 bg-card shadow-[0_0_0_1px_rgba(0,0,0,0.04),0_8px_30px_rgba(0,0,0,0.08)] dark:shadow-[0_0_0_1px_rgba(255,255,255,0.06),0_8px_40px_rgba(0,0,0,0.45)]",
              "sm:max-w-xl",
              className
            ),
          },
          <>
            {showCloseButton && (
              <DialogPrimitive.Close
                type="button"
                className={cn(
                  "absolute right-3 top-3 z-20 inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-border/60 bg-background/95 text-foreground shadow-sm",
                  "opacity-100 ring-offset-background transition-colors hover:bg-muted hover:text-foreground",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                  "disabled:pointer-events-none",
                  closeButtonClassName
                )}
              >
                <XIcon className="size-4" aria-hidden />
                <span className="sr-only">Close</span>
              </DialogPrimitive.Close>
            )}
            <div
              className={cn(
                "flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 pb-5 pl-5 pr-14 pt-5",
                innerClassName
              )}
            >
              {children}
            </div>
          </>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-1.5", className)}
      {...props}
    />
  )
}

function DialogFooter({ className, ...props }) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn("flex flex-col gap-2 pt-3 sm:flex-row sm:justify-end", className)}
      {...props}
    />
  )
}

function DialogTitle({ className, ...props }) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn("hr-dialog-title leading-tight", className)}
      {...props}
    />
  )
}

function DialogDescription({ className, ...props }) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("text-sm leading-relaxed text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogTrigger,
  DialogPortal,
  DialogOverlay,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
}

