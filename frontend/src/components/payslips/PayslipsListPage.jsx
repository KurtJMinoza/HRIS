import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMyPayslipPdfBlob, getMyPayslips, userProfileImageSrc } from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import { ArrowRight, CalendarRange, Download, Eye, FileText, Loader2, TrendingUp } from 'lucide-react'

const TEXT = 'text-[#0A0A0A]'

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
    <svg viewBox={`0 0 ${w} ${h}`} className={cn('shrink-0 text-slate-500', className)} aria-hidden>
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

function initials(name) {
  const s = String(name || '').trim()
  if (!s) return '?'
  const parts = s.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  return s.slice(0, 2).toUpperCase()
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
  const avatarSrc = userProfileImageSrc(user)

  const filterSummary =
    fromDate && toDate
      ? `${formatDateShort(fromDate)} – ${formatDateShort(toDate)}`
      : fromDate
        ? `From ${formatDateShort(fromDate)}`
        : toDate
          ? `Through ${formatDateShort(toDate)}`
          : 'Any pay date'

  return (
    <div className="mx-auto w-full min-w-0 max-w-7xl space-y-8 bg-slate-50 px-3 py-5 pb-12 sm:space-y-10 sm:px-4 md:px-5 lg:space-y-12 lg:px-6 lg:py-6 2xl:max-w-[min(90rem,100%)] 3xl:max-w-[min(100rem,100%)] 3xl:px-10">
      <header className="space-y-2">
        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">Compensation</p>
        <h1 className={cn('text-[1.75rem] font-bold tracking-tight text-[#0A0A0A] @md:text-[2.125rem]', TEXT)}>Payslips</h1>
        <p className="max-w-3xl text-[15px] leading-relaxed text-[#0A0A0A]/65">
          Your payslips appear here after payroll is finalized and HR releases them with{' '}
          <span className="font-medium text-[#0A0A0A]">Send payslips</span>.
        </p>
      </header>

      <section
        className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm @md:p-8"
        aria-label="Latest payment"
      >
        {hero ? (
          <div className="grid gap-8 @lg:grid-cols-[1fr_auto] @lg:items-center @lg:gap-10">
            <div className="min-w-0 space-y-5">
              <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-medium text-[#0A0A0A]/60">Pay date {heroPayDate}</span>
              </div>

              <div>
                <p className="text-[13px] font-medium text-[#0A0A0A]/55">
                  Latest payslip · <span className="text-[#0A0A0A]">{displayName}</span>
                </p>
                <p
                  className="mt-2 text-[clamp(2.25rem,6vw,3.25rem)] font-bold leading-none tracking-tight tabular-nums text-[#0A0A0A]"
                  style={{ fontFeatureSettings: '"tnum"' }}
                >
                  {heroNet}
                </p>
                <p className="mt-3 text-base font-medium text-[#0A0A0A]/65">
                  Net pay · <span className="text-[#0A0A0A]">{heroPeriod}</span>
                </p>
              </div>

              <div className="flex flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center">
                <Button
                  type="button"
                  className="h-11 rounded-xl bg-[#0A0A0A] px-7 text-[15px] font-semibold text-white shadow-sm transition hover:bg-[#0A0A0A]/90"
                  onClick={() => openView(Number(hero.id))}
                >
                  View full breakdown
                  <ArrowRight className="ml-2 h-4 w-4" aria-hidden />
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-xl border-slate-200 bg-white px-6 text-[15px] font-semibold text-[#0A0A0A] hover:bg-slate-50"
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

            <div className="flex flex-row items-center justify-between gap-6 @lg:flex-col @lg:items-end">
              <Avatar className="h-16 w-16 border border-slate-200 bg-white shadow-sm @md:h-20 @md:w-20">
                <AvatarImage src={avatarSrc || undefined} alt="" />
                <AvatarFallback className="bg-slate-100 text-base font-bold text-[#0A0A0A]">
                  {initials(displayName)}
                </AvatarFallback>
              </Avatar>

              <div className="flex flex-col items-end gap-2 text-right">
                {chartSeries.length >= 2 ? (
                  <>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/45">
                      Recent trend
                    </p>
                    <MiniSparkline values={chartSeries} className="h-10 w-[132px]" />
                  </>
                ) : (
                  <div className="rounded-xl bg-slate-50 px-4 py-3 text-right text-xs text-[#0A0A0A]/50">
                    More payslips unlock a net-pay trend chart.
                  </div>
                )}
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center py-6 text-center @md:flex-row @md:items-center @md:gap-10 @md:text-left">
            <div className="mb-6 flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl bg-slate-100 @md:mb-0">
              <FileText className="h-11 w-11 text-slate-400" strokeWidth={1.25} aria-hidden />
            </div>
            <div>
              <p className="text-lg font-semibold text-[#0A0A0A]">No payslips yet</p>
              <p className="mt-2 max-w-md text-[15px] leading-relaxed text-[#0A0A0A]/60">
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
                className="rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm transition hover:bg-slate-50/80"
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/50">{m.label}</p>
                    <p className="mt-2 text-xl font-bold tabular-nums text-[#0A0A0A] @md:text-2xl">{m.value}</p>
                    <p className="mt-1 text-xs text-[#0A0A0A]/55">{m.sub}</p>
                  </div>
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-[#0A0A0A]">
                    <m.icon className="h-5 w-5" aria-hidden />
                  </span>
                </div>
              </div>
            ))}
          </div>
        ) : null}

        <Card className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
          <div className="border-b border-slate-100 bg-white px-6 py-5 @md:px-8">
            <h2 className="text-lg font-semibold text-[#0A0A0A]">Historical payslips</h2>
            <p className="mt-1 text-sm text-[#0A0A0A]/60">Filter by pay period, then open or download.</p>
          </div>
          <CardContent className="space-y-6 p-6 @md:p-8">
            <div className="space-y-4">
              <Label className="text-xs font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/45">Date range</Label>
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
                      'rounded-full border px-4 py-2 text-sm font-semibold text-[#0A0A0A] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A0A0A]/20',
                      datePreset === p.id
                        ? 'border-[#0A0A0A] bg-[#0A0A0A] text-white shadow-sm'
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50',
                    )}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
              <p className="text-sm text-[#0A0A0A]/50">{filterSummary}</p>
              {datePreset === 'custom' ? (
                <div className="grid max-w-xl gap-4 @md:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="payslips-from" className="text-[#0A0A0A]/70">
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
                      className="h-11 rounded-xl border-slate-200 text-[#0A0A0A] focus-visible:ring-[#0A0A0A]/15"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="payslips-to" className="text-[#0A0A0A]/70">
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
                      className="h-11 rounded-xl border-slate-200 text-[#0A0A0A] focus-visible:ring-[#0A0A0A]/15"
                    />
                  </div>
                </div>
              ) : null}
            </div>

            {/* Desktop table — borderless rows, light hover */}
            <div className="hidden overflow-x-auto rounded-xl bg-slate-50/40 @md:block">
              <Table>
                <TableHeader className="[&_tr]:border-0">
                  <TableRow className="border-0 bg-transparent hover:bg-transparent">
                    <TableHead className="py-3.5 pl-4 text-[11px] font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/50">
                      Pay period
                    </TableHead>
                    <TableHead className="py-3.5 text-[11px] font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/50">
                      Pay date
                    </TableHead>
                    <TableHead className="py-3.5 text-right text-[11px] font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/50">
                      Net pay
                    </TableHead>
                    <TableHead className="py-3.5 pr-4 text-right text-[11px] font-semibold uppercase tracking-[0.1em] text-[#0A0A0A]/50">
                      Actions
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-slate-100/90">
                  {loading ? (
                    <TableRow className="border-0 hover:bg-transparent">
                      <TableCell colSpan={colSpan} className="py-20 text-center">
                        <Loader2 className="mx-auto mb-3 h-8 w-8 animate-spin text-[#0A0A0A]/35" />
                        <p className="text-sm font-medium text-[#0A0A0A]/60">Loading your payslips…</p>
                      </TableCell>
                    </TableRow>
                  ) : sorted.length === 0 ? (
                    <TableRow className="border-0 hover:bg-transparent">
                      <TableCell colSpan={colSpan} className="py-16 text-center">
                        <FileText className="mx-auto mb-3 h-10 w-10 text-slate-300" strokeWidth={1.25} />
                        <p className="font-medium text-[#0A0A0A]">No payslips match these filters</p>
                        <p className="mt-1 text-sm text-[#0A0A0A]/55">Try another date range.</p>
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
                          className="group cursor-pointer border-0 transition hover:bg-white"
                          onClick={() => openView(id)}
                        >
                          <TableCell className="max-w-[240px] py-4 pl-4 align-middle">
                            <div className="flex items-start gap-3">
                              <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[#0A0A0A] transition group-hover:bg-slate-200/80">
                                <FileText className="h-4 w-4" aria-hidden />
                              </span>
                              <div>
                                <p className="text-[15px] font-semibold leading-snug text-[#0A0A0A]">{periodLine}</p>
                                <p className="mt-0.5 text-xs text-[#0A0A0A]/50">{sub}</p>
                              </div>
                            </div>
                          </TableCell>
                          <TableCell className="py-4 align-middle text-[15px] text-[#0A0A0A]/70">
                            {formatDate(row.pay_date)}
                          </TableCell>
                          <TableCell className="py-4 align-middle text-right text-lg font-bold tabular-nums text-[#0A0A0A]">
                            {formatPeso(row.net_pay)}
                          </TableCell>
                          <TableCell className="py-4 pr-4 text-right align-middle" onClick={(e) => e.stopPropagation()}>
                            <div className="flex flex-nowrap justify-end gap-2">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-9 shrink-0 gap-1.5 rounded-lg border-slate-200 font-semibold text-[#0A0A0A] hover:bg-slate-50"
                                onClick={() => openView(id)}
                              >
                                <Eye className="h-4 w-4" aria-hidden />
                                View
                              </Button>
                              <Button
                                type="button"
                                size="sm"
                                className="h-9 shrink-0 gap-1.5 rounded-lg bg-[#0A0A0A] font-semibold text-white hover:bg-[#0A0A0A]/90"
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
            <div className="divide-y divide-slate-100 @md:hidden">
              {loading ? (
                <div className="flex flex-col items-center bg-white py-14">
                  <Loader2 className="mb-3 h-8 w-8 animate-spin text-[#0A0A0A]/35" />
                  <p className="text-sm text-[#0A0A0A]/60">Loading…</p>
                </div>
              ) : sorted.length === 0 ? (
                <div className="bg-slate-50/50 px-4 py-12 text-center">
                  <FileText className="mx-auto mb-3 h-10 w-10 text-slate-300" strokeWidth={1.25} />
                  <p className="font-medium text-[#0A0A0A]">No payslips match these filters</p>
                </div>
              ) : (
                sorted.map((row) => {
                  const id = Number(row.id)
                  const periodLine = formatPeriodRange(row.pay_period_start, row.pay_period_end)
                  return (
                    <div key={id} className="bg-white transition hover:bg-slate-50/70">
                      <button
                        type="button"
                        className="w-full p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#0A0A0A]/10"
                        onClick={() => openView(id)}
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="text-[15px] font-semibold text-[#0A0A0A]">{periodLine}</p>
                            <p className="mt-0.5 text-xs text-[#0A0A0A]/50">{formatDate(row.pay_date)}</p>
                          </div>
                          <p className="shrink-0 text-lg font-bold tabular-nums text-[#0A0A0A]">{formatPeso(row.net_pay)}</p>
                        </div>
                      </button>
                      <div className="flex flex-wrap items-center justify-end gap-2 px-4 pb-4">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 rounded-lg border-slate-200 font-semibold text-[#0A0A0A]"
                          onClick={() => openView(id)}
                        >
                          View
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          className="h-8 rounded-lg bg-[#0A0A0A] font-semibold text-white"
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
              <div className="flex flex-col gap-4 border-t border-slate-100 pt-6 @md:flex-row @md:items-center @md:justify-between">
                <p className="text-sm text-[#0A0A0A]/55">
                  Showing{' '}
                  <span className="font-semibold text-[#0A0A0A]">
                    {rangeFrom}–{rangeTo}
                  </span>{' '}
                  of <span className="font-semibold text-[#0A0A0A]">{totalCount}</span> payslips
                </p>
                {lastPage > 1 ? (
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 min-w-9 rounded-xl border-slate-200/90"
                      disabled={page <= 1 || loading}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                      ‹
                    </Button>
                    {pageNumbers.map((p, idx) =>
                      p === '…' ? (
                        <span key={`pg-${idx}`} className="px-1 text-[#0A0A0A]/35">
                          …
                        </span>
                      ) : (
                        <Button
                          key={p}
                          type="button"
                          variant={page === p ? 'default' : 'outline'}
                          size="sm"
                          className={cn(
                            'h-9 min-w-9 rounded-xl px-3',
                            page === p
                              ? 'border-[#0A0A0A] bg-[#0A0A0A] text-white hover:bg-[#0A0A0A]/90'
                              : 'border-slate-200 text-[#0A0A0A]',
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
                      className="h-9 min-w-9 rounded-xl border-slate-200/90"
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
