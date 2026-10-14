<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;
use Undkonsorten\Easychat\Chat\PluginResolver;

final class PluginResolverTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private PluginResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ChatPlugins.csv');
        $this->resolver = $this->get(PluginResolver::class);
    }

    public function testResolvesTheConfigurationAndCookieLifetimeOfAVisibleChatbot(): void
    {
        $plugin = $this->resolver->resolve(10);

        self::assertSame(10, $plugin->contentElementUid);
        self::assertSame(1, $plugin->configuration->uid);
        self::assertSame('model-a', $plugin->configuration->model);
        self::assertSame('be nice', $plugin->configuration->systemMessage);
        self::assertSame(50, $plugin->configuration->maxInputLength);
        self::assertSame(77, $plugin->configuration->maxTokens);
        self::assertSame(2, $plugin->configuration->historyWindow);
        self::assertSame(60, $plugin->cookieExpirationMinutes);
    }

    public function testEveryContentElementSelectsItsOwnChatbot(): void
    {
        self::assertSame(1, $this->resolver->resolve(10)->configuration->uid);
        self::assertSame(2, $this->resolver->resolve(17)->configuration->uid);
        self::assertSame('be brief', $this->resolver->resolve(17)->configuration->systemMessage);
    }

    public function testCookieLifetimeFallsBackToTheDefault(): void
    {
        self::assertSame(1440, $this->resolver->resolve(17)->cookieExpirationMinutes);
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function unusableContentElements(): iterable
    {
        yield 'no content element' => [0, 400];
        yield 'unknown uid' => [999, 404];
        yield 'hidden content element' => [11, 404];
        yield 'not a chatbot' => [12, 404];
        yield 'on a hidden page' => [13, 404];
        yield 'on an access restricted page' => [18, 404];
        yield 'deleted content element' => [19, 404];
        yield 'selects a deleted configuration' => [14, 503];
        yield 'selects a hidden configuration' => [15, 503];
        yield 'selects no configuration (not migrated)' => [16, 503];
    }

    #[DataProvider('unusableContentElements')]
    public function testUnusableContentElementsAreRejected(int $contentElementUid, int $expectedStatus): void
    {
        try {
            $this->resolver->resolve($contentElementUid);
            self::fail('A ChatRequestException was expected.');
        } catch (ChatRequestException $exception) {
            self::assertSame($expectedStatus, $exception->getStatusCode());
            self::assertStringNotContainsString('tx_easychat', $exception->getPublicMessage());
        }
    }
}
