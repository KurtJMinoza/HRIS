import { ArrowRight, Building2, RefreshCw, WalletCards } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'

/**
 * Premium Expiring Contracts widget.
 *
 * UX goals:
 * - "Quiet luxury" (no alarming fills); urgency conveyed via a refined status chip.
 * - Strong hierarchy: Title → subtitle → person → org → dates → actions.
 * - Responsive: actions stack on mobile, sit side-by-side on >=sm.
 * - A11y: clear focus rings and button labels; avatar alt left empty (decorative).
 *
 * NOTE: We keep the card header text EXACT:
 * - "Expiring Contracts"
 * - "Contracts ending soon in your scope."
 * - "View All"
 */
export function ExpiringContractsCard({
  contracts = [],
  loading = false,
  onViewAll,
  onRenewContract,
  profileImageUrl,
}) {
  const rows = Array.isArray(contracts) ? contracts : []
  const previewRows = rows.slice(0, 1)

  return (
    <Card
      className={cn(
        'h-full gap-0 overflow-hidden rounded-2xl border border-border/70 bg-card/95 py-0 shadow-[0_1px_0_rgba(15,23,42,0.04),0_14px_34px_rgba(15,23,42,0.08)] transition-[transform,box-shadow] duration-300 hover:-translate-y-px hover:shadow-[0_1px_0_rgba(15,23,42,0.05),0_20px_50px_rgba(15,23,42,0.12)] dark:bg-card/90 dark:shadow-[0_1px_0_rgba(255,255,255,0.03),0_22px_60px_rgba(0,0,0,0.38)] @xl:h-[420px]',
        rows.length > 0 ? 'max-h-[420px]' : 'max-h-none',
      )}
    >
      <CardHeader className="px-7 pb-7 pt-8">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <CardTitle className="mb-4 flex items-center gap-2 truncate text-xl font-bold leading-snug tracking-tight text-foreground">
              <Building2 className="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <span className="truncate">Expiring Contracts</span>
            </CardTitle>
            <CardDescription className="mt-0 text-sm font-normal leading-[1.55] text-muted-foreground">
              Contracts ending soon in your scope.
            </CardDescription>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
              'mt-1 shrink-0 rounded-full border-border/70 bg-background/70 px-3.5',
              'text-sm font-medium',
              'shadow-sm shadow-black/5 hover:bg-accent/55 hover:shadow-black/10',
              'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              'transition-[background-color,box-shadow,color] duration-200',
            )}
            onClick={onViewAll}
          >
            View All
            <ArrowRight className="ml-1.5 size-3.5 opacity-70" aria-hidden="true" />
          </Button>
        </div>
      </CardHeader>

      <CardContent
        className={cn(
          'min-h-0 space-y-5 px-7 pb-8 pt-0 pr-5',
          rows.length > 0 ? 'overflow-hidden' : 'overflow-visible',
        )}
      >
        {loading ? (
          <div className="rounded-2xl border border-border/70 bg-muted/15 p-5 text-sm font-normal leading-[1.55] text-muted-foreground">
            Loading expiring contracts...
          </div>
        ) : rows.length === 0 ? (
          <div className="rounded-2xl border border-border/70 bg-muted/15 p-6 text-center text-base font-medium leading-[1.55] text-foreground/90">
            No expiring contracts.
          </div>
        ) : (
          <div className="space-y-5">
            {previewRows.map((c) => {
              const name = c?.name || '—'
              const type = c?.contract_type || 'Contractual'
              const org = `${c?.department || 'Unassigned'}${c?.branch ? ` / ${c.branch}` : ''}`
              const startEnd = `Start: ${formatShortDate(c?.contract_start_date)} • End: ${formatShortDate(c?.contract_end_date)}`
              const statusLabel = c?.days_remaining_label || '—' // e.g. "Expired today"

              // Refined urgency chip palette (subtle but clear).
              const tone = c?.days_tone || 'neutral' // backend: red/orange/neutral
              const badgeClass =
                tone === 'red'
                  ? 'border-rose-500/20 bg-rose-500/10 text-rose-800 dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-200'
                  : tone === 'orange'
                    ? 'border-amber-500/20 bg-amber-500/10 text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200'
                    : 'border-border/60 bg-muted/25 text-muted-foreground'

              const img = profileImageUrl ? profileImageUrl(c?.profile_image_url) : c?.profile_image_url

              const canAct = !!c?.actions?.can_review_contract

              return (
                <article
                  key={c?.id ?? `${name}-${c?.contract_end_date ?? ''}`}
                  className={cn(
                    'group rounded-2xl border border-border/70 bg-background/45 p-4',
                    'shadow-[inset_0_1px_0_rgba(255,255,255,0.03),0_1px_2px_rgba(15,23,42,0.05)]',
                    'transition-[transform,box-shadow,background-color,border-color] duration-250',
                    'hover:border-border hover:bg-accent/30 hover:shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_34px_rgba(15,23,42,0.08)]',
                    'hover:-translate-y-px',
                    'dark:bg-card/35 dark:hover:bg-accent/25',
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <div className="relative">
                        <Avatar className="size-11 shadow-md shadow-black/10 ring-1 ring-border/70">
                          <AvatarImage src={img} alt="" className="object-cover" />
                          <AvatarFallback className="bg-muted/40 text-xs font-semibold text-muted-foreground">
                            {String(name).slice(0, 1)}
                          </AvatarFallback>
                        </Avatar>
                        <div className="pointer-events-none absolute inset-0 rounded-full opacity-0 ring-1 ring-white/10 transition-opacity duration-200 group-hover:opacity-100" />
                      </div>

                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                          <p className="truncate text-sm font-semibold tracking-[-0.012em] text-foreground">{name}</p>
                          <span className="rounded-full border border-border/70 bg-muted/30 px-2 py-0.5 text-[11px] font-medium text-muted-foreground/90">
                            {type}
                          </span>
                        </div>
                        <p className="mt-1.5 truncate text-xs text-muted-foreground">Department: {org}</p>
                        <p className="mt-1 truncate text-xs text-muted-foreground">Dates: {startEnd}</p>
                      </div>
                    </div>

                    <Badge
                      variant="secondary"
                      className={cn(
                        'mt-0.5 shrink-0 rounded-full px-2.5 py-1',
                        'border text-[11px] font-semibold tracking-[-0.01em] shadow-[inset_0_1px_0_rgba(255,255,255,0.25)]',
                        badgeClass,
                      )}
                    >
                      {statusLabel}
                    </Badge>
                  </div>

                  {canAct ? (
                    <div className="mt-4">
                      <Button
                        type="button"
                        className={cn(
                          'h-10 w-full rounded-xl font-medium tracking-[-0.01em]',
                          'bg-foreground text-background hover:bg-foreground/92',
                          'shadow-[0_10px_24px_rgba(0,0,0,0.2)] hover:shadow-[0_16px_34px_rgba(0,0,0,0.24)]',
                          'dark:shadow-[0_14px_44px_rgba(0,0,0,0.45)]',
                          'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                          'transition-[background-color,box-shadow,transform] duration-200',
                          'active:translate-y-px',
                        )}
                        onClick={() => onRenewContract?.(c)}
                      >
                        <RefreshCw className="mr-2 size-4 opacity-90" aria-hidden="true" />
                        Renew Contract
                      </Button>
                    </div>
                  ) : (
                    <div className="mt-3 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                      <span className="inline-flex items-center gap-2">
                        <WalletCards className="size-4 opacity-70" aria-hidden="true" />
                        Actions unavailable for your role.
                      </span>
                    </div>
                  )}
                </article>
              )
            })}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function formatShortDate(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(`${String(iso).trim()}T12:00:00`)
    if (Number.isNaN(d.getTime())) return '—'
    // Keep existing dashboard style (short, readable).
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return '—'
  }
}

