<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use Lochmueller\Index\Enums\IndexTechnology;
use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\FinishIndexProcessEvent;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Symfony\AI\Store\Document\VectorizerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\IndexEventListener;
use Undkonsorten\Easychat\Indexing\IndexPointRegistry;
use Undkonsorten\Easychat\Indexing\VectorTarget;
use Undkonsorten\Easychat\Indexing\VectorTargetFactory;
use Undkonsorten\Easychat\Tests\Fixtures\Indexing\FakeVectorizer;
use Undkonsorten\Easychat\Tests\Fixtures\Indexing\InMemoryPointStore;

require_once __DIR__ . '/../../Fixtures/Indexing/FakeVectorizer.php';
require_once __DIR__ . '/../../Fixtures/Indexing/InMemoryPointStore.php';

/**
 * What an editor's change to a content element does to the vector store once it is
 * re-indexed: updated in place, shrunk without leftovers, and — only with
 * vector_db_sync_removals — removed when it is no longer indexed at all.
 *
 * Runs against an in-memory store with a real IndexPointRegistry, which is all the
 * cleanup relies on; QdrantReindexTest covers the same against a real Qdrant.
 */
final class IndexEventListenerReindexTest extends FunctionalTestCase
{
    /** Linked to configuration uid 1 (removal sync off), see ReindexConfigurations.csv */
    private const INDEX_CONFIGURATION_WITHOUT_SYNC = 7;
    /** Linked to configuration uid 2 (removal sync on) */
    private const INDEX_CONFIGURATION_WITH_SYNC = 8;

    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private InMemoryPointStore $store;

    private int $indexConfiguration = self::INDEX_CONFIGURATION_WITHOUT_SYNC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReindexConfigurations.csv');
        $this->store = new InMemoryPointStore();
    }

    public function testReindexingAChangedContentElementUpdatesItsPointInPlace(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->createPageEvent('run-1', 10, 'The mascot is called Pixelotl.'));
        $idBefore = array_keys($this->store->points());

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'The mascot is called Axel.'));

        self::assertSame($idBefore, array_keys($this->store->points()));
        self::assertSame(['The mascot is called Axel.'], $this->store->textsOf('#c10'));
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testShrinkingAContentElementRemovesItsLeftoverChunks(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->createPageEvent('run-1', 10, str_repeat('Long original text. ', 150)));
        self::assertGreaterThan(1, \count($this->store->textsOf('#c10')), 'precondition: long content is split into several chunks');

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Short replacement.'));

        self::assertSame(['Short replacement.'], $this->store->textsOf('#c10'));
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testReindexingOneContentElementLeavesTheOthersOfThePageAlone(): void
    {
        $listener = $this->createListener();

        $listener->onIndexPage($this->createPageEvent('run-1', 10, str_repeat('First element. ', 150)));
        $listener->onIndexPage($this->createPageEvent('run-1', 11, 'Second element.'));

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'First element, edited.'));

        self::assertSame(['First element, edited.'], $this->store->textsOf('#c10'));
        self::assertSame(['Second element.'], $this->store->textsOf('#c11'));
    }

    public function testContentNoLongerIndexedIsKeptWhenRemovalSyncIsDisabled(): void
    {
        $listener = $this->createListener(syncRemovals: false);
        $this->indexTwoElements($listener, 'run-1');

        // #c11 was deleted or hidden, so the next full run no longer emits it.
        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->createFinishEvent('run-2', IndexType::Full));

        self::assertSame(['Removed element.'], $this->store->textsOf('#c11'));
    }

    public function testFullRunRemovesContentNoLongerIndexedWhenRemovalSyncIsEnabled(): void
    {
        $listener = $this->createListener(syncRemovals: true);
        $this->indexTwoElements($listener, 'run-1');
        $listener->onIndexFile($this->createFileEvent('run-1'));

        $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->createFinishEvent('run-2', IndexType::Full));

        self::assertSame(['Kept element.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame([], $this->store->textsOf('manual.pdf'), 'a file the full run no longer emits is removed too');
        self::assertSame(1, $this->countRegisteredPoints());
    }

    public function testPartialRunOnlyRemovesContentOfThePagesItReindexed(): void
    {
        $listener = $this->createListener(syncRemovals: true);
        $this->indexTwoElements($listener, 'run-1');
        $listener->onIndexPage($this->createPageEvent('run-1', 20, 'Element on another page.', pageUid: 2));
        $listener->onIndexFile($this->createFileEvent('run-1'));

        // Saving page 1 re-indexes only page 1; #c11 is gone from it.
        $listener->onIndexPage($this->createPageEvent('save-1', 10, 'Kept element.'));
        $listener->onFinishIndexProcess($this->createFinishEvent('save-1', IndexType::Partial));

        self::assertSame(['Kept element.'], $this->store->textsOf('#c10'));
        self::assertSame([], $this->store->textsOf('#c11'));
        self::assertSame(['Element on another page.'], $this->store->textsOf('#c20'));
        self::assertSame(['Manual text.'], $this->store->textsOf('manual.pdf'));
    }

    public function testNothingIsRemovedAfterARunThatFailedToWrite(): void
    {
        $failingVectorizer = $this->createStub(VectorizerInterface::class);
        $failingVectorizer->method('vectorize')->willThrowException(new \RuntimeException('embeddings API down'));

        $this->indexTwoElements($this->createListener(syncRemovals: true), 'run-1');

        $listener = $this->createListener(syncRemovals: true, vectorizer: $failingVectorizer);
        try {
            $listener->onIndexPage($this->createPageEvent('run-2', 10, 'Kept element.'));
            self::fail('The vectorizer exception must not be swallowed.');
        } catch (\RuntimeException) {
            // EXT:index logs it and carries on to the finish event.
        }
        $listener->onFinishIndexProcess($this->createFinishEvent('run-2', IndexType::Full));

        self::assertCount(2, $this->store->points());
    }

    public function testNothingIsRemovedAfterAFullRunThatWroteNothing(): void
    {
        $listener = $this->createListener(syncRemovals: true);
        $this->indexTwoElements($listener, 'run-1');

        $listener->onFinishIndexProcess($this->createFinishEvent('run-2', IndexType::Full));

        self::assertCount(2, $this->store->points());
    }

    private function indexTwoElements(IndexEventListener $listener, string $processId): void
    {
        $listener->onIndexPage($this->createPageEvent($processId, 10, 'Kept element.'));
        $listener->onIndexPage($this->createPageEvent($processId, 11, 'Removed element.'));
    }

    private function countRegisteredPoints(): int
    {
        return $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_easychat_index_point')
            ->count('*', 'tx_easychat_index_point', []);
    }

    private function createListener(bool $syncRemovals = false, ?VectorizerInterface $vectorizer = null): IndexEventListener
    {
        $this->indexConfiguration = $syncRemovals ? self::INDEX_CONFIGURATION_WITH_SYNC : self::INDEX_CONFIGURATION_WITHOUT_SYNC;

        $factory = $this->createStub(VectorTargetFactory::class);
        $factory->method('create')->willReturn(new VectorTarget($this->store, $vectorizer ?? new FakeVectorizer(), $this->store));

        $connectionPool = $this->get(ConnectionPool::class);

        return new IndexEventListener($connectionPool, $factory, new IndexPointRegistry($connectionPool));
    }

    private function createPageEvent(string $processId, int $contentUid, string $content, int $pageUid = 1): IndexPageEvent
    {
        return new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: IndexType::Full,
            indexConfigurationRecordId: $this->indexConfiguration,
            indexProcessId: $processId,
            language: 0,
            title: 'Page ' . $pageUid,
            content: $content,
            pageUid: $pageUid,
            accessGroups: [],
            uri: sprintf('https://example.org/page-%d#c%d', $pageUid, $contentUid),
        );
    }

    private function createFileEvent(string $processId): IndexFileEvent
    {
        return new IndexFileEvent(
            site: $this->createSiteStub(),
            indexConfigurationRecordId: $this->indexConfiguration,
            indexProcessId: $processId,
            title: 'Manual',
            content: 'Manual text.',
            fileIdentifier: '1:/manual.pdf',
            uri: 'https://example.org/fileadmin/manual.pdf',
        );
    }

    private function createFinishEvent(string $processId, IndexType $type): FinishIndexProcessEvent
    {
        return new FinishIndexProcessEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: $type,
            indexConfigurationRecordId: $this->indexConfiguration,
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
