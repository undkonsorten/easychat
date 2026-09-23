<?php

namespace Undkonsorten\Easychat\Indexing;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Whether a visitor without a login can open a page: the page itself is not hidden,
 * scheduled or access restricted, and no page above it hides its subpages that way
 * (extendToSubpages), just like the frontend decides.
 *
 * The access groups of an IndexPageEvent only cover the page's own fe_group, so
 * IndexEventListener asks this before anything is embedded.
 *
 * Queries the database directly instead of using PageRepository::getPage() or
 * RootlineUtility: both keep results in the runtime cache, which in a long-running queue
 * worker would still report a page as visible after an editor hid it.
 */
class AnonymousPageVisibility
{
    /** Guards against pid loops in broken page trees. */
    private const MAX_DEPTH = 100;

    /** The group ids TYPO3 gives a visitor without a login. */
    private const GUEST_GROUPS = [0, -1];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function isVisible(int $pageUid): bool
    {
        if ($pageUid <= 0 || !$this->isOpenToGuests($pageUid)) {
            return false;
        }

        $pid = (int)($this->findPage($pageUid)['pid'] ?? 0);
        for ($depth = 0; $pid > 0; $depth++) {
            $ancestor = $this->findPage($pid);
            if ($ancestor === null || $depth >= self::MAX_DEPTH) {
                // A broken rootline is not something a visitor could open either.
                return false;
            }
            if ((int)$ancestor['doktype'] === PageRepository::DOKTYPE_BE_USER_SECTION) {
                return false;
            }
            if ((bool)$ancestor['extendToSubpages'] && !$this->isOpenToGuests($pid)) {
                return false;
            }
            $pid = (int)$ancestor['pid'];
        }

        return true;
    }

    /**
     * The page's own hidden, start/stop time and fe_group, judged for a guest — independent
     * of the Context of the current process (the CLI's includes hidden and scheduled records).
     */
    private function isOpenToGuests(int $pageUid): bool
    {
        $restrictions = new FrontendRestrictionContainer($this->createGuestContext());
        $restrictions->removeByType(FrontendGroupRestriction::class);
        $restrictions->add(new FrontendGroupRestriction(self::GUEST_GROUPS));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->setRestrictions($restrictions);

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @return array{pid: int|string, doktype: int|string, extendToSubpages: int|string}|null
     */
    private function findPage(int $pageUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $queryBuilder
            ->select('pid', 'doktype', 'extendToSubpages')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function createGuestContext(): Context
    {
        $context = new Context();
        $context->setAspect('visibility', new VisibilityAspect());
        $context->setAspect('frontend.user', new UserAspect(null, self::GUEST_GROUPS));
        $context->setAspect('backend.user', new UserAspect());
        $context->setAspect('workspace', new WorkspaceAspect(0));

        return $context;
    }
}
