<?php

namespace Undkonsorten\Easychat\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Undkonsorten\Easychat\Domain\Model\Gen\ChatCompletionRequestUserMessage;

class EasychatController extends ActionController
{
    public function chatFrontendAction()
    {
        $this->view->assign('apiKey', $this->settings['apiKey']??'');
        $this->view->assign('url', $this->settings['url']??'');
        return $this->htmlResponse();
    }

}
