<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Domain\Repository\AiMetadataRecordFinder;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

// ModifyPageLayoutContentEvent is identical on v13 and v14, and everything used
// here (AiMetadataBadgeFactory::getBadge(), AiMetadataRecordFinder, PageRenderer)
// is version-safe too - no Typo3Version guard/Legacy split needed for this listener.
//
// Handles two things in the page module's header area:
// - the current page's own flag (pages never goes through
//   AfterPageContentPreviewRenderedEvent - that only fires per tt_content element
//   shown in the columns - so it's shown separately here)
// - flagged tt_content elements on this page: there is no PSR-14 event to render
//   into a content element's own t3-page-ce-header-right button group (only
//   EXT:backend's own hardcoded partial builds that markup), so their badges are
//   embedded here as JSON and main.js (loaded below) injects them into the
//   matching content element's header client-side. No extra request - the data
//   is already loaded server-side via AiMetadataRecordFinder.
#[AsEventListener(identifier: 'ai-label/mark-flagged-page-in-layout')]
final class MarkFlaggedPageInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly AiMetadataRecordFinder $recordFinder,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:ai_label/Resources/Public/Css/ai-label.css');
        $this->pageRenderer->loadJavaScriptModule('@b13/ai-label/main.js');

        $returnUrl = (string)$request->getUri();
        $headerContent = '';

        $row = BackendUtility::getRecord('pages', $pageId, 'ai_metadata');
        $pageMetadata = AiMetadata::fromJsonString($row['ai_metadata'] ?? null);
        if ($pageMetadata->isFlagged()) {
            $href = $this->buildEditUrl('pages', $pageId, $returnUrl);
            $headerContent .= '<div class="ai-label-page-marker">' . $this->badgeFactory->getBadge($pageMetadata, $href) . '</div>';
        }

        $contentBadges = [];
        foreach ($this->recordFinder->findFlaggedContentElementsOnPage($pageId) as $record) {
            $href = $this->buildEditUrl('tt_content', $record['uid'], $returnUrl);
            $contentBadges[$record['uid']] = $this->badgeFactory->getBadge($record['metadata'], $href);
        }
        if ($contentBadges !== []) {
            $headerContent .= '<script type="application/json" id="ai-label-content-badges">' . json_encode($contentBadges) . '</script>';
        }

        if ($headerContent !== '') {
            $event->addHeaderContent($headerContent);
        }
    }

    private function buildEditUrl(string $table, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }
}
