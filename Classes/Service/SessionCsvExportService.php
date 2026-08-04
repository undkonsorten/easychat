<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Service;

use Undkonsorten\Easychat\Domain\Model\Session;

/**
 * Builds CSV exports of chat sessions.
 *
 * The `messages` column holds a JSON-encoded Symfony AI MessageBag, so this
 * service lets callers pick which parts of it (system prompt, questions,
 * answers) become CSV columns instead of always exporting the raw blob.
 *
 * Each tx_easychat_domain_model_session row is one conversation: SessionRepository::save()
 * always finds and updates the existing row for a sessionId (never inserts a second one for
 * the same session), so one row already holds the full accumulated message history.
 *
 * When the questions and/or answers column is selected, each question/answer
 * turn of a conversation becomes its own CSV row (with sessionId, createdAt
 * and systemPrompt repeated), rather than concatenating every turn into a
 * single cell.
 */
class SessionCsvExportService
{
    public const FIELD_SESSION_ID = 'sessionId';
    public const FIELD_CREATED_AT = 'createdAt';
    public const FIELD_SYSTEM_PROMPT = 'systemPrompt';
    public const FIELD_QUESTIONS = 'questions';
    public const FIELD_ANSWERS = 'answers';

    private const SYSTEM_MESSAGE_TYPE = 'Symfony\AI\Platform\Message\SystemMessage';
    private const USER_MESSAGE_TYPE = 'Symfony\AI\Platform\Message\UserMessage';
    private const ASSISTANT_MESSAGE_TYPE = 'Symfony\AI\Platform\Message\AssistantMessage';
    private const TEXT_CONTENT_TYPE = 'Symfony\AI\Platform\Message\Content\Text';

    /**
     * @return array<string, string> field key => human-readable label, in export column order
     */
    public function getAvailableFields(): array
    {
        return [
            self::FIELD_SESSION_ID => 'Session id',
            self::FIELD_CREATED_AT => 'Created at',
            self::FIELD_SYSTEM_PROMPT => 'System prompt',
            self::FIELD_QUESTIONS => 'Questions',
            self::FIELD_ANSWERS => 'Answers',
        ];
    }

    /**
     * @param iterable<Session> $sessions
     * @param string[] $fields subset (and order) of self::getAvailableFields() keys to include;
     *                         falls back to all fields when empty or unrecognised
     */
    public function export(iterable $sessions, array $fields): string
    {
        $fields = array_values(array_intersect($fields, array_keys($this->getAvailableFields())));
        if ($fields === []) {
            $fields = array_keys($this->getAvailableFields());
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $fields, ',', '"', '');
        foreach ($sessions as $session) {
            foreach ($this->buildRows($session, $fields) as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF" . (string)$csv;
    }

    /**
     * @param string[] $fields
     * @return iterable<string[]> one row per question/answer turn (or a single row when
     *                            neither questions nor answers are selected)
     */
    private function buildRows(Session $session, array $fields): iterable
    {
        $decoded = json_decode((string)$session->getMessages(), true);
        $messages = is_array($decoded) ? $decoded : [];

        $sessionValues = [
            self::FIELD_SESSION_ID => (string)$session->getSessionId(),
            self::FIELD_CREATED_AT => $session->getCreatedAt()->format('Y-m-d H:i:s'),
            self::FIELD_SYSTEM_PROMPT => $this->extractSystemPrompt($messages),
        ];

        $needsTurns = in_array(self::FIELD_QUESTIONS, $fields, true) || in_array(self::FIELD_ANSWERS, $fields, true);
        if (!$needsTurns) {
            yield array_map(fn (string $field): string => $sessionValues[$field] ?? '', $fields);

            return;
        }

        $turns = $this->extractTurns($messages);
        if ($turns === []) {
            $turns = [['question' => '', 'answer' => '']];
        }

        foreach ($turns as $turn) {
            yield array_map(
                fn (string $field): string => match ($field) {
                    self::FIELD_QUESTIONS => $turn['question'],
                    self::FIELD_ANSWERS => $turn['answer'],
                    default => $sessionValues[$field] ?? '',
                },
                $fields,
            );
        }
    }

    /**
     * Pairs each user question with the assistant answer(s) that follow it, so a
     * conversation with several turns becomes several CSV rows instead of concatenating
     * every question (and every answer) into one cell.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return list<array{question: string, answer: string}>
     */
    private function extractTurns(array $messages): array
    {
        $turns = [];
        $question = null;
        $answerParts = [];

        foreach ($messages as $message) {
            $type = $message['type'] ?? null;

            if ($type === self::USER_MESSAGE_TYPE) {
                if ($question !== null || $answerParts !== []) {
                    $turns[] = ['question' => $question ?? '', 'answer' => implode("\n", $answerParts)];
                }
                $question = $this->messageText($message);
                $answerParts = [];

                continue;
            }

            if ($type === self::ASSISTANT_MESSAGE_TYPE) {
                $text = $this->messageText($message);
                if ($text !== '') {
                    $answerParts[] = $text;
                }
            }
        }

        if ($question !== null || $answerParts !== []) {
            $turns[] = ['question' => $question ?? '', 'answer' => implode("\n", $answerParts)];
        }

        return $turns;
    }

    /**
     * A session has exactly one system message (see the class doc), so this returns that
     * single message's text rather than collecting/joining several.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function extractSystemPrompt(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) === self::SYSTEM_MESSAGE_TYPE) {
                return $this->messageText($message);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function messageText(array $message): string
    {
        if (($message['content'] ?? '') !== '') {
            return (string)$message['content'];
        }

        $parts = [];
        foreach ($message['contentAsBase64'] ?? [] as $content) {
            if (($content['type'] ?? null) === self::TEXT_CONTENT_TYPE) {
                $parts[] = (string)($content['content'] ?? '');
            }
        }

        return implode("\n", $parts);
    }
}
