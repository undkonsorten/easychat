<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Backend;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;
use Undkonsorten\Easychat\Backend\ConversationFormatter;

/**
 * The backend module's conversation partial must show a RAG turn readably: who said what, which
 * tool the assistant called with which arguments, and the documents the tool returned as a list
 * instead of the raw JSON the model saw.
 */
final class SessionPropertiesPartialTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    public function testToolRoundsAreRenderedReadably(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $conversation = (new ConversationFormatter())->format([
            ['type' => SystemMessage::class, 'content' => 'be nice'],
            ['type' => UserMessage::class, 'content' => '', 'contentAsBase64' => [['type' => Text::class, 'content' => 'Where do hedgehogs sleep?']]],
            ['type' => AssistantMessage::class, 'content' => '', 'toolsCalls' => [['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'similarity_search', 'arguments' => '{"searchTerm":"hedgehog"}']]]],
            ['type' => ToolCallMessage::class, 'content' => "Found documents with the following information:\n" . '{"title":"Hedgehogs","uri":"https://example.com/hedgehogs","_text":"They sleep in leaf piles."}{"title":"Gardens","uri":"https://example.com/gardens","_text":"Leave a corner wild."}', 'toolsCalls' => ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'similarity_search', 'arguments' => '{"searchTerm":"hedgehog"}']]],
            ['type' => AssistantMessage::class, 'content' => 'In leaf piles.'],
        ]);

        $renderingContext = $this->get(RenderingContextFactory::class)->create();
        $renderingContext->getTemplatePaths()->setPartialRootPaths(['EXT:easychat/Resources/Private/Partials/']);
        $html = (new TemplateView($renderingContext))->renderPartial('Session/Properties', null, ['conversation' => $conversation]);

        self::assertSame(1, substr_count($html, '>System<'));
        self::assertSame(1, substr_count($html, '>User<'));
        self::assertSame(2, substr_count($html, '>Assistant<'));
        self::assertSame(1, substr_count($html, '>Tool result<'));
        self::assertStringContainsString('Where do hedgehogs sleep?', $html);
        self::assertSame(2, substr_count($html, '<strong>similarity_search</strong>'), 'The call and its result both name the tool.');
        self::assertSame(1, substr_count($html, 'Tool call'));
        self::assertSame(1, substr_count($html, 'Result of'));
        self::assertSame(1, substr_count($html, '<code>searchTerm: hedgehog</code>'), 'Arguments are shown once, on the call.');
        self::assertStringContainsString('2 documents found', $html);
        self::assertStringContainsString('<a href="https://example.com/hedgehogs" target="_blank" rel="noopener">Hedgehogs</a>', $html);
        self::assertStringContainsString('They sleep in leaf piles.', $html);
        self::assertStringContainsString('In leaf piles.', $html);
        self::assertStringNotContainsString('{"title"', $html, 'The raw JSON of the tool result is not shown.');
    }
}
