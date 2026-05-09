export function formatSalaryTabPhp(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return '—'
  return `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export function formatSalaryTabDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatDeductionScheduleType(value) {
  const t = String(value || '').trim()
  if (t === '15th') return 'First semi-monthly run'
  if (t === '30th') return 'End of month'
  if (t === 'both') return '50/50 split'
  return t || '—'
}

export function formatDeductionScheduleTypeShort(value) {
  const t = String(value || '').trim()
  if (t === '15th') return 'First semi-monthly run'
  if (t === '30th') return 'End of month'
  if (t === 'both') return '50/50 split'
  return t || '—'
}

export function formatDefaultPayComponentScheduleLabel(settingValue) {
  const t = String(settingValue || '').trim()
  if (t === '15th') return '15th'
  if (t === '30th') return 'End of month'
  if (t === 'both') return 'Split 15/30'
  return formatDeductionScheduleTypeShort(settingValue)
}

export function formatEmployeeScheduleOverrideShort(override) {
  const o = String(override || '').trim()
  const map = {
    first_run: '15th',
    second_run: 'End of month',
    split: 'Split 15/30',
    monthly: 'Monthly / full run',
  }
  return map[o] || (o || null)
}

export function formatPayProfileComponentScheduleCell(item) {
  if (!item || typeof item !== 'object') return '—'
  const src = item.schedule_source
  if (src === 'employee_override') {
    const lbl = formatEmployeeScheduleOverrideShort(item.schedule_override)
    if (lbl) return lbl
    const r = item.resolved_schedule
    return r ? formatDefaultPayComponentScheduleLabel(r) : '—'
  }
  if (src === 'default_schedule') {
    const def = item.default_schedule
    const inner = def ? formatDefaultPayComponentScheduleLabel(def) : null
    return inner ? `Use default: ${inner}` : 'Use default'
  }
  const sched = item.resolved_schedule ?? item.pay_schedule_type
  return sched ? formatDefaultPayComponentScheduleLabel(sched) : '—'
}

function isGovernmentIdTypeTin(idType) {
  const t = String(idType || '').trim().toLowerCase()
  if (!t) return false
  if (/\btin\b/.test(t)) return true
  if (t.includes('tax identification')) return true
  if (t.includes('bir') && t.includes('tin')) return true
  return false
}

function isDocStatusApproved(status) {
  return String(status || '')
    .trim()
    .toLowerCase() === 'approved'
}

export function resolveTinForSalaryDisplay(govIdDocuments, profileTinNumber, opts = {}) {
  const loading = !!opts.loading
  const docs = Array.isArray(govIdDocuments) ? govIdDocuments : []

  const approved = docs.find((d) => isGovernmentIdTypeTin(d?.id_type) && isDocStatusApproved(d?.status))
  if (approved?.id_number && String(approved.id_number).trim()) {
    return {
      displayValue: String(approved.id_number).trim(),
      variant: 'approved_document',
      cardDescription: 'Verified Tax Identification Number from your Gov IDs.',
      tinHelperText: null,
      loading: false,
      showVerifiedBadge: true,
    }
  }

  const profileTin = profileTinNumber != null && String(profileTinNumber).trim() !== '' ? String(profileTinNumber).trim() : ''
  if (profileTin) {
    return {
      displayValue: profileTin,
      variant: 'profile_record',
      cardDescription: 'Withholding treatment and BIR identifiers from HR records.',
      tinHelperText: null,
      loading: false,
      showVerifiedBadge: false,
    }
  }

  if (loading) {
    return {
      displayValue: null,
      variant: 'loading',
      cardDescription: 'Loading government ID records…',
      tinHelperText: null,
      loading: true,
      showVerifiedBadge: false,
    }
  }

  const rejected = docs.find((d) => isGovernmentIdTypeTin(d?.id_type) && String(d?.status || '').toLowerCase() === 'rejected')
  if (rejected) {
    return {
      displayValue: '—',
      variant: 'rejected',
      cardDescription: 'Your TIN submission needs to be updated in Gov IDs.',
      tinHelperText: rejected?.rejection_reason
        ? `Previous submission was not accepted: ${String(rejected.rejection_reason)}`
        : 'Previous TIN submission was not accepted. Please upload a corrected entry in Gov IDs.',
      loading: false,
      showVerifiedBadge: false,
    }
  }

  const pending = docs.find((d) => isGovernmentIdTypeTin(d?.id_type) && String(d?.status || '').toLowerCase() === 'pending')
  if (pending) {
    return {
      displayValue: '—',
      variant: 'pending',
      cardDescription: 'Your TIN is awaiting verification in Gov IDs.',
      tinHelperText: 'Pending admin verification — the number will appear here once approved.',
      loading: false,
      showVerifiedBadge: false,
    }
  }

  return {
    displayValue: '—',
    variant: 'missing',
    cardDescription: 'Add your TIN under Gov IDs to align withholding and BIR records.',
    tinHelperText: 'No TIN on file. Please update in the Gov IDs tab.',
    loading: false,
    showVerifiedBadge: false,
  }
}

export function resolveWithholdingStatutoryPresentation(withholding, compensationSummary) {
  const whtRaw = compensationSummary?.totals?.withholding_tax ?? withholding?.withholding_per_month
  const wht = whtRaw != null && whtRaw !== '' ? Number(whtRaw) : NaN
  const basic = Number(compensationSummary?.basic_salary ?? 0)
  const monthlyTaxable = Number(withholding?.monthly_taxable_compensation ?? 0)
  const mweNote = withholding?.metadata?.mwe_note
  const mweMisconfigured = typeof mweNote === 'string' && mweNote.includes('no monthly ceiling')

  if (!withholding && !compensationSummary) {
    return { amountLabel: '—', status: 'pending', showGovIdsLink: true }
  }

  if (mweMisconfigured) {
    return {
      amountLabel: Number.isFinite(wht) ? formatSalaryTabPhp(wht) : '—',
      status: 'pending',
      showGovIdsLink: true,
    }
  }

  const hasCompBase = basic > 0 || monthlyTaxable > 0
  if (!hasCompBase && !Number.isFinite(wht)) {
    return { amountLabel: '—', status: 'pending', showGovIdsLink: true }
  }

  if (!Number.isFinite(wht)) {
    return { amountLabel: '—', status: 'pending', showGovIdsLink: true }
  }

  return {
    amountLabel: formatSalaryTabPhp(wht),
    status: 'matched',
    showGovIdsLink: false,
  }
}
