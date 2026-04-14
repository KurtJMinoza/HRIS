/**
 * Policy & Rules Engine Manual — export text for Admin Daily Computation.
 * No currency symbols or sample money amounts (QA: policy-only multipliers and procedures).
 * Aligns with backend/docs/PAYROLL_RULES_ENGINE.md and config/payroll.php rules[].
 */

function escapeCsvCell(value) {
  const s = value == null ? '' : String(value)
  if (/[",\r\n]/.test(s)) {
    return `"${s.replace(/"/g, '""')}"`
  }
  return s
}

function row(section, ref, content) {
  return [escapeCsvCell(section), escapeCsvCell(ref), escapeCsvCell(content)].join(',')
}

/** @returns {string} */
export function buildPayrollPolicyManualCsv() {
  const lines = []
  lines.push(row('Meta', 'Title', 'Policy and Procedure Manual + Rules Engine Framework (OT, ND, Holiday, Rest Day)'))
  lines.push(row('Meta', 'Focus', 'Policy-driven rules and multipliers only — no payroll currency examples.'))
  lines.push(row('Meta', 'Generated', new Date().toISOString().slice(0, 10)))

  lines.push(row('Key confirmations', 'Labor Code', 'Ordinary OT: +25% (125% total). OT on holiday/rest/special day: +30% on the day rate (stacked per matrix). Rest day premium: +30% (130% total). Regular holiday worked: 200%. Regular holiday + rest day: 260%. Special non-working holiday worked: 130%. Special + rest day: 150%. ND: +10% on applicable hourly rate (including premium days). Night hours: 10:00 PM to 6:00 AM.'))

  lines.push(row('1', 'PURPOSE', `To establish a clear, standardized, and auditable policy and procedure for the computation, approval, recording, and entitlement rules of: Overtime Pay (OT); Night Differential (ND); Regular Holiday Pay; Special Non-Working Holiday Pay; Rest Day Premium Pay; and Combined Premiums (e.g. Rest Day + Holiday + OT + ND). This document is the company pay rules engine reference for payroll processing and timekeeping validation.`))

  lines.push(row('2', 'SCOPE', `Applies to all rank-and-file employees (daily or monthly paid) required to work beyond regular hours, during holidays, on rest days, or during night shift hours. Exclusions (unless approved otherwise): managerial employees, officers exempt under labor law, consultants or contractors not treated as employees.`))

  lines.push(row('3', 'LEGAL BASIS', `Philippine Labor Code (Articles 82–96, particularly 87, 93, 94); DOLE Omnibus Rules Implementing the Labor Code; DOLE advisories and handbooks on statutory monetary benefits; relevant holiday pay advisories. Always validate final implementation against the latest DOLE issuances, company CBA, and employment contracts.`))

  lines.push(row('4', 'POLICY STATEMENT', `The Company shall compensate eligible employees lawfully and fairly for: work beyond 8 hours per day; work between 10:00 PM and 6:00 AM; work on declared regular holidays; work on declared special non-working holidays; work on scheduled rest days; and combinations of the above. Entitlements follow approved time records, OT authorization, published holiday calendar, work schedule and rest day assignment, and rules engine logic.`))

  lines.push(row('5', 'DEFINITIONS — Regular hours', '', `Hours within the regular daily threshold (8 hours) and within the scheduled shift window, after applying meal breaks and attendance rules.`))
  lines.push(row('5', 'DEFINITIONS — Rendered OT', '', `Overtime minutes derived from verified time logs. Default engine basis: net work at or after scheduled shift end (schedule_end). Legacy basis eight_hour_net may apply only if explicitly configured.`))
  lines.push(row('5', 'DEFINITIONS — Approved OT', '', `Hours from approved overtime requests for the date, used for authorization and audit — not substituted for rendered time.`))
  lines.push(row('5', 'DEFINITIONS — ND', '', `Premium for work performed during the night window (default 10:00 PM–6:00 AM). Premium is +10% on the applicable hourly rate for those minutes (regular-segment ND and OT-segment ND per engine).`))
  lines.push(row('5', 'DEFINITIONS — Holidays / rest', '', `Regular holiday, special non-working holiday, double holiday (per calendar), and scheduled rest day — resolved from Admin Holidays + employee schedule.`))
  lines.push(row('5', 'DEFINITIONS — Rule code', '', `Engine label (e.g. ORD, RD, RH, RHRD, SH, SHRD, DH, DHRD) selecting first_8, ot, and nd_base multipliers.`))
  lines.push(row('5', 'DEFINITIONS — Applicable rate', '', `Hourly weighting reference (HWR) from the employee pay structure — used only as a multiplier vehicle in the engine; not illustrated with sample amounts in this export.`))

  lines.push(row('6', 'STANDARD PAY FACTORS — Table header', 'Condition', 'First 8h multiplier | OT beyond 8h multiplier | Code'))
  const factorRows = [
    ['Ordinary day', '1.00', '1.25', 'ORD'],
    ['Rest day', '1.30', '1.69', 'RD'],
    ['Regular holiday (worked)', '2.00', '2.60', 'RH'],
    ['Regular holiday + rest day (worked)', '2.60', '3.38', 'RHRD'],
    ['Special holiday (worked)', '1.30', '1.69', 'SH'],
    ['Special holiday + rest day (worked)', '1.50', '1.95', 'SHRD'],
    ['Double holiday (worked)', '3.00', '3.90', 'DH'],
    ['Double holiday + rest day (worked)', '3.00', '3.90', 'DHRD'],
  ]
  for (const [cond, f8, ot, code] of factorRows) {
    lines.push(row('6', cond, `${f8} | ${ot} | ${code}`))
  }

  lines.push(row('7.1', 'SEGMENTATION', `Net work minutes are split: regular within schedule; rendered OT at/after shift end when ot_basis is schedule_end; ND minutes in the night window are attributed to regular or OT segments per engine.`))
  lines.push(row('7.2', 'RULE RESOLUTION (summary)', '', `Holiday type from merged calendar (none → regular → special → double); rest day from schedule; rule code priority matches PayrollRulesEngineService (e.g. double+RD → DH/DHRD; regular+RD → RHRD/RH; special+RD → SHRD/SH; rest only → RD; else ORD).`))

  lines.push(row('8.1', 'FORMULA', `Regular segment weighted units = regular_hours × first_8_multiplier (for the resolved rule code).`))
  lines.push(row('8.2', 'FORMULA', `OT segment weighted units = ot_hours × ot_multiplier.`))
  lines.push(row('8.3', 'FORMULA', `ND premium on regular night minutes ∝ regular_night_hours × (HWR × first_8 × nd_base × nd_premium).`))
  lines.push(row('8.4', 'FORMULA', `ND premium on OT night minutes ∝ ot_night_hours × (HWR × ot × nd_premium) per engine configuration.`))
  lines.push(row('8.5', 'FORMULA', `Total day weighted units = sum of applicable segment units; conversion to pay uses company pay fields — not part of this policy export.`))

  lines.push(row('9.1', 'DECISION MATRIX — Rule code', 'Inputs', 'Holiday type + rest day + double flag → ORD | RD | RH | RHRD | SH | SHRD | DH | DHRD per engine order.'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'None, not RD, worked', '1.00'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'None, RD, worked', '1.30'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Regular holiday, not RD, not worked', '1.00 if entitled unworked'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Regular holiday, not RD, worked', '2.00'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Regular holiday, RD, worked', '2.60'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Special, not RD, not worked', '— (no pay unless policy/CBA)'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Special, not RD, worked', '1.30'))
  lines.push(row('9.2', 'DECISION MATRIX — First 8h (examples)', 'Special, RD, worked', '1.50'))

  lines.push(row('10.1', 'PROCEDURE — Timekeeping', `Record accurate in/out; align dates with attendance session rules; apply corrections through approved processes.`))
  lines.push(row('10.2', 'PROCEDURE — OT authorization', `File and approve OT per company workflow; rendered OT is compared to approved/pending for audit flags.`))
  lines.push(row('10.3', 'PROCEDURE — Holiday calendar', `Maintain published holidays; merge API and local DB per Admin Holidays policy.`))
  lines.push(row('10.4', 'PROCEDURE — Validation', `Supervisor and payroll review weighted units, flags, and policy snapshot for the day.`))

  lines.push(row('11', 'ELIGIBILITY', `Eligible rank-and-file employees per scope; exempt roles excluded unless policy states otherwise.`))
  lines.push(row('12', 'SPECIAL RULES / CONTROLS', `Config overrides in payroll_rules table replace defaults for a rule code when published. ND window and nd_premium may follow active pay policy snapshot.`))
  lines.push(row('13', 'ROLES & RESPONSIBILITIES', `Employees: accurate logs and timely OT requests. Supervisors: approve OT and validate exceptions. HR/Payroll: calendar, policy publication, and payroll run. Audit: sample checks on flags and multipliers.`))
  lines.push(row('14', 'INTERNAL CONTROLS', `Segregation of time capture, approval, and payroll posting; retention of attendance and OT evidence; periodic review of engine config vs Labor Code updates.`))
  lines.push(row('15', 'REQUIRED FORMS / SYSTEM RECORDS', `Attendance logs, OT requests, holiday calendar entries, and payroll daily records as implemented in the system.`))
  lines.push(row('16', 'VIOLATIONS / NON-COMPLIANCE', `Unapproved OT, falsified time, or bypassing approval workflows are subject to disciplinary process and payroll adjustment.`))
  lines.push(row('17', 'EFFECTIVITY & REVIEW', `Effective upon approval; reviewed at least annually or when labor rules or company policy changes.`))
  lines.push(row('18', 'APPROVAL', `Issued under HR and management authority; IT implements engine parameters per approved configuration.`))
  lines.push(row('19', 'SIMPLE RULES ENGINE TABLE', `See Section 6; JSON snapshot for integrations: GET /api/admin/payroll/policy-reference (authenticated admin).`))
  lines.push(row('20', 'IMPLEMENTATION NOTES', `Rendered OT vs approved OT: see PAYROLL_RULES_ENGINE.md. Double holiday: calendar type double. Company holidays may map to special per config.`))

  return ['Section', 'Ref', 'Content'].join(',') + '\r\n' + lines.join('\r\n')
}

/**
 * Triggers download of the policy manual as UTF-8 CSV (Excel-friendly BOM).
 */
export function downloadPayrollPolicyManualCsv() {
  const csv = buildPayrollPolicyManualCsv()
  const BOM = '\uFEFF'
  const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const stamp = new Date().toISOString().slice(0, 10)
  a.href = url
  a.download = `policy-rules-engine-manual-${stamp}.csv`
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
