<?php

declare(strict_types=1);

namespace B13\AiLabel\Service;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Imaging\ProcessedFileInvalidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Public API for other extensions (and AiMetaDataHandlerHook itself) to flag a
 * record as AI-created/AI-modified, or clear that flag again.
 *
 * Writes go through a real DataHandler run instead of a raw query:
 * - Permissions are enforced exactly like any other backend write - DataHandler
 *   checks the given backend user's page/table access on its own (unless
 *   $bypassAccessCheckForRecords is set, which this class never does, or the
 *   user is admin). It does so silently though (populates $errorLog, never
 *   throws), so aiMetadataUpdate() turns a non-empty errorLog into an exception.
 * - The change ends up in sys_history: submitting tx_ailabel_metadata as a plain
 *   datamap field (instead of injecting it afterwards, like AiMetaDataHandlerHook
 *   has to for form submissions) means DataHandler's own
 *   compareFieldArrayWithCurrentAndUnset() - the method that decides what gets
 *   logged - sees the change before it runs, not only afterwards.
 *
 * Note: checkValueForJson() coerces an explicitly submitted null into an empty
 * array, not SQL NULL - aiRemoved() therefore stores {} rather than NULL. This
 * doesn't affect isFlagged()/AiMetadata behaviour, only makes
 * AiMetadataRecordFinder's "WHERE tx_ailabel_metadata IS NOT NULL" a slightly less
 * tight pre-filter (a few never-actually-flagged rows may pass it and get discarded
 * in PHP afterwards).
 */
#[Autoconfigure(public: true)]
final class AiLabelApi
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
        private readonly ProcessedFileInvalidator $processedFileInvalidator,
    ) {
    }

    public function aiCreated(string $table, int $uid, ?BackendUserAuthentication $user = null): void
    {
        // Origin is exclusive and reviewedBy/reviewedTimestamp default to 0 already -
        // no need to fetch the record's current tx_ailabel_metadata first, this fully
        // replaces it. Any explicit aiCreated()/aiModified() call means the content was just
        // (re-)touched by AI - always needs a fresh review, regardless of whether it
        // was reviewed before.
        $this->aiMetadataUpdate($table, $uid, (new AiMetadata())->withOrigin(AiOrigin::Generated), $user);
    }

    public function aiModified(string $table, int $uid, ?BackendUserAuthentication $user = null): void
    {
        $this->aiMetadataUpdate($table, $uid, (new AiMetadata())->withOrigin(AiOrigin::Manipulated), $user);
    }

    // Clears the AI flag entirely (origin back to Human) - see aiMetadataUpdate()'s
    // $aiMetadata=null case.
    public function aiRemoved(string $table, int $uid, ?BackendUserAuthentication $user = null): void
    {
        $this->aiMetadataUpdate($table, $uid, null, $user);
    }

    /**
     * Low-level write used by aiCreated()/aiModified()/aiRemoved() above, and by
     * AiMetaDataHandlerHook once it has computed the final state itself for an
     * existing record (its own review-reset/"reviewed wins" decision doesn't fit
     * the simpler aiCreated()/aiModified()/aiRemoved() shape). $aiMetadata=null
     * clears the column.
     */
    public function aiMetadataUpdate(string $table, int $uid, ?AiMetadata $aiMetadata, ?BackendUserAuthentication $user = null): void
    {
        $this->assertTableIsApplicable($table);
        $backendUser = $this->resolveUser($user);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [$table => [$uid => ['tx_ailabel_metadata' => $aiMetadata?->toArray() ?? []]]],
            [],
            $backendUser
        );
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            $this->logger->error('Failed to update tx_ailabel_metadata on {table}:{uid}', [
                'table' => $table,
                'uid' => $uid,
                'user' => $backendUser->getUserId(),
                'errors' => $dataHandler->errorLog,
            ]);
            throw new \RuntimeException(
                sprintf(
                    'Backend user %d is not allowed to update tx_ailabel_metadata on %s:%d, or the record does not exist.',
                    $backendUser->getUserId(),
                    $table,
                    $uid
                ),
                1785502863
            );
        }

        $this->logger->debug('Updated tx_ailabel_metadata on {table}:{uid}', [
            'table' => $table,
            'uid' => $uid,
            'user' => $backendUser->getUserId(),
            'aiMetadata' => $aiMetadata?->toArray(),
        ]);

        // The "baked" image marker mode renders the flag into the processed image files,
        // which FAL caches on keys that don't change when only the flag does - so they
        // have to go. Unconditional rather than diffed against the previous state: this
        // runs only when an editor actually touched a file's AI fields, and the variants
        // are rebuilt lazily anyway.
        if ($table === 'sys_file_metadata') {
            $this->processedFileInvalidator->invalidateForFileMetadata($uid);
        }
    }

    private function resolveUser(?BackendUserAuthentication $user): BackendUserAuthentication
    {
        $user ??= $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            $this->logger->error('No backend user available to attribute an ai_metadata change to.');
            throw new \RuntimeException('No backend user available to attribute this ai_metadata change to.', 1785502864);
        }

        return $user;
    }

    // $table must be registered via ApplicableTablesProvider/ApplicableTablesEvent
    // (tt_content, pages and sys_file_metadata by default) - this API doesn't
    // silently write tx_ailabel_metadata onto tables that were never set up to carry it.
    private function assertTableIsApplicable(string $table): void
    {
        if (!$this->applicableTablesProvider->isTableApplicable($table)) {
            $this->logger->error('Table {table} is not registered for ai_label.', ['table' => $table]);
            throw new \InvalidArgumentException(
                sprintf(
                    'Table "%s" is not registered for ai_label. Register it via B13\AiLabel\Event\ApplicableTablesEvent.',
                    $table
                ),
                1785502865
            );
        }
    }
}
