<?php

namespace Doobert\AWSSecretsManager;

use Illuminate\Support\ServiceProvider;

class AWSSecretsManagerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton(AWSSecretsManagerService::class, function ($app) {
            return new AWSSecretsManagerService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        // Publish config file
        $this->publishes([
            __DIR__.'/../config/aws-secrets-manager.php' => config_path('aws-secrets-manager.php'),
        ], 'config');

        // Register artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Doobert\AWSSecretsManager\Console\RefreshAwsSecret::class,
            ]);
        }

        // Check if AWS Secrets Manager integration is enabled
        if (!config('aws-secrets-manager.enabled', true)) {
            \Log::info('AWS Secrets Manager integration is disabled by configuration. Using .env values.');
            return;
        }

        if ($this->app->runningInConsole() && !config('aws-secrets-manager.load_in_console', false)) {
            return;
        }

        $secretName = trim((string) config('aws-secrets-manager.name', ''));

        if ($secretName === '') {
            return;
        }

        $mapping = $this->mappingFromConfig();

        if ($mapping === []) {
            return;
        }

        /** @var AWSSecretsManagerService $service */
        $service = $this->app->make(AWSSecretsManagerService::class);
        $secrets = $service->getSecret($secretName);

        if (!is_array($secrets)) {
            \Log::warning('AWS secrets were not loaded; using existing config values.', [
                'secret_name' => $secretName,
            ]);
            return;
        }

        $this->applySecretsToConfig($secrets, $mapping);
    }

    // Write each mapped secret value to every config path listed for its key.
    protected function applySecretsToConfig(array $secrets, array $mapping): void
    {
        foreach ($mapping as $secretKey => $configPaths) {
            if (!array_key_exists($secretKey, $secrets)) {
                continue;
            }

            foreach ($configPaths as $configPath) {
                config([$configPath => $secrets[$secretKey]]);
            }
        }
    }

    // Returns [SECRET_KEY => [config.path, ...]] parsed from keys_raw.
    protected function mappingFromConfig(): array
    {
        $rawPairs = array_filter(array_map('trim', explode(',', (string) config('aws-secrets-manager.keys_raw', ''))));
        $mapping = [];

        foreach ($rawPairs as $pair) {
            [$secretKey, $configPath] = array_pad(explode(':', $pair, 2), 2, null);
            $secretKey = trim((string) $secretKey);
            $configPath = trim((string) $configPath);

            if ($secretKey === '' || $configPath === '') {
                \Log::warning('Invalid AWS_SECRETS_KEYS entry. Expected KEY:config.path format.', [
                    'entry' => $pair,
                ]);
                continue;
            }

            // One secret key may feed several config paths (e.g. two mailers sharing a password).
            $mapping[$secretKey][] = $configPath;
        }

        return $mapping;
    }
}
