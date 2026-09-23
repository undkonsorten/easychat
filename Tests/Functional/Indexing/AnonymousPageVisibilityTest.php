<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Indexing;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Indexing\AnonymousPageVisibility;

/**
 * Only pages a visitor without a login can open may reach the vector store, whatever
 * EXT:index reports. Fixture: PageVisibility.csv.
 */
final class AnonymousPageVisibilityTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PageVisibility.csv');
    }

    public static function pages(): array
    {
        return [
            'site root' => [1, true],
            'public page' => [16, true],
            'hidden page' => [2, false],
            'page with a start time in the future' => [3, false],
            'page past its stop time' => [4, false],
            'access restricted page' => [5, false],
            'child of a restricted page that extends to subpages' => [6, false],
            'grandchild of a restricted page that extends to subpages' => [7, false],
            'child of a restricted page that does not extend to subpages' => [9, true],
            'child of a hidden page that extends to subpages' => [11, false],
            'child of a hidden page that does not extend to subpages' => [13, true],
            'page hidden at login' => [14, true],
            'deleted page' => [15, false],
            'page that does not exist' => [999, false],
            'no page' => [0, false],
        ];
    }

    #[DataProvider('pages')]
    public function testMatchesWhatAVisitorWithoutLoginCanOpen(int $pageUid, bool $visible): void
    {
        self::assertSame($visible, (new AnonymousPageVisibility($this->get(ConnectionPool::class)))->isVisible($pageUid));
    }

    #[DataProvider('pages')]
    public function testIgnoresTheVisibilityOfTheCommandLine(int $pageUid, bool $visible): void
    {
        // What TYPO3's CommandApplication sets for index:queue and the scheduler.
        $this->get(Context::class)->setAspect('visibility', new VisibilityAspect(true, true, false, true));

        self::assertSame($visible, (new AnonymousPageVisibility($this->get(ConnectionPool::class)))->isVisible($pageUid));
    }

    public function testAPageHiddenLaterInTheSameProcessIsNoLongerVisible(): void
    {
        // Queue workers keep running between an editor's changes.
        $visibility = new AnonymousPageVisibility($this->get(ConnectionPool::class));
        self::assertTrue($visibility->isVisible(16), 'precondition');
        self::assertFalse($visibility->isVisible(6), 'precondition');

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 16]);
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['extendToSubpages' => 0], ['uid' => 5]);

        self::assertFalse($visibility->isVisible(16));
        self::assertTrue($visibility->isVisible(6));
    }
}
