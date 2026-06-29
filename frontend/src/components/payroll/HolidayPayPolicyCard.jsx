import React, { useState } from 'react'
import { AlertTriangle, Building2, ChevronDown, ChevronRight, Info, Scale } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import { normalizeHolidayPayPolicy } from '@/lib/holidayPayPolicy'

const RATE_ROWS = [
  { code: 'RH', label: 'Worked on Regular Holiday', minimum: 2, otMinimum: 2.6 },
  { code: 'RHRD', label: 'Regular Holiday + Rest Day', minimum: 2.6, otMinimum: 3.38 },
  { code: 'SH', label: 'Worked on Special Holiday', minimum: 1.3, otMinimum: 1.69 },
  { code: 'SHRD', label: 'Special Holiday + Rest Day', minimum: 1.5, otMinimum: 1.95 },
]

function ToggleRow({ id, checked, onCheckedChange, disabled = false, label, hint }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-border/45 bg-background/70 p-3">
      <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={onCheckedChange} className="mt-0.5" />
      <div className="min-w-0 space-y-0.5">
        <Label htmlFor={id} className={cn('text-sm font-medium leading-snug', disabled && 'cursor-default')}>
          {label}
        </Label>
        {hint && <p className="text-xs leading-relaxed text-muted-foreground">{hint}</p>}
      </div>
    </div>
  )
}

function PolicySection({ title, description, children, defaultOpen = true }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <section className="overflow-hidden rounded-2xl border border-border/50 bg-muted/15">
      <button type="button" onClick={() => setOpen((value) => !value)} className="flex w-full items-start justify-between gap-4 px-4 py-4 text-left">
        <div>
          <h4 className="font-semibold tracking-tight">{title}</h4>
          {description && <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{description}</p>}
        </div>
        {open ? <ChevronDown className="mt-0.5 size-4 shrink-0" /> : <ChevronRight className="mt-0.5 size-4 shrink-0" />}
      </button>
      {open && <div className="space-y-3 border-t border-border/40 p-4">{children}</div>}
    </section>
  )
}

function PercentInput({ id, value, minimum, onChange }) {
  const numeric = Number(value) || 0
  const invalid = numeric + 0.00001 < minimum
  return (
    <div className="space-y-1.5">
      <div className="relative">
        <Input
          id={id}
          type="number"
          min={minimum * 100}
          step="1"
          value={Number.isFinite(numeric) ? Math.round(numeric * 10000) / 100 : ''}
          onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value) / 100)}
          className={cn('pr-9 tabular-nums', invalid && 'border-destructive focus-visible:ring-destructive')}
        />
        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
      </div>
      <p className={cn('text-[11px]', invalid ? 'text-destructive' : 'text-muted-foreground')}>
        DOLE minimum: {(minimum * 100).toFixed(0)}%
      </p>
    </div>
  )
}

function RateCards({ rows, multipliers, onMultiplierChange }) {
  const rowFor = (code) => multipliers.find((row) => row.condition_key === code) || {}
  return (
    <div className="grid gap-3 @md:grid-cols-2">
      {rows.map(({ code, label, minimum, otMinimum }) => {
        const row = rowFor(code)
        return (
          <div key={code} className="rounded-xl border border-border/45 bg-background/70 p-3">
            <p className="mb-3 text-sm font-medium">{label}</p>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label className="mb-1.5 block text-xs">First 8 hours</Label>
                <PercentInput id={`${code}-first8`} value={row.first8_multiplier} minimum={minimum} onChange={(value) => onMultiplierChange(code, 'first8_multiplier', value)} />
              </div>
              <div>
                <Label className="mb-1.5 block text-xs">With OT</Label>
                <PercentInput id={`${code}-ot`} value={row.ot_multiplier} minimum={otMinimum} onChange={(value) => onMultiplierChange(code, 'ot_multiplier', value)} />
              </div>
            </div>
          </div>
        )
      })}
    </div>
  )
}

export function HolidayPayPolicyCard({ policy, multipliers, companyId, onPolicyChange, onMultiplierChange }) {
  const [open, setOpen] = useState(true)
  const holidayPolicy = normalizeHolidayPayPolicy(policy)
  const rowFor = (code) => multipliers.find((row) => row.condition_key === code) || {}
  const belowMinimum = RATE_ROWS.some(({ code, minimum, otMinimum }) => {
    const row = rowFor(code)
    return Number(row.first8_multiplier) < minimum || Number(row.ot_multiplier) < otMinimum || Number(row.nd_addon_multiplier) < 0.1
  })
  const coverageItems = [
    ['rank_and_file', 'Rank-and-file Employees'],
    ['probationary', 'Probationary Employees'],
    ['regular', 'Regular Employees'],
    ['managerial', 'Managerial Employees'],
    ['consultants', 'Consultants'],
    ['contractual', 'Contractual Employees'],
    ['fixed_term', 'Fixed-term Employees'],
  ]

  return (
    <Card className="overflow-hidden border-0 bg-card shadow-sm">
      <CardHeader className="border-b border-border/40 bg-muted/20 p-0">
        <button type="button" onClick={() => setOpen((value) => !value)} className="flex w-full items-start justify-between gap-4 px-4 py-5 text-left @sm:px-6">
          <div className="flex min-w-0 items-start gap-3">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 text-rose-700 ring-1 ring-rose-500/15 dark:text-rose-300">
              <Scale className="size-5" />
            </div>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <CardTitle className="text-lg">Holiday Pay Policy (DOLE)</CardTitle>
                <Badge variant="outline" className="gap-1"><Building2 className="size-3" />{companyId ? 'Company Override' : 'Global Default'}</Badge>
              </div>
              <CardDescription className="mt-1.5 leading-relaxed">Entitlement, attendance qualification, coverage, and the existing holiday multiplier rows used across payroll.</CardDescription>
            </div>
          </div>
          {open ? <ChevronDown className="mt-1 size-5 shrink-0" /> : <ChevronRight className="mt-1 size-5 shrink-0" />}
        </button>
      </CardHeader>

      {open && (
        <CardContent className="space-y-4 p-4 @sm:p-6">
          <div className="rounded-2xl border border-blue-500/20 bg-blue-500/[0.06] p-4 text-sm leading-relaxed">
            <div className="flex items-start gap-3"><Info className="mt-0.5 size-4 shrink-0 text-blue-600 dark:text-blue-400" /><div><p className="font-semibold">Legal basis</p><p className="mt-1 text-muted-foreground">Labor Code Article 94, Article 93 (Premium Pay), and the DOLE Workers&apos; Statutory Monetary Benefits Handbook.</p></div></div>
          </div>

          {belowMinimum && <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/[0.06] p-3 text-sm text-destructive"><AlertTriangle className="mt-0.5 size-4 shrink-0" /><span>This configuration is below the minimum standard required by DOLE. It cannot be saved.</span></div>}

          <PolicySection title="Regular Holiday" description="Mandatory unworked pay plus worked, rest-day, OT, and ND premiums.">
            <ToggleRow id="pay-unworked-regular" checked disabled label="Pay unworked regular holidays — 100%" hint="Mandatory. Holiday pay cannot be replaced with leave credits." />
            <RateCards rows={RATE_ROWS.filter((row) => row.code.startsWith('RH'))} multipliers={multipliers} onMultiplierChange={onMultiplierChange} />
            <p className="text-xs text-muted-foreground">Night work adds at least 10% of the applicable holiday rate.</p>
          </PolicySection>

          <PolicySection title="Special Non-Working Holiday" description="No work, no pay by default; companies may grant a more favorable benefit.">
            <ToggleRow id="special-no-work" checked={!holidayPolicy.pay_unworked_special} onCheckedChange={(checked) => onPolicyChange(['pay_unworked_special'], !checked)} label="No Work, No Pay" />
            <ToggleRow id="pay-unworked-special" checked={holidayPolicy.pay_unworked_special} onCheckedChange={(checked) => onPolicyChange(['pay_unworked_special'], Boolean(checked))} label="Pay employees even when they do not work on a Special Holiday" />
            {holidayPolicy.pay_unworked_special && (
              <div className="max-w-xs space-y-3 rounded-xl border border-border/45 bg-background/70 p-3">
                <div className="space-y-1.5">
                  <Label htmlFor="special-unworked-preset">Company benefit preset</Label>
                  <select
                    id="special-unworked-preset"
                    value={[1, 1.3].includes(Number(holidayPolicy.unworked_special_multiplier)) ? String(holidayPolicy.unworked_special_multiplier) : 'custom'}
                    onChange={(event) => {
                      if (event.target.value !== 'custom') onPolicyChange(['unworked_special_multiplier'], Number(event.target.value))
                    }}
                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                  >
                    <option value="1">100%</option>
                    <option value="1.3">130%</option>
                    <option value="custom">Custom (at least 100%)</option>
                  </select>
                </div>
                <div><Label htmlFor="special-unworked-rate" className="mb-1.5 block">Unworked special holiday rate</Label><PercentInput id="special-unworked-rate" value={holidayPolicy.unworked_special_multiplier} minimum={1} onChange={(value) => onPolicyChange(['unworked_special_multiplier'], value)} /></div>
              </div>
            )}
            <RateCards rows={RATE_ROWS.filter((row) => row.code.startsWith('SH'))} multipliers={multipliers} onMultiplierChange={onMultiplierChange} />
          </PolicySection>

          <PolicySection title="Attendance Qualification" description="How the immediately preceding working day is evaluated for unworked regular holiday pay.">
            {[
              ['require_previous_workday_presence', 'Employee must be present on the working day immediately before the Regular Holiday'],
              ['paid_leave_qualifies', 'Approved Paid Leave before the Regular Holiday qualifies as present'],
              ['skip_rest_days', 'If the previous day is a Rest Day, evaluate the previous working day'],
              ['skip_company_non_working_days', 'If the previous day is a Company Non-working Day, evaluate the previous working day'],
              ['unpaid_absence_disqualifies', 'Employee absent without pay before the Regular Holiday is not entitled to unworked holiday pay'],
            ].map(([key, label]) => <ToggleRow key={key} id={`attendance-${key}`} checked={holidayPolicy.attendance[key]} disabled={['paid_leave_qualifies', 'skip_rest_days', 'skip_company_non_working_days'].includes(key)} onCheckedChange={(checked) => onPolicyChange(['attendance', key], Boolean(checked))} label={label} />)}
            <p className="rounded-xl bg-muted/50 p-3 text-xs leading-relaxed text-muted-foreground">If the employee works on the holiday itself, holiday pay is still computed according to DOLE.</p>
          </PolicySection>

          <PolicySection title="Successive Regular Holidays" description="The qualifying condition before the first holiday carries through consecutive regular holidays.">
            <ToggleRow id="successive-holidays" checked disabled label="Apply DOLE Successive Holiday Rules" hint="Present or approved paid leave before the first holiday qualifies the chain; work on the first holiday restores eligibility for the next." />
          </PolicySection>

          <PolicySection title="Holiday Pay Coverage" description="Select optional employment categories covered by this policy.">
            <div className="grid gap-2 @md:grid-cols-2">{coverageItems.map(([key, label]) => <ToggleRow key={key} id={`coverage-${key}`} checked={holidayPolicy.coverage[key]} disabled={['rank_and_file', 'probationary', 'regular'].includes(key)} onCheckedChange={(checked) => onPolicyChange(['coverage', key], Boolean(checked))} label={label} />)}</div>
            <div className="space-y-2 rounded-xl border border-border/45 bg-muted/30 p-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Statutory exemptions — read-only</p>
              {[
                ['government', 'Government Employees'],
                ['field_personnel', 'Field Personnel'],
                ['micro_retail_service', 'Micro-Retail/Service Establishments (<10 employees)'],
              ].map(([key, label]) => <ToggleRow key={key} id={`excluded-${key}`} checked disabled label={`Excluded: ${label}`} />)}
              <p className="text-xs leading-relaxed text-muted-foreground">These exemptions follow DOLE guidelines and are displayed for reference.</p>
            </div>
          </PolicySection>

          <PolicySection title="Company Holiday Benefits" description="More favorable company rates use the same payroll multiplier matrix above.">
            <div className="grid gap-2 @md:grid-cols-2">
              <ToggleRow id="override-special-unworked" checked={holidayPolicy.pay_unworked_special} disabled label="Pay Special Holidays even when not worked" />
              <ToggleRow id="override-regular" checked={Number(rowFor('RH').first8_multiplier) > 2} disabled label="Increase Regular Holiday Multiplier" />
              <ToggleRow id="override-special" checked={Number(rowFor('SH').first8_multiplier) > 1.3} disabled label="Increase Special Holiday Multiplier" />
              <ToggleRow id="override-rest" checked={Number(rowFor('RHRD').first8_multiplier) > 2.6 || Number(rowFor('SHRD').first8_multiplier) > 1.5} disabled label="Increase Rest Day Holiday Multiplier" />
            </div>
            <p className="text-xs leading-relaxed text-muted-foreground">Override indicators turn on automatically when values above the DOLE floor are saved. Attendance, Holidays, Payroll, Leave, Overtime, dashboards, and reports resolve this policy by effective date and company scope.</p>
          </PolicySection>
        </CardContent>
      )}
    </Card>
  )
}
