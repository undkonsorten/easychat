<?php

namespace Undkonsorten\Easychat\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Undkonsorten\Easychat\Domain\Model\Gen\ChatCompletionRequestUserMessage;

class EasychatController extends ActionController
{
    public function chatFrontendAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

}
