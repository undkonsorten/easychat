<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use Lochmueller\Index\Enums\IndexTechnology;
use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\DeIndexDocumentEvent;
use Lochmueller\Index\Event\FinishIndexProcessEvent;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Symfony\AI\Store\Document\VectorizerInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\AnonymousPageVisibility;
use Undkonsorten\Easychat\Indexing\IndexEventListener;
use Undkonsorten\Easychat\Indexing\IndexPointRegistry;
use Undkonsorten\Easychat\Indexing\RouteArgumentsResolver;
use Undkonsorten\Easychat\Indexing\VectorTarget;
use Undkonsorten\Easychat\Indexing\VectorTargetFactory;
use Undkonsorten\Easychat\Tests\Fixtures\Indexing\FakeVectorizer;
use Undkonsorten\Easychat\Tests\Fixtures\Indexing\InMemoryPointStore;

require_once __DIR__ . '/../../Fixtures/Indexing/FakeVectorizer.php';
require_once __DIR__ . '/../../Fixtures/Indexing/InMemoryPointStore.php';

/**
 * What an editor's change does to the vector store once EXT:index re-indexes it, for each
 * way EXT:index delivers content: updated in place, shrunk without leftovers, and — only
 * with vector_db_sync_removals — removed when it is no longer indexed at all.
 *
 * Runs against an in-memory store with a real IndexPointRegistry, which is all the cleanup
 * relies on; QdrantReindexTest covers the basics against a real Qdrant.
 *
 * Configurations (ReindexConfigurations.csv), by the index configuration they are linked to:
 * 7 → uid 1, removal sync off · 8 → uid 2, sync on, no threshold · 9 and 10 → uid 3, sync on,
 * no threshold (one store fed by two index configurations) · 11 → uid 4, sync on, threshold 25%.
 */
final class IndexEventListenerReindexTest extends FunctionalTestCase
{
    private const WITHOUT_SYNC = 7;
    private const WITH_SYNC = 8;
    private const SHARED_A = 9;
    private const SHARED_B = 10;
    private const WITH_THRESHOLD = 11;

    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private InMemoryPointStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReindexConfigurations.csv');
        $this->store = new InMemoryPointStore();
    }

    public function testReindexingAChangedContentElementUpdatesItsPointInPlace(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, 'The mascot is called Pixelotl.'));
        $idBefore = array_keys($this->store->points());

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-2', 10, 'The mascot is called Axel.'));

        self::assertSame($idBefore, array_keys($this->store->points()));
        self::assertSame(['The mascot is called Axel.'], $this->store->textsOf('#c10'));
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testShrinkingAContentElementRemovesItsLeftoverChunks(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, str_repeat('Long original text. ', 150)));
        self::assertGreaterThan(1, \count($this->store->textsOf('#c10')), 'precondition: long content is split into several chunks');

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-2', 10, 'Short replacement.'));

        self::assertSame(['Short replacement.'], $this->store->textsOf('#c10'));
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testReindexingOneContentElementLeavesTheOthersOfThePageAlone(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, str_repeat('First element. ', 150)));
        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 11, 'Second element.'));

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-2', 10, 'First element, edited.'));

        self::assertSame(['First element, edited.'], $this->store->textsOf('#c10'));
        self::assertSame(['Second element.'], $this->store->textsOf('#c11'));
    }

    public function testChangingThePageSlugKeepsThePointIds(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReindexPages.csv');
        $this->writeSiteConfiguration();
        $listener = $this->createListener();

        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, 'Before the slug change.', pageUid: 2, uri: 'https://example.org/page-two#c10'));
        $idBefore = array_keys($this->store->points());

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['slug' => '/renamed'], ['uid' => 2]);
        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-2', 10, 'After the slug change.', pageUid: 2, uri: 'https://example.org/renamed#c10'));

        self::assertSame($idBefore, array_keys($this->store->points()));
        self::assertSame(['After the slug change.'], $this->store->textsOf('#c10'));
    }

    public function testContentNoLongerIndexedIsKeptWhenRemovalSyncIsDisabled(): void
    {
        $listener = $this->createListener();
        $this->indexTwoElements($listener, self::WITHOUT_SYNC, 'run-1');

        // #c11 was deleted or hidden, so the next full run no longer emits it.
        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITHOUT_SYNC, 'run-2', IndexType::Full));

        self::assertSame(['Removed element.'], $this->store->textsOf('#c11'));
    }

    public function testFullRunRemovesContentNoLongerIndexedWhenRemovalSyncIsEnabled(): void
    {
        $listener = $this->createListener();
        $this->indexTwoElements($listener, self::WITH_SYNC, 'run-1');
        $listener->onIndexFile($this->fileEvent(self::WITH_SYNC, 'run-1'));

        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'run-2', IndexType::Full));

        self::assertSame(['Kept element.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame([], $this->store->textsOf('manual.pdf'), 'a file the full run no longer emits is removed too');
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testPartialRunOnlyRemovesContentOfThePagesItReindexed(): void
    {
        $listener = $this->createListener();
        $this->indexTwoElements($listener, self::WITH_SYNC, 'run-1');
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-1', 20, 'Element on another page.', pageUid: 2));
        $listener->onIndexFile($this->fileEvent(self::WITH_SYNC, 'run-1'));

        // Saving page 1 re-indexes only page 1 (DataHandler partial runs skip files); #c11 is gone from it.
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'save-1', 10, 'Kept element.', type: IndexType::Partial));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'save-1', IndexType::Partial));

        self::assertSame(['Kept element.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame(['Element on another page.'], $this->store->textsOf('#c20'));
        self::assertSame(['Manual text.'], $this->store->textsOf('manual.pdf'));
    }

    public function testCachingAPageInOneLanguageLeavesItsOtherLanguagesAlone(): void
    {
        $listener = $this->createListener();
        $listener->onIndexPage($this->cachePageEvent('cache-en', 0, 'English page.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'cache-en', IndexType::Partial, IndexTechnology::Cache));
        $listener->onIndexPage($this->cachePageEvent('cache-de', 1, 'Deutsche Seite.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'cache-de', IndexType::Partial, IndexTechnology::Cache));

        $listener->onIndexPage($this->cachePageEvent('cache-de-2', 1, 'Deutsche Seite, geändert.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'cache-de-2', IndexType::Partial, IndexTechnology::Cache));

        self::assertSame(['English page.'], $this->store->textsMatching(['language' => 0]));
        self::assertSame(['Deutsche Seite, geändert.'], $this->store->textsMatching(['language' => 1]));
    }

    public function testCacheRunsRemoveFilesNoLongerEmitted(): void
    {
        $listener = $this->createListener();
        // The Cache technology emits every file of the configuration with each page it caches.
        $listener->onIndexPage($this->cachePageEvent('cache-1', 0, 'Page.'));
        $listener->onIndexFile($this->fileEvent(self::WITH_SYNC, 'cache-1', 'manual.pdf'));
        $listener->onIndexFile($this->fileEvent(self::WITH_SYNC, 'cache-1', 'deleted.pdf'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'cache-1', IndexType::Partial, IndexTechnology::Cache));

        $listener->onIndexPage($this->cachePageEvent('cache-2', 0, 'Page.'));
        $listener->onIndexFile($this->fileEvent(self::WITH_SYNC, 'cache-2', 'manual.pdf'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'cache-2', IndexType::Partial, IndexTechnology::Cache));

        self::assertSame(['Manual text.'], $this->store->textsMatching(['fileIdentifier' => '1:/manual.pdf']));
        self::assertSame([], $this->store->textsMatching(['fileIdentifier' => '1:/deleted.pdf']));
    }

    public function testAFileSharedByTwoIndexConfigurationsStaysUntilNeitherIndexesIt(): void
    {
        $listener = $this->createListener();
        foreach ([self::SHARED_A, self::SHARED_B] as $indexConfiguration) {
            $listener->onIndexPage($this->pageEvent($indexConfiguration, 'run-1-' . $indexConfiguration, $indexConfiguration, 'Page of ' . $indexConfiguration . '.'));
            $listener->onIndexFile($this->fileEvent($indexConfiguration, 'run-1-' . $indexConfiguration));
        }

        // B's file mount no longer contains the file, A's still does.
        $listener->onIndexPage($this->pageEvent(self::SHARED_B, 'run-2-b', self::SHARED_B, 'Page of B.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::SHARED_B, 'run-2-b', IndexType::Full));
        self::assertSame(['Manual text.'], $this->store->textsOf('manual.pdf'), 'still referenced by the other index configuration');

        $listener->onIndexPage($this->pageEvent(self::SHARED_A, 'run-2-a', self::SHARED_A, 'Page of A.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::SHARED_A, 'run-2-a', IndexType::Full));
        self::assertSame([], $this->store->textsOf('manual.pdf'));
    }

    public function testAFullRunRemovingMoreThanTheThresholdRemovesNothing(): void
    {
        $listener = $this->createListener();
        foreach ([10, 11, 12, 13] as $contentUid) {
            $listener->onIndexPage($this->pageEvent(self::WITH_THRESHOLD, 'run-1', $contentUid, 'Element ' . $contentUid . '.'));
        }

        // Two of four pages failed to render and were silently skipped: 50% > 25%.
        $listener->onIndexPage($this->pageEvent(self::WITH_THRESHOLD, 'run-2', 10, 'Element 10.'));
        $listener->onIndexPage($this->pageEvent(self::WITH_THRESHOLD, 'run-2', 11, 'Element 11.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_THRESHOLD, 'run-2', IndexType::Full));
        self::assertCount(4, $this->store->points());

        // One of four was really removed: 25% is within the threshold.
        foreach ([10, 11, 12] as $contentUid) {
            $listener->onIndexPage($this->pageEvent(self::WITH_THRESHOLD, 'run-3', $contentUid, 'Element ' . $contentUid . '.'));
        }
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_THRESHOLD, 'run-3', IndexType::Full));
        self::assertSame([], $this->store->textsOf('#c13'));
        self::assertCount(3, $this->store->points());
    }

    public function testAPageFlaggedNoSearchIsRemovedWhereRemovalSyncIsEnabled(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReindexPages.csv');
        $this->writeSiteConfiguration();
        $listener = $this->createListener();
        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, 'Without sync.', pageUid: 2, uri: 'https://example.org/page-two#c10'));
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-1', 11, 'With sync.', pageUid: 2, uri: 'https://example.org/page-two#c11'));
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-1', 12, 'Other page.', pageUid: 1, uri: 'https://example.org/#c12'));

        $listener->onDeIndexDocument(new DeIndexDocumentEvent($this->createSiteStub(), 'https://example.org/page-two'));

        self::assertSame(['Without sync.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame(['Other page.'], $this->store->textsOf('#c12'));
    }

    public function testAHiddenOrScheduledPageIndexedOnSaveNeverReachesAStore(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PageVisibility.csv');
        $listener = $this->createListener(pageVisibility: new AnonymousPageVisibility($this->get(ConnectionPool::class)));

        // EXT:index emits a page saved on its own even if it is hidden (2) or scheduled (3).
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'save-1', 20, 'Hidden draft.', pageUid: 2, type: IndexType::Partial));
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'save-2', 30, 'Not yet published.', pageUid: 3, type: IndexType::Partial));

        self::assertSame([], $this->store->points());
        self::assertSame(0, $this->countRegisteredPoints());
    }

    public function testAPageBelowARestrictedSectionNeverReachesAStore(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PageVisibility.csv');
        $listener = $this->createListener(pageVisibility: new AnonymousPageVisibility($this->get(ConnectionPool::class)));

        // Page 6 has no fe_group of its own, so EXT:index reports it as public; its parent 5
        // restricts itself and its subpages.
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'save-1', 60, 'Members only.', pageUid: 6, type: IndexType::Partial));

        self::assertSame([], $this->store->points());
    }

    public function testHidingAnIndexedPageRemovesItWhereRemovalSyncIsEnabled(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PageVisibility.csv');
        $listener = $this->createListener(pageVisibility: new AnonymousPageVisibility($this->get(ConnectionPool::class)));
        $listener->onIndexPage($this->pageEvent(self::WITHOUT_SYNC, 'run-1', 10, 'Without sync.', pageUid: 16));
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-1', 11, 'With sync.', pageUid: 16));
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-1', 12, 'Other page.', pageUid: 1));

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 16]);
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'save-1', 11, 'With sync.', pageUid: 16, type: IndexType::Partial));

        self::assertSame(['Without sync.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame(['Other page.'], $this->store->textsOf('#c12'));
    }

    public function testExternalContentNeverReachesAStore(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->pageEvent(-1, 'external-1', 10, 'External page.', pageUid: -1));

        self::assertSame([], $this->store->points());
    }

    public function testFilesWithoutIdentifierAreToldApartByUri(): void
    {
        $listener = $this->createListener();

        $listener->onIndexFile($this->fileEvent(self::WITHOUT_SYNC, 'run-1', 'a.pdf', fileIdentifier: ''));
        $listener->onIndexFile($this->fileEvent(self::WITHOUT_SYNC, 'run-1', 'b.pdf', fileIdentifier: ''));

        self::assertCount(2, $this->store->points());
    }

    /**
     * With the Cache technology and a synchronous transport, indexing runs inside a visitor's
     * page request, and EXT:index only catches \Exception. A failure — here the TypeError
     * symfony/ai 0.1 throws on a rate limit — must therefore stay inside the listener.
     */
    public function testAFailedWriteNeitherEscapesNorLeadsToRemovals(): void
    {
        $failingVectorizer = $this->createStub(VectorizerInterface::class);
        $failingVectorizer->method('vectorize')->willThrowException(new \TypeError('RateLimitExceededException::__construct(): Argument #1 must be of type ?int, string given'));

        $this->indexTwoElements($this->createListener(), self::WITH_SYNC, 'run-1');

        $listener = $this->createListener($failingVectorizer);
        $listener->onIndexPage($this->pageEvent(self::WITH_SYNC, 'run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'run-2', IndexType::Full));

        self::assertCount(2, $this->store->points());
    }

    public function testNothingIsRemovedAfterAFullRunThatWroteNothing(): void
    {
        $listener = $this->createListener();
        $this->indexTwoElements($listener, self::WITH_SYNC, 'run-1');

        $listener->onFinishIndexProcess($this->finishEvent(self::WITH_SYNC, 'run-2', IndexType::Full));

        self::assertCount(2, $this->store->points());
    }

    private function indexTwoElements(IndexEventListener $listener, int $indexConfiguration, string $processId): void
    {
        $listener->onIndexPage($this->pageEvent($indexConfiguration, $processId, 10, 'Kept element.'));
        $listener->onIndexPage($this->pageEvent($indexConfiguration, $processId, 11, 'Removed element.'));
    }

    private function countRegisteredPoints(): int
    {
        return $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_easychat_index_point')
            ->count('*', 'tx_easychat_index_point', []);
    }

    private function writeSiteConfiguration(): void
    {
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
            ],
        ]);
    }

    private function createListener(?VectorizerInterface $vectorizer = null, ?AnonymousPageVisibility $pageVisibility = null): IndexEventListener
    {
        $factory = $this->createStub(VectorTargetFactory::class);
        $factory->method('create')->willReturn(new VectorTarget($this->store, $vectorizer ?? new FakeVectorizer(), $this->store));

        $connectionPool = $this->get(ConnectionPool::class);

        return new IndexEventListener(
            $connectionPool,
            $factory,
            new IndexPointRegistry($connectionPool),
            new RouteArgumentsResolver($this->get(SiteMatcher::class)),
            $pageVisibility ?? $this->createConfiguredStub(AnonymousPageVisibility::class, ['isVisible' => true]),
        );
    }

    private function pageEvent(int $indexConfiguration, string $processId, int $contentUid, string $content, int $pageUid = 1, ?string $uri = null, IndexType $type = IndexType::Full): IndexPageEvent
    {
        return new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: $type,
            indexConfigurationRecordId: $indexConfiguration,
            indexProcessId: $processId,
            language: 0,
            title: 'Page ' . $pageUid,
            content: $content,
            pageUid: $pageUid,
            accessGroups: [],
            uri: $uri ?? sprintf('https://example.invalid/page-%d#c%d', $pageUid, $contentUid),
        );
    }

    /**
     * What the Cache technology sends: always partial, one language, and no uri.
     */
    private function cachePageEvent(string $processId, int $language, string $content): IndexPageEvent
    {
        return new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Cache,
            type: IndexType::Partial,
            indexConfigurationRecordId: self::WITH_SYNC,
            indexProcessId: $processId,
            language: $language,
            title: 'Page 1',
            content: $content,
            pageUid: 1,
            accessGroups: [0, -1],
        );
    }

    private function fileEvent(int $indexConfiguration, string $processId, string $fileName = 'manual.pdf', ?string $fileIdentifier = null): IndexFileEvent
    {
        return new IndexFileEvent(
            site: $this->createSiteStub(),
            indexConfigurationRecordId: $indexConfiguration,
            indexProcessId: $processId,
            title: ucfirst(pathinfo($fileName, PATHINFO_FILENAME)),
            content: ucfirst(pathinfo($fileName, PATHINFO_FILENAME)) . ' text.',
            fileIdentifier: $fileIdentifier ?? '1:/' . $fileName,
            uri: 'https://example.invalid/fileadmin/' . $fileName,
        );
    }

    private function finishEvent(int $indexConfiguration, string $processId, IndexType $type, IndexTechnology $technology = IndexTechnology::Database): FinishIndexProcessEvent
    {
        return new FinishIndexProcessEvent(
            site: $this->createSiteStub(),
            technology: $technology,
            type: $type,
            indexConfigurationRecordId: $indexConfiguration,
            indexProcessId: $processId,
            endTime: microtime(true),
        );
    }

    private function createSiteStub(): SiteInterface
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('main');

        return $site;
    }
}
