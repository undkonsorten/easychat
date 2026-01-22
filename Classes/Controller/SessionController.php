<?php

namespace Undkonsorten\Easychat\Controller;


use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Pagination\QueryResultPaginator;
use TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use Undkonsorten\Easychat\Domain\Model\Session;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;

class SessionController extends ActionController
{
    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly SessionRepository $sessionRepository,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ){}

    public function initializeAction(): void
    {
        parent::initializeAction();
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('EasychatMenu');
        $menuItem = $menu
            ->makeMenuItem()
            ->setHref(
                $this->uriBuilder->buildBackendUri()
            )
            ->setTitle('Session');
        $menu->addMenuItem($menuItem);
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
        $this->buildButtons();

        if ($this->arguments->hasArgument('demand')) {
            $propertyMappingConfiguration = $this->arguments['demand']->getPropertyMappingConfiguration();
            $propertyMappingConfiguration->allowCreationForSubProperty('status');
            $propertyMappingConfiguration->allowProperties('status');
            $propertyMappingConfiguration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, TRUE);
        }

    }

    public function listAction(int $currentPage = 1): ResponseInterface
    {
        $sessions = $this->sessionRepository->findAll();

        $currentPage = $this->request->hasArgument('currentPage') ? $this->request->getArgument('currentPage') : $currentPage;
        $paginator = new QueryResultPaginator($sessions, (integer)$currentPage, (integer)$this->extensionConfiguration
            ->get('easychat')['itemsPerPage'] ?? 50);
        $simplePagination = new SimplePagination($paginator);
        $pagination = $this->buildSimplePagination($simplePagination, $paginator);

        $this->moduleTemplate->assignMultiple([
            'sessions' => $paginator->getPaginatedItems(),
            'pagination' => $pagination,
            'paginator' => $paginator,
        ]);
        return $this->moduleTemplate->renderResponse('Session/List');
    }
    public function showAction(Session $session): ResponseInterface
    {
        $this->moduleTemplate->assignMultiple([
            'session' => $session,
            'messages' => json_decode((string) $session->getMessages(), true)
        ]);
        return $this->moduleTemplate->renderResponse('Session/Show');
    }

    public function deleteAction(Session $session): ResponseInterface
    {
        $this->sessionRepository->remove($session);
        $this->addFlashMessage("Session deleted.", "Success", ContextualFeedbackSeverity::OK);
        return $this->redirect('list');
    }

    /**
     * build simple pagination
     *
     * @param SimplePagination $simplePagination
     * @param QueryResultPaginator $paginator
     * @return array
     */
    protected function buildSimplePagination(SimplePagination $simplePagination, QueryResultPaginator $paginator)
    {
        $firstPage = $simplePagination->getFirstPageNumber();
        $lastPage = $simplePagination->getLastPageNumber();
        return [
            'lastPageNumber' => $lastPage,
            'firstPageNumber' => $firstPage,
            'nextPageNumber' => $simplePagination->getNextPageNumber(),
            'previousPageNumber' => $simplePagination->getPreviousPageNumber(),
            'startRecordNumber' => $simplePagination->getStartRecordNumber(),
            'endRecordNumber' => $simplePagination->getEndRecordNumber(),
            'currentPageNumber' => $paginator->getCurrentPageNumber(),
            'pages' => range($firstPage, $lastPage)
        ];
    }

    protected function buildButtons()
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttons = [
            [
                'table' => 'tx_easychat_domain_model_session',
                'label' => 'module.list',
                'action' => 'list',
                'icon' => 'actions-list'
            ],
        ];
        foreach ($buttons as $key => $tableConfiguration) {
            $title = LocalizationUtility::translate($tableConfiguration['label'],'easychat');
            $viewButton = $buttonBar->makeLinkButton()
                ->setHref($this->uriBuilder->reset()->setRequest($this->request)->uriFor(
                    $tableConfiguration['action'],
                    [],
                    'Session'
                ))
                ->setDataAttributes([
                    'toggle' => 'tooltip',
                    'placement' => 'bottom',
                    'title' => $title])
                ->setTitle($title)
                ->setIcon($this->iconFactory->getIcon($tableConfiguration['icon'], Icon::SIZE_SMALL));
            $buttonBar->addButton($viewButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }

        // Refresh
        $refreshButton = $buttonBar->makeLinkButton()
            ->setHref(GeneralUtility::getIndpEnv('REQUEST_URI'))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', Icon::SIZE_SMALL));
        $buttonBar->addButton($refreshButton, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
