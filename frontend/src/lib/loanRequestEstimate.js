/**
 * Shared loan request preview math (employee self-service + admin planning modal).
 * Interest simulation mirrors backend {@see LoanAmortizationService::interestForInstallment} (per pay-run factor).
 */

export const EMPTY_REQUEST_LOAN_FORM = {
  product_key: '',
  requested_amount: '',
  preferred_monthly_deduction: '',
  term_months: '',
  deduction_schedule: 'both',
  reason: '',
}

export const PRINCIPAL_PRESETS = [10000, 25000, 50000, 100000, 250000]

const TEXT = '#0A0A0A'

export { TEXT }

function round2(n) {
  return Math.round(Number(n) * 100) / 100
}

/**
 * Interest for one payroll application (factor 0.5 = half of a semi-monthly month, 1 = full monthly installment).
 */
export function interestForInstallment(annualRatePercent, interestType, principalTotal, remainingBalance, factor) {
  const r = Number(annualRatePercent)
  const f = Number(factor)
  if (!Number.isFinite(r) || r <= 0 || !Number.isFinite(f) || f <= 0) {
    return 0
  }
  const monthlyRate = r / 100 / 12
  const base = interestType === 'compound' ? remainingBalance : principalTotal
  if (!Number.isFinite(base) || base <= 0 || monthlyRate <= 0) {
    return 0
  }
  return round2(base * monthlyRate * f)
}

/**
 * @param {number} principal
 * @param {number} monthlyPayment full-month installment (same as backend employee_deduction.amount intent)
 * @param {'15th'|'30th'|'both'} sched
 * @param {number} annualRatePercent
 * @param {'simple'|'compound'} interestType
 * @param {number} maxMonths safety cap (calendar months of semi-monthly pairs)
 */
export function simulateLoanInterestTotals(principal, monthlyPayment, sched, annualRatePercent, interestType, maxMonths) {
  let balance = round2(principal)
  const p0 = round2(principal)
  let totalInterest = 0
  let totalInstallmentsPaid = 0
  let deductionCount = 0
  const M = Number(monthlyPayment)
  if (!Number.isFinite(M) || M <= 0 || balance <= 0) {
    return {
      totalInterest: 0,
      totalRepayment: principal,
      totalInstallmentsPaid: 0,
      deductionCount: 0,
      remainingBalance: balance,
      fullyRepaid: balance <= 0.009,
      simulatedMonths: 0,
    }
  }

  const factorsPerMonth = sched === 'both' ? [0.5, 0.5] : [1.0]
  let months = 0
  const capMonths = Math.max(1, Math.min(600, Math.floor(maxMonths || 600)))

  while (balance > 0.009 && months < capMonths) {
    for (const factor of factorsPerMonth) {
      if (balance <= 0.009) break
      let interestThis = interestForInstallment(annualRatePercent, interestType, p0, balance, factor)
      const cap = round2(M * factor)
      let principalThis = round2(Math.min(cap - interestThis, balance))
      if (principalThis < 0) {
        principalThis = 0
      }
      interestThis = round2(Math.min(interestThis, Math.max(0, cap - principalThis)))
      const total = round2(principalThis + interestThis)
      if (total <= 0) {
        continue
      }
      deductionCount += 1
      totalInterest = round2(totalInterest + interestThis)
      totalInstallmentsPaid = round2(totalInstallmentsPaid + total)
      balance = round2(balance - principalThis)
    }
    months += 1
  }

  const fullyRepaid = balance <= 0.009
  const totalRepayment = fullyRepaid ? round2(p0 + totalInterest) : round2(totalInstallmentsPaid)

  return {
    totalInterest: round2(totalInterest),
    totalRepayment,
    totalInstallmentsPaid: round2(totalInstallmentsPaid),
    deductionCount,
    remainingBalance: round2(balance),
    fullyRepaid,
    simulatedMonths: months,
  }
}

/**
 * Normalize API deduction type row for interest preview.
 * @param {Record<string, unknown>|null|undefined} t
 */
export function normalizeInterestProfile(t) {
  if (!t || typeof t !== 'object') {
    return { with_interest: false, interest_rate_percent: 0, interest_type: 'simple' }
  }
  const wi = t.with_interest === true || t.with_interest === 1 || t.with_interest === '1'
  const rate = Number(t.interest_rate_percent)
  const typ = t.interest_type === 'compound' ? 'compound' : 'simple'
  return {
    with_interest: wi,
    interest_rate_percent: Number.isFinite(rate) ? rate : 0,
    interest_type: typ,
  }
}

/**
 * @param {string} productKey
 * @param {{ loan_types?: Array<Record<string, unknown>> }} ctx
 */
export function resolveInterestProfileFromProductKey(productKey, ctx) {
  const [kind, idStr] = String(productKey || '').split(':')
  if (!kind || !idStr) return normalizeInterestProfile(null)
  const types = ctx?.loan_types || []
  if (kind === 'dt') {
    const row = types.find((x) => String(x.id) === String(idStr))
    return normalizeInterestProfile(row)
  }
  if (kind === 'pc') {
    const row = types.find((x) => String(x.pay_component_id) === String(idStr))
    return normalizeInterestProfile(row)
  }
  return normalizeInterestProfile(null)
}

/**
 * @param {typeof EMPTY_REQUEST_LOAN_FORM} form
 * @param {number} principalSlider
 * @param {ReturnType<typeof normalizeInterestProfile>|null} [interestProfile]
 */
export function computeLoanEstimatePreview(form, principalSlider, interestProfile = null) {
  const rawPrincipal =
    form.requested_amount !== '' && form.requested_amount != null ? Number(form.requested_amount) : Number(principalSlider)
  const principal = Number.isFinite(rawPrincipal) && rawPrincipal > 0 ? rawPrincipal : null

  const termStr = form.term_months
  const term = termStr === '' || termStr == null ? NaN : Number(termStr)

  const prefStr = form.preferred_monthly_deduction
  const pref = prefStr === '' || prefStr == null ? NaN : Number(prefStr)

  const hasPreferred = Number.isFinite(pref) && pref > 0
  const hasTerm = Number.isFinite(term) && term > 0

  let monthlyImpact = null
  let preferredMonthlyInput = hasPreferred ? pref : null
  let adjustedMonthlyImpact = null
  let adjustedReason = null

  if (principal != null && hasTerm) {
    const requiredMonthly = principal / term
    monthlyImpact = requiredMonthly
    adjustedMonthlyImpact = requiredMonthly
    if (hasPreferred) {
      const projected = pref * term
      if (projected < principal) {
        adjustedReason = 'increased_to_match_term'
      } else if (projected > principal) {
        adjustedReason = 'reduced_to_match_term'
      }
    }
  } else if (hasPreferred) {
    monthlyImpact = pref
  }

  const sched = form.deduction_schedule || 'both'
  let per15 = null
  let per30 = null
  if (monthlyImpact != null && monthlyImpact > 0) {
    if (sched === 'both') {
      per15 = monthlyImpact / 2
      per30 = monthlyImpact / 2
    } else if (sched === '15th') {
      per15 = monthlyImpact
      per30 = 0
    } else {
      per15 = 0
      per30 = monthlyImpact
    }
  }

  let monthsForLoan = null
  if (hasTerm) {
    monthsForLoan = term
  } else if (principal != null && monthlyImpact != null && monthlyImpact > 0) {
    monthsForLoan = Math.max(1, Math.ceil(principal / monthlyImpact))
  }

  const profile = interestProfile || normalizeInterestProfile(null)
  const ratePct = profile.with_interest && profile.interest_rate_percent > 0 ? profile.interest_rate_percent : 0
  const interestType = profile.interest_type === 'compound' ? 'compound' : 'simple'

  let deductionCount = null
  let totalRepayment = principal != null ? principal : null
  let totalInterest = null
  let interestRepaymentNote = null
  let interestShortfall = false
  let rateDisplayPercent = ratePct > 0 ? ratePct : null
  let principalRemainingAfterWindow = null
  let interestSimulatedMonths = null

  const canSimulateInterest =
    principal != null && ratePct > 0 && monthlyImpact != null && Number.isFinite(monthlyImpact) && monthlyImpact > 0

  if (canSimulateInterest) {
    const maxM = hasTerm ? term + 36 : (monthsForLoan != null ? monthsForLoan + 36 : 120)
    const sim = simulateLoanInterestTotals(principal, monthlyImpact, sched, ratePct, interestType, maxM)
    totalInterest = sim.totalInterest
    totalRepayment = sim.totalRepayment
    deductionCount = sim.deductionCount
    interestShortfall = !sim.fullyRepaid
    interestSimulatedMonths = sim.simulatedMonths
    if (interestShortfall) {
      principalRemainingAfterWindow = sim.remainingBalance
    }
    const rateLabel = Number.isInteger(ratePct) ? String(ratePct) : String(round2(ratePct))
    interestRepaymentNote = `₱${formatEstimatePhp(sim.totalInterest)} interest at ${rateLabel}% rate`
  } else {
    if (monthsForLoan != null) {
      deductionCount = sched === 'both' ? monthsForLoan * 2 : monthsForLoan
    }
    if (ratePct > 0 && principal != null) {
      const rateLabel = Number.isInteger(ratePct) ? String(ratePct) : String(round2(ratePct))
      interestRepaymentNote = `Enter term or preferred monthly to estimate interest (${rateLabel}% p.a., ${interestType})`
    }
  }

  return {
    monthlyImpact,
    preferredMonthlyInput,
    adjustedMonthlyImpact,
    adjustedReason,
    isAdjustedToTerm: adjustedReason != null,
    totalRepayment,
    totalInterest,
    interestRepaymentNote,
    interestShortfall,
    rateDisplayPercent,
    interestType,
    hasInterestRate: ratePct > 0,
    principalRemainingAfterWindow,
    interestSimulatedMonths,
    per15,
    per30,
    deductionCount,
    sched,
    monthsForLoan,
  }
}

function formatEstimatePhp(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  return v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/**
 * Active deductions table — matches backend {@see DeductionScheduleSetting} values.
 */
export function scheduleTypeFriendlyLabel(schedule) {
  const s = String(schedule || '').trim()
  if (s === '15th') return 'First semi-monthly run'
  if (s === '30th') return 'End of month'
  if (s === 'both') return '50/50 split'
  return '—'
}

/**
 * @param {string} productKey `pc:id` or `dt:id`
 * @param {{ loan_types?: Array<{ id: number, pay_component_id?: number|null }> }} ctx
 */
export function resolveManualAssignIdsFromProductKey(productKey, ctx) {
  const [kind, idStr] = String(productKey || '').split(':')
  if (!kind || !idStr) return { deduction_type_id: '', pay_component_id: '' }
  if (kind === 'dt') {
    return { deduction_type_id: idStr, pay_component_id: '' }
  }
  if (kind === 'pc') {
    const lt = (ctx?.loan_types || []).find((t) => String(t.pay_component_id) === String(idStr))
    if (lt) return { deduction_type_id: String(lt.id), pay_component_id: idStr }
    return { deduction_type_id: '', pay_component_id: idStr }
  }
  return { deduction_type_id: '', pay_component_id: '' }
}
