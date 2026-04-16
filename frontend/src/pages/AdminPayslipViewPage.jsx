import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, FileDown, Loader2, Printer } from 'lucide-react'
import {
  getAdminPayslipPdfBlob,
  getAdminPayslipViewData,
  getAdminPayslipViewPreviewData,
  getEmployeePayslipViewData,
  getMyPayslipPdfBlob,
} from '@/api'
import PayslipHtmlDocument from '@/components/payslips/PayslipHtmlDocument'
import { PAYSLIP_PAGE_PRINT_STYLES } from '@/components/payslips/payslipPrintStyles'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { Button } from '@/components/ui/button'
import { useToast } from '@/components/ui/use-toast'

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
    <div className="relative min-h-screen bg-white text-[#0A0A0A] print:min-h-0 print:bg-white print:p-0 dark:bg-background dark:text-foreground">
      <style dangerouslySetInnerHTML={{ __html: PAYSLIP_PAGE_PRINT_STYLES }} />

      <div aria-hidden data-payslip-bg className="pointer-events-none absolute inset-0 -z-10 overflow-hidden print:hidden">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_120%_80%_at_50%_-15%,oklch(0.96_0.02_247/0.85),transparent_55%)]" />
        <div className="absolute inset-0 bg-[linear-gradient(180deg,oklch(0.992_0.002_247)_0%,oklch(0.985_0.002_247)_45%,oklch(0.975_0.004_247)_100%)]" />
      </div>

      <div className="relative w-full min-w-0 max-w-none px-3 py-4 print:max-w-none print:px-0 print:py-0 sm:px-4 md:px-5 lg:px-6 lg:py-5 @2xl:py-8">
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

        <PayslipHtmlDocument data={data} isPreviewMode={isPreviewMode} />
      </div>
    </div>
  )
}
