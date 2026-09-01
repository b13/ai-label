<?php

declare(strict_types=1);

namespace B13\AiLabel\Imaging;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Service\CacheHelper;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;

// In "baked" mode the watermark lives in the pixels of the *processed* files, which
// FAL caches keyed by (original file, task, configuration) - none of which change when
// only the AI flag does. So flipping a file's origin has to throw those variants away,
// or the site keeps serving the previously rendered state until something else
// invalidates them.
//
// This mirrors what core's FileDeletionAspect does when a file is replaced: delete the
// file on disk, then drop its row. The variants are regenerated lazily on next render.
final class ProcessedFileInvalidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ProcessedFileRepository $processedFileRepository,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly CacheHelper $cacheHelper,
    ) {
    }

    /**
     * Takes a sys_file_metadata uid - the record the AI flag actually lives on - and
     * flushes the processed files of the sys_file behind it.
     */
    public function invalidateForFileMetadata(int $metadataUid): void
    {
        $this->cacheHelper->invalidate($metadataUid);
        $fileUid = $this->connectionPool
            ->getConnectionForTable('sys_file_metadata')
            ->fetchOne('SELECT file FROM sys_file_metadata WHERE uid = ?', [$metadataUid]);
        if (!$fileUid) {
            return;
        }

        try {
            $file = $this->resourceFactory->getFileObject((int)$fileUid);
        } catch (FileDoesNotExistException) {
            return;
        }

        foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
            // A processed file that "uses the original file" carries the original's own
            // identifier - deleting it would delete the editor's asset.
            if ($processedFile->usesOriginalFile()) {
                continue;
            }
            if ($processedFile->exists()) {
                $processedFile->delete(true);
            }
        }

        $this->logger->debug('Flushed processed files for sys_file {file} after an AI flag change.', [
            'file' => (int)$fileUid,
        ]);
    }
}
