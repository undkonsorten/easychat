<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Updates;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Updates\UndkonsortenEasychatReactionToConfigurationMigration;

final class UndkonsortenEasychatReactionToConfigurationMigrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private UndkonsortenEasychatReactionToConfigurationMigration $wizard;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ReactionMigration.csv');
        $this->wizard = $this->get(UndkonsortenEasychatReactionToConfigurationMigration::class);
        $this->output = new BufferedOutput();
        $this->wizard->setOutput($this->output);
        $this->dropReactionTable();
    }

    protected function tearDown(): void
    {
        // MariaDB, MySQL and PostgreSQL keep the test database between tests; the table is not part of the schema.
        $this->dropReactionTable();
        parent::tearDown();
    }

    public function testNothingToDoWithoutTheReactionTable(): void
    {
        self::assertFalse($this->wizard->updateNecessary());
    }

    public function testNothingToDoWithoutTheEasychatColumnOnTheReactionTable(): void
    {
        $this->createReactionTable(withEasychatColumn: false);

        self::assertFalse($this->wizard->updateNecessary());
    }

    public function testContentElementsFollowTheirReactionToItsConfiguration(): void
    {
        $this->createReactionTable();
        $this->insertReaction('afce5efb-861e-4e0e-8a8b-d159f194670d', 1);

        self::assertTrue($this->wizard->updateNecessary());
        self::assertTrue($this->wizard->executeUpdate());

        $migrated = $this->flexFormFields(20);
        self::assertSame('1', $migrated['settings.configuration']['vDEF']);
        self::assertArrayNotHasKey('settings.url', $migrated);
        self::assertArrayNotHasKey('settings.apiKey', $migrated);
        self::assertSame('Hello!', $migrated['settings.introMessage']['vDEF'], 'Other settings survive.');
        self::assertSame('1440', $migrated['settings.cookieExpirationMinutes']['vDEF']);

        $unknownReaction = $this->flexFormFields(21);
        self::assertArrayNotHasKey('settings.configuration', $unknownReaction);
        self::assertSame('secret', $unknownReaction['settings.apiKey']['vDEF'], 'An element whose reaction is unknown is left untouched for the admin.');
        self::assertStringContainsString('Content element 21', $this->output->fetch());

        self::assertSame('2', $this->flexFormFields(22)['settings.configuration']['vDEF'], 'Already migrated elements stay as they are.');
        self::assertArrayNotHasKey('settings.configuration', $this->flexFormFields(23), 'Deleted elements are skipped.');
        self::assertArrayNotHasKey('settings.configuration', $this->flexFormFields(24), 'Other content types are skipped.');

        self::assertTrue($this->wizard->updateNecessary(), 'The element with the unknown reaction still needs attention, so the wizard stays listed.');
    }

    public function testReactionsWithoutConfigurationAreReportedNotMigrated(): void
    {
        $this->createReactionTable();
        $this->insertReaction('afce5efb-861e-4e0e-8a8b-d159f194670d', 0);

        $this->wizard->executeUpdate();

        self::assertArrayNotHasKey('settings.configuration', $this->flexFormFields(20));
        self::assertStringContainsString('selects no configuration', $this->output->fetch());
    }

    private function createReactionTable(bool $withEasychatColumn = true): void
    {
        $columns = [
            new Column('uid', Type::getType('integer'), ['autoincrement' => true]),
            new Column('identifier', Type::getType('string'), ['length' => 36, 'default' => '']),
        ];
        if ($withEasychatColumn) {
            $columns[] = new Column('easychat_configuration', Type::getType('integer'), ['default' => 0]);
        }
        $table = new Table('sys_reaction', $columns);
        $table->setPrimaryKey(['uid']);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_reaction')->createSchemaManager()->createTable($table);
    }

    private function dropReactionTable(): void
    {
        $schemaManager = $this->get(ConnectionPool::class)->getConnectionForTable('sys_reaction')->createSchemaManager();
        if ($schemaManager->tablesExist(['sys_reaction'])) {
            $schemaManager->dropTable('sys_reaction');
        }
    }

    private function insertReaction(string $identifier, int $configurationUid): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_reaction')->insert('sys_reaction', [
            'identifier' => $identifier,
            'easychat_configuration' => $configurationUid,
        ]);
    }

    /**
     * @return array<string, array{vDEF: string}>
     */
    private function flexFormFields(int $contentElementUid): array
    {
        // Without restrictions: deleted elements must be checked too.
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $xml = $queryBuilder
            ->select('pi_flexform')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentElementUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        $data = GeneralUtility::xml2array((string)$xml);
        self::assertIsArray($data, 'FlexForm of ' . $contentElementUid . ': ' . $xml);

        return $data['data']['sDEF']['lDEF'];
    }
}
