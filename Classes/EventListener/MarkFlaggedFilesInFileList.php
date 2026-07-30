<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiMetadataBadgeFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

// Marks files whose sys_file_metadata is ai_created/ai_modified in the File >
// Filelist module's action column - ai_metadata lives on sys_file_metadata, not
// on the file itself, so it comes from the file's metadata aspect.
#[AsEventListener(identifier: 'ai-label/mark-flagged-files-in-filelist')]
final class MarkFlaggedFilesInFileList
{
    public function __construct(
        private readonly AiMetadataBadgeFactory $badgeFactory,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
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
