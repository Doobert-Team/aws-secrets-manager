<?php

namespace Doobert\AWSSecretsManager\Tests;

use Doobert\AWSSecretsManager\AWSSecretsManagerServiceProvider;
use PHPUnit\Framework\TestCase;

class AWSSecretsManagerServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['__test_config'] = [];
    }

    private function callProtected(string $method, array $args = [])
    {
        $reflection = new \ReflectionClass(AWSSecretsManagerServiceProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $ref = $reflection->getMethod($method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($provider, $args);
    }

    public function test_mapping_collects_every_path_for_a_repeated_key()
    {
        config(['aws-secrets-manager.keys_raw' => 'MAIL_PASSWORD:mail.mailers.smtp.password, MAIL_PASSWORD:mail.mailers.smtp_bulk.password,API_KEY:services.api.key']);

        $this->assertSame([
            'MAIL_PASSWORD' => ['mail.mailers.smtp.password', 'mail.mailers.smtp_bulk.password'],
            'API_KEY' => ['services.api.key'],
        ], $this->callProtected('mappingFromConfig'));
    }

    public function test_mapping_skips_invalid_entries()
    {
        config(['aws-secrets-manager.keys_raw' => 'NO_PATH,:no.key,API_KEY:services.api.key']);

        $this->assertSame(['API_KEY' => ['services.api.key']], $this->callProtected('mappingFromConfig'));
    }

    public function test_same_key_is_written_to_both_config_paths()
    {
        $this->callProtected('applySecretsToConfig', [
            ['MAIL_PASSWORD' => 's3cret', 'UNMAPPED' => 'ignored'],
            [
                'MAIL_PASSWORD' => ['mail.mailers.smtp.password', 'mail.mailers.smtp_bulk.password'],
                'MISSING_KEY' => ['services.missing.key'],
            ],
        ]);

        $this->assertSame('s3cret', config('mail.mailers.smtp.password'));
        $this->assertSame('s3cret', config('mail.mailers.smtp_bulk.password'));
        $this->assertNull(config('services.missing.key'));
        $this->assertArrayNotHasKey('UNMAPPED', $GLOBALS['__test_config']);
    }
}
