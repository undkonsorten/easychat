<?php

namespace Undkonsorten\Easychat\Indexing;

use Lochmueller\Index\Indexing\Frontend\FrontendContextBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;

/**
 * Makes EXT:index's Frontend technology render pages the way an anonymous visitor sees them.
 *
 * EXT:index renders each page in an internal subrequest and only drops $GLOBALS['BE_USER'] for
 * it. The Context aspects stay as they were — and on the CLI (index:queue, the scheduler)
 * TYPO3's CommandApplication sets a visibility that includes hidden pages, hidden content and
 * content outside its start/stop time. Rendered with that, a hidden content element ends up in
 * the vector store, where every chat user can retrieve it. This decorator swaps in a guest's
 * Context (default visibility, no backend user, live workspace) for the duration of the
 * subrequest and restores the previous one afterwards.
 *
 * Registered in Services.yaml as a decorator of FrontendContextBuilder; without EXT:index it is
 * not used.
 */
class GuestFrontendContextBuilder extends FrontendContextBuilder
{
    private const ASPECTS = ['visibility', 'backend.user', 'workspace'];

    public function __construct(
        private readonly FrontendContextBuilder $inner,
        private readonly Context $context,
    ) {
        // The parent's state is never used; everything is delegated to $inner.
    }

    public function executeInFrontendContext(callable $callback): mixed
    {
        $previous = [];
        foreach (self::ASPECTS as $name) {
            $previous[$name] = $this->context->getAspect($name);
        }

        $this->context->setAspect('visibility', new VisibilityAspect());
        $this->context->setAspect('backend.user', new UserAspect());
        $this->context->setAspect('workspace', new WorkspaceAspect(0));
        try {
            return $this->inner->executeInFrontendContext($callback);
        } finally {
            foreach ($previous as $name => $aspect) {
                $this->context->setAspect($name, $aspect);
            }
        }
    }
}
