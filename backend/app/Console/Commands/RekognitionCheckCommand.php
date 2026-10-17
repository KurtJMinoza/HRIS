<?php

namespace App\Console\Commands;

use App\Services\RekognitionLivenessService;
use Illuminate\Console\Command;

class RekognitionCheckCommand extends Command
{
    protected $signature = 'rekognition:check
                            {--create : Attempt to create a liveness session (validates AWS permissions)}';

    protected $description = 'Verify AWS Rekognition Face Liveness config and optionally create a test session';

    public function handle(): int
    {
        $config = config('services.rekognition');
        $key = $config['key'] ?? '';
        $secret = $config['secret'] ?? '';
        $configuredRegion = $config['region'] ?? 'us-east-1';
        $apiRegion = RekognitionLivenessService::resolveApiRegion($configuredRegion);
        $connectTimeout = max(1, (int) ($config['connect_timeout_seconds'] ?? 10));
        $requestTimeout = max($connectTimeout, (int) ($config['timeout_seconds'] ?? 30));

        $this->info('Rekognition Face Liveness configuration:');
        $this->line('  REKOGNITION_REGION (.env): '.$configuredRegion);
        $this->line('  API region (used for calls):  '.$apiRegion);
        if ($apiRegion !== $configuredRegion) {
            $this->warn('  Face Liveness only supports us-east-1 and us-east-2; API calls use '.$apiRegion.'.');
            $this->line('  Set REKOGNITION_REGION=us-east-1 in .env to match Cognito and the frontend.');
        }
        $this->line('  HTTP connect timeout:       '.$connectTimeout.'s');
        $this->line('  HTTP request timeout:       '.$requestTimeout.'s');
        $this->line('  Key:    '.($key ? (substr($key, 0, 8).'...') : '<empty>'));
        $this->line('  Secret: '.($secret ? '***' : '<empty>'));

        if ($key === '' || $secret === '') {
            $this->newLine();
            $this->error('AWS credentials are not set. Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in .env');
            $this->line('Then run: php artisan config:clear');
            $this->line('IAM user/role must have: rekognition:CreateFaceLivenessSession, rekognition:GetFaceLivenessSessionResults');

            return self::FAILURE;
        }

        $this->info('Credentials are present.');

        if (! $this->option('create')) {
            $this->newLine();
            $this->line('Run with --create to test creating a liveness session (validates AWS permissions).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Creating a Face Liveness session (max {$requestTimeout}s)...");

        $startedAt = microtime(true);
        $data = RekognitionLivenessService::createSession();
        $elapsed = round(microtime(true) - $startedAt, 2);

        if ($data === null || isset($data['error'])) {
            $this->error("Failed to create session (after {$elapsed}s).");
            if (isset($data['error'])) {
                $this->line('  '.$data['error']);
            } else {
                $this->line('  No error detail (APP_DEBUG=false). Check storage/logs/laravel.log');
            }
            $this->newLine();
            $this->line('Common fixes on a new machine after git pull:');
            $this->line('  1. Copy .env from the working PC (AWS keys are not in git).');
            $this->line('  2. Set REKOGNITION_REGION=us-east-1 and COGNITO_REGION=us-east-1');
            $this->line('  3. php artisan config:clear');
            $this->line('  4. Ensure outbound HTTPS to AWS is allowed (firewall/proxy).');
            $this->line('IAM policy must allow: rekognition:CreateFaceLivenessSession, rekognition:GetFaceLivenessSessionResults.');

            return self::FAILURE;
        }

        $this->info("Session created successfully ({$elapsed}s).");
        $this->line('  SessionId: '.$data['sessionId']);
        $this->line('  Region:    '.$data['region']);
        $this->newLine();
        $this->comment('Backend can create liveness sessions. Frontend can call POST /api/face/liveness/session.');

        return self::SUCCESS;
    }
}
