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
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14+ only - ModifyRecordListRecordActionsEvent::setAction() requires a
// ComponentInterface here, which only exists on v14. See
// Classes/Legacy/EventListener/MarkFlaggedRecordsInRecordList.php for v13
// (same event class, different constructor/methods depending on the running
// TYPO3 version - the early return below is the only thing telling them apart).
//
// Marks ai_created/ai_modified records in the Web > List module's action column,
// the same place edit/copy/delete live - similar to how the localize-metadata
// button appears in the file list.
#[AsEventListener(identifier: 'ai-label/mark-flagged-records-in-list')]
final class MarkFlaggedRecordsInRecordList
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        if ($this->typo3Version->getMajorVersion() < 14) {
            return;
        }

        $record = $event->getRecord();
        // RawRecord holds the actual, unfiltered database row - ai_metadata is not
        // part of any type's showitem/palette, so the regular (filtered) record
        // may not carry it depending on how it was resolved.
        $metadata = AiMetadata::fromJsonString($record->getRawRecord()?->toArray()['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$record->getMainType() => [$record->getUid() => 'edit']],
            'returnUrl' => (string)$event->getRequest()->getUri(),
        ]);

        $event->setAction($this->badgeFactory->createButton($metadata, $href), 'ai-label-flag', ActionGroup::primary);
    }
}
