<?php

namespace Undkonsorten\Easychat\Indexing;

use Symfony\AI\Platform\Exception\RateLimitExceededException;

/**
 * Runs an operation again after a rate limit of the embeddings API, waiting longer each time.
 *
 * The wait is the API's Retry-After if it sent one, otherwise doubles from $initialDelay, and is
 * never longer than $maxDelay: with the Cache technology and a synchronous transport indexing runs
 * inside a visitor's page request, which must not hang for minutes. Anything but a rate limit is
 * not retried, and once $maxAttempts are used up the rate limit is thrown as it came.
 */
class RateLimitBackoff
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $initialDelay = 2,
        private readonly int $maxDelay = 30,
    ) {}

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (RateLimitExceededException $exception) {
                if ($attempt >= $this->maxAttempts) {
                    throw $exception;
                }
                $this->sleep(min($this->maxDelay, max(1, $exception->getRetryAfter() ?? $this->initialDelay * 2 ** ($attempt - 1))));
            }
        }
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
