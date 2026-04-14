import { NavLink } from 'react-router-dom'
import { Calculator, Settings } from 'lucide-react'
import { cn } from '@/lib/utils'

const chip = ({ isActive }) =>
  cn(
    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors',
    isActive
      ? 'border-primary bg-primary/15 text-primary dark:bg-primary/20'
      : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground'
  )

/**
 * In-page tabs for the Daily computation section (matches sidebar: Daily computation + Policy settings).
 */
export function DailyComputationSubNav() {
  return (
    <nav className="flex flex-wrap gap-2" aria-label="Daily computation sections">
      <NavLink to="/admin/daily-computation" end className={chip}>
        <Calculator className="size-4 shrink-0" aria-hidden />
        Daily computation
      </NavLink>
      <NavLink to="/admin/daily-computation/policy-settings" className={chip}>
        <Settings className="size-4 shrink-0" aria-hidden />
        Policy settings
      </NavLink>
    </nav>
  )
}
