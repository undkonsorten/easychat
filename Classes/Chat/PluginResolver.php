<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

/**
 * Turns the content element uid a chat request names into the chatbot configuration to use.
 *
 * The content element and its page must be visible to the current frontend visitor (not
 * deleted, hidden, outside start/end time or access restricted), otherwise the request is
 * answered with 404, exactly like the page would be. The configuration record the element
 * selects must exist and be enabled, otherwise 503: the chatbot exists but has no backend.
 */
class PluginResolver
{
    public const CONTENT_TYPE = 'easychat_easychatfrontend';

    public const CONFIGURATION_TABLE = 'tx_easychat_configuration';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {}

    public function resolve(int $contentElementUid): ResolvedPlugin
    {
        if ($contentElementUid <= 0) {
            throw new ChatRequestException(400, 'No chatbot given.');
        }

        $contentElement = $this->fetchVisible('tt_content', ['uid', 'pid', 'pi_flexform'], [
            'uid' => $contentElementUid,
            'CType' => self::CONTENT_TYPE,
        ]);
        if ($contentElement === null) {
            throw new ChatRequestException(404, 'Unknown chatbot.');
        }
        if ($this->fetchVisible('pages', ['uid'], ['uid' => (int)$contentElement['pid']]) === null) {
            throw new ChatRequestException(404, 'Unknown chatbot.');
        }

        $settings = $this->readFlexFormSettings((string)$contentElement['pi_flexform']);
        $configurationUid = (int)($settings['settings.configuration'] ?? 0);
        if ($configurationUid <= 0) {
            throw new ChatRequestException(
                503,
                ChatRequestHandler::GENERIC_ERROR,
                [],
                sprintf('Chatbot content element %d selects no configuration', $contentElementUid),
            );
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONFIGURATION_TABLE);
        $configuration = $queryBuilder
            ->select('*')
            ->from(self::CONFIGURATION_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($configurationUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($configuration === false) {
            throw new ChatRequestException(
                503,
                ChatRequestHandler::GENERIC_ERROR,
                [],
                sprintf('Chatbot content element %d selects configuration %d, which is missing or disabled', $contentElementUid, $configurationUid),
            );
        }

        $cookieExpirationMinutes = (int)($settings['settings.cookieExpirationMinutes'] ?? 0);
        if ($cookieExpirationMinutes <= 0) {
            $cookieExpirationMinutes = ChatSessionCookie::DEFAULT_LIFETIME_MINUTES;
        }

        return new ResolvedPlugin($contentElementUid, ChatConfiguration::fromRow($configuration), $cookieExpirationMinutes);
    }

    /**
     * @param list<string> $fields
     * @param array<string, int|string> $where
     * @return array<string, mixed>|null
     */
    private function fetchVisible(string $table, array $fields, array $where): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class, $this->context));
        $queryBuilder->select(...$fields)->from($table);
        foreach ($where as $field => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR),
            ));
        }
        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Reads the sDEF sheet values of the plugin FlexForm. GeneralUtility::xml2array() is used
     * because the FlexForm service classes differ between TYPO3 versions.
     *
     * @return array<string, string>
     */
    private function readFlexFormSettings(string $flexFormXml): array
    {
        if (trim($flexFormXml) === '') {
            return [];
        }
        $data = GeneralUtility::xml2array($flexFormXml);
        if (!is_array($data)) {
            return [];
        }
        $settings = [];
        foreach ($data['data']['sDEF']['lDEF'] ?? [] as $field => $value) {
            if (is_array($value) && array_key_exists('vDEF', $value)) {
                $settings[(string)$field] = (string)$value['vDEF'];
            }
        }

        return $settings;
    }
}
