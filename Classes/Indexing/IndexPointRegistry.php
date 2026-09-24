<?php

namespace Undkonsorten\Easychat\Indexing;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Remembers which vector store point belongs to which indexed document, index configuration,
 * index run, page and language.
 *
 * Vector stores only share a delete-by-id (StoreInterface::remove() in symfony/ai >= 0.4);
 * deleting or listing by metadata is store specific. Keeping this bookkeeping in TYPO3 is what
 * lets IndexEventListener work out which ids to delete, whatever the store.
 *
 * Every lookup is scoped by $configuration, the tx_easychat_configuration uid — that is, by
 * store — because several configurations can index the same content into different stores.
 * Within a store, rows are owned per index configuration: two index configurations sharing a
 * file mount write the same point, and each keeps its own row for it, so a point only leaves
 * the store once no index configuration references it any more.
 */
class IndexPointRegistry
{
    public const KIND_PAGE = 'page';
    public const KIND_FILE = 'file';

    private const TABLE = 'tx_easychat_index_point';

    /** Keeps IN() lists well below the bound parameter limits of every DBMS. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param string[] $pointIds
     */
    public function record(int $configuration, int $indexConfiguration, string $documentId, array $pointIds, string $indexProcess, string $kind, int $pageUid, int $language): void
    {
        if ($pointIds === []) {
            return;
        }

        $this->forget($configuration, $indexConfiguration, $pointIds);

        $rows = [];
        foreach ($pointIds as $pointId) {
            $rows[] = [$configuration, $indexConfiguration, $pointId, $documentId, $indexProcess, $kind, $pageUid, $language, time()];
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->bulkInsert(
            self::TABLE,
            $rows,
            ['configuration', 'index_configuration', 'point_id', 'document_id', 'index_process', 'kind', 'page_uid', 'language', 'tstamp'],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    /**
     * @param string[] $keepIds
     * @return string[] ids of the document's points that are not among $keepIds
     */
    public function findStaleChunks(int $configuration, int $indexConfiguration, string $documentId, array $keepIds): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('point_id')
            ->from(self::TABLE)
            ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration))
            ->andWhere($queryBuilder->expr()->eq('document_id', $queryBuilder->createNamedParameter($documentId)));
        if ($keepIds !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('point_id', $queryBuilder->createNamedParameter(array_values($keepIds), Connection::PARAM_STR_ARRAY)),
            );
        }

        return $queryBuilder->executeQuery()->fetchFirstColumn();
    }

    public function countOfProcess(int $configuration, int $indexConfiguration, string $indexProcess, ?string $kind = null): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration))
            ->andWhere($queryBuilder->expr()->eq('index_process', $queryBuilder->createNamedParameter($indexProcess)));
        $this->restrictToKind($queryBuilder, $kind);

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    public function countTracked(int $configuration, int $indexConfiguration, ?string $kind = null): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration));
        $this->restrictToKind($queryBuilder, $kind);

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * @return list<array{0: int, 1: int}> [pageUid, language] of every page written by the given index process
     */
    public function pagesOfProcess(int $configuration, int $indexConfiguration, string $indexProcess): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('page_uid', 'language')
            ->from(self::TABLE)
            ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration))
            ->andWhere($queryBuilder->expr()->eq('index_process', $queryBuilder->createNamedParameter($indexProcess)))
            ->andWhere($queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter(self::KIND_PAGE)))
            ->groupBy('page_uid', 'language')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [(int)$row['page_uid'], (int)$row['language']], $rows);
    }

    /**
     * @param list<array{0: int, 1: int}>|null $pages [pageUid, language] pairs to restrict to, null for no restriction
     * @return string[] ids of the index configuration's points (of $kind, if given) that the given index process did not write
     */
    public function findNotInProcess(int $configuration, int $indexConfiguration, string $indexProcess, ?string $kind = null, ?array $pages = null): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('point_id')
            ->from(self::TABLE)
            ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration))
            ->andWhere($queryBuilder->expr()->neq('index_process', $queryBuilder->createNamedParameter($indexProcess)));
        $this->restrictToKind($queryBuilder, $kind);
        if ($pages !== null) {
            $queryBuilder->andWhere($this->pagesConstraint($queryBuilder, $pages));
        }

        return $queryBuilder->executeQuery()->fetchFirstColumn();
    }

    /**
     * @return array<int, string[]> index configuration => ids of its points on the given page and language
     */
    public function findOnPage(int $configuration, int $pageUid, int $language): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('index_configuration', 'point_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter(self::KIND_PAGE)),
                $this->pagesConstraint($queryBuilder, [[$pageUid, $language]]),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $pointIds = [];
        foreach ($rows as $row) {
            $pointIds[(int)$row['index_configuration']][] = (string)$row['point_id'];
        }

        return $pointIds;
    }

    /**
     * @param string[] $pointIds
     * @return string[] those of $pointIds that another index configuration of the same store still references
     */
    public function referencedElsewhere(int $configuration, int $indexConfiguration, array $pointIds): array
    {
        $referenced = [];
        foreach (array_chunk(array_values($pointIds), self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->getQueryBuilder();
            $referenced[] = $queryBuilder
                ->select('point_id')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq('index_configuration', $queryBuilder->createNamedParameter($indexConfiguration, Connection::PARAM_INT)),
                    $queryBuilder->expr()->in('point_id', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY)),
                )
                ->groupBy('point_id')
                ->executeQuery()
                ->fetchFirstColumn();
        }

        return array_merge(...$referenced);
    }

    /**
     * @param string[] $pointIds
     */
    public function forget(int $configuration, int $indexConfiguration, array $pointIds): void
    {
        foreach (array_chunk(array_values($pointIds), self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder
                ->delete(self::TABLE)
                ->where(...$this->ownerConstraints($queryBuilder, $configuration, $indexConfiguration))
                ->andWhere($queryBuilder->expr()->in('point_id', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY)))
                ->executeStatement();
        }
    }

    /**
     * @return string[]
     */
    private function ownerConstraints(QueryBuilder $queryBuilder, int $configuration, int $indexConfiguration): array
    {
        return [
            $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('index_configuration', $queryBuilder->createNamedParameter($indexConfiguration, Connection::PARAM_INT)),
        ];
    }

    private function restrictToKind(QueryBuilder $queryBuilder, ?string $kind): void
    {
        if ($kind !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('kind', $queryBuilder->createNamedParameter($kind)));
        }
    }

    /**
     * @param list<array{0: int, 1: int}> $pages [pageUid, language] pairs
     */
    private function pagesConstraint(QueryBuilder $queryBuilder, array $pages): string
    {
        $pairs = [];
        foreach ($pages as [$pageUid, $language]) {
            $pairs[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('language', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT)),
            );
        }

        return (string)$queryBuilder->expr()->or(...$pairs);
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
