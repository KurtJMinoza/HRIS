import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, FileDown, Loader2, Printer } from 'lucide-react'
import {
  companyLogoUrl,
  getAdminPayslipPdfBlob,
  getAdminPayslipViewData,
  getAdminPayslipViewPreviewData,
  getEmployeePayslipViewData,
  getMyPayslipPdfBlob,
  userProfileImageSrc,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { displayCompanyAddress, displayCompanyTin } from '@/lib/payslipCompanyDisplay'
import { Button } from '@/components/ui/button'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

function peso(v) {
  const n = Number(v)
  if (!Number.isFinite(n)) return 'PHP 0.00'
  return `PHP ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function dateValue(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' })
}

function payCycleRangeNumeric(start, end) {
  const fmt = (value) => {
    if (!value) return null
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return `${d.getMonth() + 1}/${d.getDate()}/${d.getFullYear()}`
  }
  const a = fmt(start)
  const b = fmt(end)
  if (!a && !b) return '—'
  if (!a) return b || '—'
  if (!b) return a
  return `${a} - ${b}`
}

function maskId(value) {
  if (value == null || String(value).trim() === '') return 'Not set'
  return String(value)
}

/** Level 4 labels / Level 2 values — employee card uses strong values; government IDs use mutedValue for “Not set” balance. */
function Field({ label, children, mutedValue = false }) {
  return (
    <div className="min-w-0">
      <p className="text-[13px] font-normal uppercase tracking-[0.1em] text-muted-foreground print:text-[9px]">{label}</p>
      <p
        className={cn(
          'mt-1 leading-[1.5] print:text-[11px]',
          mutedValue
            ? 'text-[15px] font-normal text-muted-foreground'
            : 'text-base font-semibold text-[#0A0A0A]',
        )}
      >
        {children}
      </p>
    </div>
  )
}

/** Published payslip rows — same semantics as {@link FinalizePayrollService} / delivery checks. */
const PUBLISHED_PAYSLIP_STATUSES = new Set(['finalized', 'generated', 'emailed', 'sent_finalized', 'viewed'])

function isPublishedPayslipStatus(status) {
  return PUBLISHED_PAYSLIP_STATUSES.has(String(status || '').toLowerCase())
}

const PRINT_STYLES = `
@media print {
  @page {
    size: A4 portrait;
    margin: 12mm 10mm;
  }

  html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    color: #0A0A0A !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    overflow: visible !important;
    height: auto !important;
    font-size: 11px !important;
    line-height: 1.25 !important;
  }

  /* Force single-page fit while keeping a wide layout */
  body { zoom: 0.9; }

  * {
    text-shadow: none !important;
  }

  /* Hide everything except the payslip document */
  [data-payslip-toolbar] { display: none !important; }
  [data-payslip-bg] { display: none !important; }

  /* Sidebar, header, nav, footer — hide all app chrome */
  nav, aside, header:not([data-payslip-doc-header]),
  [data-sidebar], [data-topbar], [data-app-header],
  .sidebar, .app-sidebar { display: none !important; }

  /* Keep the same layout as preview modal; just remove outer page padding constraints */
  [data-payslip-document] {
    margin: 0 auto !important;
    width: 100% !important;
    max-width: 850px !important;
    border-radius: 0 !important;
    box-shadow: none !important;
  }

  /* Keep sections readable but compact enough for one page */
  [data-payslip-document] > header { padding: 10px 12px !important; }
  [data-payslip-document] > .payslip-content { padding: 10px 12px 12px !important; }
  [data-payslip-document] .payslip-content { row-gap: 10px !important; }
  [data-payslip-section] { padding: 10px !important; }
  [data-payslip-tables-grid] { gap: 10px !important; }
  [data-payslip-meta-bar] { padding: 8px 10px !important; }
  [data-payslip-document] h1 { font-size: 20px !important; }
  [data-payslip-document] h2,
  [data-payslip-document] h3 { font-size: 14px !important; }
  [data-payslip-document] p,
  [data-payslip-document] span,
  [data-payslip-document] dt,
  [data-payslip-document] dd,
  [data-payslip-document] td,
  [data-payslip-document] th { font-size: 10.5px !important; }

  /* Prevent ugly splits: keep each table row together */
  [data-payslip-document] tr { break-inside: avoid; page-break-inside: avoid; }

  /* Net pay hero - keep the background */
  [data-payslip-net-hero] {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #0A0A0A !important;
    color: #fff !important;
    break-inside: avoid;
    page-break-inside: avoid;
  }

  [data-payslip-net-hero] { padding: 10px 12px !important; }
  [data-payslip-net-hero] p:nth-child(2) { font-size: 30px !important; }

  /* Ensure borders/backgrounds render faithfully */
  [data-payslip-document], [data-payslip-section], [data-payslip-table-header] {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
}
`


export default function AdminPayslipViewPage() {
  const { payslipId } = useParams()
  const { pathname, state: locationState } = useLocation()
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const employeeSelfServiceView = pathname.includes('/employee/payslips/view/')
  const [loading, setLoading] = useState(true)
  const [downloading, setDownloading] = useState(false)
  const [data, setData] = useState(null)

  const isPreviewMode = !payslipId

  const loadPayslip = useCallback(async () => {
    setLoading(true)
    try {
      if (payslipId && employeeSelfServiceView) {
        const payload = await getEmployeePayslipViewData(payslipId)
        setData(payload)
        return
      }
      if (payslipId) {
        const payload = await getAdminPayslipViewData(payslipId)
        setData(payload)
        return
      }
      const employeeId = searchParams.get('employee_id')
      if (!employeeId) throw new Error('No employee context found for payslip preview.')
      const payload = await getAdminPayslipViewPreviewData({
        employee_id: Number(employeeId),
        from_date: searchParams.get('from_date') || null,
        to_date: searchParams.get('to_date') || null,
        pay_cycle_id: searchParams.get('pay_cycle_id') ? Number(searchParams.get('pay_cycle_id')) : null,
        reference_date: searchParams.get('reference_date') || null,
        payroll_period_id: searchParams.get('payroll_period_id') ? Number(searchParams.get('payroll_period_id')) : null,
        is_final_pay: searchParams.get('is_final_pay') === 'true',
        password_protect: searchParams.get('password_protect') === 'true',
      })
      setData(payload)
    } catch (e) {
      toast({ title: 'Payslip view failed', description: e.message || 'Unable to load payslip.', variant: 'destructive' })
      navigate(employeeSelfServiceView ? `${hrBase}/payslips` : `${hrBase}/compensation/finalize-payroll`)
    } finally {
      setLoading(false)
    }
  }, [payslipId, searchParams, toast, navigate, hrBase, employeeSelfServiceView])

  useEffect(() => {
    loadPayslip()
  }, [loadPayslip])

  const earnings = useMemo(() => {
    const s = data?.summary
    return Array.isArray(s?.payslip_earning_lines) ? s.payslip_earning_lines : []
  }, [data?.summary])
  const dailyComputationEarnings = useMemo(() => {
    const s = data?.summary
    return Array.isArray(s?.daily_computation_earning_lines) ? s.daily_computation_earning_lines : []
  }, [data?.summary])
  const displayEarnings = useMemo(() => {
    if (dailyComputationEarnings.length > 0) return dailyComputationEarnings
    const fallback = []
    const regular = Number(data?.summary?.basic_pay || 0)
    const premium = Number(data?.summary?.attendance_premium_pay_this_period || 0)
    if (regular > 0) fallback.push({ key: 'fallback:regular_pay', label: 'Regular pay', amount: regular })
    if (premium > 0) fallback.push({ key: 'fallback:attendance_premium', label: 'Attendance premiums (OT/ND/Holiday)', amount: premium })
    return fallback
  }, [dailyComputationEarnings, data?.summary])
  const holidayPremiumDetails = useMemo(() => {
    const rows = Array.isArray(data?.summary?.holiday_premium_breakdown) ? data.summary.holiday_premium_breakdown : []
    const formatHolidayDate = (value) => {
      if (!value) return ''
      const d = new Date(value)
      if (Number.isNaN(d.getTime())) return String(value)
      return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' })
    }
    return rows
      .map((row, idx) => {
        const amount = Number(row?.amount || 0)
        const name = String(row?.holiday_name || 'Holiday').trim() || 'Holiday'
        const type = String(row?.holiday_type || row?.type || 'Holiday').trim() || 'Holiday'
        const dt = formatHolidayDate(row?.date)
        return {
          key: `${row?.date || idx}-${name}-${type}`,
          amount,
          text: `${name}${dt ? ` (${dt})` : ''} - ${type}: ${peso(amount)}`,
        }
      })
      .filter((row) => row.amount > 0)
  }, [data?.summary])
  const allDeductions = useMemo(() => {
    const s = data?.summary
    const gov = Array.isArray(s?.payslip_deduction_lines) ? s.payslip_deduction_lines : []
    const custom = Array.isArray(s?.payslip_custom_deduction_lines) ? s.payslip_custom_deduction_lines : []
    const merged = [...gov, ...custom]
    const seen = new Set()
    return merged.filter((line) => {
      const key = String(line?.key || line?.label || '').trim().toLowerCase()
      if (!key) return true
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
  }, [data?.summary])
  const payrollStatusRaw = String(data?.payroll?.status || '').toLowerCase()
  const netPay = Number(data?.amounts?.net_pay || 0)
  const isSentFinalizedLike = payrollStatusRaw === 'sent_finalized' || payrollStatusRaw === 'emailed'
  const statusLabel = (() => {
    if (!data) return '—'
    if (isPreviewMode) {
      if (isSentFinalizedLike) return 'Sent finalized'
      if (payrollStatusRaw && isPublishedPayslipStatus(payrollStatusRaw)) return 'Finalized'
      if (!payrollStatusRaw) return 'Draft / Preview'
      return 'Draft / Preview'
    }
    if (payrollStatusRaw === 'viewed') return 'Viewed'
    if (isSentFinalizedLike) return 'Sent finalized'
    if (isPublishedPayslipStatus(payrollStatusRaw)) return 'Finalized'
    return 'Draft'
  })()

  const companyLogoSrc = companyLogoUrl(data?.company ?? null)
  const employeeAvatarSrc = userProfileImageSrc(data?.employee)

  const getFinalizePayrollListUrl = useCallback(() => {
    const q = new URLSearchParams()
    const passThrough = [
      'from_date',
      'to_date',
      'pay_cycle_id',
      'reference_date',
      'payroll_period_id',
      'password_protect',
      'is_final_pay',
      'company_id',
      'branch_id',
      'department_id',
    ]
    passThrough.forEach((k) => {
      const v = searchParams.get(k)
      if (v != null && String(v).trim() !== '') q.set(k, v)
    })

    const bs = data?.batch_scope
    if (bs?.company_id != null && !q.has('company_id')) q.set('company_id', String(bs.company_id))

    const pr = data?.payroll
    if (pr?.pay_period_start && !q.has('from_date')) q.set('from_date', String(pr.pay_period_start))
    if (pr?.pay_period_end && !q.has('to_date')) q.set('to_date', String(pr.pay_period_end))
    if (pr?.pay_cycle_id != null && !q.has('pay_cycle_id')) q.set('pay_cycle_id', String(pr.pay_cycle_id))
    if (pr?.payroll_period_id != null && !q.has('payroll_period_id')) q.set('payroll_period_id', String(pr.payroll_period_id))

    if (!q.has('company_id') && data?.company?.id != null) q.set('company_id', String(data.company.id))

    const s = q.toString()
    return s ? `${hrBase}/compensation/finalize-payroll?${s}` : `${hrBase}/compensation/finalize-payroll`
  }, [hrBase, searchParams, data])

  const isLaravelAdmin = user?.role === 'admin'

  const handleBack = useCallback(() => {
    const fromState = typeof locationState?.payslipBackTo === 'string' ? locationState.payslipBackTo.trim() : ''
    if (fromState) {
      navigate(fromState)
      return
    }
    if (employeeSelfServiceView) {
      navigate(`${hrBase}/payslips`)
      return
    }
    const rt = String(searchParams.get('return_to') || '').toLowerCase()
    if (rt === 'payslips' || rt === 'list') {
      navigate(`${hrBase}/compensation/payslips`)
      return
    }
    if (rt === 'finalize' || rt === 'finalize-payroll') {
      navigate(getFinalizePayrollListUrl())
      return
    }
    if (isLaravelAdmin) {
      navigate(getFinalizePayrollListUrl())
      return
    }
    navigate(`${hrBase}/compensation/payslips`)
  }, [
    navigate,
    getFinalizePayrollListUrl,
    employeeSelfServiceView,
    hrBase,
    locationState?.payslipBackTo,
    searchParams,
    isLaravelAdmin,
  ])

  const employeeProfileId = useMemo(() => {
    const raw = data?.employee?.id ?? searchParams.get('employee_id')
    if (raw == null || raw === '') return null
    const n = Number(raw)
    return Number.isFinite(n) ? n : null
  }, [data?.employee?.id, searchParams])
  const employeeCodeLabel = data?.employee?.employee_code || (payslipId ? `Payslip #${payslipId}` : '—')

  const exportPdf = useCallback(async () => {
    if (downloading) return

    if (payslipId) {
      setDownloading(true)
      try {
        const blob = employeeSelfServiceView ? await getMyPayslipPdfBlob(payslipId) : await getAdminPayslipPdfBlob(payslipId)
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `payslip-${payslipId}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      } catch (e) {
        toast({ title: 'Export failed', description: e.message || 'Unable to export payslip PDF.', variant: 'destructive' })
      } finally {
        setDownloading(false)
      }
      return
    }

    if (isPreviewMode && data?.payslip_id) {
      setDownloading(true)
      try {
        const blob = await getAdminPayslipPdfBlob(data.payslip_id)
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `payslip-preview-${data.payslip_id}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      } catch (e) {
        toast({ title: 'Export failed', description: e.message || 'Unable to export payslip PDF.', variant: 'destructive' })
      } finally {
        setDownloading(false)
      }
      return
    }

    window.print()
  }, [payslipId, downloading, toast, isPreviewMode, data?.payslip_id, employeeSelfServiceView])

  const printDocument = useCallback(() => {
    window.print()
  }, [])

  if (loading) {
    return (
      <div className="flex min-h-[45vh] items-center justify-center">
        <Loader2 className="mr-2 h-5 w-5 animate-spin text-muted-foreground" />
        <span className="text-sm text-muted-foreground">Loading payslip view...</span>
      </div>
    )
  }

  return (
    <div className="relative min-h-screen bg-slate-50 text-[#0A0A0A] print:min-h-0 print:bg-white print:p-0 dark:bg-background dark:text-foreground">
      <style dangerouslySetInnerHTML={{ __html: PRINT_STYLES }} />

      <div aria-hidden data-payslip-bg className="pointer-events-none absolute inset-0 -z-10 overflow-hidden print:hidden">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_120%_80%_at_50%_-15%,oklch(0.96_0.02_247/0.85),transparent_55%)]" />
        <div className="absolute inset-0 bg-[linear-gradient(180deg,oklch(0.992_0.002_247)_0%,oklch(0.985_0.002_247)_45%,oklch(0.975_0.004_247)_100%)]" />
      </div>

      <div className="relative mx-auto w-full min-w-0 max-w-7xl px-3 py-4 print:max-w-none print:px-0 print:py-0 sm:px-4 md:px-5 lg:px-6 lg:py-5 2xl:max-w-[min(90rem,100%)] 3xl:max-w-[min(100rem,100%)] @2xl:py-8">
        {/* Toolbar */}
        <div data-payslip-toolbar className="mb-8 flex flex-col gap-4 print:hidden">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex min-w-0 flex-wrap items-center gap-3 @md:gap-4">
              <Button
                type="button"
                variant="outline"
                size="default"
                className="h-10 shrink-0 rounded-xl border-[#0A0A0A]/20 bg-card px-4 font-medium text-[#0A0A0A] shadow-sm hover:bg-[#0A0A0A]/5"
                onClick={handleBack}
              >
                <ArrowLeft className="mr-2 h-4 w-4" />
                Back
              </Button>
              <nav className="flex min-w-0 flex-wrap items-center text-sm text-muted-foreground" aria-label="Breadcrumb">
                <Link to={`${hrBase}/dashboard`} className="text-[#0A0A0A]/70 transition-colors hover:text-[#0A0A0A] hover:underline">
                  Registry
                </Link>
                <span className="px-2 text-[#0A0A0A]/35">/</span>
                {employeeProfileId != null ? (
                  <Link
                    to={`${hrBase}/employees/${employeeProfileId}`}
                    className="font-medium text-[#0A0A0A]/85 transition-colors hover:text-[#0A0A0A] hover:underline"
                  >
                    {employeeCodeLabel}
                  </Link>
                ) : (
                  <span className="font-medium text-[#0A0A0A]/85">{employeeCodeLabel}</span>
                )}
                <span className="px-2 text-[#0A0A0A]/35">/</span>
                <span className="font-semibold text-[#0A0A0A]">Payslip</span>
              </nav>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="default"
                className="h-10 rounded-xl border-slate-200/90 bg-white px-4 text-[#0A0A0A] shadow-sm dark:border-slate-700 dark:bg-card"
                onClick={exportPdf}
                disabled={downloading}
              >
                {downloading ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <FileDown className="mr-2 h-4 w-4" />
                )}
                Export PDF
              </Button>
              <Button
                type="button"
                variant="outline"
                className="h-10 rounded-xl border-slate-200/90 bg-white px-4 text-[#0A0A0A] shadow-sm dark:border-slate-700 dark:bg-card"
                onClick={printDocument}
              >
                <Printer className="mr-2 h-4 w-4" />
                Print document
              </Button>
            </div>
          </div>
        </div>

        {/* Document */}
        <article data-payslip-document className="overflow-hidden rounded-2xl border border-slate-200/90 bg-white text-[#0A0A0A] shadow-sm print:rounded-none print:border-0 print:shadow-none dark:border-slate-800 dark:bg-card">
          {/* Header */}
          <header data-payslip-doc-header className="border-b border-slate-100 bg-white px-6 py-5 @md:px-10 @md:py-6 dark:border-slate-800 dark:bg-card">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
              <div className="flex min-w-0 flex-1 items-start gap-3 @md:gap-4">
                {companyLogoSrc ? (
                  <div className="size-22 shrink-0 overflow-hidden rounded-full p-0 [&>img]:m-0 [&>img]:block [&>img]:p-0 print:size-14">
                    <img
                      src={companyLogoSrc}
                      alt=""
                      className="h-full w-full max-h-full max-w-full object-contain object-center"
                    />
                  </div>
                ) : null}
                <div className="min-w-0 flex-1 space-y-1">
                  <h1 className="text-[1.65rem] font-bold leading-[1.15] tracking-tight text-[#0A0A0A] print:text-xl @md:text-[1.75rem] @2xl:text-[2rem]">
                    {data?.company?.name || 'Company'}
                  </h1>
                  <div className="leading-snug text-[#0A0A0A]">
                    <span className="text-[13px] font-normal uppercase tracking-wide text-muted-foreground">Company address</span>
                    <span className="mt-0.5 block text-sm font-normal text-[#0A0A0A]/85 print:text-[10px]">{displayCompanyAddress(data?.company?.address)}</span>
                  </div>
                  <p className="leading-snug text-[#0A0A0A]">
                    <span className="text-[13px] font-normal uppercase tracking-wide text-muted-foreground">Company TIN </span>
                    <span className="text-sm font-medium tabular-nums text-[#0A0A0A] print:text-[10px]">{displayCompanyTin(data?.company?.tin)}</span>
                  </p>
                </div>
              </div>

              <div className="flex w-full shrink-0 flex-col items-start gap-3 lg:w-auto lg:items-end">
                <span className="inline-flex items-center rounded-full border border-sky-200/80 bg-sky-50 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.22em] text-sky-950">
                  Official payslip
                </span>
              </div>
            </div>
          </header>

          <div className="payslip-content space-y-5 px-6 pt-6 pb-8 @md:px-10 @md:pb-10">
            <section data-payslip-meta-bar className="rounded-xl border border-slate-200/80 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-background/60">
              <div className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">Pay cycle</p>
                  <p className="mt-1 font-semibold tabular-nums text-[#0A0A0A]">
                    {payCycleRangeNumeric(data?.payroll?.pay_period_start, data?.payroll?.pay_period_end)}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">Pay date</p>
                  <p className="mt-1 font-semibold text-[#0A0A0A]">{dateValue(data?.payroll?.pay_date)}</p>
                </div>
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">Status</p>
                  <p className="mt-1 font-semibold text-[#0A0A0A]">{statusLabel}</p>
                </div>
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">Daily rate</p>
                  <p className="mt-1 font-semibold tabular-nums text-[#0A0A0A]">
                    {peso(data?.payroll?.daily_rate || data?.summary?.daily_rate || 0)}
                  </p>
                </div>
              </div>
            </section>

            {/* Employee */}
            <section data-payslip-section className="rounded-xl border border-slate-200/80 bg-slate-50/50 p-5 shadow-sm dark:border-slate-800 dark:bg-background/60">
              <h2 className="mb-4 border-l-[3px] border-l-emerald-600 pl-3 text-lg font-bold uppercase tracking-[0.08em] text-[#0A0A0A]">
                Employee Information
              </h2>
              <div data-payslip-avatar className="mb-4 flex items-center gap-3">
                <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200/80 bg-slate-100/80 dark:border-slate-700 dark:bg-muted/40">
                  {employeeAvatarSrc ? (
                    <img src={employeeAvatarSrc} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <span className="text-lg font-semibold text-muted-foreground">
                      {(data?.employee?.name || '?').slice(0, 1).toUpperCase()}
                    </span>
                  )}
                </div>
                <div>
                  <p className="text-[1.05rem] font-bold text-[#0A0A0A]">{data?.employee?.name || '—'}</p>
                  <p className="text-[13px] text-[#0A0A0A]/70">{data?.employee?.position?.trim() || '—'}</p>
                </div>
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="grid grid-cols-1 gap-4">
                  <Field label="Employee ID">{data?.employee?.employee_code || '—'}</Field>
                  <Field label="Department">{data?.employee?.department || '—'}</Field>
                  <Field label="Employment Status">{maskId(data?.employee?.employment_status_label)}</Field>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="TIN" mutedValue>{maskId(data?.employee?.tin_number)}</Field>
                  <Field label="SSS" mutedValue>{maskId(data?.employee?.sss_number)}</Field>
                  <Field label="PhilHealth" mutedValue>{maskId(data?.employee?.philhealth_number)}</Field>
                  <Field label="Pag-IBIG" mutedValue>{maskId(data?.employee?.pagibig_number)}</Field>
                </div>
              </div>
            </section>

            <section data-payslip-tables-grid className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8">
              <div className="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800 dark:bg-card">
                <div data-payslip-table-header className="border-b border-slate-100 bg-slate-50 px-4 py-3.5 pl-5 border-l-4 border-l-emerald-600 dark:border-slate-800">
                  <h3 className="text-[1.1rem] font-bold uppercase tracking-[0.06em] text-emerald-950">Earnings</h3>
                </div>
                <div className="px-1 pb-2 pt-0">
                  <table data-payslip-lines-table className="w-full text-[14px] leading-[1.45] text-[#0A0A0A]/90">
                    <thead>
                      <tr className="border-0 bg-slate-50/90 dark:bg-slate-900/30">
                        <th className="py-2.5 pl-3 pr-2 text-left text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">
                          Description
                        </th>
                        <th className="px-2 py-2.5 text-center text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">
                          Units
                        </th>
                        <th className="py-2.5 pl-2 pr-3 text-right text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55 w-32">
                          Amount (PHP)
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {displayEarnings.map((line, idx) => {
                        const isHolidayPremium = String(line?.label || '').trim().toLowerCase() === 'holiday premium'
                        return (
                          <tr key={`dc-${idx}`} className="border-b border-slate-100/90 transition-colors last:border-b-0 hover:bg-slate-50/80 dark:border-slate-800/80 dark:hover:bg-slate-900/25">
                            <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">
                              {isHolidayPremium ? 'Holiday Pay' : (line?.label || 'Daily computation')}
                              {isHolidayPremium && holidayPremiumDetails.length > 0 ? (
                                <div className="mt-1 space-y-0.5">
                                  {holidayPremiumDetails.map((detail) => (
                                    <p key={detail.key} className="text-[12px] leading-snug text-[#0A0A0A]/55">
                                      {detail.text}
                                    </p>
                                  ))}
                                </div>
                              ) : null}
                            </td>
                            <td className="px-2 py-2.5 text-center text-[13px] font-medium tabular-nums text-[#0A0A0A]/70">
                              {line?.units || '-'}
                            </td>
                            <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                              {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </td>
                          </tr>
                        )
                      })}
                      {earnings.map((line, idx) => (
                        <tr key={`e-${idx}`} className="border-b border-slate-100/90 transition-colors last:border-b-0 hover:bg-slate-50/80 dark:border-slate-800/80 dark:hover:bg-slate-900/25">
                          <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">{line?.label || 'Earning'}</td>
                          <td className="px-2 py-2.5 text-center text-[13px] font-medium tabular-nums text-[#0A0A0A]/70">{line?.units || '-'}</td>
                          <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                            {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                          </td>
                        </tr>
                      ))}
                      <tr className="border-t border-emerald-100 bg-emerald-50/70 text-[#0A0A0A] dark:border-emerald-900/60 dark:bg-emerald-950/20">
                        <td className="py-3 pl-3 pr-2 text-[15px] font-bold">Total Gross Earnings</td>
                        <td className="px-2 py-3 text-center text-[13px] font-bold text-[#0A0A0A]/70"></td>
                        <td className="py-3 pl-2 pr-3 text-right text-[15px] font-bold tabular-nums">
                          {Number(data?.amounts?.gross_pay || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div className="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800 dark:bg-card">
                <div data-payslip-table-header className="border-b border-slate-100 bg-slate-50 px-4 py-3.5 pl-5 border-l-4 border-l-red-600 dark:border-slate-800">
                  <h3 className="text-[1.1rem] font-bold uppercase tracking-[0.06em] text-red-950">Deductions</h3>
                </div>
                <div className="px-1 pb-2 pt-0">
                  <table className="w-full text-[14px] leading-[1.45] text-[#0A0A0A]/90">
                    <thead>
                      <tr className="border-0 bg-slate-50/90 dark:bg-slate-900/30">
                        <th className="py-2.5 pl-3 pr-2 text-left text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">
                          Description
                        </th>
                        <th className="py-2.5 pl-2 pr-3 text-right text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55 w-32">
                          Amount (PHP)
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {allDeductions.map((line, idx) => (
                        <tr key={`d-${idx}`} className="border-b border-slate-100/90 transition-colors last:border-b-0 hover:bg-slate-50/80 dark:border-slate-800/80 dark:hover:bg-slate-900/25">
                          <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">{line?.label || 'Deduction'}</td>
                          <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                            {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                          </td>
                        </tr>
                      ))}
                      <tr className="border-t border-red-100 bg-red-50/70 text-[#0A0A0A] dark:border-red-900/50 dark:bg-red-950/20">
                        <td className="py-3 pl-3 pr-2 text-[15px] font-bold">Total Deductions</td>
                        <td className="py-3 pl-2 pr-3 text-right text-[15px] font-bold tabular-nums">
                          {Number(data?.amounts?.total_deductions || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>

            {/* Net pay hero */}
            <section className="flex justify-center">
              <div
                data-payslip-net-hero
                className="flex w-full flex-col items-center justify-center overflow-hidden rounded-2xl bg-[#0A0A0A] px-6 py-7 text-center text-primary-foreground shadow-lg ring-1 ring-white/10 print:rounded-lg print:shadow-none"
              >
                <p className="text-[11px] font-medium uppercase tracking-[0.32em] text-white/65">Net Take-Home Pay</p>
                <p className="mt-2 text-4xl font-bold tabular-nums leading-none tracking-tight text-white print:text-3xl @md:text-5xl">
                  {peso(data?.amounts?.net_pay)}
                </p>
                <p className="mt-4 text-[14px] font-normal leading-snug text-white/60">
                  For the period {payCycleRangeNumeric(data?.payroll?.pay_period_start, data?.payroll?.pay_period_end)}
                </p>
                {netPay <= 0 ? (
                  <p className="mt-2 text-[12px] font-normal text-white/50">Zero or negative net — verify earnings and deductions.</p>
                ) : null}
              </div>
            </section>
          </div>
        </article>
      </div>
    </div>
  )
}
