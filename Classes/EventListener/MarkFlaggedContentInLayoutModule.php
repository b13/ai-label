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
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

// Appends the AI marker to each flagged tt_content element's preview box in the
// Page module's columns. ContentFetcher (which builds these rows) uses SELECT *,
// so ai_metadata is already part of the raw row - unlike DatabaseRecordList,
// no extra query-modifying listener is needed here. Just the plain badge (no
// dropdown/edit-link) - the content preview isn't an action column, clicking the
// preview itself already opens the element for editing.
#[AsEventListener(identifier: 'ai-label/mark-flagged-content-in-layout')]
final class MarkFlaggedContentInLayoutModule
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
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

        $event->setPreviewContent(
            $event->getPreviewContent() . $this->badgeFactory->getBadge($metadata)
        );
    }
}
