<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use Lochmueller\Index\Indexing\Frontend\FrontendContextBuilder;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use Undkonsorten\Easychat\Indexing\GuestFrontendContextBuilder;

#[RequiresMethod(FrontendContextBuilder::class, 'executeInFrontendContext')]
final class GuestFrontendContextBuilderTest extends TestCase
{
    public function testRendersWithAGuestContextAndRestoresTheCliContextAfterwards(): void
    {
        // What TYPO3's CommandApplication sets for every CLI command.
        $cliVisibility = new VisibilityAspect(true, true, false, true);
        $cliUser = new UserAspect(null);
        $context = new Context();
        $context->setAspect('visibility', $cliVisibility);
        $context->setAspect('backend.user', $cliUser);
        $context->setAspect('workspace', new WorkspaceAspect(0));

        $inner = $this->createMock(FrontendContextBuilder::class);
        $inner->expects(self::once())->method('executeInFrontendContext')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());

        $seen = (new GuestFrontendContextBuilder($inner, $context))->executeInFrontendContext(static fn(): array => [
            'hiddenContent' => $context->getPropertyFromAspect('visibility', 'includeHiddenContent'),
            'hiddenPages' => $context->getPropertyFromAspect('visibility', 'includeHiddenPages'),
            'scheduled' => $context->getPropertyFromAspect('visibility', 'includeScheduledRecords'),
            'backendUser' => $context->getPropertyFromAspect('backend.user', 'isLoggedIn'),
        ]);

        self::assertSame(['hiddenContent' => false, 'hiddenPages' => false, 'scheduled' => false, 'backendUser' => false], $seen);
        self::assertSame($cliVisibility, $context->getAspect('visibility'));
        self::assertSame($cliUser, $context->getAspect('backend.user'));
    }

    public function testRestoresTheContextWhenRenderingFails(): void
    {
        $cliVisibility = new VisibilityAspect(true, true, false, true);
        $context = new Context();
        $context->setAspect('visibility', $cliVisibility);

        $inner = self::createStub(FrontendContextBuilder::class);
        $inner->method('executeInFrontendContext')->willThrowException(new \RuntimeException('render failed'));

        try {
            (new GuestFrontendContextBuilder($inner, $context))->executeInFrontendContext(static fn() => null);
            self::fail('The exception must not be swallowed.');
        } catch (\RuntimeException) {
        }

        self::assertSame($cliVisibility, $context->getAspect('visibility'));
    }
}
