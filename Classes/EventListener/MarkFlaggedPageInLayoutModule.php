<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;

// The page itself never goes through AfterPageContentPreviewRenderedEvent - that
// only fires per tt_content element shown in the columns - so the current page's
// own flag is shown separately here, in the page module's header area.
#[AsEventListener(identifier: 'ai-label/mark-flagged-page-in-layout')]
final class MarkFlaggedPageInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
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

        $row = BackendUtility::getRecord('pages', $pageId, 'ai_metadata');
        $metadata = new AiMetadata($row['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['pages' => [$pageId => 'edit']],
            'returnUrl' => (string)$request->getUri(),
        ]);

        $event->addHeaderContent(
            '<div class="ai-label-page-marker">' . $this->badgeFactory->createButton($metadata, $href)->render() . '</div>'
        );
    }
}
