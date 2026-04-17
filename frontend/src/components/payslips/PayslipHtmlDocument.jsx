import { useMemo } from 'react'
import { companyLogoUrl, userProfileImageSrc } from '@/api'
import { displayCompanyAddress, displayCompanyTin } from '@/lib/payslipCompanyDisplay'
import { cn } from '@/lib/utils'

function peso(v) {
  const n = Number(v)
  if (!Number.isFinite(n)) return 'PHP 0.00'
  return `PHP ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

/**
 * Units are normalized on the backend (PayslipService::formatUnitsAndAmount).
 * The preview modal displays the backend-provided value to stay identical with PDF.
 *
 * @param {number|null|undefined} _minutesWorked
 * @param {string|null|undefined} unitsFallback
 * @returns {string|null}
 */
function formatUnits(_minutesWorked, unitsFallback) {
  const normalized = String(unitsFallback || '').trim()
  if (normalized) return normalized
  return null
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

const PUBLISHED_PAYSLIP_STATUSES = new Set(['finalized', 'generated', 'emailed', 'sent_finalized', 'viewed'])

function isPublishedPayslipStatus(status) {
  return PUBLISHED_PAYSLIP_STATUSES.has(String(status || '').toLowerCase())
}

/**
 * On-screen payslip layout (finalize preview page, modals, print).
 * @param {{ data: object | null, isPreviewMode?: boolean }} props
 */
export function PayslipHtmlDocument({ data, isPreviewMode = false }) {
  const summary = data?.summary
  const hasPositiveAmount = (line) => Number(line?.amount || 0) > 0

  const earnings = useMemo(() => {
    const rows = Array.isArray(summary?.payslip_earning_lines) ? summary.payslip_earning_lines : []
    return rows.filter(hasPositiveAmount)
  }, [summary])

  const dailyComputationEarnings = useMemo(() => {
    const rows = Array.isArray(summary?.daily_computation_earning_lines) ? summary.daily_computation_earning_lines : []
    return rows.filter(hasPositiveAmount)
  }, [summary])

  const displayEarnings = useMemo(() => {
    if (dailyComputationEarnings.length > 0) return dailyComputationEarnings
    const fallback = []
    const regular = Number(summary?.basic_pay || 0)
    const premium = Number(summary?.attendance_premium_pay_this_period || 0)
    if (regular > 0) fallback.push({ key: 'fallback:regular_pay', label: 'Regular pay', amount: regular })
    if (premium > 0) {
      fallback.push({ key: 'fallback:attendance_premium', label: 'Attendance premiums (OT/ND/Holiday)', amount: premium })
    }
    return fallback
  }, [dailyComputationEarnings, summary])

  const holidayPremiumDetails = useMemo(() => {
    const rows = Array.isArray(summary?.holiday_premium_breakdown) ? summary.holiday_premium_breakdown : []
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
  }, [summary])

  const allDeductions = useMemo(() => {
    const gov = Array.isArray(summary?.payslip_deduction_lines) ? summary.payslip_deduction_lines : []
    const custom = Array.isArray(summary?.payslip_custom_deduction_lines) ? summary.payslip_custom_deduction_lines : []
    const merged = [...gov, ...custom]
    const seen = new Set()
    return merged.filter((line) => {
      const key = String(line?.key || line?.label || '').trim().toLowerCase()
      if (!key) return true
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
  }, [summary])

  const payrollStatusRaw = String(data?.payroll?.status || '').toLowerCase()
  const netPay = Number(data?.amounts?.net_pay || 0)
  const isSentFinalizedLike = payrollStatusRaw === 'sent_finalized' || payrollStatusRaw === 'emailed'

  const statusLabel = useMemo(() => {
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
  }, [data, isPreviewMode, isSentFinalizedLike, payrollStatusRaw])

  const companyLogoSrc = companyLogoUrl(data?.company ?? null)
  const employeeAvatarSrc = userProfileImageSrc(data?.employee)

  const generatedFooter = useMemo(
    () =>
      new Date().toLocaleString(undefined, {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      }),
    [],
  )

  if (!data) return null

  return (
    <article
      data-payslip-document
      className="overflow-hidden rounded-2xl border border-slate-200/90 bg-white text-[#0A0A0A] shadow-sm print:rounded-none print:border print:shadow-none"
    >
      <header
        data-payslip-doc-header
        className="border-b border-slate-100 bg-white px-6 py-5 @md:px-10 @md:py-6"
      >
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
          <div className="flex min-w-0 flex-1 items-start gap-3 @md:gap-4">
            {companyLogoSrc ? (
              <div className="size-22 shrink-0 overflow-hidden rounded-full p-0 [&>img]:m-0 [&>img]:block [&>img]:p-0 print:size-14">
                <img src={companyLogoSrc} alt="" className="h-full w-full max-h-full max-w-full object-contain object-center" />
              </div>
            ) : null}
            <div className="min-w-0 flex-1 space-y-1">
              <h1 className="text-[1.65rem] font-bold leading-[1.15] tracking-tight text-[#0A0A0A] print:text-xl @md:text-[1.75rem] @2xl:text-[2rem]">
                {data?.company?.name || 'Company'}
              </h1>
              <div className="leading-snug text-[#0A0A0A]">
                <span className="text-[13px] font-normal uppercase tracking-wide text-muted-foreground">Company address</span>
                <span className="mt-0.5 block text-sm font-normal text-[#0A0A0A]/85 print:text-[10px]">
                  {displayCompanyAddress(data?.company?.address)}
                </span>
              </div>
              <p className="leading-snug text-[#0A0A0A]">
                <span className="text-[13px] font-normal uppercase tracking-wide text-muted-foreground">Company TIN </span>
                <span className="text-sm font-medium tabular-nums text-[#0A0A0A] print:text-[10px]">{displayCompanyTin(data?.company?.tin)}</span>
              </p>
            </div>
          </div>

          <div className="flex w-full shrink-0 flex-col items-start gap-3 lg:w-auto lg:items-end">
            <span className="inline-flex items-center rounded-full border border-sky-200/80 bg-sky-50 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.22em] text-sky-950 print:border-sky-200 print:bg-sky-50">
              Official payslip
            </span>
          </div>
        </div>
      </header>

      <div className="payslip-content space-y-5 px-6 pt-6 pb-8 @md:px-10 @md:pb-10">
        <section data-payslip-meta-bar className="rounded-xl border border-slate-200/80 bg-white px-4 py-3">
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
              <p className="mt-1 font-semibold tabular-nums text-[#0A0A0A]">{peso(data?.payroll?.daily_rate || data?.summary?.daily_rate || 0)}</p>
            </div>
          </div>
        </section>

        <section data-payslip-section className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-sm">
          <h2 className="mb-4 border-l-[3px] border-l-emerald-600 pl-3 text-lg font-bold uppercase tracking-[0.08em] text-[#0A0A0A]">
            Employee Information
          </h2>
          <div data-payslip-avatar className="mb-4 flex items-center gap-3">
            <div
              data-payslip-avatar-photo
              className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200/80 bg-white"
            >
              {employeeAvatarSrc ? (
                <img src={employeeAvatarSrc} alt="" className="h-full w-full object-cover" />
              ) : (
                <span className="text-lg font-semibold text-muted-foreground">{(data?.employee?.name || '?').slice(0, 1).toUpperCase()}</span>
              )}
            </div>
            <div data-payslip-employee-headline>
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
              <Field label="TIN" mutedValue>
                {maskId(data?.employee?.tin_number)}
              </Field>
              <Field label="SSS" mutedValue>
                {maskId(data?.employee?.sss_number)}
              </Field>
              <Field label="PhilHealth" mutedValue>
                {maskId(data?.employee?.philhealth_number)}
              </Field>
              <Field label="Pag-IBIG" mutedValue>
                {maskId(data?.employee?.pagibig_number)}
              </Field>
            </div>
          </div>
        </section>

        <section data-payslip-tables-grid className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8">
          <div className="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div
              data-payslip-table-header
              className="border-b border-slate-100 bg-white px-4 py-3.5 pl-5 border-l-4 border-l-emerald-600"
            >
              <h3 className="text-[1.1rem] font-bold uppercase tracking-[0.06em] text-emerald-950">Earnings</h3>
            </div>
            <div className="px-1 pb-2 pt-0">
              <table data-payslip-lines-table className="w-full text-[14px] leading-[1.45] text-[#0A0A0A]/90">
                <thead>
                  <tr className="border-0 bg-white">
                    <th className="py-2.5 pl-3 pr-2 text-left text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">Description</th>
                    <th className="px-2 py-2.5 text-center text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">Units</th>
                    <th className="py-2.5 pl-2 pr-3 text-right text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55 w-32">
                      Amount (PHP)
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {displayEarnings.map((line, idx) => {
                    const isHolidayPremium = String(line?.label || '').trim().toLowerCase() === 'holiday premium'
                    return (
                      <tr
                        key={`dc-${idx}`}
                        className="border-b border-slate-100/90 transition-colors last:border-b-0 bg-white hover:bg-white"
                      >
                        <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">
                          {isHolidayPremium ? 'Holiday Pay' : line?.label || 'Daily computation'}
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
                          {formatUnits(line?.minutes_worked, line?.units) || '-'}
                        </td>
                        <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                          {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                        </td>
                      </tr>
                    )
                  })}
                  {earnings.map((line, idx) => (
                    <tr
                      key={`e-${idx}`}
                      className="border-b border-slate-100/90 transition-colors last:border-b-0 bg-white hover:bg-white"
                    >
                      <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">{line?.label || 'Earning'}</td>
                      <td className="px-2 py-2.5 text-center text-[13px] font-medium tabular-nums text-[#0A0A0A]/70">
                        {formatUnits(line?.minutes_worked, line?.units) || '-'}
                      </td>
                      <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                        {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                      </td>
                    </tr>
                  ))}
                  <tr className="border-t border-emerald-100 bg-white text-[#0A0A0A]">
                    <td className="py-3 pl-3 pr-2 text-[15px] font-bold">Total Gross Earnings</td>
                    <td className="px-2 py-3 text-center text-[13px] font-bold text-[#0A0A0A]/70" />
                    <td className="py-3 pl-2 pr-3 text-right text-[15px] font-bold tabular-nums">
                      {Number(data?.amounts?.gross_pay || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div className="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm">
            <div
              data-payslip-table-header
              className="border-b border-slate-100 bg-white px-4 py-3.5 pl-5 border-l-4 border-l-red-600"
            >
              <h3 className="text-[1.1rem] font-bold uppercase tracking-[0.06em] text-red-950">Deductions</h3>
            </div>
            <div className="px-1 pb-2 pt-0">
              <table data-payslip-deductions-table className="w-full text-[14px] leading-[1.45] text-[#0A0A0A]/90">
                <thead>
                  <tr className="border-0 bg-white">
                    <th className="w-[72%] py-2.5 pl-3 pr-2 text-left text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">
                      Description
                    </th>
                    <th className="w-[28%] py-2.5 pl-2 pr-3 text-right text-[12px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/55">
                      Amount (PHP)
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {allDeductions.map((line, idx) => (
                    <tr
                      key={`d-${idx}`}
                      className="border-b border-slate-100/90 transition-colors last:border-b-0 bg-white hover:bg-white"
                    >
                      <td className="py-2.5 pl-3 pr-2 font-normal text-[#0A0A0A]/88">{line?.label || 'Deduction'}</td>
                      <td className="py-2.5 pl-2 pr-3 text-right text-[14px] font-medium tabular-nums text-[#0A0A0A]">
                        {Number(line?.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                      </td>
                    </tr>
                  ))}
                  <tr className="border-t border-red-100 bg-white text-[#0A0A0A]">
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

        <p data-payslip-footer className="text-center text-[12px] text-muted-foreground print:text-[8px]">
          This is a system-generated document. Generated {generatedFooter}.
        </p>
      </div>
    </article>
  )
}

export default PayslipHtmlDocument
