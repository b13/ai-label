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
// tx_ailabel_domain_model_meta is a history log, not a 1:1 shadow row: as long
// as a record is flagged ai_created/ai_modified, a save that changes real content
// appends a fresh row with reviewed reset to 0, so the editor has to review again -
// but only if the latest existing row doesn't already have reviewed=0 (no point
// stacking another pending entry on top) and only if that same save doesn't also
// tick "reviewed" itself (reviewed wins over forcing a fresh review). Any other
// save (metadata-only, or the "reviewed wins" case) just updates the latest row
// in place.
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
        if (
            !array_key_exists('ai_created', $fieldArray)
            && !array_key_exists('ai_modified', $fieldArray)
            && !array_key_exists('reviewed', $fieldArray)
        ) {
            return;
        }

        $this->pendingValues["$table:$id"] = [
            'ai_created' => (int)($fieldArray['ai_created'] ?? 0),
            'ai_modified' => (int)($fieldArray['ai_modified'] ?? 0),
            'reviewed' => (int)($fieldArray['reviewed'] ?? 0),
        ];
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

        if ($status === 'new') {
            $connection->insert(self::META_TABLE, [
                'pid' => 0,
                'tablename' => $table,
                'uid_foreign' => $uid,
                'ai_created' => $values['ai_created'],
                'ai_modified' => $values['ai_modified'],
                'reviewed' => $values['reviewed'],
                'be_user_id' => $beUserId,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            return;
        }

        $contentChanged = $this->hasRelevantContentChange($table, $fieldArray);
        $aiFlagged = $values['ai_created'] === 1 || $values['ai_modified'] === 1;
        // If the same save also ticks "reviewed", that wins over forcing a fresh
        // review: no new history entry, the submitted reviewed=1 is honoured as-is.
        $reviewedTicked = $values['reviewed'] === 1;

        $queryBuilder = $connection->createQueryBuilder();
        $latestRow = $queryBuilder
            ->select('uid', 'reviewed')
            ->from(self::META_TABLE)
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        // A fresh entry is only needed if there isn't one yet, or the latest one was
        // already reviewed (reviewed=1) and content just changed again. If the latest
        // entry is still unreviewed (reviewed=0), it already represents "review
        // needed" - no point stacking another identical pending entry on top.
        $needsFreshReviewEntry = $contentChanged
            && $aiFlagged
            && !$reviewedTicked
            && ($latestRow === false || (int)$latestRow['reviewed'] === 1);

        if ($needsFreshReviewEntry) {
            $connection->insert(self::META_TABLE, [
                'pid' => 0,
                'tablename' => $table,
                'uid_foreign' => $uid,
                'ai_created' => $values['ai_created'],
                'ai_modified' => $values['ai_modified'],
                'reviewed' => 0,
                'be_user_id' => $beUserId,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            return;
        }

        if ($contentChanged && $aiFlagged && !$reviewedTicked && $latestRow !== false) {
            // Latest entry is already pending review (reviewed=0) - leave it as is.
            return;
        }

        // Either a metadata-only save (checkboxes only), or content changed but
        // reviewed was ticked in the same save (reviewed wins): update in place.
        $data = [
            'ai_created' => $values['ai_created'],
            'ai_modified' => $values['ai_modified'],
            'reviewed' => $values['reviewed'],
            'be_user_id' => $beUserId,
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
