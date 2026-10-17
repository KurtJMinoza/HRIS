<?php

namespace Tests\Unit;

use Tests\TestCase;

class WelcomeLetterNotificationServiceTest extends TestCase
{
    public function test_coo_welcome_letter_config_targets_rocelyn_cortez(): void
    {
        $letters = (array) config('welcome_letters.letters');
        $this->assertArrayHasKey('coo_rocelyn_cortez', $letters);

        $match = (array) ($letters['coo_rocelyn_cortez']['match'] ?? []);
        $this->assertSame('Cortez', $match['last_name'] ?? null);
        $this->assertSame('Rocelyn', $match['first_name'] ?? null);

        $notification = (array) ($letters['coo_rocelyn_cortez']['notification'] ?? []);
        $this->assertSame('welcome.coo_letter', $notification['type'] ?? null);
        $this->assertSame('coo_rocelyn_cortez', $notification['data']['letter_key'] ?? null);
    }
}
