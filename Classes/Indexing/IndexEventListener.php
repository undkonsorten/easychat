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
        $this->handle(
            title: $event->title,
            content: $event->content,
            uri: $event->uri,
            indexConfigurationRecordId: $event->indexConfigurationRecordId,
            idSeed: sprintf('page:%s:%d:%d', $event->site->getIdentifier(), $event->language, $event->pageUid),
            extraMetadata: [
                'pageUid' => $event->pageUid,
                'language' => $event->language,
            ],
        );
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
