<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Backend;

/**
 * Turns the stored message JSON of a session into rows the backend module can render: the role
 * of every message, its text, the tool calls an assistant message made and the documents a tool
 * result (similarity_search) returned. Understands the JSON written by symfony/ai 0.1 as well as
 * 0.13+, and leaves anything it does not understand readable as plain text.
 */
final class ConversationFormatter
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    /** Longer document excerpts are cut for display; the model saw the full text. */
    public const EXCERPT_LENGTH = 400;

    /**
     * @param array<int|string, mixed> $messages decoded "messages" column
     * @return list<array{role: string, text: string, toolCalls: list<array{name: string, arguments: string}>, documents: list<array{title: string, uri: string, text: string}>}>
     */
    public function format(array $messages): array
    {
        $rows = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $rows[] = $this->formatMessage($message);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{role: string, text: string, toolCalls: list<array{name: string, arguments: string}>, documents: list<array{title: string, uri: string, text: string}>}
     */
    private function formatMessage(array $message): array
    {
        $type = (string)($message['type'] ?? '');
        $role = match (true) {
            str_ends_with($type, '\\SystemMessage') => self::ROLE_SYSTEM,
            str_ends_with($type, '\\UserMessage') => self::ROLE_USER,
            str_ends_with($type, '\\AssistantMessage') => self::ROLE_ASSISTANT,
            str_ends_with($type, '\\ToolCallMessage') => self::ROLE_TOOL,
            default => 'unknown',
        };
        $row = ['role' => $role, 'text' => $this->text($message), 'toolCalls' => [], 'documents' => []];

        $toolCalls = $message['toolsCalls'] ?? [];
        if ($role === self::ROLE_TOOL && is_array($toolCalls) && (isset($toolCalls['function']) || isset($toolCalls['name']))) {
            // a tool result stores the one tool call it answers, not a list
            $toolCalls = [$toolCalls];
        }
        if (is_array($toolCalls)) {
            $row['toolCalls'] = $this->toolCalls($toolCalls);
        }
        if ($role === self::ROLE_TOOL) {
            [$row['text'], $row['documents']] = $this->splitToolResult($row['text']);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function text(array $message): string
    {
        if (is_string($message['content'] ?? null) && $message['content'] !== '') {
            return $message['content'];
        }
        $texts = [];
        // symfony/ai 0.1 and user messages: "contentAsBase64" holds Text parts with "content"
        foreach ((array)($message['contentAsBase64'] ?? []) as $part) {
            if (is_array($part) && is_string($part['content'] ?? null)) {
                $texts[] = $part['content'];
            }
        }
        // symfony/ai 0.13+: "parts" holds Text parts with "text"
        if ($texts === []) {
            foreach ((array)($message['parts'] ?? []) as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
        }

        return implode("\n", $texts);
    }

    /**
     * @param array<int|string, mixed> $toolCalls entries shaped {id, type, function: {name, arguments}} or {name, arguments}
     * @return list<array{name: string, arguments: string}>
     */
    private function toolCalls(array $toolCalls): array
    {
        $rows = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : $toolCall;
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : ['' => $arguments];
            }
            $pairs = [];
            foreach ((array)$arguments as $name => $value) {
                $pairs[] = ($name === '' ? '' : $name . ': ') . (is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $rows[] = ['name' => (string)($function['name'] ?? ''), 'arguments' => implode(', ', $pairs)];
        }

        return $rows;
    }

    /**
     * The similarity_search tool answers with an intro line followed by the metadata of every
     * document as JSON objects written back to back. Split them so the module can list them.
     *
     * @return array{0: string, 1: list<array{title: string, uri: string, text: string}>}
     */
    private function splitToolResult(string $text): array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return [$text, []];
        }
        $documents = [];
        foreach ($this->topLevelJsonObjects(substr($text, $start)) as $json) {
            $document = json_decode($json, true);
            if (!is_array($document)) {
                continue;
            }
            $documents[] = [
                'title' => (string)($document['title'] ?? ''),
                'uri' => (string)($document['uri'] ?? ''),
                'text' => $this->excerpt((string)($document['_text'] ?? $document['text'] ?? '')),
            ];
        }
        if ($documents === []) {
            return [$text, []];
        }

        return [trim(substr($text, 0, $start)), $documents];
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > self::EXCERPT_LENGTH ? rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH)) . ' …' : $text;
    }

    /**
     * @return list<string>
     */
    private function topLevelJsonObjects(string $text): array
    {
        $objects = [];
        $depth = 0;
        $inString = false;
        $escaped = false;
        $objectStart = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $objectStart = $i;
                }
                $depth++;
            } elseif ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $objectStart !== null) {
                    $objects[] = substr($text, $objectStart, $i - $objectStart + 1);
                    $objectStart = null;
                }
            }
        }

        return $objects;
    }
}
