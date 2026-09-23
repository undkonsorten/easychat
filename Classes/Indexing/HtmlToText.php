<?php

namespace Undkonsorten\Easychat\Indexing;

/**
 * Turns the HTML EXT:index hands over for pages (a content element's bodytext with the
 * Database technology, rendered markup with Frontend, Http and Cache) into the text a reader
 * would see. Tags, attributes and template whitespace otherwise go into the embeddings and
 * into the text the chatbot is given to answer from.
 *
 * Block boundaries become line breaks and list items keep a bullet, so the structure survives;
 * everything else — scripts, styles, comments, attributes — is dropped.
 */
final class HtmlToText
{
    private const BLOCK_ELEMENTS = 'address|article|aside|blockquote|dd|div|dl|dt|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul';

    public static function convert(string $html): string
    {
        if (!str_contains($html, '<') && !str_contains($html, '&')) {
            return self::normalizeWhitespace($html);
        }

        $text = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $text = preg_replace('#<(script|style|noscript|template|svg|head)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</?(?:' . self::BLOCK_ELEMENTS . ')\b[^>]*>#i', "\n", $text) ?? $text;
        // Inline elements are glued to their neighbours in the source; keep words apart.
        $text = preg_replace('#<[^>]+>#', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalizeWhitespace($text);
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $lines = array_map(
            static fn (string $line): string => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line),
            explode("\n", $text),
        );
        // Drop blank lines, except a single one between paragraphs.
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '';

        // Punctuation right after an inline element would otherwise be preceded by a space.
        return trim(preg_replace('/ ([.,;:!?])/', '$1', $text) ?? $text);
    }
}
