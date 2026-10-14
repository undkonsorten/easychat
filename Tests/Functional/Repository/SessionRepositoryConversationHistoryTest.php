<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Repository;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

/**
 * SessionRepository is the message store of the chat: it must write and read back every message
 * type the agent produces. The conversation flow itself (one row per session and chatbot, exactly
 * one system message, history window) is covered by Tests/Functional/Chat/ChatServiceTest.php.
 */
final class SessionRepositoryConversationHistoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    /**
     * With a knowledge base the agent adds the similarity search round to the history: an assistant
     * message with the tool call and the tool's answer. Both carry a ToolCall, which the serializer
     * must be able to write and read back.
     */
    public function testHistoryWithToolCallsCanBeSavedAndLoaded(): void
    {
        $toolCall = new ToolCall('call-1', 'similarity_search', ['searchTerm' => 'test hedgehog']);
        $repository = $this->get(SessionRepository::class);
        $repository->setup(['sessionId' => 'tool-conversation']);
        $repository->save(new MessageBag(
            Message::forSystem('be nice'),
            Message::ofUser('What is the name of the test hedgehog?'),
            Message::ofAssistant($toolCall),
            Message::ofToolCall($toolCall, 'Found documents with the following information: Tabula'),
            Message::ofAssistant('The test hedgehog is called Tabula.'),
        ));

        $history = $repository->load()->getMessages();

        self::assertCount(5, $history);
        self::assertInstanceOf(AssistantMessage::class, $history[2]);
        self::assertTrue($history[2]->hasToolCalls());
        self::assertSame(['searchTerm' => 'test hedgehog'], $history[2]->getToolCalls()[0]->getArguments());
        self::assertInstanceOf(ToolCallMessage::class, $history[3]);
        self::assertSame('call-1', $history[3]->getToolCall()->getId());
        self::assertInstanceOf(AssistantMessage::class, $history[4]);
        self::assertSame('The test hedgehog is called Tabula.', $history[4]->asText());
    }
}
