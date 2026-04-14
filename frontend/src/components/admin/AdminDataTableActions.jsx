import { Link } from 'react-router-dom'
import { Eye, Check, X, ExternalLink, Sparkles } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * Standard admin table row actions: View (outline), optional Profile link,
 * Approve (emerald), Reject (destructive). Text labels show from `sm:` up; icon-only on narrow viewports with title/aria-label.
 */
export function AdminDataTableActions({
  className,
  onView,
  viewLabel = 'View',
  viewAriaLabel = 'View details',
  profileHref,
  profileLabel = 'Profile',
  showApprove = false,
  onApprove,
  showReject = false,
  onReject,
  showSubmitRecommendation = false,
  onSubmitRecommendation,
  disabled = false,
}) {
  const stop = (fn) => (e) => {
    e.stopPropagation()
    fn?.(e)
  }

  return (
    <div
      className={cn(
        'flex max-w-full flex-nowrap items-center justify-end gap-1.5 overflow-hidden',
        className,
      )}
      role="group"
      aria-label="Row actions"
    >
      {onView != null && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(
            'h-8 shrink-0 gap-1.5 rounded-lg border-border/80 px-2 text-xs font-semibold shadow-sm',
            'bg-background hover:bg-muted/70 hover:text-foreground',
            'focus-visible:ring-2 focus-visible:ring-ring/40',
          )}
          onClick={stop(onView)}
          disabled={disabled}
          title={viewAriaLabel}
          aria-label={viewAriaLabel}
        >
          <Eye className="size-3.5 shrink-0 opacity-90" aria-hidden />
          <span className="hidden sm:inline">{viewLabel}</span>
        </Button>
      )}

      {profileHref ? (
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-8 shrink-0 gap-1.5 rounded-lg border-border/80 px-2 text-xs font-semibold shadow-sm',
            'bg-background hover:bg-muted/70',
          )}
          asChild
        >
          <Link
            to={profileHref}
            title={profileLabel}
            aria-label={profileLabel}
            onClick={stop()}
            className="inline-flex items-center justify-center"
          >
            <ExternalLink className="size-3.5 shrink-0 opacity-90" aria-hidden />
            <span className="hidden sm:inline">{profileLabel}</span>
          </Link>
        </Button>
      ) : null}

      {showSubmitRecommendation && onSubmitRecommendation ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled}
          className={cn(
            'h-8 shrink-0 gap-1.5 rounded-lg border-violet-200/90 bg-violet-50 px-2 text-xs font-semibold shadow-sm',
            'text-violet-950 hover:bg-violet-100 dark:border-violet-500/35 dark:bg-violet-950/40 dark:text-violet-50 dark:hover:bg-violet-950/55',
            'focus-visible:ring-2 focus-visible:ring-violet-500/35',
          )}
          onClick={stop(onSubmitRecommendation)}
          title="Submit regularization recommendation"
          aria-label="Submit regularization recommendation"
        >
          <Sparkles className="size-3.5 shrink-0 opacity-90" aria-hidden />
          <span className="hidden sm:inline">Submit</span>
        </Button>
      ) : null}

      {showApprove && onApprove ? (
        <Button
          type="button"
          size="sm"
          disabled={disabled}
          className={cn(
            'h-8 shrink-0 gap-1.5 rounded-lg border-0 px-2 text-xs font-semibold shadow-sm',
            'bg-emerald-600 text-white hover:bg-emerald-700',
            'focus-visible:ring-2 focus-visible:ring-emerald-500/40',
          )}
          onClick={stop(onApprove)}
          title="Approve"
          aria-label="Approve"
        >
          <Check className="size-3.5 shrink-0" strokeWidth={2.5} aria-hidden />
          <span className="hidden sm:inline">Approve</span>
        </Button>
      ) : null}

      {showReject && onReject ? (
        <Button
          type="button"
          variant="destructive"
          size="sm"
          disabled={disabled}
          className={cn(
            'h-8 shrink-0 gap-1.5 rounded-lg px-2 text-xs font-semibold shadow-sm',
            'focus-visible:ring-2 focus-visible:ring-destructive/30',
          )}
          onClick={stop(onReject)}
          title="Reject"
          aria-label="Reject"
        >
          <X className="size-3.5 shrink-0" strokeWidth={2.5} aria-hidden />
          <span className="hidden sm:inline">Reject</span>
        </Button>
      ) : null}
    </div>
  )
}
