<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use Lochmueller\Index\Enums\IndexTechnology;
use Lochmueller\Index\Enums\IndexType;
use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
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

    private function createSiteStub(): SiteInterface
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn('main');

        return $site;
    }
}
