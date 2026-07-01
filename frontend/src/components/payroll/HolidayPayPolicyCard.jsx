import React from 'react'
import { Building2, CalendarClock, Info, Moon, Sun } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import {
  REGULAR_UNWORKED_OPTIONS,
  SPECIAL_UNWORKED_OPTIONS,
  COVERAGE_BEHAVIOUR_OPTIONS,
  WORKED_EMPLOYMENT_TYPE_OPTIONS,
  normalizeHolidayPayPolicy,
} from '@/lib/holidayPayPolicy'

function ToggleRow({ id, checked, onCheckedChange, label, hint }) {
  return (
    <label
      htmlFor={id}
      className="flex cursor-pointer items-start gap-3 rounded-lg border border-border/50 bg-background/80 px-3.5 py-3 transition-colors hover:bg-muted/30"
    >
      <Checkbox id={id} checked={checked} onCheckedChange={onCheckedChange} className="mt-0.5" />
      <div className="min-w-0 space-y-0.5">
        <span className="text-sm font-medium leading-snug">{label}</span>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </div>
    </label>
  )
}

function PolicySelect({ id, label, value, options, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id} className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </Label>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger id={id} className="h-10 w-full bg-background">
          <SelectValue placeholder="Select policy" />
        </SelectTrigger>
        <SelectContent>
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}

function CoverageBehaviourToggle({ id, value, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        Coverage behaviour
      </Label>
      <div role="radiogroup" aria-labelledby={`${id}-label`} className="grid gap-2 sm:grid-cols-2">
        {COVERAGE_BEHAVIOUR_OPTIONS.map((option) => {
          const selected = value === option.value
          const isIgnore = option.value === 'ignore_coverage'
          return (
            <button
              key={option.value}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => onChange(option.value)}
              className={cn(
                'rounded-lg border px-3 py-2.5 text-left text-sm transition-colors',
                selected
                  ? isIgnore
                    ? 'border-amber-500/50 bg-amber-500/10 ring-1 ring-amber-500/30'
                    : 'border-primary/40 bg-primary/5 ring-1 ring-primary/20'
                  : 'border-border/60 bg-background hover:bg-muted/40',
              )}
            >
              <span className="font-medium">{isIgnore ? 'Ignore coverage' : 'Respect coverage'}</span>
              <span className="mt-0.5 block text-xs text-muted-foreground">
                {isIgnore ? 'Payroll only — calendar still uses Holiday Coverage' : 'DOLE default — must be in scope'}
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
}

function EmploymentTypeSelector({ idPrefix, options, selected, loading, onChange }) {
  const toggle = (value) => {
    const next = new Set(selected)
    if (next.has(value)) next.delete(value)
    else next.add(value)
    onChange(Array.from(next))
  }

  if (loading) return <p className="text-sm text-muted-foreground">Loading employment types…</p>
  if (!options.length) {
    return (
      <p className="rounded-lg border border-dashed border-border/60 px-3 py-2 text-sm text-muted-foreground">
        No employment types found for active employees in this scope.
      </p>
    )
  }

  return (
    <div className="space-y-2 rounded-lg border border-border/50 bg-muted/15 p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        Allowed employment types
      </p>
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((option) => (
          <ToggleRow
            key={option.value}
            id={`${idPrefix}-${option.value}`}
            checked={selected.includes(option.value)}
            onCheckedChange={() => toggle(option.value)}
            label={option.label}
            hint={`${option.employee_count} active`}
          />
        ))}
      </div>
    </div>
  )
}

function PayScenarioBlock({ icon: Icon, title, children, muted }) {
  return (
    <div className={cn('space-y-3 rounded-lg border border-border/50 p-4', muted && 'bg-muted/10')}>
      <div className="flex items-center gap-2">
        <Icon className="size-4 text-muted-foreground" aria-hidden />
        <h5 className="text-sm font-semibold">{title}</h5>
      </div>
      {children}
    </div>
  )
}

function HolidayTypePanel({ title, accent, children }) {
  return (
    <section className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
      <div className={cn('border-b border-border/50 px-5 py-4', accent)}>
        <h3 className="text-base font-semibold tracking-tight">{title}</h3>
      </div>
      <div className="space-y-4 p-5">{children}</div>
    </section>
  )
}

export function HolidayPayPolicyCard({
  policy,
  companyId,
  branchId,
  employmentTypes = [],
  employmentTypesLoading = false,
  onPolicyChange,
}) {
  const holidayPolicy = normalizeHolidayPayPolicy(policy)
  const scopeBadge = companyId ? (branchId ? 'Branch override' : 'Company override') : 'Global default'

  return (
    <Card className="overflow-hidden border border-rose-200/40 shadow-sm dark:border-rose-900/35">
      <CardHeader className="border-b border-rose-200/50 bg-gradient-to-r from-rose-50/90 via-background to-background pb-4 dark:border-rose-900/40 dark:from-rose-950/40 dark:via-card dark:to-card">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <CardTitle className="text-lg font-semibold tracking-tight">Holiday pay policy</CardTitle>
              <Badge variant="outline" className="gap-1 border-rose-300/50 bg-background/80">
                <Building2 className="size-3" />
                {scopeBadge}
              </Badge>
            </div>
            <CardDescription className="mt-1.5 max-w-2xl text-sm leading-relaxed">
              Controls payroll eligibility and earnings. Holiday Coverage in the Holidays module still drives
              calendar and attendance. Worked premium rates live in the Multipliers tab.
            </CardDescription>
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-6 p-5 @sm:p-6">
        <div className="flex gap-3 rounded-xl border border-border/50 bg-muted/20 px-4 py-3 text-sm dark:bg-muted/10">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-muted-foreground">
            <span className="font-medium text-foreground">Coverage behaviour</span> decides whether payroll
            pays employees outside the holiday&apos;s organizational scope. It never changes who sees a holiday
            on the calendar.
          </p>
        </div>

        <div className="grid gap-6 xl:grid-cols-2">
          <HolidayTypePanel
            title="Regular holiday"
            accent="bg-rose-500/5 dark:bg-rose-950/20"
          >
            <PayScenarioBlock icon={Moon} title="Unworked pay">
              <PolicySelect
                id="regular-unworked-policy"
                label="Who gets paid without clock-in"
                value={holidayPolicy.regular_unworked.unworked_pay_policy}
                options={REGULAR_UNWORKED_OPTIONS}
                onChange={(value) => onPolicyChange(['regular_unworked', 'unworked_pay_policy'], value)}
              />
              {holidayPolicy.regular_unworked.unworked_pay_policy === 'selected_employment_types' && (
                <EmploymentTypeSelector
                  idPrefix="regular-employment-type"
                  options={employmentTypes}
                  selected={holidayPolicy.regular_unworked.eligible_employment_types}
                  loading={employmentTypesLoading}
                  onChange={(value) => onPolicyChange(['regular_unworked', 'eligible_employment_types'], value)}
                />
              )}
              <CoverageBehaviourToggle
                id="regular-unworked-coverage"
                value={holidayPolicy.regular_unworked.coverage_behaviour}
                onChange={(value) => onPolicyChange(['regular_unworked', 'coverage_behaviour'], value)}
              />
            </PayScenarioBlock>

            <PayScenarioBlock icon={Sun} title="Worked pay" muted>
              <p className="text-xs text-muted-foreground">
                Premium multiplier (e.g. 200%) is set under Multipliers → Regular Holiday.
              </p>
              <PolicySelect
                id="regular-worked-employment-rule"
                label="Employment type rule"
                value={holidayPolicy.regular_worked.employment_type_rule}
                options={WORKED_EMPLOYMENT_TYPE_OPTIONS}
                onChange={(value) => onPolicyChange(['regular_worked', 'employment_type_rule'], value)}
              />
              {holidayPolicy.regular_worked.employment_type_rule === 'selected_employment_types' && (
                <EmploymentTypeSelector
                  idPrefix="regular-worked-employment-type"
                  options={employmentTypes}
                  selected={holidayPolicy.regular_worked.eligible_employment_types}
                  loading={employmentTypesLoading}
                  onChange={(value) => onPolicyChange(['regular_worked', 'eligible_employment_types'], value)}
                />
              )}
              <CoverageBehaviourToggle
                id="regular-worked-coverage"
                value={holidayPolicy.regular_worked.coverage_behaviour}
                onChange={(value) => onPolicyChange(['regular_worked', 'coverage_behaviour'], value)}
              />
            </PayScenarioBlock>
          </HolidayTypePanel>

          <HolidayTypePanel
            title="Special holiday"
            accent="bg-amber-500/5 dark:bg-amber-950/20"
          >
            <PayScenarioBlock icon={Moon} title="Unworked pay">
              <PolicySelect
                id="special-unworked-policy"
                label="Who gets paid without clock-in"
                value={holidayPolicy.special_unworked.unworked_pay_policy}
                options={SPECIAL_UNWORKED_OPTIONS}
                onChange={(value) => onPolicyChange(['special_unworked', 'unworked_pay_policy'], value)}
              />
              {holidayPolicy.special_unworked.unworked_pay_policy === 'selected_employment_types' && (
                <EmploymentTypeSelector
                  idPrefix="special-employment-type"
                  options={employmentTypes}
                  selected={holidayPolicy.special_unworked.eligible_employment_types}
                  loading={employmentTypesLoading}
                  onChange={(value) => onPolicyChange(['special_unworked', 'eligible_employment_types'], value)}
                />
              )}
              <CoverageBehaviourToggle
                id="special-unworked-coverage"
                value={holidayPolicy.special_unworked.coverage_behaviour}
                onChange={(value) => onPolicyChange(['special_unworked', 'coverage_behaviour'], value)}
              />
            </PayScenarioBlock>

            <PayScenarioBlock icon={Sun} title="Worked pay" muted>
              <p className="text-xs text-muted-foreground">
                Premium multiplier is set under Multipliers → Special Holiday.
              </p>
              <PolicySelect
                id="special-worked-employment-rule"
                label="Employment type rule"
                value={holidayPolicy.special_worked.employment_type_rule}
                options={WORKED_EMPLOYMENT_TYPE_OPTIONS}
                onChange={(value) => onPolicyChange(['special_worked', 'employment_type_rule'], value)}
              />
              {holidayPolicy.special_worked.employment_type_rule === 'selected_employment_types' && (
                <EmploymentTypeSelector
                  idPrefix="special-worked-employment-type"
                  options={employmentTypes}
                  selected={holidayPolicy.special_worked.eligible_employment_types}
                  loading={employmentTypesLoading}
                  onChange={(value) => onPolicyChange(['special_worked', 'eligible_employment_types'], value)}
                />
              )}
              <CoverageBehaviourToggle
                id="special-worked-coverage"
                value={holidayPolicy.special_worked.coverage_behaviour}
                onChange={(value) => onPolicyChange(['special_worked', 'coverage_behaviour'], value)}
              />
            </PayScenarioBlock>
          </HolidayTypePanel>
        </div>

        <section className="rounded-xl border border-border/60 bg-card shadow-sm">
          <div className="border-b border-border/50 px-5 py-4">
            <div className="flex items-center gap-2">
              <CalendarClock className="size-4 text-muted-foreground" aria-hidden />
              <h4 className="font-semibold">Unworked regular — attendance rules</h4>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              Applied when evaluating unworked regular holiday pay only.
            </p>
          </div>
          <div className="grid gap-3 p-5 sm:grid-cols-2">
            <ToggleRow
              id="previous-workday-required"
              checked={holidayPolicy.attendance.require_previous_workday_presence !== false}
              onCheckedChange={(checked) =>
                onPolicyChange(['attendance', 'require_previous_workday_presence'], Boolean(checked))
              }
              label="Require attendance on the previous workday"
              hint="Employee must have been present (or on paid leave) before the holiday."
            />
            <ToggleRow
              id="successive-holiday-rule"
              checked={holidayPolicy.regular_unworked.successive_holiday_rule !== false}
              onCheckedChange={(checked) =>
                onPolicyChange(['regular_unworked', 'successive_holiday_rule'], Boolean(checked))
              }
              label="Successive holiday rule"
              hint="Back-to-back regular holidays share the first holiday's qualifying condition."
            />
          </div>
        </section>
      </CardContent>
    </Card>
  )
}
