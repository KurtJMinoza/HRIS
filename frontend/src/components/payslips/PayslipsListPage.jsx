import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMyPayslipPdfBlob, getMyPayslips } from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import { ArrowRight, CalendarDays, CalendarRange, Download, Eye, FileText, Loader2, TrendingUp } from 'lucide-react'

function formatPeso(n) {
  const v = Number(n)
  const peso = '₱'
  if (!Number.isFinite(v)) return `${peso}0.00`
  return `${peso}${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatPeriodRange(start, end) {
  const a = formatDateShort(start)
  const b = formatDateShort(end)
  if (!a && !b) return '—'
  if (!a) return b
  if (!b) return a
  return `${a} – ${b}`
}

function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateShort(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function toYmd(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function startOfQuarter(d = new Date()) {
  const m = d.getMonth()
  const qStart = Math.floor(m / 3) * 3
  return new Date(d.getFullYear(), qStart, 1)
}

function endOfQuarter(d = new Date()) {
  const s = startOfQuarter(d)
  return new Date(s.getFullYear(), s.getMonth() + 3, 0)
}

const DATE_PRESETS = [
  { id: 'all', label: 'All dates' },
  { id: '30d', label: 'Last 30 days' },
  { id: 'quarter', label: 'This quarter' },
  { id: 'year', label: 'This year' },
  { id: 'custom', label: 'Custom' },
]

function buildPageList(current, last) {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1)
  }
  const pages = new Set([1, last, current, current - 1, current + 1].filter((p) => p >= 1 && p <= last))
  const sorted = [...pages].sort((a, b) => a - b)
  const out = []
  let prev = 0
  for (const p of sorted) {
    if (prev && p - prev > 1) out.push('…')
    out.push(p)
    prev = p
  }
  return out
}

function MiniSparkline({ values, className }) {
  if (!values?.length || values.length < 2) return null
  const min = Math.min(...values)
  const max = Math.max(...values)
  const range = max - min || 1
  const w = 132
  const h = 40
  const pad = 3
  const points = values
    .map((v, i) => {
      const x = pad + (i / (values.length - 1)) * (w - 2 * pad)
      const y = pad + (1 - (v - min) / range) * (h - 2 * pad)
      return `${x},${y}`
    })
    .join(' ')

  return (
    <svg viewBox={`0 0 ${w} ${h}`} className={cn('shrink-0 text-muted-foreground', className)} aria-hidden>
      <polyline
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        points={points}
      />
    </svg>
  )
}

/**
 * Own delivered payslips only — {@link EmployeePayslipController} (`delivered_at` + published statuses).
 * `variant="employee"`: `/employee/payslips/view/:id`. `variant="hr"`: HR panel preview route (org heads).
 */
export default function PayslipsListPage({ variant }) {
  const isHr = variant === 'hr'
  const { user } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState([])
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [datePreset, setDatePreset] = useState('all')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [totalCount, setTotalCount] = useState(0)
  const [rangeFrom, setRangeFrom] = useState(0)
  const [rangeTo, setRangeTo] = useState(0)
  const [downloadingId, setDownloadingId] = useState(null)
  const [latestHero, setLatestHero] = useState(null)
  const [recentForChart, setRecentForChart] = useState([])

  const perPage = 10

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getMyPayslips({
        per_page: perPage,
        page,
        from_date: fromDate || undefined,
        to_date: toDate || undefined,
        status: 'all',
      })
      setRows(Array.isArray(data?.data) ? data.data : [])
      setLastPage(Math.max(1, Number(data?.last_page) || 1))
      setTotalCount(Number(data?.total) || 0)
      setRangeFrom(Number(data?.from) || 0)
      setRangeTo(Number(data?.to) || 0)
    } catch (e) {
      toast({ title: 'Could not load payslips', description: e.message, variant: 'destructive' })
      setRows([])
      setLastPage(1)
      setTotalCount(0)
      setRangeFrom(0)
      setRangeTo(0)
    } finally {
      setLoading(false)
    }
  }, [fromDate, toDate, page, toast, perPage])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const d = await getMyPayslips({ per_page: 8, page: 1 })
        const list = Array.isArray(d?.data) ? d.data : []
        if (!cancelled) {
          setLatestHero(list[0] ?? null)
          setRecentForChart(list.slice(0, 8))
        }
      } catch {
        if (!cancelled) {
          setLatestHero(null)
          setRecentForChart([])
        }
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  const applyDatePreset = (id) => {
    setDatePreset(id)
    setPage(1)
    const now = new Date()
    if (id === 'all') {
      setFromDate('')
      setToDate('')
      return
    }
    if (id === '30d') {
      const start = new Date(now)
      start.setDate(start.getDate() - 30)
      setFromDate(toYmd(start))
      setToDate(toYmd(now))
      return
    }
    if (id === 'quarter') {
      setFromDate(toYmd(startOfQuarter(now)))
      setToDate(toYmd(endOfQuarter(now)))
      return
    }
    if (id === 'year') {
      setFromDate(toYmd(new Date(now.getFullYear(), 0, 1)))
      setToDate(toYmd(new Date(now.getFullYear(), 11, 31)))
    }
  }

  const sorted = useMemo(() => {
    return [...rows].sort((a, b) => {
      const ae = new Date(a.pay_period_end || 0).getTime()
      const be = new Date(b.pay_period_end || 0).getTime()
      return be - ae
    })
  }, [rows])

  const chartSeries = useMemo(() => {
    const arr = [...recentForChart].sort(
      (a, b) => new Date(a.pay_period_end || 0).getTime() - new Date(b.pay_period_end || 0).getTime(),
    )
    return arr.map((r) => Number(r.net_pay) || 0).filter((n) => Number.isFinite(n))
  }, [recentForChart])

  const quickStats = useMemo(() => {
    if (chartSeries.length === 0) return null
    const sum = chartSeries.reduce((a, b) => a + b, 0)
    const avg = sum / chartSeries.length
    const delta =
      chartSeries.length >= 2 ? chartSeries[chartSeries.length - 1] - chartSeries[chartSeries.length - 2] : null
    return { sum, avg, delta, n: chartSeries.length }
  }, [chartSeries])

  const openView = (id) => {
    if (isHr) {
      navigate(`${hrBase}/compensation/payslips/${id}/view`, {
        state: { payslipBackTo: `${hrBase}/compensation/payslips` },
      })
    } else {
      navigate(`${hrBase}/payslips/view/${id}`, { state: { payslipBackTo: `${hrBase}/payslips` } })
    }
  }

  const handleDownloadPdf = async (e, id) => {
    e?.stopPropagation?.()
    setDownloadingId(id)
    toast({ title: 'Preparing PDF…', description: 'Your download will start in a moment.' })
    try {
      const blob = await getMyPayslipPdfBlob(id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `payslip-${id}.pdf`
      a.click()
      URL.revokeObjectURL(url)
      toast({ title: 'Download started', description: 'Check your downloads folder.' })
    } catch (err) {
      toast({ title: 'Download failed', description: err.message, variant: 'destructive' })
    } finally {
      setDownloadingId(null)
    }
  }

  const colSpan = 4
  const pageNumbers = useMemo(() => buildPageList(page, lastPage), [page, lastPage])

  const hero = latestHero
  const heroNet = hero ? formatPeso(hero.net_pay) : '—'
  const heroPeriod = hero ? formatPeriodRange(hero.pay_period_start, hero.pay_period_end) : '—'
  const heroPayDate = hero ? formatDate(hero.pay_date) : '—'
  const displayName = String(user?.name || 'there').trim() || 'there'

  const filterSummary =
    fromDate && toDate
      ? `${formatDateShort(fromDate)} – ${formatDateShort(toDate)}`
      : fromDate
        ? `From ${formatDateShort(fromDate)}`
        : toDate
          ? `Through ${formatDateShort(toDate)}`
          : 'Any pay date'

  return (
    <div className="w-full min-w-0 max-w-none space-y-8 bg-background px-3 py-5 pb-12 text-foreground sm:space-y-10 sm:px-4 md:px-5 lg:space-y-12 lg:px-6 lg:py-6 3xl:px-10">
      <header className="space-y-2">
        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand">Compensation</p>
        <h1 className="text-[1.75rem] font-bold tracking-tight text-foreground @md:text-[2.125rem]">Payslips</h1>
        <p className="max-w-3xl text-[15px] leading-relaxed text-muted-foreground">
          Your payslips appear here after payroll is finalized and HR releases them with{' '}
          <span className="font-medium text-foreground">Send payslips</span>.
        </p>
      </header>

      <section
        className="rounded-2xl border border-border bg-card p-6 shadow-sm @md:p-8"
        aria-label="Latest payment"
      >
        {hero ? (
          <div className="grid gap-8 @lg:grid-cols-[1fr_auto] @lg:items-center @lg:gap-10">
            <div className="min-w-0 space-y-5">
              <div className="flex flex-wrap items-center gap-3">
                <span className="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-brand/10 px-3 py-1 text-sm font-medium text-brand dark:bg-brand/15">
                  <CalendarDays className="h-4 w-4" aria-hidden />
                  Pay date {heroPayDate}
                </span>
              </div>

              <div>
                <p className="text-[13px] font-medium text-muted-foreground">
                  Latest payslip · <span className="text-foreground">{displayName}</span>
                </p>
                <p
                  className="mt-2 text-[clamp(2.25rem,6vw,3.25rem)] font-bold leading-none tracking-tight tabular-nums text-foreground"
                  style={{ fontFeatureSettings: '"tnum"' }}
                >
                  {heroNet}
                </p>
                <p className="mt-3 text-base font-medium text-muted-foreground">
                  Net pay · <span className="text-foreground">{heroPeriod}</span>
                </p>
              </div>

              <div className="flex flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center">
                <Button
                  type="button"
                  className="h-11 rounded-xl bg-brand px-7 text-[15px] font-semibold text-brand-foreground shadow-sm transition hover:bg-brand-strong"
                  onClick={() => openView(Number(hero.id))}
                >
                  View full breakdown
                  <ArrowRight className="ml-2 h-4 w-4" aria-hidden />
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-xl border-border bg-card px-6 text-[15px] font-semibold text-foreground hover:bg-muted/60"
                  disabled={downloadingId === Number(hero.id)}
                  onClick={(e) => handleDownloadPdf(e, Number(hero.id))}
                >
                  {downloadingId === Number(hero.id) ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Download className="mr-2 h-4 w-4" />
                  )}
                  Download PDF
                </Button>
              </div>
            </div>

            <div className="flex flex-row items-center justify-end gap-6 @lg:flex-col @lg:items-end">
              <div className="flex flex-col items-end gap-2 text-right">
                {chartSeries.length >= 2 ? (
                  <>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                      Recent trend
                    </p>
                    <MiniSparkline values={chartSeries} className="h-10 w-[132px]" />
                  </>
                ) : (
                  <div className="rounded-xl border border-brand/25 bg-brand/10 px-4 py-3 text-right text-xs text-brand dark:bg-brand/15">
                    More payslips unlock a net-pay trend chart.
                  </div>
                )}
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center py-6 text-center @md:flex-row @md:items-center @md:gap-10 @md:text-left">
            <div className="mb-6 flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl bg-muted @md:mb-0">
              <FileText className="h-11 w-11 text-muted-foreground" strokeWidth={1.25} aria-hidden />
            </div>
            <div>
              <p className="text-lg font-semibold text-foreground">No payslips yet</p>
              <p className="mt-2 max-w-md text-[15px] leading-relaxed text-muted-foreground">
                When HR finalizes payroll and sends your payslip, your latest net pay and history will show up here.
              </p>
            </div>
          </div>
        )}
      </section>

        {/* Quick stats */}
        {quickStats ? (
          <div className="grid gap-4 @sm:grid-cols-3">
            {[
              {
                label: `Net (last ${quickStats.n} periods)`,
                value: formatPeso(quickStats.sum),
                sub: 'From your recent payslips',
                icon: TrendingUp,
              },
              {
                label: 'Average net',
                value: formatPeso(quickStats.avg),
                sub: 'Same window',
                icon: CalendarRange,
              },
              {
                label: 'Vs prior period',
                value:
                  quickStats.delta == null
                    ? '—'
                    : `${quickStats.delta >= 0 ? '+' : ''}${formatPeso(quickStats.delta)}`,
                sub: quickStats.delta == null ? 'Need 2+ payslips' : 'Most recent minus previous',
                icon: FileText,
              },
            ].map((m) => (
              <div
                key={m.label}
                className="rounded-2xl border border-border bg-card p-5 shadow-sm transition hover:bg-muted/40"
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">{m.label}</p>
                    <p className="mt-2 text-xl font-bold tabular-nums text-foreground @md:text-2xl">{m.value}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{m.sub}</p>
                  </div>
                  <span
                    className={cn(
                      'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                      m.label.startsWith('Net') && 'bg-brand/15 text-brand',
                      m.label === 'Average net' &&
                        'bg-emerald-500/12 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300',
                      m.label === 'Vs prior period' &&
                        'bg-violet-500/12 text-violet-700 dark:bg-violet-400/15 dark:text-violet-300',
                    )}
                  >
                    <m.icon className="h-5 w-5" aria-hidden />
                  </span>
                </div>
              </div>
            ))}
          </div>
        ) : null}

        <Card className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
          <div className="border-b border-border bg-card px-6 py-5 @md:px-8">
            <h2 className="text-lg font-semibold text-foreground">Historical payslips</h2>
            <p className="mt-1 text-sm text-muted-foreground">Filter by pay period, then open or download.</p>
          </div>
          <CardContent className="space-y-6 p-6 @md:p-8">
            <div className="space-y-4">
              <Label className="text-xs font-semibold uppercase tracking-[0.1em] text-muted-foreground">Date range</Label>
              <div className="flex flex-wrap gap-2">
                {DATE_PRESETS.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => {
                      if (p.id === 'custom') {
                        setDatePreset('custom')
                        return
                      }
                      applyDatePreset(p.id)
                    }}
                    className={cn(
                      'rounded-full border px-4 py-2 text-sm font-semibold text-foreground transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                      datePreset === p.id
                        ? 'border-brand bg-brand text-brand-foreground shadow-sm'
                        : 'border-border bg-card hover:bg-muted/50',
                    )}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
              <p className="text-sm text-muted-foreground">{filterSummary}</p>
              {datePreset === 'custom' ? (
                <div className="grid max-w-xl gap-4 @md:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="payslips-from" className="text-muted-foreground">
                      From
                    </Label>
                    <Input
                      id="payslips-from"
                      type="date"
                      value={fromDate}
                      onChange={(e) => {
                        setFromDate(e.target.value)
                        setPage(1)
                      }}
                      className="h-11 rounded-xl border-border bg-background text-foreground"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="payslips-to" className="text-muted-foreground">
                      To
                    </Label>
                    <Input
                      id="payslips-to"
                      type="date"
                      value={toDate}
                      onChange={(e) => {
                        setToDate(e.target.value)
                        setPage(1)
                      }}
                      className="h-11 rounded-xl border-border bg-background text-foreground"
                    />
                  </div>
                </div>
              ) : null}
            </div>

            {/* Desktop table — borderless rows, light hover */}
            <div className="hidden overflow-x-auto rounded-xl bg-muted/30 @md:block">
              <Table>
                <TableHeader className="[&_tr]:border-0">
                  <TableRow className="border-0 bg-transparent hover:bg-transparent">
                    <TableHead className="py-3.5 pl-4 text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">
                      Pay period
                    </TableHead>
                    <TableHead className="py-3.5 text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">
                      Pay date
                    </TableHead>
                    <TableHead className="py-3.5 text-right text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">
                      Net pay
                    </TableHead>
                    <TableHead className="py-3.5 pr-4 text-right text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">
                      Actions
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-border">
                  {loading ? (
                    <TableRow className="border-0 hover:bg-transparent">
                      <TableCell colSpan={colSpan} className="py-20 text-center">
                        <Loader2 className="mx-auto mb-3 h-8 w-8 animate-spin text-muted-foreground" />
                        <p className="text-sm font-medium text-muted-foreground">Loading your payslips…</p>
                      </TableCell>
                    </TableRow>
                  ) : sorted.length === 0 ? (
                    <TableRow className="border-0 hover:bg-transparent">
                      <TableCell colSpan={colSpan} className="py-16 text-center">
                        <FileText className="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" strokeWidth={1.25} />
                        <p className="font-medium text-foreground">No payslips match these filters</p>
                        <p className="mt-1 text-sm text-muted-foreground">Try another date range.</p>
                      </TableCell>
                    </TableRow>
                  ) : (
                    sorted.map((row) => {
                      const id = Number(row.id)
                      const periodLine = formatPeriodRange(row.pay_period_start, row.pay_period_end)
                      const sub = row.cycle_label ? String(row.cycle_label) : 'Pay cycle'
                      return (
                        <TableRow
                          key={id}
                          className="group cursor-pointer border-0 transition hover:bg-muted/40"
                          onClick={() => openView(id)}
                        >
                          <TableCell className="max-w-[240px] py-4 pl-4 align-middle">
                            <div className="flex items-start gap-3">
                              <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand/15 text-brand transition group-hover:bg-brand/25">
                                <FileText className="h-4 w-4" aria-hidden />
                              </span>
                              <div>
                                <p className="text-[15px] font-semibold leading-snug text-foreground">{periodLine}</p>
                                <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>
                              </div>
                            </div>
                          </TableCell>
                          <TableCell className="py-4 align-middle text-[15px] text-muted-foreground">
                            {formatDate(row.pay_date)}
                          </TableCell>
                          <TableCell className="py-4 align-middle text-right text-lg font-bold tabular-nums text-foreground">
                            {formatPeso(row.net_pay)}
                          </TableCell>
                          <TableCell className="py-4 pr-4 text-right align-middle" onClick={(e) => e.stopPropagation()}>
                            <div className="flex flex-nowrap justify-end gap-2">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-9 shrink-0 gap-1.5 rounded-lg border-border font-semibold text-foreground hover:bg-muted/60"
                                onClick={() => openView(id)}
                              >
                                <Eye className="h-4 w-4" aria-hidden />
                                View
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-9 shrink-0 gap-1.5 rounded-lg border-border font-semibold text-foreground hover:bg-muted/60"
                                disabled={downloadingId === id}
                                onClick={(e) => handleDownloadPdf(e, id)}
                              >
                                {downloadingId === id ? (
                                  <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                  <Download className="h-4 w-4" aria-hidden />
                                )}
                                PDF
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })
                  )}
                </TableBody>
              </Table>
            </div>

            {/* Mobile list — no card borders */}
            <div className="divide-y divide-border @md:hidden">
              {loading ? (
                <div className="flex flex-col items-center bg-card py-14">
                  <Loader2 className="mb-3 h-8 w-8 animate-spin text-muted-foreground" />
                  <p className="text-sm text-muted-foreground">Loading…</p>
                </div>
              ) : sorted.length === 0 ? (
                <div className="bg-muted/30 px-4 py-12 text-center">
                  <FileText className="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" strokeWidth={1.25} />
                  <p className="font-medium text-foreground">No payslips match these filters</p>
                </div>
              ) : (
                sorted.map((row) => {
                  const id = Number(row.id)
                  const periodLine = formatPeriodRange(row.pay_period_start, row.pay_period_end)
                  return (
                    <div key={id} className="bg-card transition hover:bg-muted/40">
                      <button
                        type="button"
                        className="w-full p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        onClick={() => openView(id)}
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="text-[15px] font-semibold text-foreground">{periodLine}</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">{formatDate(row.pay_date)}</p>
                          </div>
                          <p className="shrink-0 text-lg font-bold tabular-nums text-foreground">{formatPeso(row.net_pay)}</p>
                        </div>
                      </button>
                      <div className="flex flex-wrap items-center justify-end gap-2 px-4 pb-4">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 rounded-lg border-border font-semibold text-foreground"
                          onClick={() => openView(id)}
                        >
                          View
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 rounded-lg border-border font-semibold text-foreground"
                          disabled={downloadingId === id}
                          onClick={(e) => void handleDownloadPdf(e, id)}
                        >
                          PDF
                        </Button>
                      </div>
                    </div>
                  )
                })
              )}
            </div>

            {totalCount > 0 ? (
              <div className="flex flex-col gap-4 border-t border-border pt-6 @md:flex-row @md:items-center @md:justify-between">
                <p className="text-sm text-muted-foreground">
                  Showing{' '}
                  <span className="font-semibold text-foreground">
                    {rangeFrom}–{rangeTo}
                  </span>{' '}
                  of <span className="font-semibold text-foreground">{totalCount}</span> payslips
                </p>
                {lastPage > 1 ? (
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 min-w-9 rounded-xl border-border"
                      disabled={page <= 1 || loading}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                      ‹
                    </Button>
                    {pageNumbers.map((p, idx) =>
                      p === '…' ? (
                        <span key={`pg-${idx}`} className="px-1 text-muted-foreground/50">
                          …
                        </span>
                      ) : (
                        <Button
                          key={p}
                          type="button"
                          variant="outline"
                          size="sm"
                          className={cn(
                            'h-9 min-w-9 rounded-xl px-3',
                            page === p
                              ? 'border-brand bg-brand text-brand-foreground hover:bg-brand-strong'
                              : 'border-border text-foreground hover:bg-muted/50',
                          )}
                          disabled={loading}
                          onClick={() => setPage(p)}
                        >
                          {p}
                        </Button>
                      ),
                    )}
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 min-w-9 rounded-xl border-border"
                      disabled={page >= lastPage || loading}
                      onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                    >
                      ›
                    </Button>
                  </div>
                ) : null}
              </div>
            ) : null}
          </CardContent>
        </Card>
    </div>
  )
}
