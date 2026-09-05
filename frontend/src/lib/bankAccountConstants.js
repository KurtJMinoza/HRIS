/** Mirrors backend App\Services\BankAccountFormatter constants. */

export const BANK_ACCOUNT_NUMBER_EXAMPLE = '934105106070'
export const BANK_CODE_EXAMPLE = 'AUB'

export const BANK_ACCOUNT_NUMBER_PATTERN = /^\d{12}$/
export const BANK_CODE_PATTERN = /^[A-Za-z0-9]{2,10}$/

export function createEmptyBankAccountState() {
  return {
    bank_name: '',
    bank_code: '',
    account_number: '',
  }
}

export function normalizeBankAccountForm(value = {}) {
  return {
    bank_name: String(value?.bank_name || '').trim(),
    bank_code: String(value?.bank_code || '').trim().toUpperCase(),
    account_number: String(value?.account_number || '').replace(/\D/g, '').slice(0, 12),
  }
}

export function validateBankAccountForm(form) {
  const errors = {}
  const normalized = normalizeBankAccountForm(form)
  const filled = [normalized.bank_name, normalized.bank_code, normalized.account_number].filter(Boolean).length

  if (filled === 0) {
    return { errors, normalized, isComplete: false }
  }

  if (filled < 3) {
    errors.bank_account = 'Bank name, bank code, and account number must all be provided together.'
    return { errors, normalized, isComplete: false }
  }

  if (!BANK_CODE_PATTERN.test(normalized.bank_code)) {
    errors.bank_code = `Bank code must be 2–10 letters or digits (e.g. ${BANK_CODE_EXAMPLE}).`
  }
  if (!BANK_ACCOUNT_NUMBER_PATTERN.test(normalized.account_number)) {
    errors.account_number = `Account number must be exactly 12 digits (e.g. ${BANK_ACCOUNT_NUMBER_EXAMPLE}).`
  }

  return {
    errors,
    normalized,
    isComplete: Object.keys(errors).length === 0,
  }
}

export function bankAccountIsComplete(value = {}) {
  const normalized = normalizeBankAccountForm(value)
  return Boolean(
    normalized.bank_name
    && BANK_CODE_PATTERN.test(normalized.bank_code)
    && BANK_ACCOUNT_NUMBER_PATTERN.test(normalized.account_number),
  )
}
