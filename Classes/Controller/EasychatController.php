<?php

namespace Undkonsorten\Easychat\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Undkonsorten\Easychat\Middleware\ChatEndpointMiddleware;

class EasychatController extends ActionController
{
    public function chatFrontendAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $contentElementUid = $contentObject instanceof ContentObjectRenderer ? (int)($contentObject->data['uid'] ?? 0) : 0;

        // Path only: the endpoint must be same-origin with the page, whatever host it was requested with.
        $language = $this->request->getAttribute('language');
        $basePath = $language instanceof SiteLanguage ? $language->getBase()->getPath() : '/';

        $this->view->assignMultiple([
            'contentElementUid' => $contentElementUid,
            'endpointUrl' => rtrim($basePath, '/') . '/' . ChatEndpointMiddleware::ROUTE_TAIL,
        ]);

        return $this->htmlResponse();
    }
}
