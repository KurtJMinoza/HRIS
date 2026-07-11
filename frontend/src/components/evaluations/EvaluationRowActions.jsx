import { Eye, Send, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

export const evalActionBtnClass =
  'size-8 shrink-0 rounded-lg border-border/70 bg-background text-muted-foreground shadow-sm hover:bg-muted/50 dark:border-white/10 dark:bg-card/80'

const toneClass = {
  default: 'hover:border-border hover:text-foreground',
  success: 'hover:border-emerald-500/35 hover:bg-emerald-500/10 hover:text-emerald-700 dark:hover:text-emerald-300',
  warning: 'hover:border-amber-500/35 hover:bg-amber-500/10 hover:text-amber-700 dark:hover:text-amber-300',
  danger: 'hover:border-destructive/35 hover:bg-destructive/10 hover:text-destructive',
}

export function EvalActionButton({ label, onClick, tone = 'default', children, className }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className={cn(evalActionBtnClass, toneClass[tone], className)}
          onClick={onClick}
          aria-label={label}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent side="top">{label}</TooltipContent>
    </Tooltip>
  )
}

/**
 * Shared evaluation row actions for Admin HR and org heads (company/branch/dept heads).
 */
export default function EvaluationRowActions({
  evaluation,
  onView,
  onSubmit,
  onDelete,
  className,
}) {
  if (!evaluation) return null

  return (
    <div className={cn('inline-flex items-center justify-end gap-1.5', className)}>
      <EvalActionButton label="View details" onClick={() => onView?.(evaluation)}>
        <Eye className="size-4" />
      </EvalActionButton>

      {evaluation.status === 'draft' && (
        <>
          <EvalActionButton label="Submit evaluation" tone="success" onClick={() => onSubmit?.(evaluation.id)}>
            <Send className="size-4" />
          </EvalActionButton>
          <EvalActionButton label="Delete draft" tone="danger" onClick={() => onDelete?.(evaluation.id)}>
            <Trash2 className="size-4" />
          </EvalActionButton>
        </>
      )}
    </div>
  )
}
