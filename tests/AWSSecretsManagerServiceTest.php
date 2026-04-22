<?php

namespace Doobert\AWSSecretsManager\Tests;

use Doobert\AWSSecretsManager\AWSSecretsManagerService;
use PHPUnit\Framework\TestCase;

class AWSSecretsManagerServiceTest extends TestCase
{
    public function test_service_can_be_instantiated()
    {
        $service = new AWSSecretsManagerService();
        $this->assertInstanceOf(AWSSecretsManagerService::class, $service);
    }

    public function test_returns_null_when_disabled()
    {
        // Use a real instance and override config helper
        $service = new AWSSecretsManagerService();
        // Override enabled via Reflection
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('enabled');
        $prop->setAccessible(true);
        $prop->setValue($service, false);
        $this->assertNull($service->getSecret('any'));
        $this->assertSame([], $service->getAllSecrets());
    }

    public function test_returns_null_for_empty_secret_name()
    {
        $service = new AWSSecretsManagerService();
        $this->assertNull($service->getSecret(''));
        // getAllSecrets returns ['test/secret' => null] due to stub config
        $this->assertSame(['test/secret' => null], $service->getAllSecrets());
    }

    public function test_cache_and_aws_fallback()
    {
        $service = $this->getMockBuilder(AWSSecretsManagerService::class)
            ->onlyMethods(['store', 'cacheKey', 'fetchAndCacheSecret'])
            ->getMock();
        $service->method('store')->willReturn(new \Illuminate\Cache\Repository());
        $service->method('cacheKey')->willReturn('cache-key');
        $service->method('fetchAndCacheSecret')->willReturn(['foo' => 'bar']);
        $result = $service->getSecret('secret', 'foo');
        $this->assertEquals('bar', $result);
    }

    public function test_get_secret_returns_full_array()
    {
        $service = $this->getMockBuilder(AWSSecretsManagerService::class)
            ->onlyMethods(['store', 'cacheKey'])
            ->getMock();
        $repo = $this->getMockBuilder(\Illuminate\Cache\Repository::class)
            ->onlyMethods(['get'])
            ->getMock();
        $repo->method('get')->willReturn(['foo' => 'bar']);
        $service->method('store')->willReturn($repo);
        $service->method('cacheKey')->willReturn('cache-key');
        $result = $service->getSecret('secret');
        $this->assertEquals(['foo' => 'bar'], $result);
    }

    public function test_refresh_secret_returns_null_when_disabled()
    {
        $service = new AWSSecretsManagerService();
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('enabled');
        $prop->setAccessible(true);
        $prop->setValue($service, false);
        $this->assertNull($service->refreshSecret('any'));
    }
}
