<?php

namespace Undkonsorten\Easychat\Indexing;

use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\DeIndexDocumentEvent;
use Lochmueller\Index\Event\FinishIndexProcessEvent;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Bridges EXT:index's page/file crawl events into the vector store(s) configured
 * on tx_easychat_configuration records, so the SimilaritySearch tool used in
 * ChatReaction has content to retrieve.
 *
 * Chunk ids are deterministic per document (see pageSeed()), so re-indexing
 * overwrites existing chunks in place; chunks left over from a larger previous
 * version of the same document are deleted right after.
 *
 * Content that is no longer emitted at all (deleted, hidden, access restricted,
 * no_search, ...) is only removed when the configuration enables
 * vector_db_sync_removals: at the end of an index process, the points of that
 * index configuration not re-written by the process are deleted — all of them
 * after a full run, only those of the pages (and files) the process re-emitted
 * after a partial one.
 *
 * Which points exist is tracked in IndexPointRegistry, and deleting only needs a
 * delete-by-id, so none of this depends on a particular vector store. Points written
 * before the registry existed are unknown to it and never deleted; drop the
 * collection and re-index once to get rid of them.
 *
 * External content (EXT:index's webhook reactions) carries index configuration -1,
 * which no EasyChat configuration can reference, so it never reaches a store.
 */
class IndexEventListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE = 'tx_easychat_configuration';

    /** @var array<int, array<int, array<string, mixed>>> indexConfigurationRecordId => matching tx_easychat_configuration rows */
    private array $targetsByIndexConfiguration = [];

    /** @var array<int, VectorTarget> configuration uid => resolved store/vectorizer/remover */
    private array $resolved = [];

    /** @var array<string, true> index process ids in which writing to a store failed */
    private array $failedProcesses = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly VectorTargetFactory $vectorTargetFactory,
        private readonly IndexPointRegistry $registry,
        private readonly RouteArgumentsResolver $routeArgumentsResolver,
    ) {}

    #[AsEventListener(identifier: 'easychat/index-page')]
    public function onIndexPage(IndexPageEvent $event): void
    {
        // Retrieval applies no per-user filter, so everything in the store is answerable to
        // every chat user. Access restricted content therefore must not enter it at all.
        if (!self::isVisibleToAnonymousVisitor($event->accessGroups)) {
            return;
        }

        $this->handle(
            title: $event->title,
            content: $event->content,
            uri: $event->uri,
            indexConfigurationRecordId: $event->indexConfigurationRecordId,
            indexProcessId: $event->indexProcessId,
            kind: IndexPointRegistry::KIND_PAGE,
            pageUid: $event->pageUid,
            language: $event->language,
            idSeed: $this->pageSeed($event),
            extraMetadata: [
                'pageUid' => $event->pageUid,
                'language' => $event->language,
                // Always empty or [-1] by the check above. Stored so that a point can be
                // told apart from one written before access filtering existed.
                'accessGroups' => array_values($event->accessGroups),
            ],
        );
    }

    /**
     * Mirrors what TYPO3's FrontendGroupRestriction admits for a visitor without a login,
     * whose group ids are [0, -1]: either no restriction at all, or one that explicitly
     * includes "-1" (hide at login).
     *
     * What $accessGroups means depends on the technology, and the same rule is correct for
     * both readings. The database/frontend/http queues report the page's own fe_group. The
     * cache queue instead reports the group ids of the visitor whose request filled the
     * cache — a logged-in visitor's ids never contain -1, so content cached for a member is
     * skipped and only picked up again when a guest triggers the same page.
     *
     * @param int[] $accessGroups
     */
    private static function isVisibleToAnonymousVisitor(array $accessGroups): bool
    {
        return $accessGroups === [] || \in_array(-1, $accessGroups, true);
    }

    #[AsEventListener(identifier: 'easychat/index-file')]
    public function onIndexFile(IndexFileEvent $event): void
    {
        $this->handle(
            title: $event->title,
            content: $event->content,
            uri: $event->uri,
            indexConfigurationRecordId: $event->indexConfigurationRecordId,
            indexProcessId: $event->indexProcessId,
            kind: IndexPointRegistry::KIND_FILE,
            pageUid: 0,
            language: 0,
            // External files come without an identifier; their uri is all that tells them apart.
            idSeed: sprintf('file:%s:%s', $event->site->getIdentifier(), $event->fileIdentifier !== '' ? $event->fileIdentifier : $event->uri),
            extraMetadata: [
                'fileIdentifier' => $event->fileIdentifier,
            ],
        );
    }

    /**
     * Relies on EXT:index dispatching the finish message after all page/file messages of the
     * process, and on those being handled before it — true for the synchronous transport and
     * for a single messenger worker. A partial run on a page whose last content element was
     * removed emits no page event, so that page is only cleaned by the next full run.
     *
     * EXT:index logs and swallows exceptions, both its own (a page that fails to render or
     * fetch is simply not emitted) and ours (e.g. the embeddings API went down), so a run can
     * reach its end with current content never re-written. A process in which writing failed,
     * or that wrote nothing, is therefore not swept, and a sweep that is not limited to the
     * pages the process emitted is skipped when it would remove more than the configured
     * share (vector_db_sync_removals_threshold).
     */
    #[AsEventListener(identifier: 'easychat/index-finish')]
    public function onFinishIndexProcess(FinishIndexProcessEvent $event): void
    {
        $indexConfiguration = $event->indexConfigurationRecordId;
        if ($indexConfiguration === null) {
            return;
        }
        if (isset($this->failedProcesses[$event->indexProcessId])) {
            unset($this->failedProcesses[$event->indexProcessId]);
            return;
        }

        foreach ($this->getTargetConfigurations($indexConfiguration) as $configuration) {
            if (!(bool)($configuration['vector_db_sync_removals'] ?? false)) {
                continue;
            }

            $uid = (int)$configuration['uid'];
            if ($event->type === IndexType::Full) {
                if ($this->registry->countOfProcess($uid, $indexConfiguration, $event->indexProcessId) > 0) {
                    $this->sweepUnlessExcessive(
                        $configuration,
                        $indexConfiguration,
                        $this->registry->findNotInProcess($uid, $indexConfiguration, $event->indexProcessId),
                        $this->registry->countTracked($uid, $indexConfiguration),
                    );
                }
                continue;
            }

            // A partial run only re-emits what it was triggered for — for the Cache technology one
            // page in one language — so anything outside of that is still current. Only the pages
            // it emitted can be swept, which also means a page that failed to render is never in
            // scope.
            $pages = $this->registry->pagesOfProcess($uid, $indexConfiguration, $event->indexProcessId);
            if ($pages !== []) {
                $this->removePoints(
                    $configuration,
                    $indexConfiguration,
                    $this->registry->findNotInProcess($uid, $indexConfiguration, $event->indexProcessId, IndexPointRegistry::KIND_PAGE, $pages),
                );
            }

            // The Cache technology re-emits every file of the configuration with each page it
            // caches, so a partial run that emitted files emitted all of them.
            if ($this->registry->countOfProcess($uid, $indexConfiguration, $event->indexProcessId, IndexPointRegistry::KIND_FILE) > 0) {
                $this->sweepUnlessExcessive(
                    $configuration,
                    $indexConfiguration,
                    $this->registry->findNotInProcess($uid, $indexConfiguration, $event->indexProcessId, IndexPointRegistry::KIND_FILE),
                    $this->registry->countTracked($uid, $indexConfiguration, IndexPointRegistry::KIND_FILE),
                );
            }
        }
    }

    /**
     * EXT:index dispatches this when a page is flagged no_search (and its index configuration
     * skips such pages) — the one removal it announces explicitly.
     */
    #[AsEventListener(identifier: 'easychat/deindex-document')]
    public function onDeIndexDocument(DeIndexDocumentEvent $event): void
    {
        $page = $this->routeArgumentsResolver->resolve($event->uri);
        if ($page === null) {
            $this->logger?->warning('Cannot resolve the uri of a page to de-index, its vectors stay in place.', ['uri' => $event->uri]);
            return;
        }

        foreach ($this->getSyncingConfigurations() as $configuration) {
            foreach ($this->registry->findOnPage((int)$configuration['uid'], $page['pageUid'], $page['language']) as $indexConfiguration => $pointIds) {
                $this->removePoints($configuration, $indexConfiguration, $pointIds);
            }
        }
    }

    /**
     * Seeds a page document's id from what the uri addresses rather than from how it is
     * spelled: site, language, page, route arguments (a record variant's identity) and the
     * "#c<uid>" fragment EXT:index adds per content element. A changed slug, or a record uri
     * that EXT:index starts to build differently, therefore still overwrites the same points.
     * Without the fragment every element of a page would collide on one id.
     *
     * Uris that cannot be routed fall back to their path, which is still unique. The Cache
     * technology sends no uri at all, so there it is one document per page and language.
     */
    private function pageSeed(IndexPageEvent $event): string
    {
        $route = $this->routeArgumentsResolver->resolve($event->uri);
        $fragment = (string)parse_url($event->uri, PHP_URL_FRAGMENT);

        return sprintf(
            'page:%s:%d:%d:%s#%s',
            $event->site->getIdentifier(),
            $event->language,
            $event->pageUid,
            $route !== null ? 'route:' . $route['arguments'] : 'path:' . self::uriPath($event->uri),
            $fragment,
        );
    }

    /**
     * Path and query of a uri. Scheme and host are dropped so that ids stay stable when the
     * same content is indexed from a different environment.
     */
    private static function uriPath(string $uri): string
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            return $uri;
        }

        return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @param array<string, mixed> $extraMetadata
     */
    private function handle(string $title, string $content, string $uri, int $indexConfigurationRecordId, string $indexProcessId, string $kind, int $pageUid, int $language, string $idSeed, array $extraMetadata): void
    {
        if (trim($content) === '') {
            return;
        }

        foreach ($this->getTargetConfigurations($indexConfigurationRecordId) as $configuration) {
            try {
                $chunkIds = $this->indexInto($configuration, $title, $content, $uri, $idSeed, $extraMetadata);

                // Upserting only overwrites chunks that still exist; chunks a larger, previous
                // version of the same document left behind would otherwise stay retrievable.
                $uid = (int)$configuration['uid'];
                $documentId = DeterministicUuid::generate($idSeed)->toRfc4122();
                $this->registry->record($uid, $indexConfigurationRecordId, $documentId, $chunkIds, $indexProcessId, $kind, $pageUid, $language);
                $this->removePoints(
                    $configuration,
                    $indexConfigurationRecordId,
                    $this->registry->findStaleChunks($uid, $indexConfigurationRecordId, $documentId, $chunkIds),
                );
            } catch (\Throwable $exception) {
                $this->failedProcesses[$indexProcessId] = true;
                throw $exception;
            }
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $extraMetadata
     * @return string[] ids of the chunks written
     */
    private function indexInto(array $configuration, string $title, string $content, string $uri, string $idSeed, array $extraMetadata): array
    {
        $target = $this->resolveTarget($configuration);

        $document = new TextDocument(
            id: DeterministicUuid::generate($idSeed),
            content: $content,
            metadata: new Metadata([
                'title' => $title,
                'uri' => $uri,
                ...$extraMetadata,
            ]),
        );

        $chunks = [];
        foreach ((new TextSplitTransformer())->transform([$document]) as $index => $chunk) {
            // The transformer assigns a random id per chunk; replace it with a
            // deterministic one so re-indexing overwrites instead of duplicating.
            $metadata = $chunk->getMetadata();
            // SimilaritySearch returns metadata (not content) to the LLM, so the
            // actual chunk text must be in the payload, not just title/uri/etc.
            // Set explicitly (rather than trusting the transformer) because when it
            // does split, it merges the parent document's metadata in after setting
            // _text, which would otherwise overwrite each chunk's text with the
            // full, unsplit original content.
            $metadata->setText($chunk->getContent());
            $chunks[] = new TextDocument(
                id: DeterministicUuid::generate($idSeed . '#' . $index),
                content: $chunk->getContent(),
                metadata: $metadata,
            );
        }

        $target->store->add(...$target->vectorizer->vectorize($chunks));

        return array_map(static fn (TextDocument $chunk): string => $chunk->getId()->toRfc4122(), $chunks);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param string[] $pointIds
     */
    private function sweepUnlessExcessive(array $configuration, int $indexConfiguration, array $pointIds, int $tracked): void
    {
        $threshold = (int)($configuration['vector_db_sync_removals_threshold'] ?? 25);
        if ($tracked > 0 && \count($pointIds) * 100 > $threshold * $tracked) {
            $this->logger?->warning(
                'Index run would remove {remove} of {tracked} tracked vectors, more than the threshold of {threshold}%. Nothing was removed; if the removal is intended, raise the threshold or purge and re-index.',
                ['remove' => \count($pointIds), 'tracked' => $tracked, 'threshold' => $threshold, 'configuration' => (int)$configuration['uid'], 'indexConfiguration' => $indexConfiguration],
            );
            return;
        }

        $this->removePoints($configuration, $indexConfiguration, $pointIds);
    }

    /**
     * Drops the index configuration's claim on the given points. A point leaves the store only
     * if no other index configuration still references it (two index configurations sharing a
     * file mount write the same point). The store goes first, so ids whose deletion failed
     * stay registered and are retried on the next run.
     *
     * @param array<string, mixed> $configuration
     * @param string[] $pointIds
     */
    private function removePoints(array $configuration, int $indexConfiguration, array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }

        $uid = (int)$configuration['uid'];
        $orphans = array_values(array_diff($pointIds, $this->registry->referencedElsewhere($uid, $indexConfiguration, $pointIds)));
        if ($orphans !== []) {
            $this->resolveTarget($configuration)->remover->remove($orphans);
        }
        $this->registry->forget($uid, $indexConfiguration, $pointIds);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function resolveTarget(array $configuration): VectorTarget
    {
        $uid = (int)$configuration['uid'];

        return $this->resolved[$uid] ??= $this->vectorTargetFactory->create($configuration);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTargetConfigurations(int $indexConfigurationRecordId): array
    {
        if (!isset($this->targetsByIndexConfiguration[$indexConfigurationRecordId])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $this->targetsByIndexConfiguration[$indexConfigurationRecordId] = $queryBuilder
                ->select('*')
                ->from(self::TABLE)
                ->where(
                    // Every configured store takes part; one StoreFactory cannot build fails loudly
                    // there instead of being skipped here without notice.
                    $queryBuilder->expr()->notIn('vector_db', $queryBuilder->createNamedParameter(['none', ''], Connection::PARAM_STR_ARRAY)),
                    $queryBuilder->expr()->inSet('index_configurations', $queryBuilder->createNamedParameter((string)$indexConfigurationRecordId)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        }

        return $this->targetsByIndexConfiguration[$indexConfigurationRecordId];
    }

    /**
     * @return array<int, array<string, mixed>> configurations with a vector store and removal sync enabled
     */
    private function getSyncingConfigurations(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->notIn('vector_db', $queryBuilder->createNamedParameter(['none', ''], Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('vector_db_sync_removals', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
