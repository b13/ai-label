<?php

declare(strict_types=1);

namespace B13\AiLabel\Legacy\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v13 only - see Classes/EventListener/MarkFlaggedRecordsInRecordList.php
// for the v14+ equivalent. Once v13 support is dropped, this whole
// Classes/Legacy/ directory can just be deleted.
//
// Both this class and the v14 one listen to the same event class name - v13's
// ModifyRecordListRecordActionsEvent just has a different constructor/methods
// than v14's (plain row array + string setAction() here, vs. RecordInterface +
// ComponentInterface there) - the early return below is the only thing telling
// them apart at runtime.
#[AsEventListener(identifier: 'ai-label/legacy-mark-flagged-records-in-list')]
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
        if ($this->typo3Version->getMajorVersion() >= 14) {
            return;
        }

        $row = $event->getRecord();
        $metadata = new AiMetadata($row['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        // v13's event carries no request; fall back to the global one.
        $returnUrl = (string)($this->getCurrentRequest()?->getUri() ?? '');
        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$event->getTable() => [(int)$row['uid'] => 'edit']],
            'returnUrl' => $returnUrl,
        ]);

        $event->setAction($this->badgeFactory->createButtonHtml($metadata, $href), 'ai-label-flag', 'primary');
    }

    protected function getCurrentRequest(): ?ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }
}
