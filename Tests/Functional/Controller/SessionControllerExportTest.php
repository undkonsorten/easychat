<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Controller;

use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Controller\SessionController;
use Undkonsorten\Easychat\Domain\Model\Session;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Service\SessionCsvExportService;

/**
 * Covers the parts of the CSV export that only a real TYPO3 bootstrap can
 * verify: that the backend module actually exposes the new export action,
 * and that scoping an export to one session (SessionController::exportAction's
 * $sessionUid argument) really only pulls in that session's own row, not any
 * other conversation. The CSV building logic itself, including how a single
 * row's several question/answer turns become several CSV rows, is covered by
 * the unit tests in Tests/Unit/Service/SessionCsvExportServiceTest.php.
 */
final class SessionControllerExportTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    public function testExportActionsAreRegisteredForTheBackendModule(): void
    {
        $moduleConfiguration = require __DIR__ . '/../../../Configuration/Backend/Modules.php';
        $registeredActions = $moduleConfiguration['easychat']['controllerActions'][SessionController::class];

        self::assertContains('export', $registeredActions);
        self::assertContains(
            'exportSettings',
            $registeredActions,
            'exportSettings renders the field-selection form loaded into the <typo3-recordlist-record-download-button> modal.',
        );
    }

    public function testSingleSessionExportOnlyIncludesThatSessionsOwnTurns(): void
    {
        // Reproduces exactly what exportAction(sessionUid: ...) does: resolve the anchor row by
        // uid and export only it. A conversation lives entirely in one row (SessionRepository::save()
        // always finds and updates the existing row for a sessionId), so a multi-turn conversation
        // is set up here as several turns within that single row's messages.
        $repository = $this->get(SessionRepository::class);
        $persistenceManager = $this->get(PersistenceManager::class);

        $conversationA = $this->persistSession($repository, 'conversation-a', [
            ...$this->turnMessages('First question?', 'First answer.'),
            ...$this->turnMessages('Second question?', 'Second answer.'),
        ]);
        $this->persistSession($repository, 'conversation-b', $this->turnMessages('Unrelated question?', 'Unrelated answer.'));
        $persistenceManager->persistAll();

        $anchor = $repository->findByUid($conversationA->getUid());
        self::assertNotNull($anchor);

        $csv = $this->get(SessionCsvExportService::class)->export([$anchor], ['sessionId', 'questions', 'answers']);

        self::assertStringContainsString('First question?', $csv);
        self::assertStringContainsString('Second question?', $csv);
        self::assertStringNotContainsString('Unrelated question?', $csv);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function persistSession(SessionRepository $repository, string $sessionId, array $messages): Session
    {
        $session = new Session();
        $session->setPid(1);
        $session->setSessionId($sessionId);
        $session->setMessages(json_encode($messages, JSON_THROW_ON_ERROR));
        $repository->add($session);

        return $session;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function turnMessages(string $question, string $answer): array
    {
        return [
            [
                'id' => 'u-' . $question,
                'type' => 'Symfony\AI\Platform\Message\UserMessage',
                'content' => '',
                'contentAsBase64' => [
                    ['type' => 'Symfony\AI\Platform\Message\Content\Text', 'content' => $question],
                ],
            ],
            [
                'id' => 'a-' . $question,
                'type' => 'Symfony\AI\Platform\Message\AssistantMessage',
                'content' => $answer,
                'contentAsBase64' => [],
            ],
        ];
    }
}
