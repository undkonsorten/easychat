<?php

namespace Undkonsorten\Easychat\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class EasychatController extends ActionController
{
    public function chatFrontendAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

}
