<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Repository;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Chat\Chat;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

/**
 * Reproduces the exact guarded flow ChatReaction::react() drives through Chat +
 * SessionRepository: initiate() only fires once, when no row exists yet for the
 * sessionId; every later message goes through submit(), which loads the full
 * existing history, appends the new turn, and saves the whole bag back.
 *
 * This covers two invariants that have no other integration-level coverage:
 * exactly one row per sessionId (Chat/SessionRepository accumulate in place,
 * see SessionCsvExportService's class doc), and exactly one system message per
 * session (only ChatReaction's is_null($session) branch ever adds one).
 */
final class SessionRepositoryConversationHistoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    public function testMultipleTurnsAccumulateInOneRowWithExactlyOneSystemMessage(): void
    {
        $repository = $this->get(SessionRepository::class);
        $repository->setup(['sessionId' => 'conversation-1']);
        $chat = new Chat($this->fakeAgent(), $repository);

        // Mirrors ChatReaction::react(): initiate() only runs for a brand-new session.
        if ($repository->findBy(['session_id' => 'conversation-1'])->getFirst() === null) {
            $chat->initiate(new MessageBag(Message::forSystem('be nice')));
        }
        $chat->submit(Message::ofUser('question one'));

        if ($repository->findBy(['session_id' => 'conversation-1'])->getFirst() === null) {
            $chat->initiate(new MessageBag(Message::forSystem('be nice')));
        }
        $chat->submit(Message::ofUser('question two'));

        if ($repository->findBy(['session_id' => 'conversation-1'])->getFirst() === null) {
            $chat->initiate(new MessageBag(Message::forSystem('be nice')));
        }
        $chat->submit(Message::ofUser('question three'));

        $matches = $repository->findBy(['session_id' => 'conversation-1']);
        self::assertCount(1, $matches, 'A conversation must stay in exactly one row.');

        $messages = json_decode((string)$matches->getFirst()->getMessages(), true);
        $typeCounts = array_count_values(array_column($messages, 'type'));

        self::assertSame(1, $typeCounts['Symfony\AI\Platform\Message\SystemMessage'] ?? 0, 'Exactly one system message is expected, no matter how many turns happened.');
        self::assertSame(3, $typeCounts['Symfony\AI\Platform\Message\UserMessage'] ?? 0);
        self::assertSame(3, $typeCounts['Symfony\AI\Platform\Message\AssistantMessage'] ?? 0);
    }

    public function testChangingTheConfiguredSystemMessageDoesNotUpdateAnAlreadyActiveSession(): void
    {
        // ChatReaction::react() re-fetches tx_easychat_configuration on every request, but only
        // ever turns its system_message into a SystemMessage inside the is_null($session) branch
        // (see initiate() calls below). So editing that configuration field while a session is
        // already running has no effect on that session: the agent keeps being sent whichever
        // system message was current when the session started, for every later turn too.
        $repository = $this->get(SessionRepository::class);
        $repository->setup(['sessionId' => 'conversation-1']);
        $chat = new Chat($this->fakeAgent(), $repository);

        if ($repository->findBy(['session_id' => 'conversation-1'])->getFirst() === null) {
            $chat->initiate(new MessageBag(Message::forSystem('original prompt')));
        }
        $chat->submit(Message::ofUser('question one'));

        // An admin edits tx_easychat_configuration.system_message here. ChatReaction would read
        // this new value on the next request...
        $currentlyConfiguredSystemMessage = 'updated prompt';

        // ...but the session already exists, so is_null($session) is false and initiate() -
        // the only place a system message is ever written - never runs for this turn.
        if ($repository->findBy(['session_id' => 'conversation-1'])->getFirst() === null) {
            $chat->initiate(new MessageBag(Message::forSystem($currentlyConfiguredSystemMessage)));
        }
        $chat->submit(Message::ofUser('question two'));

        $session = $repository->findBy(['session_id' => 'conversation-1'])->getFirst();
        $messages = json_decode((string)$session->getMessages(), true);
        $systemMessages = array_values(array_filter(
            $messages,
            static fn (array $message): bool => $message['type'] === 'Symfony\AI\Platform\Message\SystemMessage',
        ));

        self::assertCount(1, $systemMessages, 'Still exactly one system message - the config change did not add a second one.');
        self::assertSame(
            'original prompt',
            $systemMessages[0]['content'],
            'A tx_easychat_configuration change made mid-session does not retroactively update an already active session; it stays on the prompt the session started with.',
        );
    }

    private function fakeAgent(): AgentInterface
    {
        return new class implements AgentInterface {
            private int $calls = 0;

            public function call(MessageBag $messages, array $options = []): ResultInterface
            {
                $this->calls++;

                return new TextResult('answer-' . $this->calls);
            }

            public function getName(): string
            {
                return 'fake';
            }
        };
    }
}
