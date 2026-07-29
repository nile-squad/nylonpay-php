<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Factory for creating thread-safe singleton SDK instances.
 */
final class CreateNylonPay
{
    /** @var array<string, NylonPay> */
    private static array $instances = [];

    /**
     * @param array{
     *   apiKey: string,
     *   apiSecret: string,
     *   baseUrl?: string,
     *   timeoutMs?: int,
     *   maxRetries?: int,
     *   maxPollIntervalMs?: int,
     *   maxPollDurationMs?: int|null,
     *   maxPollAttempts?: int|null,
     *   onDelayed?: 'wait'|'return',
     *   force?: bool,
     *   httpClient?: HttpClient|null,
     *   hooks?: array<string, mixed>|null
     * } $config
     */
    public static function create(array $config): NylonPay
    {
        if (!isset($config['apiKey']) || $config['apiKey'] === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }

        if (!str_starts_with($config['apiKey'], 'npk_')) {
            throw new \InvalidArgumentException('apiKey must start with "npk_"');
        }

        if (!isset($config['apiSecret']) || $config['apiSecret'] === '') {
            throw new \InvalidArgumentException('apiSecret is required');
        }

        if (!str_starts_with($config['apiSecret'], 'nps_')) {
            throw new \InvalidArgumentException('apiSecret must start with "nps_"');
        }

        $baseUrl = $config['baseUrl'] ?? Config::DEFAULT_BASE_URL;
        $secretHash = substr(hash('sha256', $config['apiSecret']), 0, 16);
        $instanceKey = sprintf('%s:%s:%s', $config['apiKey'], $baseUrl, $secretHash);

        if (($config['force'] ?? false) !== true) {
            $existing = self::withLock(static fn (): ?NylonPay => self::$instances[$instanceKey] ?? null);
            if ($existing instanceof NylonPay) {
                return $existing;
            }
        }

        $resolved = [
            'apiKey' => $config['apiKey'],
            'apiSecret' => $config['apiSecret'],
            'baseUrl' => $baseUrl,
            'timeoutMs' => $config['timeoutMs'] ?? Config::DEFAULT_TIMEOUT_MS,
            'maxRetries' => $config['maxRetries'] ?? Config::DEFAULT_MAX_RETRIES,
            'maxPollIntervalMs' => $config['maxPollIntervalMs'] ?? Config::DEFAULT_MAX_POLL_INTERVAL_MS,
            'maxPollDurationMs' => $config['maxPollDurationMs'] ?? null,
            'maxPollAttempts' => $config['maxPollAttempts'] ?? null,
            'onDelayed' => $config['onDelayed'] ?? 'wait',
            'httpClient' => $config['httpClient'] ?? null,
            'hooks' => $config['hooks'] ?? null,
        ];

        $instance = new NylonPay($resolved);

        self::withLock(static function () use ($instanceKey, $instance): void {
            self::$instances[$instanceKey] = $instance;
        });

        return $instance;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function withLock(callable $fn): mixed
    {
        $lockFile = sys_get_temp_dir() . '/nylonpay-php-sdk.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            return $fn();
        }

        try {
            flock($handle, LOCK_EX);

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
