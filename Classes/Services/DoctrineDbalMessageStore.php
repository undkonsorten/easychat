<?php

namespace Undkonsorten\Easychat\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Symfony\AI\Chat\ManagedStoreInterface;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Chat\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final class DoctrineDbalMessageStore implements ManagedStoreInterface, MessageStoreInterface
{
    public function __construct(
        private readonly string $tableName,
        private readonly \TYPO3\CMS\Core\Database\Connection $dbalConnection,
        private readonly SerializerInterface $serializer = new Serializer([
            new ArrayDenormalizer(),
            new MessageNormalizer(),
        ], [new JsonEncoder()]),
    ) {
    }

    public function setup(array $options = []): void
    {
        /**
         * Just s stub, TYPO3 generates the table for use. See ext_tables.sql
         */
    }

    public function drop(): void
    {
        /**
         * Just s stub, we drop differently
         */
    }

    public function save(MessageBag $messages): void
    {
        $this->dbalConnection
            ->insert(
                $this->tableName,
                [
                    'messages' =>  $this->serializer->serialize($messages->getMessages(), 'json'),
                ],
            );
    }

    public function load(): MessageBag
    {
        $queryBuilder = $this->dbalConnection->createQueryBuilder()
            ->select('messages')
            ->from($this->tableName)
        ;

        $result = $this->dbalConnection->transactional(static fn (Connection $connection): Result => $connection->executeQuery(
            $queryBuilder->getSQL(),
        ));

        $messages = array_map(
            fn (array $payload): array => $this->serializer->deserialize($payload['messages'], MessageInterface::class.'[]', 'json'),
            $result->fetchAllAssociative(),
        );

        return new MessageBag(...array_merge(...$messages));
    }
}
