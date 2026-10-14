<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

/**
 * Sliding-window rate limits for the chat endpoint, configured in the extension configuration:
 * requests per IP address and minute, messages per session and hour, new sessions per IP
 * address and hour. 0 disables a limit.
 *
 * IP addresses never reach the storage: the limiter key is a keyed hash that rotates daily
 * (see anonymizeIp()). The raw address is only compared against the exclude list in memory.
 */
class ChatRateLimiter
{
    public const DEFAULT_PER_IP_PER_MINUTE = 20;

    public const DEFAULT_PER_SESSION_PER_HOUR = 60;

    public const DEFAULT_NEW_SESSIONS_PER_IP_PER_HOUR = 10;

    public const PUBLIC_MESSAGE = 'Too many requests. Please wait a moment and try again.';

    /**
     * @param array<string, mixed>|null $settings overrides the extension configuration (tests)
     */
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private ?StorageInterface $storage = null,
        private readonly ?array $settings = null,
    ) {}

    public function checkIp(string $remoteIp): void
    {
        if ($this->isExcluded($remoteIp)) {
            return;
        }
        $this->consume(
            'easychat-ip',
            $this->limit('rateLimitPerIpPerMinute', self::DEFAULT_PER_IP_PER_MINUTE),
            '1 minute',
            self::anonymizeIp($remoteIp),
        );
    }

    public function checkNewSession(string $remoteIp): void
    {
        if ($this->isExcluded($remoteIp)) {
            return;
        }
        $this->consume(
            'easychat-new-session',
            $this->limit('rateLimitNewSessionsPerIpPerHour', self::DEFAULT_NEW_SESSIONS_PER_IP_PER_HOUR),
            '1 hour',
            self::anonymizeIp($remoteIp),
        );
    }

    public function checkSession(string $sessionId): void
    {
        $this->consume(
            'easychat-session',
            $this->limit('rateLimitPerSessionPerHour', self::DEFAULT_PER_SESSION_PER_HOUR),
            '1 hour',
            substr(sha1($sessionId), 0, 32),
        );
    }

    /**
     * Keyed, daily rotating hash of an IP address. Without the site's encryption key the stored
     * value cannot be linked back to the address, and the daily salt keeps keys from being
     * linkable across days. The alternative, masking with IpAnonymizationUtility before hashing,
     * would make a whole /24 share one limit and was therefore not chosen.
     */
    public static function anonymizeIp(string $remoteIp, ?string $day = null): string
    {
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '') . ($day ?? date('Y-m-d'));

        return substr(hash_hmac('sha256', $remoteIp, $secret), 0, 32);
    }

    private function consume(string $limiterId, int $limit, string $interval, string $key): void
    {
        if ($limit <= 0) {
            return;
        }
        $factory = new RateLimiterFactory(
            [
                'id' => $limiterId,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => $interval,
            ],
            $this->getStorage(),
        );
        $rateLimit = $factory->create($key)->consume();
        if ($rateLimit->isAccepted()) {
            return;
        }
        $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

        throw new ChatRequestException(
            429,
            self::PUBLIC_MESSAGE,
            ['Retry-After' => (string)$retryAfter],
            sprintf('Rate limit "%s" exceeded', $limiterId),
        );
    }

    private function isExcluded(string $remoteIp): bool
    {
        $excludeList = trim((string)($this->getSettings()['rateLimitIpExcludeList'] ?? ''));

        return $excludeList !== '' && $remoteIp !== '' && GeneralUtility::cmpIP($remoteIp, $excludeList);
    }

    private function limit(string $key, int $default): int
    {
        $settings = $this->getSettings();
        if (!array_key_exists($key, $settings) || $settings[$key] === '' || $settings[$key] === null) {
            return $default;
        }

        return (int)$settings[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        try {
            $settings = $this->extensionConfiguration->get('easychat');
        } catch (\Throwable) {
            return [];
        }

        return is_array($settings) ? $settings : [];
    }

    private function getStorage(): StorageInterface
    {
        return $this->storage ??= GeneralUtility::makeInstance(CachingFrameworkStorage::class);
    }
}
