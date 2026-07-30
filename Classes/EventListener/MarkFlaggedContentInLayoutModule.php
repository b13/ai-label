<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

// Appends the AI marker to each flagged tt_content element's preview box in the
// Page module's columns. ContentFetcher (which builds these rows) uses SELECT *,
// so ai_metadata is already part of the raw row - unlike DatabaseRecordList,
// no extra query-modifying listener is needed here.
#[AsEventListener(identifier: 'ai-label/mark-flagged-content-in-layout')]
final class MarkFlaggedContentInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function __invoke(AfterPageContentPreviewRenderedEvent $event): void
    {
        if ($event->getTable() !== 'tt_content') {
            return;
        }

        $record = $event->getRecord();
        // toArray() is filtered against TCA (ai_metadata is schema-only, never
        // added to TCA) - the raw, unfiltered row lives on the RawRecord.
        $metadata = new AiMetadata($record->getRawRecord()?->toArray()['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tt_content' => [$record->getUid() => 'edit']],
            'returnUrl' => $event->getPageLayoutContext()->getReturnUrl(),
        ]);

        $event->setPreviewContent(
            $event->getPreviewContent() . $this->badgeFactory->createButton($metadata, $href)->render()
        );
    }
}
