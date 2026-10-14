<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Fixtures\Chat;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
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

            public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
            {
                $this->factory->inputs[] = $input instanceof MessageBag ? $input : new MessageBag();
                $this->factory->options[] = $options;
                $answer = 'answer-' . $this->factory->nextCall();
                $failure = $this->factory->failure;
                $toolRound = $this->factory->toolRound;

                return new Execution(static function () use ($input, $answer, $failure, $toolRound): \Generator {
                    if ($failure !== null) {
                        throw $failure;
                    }
                    if ($toolRound !== null && $input instanceof MessageBag) {
                        // Symfony\AI\Agent\Execution\Runner appends tool rounds to the caller's bag
                        $input->add(Message::ofAssistant($toolRound));
                        $input->add(Message::ofToolCall($toolRound, 'result of ' . $toolRound->getId()));
                    }
                    yield new Result(new TextResult($answer));
                });
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
