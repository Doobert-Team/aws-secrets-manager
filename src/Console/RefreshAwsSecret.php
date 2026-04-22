<?php

namespace Doobert\AWSSecretsManager\Console;

use Doobert\AWSSecretsManager\AWSSecretsManagerService;
use Illuminate\Console\Command;

class RefreshAwsSecret extends Command
{
    protected $signature = 'doobertaws:secret-refresh {--all : Refresh the secret configured in AWS_SECRETS_NAME}';

    protected $description = 'Force a fresh fetch from AWS Secrets Manager, overwriting the Redis cache. Run this immediately after rotating a secret.';

    public function handle(AWSSecretsManagerService $service): int
    {
        $secretName = trim((string) config('services.aws_secrets.name', ''));

        if ($secretName === '') {
            $this->error('AWS_SECRETS_NAME is not set. Nothing to refresh.');
            return self::FAILURE;
        }

        $this->info("Refreshing secret: {$secretName}");

        $secret = $service->refreshSecret($secretName);

        if (!is_array($secret)) {
            $this->error('Failed to fetch secret from AWS. Check logs for details.');
            $this->line('  Tip: verify IAM permissions and that AWS_SECRETS_NAME is correct.');
            return self::FAILURE;
        }

        $this->info('Secret refreshed and cached in Redis successfully.');
        $this->line('  Keys available: ' . implode(', ', array_keys($secret)));

        return self::SUCCESS;
    }
}
