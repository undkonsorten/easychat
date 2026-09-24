<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use Lochmueller\Index\Enums\IndexTechnology;
use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\FinishIndexProcessEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Symfony\AI\Store\Bridge\Qdrant\Store;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\AnonymousPageVisibility;
use Undkonsorten\Easychat\Indexing\IndexEventListener;
use Undkonsorten\Easychat\Indexing\IndexPointRegistry;
use Undkonsorten\Easychat\Indexing\QdrantPointRemover;
use Undkonsorten\Easychat\Indexing\RouteArgumentsResolver;
use Undkonsorten\Easychat\Indexing\VectorTarget;
use Undkonsorten\Easychat\Indexing\VectorTargetFactory;
use Undkonsorten\Easychat\Tests\Fixtures\Indexing\FakeVectorizer;

require_once __DIR__ . '/../../Fixtures/Indexing/FakeVectorizer.php';

/**
 * Re-indexing against a real Qdrant, in a throwaway collection. Only the embeddings
 * are faked, so no LLM API key is needed. Skipped when Qdrant is not reachable; set
 * EASYCHAT_TEST_QDRANT_URL to point it somewhere other than the DDEV service.
 */
#[RequiresMethod(IndexPageEvent::class, '__construct')]
final class QdrantReindexTest extends FunctionalTestCase
{
    private const DIMENSIONS = 4;
    /** Linked to configuration uid 1 (removal sync off), see ReindexConfigurations.csv */
    private const INDEX_CONFIGURATION_WITHOUT_SYNC = 7;
    /** Linked to configuration uid 2 (removal sync on) */
    private const INDEX_CONFIGURATION_WITH_SYNC = 8;

    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private int $indexConfiguration = self::INDEX_CONFIGURATION_WITHOUT_SYNC;

    private HttpClientInterface $httpClient;
    private string $url;
    private string $collection;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReindexConfigurations.csv');
        $this->httpClient = HttpClient::create();
        $this->url = rtrim((string)(getenv('EASYCHAT_TEST_QDRANT_URL') ?: 'http://qdrant:6333'), '/');
        $this->collection = 'easychat_test_' . bin2hex(random_bytes(6));

        try {
            $this->httpClient->request('GET', $this->url . '/collections', ['timeout' => 2])->getStatusCode();
        } catch (ExceptionInterface $exception) {
            self::markTestSkipped('Qdrant not reachable at ' . $this->url . ': ' . $exception->getMessage());
        }

        $this->store = new Store($this->httpClient, $this->url, '', $this->collection, self::DIMENSIONS, 'Dot');
        $this->store->setup();
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            $this->store->drop();
        }
        parent::tearDown();
    }

    public function testReindexingAChangedContentElementUpdatesItsPointInPlace(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->createPageEvent('run-1', 10, 'The mascot is called Pixelotl.'));
        $before = $this->scroll();

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'The mascot is called Axel.'));
        $after = $this->scroll();

        self::assertSame(array_keys($before), array_keys($after));
        self::assertSame(['The mascot is called Axel.'], array_column($after, '_text'));
    }

    public function testShrinkingAContentElementRemovesItsLeftoverChunks(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->createPageEvent('run-1', 10, str_repeat('Long original text. ', 150)));
        self::assertGreaterThan(1, \count($this->scroll()), 'precondition: long content is split into several chunks');

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Short replacement.'));

        self::assertSame(['Short replacement.'], array_column($this->scroll(), '_text'));
    }

    public function testFullRunRemovesContentNoLongerIndexedWhenRemovalSyncIsEnabled(): void
    {
        $listener = $this->createListener(syncRemovals: true);
        $listener->onIndexPage($this->createPageEvent('run-1', 10, 'Kept element.'));
        $listener->onIndexPage($this->createPageEvent('run-1', 11, 'Removed element.'));

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess(new FinishIndexProcessEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: IndexType::Full,
            indexConfigurationRecordId: $this->indexConfiguration,
            indexProcessId: 'run-2',
            endTime: microtime(true),
        ));

        self::assertSame(['Kept element.'], array_column($this->scroll(), '_text'));
    }

    /**
     * @return array<string, array<string, mixed>> payload by point id
     */
    private function scroll(): array
    {
        $response = $this->httpClient->request('POST', sprintf('%s/collections/%s/points/scroll', $this->url, $this->collection), [
            'json' => ['limit' => 100, 'with_payload' => true, 'with_vector' => false],
        ])->toArray();

        $points = [];
        foreach ($response['result']['points'] as $point) {
            $points[$point['id']] = $point['payload'];
        }
        ksort($points);

        return $points;
    }

    private function createListener(bool $syncRemovals = false): IndexEventListener
    {
        $this->indexConfiguration = $syncRemovals ? self::INDEX_CONFIGURATION_WITH_SYNC : self::INDEX_CONFIGURATION_WITHOUT_SYNC;

        $factory = $this->createStub(VectorTargetFactory::class);
        $factory->method('create')->willReturn(new VectorTarget(
            $this->store,
            new FakeVectorizer(self::DIMENSIONS),
            new QdrantPointRemover($this->httpClient, $this->url, '', $this->collection),
        ));

        $connectionPool = $this->get(ConnectionPool::class);

        return new IndexEventListener(
            $connectionPool,
            $factory,
            new IndexPointRegistry($connectionPool),
            new RouteArgumentsResolver($this->get(SiteMatcher::class)),
            $this->createConfiguredStub(AnonymousPageVisibility::class, ['isVisible' => true]),
        );
    }

    private function createPageEvent(string $processId, int $contentUid, string $content): IndexPageEvent
    {
        return new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: IndexType::Full,
            indexConfigurationRecordId: $this->indexConfiguration,
            indexProcessId: $processId,
            language: 0,
            title: 'Page 1',
            content: $content,
            pageUid: 1,
            accessGroups: [],
            uri: 'https://example.org/page-1#c' . $contentUid,
        );
    }

    private function createSiteStub(): SiteInterface
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('main');

        return $site;
    }
}
