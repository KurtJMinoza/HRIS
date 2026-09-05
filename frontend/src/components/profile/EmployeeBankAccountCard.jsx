import { Building2, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  BANK_ACCOUNT_NUMBER_EXAMPLE,
  BANK_CODE_EXAMPLE,
  normalizeBankAccountForm,
} from '@/lib/bankAccountConstants'

export function EmployeeBankAccountCard({
  value,
  errors = {},
  saving = false,
  disabled = false,
  onChange,
  onSave,
}) {
  const form = normalizeBankAccountForm(value)

  const setField = (field, nextValue) => {
    onChange?.({
      ...form,
      [field]: field === 'account_number'
        ? String(nextValue || '').replace(/\D/g, '').slice(0, 12)
        : field === 'bank_code'
          ? String(nextValue || '').toUpperCase()
          : nextValue,
    })
  }

  return (
    <Card className="border border-border/40 bg-white shadow-sm dark:border-white/8 dark:bg-[#111827]">
      <CardHeader className="pb-4">
        <div className="flex items-start gap-3">
          <div className="rounded-lg border border-border/60 bg-muted/20 p-2">
            <Building2 className="size-4 text-muted-foreground" />
          </div>
          <div>
            <CardTitle className="text-lg font-semibold text-[#0A0A0A] dark:text-white">Bank Account</CardTitle>
            <CardDescription className="mt-1">
              Payroll disbursement account. Account number must be exactly 12 digits.
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {errors.bank_account ? (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            {errors.bank_account}
          </p>
        ) : null}
        <div className="grid gap-4 @md:grid-cols-2">
          <div className="space-y-1.5 @md:col-span-2">
            <Label htmlFor="bank_name">Bank Name</Label>
            <Input
              id="bank_name"
              value={form.bank_name}
              onChange={(e) => setField('bank_name', e.target.value)}
              placeholder="e.g. Asia United Bank"
              disabled={disabled || saving}
            />
            {errors.bank_name ? <p className="text-xs text-destructive">{errors.bank_name}</p> : null}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="bank_code">Bank Code</Label>
            <Input
              id="bank_code"
              value={form.bank_code}
              onChange={(e) => setField('bank_code', e.target.value)}
              placeholder={`e.g. ${BANK_CODE_EXAMPLE}`}
              disabled={disabled || saving}
              className="uppercase"
            />
            {errors.bank_code ? <p className="text-xs text-destructive">{errors.bank_code}</p> : null}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="account_number">Account Number</Label>
            <Input
              id="account_number"
              value={form.account_number}
              onChange={(e) => setField('account_number', e.target.value)}
              placeholder={BANK_ACCOUNT_NUMBER_EXAMPLE}
              inputMode="numeric"
              disabled={disabled || saving}
              className="font-mono tracking-wide"
            />
            {errors.account_number ? <p className="text-xs text-destructive">{errors.account_number}</p> : null}
          </div>
        </div>
        {!disabled ? (
          <div className="flex justify-end border-t border-border/40 pt-4">
            <Button type="button" onClick={onSave} disabled={saving}>
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Save Bank Account
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  )
}

export default EmployeeBankAccountCard
