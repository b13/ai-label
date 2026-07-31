<?php

declare(strict_types=1);

namespace B13\AiLabel\Legacy\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

// TYPO3 v13 only - see Classes/EventListener/MarkFlaggedFilesInFileList.php for
// the v14+ equivalent. Once v13 support is dropped, this whole Classes/Legacy/
// directory can just be deleted.
//
// Both this class and the v14 one listen to the same event class name - v13's
// ProcessFileListActionsEvent just works with a plain actionItems array of raw
// HTML strings here, instead of setAction()/ComponentInterface there - the
// early return below is the only thing telling them apart at runtime.
#[AsEventListener(identifier: 'ai-label/legacy-mark-flagged-files-in-filelist')]
final class MarkFlaggedFilesInFileList
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        if ($this->typo3Version->getMajorVersion() >= 14) {
            return;
        }

        $file = $event->getResource();
        if (!$file instanceof File) {
            return;
        }

        $metaDataRow = $file->getMetaData()->get();
        $metadata = new AiMetadata($metaDataRow['ai_metadata'] ?? null);
        if (!$metadata->isFlagged()) {
            return;
        }

        // v13's event carries no request; fall back to the global one.
        $returnUrl = (string)($this->getCurrentRequest()?->getUri() ?? '');
        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_file_metadata' => [(int)$metaDataRow['uid'] => 'edit']],
            'returnUrl' => $returnUrl,
        ]);

        $actionItems = $event->getActionItems();
        $actionItems['ai-label-flag'] = $this->badgeFactory->createButtonHtml($metadata, $href);
        $event->setActionItems($actionItems);
    }

    protected function getCurrentRequest(): ?ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }
}
