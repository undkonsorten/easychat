<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Chat;

use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Undkonsorten\Easychat\Chat\ChatRateLimiter;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

final class ChatRateLimiterTest extends TestCase
{
    public function testRequestsBeyondTheIpLimitAreRejectedWith429AndRetryAfter(): void
    {
        $limiter = $this->limiter(['rateLimitPerIpPerMinute' => 3]);

        $limiter->checkIp('203.0.113.5');
        $limiter->checkIp('203.0.113.5');
        $limiter->checkIp('203.0.113.5');

        try {
            $limiter->checkIp('203.0.113.5');
            self::fail('The fourth request within a minute must be rejected.');
        } catch (ChatRequestException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame(ChatRateLimiter::PUBLIC_MESSAGE, $exception->getPublicMessage());
            self::assertArrayHasKey('Retry-After', $exception->getHeaders());
            self::assertGreaterThanOrEqual(1, (int)$exception->getHeaders()['Retry-After']);
            self::assertStringNotContainsString('203.0.113.5', $exception->getMessage(), 'The address must not even reach the log message.');
        }
    }

    public function testAddressesHaveIndependentCounters(): void
    {
        $limiter = $this->limiter(['rateLimitPerIpPerMinute' => 1]);

        $limiter->checkIp('203.0.113.5');
        $limiter->checkIp('203.0.113.6');

        $this->expectException(ChatRequestException::class);
        $limiter->checkIp('203.0.113.6');
    }

    public function testZeroDisablesALimit(): void
    {
        $this->expectNotToPerformAssertions();
        $limiter = $this->limiter(['rateLimitPerIpPerMinute' => 0, 'rateLimitPerSessionPerHour' => 0, 'rateLimitNewSessionsPerIpPerHour' => 0]);

        for ($i = 0; $i < 50; $i++) {
            $limiter->checkIp('203.0.113.5');
            $limiter->checkSession('session');
            $limiter->checkNewSession('203.0.113.5');
        }
    }

    public function testExcludedAddressesAreNeverLimited(): void
    {
        $limiter = $this->limiter(['rateLimitPerIpPerMinute' => 1, 'rateLimitNewSessionsPerIpPerHour' => 1, 'rateLimitIpExcludeList' => '10.0.0.*,203.0.113.5']);

        for ($i = 0; $i < 5; $i++) {
            $limiter->checkIp('203.0.113.5');
            $limiter->checkIp('10.0.0.42');
            $limiter->checkNewSession('203.0.113.5');
        }

        $this->expectException(ChatRequestException::class);
        $limiter->checkIp('198.51.100.1');
        $limiter->checkIp('198.51.100.1');
    }

    public function testSessionAndNewSessionLimitsAreEnforced(): void
    {
        $limiter = $this->limiter(['rateLimitPerSessionPerHour' => 2, 'rateLimitNewSessionsPerIpPerHour' => 1]);

        $limiter->checkSession('session-a');
        $limiter->checkSession('session-a');
        $limiter->checkSession('session-b');
        try {
            $limiter->checkSession('session-a');
            self::fail('The third message of a session within an hour must be rejected.');
        } catch (ChatRequestException $exception) {
            self::assertSame(429, $exception->getStatusCode());
        }

        $limiter->checkNewSession('203.0.113.5');
        $this->expectException(ChatRequestException::class);
        $limiter->checkNewSession('203.0.113.5');
    }

    public function testMissingSettingsUseTheDefaults(): void
    {
        $limiter = $this->limiter([]);

        for ($i = 0; $i < ChatRateLimiter::DEFAULT_PER_IP_PER_MINUTE; $i++) {
            $limiter->checkIp('203.0.113.5');
        }

        $this->expectException(ChatRequestException::class);
        $limiter->checkIp('203.0.113.5');
    }

    public function testAnonymizedKeysDoNotContainTheAddressAndRotateDaily(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key';

        $today = ChatRateLimiter::anonymizeIp('203.0.113.5', '2026-10-14');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $today);
        self::assertStringNotContainsString('203.0.113.5', $today);
        self::assertSame($today, ChatRateLimiter::anonymizeIp('203.0.113.5', '2026-10-14'), 'Same address and day: same key, otherwise the limit could not count.');
        self::assertNotSame($today, ChatRateLimiter::anonymizeIp('203.0.113.6', '2026-10-14'));
        self::assertNotSame($today, ChatRateLimiter::anonymizeIp('203.0.113.5', '2026-10-15'), 'Keys must not be linkable across days.');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'another-key';
        self::assertNotSame($today, ChatRateLimiter::anonymizeIp('203.0.113.5', '2026-10-14'), 'Without the site secret the key cannot be reproduced.');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function limiter(array $settings): ChatRateLimiter
    {
        return new ChatRateLimiter(self::createStub(ExtensionConfiguration::class), new InMemoryStorage(), $settings);
    }
}
