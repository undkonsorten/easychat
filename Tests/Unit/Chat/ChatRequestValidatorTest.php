<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Undkonsorten\Easychat\Chat\ChatRequestValidator;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

final class ChatRequestValidatorTest extends TestCase
{
    private ChatRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ChatRequestValidator();
    }

    public function testOnlyPostIsAllowed(): void
    {
        $exception = $this->catch(fn() => $this->validator->assertMethod($this->request('{}', method: 'GET')));

        self::assertSame(405, $exception->getStatusCode());
        self::assertSame(['Allow' => 'POST'], $exception->getHeaders());

        $this->validator->assertMethod($this->request('{}'));
    }

    public function testForeignOriginIsRejected(): void
    {
        $request = $this->request('{}', ['Origin' => 'https://evil.example']);

        $exception = $this->catch(fn() => $this->validator->assertSameOrigin($request, $this->normalizedParams($request)));

        self::assertSame(403, $exception->getStatusCode());
    }

    public function testForeignRefererIsRejectedWhenThereIsNoOrigin(): void
    {
        $request = $this->request('{}', ['Referer' => 'https://evil.example/page']);

        $exception = $this->catch(fn() => $this->validator->assertSameOrigin($request, $this->normalizedParams($request)));

        self::assertSame(403, $exception->getStatusCode());
    }

    public function testOwnOriginAndMissingOriginAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $request = $this->request('{}', ['Origin' => 'https://EXAMPLE.com']);
        $this->validator->assertSameOrigin($request, $this->normalizedParams($request));

        $request = $this->request('{}', ['Referer' => 'https://example.com/en/page/']);
        $this->validator->assertSameOrigin($request, $this->normalizedParams($request));

        $request = $this->request('{}');
        $this->validator->assertSameOrigin($request, $this->normalizedParams($request));
    }

    public function testNonJsonContentTypeIsRejected(): void
    {
        $exception = $this->catch(fn() => $this->validator->decodeBody($this->request('{}', ['Content-Type' => 'text/plain'])));

        self::assertSame(415, $exception->getStatusCode());
    }

    public function testOversizedContentLengthIsRejectedBeforeReading(): void
    {
        $exception = $this->catch(fn() => $this->validator->decodeBody($this->request('{}', ['Content-Length' => (string)(ChatRequestValidator::MAX_BODY_BYTES + 1)])));

        self::assertSame(413, $exception->getStatusCode());
    }

    public function testOversizedBodyWithoutContentLengthIsRejected(): void
    {
        $body = '{"messages":[{"role":"user","text":"' . str_repeat('a', ChatRequestValidator::MAX_BODY_BYTES) . '"}]}';

        $exception = $this->catch(fn() => $this->validator->decodeBody($this->request($body)));

        self::assertSame(413, $exception->getStatusCode());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $exception = $this->catch(fn() => $this->validator->decodeBody($this->request('{"messages": [')));

        self::assertSame(400, $exception->getStatusCode());
        self::assertStringNotContainsString('JsonException', $exception->getPublicMessage());
    }

    public function testJsonThatIsNotAnObjectIsRejected(): void
    {
        $exception = $this->catch(fn() => $this->validator->decodeBody($this->request('"just a string"')));

        self::assertSame(400, $exception->getStatusCode());
    }

    public function testDecodedBodyIsReturned(): void
    {
        $body = $this->validator->decodeBody($this->request('{"messages":[{"role":"user","text":"hi"}],"contentElement":12}', ['Content-Type' => 'application/json; charset=utf-8']));

        self::assertSame(12, $body['contentElement']);
        self::assertSame('hi', $body['messages'][0]['text']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function bodiesWithoutAUsableMessage(): iterable
    {
        yield 'no messages key' => [['contentElement' => 1]];
        yield 'messages is not an array' => [['messages' => 'hello']];
        yield 'messages is an object' => [['messages' => ['role' => 'user', 'text' => 'hello']]];
        yield 'messages is empty' => [['messages' => []]];
        yield 'last message is not an object' => [['messages' => ['hello']]];
        yield 'last message has no text' => [['messages' => [['role' => 'user']]]];
        yield 'text is not a string' => [['messages' => [['role' => 'user', 'text' => 42]]]];
        yield 'text is blank' => [['messages' => [['role' => 'user', 'text' => "  \n\t "]]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('bodiesWithoutAUsableMessage')]
    public function testBodiesWithoutAUsableMessageAreRejected(array $body): void
    {
        $exception = $this->catch(fn() => $this->validator->extractUserMessage($body, 2000));

        self::assertSame(400, $exception->getStatusCode());
    }

    public function testMessageLengthIsCountedInCharacters(): void
    {
        $text = str_repeat('ä', 2000);
        self::assertSame($text, $this->validator->extractUserMessage(['messages' => [['text' => $text]]], 2000));

        $exception = $this->catch(fn() => $this->validator->extractUserMessage(['messages' => [['text' => $text . 'ä']]], 2000));

        self::assertSame(400, $exception->getStatusCode());
        self::assertStringContainsString('2000', $exception->getPublicMessage());
    }

    public function testOnlyTheLastMessageCountsAndIsTrimmed(): void
    {
        $text = $this->validator->extractUserMessage(['messages' => [['text' => str_repeat('x', 5000)], ['text' => "  hello \n"]]], 10);

        self::assertSame('hello', $text);
    }

    private function catch(callable $callable): ChatRequestException
    {
        try {
            $callable();
        } catch (ChatRequestException $exception) {
            return $exception;
        }
        self::fail('A ChatRequestException was expected.');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, array $headers = [], string $method = 'POST'): ServerRequest
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return new ServerRequest(
            'https://example.com/_easychat/chat',
            $method,
            $stream,
            array_replace(['Content-Type' => 'application/json'], $headers),
            [
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/_easychat/chat',
                'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
                'REMOTE_ADDR' => '203.0.113.5',
            ],
        );
    }

    private function normalizedParams(ServerRequest $request): NormalizedParams
    {
        return NormalizedParams::createFromRequest($request, []);
    }
}
