import React from 'react'
import {
  CalendarDays,
  Check,
  ChevronRight,
  CircleDollarSign,
  Info,
  ShieldCheck,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import {
  REGULAR_UNWORKED_OPTIONS,
  COVERAGE_BEHAVIOUR_OPTIONS,
  WORKED_EMPLOYMENT_TYPE_OPTIONS,
  normalizeHolidayPayPolicy,
} from '@/lib/holidayPayPolicy'

function ToggleRow({ id, checked, onCheckedChange, label, hint }) {
  return (
    <label
      htmlFor={id}
      className="flex min-h-[4rem] cursor-pointer items-start gap-3 rounded-lg border border-border/60 bg-background px-3 py-3 transition-colors hover:border-orange-300/70 hover:bg-orange-50/30 dark:hover:bg-orange-950/10"
    >
      <Checkbox id={id} checked={checked} onCheckedChange={onCheckedChange} className="mt-0.5 text-orange-600" />
      <div className="min-w-0 space-y-1">
        <span className="block text-sm font-semibold leading-snug text-foreground">{label}</span>
        {hint && <p className="text-xs leading-relaxed text-muted-foreground">{hint}</p>}
      </div>
    </label>
  )
}

function PolicySelect({ id, label, value, options, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id} className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
        {label}
      </Label>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger id={id} className="h-9 w-full rounded-md border-border/70 bg-background text-sm shadow-none">
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

function StepHeader({ number, title, description }) {
  return (
    <div className="flex items-start gap-2">
      <span className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-md border border-orange-300 bg-orange-50 text-[11px] font-bold text-orange-600 dark:border-orange-800/70 dark:bg-orange-950/40 dark:text-orange-300">
        {number}
      </span>
      <div className="min-w-0">
        <h4 className="text-sm font-semibold leading-tight text-foreground">{title}</h4>
        {description ? <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">{description}</p> : null}
      </div>
    </div>
  )
}

function PolicyStepSection({ number, title, description, children }) {
  return (
    <section className="rounded-lg border border-border/70 bg-card p-4 shadow-sm">
      <StepHeader number={number} title={title} description={description} />
      <div className="mt-4">{children}</div>
    </section>
  )
}

function RadioTile({ idPrefix = 'holiday-pay', value, title, description, badge, trailing, className }) {
  const id = `${idPrefix}-${value}`
  return (
    <label
      htmlFor={id}
      className={cn(
        'flex min-h-[3.625rem] cursor-pointer items-center gap-3 rounded-lg border border-border/70 bg-background px-3 py-2.5 transition-colors',
        'has-[[data-state=checked]]:border-orange-400 has-[[data-state=checked]]:bg-orange-50/70 has-[[data-state=checked]]:shadow-sm dark:has-[[data-state=checked]]:bg-orange-950/15',
        'hover:border-orange-300/80 hover:bg-orange-50/30 dark:hover:bg-orange-950/10',
        className,
      )}
    >
      <RadioGroupItem
        id={id}
        value={value}
        className="border-muted-foreground text-orange-600 data-[state=checked]:border-orange-600"
      />
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="text-sm font-semibold leading-snug text-foreground">{title}</span>
          {badge ? (
            <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
              {badge}
            </span>
          ) : null}
        </div>
        {description ? <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">{description}</p> : null}
      </div>
      {trailing ? <div className="shrink-0 text-muted-foreground">{trailing}</div> : null}
    </label>
  )
}

function CoverageBehaviourCards({ idPrefix, value, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
        Coverage behaviour
      </Label>
      <RadioGroup value={value} onValueChange={onChange} className="grid gap-2 sm:grid-cols-2">
        {COVERAGE_BEHAVIOUR_OPTIONS.map((option) => {
          const isIgnore = option.value === 'ignore_coverage'
          return (
            <RadioTile
              key={option.value}
              idPrefix={idPrefix}
              value={option.value}
              title={isIgnore ? 'Ignore coverage' : 'Respect coverage'}
              description={isIgnore ? 'Payroll only - applies to present and absent outside scope' : 'DOLE default - must be in scope'}
            />
          )
        })}
      </RadioGroup>
    </div>
  )
}

function EmploymentTypeSelector({ idPrefix, options, selected, loading, onChange }) {
  const selectedSet = new Set(selected)
  const toggle = (value) => {
    const next = new Set(selectedSet)
    if (next.has(value)) next.delete(value)
    else next.add(value)
    onChange(Array.from(next))
  }

  if (loading) return <p className="text-sm text-muted-foreground">Loading employment types...</p>
  if (!options.length) {
    return (
      <p className="rounded-lg border border-dashed border-border/60 px-3 py-2 text-sm text-muted-foreground">
        No employment types found for active employees in this scope.
      </p>
    )
  }

  return (
    <div className="space-y-2 rounded-lg border border-border/60 bg-muted/15 p-3">
      <p className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Allowed employment types</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((option) => (
          <ToggleRow
            key={option.value}
            id={`${idPrefix}-${option.value}`}
            checked={selectedSet.has(option.value)}
            onCheckedChange={() => toggle(option.value)}
            label={option.label}
            hint={`${option.employee_count} active`}
          />
        ))}
      </div>
    </div>
  )
}

function EmploymentRuleGroup({ idPrefix, title, mode, selected, options, loading, onModeChange, onTypesChange }) {
  return (
    <div className="space-y-2">
      <PolicySelect id={`${idPrefix}-rule`} label={title} value={mode} options={WORKED_EMPLOYMENT_TYPE_OPTIONS} onChange={onModeChange} />
      {mode === 'selected_employment_types' ? (
        <EmploymentTypeSelector
          idPrefix={idPrefix}
          options={options}
          selected={selected}
          loading={loading}
          onChange={onTypesChange}
        />
      ) : null}
    </div>
  )
}

function HolidayChecklist({ idPrefix, title, holidays, selected, loading, onChange }) {
  const availableIds = new Set(holidays.map((holiday) => Number(holiday.id)))
  const selectedIds = selected.map(Number).filter((id) => availableIds.has(id))
  const selectedSet = new Set(selectedIds)
  const toggle = (id) => {
    const next = new Set(selectedSet)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    onChange(Array.from(next))
  }

  return (
    <div className="rounded-lg border border-border/60 bg-background p-3">
      <div className="mb-2 flex items-center justify-between gap-3">
        <p className="text-xs font-semibold text-foreground">{title}</p>
        <Badge variant="secondary" className="rounded-full text-[10px]">
          {selectedIds.length} selected
        </Badge>
      </div>
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading holidays...</p>
      ) : holidays.length ? (
        <div className="grid max-h-52 gap-2 overflow-y-auto pr-1">
          {holidays.map((holiday) => (
            <ToggleRow
              key={holiday.id}
              id={`${idPrefix}-${holiday.id}`}
              checked={selectedSet.has(Number(holiday.id))}
              onCheckedChange={() => toggle(Number(holiday.id))}
              label={holiday.name}
              hint={holiday.date}
            />
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No active holidays found in the Holiday Module.</p>
      )}
    </div>
  )
}

function HowItWorksPanel() {
  const items = [
    'Pays employees who did not work on covered holidays.',
    'Uses the 1.00x ordinary day rate from Multipliers.',
    'Overtime on the holiday uses holiday OT rates from Multipliers.',
    'Day type and attendance still follow Holiday Coverage.',
  ]

  return (
    <aside className="rounded-lg border border-orange-200 bg-orange-50/60 p-4 dark:border-orange-900/50 dark:bg-orange-950/20">
      <h5 className="text-xs font-bold uppercase tracking-wide text-foreground">How it works</h5>
      <div className="mt-3 space-y-3">
        {items.map((item) => (
          <div key={item} className="flex gap-2 text-sm leading-relaxed text-muted-foreground">
            <Check className="mt-0.5 size-3.5 shrink-0 text-orange-600" />
            <span>{item}</span>
          </div>
        ))}
      </div>
    </aside>
  )
}

function PayComponentsPanel() {
  const rows = [
    ['Regular pay', '100% (1.00x)'],
    ['Holiday premium', '100% (1.00x)'],
  ]

  return (
    <div className="rounded-lg border border-border/70 bg-background p-3">
      <p className="mb-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Pay components</p>
      <div className="divide-y divide-border/60 overflow-hidden rounded-md border border-border/60">
        {rows.map(([label, value]) => (
          <div key={label} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium text-foreground">{value}</span>
          </div>
        ))}
        <div className="flex items-center justify-between gap-3 bg-orange-50/70 px-3 py-2.5 text-sm dark:bg-orange-950/20">
          <span className="font-semibold text-foreground">Total</span>
          <span className="font-bold text-foreground">200% (2.00x)</span>
        </div>
      </div>
    </div>
  )
}

export function HolidayPayPolicyCard({
  policy,
  companyId,
  branchId,
  employmentTypes = [],
  employmentTypesLoading = false,
  holidays = [],
  holidaysLoading = false,
  onPolicyChange,
}) {
  const holidayPolicy = normalizeHolidayPayPolicy(policy)
  const scopeBadge = companyId ? (branchId ? 'Branch override' : 'Company override') : 'Global default'
  const regularHolidays = holidays.filter((holiday) => ['regular', 'regular_holiday'].includes(String(holiday.type).toLowerCase()))
  const specialHolidays = holidays.filter((holiday) => ['special', 'special_non_working', 'special_non_working_holiday'].includes(String(holiday.type).toLowerCase()))

  const selectedRegularHolidayCount = holidayPolicy.regular_unworked.holiday_ids.length
  const selectedSpecialHolidayCount = holidayPolicy.special_unworked.holiday_ids.length

  const unworkedCoverageValue =
    holidayPolicy.regular_unworked.coverage_behaviour === 'ignore_coverage' ||
    holidayPolicy.special_unworked.coverage_behaviour === 'ignore_coverage'
      ? 'ignore_coverage'
      : 'respect_coverage'

  const workedCoverageValue =
    holidayPolicy.regular_worked.coverage_behaviour === 'ignore_coverage' ||
    holidayPolicy.special_worked.coverage_behaviour === 'ignore_coverage'
      ? 'ignore_coverage'
      : 'respect_coverage'

  const workedEmploymentValue =
    holidayPolicy.regular_worked.employment_type_rule === 'selected_employment_types' ||
    holidayPolicy.special_worked.employment_type_rule === 'selected_employment_types'
      ? 'selected_employment_types'
      : 'all_employment_types'

  const specialUnworkedEnabled = Boolean(holidayPolicy.pay_unworked_special)
  const specialIncludedMode =
    holidayPolicy.special_unworked.holiday_selection_mode === 'selected_special_holidays'
      ? 'selected'
      : 'all_special'

  const regularIncludedMode =
    holidayPolicy.regular_unworked.holiday_selection_mode === 'selected_regular_holidays'
      ? 'selected'
      : 'all_regular'

  const setBoth = (pairs) => {
    pairs.forEach(([path, value]) => onPolicyChange(path, value))
  }

  const setSpecialUnworkedEnabled = (enabled) => {
    if (enabled) {
      onPolicyChange(
        ['special_unworked', 'holiday_selection_mode'],
        specialIncludedMode === 'selected' ? 'selected_special_holidays' : 'all_special_holidays',
      )
      return
    }
    onPolicyChange(['special_unworked', 'holiday_selection_mode'], 'no_work_no_pay_default')
  }

  const setRegularIncludedMode = (value) => {
    onPolicyChange(
      ['regular_unworked', 'holiday_selection_mode'],
      value === 'selected' ? 'selected_regular_holidays' : 'dole_default',
    )
  }

  const setSpecialIncludedMode = (value) => {
    onPolicyChange(
      ['special_unworked', 'holiday_selection_mode'],
      value === 'selected' ? 'selected_special_holidays' : 'all_special_holidays',
    )
  }

  const setWorkedEmploymentTypes = (value) => {
    setBoth([
      [['regular_worked', 'eligible_employment_types'], value],
      [['special_worked', 'eligible_employment_types'], value],
    ])
  }

  const selectedWorkedEmploymentTypes = Array.from(
    new Set([
      ...holidayPolicy.regular_worked.eligible_employment_types,
      ...holidayPolicy.special_worked.eligible_employment_types,
    ]),
  )

  return (
    <Card className="overflow-hidden rounded-lg border border-border/70 bg-card shadow-sm">
      <CardHeader className="space-y-2 border-b border-border/50 bg-card px-5 py-5">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle className="text-lg font-semibold tracking-tight">Holiday pay policy</CardTitle>
          <Badge variant="secondary" className="rounded-full px-2 py-0.5 text-[10px] font-semibold">
            {scopeBadge}
          </Badge>
        </div>
        <CardDescription className="max-w-4xl text-sm leading-relaxed">
          Controls payroll eligibility and earnings. Holiday Coverage in the Holidays module still drives calendar
          and attendance. Worked premium rates live in the Multipliers tab.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4 p-5">
        <div className="flex gap-3 rounded-lg border border-border/60 bg-background px-4 py-3 text-sm">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <p className="leading-relaxed text-muted-foreground">
            <span className="font-medium text-foreground">Regular Holidays</span> grant qualified unworked pay to
            employees inside Holiday Coverage. <span className="font-medium text-foreground">Special Non-Working</span>{' '}
            defaults to No Work, No Pay — turn on the switch below to pay unworked specials the same way (100% daily
            rate when qualified). Worked premium rates live in the Multipliers tab.
          </p>
        </div>

        <PolicyStepSection number="1." title="Holiday Coverage" description="Unworked pay eligibility">
          <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
            <div className="space-y-2">
              <p className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Coverage mode</p>
              <RadioGroup
                value={unworkedCoverageValue}
                onValueChange={(value) =>
                  setBoth([
                    [['regular_unworked', 'coverage_behaviour'], value],
                    [['special_unworked', 'coverage_behaviour'], value],
                  ])
                }
                className="grid gap-2"
              >
                <RadioTile
                  idPrefix="holiday-coverage-mode"
                  value="respect_coverage"
                  title="Follow Holiday Coverage"
                  description="Pay unworked holiday based on the Holiday Coverage scope."
                />
                <RadioTile
                  idPrefix="holiday-coverage-mode"
                  value="ignore_coverage"
                  title="Ignore Coverage (Pay outside scope)"
                  description="Pay included holidays outside Holiday Coverage (unworked and worked)."
                />
              </RadioGroup>
            </div>

            <div className="space-y-2">
              <p className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                Included Regular Holidays
              </p>
              <RadioGroup value={regularIncludedMode} onValueChange={setRegularIncludedMode} className="grid gap-2">
                <RadioTile idPrefix="included-regular-holidays" value="all_regular" title="All Regular Holidays" />
                <RadioTile
                  idPrefix="included-regular-holidays"
                  value="selected"
                  title="Selected Regular Holidays"
                  badge={`${selectedRegularHolidayCount} ${selectedRegularHolidayCount === 1 ? 'holiday' : 'holidays'} selected`}
                  trailing={<ChevronRight className="size-4" />}
                />
              </RadioGroup>
            </div>
          </div>

          {regularIncludedMode === 'selected' ? (
            <div className="mt-4">
              <HolidayChecklist
                idPrefix="regular-holiday"
                title="Regular holidays"
                holidays={regularHolidays}
                selected={holidayPolicy.regular_unworked.holiday_ids}
                loading={holidaysLoading}
                onChange={(value) => onPolicyChange(['regular_unworked', 'holiday_ids'], value)}
              />
            </div>
          ) : null}
        </PolicyStepSection>

        <PolicyStepSection
          number="2."
          title="Special Non-Working — Unworked pay"
          description="DOLE default is No Work, No Pay. Enable to pay the ordinary daily rate when the employee is qualified and did not work."
        >
          <div
            className={cn(
              'flex flex-col gap-3 rounded-lg border px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
              specialUnworkedEnabled
                ? 'border-orange-300/80 bg-orange-50/60 dark:border-orange-800/60 dark:bg-orange-950/20'
                : 'border-border/70 bg-background',
            )}
          >
            <div className="min-w-0 space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-semibold text-foreground">Pay unworked Special Non-Working Holidays</p>
                <Badge
                  variant="secondary"
                  className={cn(
                    'rounded-full px-2 py-0.5 text-[10px] font-semibold',
                    specialUnworkedEnabled
                      ? 'bg-orange-100 text-orange-800 dark:bg-orange-950/50 dark:text-orange-200'
                      : 'text-muted-foreground',
                  )}
                >
                  {specialUnworkedEnabled ? 'Enabled' : 'Disabled'}
                </Badge>
              </div>
              <p className="text-xs leading-relaxed text-muted-foreground">
                {specialUnworkedEnabled
                  ? 'Qualified employees receive 100% daily rate when they do not work the special holiday (same as regular unworked pay).'
                  : 'No Work, No Pay — unworked special non-working holidays are unpaid unless the employee works (130% if worked).'}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <span className="text-xs font-medium text-muted-foreground">
                {specialUnworkedEnabled ? 'On' : 'Off'}
              </span>
              <Switch
                id="special-unworked-pay-toggle"
                checked={specialUnworkedEnabled}
                onCheckedChange={setSpecialUnworkedEnabled}
                className="data-[state=checked]:bg-orange-600"
                aria-label="Enable unworked pay for special non-working holidays"
              />
            </div>
          </div>

          {specialUnworkedEnabled ? (
            <div className="mt-4 space-y-3">
              <RadioGroup value={specialIncludedMode} onValueChange={setSpecialIncludedMode} className="grid gap-2 sm:grid-cols-2">
                <RadioTile
                  idPrefix="included-special-holidays"
                  value="all_special"
                  title="All Special Non-Working Holidays"
                />
                <RadioTile
                  idPrefix="included-special-holidays"
                  value="selected"
                  title="Selected Special Holidays"
                  badge={`${selectedSpecialHolidayCount} ${selectedSpecialHolidayCount === 1 ? 'holiday' : 'holidays'} selected`}
                  trailing={<ChevronRight className="size-4" />}
                />
              </RadioGroup>
              {specialIncludedMode === 'selected' ? (
                <HolidayChecklist
                  idPrefix="special-holiday"
                  title="Special non-working holidays"
                  holidays={specialHolidays}
                  selected={holidayPolicy.special_unworked.holiday_ids}
                  loading={holidaysLoading}
                  onChange={(value) => onPolicyChange(['special_unworked', 'holiday_ids'], value)}
                />
              ) : null}
            </div>
          ) : null}
        </PolicyStepSection>

        <PolicyStepSection number="3." title="Unworked pay rules">
          <div className="grid gap-5 lg:grid-cols-[1fr_0.9fr]">
            <div className="space-y-4">
              <PolicySelect
                id="regular-unworked-policy"
                label="Regular holiday pay policy"
                value={holidayPolicy.regular_unworked.holiday_selection_mode}
                options={REGULAR_UNWORKED_OPTIONS}
                onChange={(value) => onPolicyChange(['regular_unworked', 'holiday_selection_mode'], value)}
              />

              <div className="space-y-3 border-t border-border/60 pt-4">
                <EmploymentRuleGroup
                  idPrefix="regular-unworked-employment-type"
                  title="Regular working holiday — employment types"
                  mode={holidayPolicy.regular_unworked.employment_type_mode}
                  selected={holidayPolicy.regular_unworked.eligible_employment_types}
                  options={employmentTypes}
                  loading={employmentTypesLoading}
                  onModeChange={(value) => onPolicyChange(['regular_unworked', 'employment_type_mode'], value)}
                  onTypesChange={(value) => onPolicyChange(['regular_unworked', 'eligible_employment_types'], value)}
                />
                {specialUnworkedEnabled ? (
                  <EmploymentRuleGroup
                    idPrefix="special-unworked-employment-type"
                    title="Special non-working holiday — employment types"
                    mode={holidayPolicy.special_unworked.employment_type_mode}
                    selected={holidayPolicy.special_unworked.eligible_employment_types}
                    options={employmentTypes}
                    loading={employmentTypesLoading}
                    onModeChange={(value) => onPolicyChange(['special_unworked', 'employment_type_mode'], value)}
                    onTypesChange={(value) => onPolicyChange(['special_unworked', 'eligible_employment_types'], value)}
                  />
                ) : null}
              </div>

              <CoverageBehaviourCards
                idPrefix="unworked-coverage-behaviour"
                value={unworkedCoverageValue}
                onChange={(value) =>
                  setBoth([
                    [['regular_unworked', 'coverage_behaviour'], value],
                    [['special_unworked', 'coverage_behaviour'], value],
                  ])
                }
              />
            </div>
            <HowItWorksPanel />
          </div>
        </PolicyStepSection>

        <PolicyStepSection number="4." title="Worked pay" description="On the actual holiday">
          <p className="mb-4 text-xs leading-relaxed text-muted-foreground">
            DOLE double pay: regular pay (100%) plus holiday worked premium. Multipliers are set under
            Multipliers - Regular Holiday.
          </p>
          <div className="grid gap-5 lg:grid-cols-[1fr_0.95fr]">
            <div className="space-y-3">
              <PolicySelect
                id="worked-employment-rule"
                label="Employment type rule"
                value={workedEmploymentValue}
                options={WORKED_EMPLOYMENT_TYPE_OPTIONS}
                onChange={(value) =>
                  setBoth([
                    [['regular_worked', 'employment_type_rule'], value],
                    [['special_worked', 'employment_type_rule'], value],
                  ])
                }
              />
              {workedEmploymentValue === 'selected_employment_types' ? (
                <EmploymentTypeSelector
                  idPrefix="worked-employment-type"
                  options={employmentTypes}
                  selected={selectedWorkedEmploymentTypes}
                  loading={employmentTypesLoading}
                  onChange={setWorkedEmploymentTypes}
                />
              ) : null}
              <CoverageBehaviourCards
                idPrefix="worked-coverage-behaviour"
                value={workedCoverageValue}
                onChange={(value) =>
                  setBoth([
                    [['regular_worked', 'coverage_behaviour'], value],
                    [['special_worked', 'coverage_behaviour'], value],
                  ])
                }
              />
            </div>
            <PayComponentsPanel />
          </div>
        </PolicyStepSection>

        <section className="rounded-lg border border-border/70 bg-card p-4 shadow-sm">
          <div>
            <h4 className="text-sm font-semibold text-foreground">Unworked holiday - attendance rules</h4>
            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
              Applied when evaluating unworked regular holiday pay, and special non-working unworked pay when enabled.
            </p>
          </div>
          <div className="mt-4 grid gap-3 lg:grid-cols-3">
            <ToggleRow
              id="previous-workday-required"
              checked={holidayPolicy.attendance.require_previous_workday_presence !== false}
              onCheckedChange={(checked) =>
                onPolicyChange(['attendance', 'require_previous_workday_presence'], Boolean(checked))
              }
              label="Require attendance on the preceding workday"
              hint="Must be present or on paid leave on the last workday before the holiday."
            />
            <ToggleRow
              id="following-workday-required"
              checked={holidayPolicy.attendance.require_following_workday_presence === true}
              onCheckedChange={(checked) =>
                onPolicyChange(['attendance', 'require_following_workday_presence'], Boolean(checked))
              }
              label="Require attendance on the following workday"
              hint="Must be present or on paid leave on the first workday after the holiday."
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

        <div className="grid gap-3 border-t border-border/50 pt-4 sm:grid-cols-3">
          <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2.5">
            <ShieldCheck className="size-4 text-orange-600" />
            <span className="text-xs font-medium text-muted-foreground">Coverage follows Holiday Module scope</span>
          </div>
          <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2.5">
            <CalendarDays className="size-4 text-orange-600" />
            <span className="text-xs font-medium text-muted-foreground">Calendar and attendance remain authoritative</span>
          </div>
          <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2.5">
            <CircleDollarSign className="size-4 text-orange-600" />
            <span className="text-xs font-medium text-muted-foreground">Multipliers define worked holiday rates</span>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
