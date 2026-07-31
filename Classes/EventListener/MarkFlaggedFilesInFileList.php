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
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

// TYPO3 v14+ only - ProcessFileListActionsEvent::setAction() requires a
// ComponentInterface here, which only exists on v14. See
// Classes/Legacy/EventListener/MarkFlaggedFilesInFileList.php for v13 (same
// event class, different constructor/methods depending on the running TYPO3
// version - the early return below is the only thing telling them apart).
//
// Marks files whose sys_file_metadata is ai_created/ai_modified in the File >
// Filelist module's action column - ai_metadata lives on sys_file_metadata, not
// on the file itself, so it comes from the file's metadata aspect.
#[AsEventListener(identifier: 'ai-label/mark-flagged-files-in-filelist')]
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
        if ($this->typo3Version->getMajorVersion() < 14) {
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

        $href = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_file_metadata' => [(int)$metaDataRow['uid'] => 'edit']],
            'returnUrl' => (string)$event->getRequest()->getUri(),
        ]);

        $event->setAction($this->badgeFactory->createButton($metadata, $href), 'ai-label-flag', ActionGroup::primary);
    }
}
