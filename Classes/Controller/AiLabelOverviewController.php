<?php

declare(strict_types=1);

namespace B13\AiLabel\Controller;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;

#[AsController]
final class AiLabelOverviewController
{
    private const ITEMS_PER_PAGE = 25;
    private const MODULE_IDENTIFIER = 'web_ai_label_overview';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AiMetadataRecordFinder $recordFinder,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:ai_label/Resources/Public/Css/ai-label.css');
        $currentPageNumber = max(1, (int)($request->getQueryParams()['currentPage'] ?? 1));

        $returnUrl = (string)$request->getUri();
        $records = array_map(
            fn (array $record) => [
                ...$record,
                'reviewBadge' => $this->badgeFactory->getBadge($record['metadata'], $this->buildEditUrl($record['table'], $record['uid'], $returnUrl)),
            ],
            $this->recordFinder->findFlaggedRecords()
        );

        $paginator = new ArrayPaginator($records, $currentPageNumber, self::ITEMS_PER_PAGE);
        $pagination = new SlidingWindowPagination($paginator, 7);

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
        );
        $moduleTemplate->assignMultiple([
            'paginator' => $paginator,
            'pagination' => $pagination,
            'pageUris' => $this->buildPageUris($pagination),
            'previousPageUri' => $this->buildPageUri($pagination->getPreviousPageNumber()),
            'nextPageUri' => $this->buildPageUri($pagination->getNextPageNumber()),
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    /** @return array<int, string> */
    private function buildPageUris(SlidingWindowPagination $pagination): array
    {
        $uris = [];
        foreach ($pagination->getAllPageNumbers() as $pageNumber) {
            $uris[$pageNumber] = (string)$this->buildPageUri($pageNumber);
        }
        return $uris;
    }

    private function buildPageUri(?int $pageNumber): ?string
    {
        if ($pageNumber === null) {
            return null;
        }
        return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, ['currentPage' => $pageNumber]);
    }

    private function buildEditUrl(string $table, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
