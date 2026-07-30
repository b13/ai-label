<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Core\Attribute\AsEventListener;

// Marks ai_created/ai_modified records in the Web > List module's action column,
// the same place edit/copy/delete live - similar to how the localize-metadata
// button appears in the file list.
#[AsEventListener(identifier: 'ai-label/mark-flagged-records-in-list')]
final class MarkFlaggedRecordsInRecordList
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        $record = $event->getRecord();
        // RecordFactory::createResolvedRecordFromDatabaseRow() filters toArray()
        // against the TCA schema, dropping ai_metadata since it's schema-only and
        // never added to TCA. RawRecord holds the actual, unfiltered database row.
        $metadata = new AiMetadata($record->getRawRecord()?->toArray()['ai_metadata'] ?? null);
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
