<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use Lochmueller\Index\Indexing\Frontend\FrontendContextBuilder;
use PHPUnit\Framework\Attributes\RequiresMethod;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\GuestFrontendContextBuilder;
use Undkonsorten\Easychat\Indexing\IndexEventListener;

/**
 * The decoration is registered with decoration_on_invalid: ignore, so it switches itself off
 * without any error once EXT:index renames or moves FrontendContextBuilder — and hidden and
 * scheduled content would reach the vector store again. This makes that loud.
 */
#[RequiresMethod(FrontendContextBuilder::class, 'executeInFrontendContext')]
final class IndexingServiceWiringTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions', 'webhooks'];

    protected array $testExtensionsToLoad = ['lochmueller/index', 'undkonsorten/easychat'];

    public function testFrontendIndexingRendersThroughTheGuestContext(): void
    {
        self::assertInstanceOf(GuestFrontendContextBuilder::class, $this->get(FrontendContextBuilder::class));
    }

    public function testTheIndexListenerCanBeBuiltFromTheContainer(): void
    {
        self::assertInstanceOf(IndexEventListener::class, $this->get(IndexEventListener::class));
    }
}
