<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Chat;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Chat\ChatConfiguration;
use Undkonsorten\Easychat\Chat\ChatService;
use Undkonsorten\Easychat\Domain\Model\Session;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Tests\Fixtures\Chat\RecordingAgentFactory;

/**
 * ChatService drives one chat turn: it stores the whole conversation in one row per session and
 * chatbot, but sends the model only the configured window of it, capped with max_tokens.
 */
final class ChatServiceTest extends FunctionalTestCase
{
    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private RecordingAgentFactory $agentFactory;

    private SessionRepository $repository;

    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentFactory = new RecordingAgentFactory();
        $this->repository = $this->get(SessionRepository::class);
        $this->service = new ChatService($this->repository, $this->agentFactory);
    }

    public function testMultipleTurnsAccumulateInOneRowWithExactlyOneSystemMessage(): void
    {
        $configuration = $this->configuration(['system_message' => 'be nice']);

        self::assertSame('answer-1', $this->service->respond($configuration, self::SESSION, sha1('marker'), 'question one'));
        self::assertSame('answer-2', $this->service->respond($configuration, self::SESSION, sha1('marker'), 'question two'));
        self::assertSame('answer-3', $this->service->respond($configuration, self::SESSION, sha1('marker'), 'question three'));

        $rows = $this->repository->findBy(['sessionId' => self::SESSION]);
        self::assertCount(1, $rows, 'A conversation must stay in exactly one row.');
        $session = $rows->getFirst();
        self::assertSame(1, $session->getConfiguration());
        self::assertSame(sha1('marker'), $session->getClientMarker());

        $messages = json_decode((string)$session->getMessages(), true);
        $typeCounts = array_count_values(array_column($messages, 'type'));
        self::assertSame(1, $typeCounts[SystemMessage::class] ?? 0, 'Exactly one system message, no matter how many turns happened.');
        self::assertSame(3, $typeCounts[UserMessage::class] ?? 0, 'The full history is stored, not the window.');
        self::assertSame(3, $typeCounts[AssistantMessage::class] ?? 0);
    }

    public function testOnlyTheHistoryWindowIsSentToTheModel(): void
    {
        $configuration = $this->configuration(['system_message' => 'be nice', 'history_window' => 2]);

        $this->service->respond($configuration, self::SESSION, sha1('marker'), 'q1');
        $this->service->respond($configuration, self::SESSION, sha1('marker'), 'q2');
        $this->service->respond($configuration, self::SESSION, sha1('marker'), 'q3');

        self::assertSame(['be nice', 'q1'], $this->texts($this->agentFactory->inputs[0]));
        self::assertSame(['be nice', 'q1', 'answer-1', 'q2'], $this->texts($this->agentFactory->inputs[1]));
        self::assertSame(['be nice', 'q2', 'answer-2', 'q3'], $this->texts($this->agentFactory->inputs[2]), 'q1 and its answer fell out of the window of 2.');
    }

    public function testMaxTokensIsPassedToTheModel(): void
    {
        $this->service->respond($this->configuration(['max_tokens' => 77]), self::SESSION, sha1('marker'), 'q1');

        self::assertSame(['max_tokens' => 77], $this->agentFactory->options[0]);
    }

    public function testAVisitorHasOneConversationPerChatbot(): void
    {
        $chatbotA = $this->configuration(['uid' => 1, 'system_message' => 'you are A']);
        $chatbotB = $this->configuration(['uid' => 2, 'system_message' => 'you are B']);

        $this->service->respond($chatbotA, self::SESSION, sha1('marker'), 'to A');
        $this->service->respond($chatbotB, self::SESSION, sha1('marker'), 'to B');
        $this->service->respond($chatbotA, self::SESSION, sha1('marker'), 'to A again');

        self::assertCount(2, $this->repository->findBy(['sessionId' => self::SESSION]));
        self::assertSame(['you are B', 'to B'], $this->texts($this->agentFactory->inputs[1]));
        self::assertSame(['you are A', 'to A', 'answer-1', 'to A again'], $this->texts($this->agentFactory->inputs[2]), 'Chatbot A continues its own conversation.');
    }

    public function testChangingTheConfiguredSystemMessageDoesNotUpdateAnAlreadyActiveSession(): void
    {
        $this->service->respond($this->configuration(['system_message' => 'original prompt']), self::SESSION, sha1('marker'), 'question one');
        // An admin edits tx_easychat_configuration.system_message here...
        $this->service->respond($this->configuration(['system_message' => 'updated prompt']), self::SESSION, sha1('marker'), 'question two');

        $messages = json_decode((string)$this->repository->findBy(['sessionId' => self::SESSION])->getFirst()->getMessages(), true);
        $systemMessages = array_values(array_filter($messages, static fn(array $message): bool => $message['type'] === SystemMessage::class));

        self::assertCount(1, $systemMessages);
        self::assertSame('original prompt', $systemMessages[0]['content'], 'A running conversation stays on the prompt it started with.');
        self::assertSame(['original prompt', 'question one', 'answer-1', 'question two'], $this->texts($this->agentFactory->inputs[1]));
    }

    /**
     * A session stored by EasyChat 0.2 must load, and a new turn must be appended without losing
     * the old ones.
     */
    public function testSessionStoredWithSymfonyAi01CanBeContinued(): void
    {
        $legacyMessages = [
            ['id' => '019a0000-0000-7000-8000-000000000001', 'type' => SystemMessage::class, 'content' => 'be nice', 'contentAsBase64' => [], 'toolsCalls' => [], 'metadata' => [], 'addedAt' => 1767225600],
            ['id' => '019a0000-0000-7000-8000-000000000002', 'type' => UserMessage::class, 'content' => '', 'contentAsBase64' => [['type' => Text::class, 'content' => 'old question']], 'toolsCalls' => [], 'metadata' => [], 'addedAt' => 1767225601],
            ['id' => '019a0000-0000-7000-8000-000000000003', 'type' => AssistantMessage::class, 'content' => 'old answer', 'contentAsBase64' => [], 'toolsCalls' => [], 'metadata' => [], 'addedAt' => 1767225602],
        ];
        $session = new Session();
        $session->setPid(1);
        $session->setSessionId(self::SESSION);
        $session->setConfiguration(1);
        $session->setMessages(json_encode($legacyMessages, JSON_THROW_ON_ERROR));
        $this->repository->add($session);
        $this->get(PersistenceManagerInterface::class)->persistAll();

        $this->service->respond($this->configuration(['uid' => 1]), self::SESSION, sha1('marker'), 'new question');

        $messages = json_decode((string)$this->repository->findBy(['sessionId' => self::SESSION])->getFirst()->getMessages(), true);
        self::assertSame(['be nice', '', 'old answer', '', 'answer-1'], array_column($messages, 'content'));
        self::assertSame(['be nice', 'old question', 'old answer', 'new question'], $this->texts($this->agentFactory->inputs[0]));
    }

    /**
     * With RAG the agent runs tool rounds (an assistant message calling similarity_search, then
     * the tool's result) before it answers. They belong to the stored transcript, which the
     * backend module shows, but not to the next request to the model.
     */
    public function testToolRoundsOfTheAgentAreStoredButNotResent(): void
    {
        $this->agentFactory->toolRound = new ToolCall('call-1', 'similarity_search', ['searchTerm' => 'hedgehog']);
        self::assertSame('answer-1', $this->service->respond($this->configuration(), self::SESSION, sha1('marker'), 'question one'));
        $this->agentFactory->toolRound = null;
        self::assertSame('answer-2', $this->service->respond($this->configuration(), self::SESSION, sha1('marker'), 'question two'));

        $this->repository->setup(['sessionId' => self::SESSION, 'configuration' => 1]);
        $stored = $this->repository->load()->getMessages();
        self::assertSame(
            [SystemMessage::class, UserMessage::class, AssistantMessage::class, ToolCallMessage::class, AssistantMessage::class, UserMessage::class, AssistantMessage::class],
            array_map(static fn(MessageInterface $message): string => $message::class, $stored),
            'The tool round sits between the question and the answer.',
        );
        self::assertInstanceOf(AssistantMessage::class, $stored[2]);
        self::assertTrue($stored[2]->hasToolCalls());
        self::assertSame('similarity_search', $stored[2]->getToolCalls()[0]->getName());
        self::assertInstanceOf(ToolCallMessage::class, $stored[3]);
        self::assertSame('call-1', $stored[3]->getToolCall()->getId());
        self::assertSame(['be nice', 'question one', 'answer-1', 'question two'], $this->texts($this->agentFactory->inputs[1]), 'Tool rounds are not sent again.');
    }

    public function testAFailingModelLeavesNoHalfConversationBehind(): void
    {
        $this->agentFactory->failure = new \RuntimeException('connection to llm.internal refused');

        try {
            $this->service->respond($this->configuration(), self::SESSION, sha1('marker'), 'q1');
            self::fail('The provider error must surface to the caller.');
        } catch (\RuntimeException) {
        }

        self::assertCount(0, $this->repository->findBy(['sessionId' => self::SESSION]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configuration(array $row = []): ChatConfiguration
    {
        return ChatConfiguration::fromRow(array_replace(['uid' => 1, 'model' => 'model-a', 'system_message' => 'be nice', 'vector_db' => 'none'], $row));
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
