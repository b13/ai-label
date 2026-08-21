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

use B13\AiLabel\Backend\SortUrlBuilder;
use B13\AiLabel\Domain\Repository\AiLabelDemand;
use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use B13\AiLabel\Pagination\DemandedArrayPaginator;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class AiLabelOverviewController
{
    private const MODULE_IDENTIFIER = 'web_ai_label_overview';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AiMetadataRecordFinder $recordFinder,
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SortUrlBuilder $sortUrlBuilder,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $languageService = $this->getLanguageService();
        $view->setTitle($languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'));

        $shortcutButton = GeneralUtility::makeInstance(ShortcutButton::class)
            ->setRouteIdentifier(self::MODULE_IDENTIFIER)
            ->setDisplayName($languageService->sL('LLL:EXT:ai_label/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'));
        $view->getDocHeaderComponent()->getButtonBar()->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);

        $demand = AiLabelDemand::fromRequest($request);
        $allRecords = $this->recordFinder->findFlaggedRecords();
        $statistics = $this->recordFinder->calculateStatistics($allRecords);
        $tables = $this->recordFinder->getDistinctTables($allRecords);

        $matchingRecords = $this->recordFinder->filterAndSort($allRecords, $demand);
        $totalCount = count($matchingRecords);
        $pageItems = array_slice($matchingRecords, ($demand->getPage() - 1) * $demand->getLimit(), $demand->getLimit());

        $returnUrl = $this->buildOverviewUrl($demand);
        $pageItems = array_map(
            fn (array $record): array => [
                ...$record,
                'reviewBadge' => $this->badgeFactory->getBadge($record['metadata'], $this->buildEditUrl($record['table'], $record['uid'], $returnUrl)),
            ],
            $pageItems,
        );

        $paginator = new DemandedArrayPaginator($pageItems, $demand->getPage(), $demand->getLimit(), $totalCount);
        $pagination = new SimplePagination($paginator);
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_IDENTIFIER, $this->demandToRouteParams($demand));

        $view->assignMultiple([
            'demand' => $demand,
            // Filters are submitted via POST (Overview/Filters.html), so they never
            // show up in the request's own URI. It must be rebuilt from the parsed
            // demand instead, the same way $paginationBaseUrl is.
            'returnUrl' => $returnUrl,
            'paginationBaseUrl' => $paginationBaseUrl,
            'sortUrls' => $this->sortUrlBuilder->build($demand, self::MODULE_IDENTIFIER),
            'paginator' => $paginator,
            'pagination' => $pagination,
            'statistics' => $statistics,
            'tables' => $tables,
        ]);

        return $view->renderResponse('Overview/Index');
    }

    /**
     * @return array<string, string>
     */
    private function demandToRouteParams(AiLabelDemand $demand): array
    {
        $params = [
            'orderField' => $demand->getOrderField(),
            'orderDirection' => $demand->getOrderDirection(),
        ];
        foreach ($demand->getParameters() as $key => $value) {
            $params['demand[' . $key . ']'] = $value;
        }
        return $params;
    }

    private function buildOverviewUrl(AiLabelDemand $demand): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(
            self::MODULE_IDENTIFIER,
            array_merge($this->demandToRouteParams($demand), ['page' => $demand->getPage()]),
        );
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
