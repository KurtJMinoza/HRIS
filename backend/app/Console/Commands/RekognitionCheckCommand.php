<?php

namespace App\Console\Commands;

use App\Services\RekognitionLivenessService;
use Illuminate\Console\Command;

class RekognitionCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rekognition:check
                            {--create : Attempt to create a liveness session (validates AWS permissions)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify AWS Rekognition Face Liveness config and optionally create a test session';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $config = config('services.rekognition');
        $key = $config['key'] ?? '';
        $secret = $config['secret'] ?? '';
        $region = $config['region'] ?? 'us-east-1';

        $this->info('Rekognition Face Liveness configuration:');
        $this->line('  Region: '.$region);
        $this->line('  Key:    '.($key ? (substr($key, 0, 8).'...') : '<empty>'));
        $this->line('  Secret: '.($secret ? '***' : '<empty>'));

        if ($key === '' || $secret === '') {
            $this->newLine();
            $this->error('AWS credentials are not set. Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in .env');
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
        $this->info('Creating a Face Liveness session...');

        $data = RekognitionLivenessService::createSession();

        if ($data === null || isset($data['error'])) {
            $this->error('Failed to create session.');
            if (isset($data['error'])) {
                $this->line('  '.$data['error']);
            } else {
                $this->line('Check Laravel logs (storage/logs) and IAM permissions.');
            }
            $this->line('IAM policy must allow: rekognition:CreateFaceLivenessSession, rekognition:GetFaceLivenessSessionResults.');
            $this->line('Face Liveness is available in select regions (e.g. us-east-1, us-east-2). See AWS Rekognition docs.');

            return self::FAILURE;
        }

        $this->info('Session created successfully.');
        $this->line('  SessionId: '.$data['sessionId']);
        $this->line('  Region:    '.$data['region']);
        $this->newLine();
        $this->comment('Backend can create liveness sessions. Frontend can call POST /api/face/liveness/session.');

        return self::SUCCESS;
    }
}
