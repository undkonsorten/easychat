<?php

namespace Undkonsorten\Easychat\Indexing;

use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\StoreInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Undkonsorten\Easychat\Factories\StoreFactory;
use Undkonsorten\Easychat\Factories\VectorizerFactory;

/**
 * Bridges EXT:index's page/file crawl events into the vector store(s) configured
 * on tx_easychat_configuration records, so the SimilaritySearch tool used in
 * ChatReaction has content to retrieve.
 *
 * Known limitation: chunk ids are deterministic per page/file so re-indexing
 * overwrites existing chunks, but if a page shrinks to fewer chunks than a
 * previous run, the trailing stale chunks are not deleted (StoreInterface
 * exposes no delete/filtered-delete operation).
 */
class IndexEventListener
{
    private const TABLE = 'tx_easychat_configuration';

    /** @var array<int, array<int, array<string, mixed>>> indexConfigurationRecordId => matching tx_easychat_configuration rows */
    private array $targetsByIndexConfiguration = [];

    /** @var array<int, array{store: StoreInterface, vectorizer: Vectorizer}> configuration uid => resolved store/vectorizer */
    private array $resolved = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
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
            idSeed: sprintf('file:%s:%s', $event->site->getIdentifier(), $event->fileIdentifier),
            extraMetadata: [
                'fileIdentifier' => $event->fileIdentifier,
            ],
        );
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
    private function handle(string $title, string $content, string $uri, int $indexConfigurationRecordId, string $idSeed, array $extraMetadata): void
    {
        if (trim($content) === '') {
            return;
        }

        foreach ($this->getTargetConfigurations($indexConfigurationRecordId) as $configuration) {
            $this->indexInto($configuration, $title, $content, $uri, $idSeed, $extraMetadata);
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $extraMetadata
     */
    private function indexInto(array $configuration, string $title, string $content, string $uri, string $idSeed, array $extraMetadata): void
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

        $target['store']->add(...$target['vectorizer']->vectorize($chunks));
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array{store: StoreInterface, vectorizer: Vectorizer}
     */
    private function resolveTarget(array $configuration): array
    {
        $uid = (int)$configuration['uid'];
        if (!isset($this->resolved[$uid])) {
            $store = StoreFactory::create(
                $configuration['vector_db'],
                $configuration['vector_db_host'] . ':' . $configuration['vector_db_port'],
                $configuration['vector_db_api_key'],
                $configuration['vector_db_name'],
                (int)$configuration['vector_db_dimensions'],
            );
            $store->setup();

            $this->resolved[$uid] = [
                'store' => $store,
                'vectorizer' => VectorizerFactory::create($configuration),
            ];
        }

        return $this->resolved[$uid];
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
                    $queryBuilder->expr()->eq('vector_db', $queryBuilder->createNamedParameter('qdrant')),
                    $queryBuilder->expr()->inSet('index_configurations', $queryBuilder->createNamedParameter((string)$indexConfigurationRecordId)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        }

        return $this->targetsByIndexConfiguration[$indexConfigurationRecordId];
    }
}
