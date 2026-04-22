<?php

namespace Doobert\AWSSecretsManager;

use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AWSSecretsManagerService
{
    // AWS Secrets Manager same-region p99 latency is ~500ms.
    // 10 seconds is a 20x safety margin; covers SDK cold-starts and API throttling spikes.
    private const LOCK_TTL_SECONDS = 10;
    private bool $check_latency;
    private bool $enabled;
    private string $log_channel;

    public function __construct()
    {
        $this->check_latency = (bool) config('services.aws_secrets.check_latency', false);
        $this->enabled = (bool) config('services.aws_secrets.enabled', true);
        $this->log_channel = config('services.aws_secrets.log_channel', null) ?? config('logging.default', 'stack');
    }

    protected function log($level, $message, array $context = [])
    {
        if ($this->log_channel) {
            \Log::channel($this->log_channel)->log($level, $message, $context);
        } else {
            \Log::log($level, $message, $context);
        }
    }
    // Return one key or the full secret, using cache first and AWS second.
    public function getSecret(string $secretName, ?string $key = null): mixed
    {
        if (!$this->enabled) {
            return null;
        }
        if ($secretName === '') {
            return null;
        }

        $cacheKey = $this->cacheKey($secretName);

        // Measure cache fetch latency
        if ($this->check_latency) {
            $start = microtime(true);
        }
        try {
            $secret = $this->store()->get($cacheKey);
        } catch (Throwable $exception) {
            $this->log('warning', 'AWS secret cache read failed; fetching directly from AWS.', [
                'secret_name' => $secretName,
                'message'     => $exception->getMessage(),
            ]);
            $secret = null;
        }
        if($this->check_latency) {
            $latency = round((microtime(true) - $start) * 1000);
            $this->log('info', 'AWS secret cache fetch latency', [
                'secret_name' => $secretName,
                'latency_ms' => $latency,
                'cache_hit' => is_array($secret),
            ]);
        }

        if (!is_array($secret)) {
            $secret = $this->fetchAndCacheSecret($secretName, $cacheKey);
        }

        if (!is_array($secret)) {
            return null;
        }
        if ($key === null) {
            return $secret;
        }

        return $secret[$key] ?? null;
    }

    // Force a fresh AWS read but keep stale cache if AWS is temporarily unavailable.
    // A lock prevents multiple workers from refreshing simultaneously.
    public function refreshSecret(string $secretName): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        if ($secretName === '') {
            return null;
        }

        $cacheKey  = $this->cacheKey($secretName);
        $lockTtl   = self::LOCK_TTL_SECONDS;

        $doRefresh = function () use ($secretName, $cacheKey): ?array {
            $secret = $this->fetchSecretFromAws($secretName);

            if (is_array($secret)) {
                try {
                    $this->store()->put($cacheKey, $secret, now()->addSeconds($this->cacheTtl()));
                } catch (Throwable $exception) {
                    $this->log('warning', 'AWS secret cache write failed during refresh; secret fetched but not cached.', [
                        'secret_name' => $secretName,
                        'message'     => $exception->getMessage(),
                    ]);
                }
                return $secret;
            }

            // AWS unavailable — return stale cached value rather than null.
            try {
                $stale = $this->store()->get($cacheKey);
            } catch (Throwable $exception) {
                $this->log('warning', 'AWS secret cache read failed during refresh fallback.', [
                    'secret_name' => $secretName,
                    'message'     => $exception->getMessage(),
                ]);
                return null;
            }

            return is_array($stale) ? $stale : null;
        };

        try {
            if (method_exists($this->store()->getStore(), 'lock')) {
                return $this->store()->lock($this->lockKey($secretName), $lockTtl)->block($lockTtl, $doRefresh);
            }
        } catch (LockTimeoutException) {
            // Another worker is already refreshing — return whatever is in cache right now.
            $this->log('warning', 'AWS secret refresh lock timed out; returning current cached value.', [
                'secret_name' => $secretName,
            ]);
            try {
                $stale = $this->store()->get($cacheKey);
                return is_array($stale) ? $stale : null;
            } catch (Throwable) {
                return null;
            }
        } catch (Throwable $exception) {
            $this->log('warning', 'AWS secret refresh lock unavailable; refreshing without lock.', [
                'secret_name' => $secretName,
                'message'     => $exception->getMessage(),
            ]);
        }

        return $doRefresh();
    }

    // Load the one configured AWS secret so it can be inspected or pre-used.
    public function getAllSecrets(): array
    {
        if (!$this->enabled) {
            return [];
        }
        $secretName = trim((string) config('services.aws_secrets.name', ''));

        if ($secretName === '') {
            return [];
        }

        return [
            $secretName => $this->getSecret($secretName),
        ];
    }

    // Fetch a secret directly from AWS Secrets Manager and handle failures safely.
    protected function fetchSecretFromAws(string $secretName): ?array
    {
        if($this->check_latency) {
            $start = microtime(true);
        }
        try {
            $client = new SecretsManagerClient([
                'version' => 'latest',
                'region'  => $this->region(),
            ]);
            $result = $this->fetchSecretWithClient($secretName, $client);
        } catch (Throwable $exception) {
            $this->log('error', 'Unable to fetch AWS secret.', [
                'secret_name' => $secretName,
                'message'     => $exception->getMessage(),
            ]);
            return null;
        }
        if($this->check_latency) {
            $latency = round((microtime(true) - $start) * 1000);
            $this->log('info', 'AWS secret fetch latency', [
                'secret_name' => $secretName,
                'latency_ms' => $latency,
            ]);
        }
        return $result;
    }

    // Return the AWS region used when calling Secrets Manager.
    protected function region(): string
    {
        return (string) config('services.aws_secrets.region', (string) config('app.region', 'us-west-2'));
    }

    // Ask AWS for the secret value and convert it into a PHP array.
    protected function fetchSecretWithClient(string $secretName, SecretsManagerClient $client): ?array
    {
        $result = $client->getSecretValue([
            'SecretId' => $secretName,
        ]);

        $secretString = $result['SecretString'] ?? null;

        if ($secretString === null && isset($result['SecretBinary'])) {
            $secretString = base64_decode($result['SecretBinary'], true) ?: null;
        }

        if ($secretString === null || $secretString === '') {
            return null;
        }

        $decodedSecret = json_decode($secretString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSecret)) {
            return $decodedSecret;
        }

        return ['value' => $secretString];
    }

    // Return the cache lifetime in seconds, defaulting to one hour.
    protected function cacheTtl(): int
    {
        $ttl = (int) config('services.aws_secrets.cache_ttl', 3600);

        return $ttl > 0 ? $ttl : 3600;
    }

    protected function cacheKey(string $secretName): string
    {
        return sprintf(
            'aws-secrets:%s:%s:%s',
            (string) config('app.env', 'production'),
            (string) config('app.name', 'app'),
            $secretName
        );
    }

    // Always read/write secrets through a dedicated cache store (default: Redis)
    // instead of the application default store (database), which is too slow
    // and does not provide atomic locks suitable for stampede protection.
    protected function store(): \Illuminate\Cache\Repository
    {
        return Cache::store(
            (string) config('services.aws_secrets.cache_store', 'redis')
        );
    }

    // Lock key mirrors the cache key namespace so apps sharing the same Redis
    // instance never contend on each other's locks.
    protected function lockKey(string $secretName): string
    {
        return sprintf(
            'aws-secrets-lock:%s:%s:%s',
            (string) config('app.env', 'production'),
            (string) config('app.name', 'app'),
            sha1($secretName)
        );
    }

    protected function fetchAndCacheSecret(string $secretName, string $cacheKey): ?array
    {
        // LOCK_TTL_SECONDS covers the full AWS API round-trip with headroom.
        // block() waits the same duration before giving up — safe for same-region EC2.
        $lockTtl = self::LOCK_TTL_SECONDS;

        try {
            if (method_exists($this->store()->getStore(), 'lock')) {
                return $this->store()->lock($this->lockKey($secretName), $lockTtl)->block($lockTtl, function () use ($secretName, $cacheKey) {
                    // Double-check: winner of a previous lock may have already filled cache.
                    $cachedSecret = $this->store()->get($cacheKey);

                    if (is_array($cachedSecret)) {
                        return $cachedSecret;
                    }

                    $secret = $this->fetchSecretFromAws($secretName);

                    if (is_array($secret)) {
                        $this->store()->put($cacheKey, $secret, now()->addSeconds($this->cacheTtl()));
                    }

                    return $secret;
                });
            }
        } catch (LockTimeoutException) {
            // Lock held longer than expected — fall through and fetch directly.
            // This can only happen if the lock holder's AWS call exceeds LOCK_TTL_SECONDS.
            $this->log('warning', 'AWS secret lock wait timed out; falling back to direct AWS fetch.', [
                'secret_name'    => $secretName,
                'waited_seconds' => $lockTtl,
            ]);
        } catch (Throwable $exception) {
            // Lock driver unavailable (e.g. non-Redis cache store) — fetch without lock.
            $this->log('warning', 'AWS secret cache lock unavailable; fetching without lock.', [
                'secret_name' => $secretName,
                'message'     => $exception->getMessage(),
            ]);
        }

        // Fallback: either lock timed out or cache store has no lock support.
        $secret = $this->fetchSecretFromAws($secretName);

        if (is_array($secret)) {
            try {
                $this->store()->put($cacheKey, $secret, now()->addSeconds($this->cacheTtl()));
            } catch (Throwable $exception) {
                $this->log('warning', 'AWS secret cache write failed in fallback path; secret fetched but not cached.', [
                    'secret_name' => $secretName,
                    'message'     => $exception->getMessage(),
                ]);
            }
        }

        return $secret;
    }
}
