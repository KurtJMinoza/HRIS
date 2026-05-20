import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { HrAppPathProvider } from '@/contexts/HrAppPathContext'
import { buildAdminNav, buildManagerNav } from '@/config/rbacNav'
import { resolvePostLoginPath, isAdminHrUser } from '@/lib/hrRoutes'
import { DashboardLayout } from '@/layouts/DashboardLayout'

const PANEL_SCOPES = new Set(['admin', 'company', 'branch', 'department', 'division', 'section-unit'])

const SCOPE_TO_ROLE = {
  company: 'company_head',
  branch: 'branch_head',
  department: 'department_head',
  division: 'division_head',
  'section-unit': 'section_unit_head',
}

/**
 * Shared layout for `/admin`, `/company`, `/branch`, `/department` (org heads use scoped panels, not `/employee/*`).
 * Scope is taken from the first URL segment (after basename).
 */
export function HrPanelLayout() {
  const { user, loading } = useAuth()
  const location = useLocation()
  const seg = location.pathname.split('/').filter(Boolean)[0]
  const scope = PANEL_SCOPES.has(seg) ? seg : null
  const hrBase = scope ? `/${scope}` : '/admin'

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="text-center">
          <div className="inline-block size-8 animate-spin rounded-full border-2 border-primary border-t-transparent" aria-hidden />
          <p className="mt-3 text-sm text-muted-foreground">Loading…</p>
        </div>
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  if (!scope) {
    return <Navigate to="/" replace />
  }

  // Admin (HR) must use /admin/* (full modules + nav), not a scoped organization shell.
  if (scope !== 'admin' && isAdminHrUser(user)) {
    const stripped = location.pathname.replace(/^\/(company|branch|department|division|section-unit)/, '')
    const suffix = stripped === '' || stripped === '/' ? '/dashboard' : (stripped.startsWith('/') ? stripped : `/${stripped}`)
    return <Navigate to={`/admin${suffix}`} replace />
  }

  if (scope === 'admin') {
    if (!isAdminHrUser(user)) {
      try {
        sessionStorage.setItem('hr_access_denied', '1')
      } catch {
        // ignore
      }
      return <Navigate to={resolvePostLoginPath(user)} replace />
    }
  } else {
    const expected = SCOPE_TO_ROLE[scope]
    if (String(user.hr_role || '').trim().toLowerCase() !== expected) {
      return <Navigate to={resolvePostLoginPath(user)} replace />
    }
  }

  // Route-level module gates.
  const permissionSet = new Set(user?.permissions ?? [])
  const hrAdmin = isAdminHrUser(user)
  const path = String(location.pathname || '')
  const isCompensationPath = /\/compensation(\/|$)/.test(path)
  if (isCompensationPath && !hrAdmin) {
    const isPayslipPath = /\/compensation\/(payslips|generate-payslips|finalize-payroll)(\/|$)/.test(path)
    if (isPayslipPath) {
      const canAccessPayslipPath =
        (/\/compensation\/generate-payslips(\/|$)/.test(path) && permissionSet.has('payslip.generate')) ||
        (/\/compensation\/finalize-payroll(\/|$)/.test(path) && permissionSet.has('payslip.finalize')) ||
        (/\/compensation\/payslips(\/|$)/.test(path) && permissionSet.has('payslip.view'))
      if (!canAccessPayslipPath) {
        return <Navigate to={`${hrBase}/dashboard`} replace />
      }
    } else if (!permissionSet.has('compensation.view')) {
      return <Navigate to={`${hrBase}/dashboard`} replace />
    }
  }

  const navItems = scope === 'admin' ? buildAdminNav(user) : buildManagerNav(user, hrBase)
  const role = scope === 'admin' ? 'admin' : 'manager'

  return (
    <HrAppPathProvider value={hrBase}>
      <DashboardLayout navItems={navItems} role={role} hrBasePath={hrBase} />
    </HrAppPathProvider>
  )
}
