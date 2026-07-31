<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

// Folds ai_created / ai_modified / reviewed into the ai_metadata JSON column (a real
// type=json TCA column added by AddAiMetaFieldsToTca, never part of any showitem/
// palette) as part of DataHandler's own insert/update - no separate table, no
// separate query to write. "reviewed" is a plain boolean
// checkbox in the form, but persisted as reviewed_by inside the JSON: the be_users
// uid that reviewed it, or 0 for "review required".
//
// As long as a record is flagged ai_created/ai_modified, a save that changes real
// content resets reviewed_by to 0, so the editor has to review again - unless that
// same save also actively ticks "reviewed" from 0/none to 1 (reviewed wins over
// forcing a reset). Reviewed already being set and simply staying set (checkbox
// untouched) does NOT count as "reviewed wins" - content changing after a record
// was already reviewed must still reset it.
#[Autoconfigure(public: true)]
final class AiMetaDataHandlerHook
{
    // Keyed by "$table:$id". "reviewed" (the submitted checkbox, not yet the actual
    // reviewing user) is stashed via reviewedBy as a 1/0 placeholder - only isReviewed()
    // is ever read back from it here, the real backend user id is resolved later in
    // processDatamap_postProcessFieldArray().
    /** @var array<string, AiMetadata> */
    private array $pendingValues = [];

    public function __construct(
        private readonly Context $context,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    /**
     * Runs before DataHandler's own fillInFieldArray()/checkValue()/
     * compareFieldArrayWithCurrentAndUnset() ever see these fields. That last one
     * reads $currentRecord[$col] for every submitted field to decide whether it is
     * unchanged - since these 3 fields have no real column of their own, that access
     * is an undefined array key, and TYPO3's error handler turns that PHP warning
     * into a thrown exception, aborting the whole save. Stripping them here, before
     * they ever reach that code, avoids it entirely.
     */
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        if (
            !array_key_exists('ai_created', $incomingFieldArray)
            && !array_key_exists('ai_modified', $incomingFieldArray)
            && !array_key_exists('reviewed', $incomingFieldArray)
        ) {
            return;
        }

        $this->pendingValues[$table . ':' . $id] = (new AiMetadata())
            ->withAiCreated((bool)($incomingFieldArray['ai_created'] ?? false))
            ->withAiModified((bool)($incomingFieldArray['ai_modified'] ?? false))
            ->withReviewedBy(($incomingFieldArray['reviewed'] ?? false) ? 1 : 0);
        unset($incomingFieldArray['ai_created'], $incomingFieldArray['ai_modified'], $incomingFieldArray['reviewed']);
    }

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        $key = $table . ':' . $id;
        if (!isset($this->pendingValues[$key])) {
            return;
        }
        $pendingAiMetadata = $this->pendingValues[$key];
        unset($this->pendingValues[$key]);

        $existingAiMetadata = $status === 'update'
            ? AiMetadata::fromJsonString(BackendUtility::getRecord($table, (int)$id, 'ai_metadata')['ai_metadata'] ?? null)
            : new AiMetadata();

        if (!$pendingAiMetadata->isFlagged() && !$existingAiMetadata->isFlagged() && !$existingAiMetadata->isReviewed()) {
            // Never flagged, and nothing stored yet - nothing to persist.
            return;
        }

        if (!$pendingAiMetadata->isFlagged()) {
            // Unflagged now: nothing worth tracking anymore. NULL the column
            // (instead of storing all-zero JSON) so AiMetadataRecordFinder's
            // "WHERE ai_metadata IS NOT NULL" stays an accurate filter on its own.
            $fieldArray['ai_metadata'] = null;
            return;
        }

        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);

        // "reviewed wins" only applies if the editor actively ticked reviewed in this
        // very save (0/none -> 1). If it was already reviewed and simply stayed reviewed
        // because the checkbox wasn't touched, that's not a decision made in this save
        // and must not block the reset below - otherwise an already-reviewed record
        // could never be flagged for re-review again once content changes.
        $reviewedJustTicked = $pendingAiMetadata->isReviewed() && !$existingAiMetadata->isReviewed();

        // Content changing on an already-reviewed, still-flagged record means review
        // is needed again - reset reviewed_by, unless this same save also (re-)ticks it.
        // $pendingAiMetadata->isFlagged() is already guaranteed true here (see the early return above).
        $contentChanged = $status === 'update' && $this->hasRelevantContentChange($table, $fieldArray);
        $needsReviewReset = $contentChanged && $existingAiMetadata->isReviewed() && !$reviewedJustTicked;

        $reviewedBy = $needsReviewReset ? 0 : ($pendingAiMetadata->isReviewed() ? $beUserId : 0);

        // reviewed_timestamp only moves when reviewed actually flips from unreviewed
        // to reviewed in this save; it's cleared again once a reset makes it
        // unreviewed, and otherwise just keeps whatever was stored before.
        $reviewedTimestamp = match (true) {
            $needsReviewReset => 0,
            $reviewedJustTicked => (int)$this->context->getPropertyFromAspect('date', 'timestamp'),
            default => $existingAiMetadata->getReviewedTimestamp(),
        };

        // DataHandler/Doctrine already JSON-encode values written to a json-typed
        // column - passing an already-encoded string here would double-encode it.
        $fieldArray['ai_metadata'] = (new AiMetadata())
            ->withAiCreated($pendingAiMetadata->isAiCreated())
            ->withAiModified($pendingAiMetadata->isAiModified())
            ->withReviewedBy($reviewedBy)
            ->withReviewedTimestamp($reviewedTimestamp)
            ->toArray();
    }

    /**
     * $fieldArray here is not a reliable "did anything change" flag on its own.
     * DataHandler's own compareFieldArrayWithCurrentAndUnset() deliberately never
     * strips MM-relation fields even when their value is unchanged ("except the
     * current field holds MM relations", DataHandler::compareFieldArrayWithCurrentAndUnset()),
     * and it only adds tstamp back in once $fieldArray is already non-empty - so tstamp
     * itself is never the cause, but a table with e.g. an MM-related category field
     * would otherwise always look "changed" here. The transOrigDiffSourceField (usually
     * l18n_diffsource) is a re-serialized snapshot of the record used for the
     * translation diff view, not user content, and is rewritten on saves where nothing
     * the editor did actually changed - most noticeably from the second save onwards.
     * All three are filtered out before deciding.
     */
    private function hasRelevantContentChange(string $table, array $fieldArray): bool
    {
        $schema = $this->tcaSchemaFactory->get($table);

        $ignoredFields = array_filter([
            $schema->hasCapability(TcaSchemaCapability::UpdatedAt)
                ? $schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName()
                : null,
            $schema->hasCapability(TcaSchemaCapability::Language)
                ? $schema->getCapability(TcaSchemaCapability::Language)->getDiffSourceField()?->getName()
                : null,
        ]);

        foreach ($fieldArray as $field => $value) {
            if (in_array($field, $ignoredFields, true)) {
                continue;
            }
            $fieldConfig = $schema->hasField($field) ? $schema->getField($field)->getConfiguration() : [];
            if (!empty($fieldConfig['MM'])) {
                continue;
            }
            return true;
        }

        return false;
    }
}
