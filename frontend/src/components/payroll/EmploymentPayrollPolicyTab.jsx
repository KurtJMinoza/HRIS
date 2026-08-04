import { useCallback, useEffect, useMemo, useState } from 'react'
import { Loader2, Save, Users } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import { getEmploymentPayrollSettings, updateEmploymentPayrollSettings } from '@/api'
import { useToast } from '@/components/ui/use-toast'

const EMPLOYMENT_TYPES = [
  { value: 'regular', label: 'Regular' },
  { value: 'probationary', label: 'Probationary' },
  { value: 'project_based', label: 'Project Based' },
  { value: 'consultant', label: 'Consultant' },
]

/** Probationary / project-based staff do not receive paid leave. */
const UNPAID_LEAVE_TYPES = new Set(['probationary', 'project_based'])

const DEFAULT_SETTINGS = {
  apply_custom_deductions: true,
  apply_allowances: true,
  allow_paid_leave: true,
  allow_overtime: false,
  allow_holiday_pay: false,
}

function defaultsForType(employmentType) {
  return {
    ...DEFAULT_SETTINGS,
    allow_paid_leave: !UNPAID_LEAVE_TYPES.has(employmentType),
  }
}

function settingsKeysForType(employmentType) {
  return SETTINGS_ORDER.filter(
    (key) => key !== 'allow_paid_leave' || !UNPAID_LEAVE_TYPES.has(employmentType)
  )
}

const SETTINGS_META = {
  apply_custom_deductions: {
    label: 'Apply custom deductions',
    description:
      'Include employee-specific and company deductions such as loans, cash advances, and manual deductions.',
  },
  apply_allowances: {
    label: 'Apply allowances',
    description: 'Load employee allowances from Employee Compensation into gross pay and the payslip.',
  },
  allow_paid_leave: {
    label: 'Allow paid leave',
    description: 'Include approved paid leave as payable time in regular payroll.',
  },
  allow_overtime: {
    label: 'Allow overtime',
    description: 'Include approved overtime earnings using existing overtime multipliers and pay conditions.',
  },
  allow_holiday_pay: {
    label: 'Allow holiday pay',
    description:
      'Include Holiday Module pay components (worked/unworked) only when the holiday covers the employee (scope/coverage), using Policy Settings multipliers. Staff match by their regular employment class (e.g. Regular / Full-time), not a separate EXECOM type.',
  },
}

const SETTINGS_ORDER = Object.keys(DEFAULT_SETTINGS)

function buildDefaultSettingsMap() {
  return Object.fromEntries(
    EMPLOYMENT_TYPES.map(({ value }) => [
      value,
      { employment_type: value, ...defaultsForType(value) },
    ])
  )
}

function normalizeTypeSettings(employmentType, row = {}) {
  const defaults = defaultsForType(employmentType)
  const next = {
    employment_type: employmentType,
    ...defaults,
    ...row,
  }
  if (UNPAID_LEAVE_TYPES.has(employmentType)) {
    next.allow_paid_leave = false
  }
  return next
}

function behaviorPreviewLines(settings, employmentType) {
  const lines = [
    'Government deductions: Applied via Employee Exemptions',
    `Custom deductions: ${settings.apply_custom_deductions ? 'Applied' : 'Not applied'}`,
    `Allowances: ${settings.apply_allowances ? 'Included' : 'Not included'}`,
  ]
  if (!UNPAID_LEAVE_TYPES.has(employmentType)) {
    lines.push(`Paid leave: ${settings.allow_paid_leave ? 'Included when approved and paid' : 'Not included'}`)
  } else {
    lines.push('Paid leave: Not included (unpaid for this employment class)')
  }
  lines.push(
    `Overtime: ${settings.allow_overtime ? 'Included' : 'Not included'}`,
    `Holiday pay: ${settings.allow_holiday_pay ? 'Included when in Holiday Module scope' : 'Not included'}`,
  )
  return lines
}

export function EmploymentPayrollPolicyTab({ companies = [], companyFilter = '' }) {
  const { toast } = useToast()
  const [settingsByType, setSettingsByType] = useState(buildDefaultSettingsMap)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [scopeCompanyId, setScopeCompanyId] = useState('')
  const [activeType, setActiveType] = useState('regular')

  const activeSettings = settingsByType[activeType] ?? normalizeTypeSettings(activeType)
  const visibleSettingKeys = settingsKeysForType(activeType)

  const loadSettings = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (scopeCompanyId) params.company_id = parseInt(scopeCompanyId, 10)
      const data = await getEmploymentPayrollSettings(params)
      const incoming = data?.settings && typeof data.settings === 'object' ? data.settings : {}
      const next = buildDefaultSettingsMap()
      for (const { value } of EMPLOYMENT_TYPES) {
        const row = incoming[value]
        if (row && typeof row === 'object') {
          next[value] = normalizeTypeSettings(value, row)
        }
      }
      setSettingsByType(next)
      setDirty(false)
    } catch (error) {
      toast({
        title: 'Failed to load employment payroll settings',
        description: error?.message,
        variant: 'error',
      })
    } finally {
      setLoading(false)
    }
  }, [scopeCompanyId, toast])

  useEffect(() => {
    loadSettings()
  }, [loadSettings])

  // ponytail: Employment settings stay Global by default. Do not mirror the policy-list
  // company filter — that silently created company overrides while users thought they
  // edited the shared Probationary toggle.

  const toggleSetting = (key, checked) => {
    if (key === 'allow_paid_leave' && UNPAID_LEAVE_TYPES.has(activeType)) {
      return
    }
    setSettingsByType((prev) => ({
      ...prev,
      [activeType]: normalizeTypeSettings(activeType, {
        ...(prev[activeType] ?? defaultsForType(activeType)),
        [key]: checked,
      }),
    }))
    setDirty(true)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload = {
        settings: EMPLOYMENT_TYPES.map(({ value }) =>
          normalizeTypeSettings(value, settingsByType[value] ?? defaultsForType(value))
        ),
      }
      if (scopeCompanyId) payload.company_id = parseInt(scopeCompanyId, 10)
      const data = await updateEmploymentPayrollSettings(payload)
      const incoming = data?.settings && typeof data.settings === 'object' ? data.settings : {}
      const next = buildDefaultSettingsMap()
      for (const { value } of EMPLOYMENT_TYPES) {
        const row = incoming[value]
        if (row && typeof row === 'object') {
          next[value] = normalizeTypeSettings(value, row)
        }
      }
      setSettingsByType(next)
      setDirty(false)
      toast({ title: 'Employment payroll settings saved' })
    } catch (error) {
      toast({
        title: 'Failed to save employment payroll settings',
        description: error?.message,
        variant: 'error',
      })
    } finally {
      setSaving(false)
    }
  }

  const scopeLabel = useMemo(() => {
    if (!scopeCompanyId) return 'Global default'
    const company = companies.find((row) => String(row.id) === String(scopeCompanyId))
    return company?.name ?? `Company #${scopeCompanyId}`
  }, [companies, scopeCompanyId])

  return (
    <Card className="border-0 bg-card shadow-sm overflow-hidden">
      <CardHeader className="border-b border-border/40 bg-muted/20 px-4 pb-4 pt-4 @sm:px-6">
        <div className="flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15 @sm:size-11 dark:bg-primary/15">
              <Users className="size-[1.125rem] @sm:size-5" aria-hidden />
            </div>
            <div className="min-w-0 space-y-1">
              <CardTitle className="text-lg font-semibold tracking-tight">Employment payroll components</CardTitle>
              <CardDescription className="text-sm leading-relaxed">
                Control which earnings and deductions apply per employment class in regular payroll.
                Defaults to <span className="font-medium text-foreground">Global</span>
                {scopeCompanyId ? (
                  <>
                    {' '}(currently scoped to <span className="font-medium text-foreground">{scopeLabel}</span>)
                  </>
                ) : null}
                . Pick a company only when you need a company-specific override.
              </CardDescription>
            </div>
          </div>
          <div className="flex w-full flex-col gap-2 @sm:w-auto @sm:flex-row @sm:items-center">
            <select
              className={cn(FIELD_SELECT_CLASS, 'min-w-[12rem]')}
              value={scopeCompanyId}
              onChange={(event) => {
                setScopeCompanyId(event.target.value)
                setDirty(false)
              }}
            >
              <option value="">Global default</option>
              {companies.map((company) => (
                <option key={company.id} value={String(company.id)}>
                  {company.name}
                </option>
              ))}
            </select>
            <Button onClick={handleSave} disabled={!dirty || saving || loading} className="shadow-sm">
              {saving ? <Loader2 className="size-4 animate-spin mr-1" /> : <Save className="size-4 mr-1" />}
              Save settings
            </Button>
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-5 p-4 @sm:p-6">
        {loading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            Loading employment payroll settings…
          </div>
        ) : (
          <>
            <div className="flex flex-wrap gap-2">
              {EMPLOYMENT_TYPES.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  className={cn(
                    'rounded-full border px-3 py-1.5 text-sm font-medium transition-colors',
                    activeType === value
                      ? 'border-primary/30 bg-primary/10 text-foreground'
                      : 'border-border/70 bg-background text-muted-foreground hover:text-foreground'
                  )}
                  onClick={() => setActiveType(value)}
                >
                  {label}
                </button>
              ))}
            </div>

            <div className="grid gap-3">
              {visibleSettingKeys.map((key) => {
                const meta = SETTINGS_META[key]
                const checked = Boolean(activeSettings[key])
                return (
                  <label
                    key={key}
                    className="flex cursor-pointer items-start gap-3 rounded-2xl border border-border/60 bg-muted/15 p-4 transition-colors hover:bg-muted/25"
                  >
                    <Checkbox
                      checked={checked}
                      onCheckedChange={(value) => toggleSetting(key, value === true)}
                      className="mt-0.5"
                    />
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-foreground">{meta.label}</span>
                        <span
                          className={cn(
                            'rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide',
                            checked
                              ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200'
                              : 'bg-muted text-muted-foreground'
                          )}
                        >
                          {checked ? 'Enabled' : 'Disabled'}
                        </span>
                      </span>
                      <span className="mt-1 block text-sm leading-relaxed text-muted-foreground">
                        {meta.description}
                      </span>
                    </span>
                  </label>
                )
              })}
            </div>

            <div className="rounded-2xl border border-border/60 bg-background/80 p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                Payroll behavior preview
              </p>
              <ul className="mt-3 space-y-1.5 text-sm text-foreground">
                {behaviorPreviewLines(activeSettings, activeType).map((line) => (
                  <li key={line} className="flex gap-2">
                    <span className="text-muted-foreground">•</span>
                    <span>{line}</span>
                  </li>
                ))}
              </ul>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
