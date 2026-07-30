<?php

declare(strict_types=1);

namespace B13\AiLabel\Legacy\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Legacy\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v13 only - see Classes/EventListener/MarkFlaggedPageInLayoutModule.php
// for the v14+ equivalent. ModifyPageLayoutContentEvent itself is identical on
// both versions - this class only exists because the v14 badge factory uses
// ComponentFactory, which doesn't exist on v13. Once v13 support is dropped,
// this whole Classes/Legacy/ directory can just be deleted.
#[AsEventListener(identifier: 'ai-label/legacy-mark-flagged-page-in-layout')]
final class MarkFlaggedPageInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        if ($this->typo3Version->getMajorVersion() >= 14) {
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

        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['pages' => [$pageId => 'edit']],
            'returnUrl' => (string)$request->getUri(),
        ]);

        $event->addHeaderContent(
            '<div class="ai-label-page-marker">' . $this->badgeFactory->createButtonHtml($metadata, $href) . '</div>'
        );
    }
}
