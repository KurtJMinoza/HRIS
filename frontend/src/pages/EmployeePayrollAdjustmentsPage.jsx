import { useCallback, useEffect, useState } from 'react'
import { Loader2, Undo2 } from 'lucide-react'
import { getMyPayrollAdjustments } from '@/api'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { REFUND_DIRECTIONS, REFUND_STATUSES, formatPeso } from '@/lib/refundConstants'
import { cn } from '@/lib/utils'

const PAGE_SHELL = 'w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-6 lg:px-8'
const CARD_SHELL = 'rounded-[1.35rem] border border-border/70 bg-card text-card-foreground shadow-[0_14px_40px_rgba(15,23,42,0.06)] dark:shadow-black/25'

function formatDate(value) {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
      .format(new Date(`${value}T12:00:00`))
  } catch {
    return value
  }
}

export default function EmployeePayrollAdjustmentsPage() {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getMyPayrollAdjustments()
      setRows(Array.isArray(res.data) ? res.data : [])
    } catch {
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  return (
    <div className={PAGE_SHELL}>
      <header className="mb-4">
        <h1 className="text-2xl font-extrabold tracking-tight">Payroll Adjustments</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Processed and pending payroll corrections applied outside the original payslip run.
        </p>
      </header>

      <Card className={CARD_SHELL}>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Undo2 className="size-5" /> My Adjustments</CardTitle>
          <CardDescription>Attendance, OT, holiday, and leave corrections queued or already paid on a later payroll.</CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex items-center justify-center py-12"><Loader2 className="size-6 animate-spin text-muted-foreground" /></div>
          ) : rows.length === 0 ? (
            <p className="py-10 text-center text-sm text-muted-foreground">No payroll adjustments on record.</p>
          ) : (
            <div className="overflow-hidden rounded-xl border border-border/60">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Reference</TableHead>
                    <TableHead>Reason</TableHead>
                    <TableHead>Affected date</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Applied payroll</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="font-mono text-xs">{row.refund_number}</TableCell>
                      <TableCell>{row.reason_label}</TableCell>
                      <TableCell>{formatDate(row.affected_date)}</TableCell>
                      <TableCell>{REFUND_DIRECTIONS[row.direction]?.label || row.direction}</TableCell>
                      <TableCell className={cn('text-right font-mono font-semibold tabular-nums', row.direction === 'overpayment' && 'text-red-600')}>
                        {formatPeso(row.amount)}
                      </TableCell>
                      <TableCell>
                        <Badge variant="secondary">{REFUND_STATUSES[row.status]?.label || row.status}</Badge>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {row.applied_payroll_period
                          ? `${formatDate(row.applied_payroll_period.start)} → ${formatDate(row.applied_payroll_period.end)}`
                          : '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
