<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\IndexPointRegistry;

/**
 * The bookkeeping IndexEventListener relies on to find out which points to delete.
 * IndexEventListenerReindexTest covers it through the listener; this pins down each
 * lookup on its own, in particular that it never reaches into another owner's rows.
 */
final class IndexPointRegistryTest extends FunctionalTestCase
{
    private const CONFIGURATION = 1;
    private const OTHER_CONFIGURATION = 2;
    private const INDEX_CONFIGURATION = 10;
    private const OTHER_INDEX_CONFIGURATION = 20;

    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private IndexPointRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new IndexPointRegistry($this->get(ConnectionPool::class));
    }

    public function testRecordStoresOneRowPerPoint(): void
    {
        $this->record('doc-1', ['p1', 'p2'], 'run-1', pageUid: 5, language: 1);

        $rows = $this->get(ConnectionPool::class)->getConnectionForTable('tx_easychat_index_point')
            ->select(['configuration', 'index_configuration', 'point_id', 'document_id', 'index_process', 'kind', 'page_uid', 'language'], 'tx_easychat_index_point', [], [], ['point_id' => 'ASC'])
            ->fetchAllAssociative();

        self::assertSame([
            ['configuration' => 1, 'index_configuration' => 10, 'point_id' => 'p1', 'document_id' => 'doc-1', 'index_process' => 'run-1', 'kind' => 'page', 'page_uid' => 5, 'language' => 1],
            ['configuration' => 1, 'index_configuration' => 10, 'point_id' => 'p2', 'document_id' => 'doc-1', 'index_process' => 'run-1', 'kind' => 'page', 'page_uid' => 5, 'language' => 1],
        ], $rows);
    }

    public function testRecordingAPointAgainReplacesItsRow(): void
    {
        $this->record('doc-1', ['p1'], 'run-1');
        $this->record('doc-1', ['p1'], 'run-2');

        self::assertSame(1, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION));
        self::assertSame(0, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1'));
        self::assertSame(1, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-2'));
    }

    public function testRecordingAPointAgainLeavesAnotherIndexConfigurationsRowAlone(): void
    {
        $this->record('doc-1', ['p1'], 'run-1');
        $this->record('doc-1', ['p1'], 'run-1', indexConfiguration: self::OTHER_INDEX_CONFIGURATION);

        self::assertSame(1, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION));
        self::assertSame(1, $this->registry->countTracked(self::CONFIGURATION, self::OTHER_INDEX_CONFIGURATION));
    }

    public function testRecordingNoPointsIsANoOp(): void
    {
        $this->record('doc-1', ['p1'], 'run-1');
        $this->record('doc-1', [], 'run-2');

        self::assertSame(1, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1'));
    }

    public function testFindStaleChunksReturnsTheDocumentsPointsThatAreNotKept(): void
    {
        $this->record('doc-1', ['p1', 'p2', 'p3'], 'run-1');
        $this->record('doc-2', ['p4'], 'run-1');

        $stale = $this->registry->findStaleChunks(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'doc-1', ['p1']);

        self::assertEqualsCanonicalizing(['p2', 'p3'], $stale);
    }

    public function testFindStaleChunksWithoutKeptIdsReturnsAllPointsOfTheDocument(): void
    {
        $this->record('doc-1', ['p1', 'p2'], 'run-1');
        $this->record('doc-2', ['p3'], 'run-1');

        $stale = $this->registry->findStaleChunks(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'doc-1', []);

        self::assertEqualsCanonicalizing(['p1', 'p2'], $stale);
    }

    public function testCountsCanBeRestrictedToAKind(): void
    {
        $this->record('page-doc', ['p1', 'p2'], 'run-1', IndexPointRegistry::KIND_PAGE);
        $this->record('file-doc', ['f1'], 'run-1', IndexPointRegistry::KIND_FILE);
        $this->record('old-file-doc', ['f2'], 'run-0', IndexPointRegistry::KIND_FILE);

        self::assertSame(3, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1'));
        self::assertSame(2, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1', IndexPointRegistry::KIND_PAGE));
        self::assertSame(1, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1', IndexPointRegistry::KIND_FILE));
        self::assertSame(4, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION));
        self::assertSame(2, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION, IndexPointRegistry::KIND_FILE));
    }

    public function testPagesOfProcessListsEachPageAndLanguageOnceAndIgnoresFiles(): void
    {
        $this->record('doc-1', ['p1', 'p2'], 'run-1', pageUid: 5, language: 0);
        $this->record('doc-2', ['p3'], 'run-1', pageUid: 5, language: 0);
        $this->record('doc-3', ['p4'], 'run-1', pageUid: 5, language: 1);
        $this->record('doc-4', ['p5'], 'run-1', pageUid: 6, language: 0);
        $this->record('doc-5', ['p6'], 'run-2', pageUid: 7, language: 0);
        $this->record('file', ['f1'], 'run-1', IndexPointRegistry::KIND_FILE, pageUid: 8);

        $pages = $this->registry->pagesOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1');

        self::assertEqualsCanonicalizing([[5, 0], [5, 1], [6, 0]], $pages);
    }

    public function testFindNotInProcessReturnsPointsOtherRunsWrote(): void
    {
        $this->record('doc-1', ['p1'], 'run-1');
        $this->record('doc-2', ['p2'], 'run-2');
        $this->record('file', ['f1'], 'run-1', IndexPointRegistry::KIND_FILE);

        self::assertEqualsCanonicalizing(['p1', 'f1'], $this->registry->findNotInProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-2'));
        self::assertSame(['f1'], $this->registry->findNotInProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-2', IndexPointRegistry::KIND_FILE));
    }

    public function testFindNotInProcessCanBeRestrictedToPagesAndLanguages(): void
    {
        $this->record('doc-1', ['p1'], 'run-1', pageUid: 5, language: 0);
        $this->record('doc-2', ['p2'], 'run-1', pageUid: 5, language: 1);
        $this->record('doc-3', ['p3'], 'run-1', pageUid: 6, language: 0);
        $this->record('doc-4', ['p4'], 'run-1', pageUid: 7, language: 0);

        $notInProcess = $this->registry->findNotInProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-2', IndexPointRegistry::KIND_PAGE, [[5, 0], [6, 0]]);

        self::assertEqualsCanonicalizing(['p1', 'p3'], $notInProcess);
    }

    public function testFindOnPageGroupsThePagesPointsByIndexConfiguration(): void
    {
        $this->record('doc-1', ['p1', 'p2'], 'run-1', pageUid: 5, language: 0);
        $this->record('doc-1', ['p3'], 'run-1', pageUid: 5, language: 0, indexConfiguration: self::OTHER_INDEX_CONFIGURATION);
        $this->record('doc-2', ['p4'], 'run-1', pageUid: 5, language: 1);
        $this->record('file', ['f1'], 'run-1', IndexPointRegistry::KIND_FILE, pageUid: 5, language: 0);

        $onPage = $this->registry->findOnPage(self::CONFIGURATION, 5, 0);
        ksort($onPage);
        sort($onPage[self::INDEX_CONFIGURATION]);

        self::assertSame([self::INDEX_CONFIGURATION => ['p1', 'p2'], self::OTHER_INDEX_CONFIGURATION => ['p3']], $onPage);
    }

    public function testReferencedElsewhereOnlyCountsOtherIndexConfigurationsOfTheSameStore(): void
    {
        $this->record('doc-1', ['p1', 'p2', 'p3'], 'run-1');
        $this->record('doc-1', ['p1'], 'run-1', indexConfiguration: self::OTHER_INDEX_CONFIGURATION);
        $this->record('doc-1', ['p2'], 'run-1', configuration: self::OTHER_CONFIGURATION, indexConfiguration: self::OTHER_INDEX_CONFIGURATION);

        self::assertSame(['p1'], $this->registry->referencedElsewhere(self::CONFIGURATION, self::INDEX_CONFIGURATION, ['p1', 'p2', 'p3']));
    }

    public function testReferencedElsewhereHandlesMoreIdsThanFitIntoOneQuery(): void
    {
        $ids = $this->pointIds(1200);
        $this->record('doc-1', $ids, 'run-1');
        $this->record('doc-1', $ids, 'run-1', indexConfiguration: self::OTHER_INDEX_CONFIGURATION);

        $referenced = $this->registry->referencedElsewhere(self::CONFIGURATION, self::INDEX_CONFIGURATION, $ids);

        self::assertEqualsCanonicalizing($ids, $referenced);
    }

    public function testReferencedElsewhereWithoutIdsReturnsNothing(): void
    {
        self::assertSame([], $this->registry->referencedElsewhere(self::CONFIGURATION, self::INDEX_CONFIGURATION, []));
    }

    public function testForgetRemovesOnlyTheOwnersRows(): void
    {
        $this->record('doc-1', ['p1', 'p2'], 'run-1');
        $this->record('doc-1', ['p1'], 'run-1', indexConfiguration: self::OTHER_INDEX_CONFIGURATION);
        $this->record('doc-1', ['p1'], 'run-1', configuration: self::OTHER_CONFIGURATION);

        $this->registry->forget(self::CONFIGURATION, self::INDEX_CONFIGURATION, ['p1']);

        self::assertSame(['p2'], $this->registry->findStaleChunks(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'doc-1', []));
        self::assertSame(1, $this->registry->countTracked(self::CONFIGURATION, self::OTHER_INDEX_CONFIGURATION));
        self::assertSame(1, $this->registry->countTracked(self::OTHER_CONFIGURATION, self::INDEX_CONFIGURATION));
    }

    public function testForgetHandlesMoreIdsThanFitIntoOneQuery(): void
    {
        $ids = $this->pointIds(1200);
        $this->record('doc-1', $ids, 'run-1');

        $this->registry->forget(self::CONFIGURATION, self::INDEX_CONFIGURATION, $ids);

        self::assertSame(0, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION));
    }

    public function testLookupsNeverReachIntoAnotherConfigurationOrIndexConfiguration(): void
    {
        $this->record('doc-1', ['other-store'], 'run-1', configuration: self::OTHER_CONFIGURATION);
        $this->record('doc-1', ['other-index'], 'run-1', indexConfiguration: self::OTHER_INDEX_CONFIGURATION);

        self::assertSame([], $this->registry->findStaleChunks(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'doc-1', []));
        self::assertSame(0, $this->registry->countTracked(self::CONFIGURATION, self::INDEX_CONFIGURATION));
        self::assertSame(0, $this->registry->countOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1'));
        self::assertSame([], $this->registry->pagesOfProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-1'));
        self::assertSame([], $this->registry->findNotInProcess(self::CONFIGURATION, self::INDEX_CONFIGURATION, 'run-2'));
        self::assertSame([self::OTHER_INDEX_CONFIGURATION => ['other-index']], $this->registry->findOnPage(self::CONFIGURATION, 1, 0));
    }

    /**
     * @param string[] $pointIds
     */
    private function record(
        string $documentId,
        array $pointIds,
        string $indexProcess,
        string $kind = IndexPointRegistry::KIND_PAGE,
        int $pageUid = 1,
        int $language = 0,
        int $configuration = self::CONFIGURATION,
        int $indexConfiguration = self::INDEX_CONFIGURATION,
    ): void {
        $this->registry->record($configuration, $indexConfiguration, $documentId, $pointIds, $indexProcess, $kind, $pageUid, $language);
    }

    /**
     * @return string[]
     */
    private function pointIds(int $count): array
    {
        return array_map(static fn (int $i): string => sprintf('point-%04d', $i), range(1, $count));
    }
}
