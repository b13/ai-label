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
// tx_ailabel_domain_model_meta is a history log, not a 1:1 shadow row. "reviewed" is
// a plain boolean checkbox in the form, but persisted as reviewed_by: the be_users uid
// that reviewed it, or 0 for "review required". As long as a record is flagged
// ai_created/ai_modified, a save that changes real content appends a fresh row with
// reviewed_by reset to 0, so the editor has to review again -
// but only if the latest existing row doesn't already have reviewed=0 (no point
// stacking another pending entry on top) and only if that same save doesn't also
// actively tick "reviewed" from 0/none to 1 (reviewed wins over forcing a fresh
// review). Reviewed already being 1 and simply staying 1 (checkbox untouched)
// does NOT count as "reviewed wins" - content changing after a record was already
// reviewed must still reset it to 0 in a fresh entry. Every other save updates the
// latest row's checkbox values in place instead of inserting - this must happen
// unconditionally whenever no fresh entry is created, so toggling a checkbox back
// off is never silently dropped.
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

        if ($status === 'new') {
            $connection->insert(self::META_TABLE, [
                'pid' => 0,
                'tablename' => $table,
                'uid_foreign' => $uid,
                'ai_created' => $values['ai_created'],
                'ai_modified' => $values['ai_modified'],
                'reviewed_by' => $reviewedBy,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            return;
        }

        $contentChanged = $this->hasRelevantContentChange($table, $fieldArray);
        $aiFlagged = $values['ai_created'] === 1 || $values['ai_modified'] === 1;

        $queryBuilder = $connection->createQueryBuilder();
        $latestRow = $queryBuilder
            ->select('uid', 'reviewed_by')
            ->from(self::META_TABLE)
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $latestWasReviewed = $latestRow !== false && (int)$latestRow['reviewed_by'] > 0;

        // "reviewed wins" only applies if the editor actively ticked reviewed in this
        // very save (0/none -> 1). If it was already 1 and simply stayed 1 because the
        // checkbox wasn't touched, that's not a decision made in this save and must not
        // block the reset below - otherwise an already-reviewed record could never be
        // flagged for re-review again once content changes.
        $reviewedJustTicked = $values['reviewed'] === 1 && !$latestWasReviewed;

        $needsFreshReviewEntry = $contentChanged
            && $aiFlagged
            && !$reviewedJustTicked
            && ($latestRow === false || $latestWasReviewed);

        if ($needsFreshReviewEntry) {
            // First entry, or content changed again after having been reviewed:
            // fresh history entry, review required again (reviewed_by=0).
            $connection->insert(self::META_TABLE, [
                'pid' => 0,
                'tablename' => $table,
                'uid_foreign' => $uid,
                'ai_created' => $values['ai_created'],
                'ai_modified' => $values['ai_modified'],
                'reviewed_by' => 0,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            return;
        }

        // Every other case - metadata-only save, reviewed wins, or content changed
        // but there's already a pending unreviewed entry - syncs the latest row's
        // checkbox values in place. This must always run: skipping it here would
        // also silently drop e.g. unchecking ai_created/ai_modified again.
        $data = [
            'ai_created' => $values['ai_created'],
            'ai_modified' => $values['ai_modified'],
            'reviewed_by' => $reviewedBy,
            'tstamp' => $now,
        ];

        if ($latestRow !== false) {
            $connection->update(self::META_TABLE, $data, ['uid' => (int)$latestRow['uid']]);
        } else {
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
