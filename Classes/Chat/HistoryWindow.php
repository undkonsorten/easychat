<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Builds the messages sent to the model from the full stored conversation: the system message,
 * the last $window conversational turns and the new user message. The full history stays in
 * the database untouched; this only caps what every request costs.
 *
 * Tool rounds (an assistant message with tool calls and the tool result) are left out of the
 * window. Their final text answer is kept, and a window can never start on a tool result, which
 * OpenAI-compatible APIs reject.
 */
final class HistoryWindow
{
    public static function apply(MessageBag $history, int $window, UserMessage $newMessage): MessageBag
    {
        $systemMessage = null;
        $conversation = [];
        foreach ($history->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $systemMessage ??= $message;
                continue;
            }
            if ($message instanceof ToolCallMessage) {
                continue;
            }
            if ($message instanceof AssistantMessage && $message->hasToolCalls()) {
                continue;
            }
            $conversation[] = $message;
        }

        $slice = $window > 0 ? array_slice($conversation, -$window) : [];
        // Never start the window with the answer to a question the model does not see.
        while ($slice !== [] && !($slice[0] instanceof UserMessage)) {
            array_shift($slice);
        }

        $messages = $slice;
        if ($systemMessage !== null) {
            array_unshift($messages, $systemMessage);
        }
        $messages[] = $newMessage;

        return new MessageBag(...$messages);
    }
}
