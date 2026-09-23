<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Indexing\HtmlToText;

final class HtmlToTextTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function htmlProvider(): iterable
    {
        yield 'bodytext of a text element' => [
            '<h1>The test hedgehog</h1><p>The hedgehog is called <strong>Tabula</strong>.</p>',
            "The test hedgehog\n\nThe hedgehog is called Tabula.",
        ];
        yield 'list with attributes' => [
            '<ol><li data-list-item-id="e39">First fact</li><li data-list-item-id="e40">Second <a href="https://example.org">fact</a></li></ol>',
            "- First fact\n\n- Second fact",
        ];
        yield 'entities and line breaks' => [
            'Caf&eacute; &amp; Bar<br>Line&nbsp;two',
            "Café & Bar\nLine two",
        ];
        yield 'template noise' => [
            "<div class=\"frame\">\n    <!-- comment -->\n    <script>var x = '<p>no</p>';</script>\n    <style>p { color: red }</style>\n        <p>Visible   text</p>\n\n\n\n</div>",
            'Visible text',
        ];
        yield 'plain text is only normalised' => [
            "Plain  text\r\nwith lines",
            "Plain text\nwith lines",
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testConvertsHtmlToReadableText(string $html, string $expected): void
    {
        self::assertSame($expected, HtmlToText::convert($html));
    }
}
