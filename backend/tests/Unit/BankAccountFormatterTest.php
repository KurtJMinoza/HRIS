<?php

namespace Tests\Unit;

use App\Services\BankAccountFormatter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankAccountFormatterTest extends TestCase
{
    public function test_validate_and_normalize_accepts_complete_bank_account(): void
    {
        $normalized = BankAccountFormatter::validateAndNormalize([
            'bank_name' => 'Asia United Bank',
            'bank_code' => 'aub',
            'account_number' => '934105106070',
        ]);

        $this->assertSame('Asia United Bank', $normalized['bank_name']);
        $this->assertSame('AUB', $normalized['bank_code']);
        $this->assertSame('934105106070', $normalized['account_number']);
    }

    public function test_validate_and_normalize_rejects_partial_bank_account(): void
    {
        $this->expectException(ValidationException::class);

        BankAccountFormatter::validateAndNormalize([
            'bank_name' => 'Asia United Bank',
            'bank_code' => '',
            'account_number' => '',
        ]);
    }

    public function test_validate_and_normalize_rejects_invalid_account_number(): void
    {
        try {
            BankAccountFormatter::validateAndNormalize([
                'bank_name' => 'Asia United Bank',
                'bank_code' => 'AUB',
                'account_number' => '12345',
            ]);
            $this->fail('Expected validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account_number', $e->errors());
        }
    }
}
