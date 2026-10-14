<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

/**
 * The server-issued, HttpOnly session cookie that identifies a visitor's conversations.
 *
 * The visitor's browser also holds the easychat_session_id cookie that the widget's JavaScript
 * (or an external cookie manager) sets once consent is given. Its value is only a marker: the
 * server stores a hash of it with every conversation, and a changed marker (revoked and renewed
 * consent, or an expired cookie) ends the old conversations by issuing a new session id.
 */
final readonly class ChatSessionCookie
{
    public const NAME = 'easychat_session';

    public const MARKER_NAME = 'easychat_session_id';

    public const ID_PATTERN = '/^[0-9a-f]{64}$/';

    public const MAX_MARKER_LENGTH = 128;

    public const DEFAULT_LIFETIME_MINUTES = 1440;

    public function __construct(
        private SessionRepository $sessionRepository,
    ) {}

    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * External cookie managers set the marker to whatever they like; only the shape is checked.
     */
    public static function isValidMarker(string $marker): bool
    {
        return $marker !== ''
            && strlen($marker) <= self::MAX_MARKER_LENGTH
            && preg_match('/^[\x21-\x7e]+$/', $marker) === 1;
    }

    public static function hashMarker(string $marker): string
    {
        return sha1($marker);
    }

    /**
     * @return array{0: string, 1: bool} the session id and whether it was newly issued
     */
    public function resolve(ServerRequestInterface $request, string $markerHash): array
    {
        $candidate = $request->getCookieParams()[self::NAME] ?? '';
        if (is_string($candidate)
            && self::isValidId($candidate)
            && $this->sessionRepository->hasSessionWithMarker($candidate, $markerHash)
        ) {
            return [$candidate, false];
        }

        return [self::generateId(), true];
    }

    public static function build(string $sessionId, int $lifetimeMinutes, bool $secure): string
    {
        if ($lifetimeMinutes <= 0) {
            $lifetimeMinutes = self::DEFAULT_LIFETIME_MINUTES;
        }
        $cookie = Cookie::create(
            self::NAME,
            $sessionId,
            time() + $lifetimeMinutes * 60,
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );

        return (string)$cookie;
    }
}
