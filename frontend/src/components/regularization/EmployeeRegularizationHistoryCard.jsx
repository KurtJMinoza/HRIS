import { useCallback, useEffect, useState } from 'react'
import { Loader2, History } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { getMyRegularizationHistory, profileImageUrl } from '@/api'
import { EmployeeAvatarNameCell } from '@/components/presenceFiling/CorrectionTableCells'
import { RegularizationStatusBadge } from '@/components/regularization/RegularizationStatusBadge'
import { RegularizationRecommendationViewDialog } from '@/components/regularization/RegularizationRecommendationViewDialog'

const REC_TYPES = [
  { value: 'probation_to_regular', label: 'Probation to Regular' },
  { value: 'contract_renewal', label: 'Contract Renewal' },
  { value: 'contract_extension', label: 'Contract Extension' },
  { value: 'end_contract', label: 'End Contract' },
  { value: 'project_extension', label: 'Project Extension' },
  { value: 'project_completion', label: 'Project Completion' },
  { value: 'performance_based', label: 'Performance-Based' },
]

function recommendationTypeLabel(value) {
  return REC_TYPES.find((t) => t.value === value)?.label || value || '—'
}

function formatDateTimeSubmitted(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return '—'
    return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return '—'
  }
}

const cellPad = '!p-3'

/**
 * Self-service: all regularization recommendations where the current user is the subject employee.
 */
export function EmployeeRegularizationHistoryCard() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [viewOpen, setViewOpen] = useState(false)
  const [active, setActive] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getMyRegularizationHistory()
      setItems(Array.isArray(data?.recommendations) ? data.recommendations : [])
    } catch {
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  if (loading) {
    return (
      <div className="mt-6 flex items-center justify-center gap-2 rounded-xl border border-border/60 bg-muted/15 py-10 text-sm text-muted-foreground">
        <Loader2 className="size-5 animate-spin text-primary" aria-hidden />
        Loading regularization history…
      </div>
    )
  }

  if (items.length === 0) {
    return null
  }

  return (
    <>
      <Card className="mt-6 overflow-hidden rounded-2xl border-border/80 shadow-sm">
        <CardHeader className="border-b border-border/60 bg-muted/20 pb-3">
          <CardTitle className="flex items-center gap-2 text-base font-semibold">
            <History className="size-5 text-primary" aria-hidden />
            My regularization history
          </CardTitle>
          <CardDescription className="text-sm">
            Recommendations filed about your employment status (pending, approved, or rejected). This is your personal audit trail
            only — not attendance corrections.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <div className="w-full min-w-0 touch-pan-x overflow-x-auto px-2 pb-4 pt-2 sm:px-4">
            <Table className="w-full min-w-[640px]">
              <TableHeader>
                <TableRow className="border-b border-border/60 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25 dark:hover:bg-muted/25">
                  <TableHead className="py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">ID</TableHead>
                  <TableHead className="min-w-[10rem] py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Submitted
                  </TableHead>
                  <TableHead className="hidden py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:table-cell">
                    Type
                  </TableHead>
                  <TableHead className="py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</TableHead>
                  <TableHead className="w-[6.5rem] py-3 pr-2 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((rec, rowIdx) => {
                  const img = profileImageUrl(rec.employee_profile_image)
                  return (
                    <TableRow
                      key={rec.id}
                      className={cn(
                        'border-b border-border/50 text-sm transition-colors hover:bg-muted/25',
                        rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/15 dark:bg-muted/10',
                      )}
                    >
                      <TableCell className={cn('font-mono text-xs font-semibold tabular-nums', cellPad)}>#{rec.id}</TableCell>
                      <TableCell className={cn('align-top', cellPad)}>
                        <p className="text-xs tabular-nums text-muted-foreground">{formatDateTimeSubmitted(rec.recommended_at)}</p>
                        <div className="mt-2">
                          <EmployeeAvatarNameCell name={rec.employee_name} imageUrl={img} compact />
                        </div>
                      </TableCell>
                      <TableCell className={cn('hidden align-top sm:table-cell', cellPad)}>
                        {recommendationTypeLabel(rec.recommendation_type)}
                      </TableCell>
                      <TableCell className={cn('align-top', cellPad)}>
                        <RegularizationStatusBadge status={rec.status} processed={rec.processed} />
                      </TableCell>
                      <TableCell className={cn('text-right align-middle', cellPad)}>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 rounded-lg text-xs font-semibold"
                          onClick={() => {
                            setActive(rec)
                            setViewOpen(true)
                          }}
                        >
                          View details
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
          <p className="border-t border-border/50 px-4 py-3 text-[11px] text-muted-foreground">
            Open <strong className="font-medium text-foreground">View details</strong> for the full submission basis, HR decision, and
            timestamps.
          </p>
        </CardContent>
      </Card>

      <RegularizationRecommendationViewDialog
        open={viewOpen}
        onOpenChange={(open) => {
          setViewOpen(open)
          if (!open) setActive(null)
        }}
        rec={active}
        employeeProfileHref={null}
      />
    </>
  )
}
