import React from 'react'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import {
  DEFAULT_ATTENDANCE_RULE,
  MINIMUM_CONDITION_OPTIONS,
  SUCCESSIVE_QUALIFICATION_OPTIONS,
} from '@/lib/holidayPayPolicy'

function StatusMultiSelect({ idPrefix, label, hint, options, selected, onChange }) {
  const toggle = (value) => {
    const next = new Set(selected)
    if (next.has(value)) next.delete(value)
    else next.add(value)
    onChange(Array.from(next))
  }

  return (
    <div className="space-y-2">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((option) => (
          <label
            key={option.value}
            htmlFor={`${idPrefix}-${option.value}`}
            className="flex cursor-pointer items-start gap-2 rounded-md border border-border/50 bg-background/80 px-2.5 py-2 text-sm"
          >
            <Checkbox
              id={`${idPrefix}-${option.value}`}
              checked={selected.includes(option.value)}
              onCheckedChange={() => toggle(option.value)}
              className="mt-0.5"
            />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
    </div>
  )
}

function LookupToggle({ id, label, checked, onCheckedChange }) {
  return (
    <label
      htmlFor={id}
      className="flex cursor-pointer items-center gap-2 rounded-md border border-border/40 px-2.5 py-2 text-sm"
    >
      <Checkbox id={id} checked={checked} onCheckedChange={onCheckedChange} />
      <span>{label}</span>
    </label>
  )
}

export function HolidayPayRuleBuilder({
  idPrefix,
  rule,
  statusCatalog,
  onRuleChange,
  showSuccessive = false,
  successiveEnabled,
  successiveQualification,
  onSuccessiveChange,
}) {
  const qualifyingOptions = statusCatalog?.qualifying ?? []
  const disqualifyingOptions = statusCatalog?.disqualifying ?? []
  const minimumOptions = statusCatalog?.minimum_conditions?.length
    ? statusCatalog.minimum_conditions
    : MINIMUM_CONDITION_OPTIONS
  const successiveOptions = statusCatalog?.successive_qualifications?.length
    ? statusCatalog.successive_qualifications
    : SUCCESSIVE_QUALIFICATION_OPTIONS

  const lookup = { ...DEFAULT_ATTENDANCE_RULE.lookup, ...(rule?.lookup || {}) }
  const needsAdjacentLookup = !['none'].includes(rule?.minimum_condition ?? '')

  const patch = (partial) => onRuleChange({ ...DEFAULT_ATTENDANCE_RULE, ...rule, ...partial })

  return (
    <div className="space-y-4 rounded-lg border border-dashed border-border/60 bg-muted/10 p-4">
      <div className="space-y-1.5">
        <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Minimum required condition
        </Label>
        <Select
          value={rule?.minimum_condition ?? DEFAULT_ATTENDANCE_RULE.minimum_condition}
          onValueChange={(value) => patch({ minimum_condition: value })}
        >
          <SelectTrigger className="h-10 w-full bg-background">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {minimumOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <StatusMultiSelect
        idPrefix={`${idPrefix}-qualifying`}
        label="Qualifying attendance statuses"
        hint="Employee must match at least one on the evaluated day."
        options={qualifyingOptions}
        selected={rule?.qualifying_statuses ?? DEFAULT_ATTENDANCE_RULE.qualifying_statuses}
        onChange={(value) => patch({ qualifying_statuses: value })}
      />

      <StatusMultiSelect
        idPrefix={`${idPrefix}-disqualifying`}
        label="Disqualifying statuses"
        hint="Any match on the evaluated day fails qualification."
        options={disqualifyingOptions}
        selected={rule?.disqualifying_statuses ?? DEFAULT_ATTENDANCE_RULE.disqualifying_statuses}
        onChange={(value) => patch({ disqualifying_statuses: value })}
      />

      {needsAdjacentLookup && (
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Previous / next day lookup
          </p>
          <div className="grid gap-2 sm:grid-cols-2">
            <LookupToggle
              id={`${idPrefix}-skip-rest`}
              label="Skip rest days"
              checked={lookup.skip_rest_days !== false}
              onCheckedChange={(checked) => patch({ lookup: { ...lookup, skip_rest_days: Boolean(checked) } })}
            />
            <LookupToggle
              id={`${idPrefix}-skip-non-working`}
              label="Skip company non-working days"
              checked={lookup.skip_non_working_days !== false}
              onCheckedChange={(checked) =>
                patch({ lookup: { ...lookup, skip_non_working_days: Boolean(checked) } })
              }
            />
            <LookupToggle
              id={`${idPrefix}-skip-holidays`}
              label="Skip holidays"
              checked={lookup.skip_holidays !== false}
              onCheckedChange={(checked) => patch({ lookup: { ...lookup, skip_holidays: Boolean(checked) } })}
            />
            <LookupToggle
              id={`${idPrefix}-skip-paid-leave`}
              label="Skip approved leave days"
              checked={lookup.skip_paid_leave === true}
              onCheckedChange={(checked) => patch({ lookup: { ...lookup, skip_paid_leave: Boolean(checked) } })}
            />
          </div>
        </div>
      )}

      {showSuccessive && (
        <div className={cn('space-y-3 rounded-md border border-border/40 p-3')}>
          <label
            htmlFor={`${idPrefix}-successive-enabled`}
            className="flex cursor-pointer items-start gap-2"
          >
            <Checkbox
              id={`${idPrefix}-successive-enabled`}
              checked={successiveEnabled !== false}
              onCheckedChange={(checked) => onSuccessiveChange?.({ enabled: Boolean(checked) })}
              className="mt-0.5"
            />
            <div>
              <span className="text-sm font-medium">Enable successive holiday rule</span>
              <p className="text-xs text-muted-foreground">
                Back-to-back regular holidays share the first holiday&apos;s qualifying condition.
              </p>
            </div>
          </label>
          {successiveEnabled !== false && (
            <div className="space-y-1.5 pl-6">
              <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Successive qualification
              </Label>
              <Select
                value={successiveQualification ?? 'previous_working_day'}
                onValueChange={(value) => onSuccessiveChange?.({ qualification: value })}
              >
                <SelectTrigger className="h-9 w-full bg-background">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {successiveOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export function PolicyModeToggle({ idPrefix, value, onChange }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Policy</Label>
      <div role="radiogroup" className="grid gap-2 sm:grid-cols-2">
        {[
          { value: 'dole_default', label: 'Follow DOLE default', hint: 'Previous working day + paid leave qualifies.' },
          { value: 'custom', label: 'Custom company policy', hint: 'Configure attendance qualification rules below.' },
        ].map((option) => {
          const selected = (value ?? 'dole_default') === option.value
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
                  ? 'border-primary/40 bg-primary/5 ring-1 ring-primary/20'
                  : 'border-border/60 bg-background hover:bg-muted/40',
              )}
            >
              <span className="font-medium">{option.label}</span>
              <span className="mt-0.5 block text-xs text-muted-foreground">{option.hint}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
