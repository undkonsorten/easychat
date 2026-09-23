<?php

namespace Undkonsorten\Easychat\Indexing;

use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\FinishIndexProcessEvent;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
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
 * Chunk ids are deterministic per page/file, so re-indexing overwrites existing
 * chunks in place; chunks left over from a larger previous version of the same
 * document are deleted right after.
 *
 * Content that is no longer emitted at all (deleted, hidden, access restricted,
 * no_search, ...) is only removed when the configuration enables
 * vector_db_sync_removals: at the end of an index process, every point of that
 * index configuration not re-written by the process is deleted — the whole
 * configuration after a full run, only the pages touched after a partial one.
 *
 * Which points exist is tracked in IndexPointRegistry, and deleting only needs a
 * delete-by-id, so none of this depends on a particular vector store. Points written
 * before the registry existed are unknown to it and never deleted; drop the
 * collection and re-index once to get rid of them.
 */
class IndexEventListener
{
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
            pageUid: $event->pageUid,
            // EXT:index dispatches one event per content element when contentIndexing
            // is enabled, and one per record variant on top of that. All of them share
            // site/language/pageUid, so the uri (its "#c<uid>" fragment, plus the route
            // arguments a variant encodes into path or query) is the only discriminator
            // available — without it every element of a page collides on the same id and
            // silently overwrites the one before it.
            idSeed: sprintf('page:%s:%d:%d:%s', $event->site->getIdentifier(), $event->language, $event->pageUid, self::uriDiscriminator($event->uri)),
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
            pageUid: 0,
            idSeed: sprintf('file:%s:%s', $event->site->getIdentifier(), $event->fileIdentifier),
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
     * EXT:index logs and swallows exceptions from page handlers, so a run can reach its end
     * with part of the content never re-written (e.g. the embeddings API went down). Sweeping
     * then would delete perfectly current content, so a process that failed, or wrote nothing
     * at all, is not swept.
     */
    #[AsEventListener(identifier: 'easychat/index-finish')]
    public function onFinishIndexProcess(FinishIndexProcessEvent $event): void
    {
        if ($event->indexConfigurationRecordId === null) {
            return;
        }
        if (isset($this->failedProcesses[$event->indexProcessId])) {
            unset($this->failedProcesses[$event->indexProcessId]);
            return;
        }

        foreach ($this->getTargetConfigurations($event->indexConfigurationRecordId) as $configuration) {
            if (!(bool)($configuration['vector_db_sync_removals'] ?? false)) {
                continue;
            }

            $uid = (int)$configuration['uid'];
            if ($event->type === IndexType::Full) {
                if ($this->registry->countOfProcess($uid, $event->indexConfigurationRecordId, $event->indexProcessId) === 0) {
                    continue;
                }
                $pageUids = null;
            } else {
                // A partial run only re-emits the pages it was triggered for, so anything outside
                // of them is still current and must not be swept.
                $pageUids = $this->registry->pageUidsOfProcess($uid, $event->indexConfigurationRecordId, $event->indexProcessId);
                if ($pageUids === []) {
                    continue;
                }
            }

            $this->removePoints(
                $configuration,
                $this->registry->findNotInProcess($uid, $event->indexConfigurationRecordId, $event->indexProcessId, $pageUids),
            );
        }
    }

    /**
     * Reduces a uri to the part that distinguishes it from other uris of the same
     * page: path, query and fragment. The scheme and host are dropped so that ids
     * stay stable when the same content is indexed from a different environment.
     */
    private static function uriDiscriminator(string $uri): string
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            return $uri;
        }

        return ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * @param array<string, mixed> $extraMetadata
     */
    private function handle(string $title, string $content, string $uri, int $indexConfigurationRecordId, string $indexProcessId, int $pageUid, string $idSeed, array $extraMetadata): void
    {
        if (trim($content) === '') {
            return;
        }

        foreach ($this->getTargetConfigurations($indexConfigurationRecordId) as $configuration) {
            try {
                $chunkIds = $this->indexInto($configuration, $title, $content, $uri, $idSeed, $extraMetadata);
                $this->registerChunks($configuration, $idSeed, $chunkIds, $indexConfigurationRecordId, $indexProcessId, $pageUid);
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
     * Records the chunks just written and deletes the ones a larger, previous version of the
     * same document left behind — upserting only overwrites chunks that still exist, so those
     * would otherwise stay retrievable.
     *
     * @param array<string, mixed> $configuration
     * @param string[] $chunkIds
     */
    private function registerChunks(array $configuration, string $idSeed, array $chunkIds, int $indexConfigurationRecordId, string $indexProcessId, int $pageUid): void
    {
        $uid = (int)$configuration['uid'];
        $documentId = DeterministicUuid::generate($idSeed)->toRfc4122();

        $this->registry->record($uid, $documentId, $chunkIds, $indexConfigurationRecordId, $indexProcessId, $pageUid);
        $this->removePoints($configuration, $this->registry->findStaleChunks($uid, $documentId, $chunkIds));
    }

    /**
     * Deletes from the store first and from the registry only afterwards, so ids whose deletion
     * failed stay known and are retried on the next run.
     *
     * @param array<string, mixed> $configuration
     * @param string[] $pointIds
     */
    private function removePoints(array $configuration, array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }

        $this->resolveTarget($configuration)->remover->remove($pointIds);
        $this->registry->forget((int)$configuration['uid'], $pointIds);
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
}
