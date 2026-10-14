<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Chat;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use Undkonsorten\Easychat\Chat\ChatSessionCookie;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

final class ChatSessionCookieTest extends TestCase
{
    public function testGeneratedIdsAreRandomLowercaseHex(): void
    {
        $first = ChatSessionCookie::generateId();
        $second = ChatSessionCookie::generateId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
        self::assertTrue(ChatSessionCookie::isValidId($first));
    }

    public function testClientMadeIdsAreRejected(): void
    {
        self::assertFalse(ChatSessionCookie::isValidId('0f9c8a7e-1234-4bcd-9ef0-123456789abc'), 'UUIDs from EasyChat 0.2 are not server sessions.');
        self::assertFalse(ChatSessionCookie::isValidId(strtoupper(str_repeat('ab', 32))));
        self::assertFalse(ChatSessionCookie::isValidId(str_repeat('a', 63)));
        self::assertFalse(ChatSessionCookie::isValidId(''));
    }

    public function testMarkerShapeIsChecked(): void
    {
        self::assertTrue(ChatSessionCookie::isValidMarker('0f9c8a7e-1234-4bcd-9ef0-123456789abc'));
        self::assertTrue(ChatSessionCookie::isValidMarker('consent-given'));
        self::assertFalse(ChatSessionCookie::isValidMarker(''));
        self::assertFalse(ChatSessionCookie::isValidMarker('a b'));
        self::assertFalse(ChatSessionCookie::isValidMarker(str_repeat('a', 129)));
        self::assertFalse(ChatSessionCookie::isValidMarker('ä'));
    }

    public function testBuildSetsHttpOnlyStrictCookieForTheWholeSite(): void
    {
        $header = ChatSessionCookie::build(str_repeat('ab', 32), 1440, true);

        self::assertStringStartsWith('easychat_session=' . str_repeat('ab', 32) . ';', $header);
        self::assertStringContainsString('Max-Age=86400', $header);
        self::assertStringContainsString('path=/', $header);
        self::assertStringContainsString('secure', $header);
        self::assertStringContainsString('httponly', $header);
        self::assertStringContainsString('samesite=strict', $header);
    }

    public function testBuildOmitsSecureOnPlainHttp(): void
    {
        $header = ChatSessionCookie::build(str_repeat('ab', 32), 10, false);

        self::assertStringNotContainsString('secure', $header);
        self::assertStringContainsString('Max-Age=600', $header);
    }

    public function testBuildFallsBackToTheDefaultLifetime(): void
    {
        self::assertStringContainsString('Max-Age=' . (ChatSessionCookie::DEFAULT_LIFETIME_MINUTES * 60), ChatSessionCookie::build(str_repeat('ab', 32), 0, false));
    }

    public function testResolveKeepsAKnownSessionAndIssuesANewOneOtherwise(): void
    {
        $known = str_repeat('cd', 32);
        $repository = self::createStub(SessionRepository::class);
        $repository->method('hasSessionWithMarker')->willReturnCallback(
            static fn(string $sessionId, string $markerHash): bool => $sessionId === $known && $markerHash === sha1('marker'),
        );
        $cookie = new ChatSessionCookie($repository);

        [$sessionId, $isNew] = $cookie->resolve((new ServerRequest())->withCookieParams([ChatSessionCookie::NAME => $known]), sha1('marker'));
        self::assertSame($known, $sessionId);
        self::assertFalse($isNew);

        [$sessionId, $isNew] = $cookie->resolve((new ServerRequest())->withCookieParams([ChatSessionCookie::NAME => $known]), sha1('other-marker'));
        self::assertNotSame($known, $sessionId, 'A changed consent marker ends the old session.');
        self::assertTrue($isNew);

        [$sessionId, $isNew] = $cookie->resolve((new ServerRequest())->withCookieParams([ChatSessionCookie::NAME => str_repeat('ef', 32)]), sha1('marker'));
        self::assertTrue($isNew, 'A well-formed but unknown id is not continued.');

        [$sessionId, $isNew] = $cookie->resolve(new ServerRequest(), sha1('marker'));
        self::assertTrue($isNew);
        self::assertTrue(ChatSessionCookie::isValidId($sessionId));
    }
}
