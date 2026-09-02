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

use B13\AiLabel\Domain\Model\AiMetadata;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
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
    ) {
    }

    /**
     * Takes a sys_file_metadata uid - the record the AI flag actually lives on - and
     * flushes the processed files of the sys_file behind it.
     */
    public function invalidateForFileMetadata(int $metadataUid): void
    {
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

    /**
     * @return int the number of files whose variants were flushed
     */
    public function invalidateForAllFlaggedFiles(): int
    {
        // QueryBuilder rather than a raw statement, since this table is ctrl.versioningWS,
        // so a plain "WHERE tx_ailabel_metadata IS NOT NULL" also returns workspace drafts
        // and delete placeholders. Those carry the same "file" as their live counterpart,
        // so they would not break anything, they would just flush the same file again and
        // inflate the number this method reports.
        $queryBuilder = $this->connectionPool->getConnectionForTable('sys_file_metadata')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'file', 'tx_ailabel_metadata')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->isNotNull('tx_ailabel_metadata'),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $flushedFiles = [];
        foreach ($rows as $row) {
            // IS NOT NULL is only a cheap pre-filter: writes through AiLabelApi leave
            // {"origin": 0, ...} behind rather than SQL NULL, so the decision is
            // isFlagged(), not the column being set.
            $value = $row['tx_ailabel_metadata'] ?? null;
            if (!AiMetadata::fromJsonString(is_string($value) ? $value : null)->isFlagged()) {
                continue;
            }
            // Translations are separate metadata records pointing at the same sys_file,
            // and processed files belong to the file, not to a language, so a file is
            // flushed once even when several of its metadata records are flagged. Which
            // of them carried the flag doesn't matter either: they all resolve to these
            // same variants.
            $fileUid = (int)($row['file'] ?? 0);
            if ($fileUid <= 0 || isset($flushedFiles[$fileUid])) {
                continue;
            }
            $flushedFiles[$fileUid] = true;
            $this->invalidateForFileMetadata((int)$row['uid']);
        }

        return count($flushedFiles);
    }
}
