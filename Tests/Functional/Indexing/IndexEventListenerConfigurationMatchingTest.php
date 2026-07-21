<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\IndexEventListener;

/**
 * Covers IndexEventListener::getTargetConfigurations() against a real database —
 * this is the routing that decides which tx_easychat_configuration record(s) an
 * indexed page/file gets embedded into, via the index_configurations link field.
 */
final class IndexEventListenerConfigurationMatchingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/EasychatConfigurations.csv');
    }

    public function testOnlyEnabledQdrantConfigurationsLinkedToTheIndexConfigurationAreReturned(): void
    {
        $matches = $this->getTargetConfigurations(5);

        self::assertCount(1, $matches);
        self::assertSame(1, (int)$matches[0]['uid']);
    }

    public function testMultipleConfigurationsCanShareTheSameIndexConfiguration(): void
    {
        $uids = array_map(
            static fn (array $row): int => (int)$row['uid'],
            $this->getTargetConfigurations(9),
        );
        sort($uids);

        self::assertSame([1, 2], $uids);
    }

    public function testNonQdrantConfigurationIsNeverMatched(): void
    {
        // uid 3 has index_configurations=5 but vector_db=none
        $uids = array_map(
            static fn (array $row): int => (int)$row['uid'],
            $this->getTargetConfigurations(5),
        );

        self::assertNotContains(3, $uids);
    }

    public function testHiddenAndDeletedConfigurationsAreNeverMatched(): void
    {
        // uid 4 (hidden) and uid 5 (deleted) both have index_configurations=5
        $uids = array_map(
            static fn (array $row): int => (int)$row['uid'],
            $this->getTargetConfigurations(5),
        );

        self::assertNotContains(4, $uids);
        self::assertNotContains(5, $uids);
    }

    public function testUnknownIndexConfigurationIdReturnsNoMatches(): void
    {
        self::assertSame([], $this->getTargetConfigurations(999));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTargetConfigurations(int $indexConfigurationRecordId): array
    {
        $listener = new IndexEventListener($this->get(ConnectionPool::class));

        $method = new \ReflectionMethod($listener, 'getTargetConfigurations');

        return $method->invoke($listener, $indexConfigurationRecordId);
    }
}
