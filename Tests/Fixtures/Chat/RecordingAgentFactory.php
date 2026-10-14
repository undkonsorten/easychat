<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Fixtures\Chat;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Undkonsorten\Easychat\Chat\ChatConfiguration;
use Undkonsorten\Easychat\Factories\AgentFactory;

/**
 * Stands in for the real agent: answers "answer-1", "answer-2", ... and records what the model
 * would have been sent, so tests can check the history window and the options.
 */
final class RecordingAgentFactory extends AgentFactory
{
    /** @var list<MessageBag> */
    public array $inputs = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /** @var list<ChatConfiguration> */
    public array $configurations = [];

    public ?\Throwable $failure = null;

    /** When set, the agent runs this tool round before answering, like the real one does with RAG. */
    public ?ToolCall $toolRound = null;

    private int $calls = 0;

    public function create(ChatConfiguration $configuration): AgentInterface
    {
        $this->configurations[] = $configuration;
        $factory = $this;

        return new class ($factory) implements AgentInterface {
            public function __construct(private readonly RecordingAgentFactory $factory) {}

            public function call(MessageBag $messages, array $options = []): ResultInterface
            {
                $this->factory->inputs[] = $messages;
                $this->factory->options[] = $options;
                if ($this->factory->failure !== null) {
                    throw $this->factory->failure;
                }
                $toolRound = $this->factory->toolRound;
                if ($toolRound !== null) {
                    // AgentProcessor with keepToolMessages appends tool rounds to the caller's bag
                    $messages->add(Message::ofAssistant(null, [$toolRound]));
                    $messages->add(Message::ofToolCall($toolRound, 'result of ' . $toolRound->getId()));
                }

                return new TextResult('answer-' . $this->factory->nextCall());
            }

            public function getName(): string
            {
                return 'recording';
            }
        };
    }

    public function nextCall(): int
    {
        return ++$this->calls;
    }
}
