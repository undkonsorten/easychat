<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

/**
 * What a chat request resolves to: the Chatbot content element it came from, the configuration
 * that element selected, and the cookie lifetime the editor chose for it.
 */
final readonly class ResolvedPlugin
{
    public function __construct(
        public int $contentElementUid,
        public ChatConfiguration $configuration,
        public int $cookieExpirationMinutes,
    ) {}
}
