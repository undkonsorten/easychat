<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use Lochmueller\Index\Enums\IndexTechnology;
use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use Undkonsorten\Easychat\Indexing\IndexEventListener;

final class IndexEventListenerTest extends TestCase
{
    public function testOnIndexPageSkipsBlankContentWithoutQueryingConfigurations(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $listener = new IndexEventListener($connectionPool);

        $listener->onIndexPage(new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: IndexType::Full,
            indexConfigurationRecordId: 1,
            indexProcessId: 'test',
            language: 0,
            title: 'Empty page',
            content: "   \n\t  ",
            pageUid: 1,
            accessGroups: [],
        ));
    }

    public function testOnIndexFileSkipsBlankContentWithoutQueryingConfigurations(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $listener = new IndexEventListener($connectionPool);

        $listener->onIndexFile(new IndexFileEvent(
            site: $this->createSiteStub(),
            indexConfigurationRecordId: 1,
            indexProcessId: 'test',
            title: 'Empty file',
            content: '',
            fileIdentifier: '1:/empty.pdf',
        ));
    }

    /**
     * @return iterable<string, array{0: int[]}>
     */
    public static function accessRestrictedGroupsProvider(): iterable
    {
        yield 'single fe_group' => [[3]];
        yield 'several fe_groups' => [[3, 7]];
        yield 'any logged in user' => [[-2]];
        yield 'group ids of a logged in visitor (cache technology)' => [[0, -2, 3]];
    }

    /**
     * @param int[] $accessGroups
     */
    #[DataProvider('accessRestrictedGroupsProvider')]
    public function testOnIndexPageSkipsAccessRestrictedContent(array $accessGroups): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $listener = new IndexEventListener($connectionPool);

        $listener->onIndexPage($this->createPageEvent($accessGroups));
    }

    /**
     * @return iterable<string, array{0: int[]}>
     */
    public static function publiclyVisibleGroupsProvider(): iterable
    {
        yield 'no restriction' => [[]];
        yield 'hide at login' => [[-1]];
        yield 'hide at login combined with a group' => [[-1, 3]];
        yield 'group ids of a guest visitor (cache technology)' => [[0, -1]];
    }

    /**
     * Content a guest could see must still be handed on - the configuration lookup running
     * is what proves the listener did not bail out early.
     *
     * @param int[] $accessGroups
     */
    #[DataProvider('publiclyVisibleGroupsProvider')]
    public function testOnIndexPageHandlesPubliclyVisibleContent(array $accessGroups): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::once())->method('getQueryBuilderForTable');

        $listener = new IndexEventListener($connectionPool);

        $listener->onIndexPage($this->createPageEvent($accessGroups));
    }

    /**
     * @param int[] $accessGroups
     */
    private function createPageEvent(array $accessGroups): IndexPageEvent
    {
        return new IndexPageEvent(
            site: $this->createSiteStub(),
            technology: IndexTechnology::Database,
            type: IndexType::Full,
            indexConfigurationRecordId: 1,
            indexProcessId: 'test',
            language: 0,
            title: 'Members only',
            content: 'Content that must not reach the vector store.',
            pageUid: 1,
            accessGroups: $accessGroups,
        );
    }

    private function createSiteStub(): SiteInterface
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('main');

        return $site;
    }
}
