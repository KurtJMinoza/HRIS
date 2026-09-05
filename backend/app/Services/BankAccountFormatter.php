<?php

namespace App\Services;

use App\Models\EmployeeBankAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BankAccountFormatter
{
    public const ACCOUNT_NUMBER_EXAMPLE = '934105106070';

    /** @return array<string, mixed> */
    public static function validationRules(): array
    {
        return [
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]{2,10}$/u'],
            'account_number' => ['nullable', 'string', 'max:12', 'regex:/^\d{12}$/u'],
        ];
    }

    /** @return array<string, string> */
    public static function validationMessages(): array
    {
        return [
            'bank_code.regex' => 'Bank code must be 2–10 letters or digits (e.g. AUB).',
            'account_number.regex' => 'Account number must be exactly 12 digits (e.g. '.self::ACCOUNT_NUMBER_EXAMPLE.').',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{bank_name:?string,bank_code:?string,account_number:?string}
     */
    public static function normalize(array $payload): array
    {
        $bankName = trim((string) ($payload['bank_name'] ?? ''));
        $bankCode = strtoupper(trim((string) ($payload['bank_code'] ?? '')));
        $accountNumber = preg_replace('/\D+/', '', (string) ($payload['account_number'] ?? '')) ?? '';

        return [
            'bank_name' => $bankName !== '' ? $bankName : null,
            'bank_code' => $bankCode !== '' ? $bankCode : null,
            'account_number' => $accountNumber !== '' ? $accountNumber : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{bank_name:?string,bank_code:?string,account_number:?string}
     */
    public static function validateAndNormalize(array $payload): array
    {
        $validator = Validator::make($payload, self::validationRules(), self::validationMessages());
        $normalized = self::normalize($payload);

        $validator->after(function ($v) use ($normalized): void {
            $filled = collect($normalized)->filter(fn ($value) => $value !== null && $value !== '')->count();
            if ($filled > 0 && $filled < 3) {
                $v->errors()->add('bank_account', 'Bank name, bank code, and account number must all be provided together.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $normalized;
    }

    /** @return array{bank_name:?string,bank_code:?string,account_number:?string} */
    public static function serialize(?EmployeeBankAccount $record): array
    {
        return [
            'bank_name' => $record?->bank_name,
            'bank_code' => $record?->bank_code,
            'account_number' => $record?->account_number,
        ];
    }
}
