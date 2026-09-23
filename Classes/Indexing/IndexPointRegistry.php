<?php

namespace Undkonsorten\Easychat\Indexing;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Remembers which vector store point belongs to which indexed document, index configuration,
 * index run and page.
 *
 * Vector stores only share a delete-by-id (StoreInterface::remove() in symfony/ai >= 0.4);
 * deleting or listing by metadata is store specific. Keeping this bookkeeping in TYPO3 is what
 * lets IndexEventListener work out which ids to delete, whatever the store.
 *
 * Every lookup is scoped by $configuration, the tx_easychat_configuration uid — that is, by
 * store — because several configurations can index the same content into different stores.
 */
class IndexPointRegistry
{
    private const TABLE = 'tx_easychat_index_point';

    /** Keeps IN() lists well below the bound parameter limits of every DBMS. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param string[] $pointIds
     */
    public function record(int $configuration, string $documentId, array $pointIds, int $indexConfiguration, string $indexProcess, int $pageUid): void
    {
        if ($pointIds === []) {
            return;
        }

        $this->forget($configuration, $pointIds);

        $rows = [];
        foreach ($pointIds as $pointId) {
            $rows[] = [$configuration, $pointId, $documentId, $indexConfiguration, $indexProcess, $pageUid, time()];
        }
        $this->getConnection()->bulkInsert(
            self::TABLE,
            $rows,
            ['configuration', 'point_id', 'document_id', 'index_configuration', 'index_process', 'page_uid', 'tstamp'],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    /**
     * @param string[] $keepIds
     * @return string[] ids of the document's points that are not among $keepIds
     */
    public function findStaleChunks(int $configuration, string $documentId, array $keepIds): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('point_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('document_id', $queryBuilder->createNamedParameter($documentId)),
            );
        if ($keepIds !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('point_id', $queryBuilder->createNamedParameter(array_values($keepIds), Connection::PARAM_STR_ARRAY)),
            );
        }

        return $queryBuilder->executeQuery()->fetchFirstColumn();
    }

    public function countOfProcess(int $configuration, int $indexConfiguration, string $indexProcess): int
    {
        $queryBuilder = $this->getQueryBuilder();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$this->processConstraints($queryBuilder, $configuration, $indexConfiguration, $indexProcess))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return int[] pages that got at least one point written by the given index process
     */
    public function pageUidsOfProcess(int $configuration, int $indexConfiguration, string $indexProcess): array
    {
        $queryBuilder = $this->getQueryBuilder();

        return array_map('intval', $queryBuilder
            ->select('page_uid')
            ->from(self::TABLE)
            ->where(...$this->processConstraints($queryBuilder, $configuration, $indexConfiguration, $indexProcess))
            ->andWhere($queryBuilder->expr()->gt('page_uid', 0))
            ->groupBy('page_uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @param int[]|null $pageUids null for no page restriction; points of files have no page and
     *                             are therefore only included without one
     * @return string[] ids of the index configuration's points that the given index process did not write
     */
    public function findNotInProcess(int $configuration, int $indexConfiguration, string $indexProcess, ?array $pageUids = null): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('point_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('index_configuration', $queryBuilder->createNamedParameter($indexConfiguration, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('index_process', $queryBuilder->createNamedParameter($indexProcess)),
            );
        if ($pageUids !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('page_uid', $queryBuilder->createNamedParameter(array_values($pageUids), Connection::PARAM_INT_ARRAY)),
            );
        }

        return $queryBuilder->executeQuery()->fetchFirstColumn();
    }

    /**
     * @param string[] $pointIds
     */
    public function forget(int $configuration, array $pointIds): void
    {
        foreach (array_chunk(array_values($pointIds), self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder
                ->delete(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
                    $queryBuilder->expr()->in('point_id', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY)),
                )
                ->executeStatement();
        }
    }

    /**
     * @return string[]
     */
    private function processConstraints(QueryBuilder $queryBuilder, int $configuration, int $indexConfiguration, string $indexProcess): array
    {
        return [
            $queryBuilder->expr()->eq('configuration', $queryBuilder->createNamedParameter($configuration, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('index_configuration', $queryBuilder->createNamedParameter($indexConfiguration, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('index_process', $queryBuilder->createNamedParameter($indexProcess)),
        ];
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
