<?php

namespace App\Console\Commands;

use Aws\CognitoIdentity\CognitoIdentityClient;
use Aws\Exception\AwsException;
use Illuminate\Console\Command;

class CognitoIdentityPoolCheckCommand extends Command
{
    protected $signature = 'cognito:check';

    protected $description = 'Verify Cognito Identity Pool exists and guest credentials can be issued (Face Liveness streaming)';

    public function handle(): int
    {
        $poolId = trim((string) config('services.cognito.identity_pool_id', ''));
        $key = trim((string) config('services.rekognition.key', ''));
        $secret = trim((string) config('services.rekognition.secret', ''));
        $region = 'us-east-1';
        if (str_contains($poolId, ':')) {
            $region = explode(':', $poolId, 2)[0] ?: $region;
        }

        $this->info('Cognito Identity Pool check');
        $this->line('  Pool ID: '.($poolId !== '' ? $poolId : '<empty>'));
        $this->line('  Region:  '.$region);

        if ($poolId === '' || $key === '' || $secret === '') {
            $this->error('Set COGNITO_IDENTITY_POOL_ID and AWS credentials in .env');

            return self::FAILURE;
        }

        if (! in_array($region, ['us-east-1', 'us-east-2'], true)) {
            $this->error("Pool region {$region} is not supported for Face Liveness. Create a pool in us-east-1.");

            return self::FAILURE;
        }

        $client = new CognitoIdentityClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => ['key' => $key, 'secret' => $secret],
        ]);

        try {
            $pool = $client->describeIdentityPool(['IdentityPoolId' => $poolId]);
            $allowUnauth = (bool) $pool->get('AllowUnauthenticatedIdentities');
            $this->info('Pool exists: '.(string) ($pool->get('IdentityPoolName') ?? 'unnamed'));
            $this->line('  Allow unauthenticated identities: '.($allowUnauth ? 'yes' : 'NO — enable guest access in Cognito'));
            if (! $allowUnauth) {
                $this->newLine();
                $this->warn('Face Liveness requires guest/unauthenticated access on the Identity Pool.');

                return self::FAILURE;
            }

            $identity = $client->getId(['IdentityPoolId' => $poolId]);
            $identityId = (string) $identity->get('IdentityId');
            $this->line('  Guest identity ID: '.$identityId);

            $creds = $client->getCredentialsForIdentity(['IdentityId' => $identityId]);
            $accessKey = (string) ($creds->get('Credentials')['AccessKeyId'] ?? '');
            $this->info('Guest credentials issued: '.($accessKey !== '' ? substr($accessKey, 0, 8).'...' : 'failed'));
            $this->newLine();
            $this->comment('If liveness still shows "Server issue", attach this policy to the pool\'s UNAUTHENTICATED IAM role:');
            $this->line('  rekognition:StartFaceLivenessSession on Resource "*".');

            return self::SUCCESS;
        } catch (AwsException $e) {
            $this->error('AWS error: ['.$e->getAwsErrorCode().'] '.$e->getMessage());
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->line('This Identity Pool does not exist in '.$region.' (wrong ID, wrong account, or deleted).');
                $this->line('Create a new pool in us-east-1 and update COGNITO_IDENTITY_POOL_ID in backend/.env and frontend/.env.');
            }

            return self::FAILURE;
        }
    }
}
