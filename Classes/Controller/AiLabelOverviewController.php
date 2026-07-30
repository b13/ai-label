<?php

declare(strict_types=1);

namespace B13\AiLabel\Controller;

use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

#[AsController]
final class AiLabelOverviewController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AiMetadataRecordFinder $recordFinder,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle(
            $GLOBALS['LANG']->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
        );
        $moduleTemplate->assignMultiple([
            'records' => $this->recordFinder->findFlaggedRecords(),
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }
}
