<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Strips ai_created / ai_modified / reviewed from the field array before
// DataHandler writes it to the origin table (they have no real column there)
// and stores the values in tx_ailabel_domain_model_meta once the real uid of
// a new record is known.
//
// tx_ailabel_domain_model_meta holds exactly one row per (tablename, uid_foreign) -
// this is an upsert, never a history: a row is only ever inserted once, and every
// subsequent save updates that same row in place. "reviewed" is a plain boolean
// checkbox in the form, but persisted as reviewed_by: the be_users uid that reviewed
// it, or 0 for "review required". As long as a record is flagged ai_created/ai_modified,
// a save that changes real content resets reviewed_by to 0 on the existing row, so the
// editor has to review again - unless that same save also actively ticks "reviewed"
// from 0/none to 1 (reviewed wins over forcing a reset). Reviewed already being set and
// simply staying set (checkbox untouched) does NOT count as "reviewed wins" - content
// changing after a record was already reviewed must still reset it.
final class AiMetaDataHandlerHook
{
    private const META_TABLE = 'tx_ailabel_domain_model_meta';

    /** @var array<string, array{ai_created: int, ai_modified: int, reviewed: int}> */
    private array $pendingValues = [];

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        // Read from the untouched original submission, not from $fieldArray: DataHandler's
        // compareFieldArrayWithCurrentAndUnset() strips a field whose submitted value is
        // considered "equal to stored" - and since these 3 fields have no real column to
        // compare against, unchecking one (submitting 0) gets treated as unchanged and
        // silently dropped from $fieldArray before this hook ever sees it.
        $incoming = $dataHandler->datamap[$table][$id] ?? [];
        if (
            !array_key_exists('ai_created', $incoming)
            && !array_key_exists('ai_modified', $incoming)
            && !array_key_exists('reviewed', $incoming)
        ) {
            return;
        }

        $this->pendingValues["$table:$id"] = [
            'ai_created' => (int)($incoming['ai_created'] ?? 0),
            'ai_modified' => (int)($incoming['ai_modified'] ?? 0),
            'reviewed' => (int)($incoming['reviewed'] ?? 0),
        ];
        // Still strip them from $fieldArray in case they did survive the compare-and-unset
        // (e.g. the very first time a value is set to 1) - they have no real column.
        unset($fieldArray['ai_created'], $fieldArray['ai_modified'], $fieldArray['reviewed']);
    }

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        $key = "$table:$id";
        if (!isset($this->pendingValues[$key])) {
            return;
        }

        $values = $this->pendingValues[$key];
        unset($this->pendingValues[$key]);

        $uid = $status === 'new' ? (int)$dataHandler->substNEWwithIDs[$id] : (int)$id;
        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);
        $now = $GLOBALS['EXEC_TIME'] ?? time();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::META_TABLE);

        // The "reviewed" checkbox itself is just a boolean toggle in the form; what's
        // actually persisted is who reviewed it (reviewed_by > 0) or nobody yet (0).
        $reviewedBy = $values['reviewed'] === 1 ? $beUserId : 0;
        $aiFlagged = $values['ai_created'] === 1 || $values['ai_modified'] === 1;

        $queryBuilder = $connection->createQueryBuilder();
        $existingRow = $queryBuilder
            ->select('uid', 'reviewed_by')
            ->from(self::META_TABLE)
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        $wasReviewed = $existingRow !== false && (int)$existingRow['reviewed_by'] > 0;

        // "reviewed wins" only applies if the editor actively ticked reviewed in this
        // very save (0/none -> 1). If it was already reviewed and simply stayed reviewed
        // because the checkbox wasn't touched, that's not a decision made in this save
        // and must not block the reset below - otherwise an already-reviewed record
        // could never be flagged for re-review again once content changes.
        $reviewedJustTicked = $values['reviewed'] === 1 && !$wasReviewed;

        // Content changing on an already-reviewed, still-flagged record means review
        // is needed again - reset reviewed_by, unless this same save also (re-)ticks it.
        $contentChanged = $status === 'update' && $this->hasRelevantContentChange($table, $fieldArray);
        $needsReviewReset = $contentChanged && $aiFlagged && $wasReviewed && !$reviewedJustTicked;

        $data = [
            'ai_created' => $values['ai_created'],
            'ai_modified' => $values['ai_modified'],
            'reviewed_by' => $needsReviewReset ? 0 : $reviewedBy,
            'tstamp' => $now,
        ];

        if ($existingRow !== false) {
            $connection->update(self::META_TABLE, $data, ['uid' => (int)$existingRow['uid']]);
        } elseif ($aiFlagged) {
            // A first entry only ever makes sense for a record that's actually flagged -
            // no point tracking plain, never-AI-touched records.
            $connection->insert(self::META_TABLE, $data + [
                'pid' => 0,
                'tablename' => $table,
                'uid_foreign' => $uid,
                'crdate' => $now,
            ]);
        }
    }

    /**
     * $fieldArray (as received by processDatamap_afterDatabaseOperations) is not a
     * reliable "did anything change" flag on its own. DataHandler's own
     * compareFieldArrayWithCurrentAndUnset() deliberately never strips MM-relation
     * fields even when their value is unchanged ("except the current field holds MM
     * relations", DataHandler::compareFieldArrayWithCurrentAndUnset()), and it only
     * adds tstamp back in once $fieldArray is already non-empty - so tstamp itself
     * is never the cause, but a table with e.g. an MM-related category field would
     * otherwise always look "changed" here. The transOrigDiffSourceField (usually
     * l18n_diffsource) is a re-serialized snapshot of the record used for the
     * translation diff view, not user content, and is rewritten on saves where
     * nothing the editor did actually changed - most noticeably from the second
     * save onwards. All three are filtered out before deciding.
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
