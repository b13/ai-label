<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14+ only - only here because Services.yaml autowires this whole
// directory and the v13 event class differs slightly. See
// Classes/Legacy/EventListener/MarkFlaggedPageInLayoutModule.php for v13.
//
// The page itself never goes through AfterPageContentPreviewRenderedEvent - that
// only fires per tt_content element shown in the columns - so the current page's
// own flag is shown separately here, in the page module's header area. Just the
// plain badge (no dropdown/edit-link) - the header area isn't an action column.
#[AsEventListener(identifier: 'ai-label/mark-flagged-page-in-layout')]
final class MarkFlaggedPageInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        if ($this->typo3Version->getMajorVersion() < 14) {
            return;
        }

        $request = $event->getRequest();
        $pageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $row = BackendUtility::getRecord('pages', $pageId, 'ai_metadata');
        $metadata = new AiMetadata($row['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        $event->addHeaderContent(
            '<div class="ai-label-page-marker">' . $this->badgeFactory->getBadge($metadata) . '</div>'
        );
    }
}
