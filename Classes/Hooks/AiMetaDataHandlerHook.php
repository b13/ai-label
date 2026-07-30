<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

// Folds ai_created / ai_modified / reviewed into the ai_metadata JSON column (schema
// added by AddAiMetadataColumnToSchema) as part of DataHandler's own insert/update -
// no separate table, no separate query to write. "reviewed" is a plain boolean
// checkbox in the form, but persisted as reviewed_by inside the JSON: the be_users
// uid that reviewed it, or 0 for "review required".
//
// As long as a record is flagged ai_created/ai_modified, a save that changes real
// content resets reviewed_by to 0, so the editor has to review again - unless that
// same save also actively ticks "reviewed" from 0/none to 1 (reviewed wins over
// forcing a reset). Reviewed already being set and simply staying set (checkbox
// untouched) does NOT count as "reviewed wins" - content changing after a record
// was already reviewed must still reset it.
final class AiMetaDataHandlerHook
{
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        // Read from the untouched original submission, not from $fieldArray: DataHandler's
        // compareFieldArrayWithCurrentAndUnset() strips a field whose submitted value is
        // considered "equal to stored" - and since these 3 fields have no real column of
        // their own to compare against, unchecking one (submitting 0) gets treated as
        // unchanged and silently dropped from $fieldArray before this hook ever sees it.
        $incoming = $dataHandler->datamap[$table][$id] ?? [];
        if (
            !array_key_exists('ai_created', $incoming)
            && !array_key_exists('ai_modified', $incoming)
            && !array_key_exists('reviewed', $incoming)
        ) {
            return;
        }

        // They have no real column of their own - never let them reach the SQL query.
        unset($fieldArray['ai_created'], $fieldArray['ai_modified'], $fieldArray['reviewed']);

        $aiCreated = (int)($incoming['ai_created'] ?? 0);
        $aiModified = (int)($incoming['ai_modified'] ?? 0);
        $reviewed = (int)($incoming['reviewed'] ?? 0);
        $aiFlagged = $aiCreated === 1 || $aiModified === 1;

        $existingMetadata = null;
        if ($status === 'update') {
            $existingRow = BackendUtility::getRecord($table, (int)$id, 'ai_metadata');
            $decoded = $existingRow && $existingRow['ai_metadata']
                ? json_decode((string)$existingRow['ai_metadata'], true)
                : null;
            $existingMetadata = is_array($decoded) ? $decoded : null;
        }

        if (!$aiFlagged && $existingMetadata === null) {
            // Never flagged, and nothing stored yet - nothing to persist.
            return;
        }

        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);
        $wasReviewed = (int)($existingMetadata['reviewed_by'] ?? 0) > 0;
        $reviewedBy = $reviewed === 1 ? $beUserId : 0;

        // "reviewed wins" only applies if the editor actively ticked reviewed in this
        // very save (0/none -> 1). If it was already reviewed and simply stayed reviewed
        // because the checkbox wasn't touched, that's not a decision made in this save
        // and must not block the reset below - otherwise an already-reviewed record
        // could never be flagged for re-review again once content changes.
        $reviewedJustTicked = $reviewed === 1 && !$wasReviewed;

        // Content changing on an already-reviewed, still-flagged record means review
        // is needed again - reset reviewed_by, unless this same save also (re-)ticks it.
        $contentChanged = $status === 'update' && $this->hasRelevantContentChange($table, $fieldArray);
        $needsReviewReset = $contentChanged && $aiFlagged && $wasReviewed && !$reviewedJustTicked;

        // DataHandler/Doctrine already JSON-encode values written to a json-typed
        // column - passing an already-encoded string here would double-encode it.
        $fieldArray['ai_metadata'] = [
            'ai_created' => $aiCreated,
            'ai_modified' => $aiModified,
            'reviewed_by' => $needsReviewReset ? 0 : $reviewedBy,
        ];
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
        $ignoredFields = array_filter([
            $GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? null,
            $GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'] ?? null,
        ]);
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];

        foreach ($fieldArray as $field => $value) {
            if (in_array($field, $ignoredFields, true)) {
                continue;
            }
            if (!empty($columns[$field]['config']['MM'])) {
                continue;
            }
            return true;
        }

        return false;
    }
}
