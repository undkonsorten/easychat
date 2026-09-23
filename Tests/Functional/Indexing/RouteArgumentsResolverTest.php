<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\RouteArgumentsResolver;

/**
 * Point ids are seeded from what a uri resolves to, so this is what keeps them stable
 * across slug changes and reordered query strings.
 */
final class RouteArgumentsResolverTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private RouteArgumentsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/RoutingPages.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.org/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                    'enabled' => true,
                ],
                [
                    'languageId' => 1,
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/de/',
                    'enabled' => true,
                ],
            ],
        ]);
        $this->resolver = new RouteArgumentsResolver($this->get(SiteMatcher::class));
    }

    public function testResolvesThePageAndLanguageOfAUri(): void
    {
        self::assertSame(
            ['pageUid' => 2, 'language' => 0, 'arguments' => ''],
            $this->resolver->resolve('https://example.org/page-two'),
        );
    }

    public function testResolvesATranslatedPageToItsDefaultLanguagePage(): void
    {
        self::assertSame(
            ['pageUid' => 2, 'language' => 1, 'arguments' => ''],
            $this->resolver->resolve('https://example.org/de/seite-zwei'),
        );
    }

    public function testResolvesTheSiteRoot(): void
    {
        self::assertSame(
            ['pageUid' => 1, 'language' => 0, 'arguments' => ''],
            $this->resolver->resolve('https://example.org/'),
        );
    }

    public function testIgnoresTheFragment(): void
    {
        self::assertSame(
            ['pageUid' => 2, 'language' => 0, 'arguments' => ''],
            $this->resolver->resolve('https://example.org/page-two#c10'),
        );
    }

    public function testArgumentsAreSortedAndWithoutCacheHash(): void
    {
        $resolved = $this->resolver->resolve('https://example.org/page-two?b=1&a[z]=1&a[y]=2&cHash=0123456789abcdef');

        self::assertSame('a%5By%5D=2&a%5Bz%5D=1&b=1', $resolved['arguments'] ?? null);
    }

    public function testTheOrderOfQueryArgumentsDoesNotMatter(): void
    {
        $resolved = $this->resolver->resolve('https://example.org/page-two?tx_news[news]=5&tx_news[action]=detail&cHash=aaa');

        self::assertNotNull($resolved);
        self::assertSame($resolved, $this->resolver->resolve('https://example.org/page-two?tx_news[action]=detail&tx_news[news]=5&cHash=bbb'));
    }

    public static function unroutableUris(): array
    {
        return [
            'empty uri' => [''],
            'unknown host' => ['https://unknown.invalid/page-two'],
            'unknown slug' => ['https://example.org/does-not-exist'],
            'deleted page' => ['https://example.org/deleted'],
            'not a uri' => ['://'],
        ];
    }

    #[DataProvider('unroutableUris')]
    public function testUnroutableUrisResolveToNull(string $uri): void
    {
        self::assertNull($this->resolver->resolve($uri));
    }
}
