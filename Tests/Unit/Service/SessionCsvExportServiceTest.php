<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Domain\Model\Session;
use Undkonsorten\Easychat\Service\SessionCsvExportService;

final class SessionCsvExportServiceTest extends TestCase
{
    private function buildSession(string $sessionId, int $crdate, array $messages): Session
    {
        $session = new Session();
        $session->setSessionId($sessionId);
        $session->setMessages(json_encode($messages, JSON_THROW_ON_ERROR));

        $crdateProperty = new \ReflectionProperty(Session::class, 'crdate');
        $crdateProperty->setAccessible(true);
        $crdateProperty->setValue($session, $crdate);

        return $session;
    }

    private function systemMessage(string $text): array
    {
        return [
            'id' => 's-' . $text,
            'type' => 'Symfony\AI\Platform\Message\SystemMessage',
            'content' => $text,
            'contentAsBase64' => [],
        ];
    }

    private function userMessage(string $text): array
    {
        return [
            'id' => 'u-' . $text,
            'type' => 'Symfony\AI\Platform\Message\UserMessage',
            'content' => '',
            'contentAsBase64' => [
                ['type' => 'Symfony\AI\Platform\Message\Content\Text', 'content' => $text],
            ],
        ];
    }

    private function assistantMessage(string $text): array
    {
        return [
            'id' => 'a-' . $text,
            'type' => 'Symfony\AI\Platform\Message\AssistantMessage',
            'content' => $text,
            'contentAsBase64' => [],
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function testGetAvailableFieldsListsAllExportableColumns(): void
    {
        $service = new SessionCsvExportService();

        self::assertSame(
            ['sessionId', 'createdAt', 'systemPrompt', 'questions', 'answers'],
            array_keys($service->getAvailableFields()),
        );
    }

    public function testExportExtractsSystemPromptQuestionsAndAnswersFromTheMessageBag(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, [
            [
                'id' => 'a',
                'type' => 'Symfony\AI\Platform\Message\SystemMessage',
                'content' => 'You are a helpful assistant.',
                'contentAsBase64' => [],
            ],
            [
                'id' => 'b',
                'type' => 'Symfony\AI\Platform\Message\UserMessage',
                'content' => '',
                'contentAsBase64' => [
                    ['type' => 'Symfony\AI\Platform\Message\Content\Text', 'content' => 'What is TYPO3?'],
                ],
            ],
            [
                'id' => 'c',
                'type' => 'Symfony\AI\Platform\Message\AssistantMessage',
                'content' => 'TYPO3 is an open source CMS.',
                'contentAsBase64' => [],
            ],
        ]);

        $csv = $service->export([$session], ['sessionId', 'createdAt', 'systemPrompt', 'questions', 'answers']);
        $rows = $this->parseCsv($csv);

        self::assertSame(['sessionId', 'createdAt', 'systemPrompt', 'questions', 'answers'], $rows[0]);
        self::assertSame(
            [
                'session-1',
                (new \DateTime())->setTimestamp(1735689600)->format('Y-m-d H:i:s'),
                'You are a helpful assistant.',
                'What is TYPO3?',
                'TYPO3 is an open source CMS.',
            ],
            $rows[1],
        );
    }

    public function testExportUsesOnlyTheFirstSystemMessageWhenSeveralArePresent(): void
    {
        // A session is expected to have exactly one system message (only ChatReaction's
        // is_null($session) branch ever adds one - see the class doc), so the export doesn't
        // join several. Should stray data ever contain more than one regardless, exporting just
        // the first one is preferable to silently concatenating them into a confusing cell.
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, [
            $this->systemMessage('Original prompt'),
            $this->systemMessage('Stray second prompt'),
        ]);

        $csv = $service->export([$session], ['systemPrompt']);
        $rows = $this->parseCsv($csv);

        self::assertSame(['Original prompt'], $rows[1]);
    }

    public function testExportCreatesOneRowPerQuestionAnswerTurnInsteadOfJoiningThem(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, [
            $this->userMessage('First question?'),
            $this->assistantMessage('First answer.'),
            $this->userMessage('Second question?'),
            $this->assistantMessage('Second answer.'),
        ]);

        $csv = $service->export([$session], ['sessionId', 'questions', 'answers']);
        $rows = $this->parseCsv($csv);

        self::assertCount(3, $rows, 'Expected a header row plus one row per turn.');
        self::assertSame(['session-1', 'First question?', 'First answer.'], $rows[1]);
        self::assertSame(['session-1', 'Second question?', 'Second answer.'], $rows[2]);
    }

    public function testExportDoesNotSplitIntoMultipleRowsWhenQuestionsAndAnswersAreNotSelected(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, [
            $this->userMessage('First question?'),
            $this->assistantMessage('First answer.'),
            $this->userMessage('Second question?'),
            $this->assistantMessage('Second answer.'),
        ]);

        $csv = $service->export([$session], ['sessionId']);
        $rows = $this->parseCsv($csv);

        self::assertCount(2, $rows, 'Without questions/answers selected there is nothing to split per turn.');
        self::assertSame(['session-1'], $rows[1]);
    }

    public function testExportTreatsEachSessionRowIndependentlyEvenIfSessionIdsCollide(): void
    {
        // SessionRepository::save() always finds and updates the existing row for a sessionId,
        // so a conversation is expected to live entirely in one row (see the class doc). Should
        // two rows ever end up sharing a sessionId regardless (e.g. stale/corrupt data), the
        // export must not merge them into a single conversation - it exports each row as-is.
        $service = new SessionCsvExportService();
        $firstRow = $this->buildSession('session-1', 1735689600, [
            $this->userMessage('First question?'),
            $this->assistantMessage('First answer.'),
        ]);
        $secondRow = $this->buildSession('session-1', 1735689700, [
            $this->userMessage('Second question?'),
            $this->assistantMessage('Second answer.'),
        ]);

        $csv = $service->export([$firstRow, $secondRow], ['sessionId', 'createdAt', 'questions', 'answers']);
        $rows = $this->parseCsv($csv);

        self::assertCount(3, $rows, 'Expected a header row plus one row per Session entity.');
        self::assertSame(
            ['session-1', (new \DateTime())->setTimestamp(1735689600)->format('Y-m-d H:i:s'), 'First question?', 'First answer.'],
            $rows[1],
        );
        self::assertSame(
            ['session-1', (new \DateTime())->setTimestamp(1735689700)->format('Y-m-d H:i:s'), 'Second question?', 'Second answer.'],
            $rows[2],
        );
    }

    public function testExportPairsAQuestionWithoutAFollowingAnswer(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, [
            $this->userMessage('Answered question?'),
            $this->assistantMessage('The answer.'),
            $this->userMessage('Unanswered question?'),
        ]);

        $csv = $service->export([$session], ['questions', 'answers']);
        $rows = $this->parseCsv($csv);

        self::assertCount(3, $rows);
        self::assertSame(['Answered question?', 'The answer.'], $rows[1]);
        self::assertSame(['Unanswered question?', ''], $rows[2]);
    }

    public function testExportKeepsUnrelatedSessionsAsSeparateRows(): void
    {
        $service = new SessionCsvExportService();
        $sessionA = $this->buildSession('session-a', 1735689600, [$this->userMessage('A question?')]);
        $sessionB = $this->buildSession('session-b', 1735689700, [$this->userMessage('B question?')]);

        $csv = $service->export([$sessionA, $sessionB], ['sessionId', 'questions']);
        $rows = $this->parseCsv($csv);

        self::assertCount(3, $rows, 'Expected a header row plus one row per distinct sessionId.');
        self::assertSame(['session-a', 'A question?'], $rows[1]);
        self::assertSame(['session-b', 'B question?'], $rows[2]);
    }

    public function testExportFallsBackToAllFieldsWhenNoneAreSelected(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, []);

        $csv = $service->export([$session], []);
        $rows = $this->parseCsv($csv);

        self::assertSame(array_keys($service->getAvailableFields()), $rows[0]);
    }

    public function testExportIgnoresUnknownFields(): void
    {
        $service = new SessionCsvExportService();
        $session = $this->buildSession('session-1', 1735689600, []);

        $csv = $service->export([$session], ['sessionId', 'somethingUnknown']);
        $rows = $this->parseCsv($csv);

        self::assertSame(['sessionId'], $rows[0]);
    }

    public function testExportPrependsUtf8ByteOrderMarkForExcelCompatibility(): void
    {
        $service = new SessionCsvExportService();

        $csv = $service->export([], ['sessionId']);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }
}
