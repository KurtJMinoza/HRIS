import { useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { Card, CardContent } from '@/components/ui/card'
import { PayrollLogisticsPolicyShell } from '@/components/payroll/PayrollLogisticsPolicyShell'

const MODULES = [
  {
    match: (p) => p.includes('/daily-computation/rules'),
    title: 'Rules',
    subtitle: 'Rules engine — configure how payroll classifies time and applies policies.',
  },
  {
    match: (p) => p.includes('/daily-computation/audit'),
    title: 'Audit',
    subtitle: 'Audit trail for policy changes and payroll configuration.',
  },
]

export default function AdminPayrollLogisticsPlaceholder() {
  useAuth()
  const { pathname } = useLocation()
  const cfg = MODULES.find((m) => m.match(pathname)) ?? {
    title: 'Payroll module',
    subtitle: '',
  }

  return (
    <PayrollLogisticsPolicyShell disableActions>
      <div className="mx-auto max-w-[1600px] py-8">
        <Card className="border-0 bg-card shadow-sm">
          <CardContent className="space-y-2 py-20 text-center text-muted-foreground">
            <h2 className="text-2xl font-bold tracking-tight text-foreground">{cfg.title}</h2>
            {cfg.subtitle ? <p className="text-sm">{cfg.subtitle}</p> : null}
            <p className="pt-4 text-sm">Coming soon.</p>
          </CardContent>
        </Card>
      </div>
    </PayrollLogisticsPolicyShell>
  )
}
