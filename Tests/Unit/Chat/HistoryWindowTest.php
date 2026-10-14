<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Chat;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Undkonsorten\Easychat\Chat\HistoryWindow;

final class HistoryWindowTest extends TestCase
{
    public function testKeepsSystemMessageAndTheLastTurns(): void
    {
        $history = new MessageBag(Message::forSystem('be nice'));
        for ($i = 1; $i <= 15; $i++) {
            $history->add(Message::ofUser('q' . $i));
            $history->add(Message::ofAssistant('a' . $i));
        }

        $result = HistoryWindow::apply($history, 20, Message::ofUser('q16'));

        self::assertSame(
            ['be nice', 'q6', 'a6', 'q7', 'a7', 'q8', 'a8', 'q9', 'a9', 'q10', 'a10', 'q11', 'a11', 'q12', 'a12', 'q13', 'a13', 'q14', 'a14', 'q15', 'a15', 'q16'],
            $this->texts($result),
        );
        self::assertInstanceOf(SystemMessage::class, $result->getMessages()[0]);
    }

    public function testWindowZeroSendsOnlySystemMessageAndTheNewQuestion(): void
    {
        $history = new MessageBag(Message::forSystem('be nice'), Message::ofUser('q1'), Message::ofAssistant('a1'));

        $result = HistoryWindow::apply($history, 0, Message::ofUser('q2'));

        self::assertSame(['be nice', 'q2'], $this->texts($result));
    }

    public function testWindowNeverStartsWithAnAssistantMessage(): void
    {
        $history = new MessageBag(Message::forSystem('be nice'), Message::ofUser('q1'), Message::ofAssistant('a1'), Message::ofUser('q2'), Message::ofAssistant('a2'));

        // An odd window would cut between q1 and a1; the orphaned a1 must be dropped too.
        $result = HistoryWindow::apply($history, 3, Message::ofUser('q3'));

        self::assertSame(['be nice', 'q2', 'a2', 'q3'], $this->texts($result));
        self::assertInstanceOf(UserMessage::class, $result->getMessages()[1]);
    }

    public function testToolRoundsAreLeftOutButTheirFinalAnswerIsKept(): void
    {
        $toolCall = new ToolCall('call-1', 'similarity_search', ['searchTerm' => 'hedgehog']);
        $history = new MessageBag(
            Message::forSystem('be nice'),
            Message::ofUser('q1'),
            Message::ofAssistant(null, [$toolCall]),
            Message::ofToolCall($toolCall, 'Found: Tabula'),
            Message::ofAssistant('The hedgehog is called Tabula.'),
        );

        $result = HistoryWindow::apply($history, 20, Message::ofUser('q2'));

        self::assertSame(['be nice', 'q1', 'The hedgehog is called Tabula.', 'q2'], $this->texts($result));
        foreach ($result->getMessages() as $message) {
            if ($message instanceof AssistantMessage) {
                self::assertFalse($message->hasToolCalls());
            }
        }
    }

    public function testWorksWithoutSystemMessage(): void
    {
        $history = new MessageBag(Message::ofUser('q1'), Message::ofAssistant('a1'));

        $result = HistoryWindow::apply($history, 20, Message::ofUser('q2'));

        self::assertSame(['q1', 'a1', 'q2'], $this->texts($result));
    }

    public function testTheStoredHistoryIsNotModified(): void
    {
        $history = new MessageBag(Message::forSystem('be nice'), Message::ofUser('q1'), Message::ofAssistant('a1'));

        HistoryWindow::apply($history, 1, Message::ofUser('q2'));

        self::assertCount(3, $history);
    }

    /**
     * @return list<string>
     */
    private function texts(MessageBag $bag): array
    {
        return array_map(
            static fn(MessageInterface $message): string => match (true) {
                $message instanceof SystemMessage => (string)$message->getContent(),
                $message instanceof UserMessage => (string)($message->asText() ?? ''),
                $message instanceof AssistantMessage => (string)($message->getContent() ?? ''),
                default => '',
            },
            $bag->getMessages(),
        );
    }
}
