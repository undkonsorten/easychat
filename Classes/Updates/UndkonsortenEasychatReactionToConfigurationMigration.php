<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
// TYPO3 v14 moved these to TYPO3\CMS\Core\Attribute\UpgradeWizard and TYPO3\CMS\Core\Upgrades\, which do
// not exist in v13. The EXT:install names still work in v14 (deprecated, removed in v15), so switch
// together with dropping TYPO3 v13 support.
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * EasyChat 0.3 replaced the TYPO3 reaction with a frontend endpoint. Chatbot content elements
 * used to store the reaction URL and secret in their FlexForm; now they select the EasyChat
 * configuration record directly. This wizard follows the old reaction URL to the reaction's
 * configuration and writes that into the content element.
 *
 * Run it before editors re-save Chatbot elements: FormEngine drops FlexForm fields that are no
 * longer part of the data structure on save, and with them the reaction URL this wizard needs.
 */
#[UpgradeWizard('undkonsortenEasychatReactionToConfigurationMigration')]
final class UndkonsortenEasychatReactionToConfigurationMigration implements UpgradeWizardInterface, ChattyInterface
{
    public const CONTENT_TYPE = 'easychat_easychatfrontend';

    private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormTools $flexFormTools,
    ) {}

    public function getTitle(): string
    {
        return 'Migrate EasyChat chatbots from reactions to configurations';
    }

    public function getDescription(): string
    {
        return 'EasyChat 0.3 no longer uses TYPO3 reactions. Chatbot content elements select the EasyChat configuration'
            . ' directly. This update looks up the configuration of the reaction each content element pointed to and'
            . ' stores it in the element. Run it before re-saving any Chatbot content element. Afterwards the reactions'
            . ' of type "Reaction for easychat" can be deleted.';
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool
    {
        return $this->reactionTableIsUsable() && $this->findContentElementsToMigrate() !== [];
    }

    public function executeUpdate(): bool
    {
        if (!$this->reactionTableIsUsable()) {
            return true;
        }
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ($this->findContentElementsToMigrate() as $row) {
            $uid = (int)$row['uid'];
            $flexForm = GeneralUtility::xml2array((string)$row['pi_flexform']);
            if (!is_array($flexForm)) {
                $this->report($row, 'its FlexForm cannot be parsed');
                continue;
            }
            $identifier = self::extractReactionIdentifier((string)($flexForm['data']['sDEF']['lDEF']['settings.url']['vDEF'] ?? ''));
            if ($identifier === null) {
                $this->report($row, 'its reaction URL contains no reaction identifier');
                continue;
            }
            $configurationUid = $this->findConfigurationForReaction($identifier);
            if ($configurationUid <= 0) {
                $this->report($row, sprintf('reaction %s does not exist or selects no configuration', $identifier));
                continue;
            }
            $connection->update(
                'tt_content',
                ['pi_flexform' => $this->flexFormTools->flexArray2Xml(self::migrateFlexFormArray($flexForm, $configurationUid))],
                ['uid' => $uid],
            );
            $this->output?->writeln(sprintf('Content element %d (page %d): now uses configuration %d.', $uid, (int)$row['pid'], $configurationUid));
        }

        return true;
    }

    /**
     * The reaction URL as pasted by editors: "/typo3/reaction/<uuid>" or with scheme and host.
     */
    public static function extractReactionIdentifier(string $reactionUrl): ?string
    {
        if (preg_match(self::UUID_PATTERN, $reactionUrl, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Replaces settings.url and settings.apiKey with settings.configuration; everything else stays.
     *
     * @param array<string, mixed> $flexForm as returned by GeneralUtility::xml2array()
     * @return array<string, mixed>
     */
    public static function migrateFlexFormArray(array $flexForm, int $configurationUid): array
    {
        unset($flexForm['data']['sDEF']['lDEF']['settings.url'], $flexForm['data']['sDEF']['lDEF']['settings.apiKey']);
        $flexForm['data']['sDEF']['lDEF']['settings.configuration'] = ['vDEF' => (string)$configurationUid];

        return $flexForm;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findContentElementsToMigrate(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'pid', 'pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::CONTENT_TYPE)),
                $queryBuilder->expr()->like('pi_flexform', $queryBuilder->createNamedParameter('%index="settings.url"%')),
                $queryBuilder->expr()->notLike('pi_flexform', $queryBuilder->createNamedParameter('%index="settings.configuration"%')),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function findConfigurationForReaction(string $identifier): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_reaction');
        $queryBuilder->getRestrictions()->removeAll();
        $configurationUid = $queryBuilder
            ->select('easychat_configuration')
            ->from('sys_reaction')
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();

        return (int)$configurationUid;
    }

    /**
     * sys_reaction belongs to EXT:reactions, which EasyChat no longer requires, and its
     * easychat_configuration column came from EasyChat 0.2. Without both there is nothing to read.
     */
    private function reactionTableIsUsable(): bool
    {
        $schemaManager = $this->connectionPool->getConnectionForTable('sys_reaction')->createSchemaManager();
        if (!$schemaManager->tablesExist(['sys_reaction'])) {
            return false;
        }

        return array_key_exists('easychat_configuration', $schemaManager->listTableColumns('sys_reaction'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function report(array $row, string $reason): void
    {
        $this->output?->writeln(sprintf(
            '<warning>Content element %d (page %d) was not migrated: %s. Select the configuration in the element by hand.</warning>',
            (int)$row['uid'],
            (int)$row['pid'],
            $reason,
        ));
    }
}
