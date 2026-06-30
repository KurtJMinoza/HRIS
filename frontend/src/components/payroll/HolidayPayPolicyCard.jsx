import React, { useState } from 'react'
import { BriefcaseBusiness, Building2, CalendarDays, ChevronDown, ChevronRight, Info, Scale } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import {
  EMPLOYMENT_TYPES,
  NON_STATUTORY_HOLIDAY_TYPES,
  REGULAR_UNWORKED_OPTIONS,
  SPECIAL_UNWORKED_OPTIONS,
  UNWORKED_POLICY_LABELS,
  normalizeHolidayPayPolicy,
} from '@/lib/holidayPayPolicy'

function ToggleRow({ id, checked, onCheckedChange, disabled = false, label, hint }) {
  return (
    <div className={cn(
      'flex items-start gap-3 rounded-lg border p-3.5 transition-colors',
      disabled ? 'border-border/30 bg-muted/20' : 'border-border/50 bg-card hover:border-border/70',
    )}>
      <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={onCheckedChange} className="mt-0.5" />
      <div className="min-w-0 space-y-0.5">
        <Label htmlFor={id} className={cn('text-sm font-medium leading-snug', disabled && 'cursor-default text-muted-foreground')}>
          {label}
        </Label>
        {hint && <p className="text-xs leading-relaxed text-muted-foreground">{hint}</p>}
      </div>
    </div>
  )
}

function PolicySection({ title, description, icon: Icon, badge, children }) {
  return (
    <section className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
      <div className="flex items-center justify-between gap-4 border-b border-border/50 px-5 py-4">
        <div className="flex min-w-0 items-start gap-3">
          {Icon && (
            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary">
              <Icon className="size-4" />
            </div>
          )}
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h4 className="font-semibold tracking-tight text-foreground">{title}</h4>
              {badge}
            </div>
            {description && <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{description}</p>}
          </div>
        </div>
      </div>
      <div className="space-y-4 px-5 py-4">{children}</div>
    </section>
  )
}

function EmploymentTypeMultiSelect({ idPrefix, selected = [], onChange }) {
  const toggle = (key) => {
    const next = new Set(selected)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    onChange(Array.from(next))
  }

  return (
    <div className="grid gap-2 @md:grid-cols-2">
      {EMPLOYMENT_TYPES.map(([key, label]) => (
        <ToggleRow
          key={key}
          id={`${idPrefix}-${key}`}
          checked={selected.includes(key)}
          onCheckedChange={() => toggle(key)}
          label={label}
        />
      ))}
    </div>
  )
}

function UnworkedPayDropdown({ id, label, hint, value, options, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id} className="text-sm font-medium">{label}</Label>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {options.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
      </select>
    </div>
  )
}

export function HolidayPayPolicyCard({
  policy,
  companyId,
  branchId,
  onPolicyChange,
}) {
  const [open, setOpen] = useState(true)
  const holidayPolicy = normalizeHolidayPayPolicy(policy)

  const scopeBadge = companyId
    ? branchId ? 'Branch override' : 'Company override'
    : 'Global default'

  const specialUnworkedLabel = UNWORKED_POLICY_LABELS[holidayPolicy.special_unworked.unworked_pay_policy] || '—'
  const regularUnworkedLabel = UNWORKED_POLICY_LABELS[holidayPolicy.regular_unworked.unworked_pay_policy] || '—'

  return (
    <Card className="overflow-hidden border border-border/60 bg-card shadow-sm">
      <CardHeader className="border-b border-border/50 bg-gradient-to-br from-muted/40 via-muted/20 to-transparent p-0">
        <button type="button" onClick={() => setOpen((value) => !value)} className="flex w-full items-start justify-between gap-4 px-5 py-5 text-left @sm:px-6">
          <div className="flex min-w-0 items-start gap-4">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 text-rose-700 ring-1 ring-rose-500/20 dark:text-rose-300">
              <Scale className="size-5" />
            </div>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <CardTitle className="text-xl font-semibold tracking-tight">Holiday Pay Policy</CardTitle>
                <Badge variant="outline" className="gap-1 font-normal">
                  <Building2 className="size-3" />
                  {scopeBadge}
                </Badge>
              </div>
              <CardDescription className="mt-1.5 max-w-2xl text-sm leading-relaxed">
                Configure who qualifies for unworked holiday pay. Premium multipliers are managed under the Multipliers tab.
              </CardDescription>
            </div>
          </div>
          {open ? <ChevronDown className="mt-1 size-5 shrink-0 text-muted-foreground" /> : <ChevronRight className="mt-1 size-5 shrink-0 text-muted-foreground" />}
        </button>
      </CardHeader>

      {open && (
        <CardContent className="space-y-5 p-5 @sm:p-6">
          <div className="flex items-start gap-3 rounded-xl border border-blue-500/20 bg-blue-500/5 px-4 py-3.5 text-sm">
            <Info className="mt-0.5 size-4 shrink-0 text-blue-600 dark:text-blue-400" />
            <div>
              <p className="font-medium text-foreground">Eligibility configuration</p>
              <p className="mt-1 leading-relaxed text-muted-foreground">
                Preceding workday attendance follows DOLE standards: present or approved paid leave on the immediately preceding working day. Unpaid absence on that day disqualifies unworked holiday pay.
              </p>
            </div>
          </div>

          <div className="grid gap-5 @lg:grid-cols-2">
            <PolicySection
              title="Regular holiday — unworked pay"
              description="Employees who do not work on a regular holiday."
              icon={CalendarDays}
              badge={<Badge variant="secondary" className="text-[10px] font-normal">{regularUnworkedLabel}</Badge>}
            >
              <UnworkedPayDropdown
                id="regular-unworked-policy"
                label="Unworked pay eligibility"
                hint="Determines which employee groups may receive pay without working on a regular holiday."
                value={holidayPolicy.regular_unworked.unworked_pay_policy}
                options={REGULAR_UNWORKED_OPTIONS}
                onChange={(value) => onPolicyChange(['regular_unworked', 'unworked_pay_policy'], value)}
              />
              {holidayPolicy.regular_unworked.unworked_pay_policy === 'selected_employment_types' && (
                <div className="space-y-2">
                  <p className="text-sm font-medium">Allowed employment types</p>
                  <EmploymentTypeMultiSelect
                    idPrefix="regular-unworked-types"
                    selected={holidayPolicy.regular_unworked.eligible_employment_types}
                    onChange={(types) => onPolicyChange(['regular_unworked', 'eligible_employment_types'], types)}
                  />
                </div>
              )}
              <ToggleRow
                id="elig-prev-day"
                checked={holidayPolicy.attendance.require_previous_workday_presence !== false}
                onCheckedChange={(checked) => onPolicyChange(['attendance', 'require_previous_workday_presence'], Boolean(checked))}
                label="Require preceding workday attendance"
                hint="When enabled, employees must have been present or on approved paid leave on the immediately preceding working day."
              />
            </PolicySection>

            <PolicySection
              title="Special holiday — unworked pay"
              description="Special non-working holidays default to No Work, No Pay."
              icon={CalendarDays}
              badge={<Badge variant="secondary" className="text-[10px] font-normal">{specialUnworkedLabel}</Badge>}
            >
              <UnworkedPayDropdown
                id="special-unworked-policy"
                label="Unworked pay eligibility"
                hint="Company may extend pay to absent employees on special holidays beyond the DOLE default."
                value={holidayPolicy.special_unworked.unworked_pay_policy}
                options={SPECIAL_UNWORKED_OPTIONS}
                onChange={(value) => onPolicyChange(['special_unworked', 'unworked_pay_policy'], value)}
              />
              {holidayPolicy.special_unworked.unworked_pay_policy === 'selected_employment_types' && (
                <div className="space-y-2">
                  <p className="text-sm font-medium">Allowed employment types</p>
                  <EmploymentTypeMultiSelect
                    idPrefix="special-unworked-types"
                    selected={holidayPolicy.special_unworked.eligible_employment_types}
                    onChange={(types) => onPolicyChange(['special_unworked', 'eligible_employment_types'], types)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Selected types receive special holiday pay even when absent. All others follow No Work, No Pay.
                  </p>
                </div>
              )}
            </PolicySection>
          </div>

          <div className="grid gap-5 @lg:grid-cols-2">
            {NON_STATUTORY_HOLIDAY_TYPES.map(({ key, label, hint }) => {
              const payOrdinary = holidayPolicy.non_statutory?.[key]?.pay_as_ordinary_day !== false
              const Icon = key === 'company' ? Building2 : BriefcaseBusiness

              return (
                <PolicySection
                  key={key}
                  title={label}
                  description={hint}
                  icon={Icon}
                  badge={(
                    <Badge variant="secondary" className="text-[10px] font-normal">
                      {payOrdinary ? 'Ordinary day (default)' : 'SNW premium if worked'}
                    </Badge>
                  )}
                >
                  <ToggleRow
                    id={`non-statutory-${key}-ordinary`}
                    checked={payOrdinary}
                    onCheckedChange={(checked) => onPolicyChange(['non_statutory', key, 'pay_as_ordinary_day'], Boolean(checked))}
                    label="Pay as ordinary working day"
                    hint={key === 'special_working'
                      ? 'When enabled (default), no RH/SNW statutory premium applies. Disable only if company policy treats this like a special non-working holiday.'
                      : 'When enabled (default), no statutory holiday premium. Disable if your company pays SNW rates for internal events.'}
                  />
                  <p className="text-xs leading-relaxed text-muted-foreground">
                    Unworked pay does not apply — employees who are absent are not entitled to holiday premium by default.
                  </p>
                </PolicySection>
              )
            })}
          </div>
        </CardContent>
      )}
    </Card>
  )
}
