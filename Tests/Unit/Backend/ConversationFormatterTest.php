<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Backend;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Undkonsorten\Easychat\Backend\ConversationFormatter;

final class ConversationFormatterTest extends TestCase
{
    private ConversationFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ConversationFormatter();
    }

    public function testPlainMessagesGetTheirRoleAndText(): void
    {
        $rows = $this->formatter->format([
            ['type' => SystemMessage::class, 'content' => 'be nice'],
            // user messages carry their text in a Text part, "content" is empty
            ['type' => UserMessage::class, 'content' => '', 'contentAsBase64' => [['type' => Text::class, 'content' => 'a question']]],
            // symfony/ai 0.13+ writes assistant text into "content" and "parts"
            ['type' => AssistantMessage::class, 'content' => 'an answer', 'parts' => [['type' => Text::class, 'text' => 'an answer']]],
            ['type' => AssistantMessage::class, 'content' => '', 'parts' => [['type' => Text::class, 'text' => 'only in parts']]],
            'not a message',
        ]);

        self::assertSame(
            [['system', 'be nice'], ['user', 'a question'], ['assistant', 'an answer'], ['assistant', 'only in parts']],
            array_map(static fn(array $row): array => [$row['role'], $row['text']], $rows),
        );
        self::assertSame([], $rows[2]['toolCalls']);
        self::assertSame([], $rows[2]['documents']);
    }

    public function testAssistantToolCallsAreListedWithTheirArguments(): void
    {
        $rows = $this->formatter->format([[
            'type' => AssistantMessage::class,
            'content' => '',
            'toolsCalls' => [
                ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'similarity_search', 'arguments' => '{"searchTerm":"EasyChat vector database"}']],
                ['name' => 'other_tool', 'arguments' => ['limit' => 3, 'tags' => ['a', 'b']]],
            ],
        ]]);

        self::assertSame([
            ['name' => 'similarity_search', 'arguments' => 'searchTerm: EasyChat vector database'],
            ['name' => 'other_tool', 'arguments' => 'limit: 3, tags: ["a","b"]'],
        ], $rows[0]['toolCalls']);
    }

    public function testToolResultDocumentsAreSplitAndLinked(): void
    {
        $content = "Found documents with the following information:\n"
            . '{"title":"Easychat | TYPO3 Easychat","uri":"https:\/\/example.com\/#c103","pageUid":1,"_text":"Backend module\nBrowse sessions."}'
            . '{"title":"Tricky } { braces","uri":"https:\/\/example.com\/faq","_text":"Text with \"quotes\" and {braces} inside"}'
            . '{"uri":"https:\/\/example.com\/untitled"}';
        $rows = $this->formatter->format([[
            'type' => ToolCallMessage::class,
            'content' => $content,
            'toolsCalls' => ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'similarity_search', 'arguments' => '{"searchTerm":"backend"}']],
        ]]);

        self::assertSame('tool', $rows[0]['role']);
        self::assertSame('Found documents with the following information:', $rows[0]['text']);
        self::assertSame([['name' => 'similarity_search', 'arguments' => 'searchTerm: backend']], $rows[0]['toolCalls']);
        self::assertSame([
            ['title' => 'Easychat | TYPO3 Easychat', 'uri' => 'https://example.com/#c103', 'text' => "Backend module\nBrowse sessions."],
            ['title' => 'Tricky } { braces', 'uri' => 'https://example.com/faq', 'text' => 'Text with "quotes" and {braces} inside'],
            ['title' => '', 'uri' => 'https://example.com/untitled', 'text' => ''],
        ], $rows[0]['documents']);
    }

    public function testLongDocumentTextsAreCutToAnExcerpt(): void
    {
        $long = str_repeat('word ', 200);
        $rows = $this->formatter->format([[
            'type' => ToolCallMessage::class,
            'content' => 'Found documents with the following information:' . json_encode(['title' => 'Long', '_text' => $long]),
        ]]);

        $text = $rows[0]['documents'][0]['text'];
        self::assertLessThanOrEqual(ConversationFormatter::EXCERPT_LENGTH + 2, mb_strlen($text));
        self::assertGreaterThan(ConversationFormatter::EXCERPT_LENGTH - 10, mb_strlen($text));
        self::assertStringEndsWith('word …', $text);
    }

    public function testToolResultWithoutDocumentsStaysPlainText(): void
    {
        $rows = $this->formatter->format([
            ['type' => ToolCallMessage::class, 'content' => 'No results found', 'toolsCalls' => ['function' => ['name' => 'similarity_search', 'arguments' => '{}']]],
            ['type' => ToolCallMessage::class, 'content' => 'broken {not json', 'toolsCalls' => []],
        ]);

        self::assertSame('No results found', $rows[0]['text']);
        self::assertSame([['name' => 'similarity_search', 'arguments' => '']], $rows[0]['toolCalls']);
        self::assertSame([], $rows[0]['documents']);
        self::assertSame('broken {not json', $rows[1]['text']);
        self::assertSame([], $rows[1]['documents']);
    }

    public function testUnknownTypesAreStillShown(): void
    {
        $rows = $this->formatter->format([['type' => 'Something\Else', 'content' => 'kept']]);

        self::assertSame([['role' => 'unknown', 'text' => 'kept', 'toolCalls' => [], 'documents' => []]], $rows);
    }
}
