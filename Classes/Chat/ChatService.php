<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Factories\AgentFactory;

/**
 * One chat turn: load the conversation, ask the model with a capped history and output size,
 * store the full conversation again. Replaces Symfony\AI\Chat\Chat, which always resends the
 * whole history and cannot pass options such as max_tokens.
 */
class ChatService
{
    public function __construct(
        private readonly SessionRepository $sessionRepository,
        private readonly AgentFactory $agentFactory,
    ) {}

    /**
     * @param string $sessionId server-issued session id (ChatSessionCookie)
     * @param string $clientMarkerHash hash of the visitor's consent marker cookie, stored with new rows
     * @return string the assistant's answer
     */
    public function respond(ChatConfiguration $configuration, string $sessionId, string $clientMarkerHash, string $userText): string
    {
        $this->sessionRepository->setup([
            'sessionId' => $sessionId,
            'configuration' => $configuration->uid,
            'clientMarker' => $clientMarkerHash,
        ]);

        $history = $this->sessionRepository->load();
        if (count($history) === 0) {
            $history = new MessageBag(Message::forSystem($configuration->systemMessage));
        }

        $userMessage = Message::ofUser($userText);
        $input = HistoryWindow::apply($history, $configuration->historyWindow, $userMessage);

        $sentMessages = count($input);
        $result = $this->agentFactory
            ->create($configuration)
            ->call($input, ['max_tokens' => $configuration->maxTokens]);

        $assistantMessage = Message::ofAssistant((string)$result->getContent());

        $history->add($userMessage);
        // The agent appends its tool rounds (the assistant's tool calls and the tool results) to the
        // bag it was given (AgentProcessor with keepToolMessages). They belong to the stored
        // transcript, which the backend module shows; HistoryWindow leaves them out of the next
        // request anyway.
        foreach (array_slice($input->getMessages(), $sentMessages) as $toolRoundMessage) {
            $history->add($toolRoundMessage);
        }
        $history->add($assistantMessage);
        $this->sessionRepository->save($history);

        return $assistantMessage->getContent() ?? '';
    }
}
