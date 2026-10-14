<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Undkonsorten\Easychat\Indexing\RateLimitBackoff;

final class RateLimitBackoffTest extends TestCase
{
    public function testReturnsTheResultWithoutWaitingWhenNothingIsLimited(): void
    {
        $backoff = new RecordingRateLimitBackoff();

        self::assertSame('vectors', $backoff->run(static fn(): string => 'vectors'));
        self::assertSame([], $backoff->waits);
    }

    public function testWaitsLongerEachTimeAndSucceedsOnceTheLimitIsLifted(): void
    {
        $backoff = new RecordingRateLimitBackoff(5, 2, 30);
        $calls = 0;

        $result = $backoff->run(static function () use (&$calls): string {
            if (++$calls < 4) {
                throw new RateLimitExceededException(retryAfter: null, errorMessage: 'Too many requests');
            }

            return 'vectors';
        });

        self::assertSame('vectors', $result);
        self::assertSame(4, $calls);
        self::assertSame([2, 4, 8], $backoff->waits);
    }

    public function testHonoursRetryAfterButNeverWaitsLongerThanTheMaximum(): void
    {
        $backoff = new RecordingRateLimitBackoff(4, 2, 30);
        $calls = 0;

        $backoff->run(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RateLimitExceededException(retryAfter: 7, errorMessage: 'Too many requests');
            }
            if ($calls === 2) {
                throw new RateLimitExceededException(retryAfter: 600, errorMessage: 'Too many requests');
            }
        });

        self::assertSame([7, 30], $backoff->waits);
    }

    public function testThrowsTheRateLimitAfterTheLastAttempt(): void
    {
        $backoff = new RecordingRateLimitBackoff(3, 1, 30);
        $calls = 0;

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('still limited');
        try {
            $backoff->run(static function () use (&$calls): void {
                ++$calls;
                throw new RateLimitExceededException(retryAfter: null, errorMessage: 'still limited');
            });
        } finally {
            self::assertSame(3, $calls);
            self::assertSame([1, 2], $backoff->waits);
        }
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $backoff = new RecordingRateLimitBackoff();
        $calls = 0;

        $this->expectException(\RuntimeException::class);
        try {
            $backoff->run(static function () use (&$calls): void {
                ++$calls;
                throw new \RuntimeException('Embeddings API is down', 4144662967);
            });
        } finally {
            self::assertSame(1, $calls);
            self::assertSame([], $backoff->waits);
        }
    }
}

/**
 * Records the waits instead of sleeping them.
 */
final class RecordingRateLimitBackoff extends RateLimitBackoff
{
    /** @var int[] */
    public array $waits = [];

    protected function sleep(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
