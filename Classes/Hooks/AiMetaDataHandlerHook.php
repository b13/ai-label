<?php

declare(strict_types=1);

namespace B13\AiLabel\Hooks;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Strips ai_created / ai_modified from the field array before DataHandler writes it
// to the origin table (they have no real column there) and stores the values in
// tx_ailabel_domain_model_meta once the real uid of a new record is known.
final class AiMetaDataHandlerHook
{
    /** @var array<string, array{ai_created: int, ai_modified: int}> */
    private array $pendingValues = [];

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        if (!array_key_exists('ai_created', $fieldArray) && !array_key_exists('ai_modified', $fieldArray)) {
            return;
        }

        $this->pendingValues["$table:$id"] = [
            'ai_created' => (int)($fieldArray['ai_created'] ?? 0),
            'ai_modified' => (int)($fieldArray['ai_modified'] ?? 0),
        ];
        unset($fieldArray['ai_created'], $fieldArray['ai_modified']);
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

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_ailabel_domain_model_meta');
        $existingUid = $connection->select(
            ['uid'],
            'tx_ailabel_domain_model_meta',
            ['tablename' => $table, 'uid_foreign' => $uid]
        )->fetchOne();

        $data = [
            'ai_created' => $values['ai_created'],
            'ai_modified' => $values['ai_modified'],
            'be_user_id' => $beUserId,
            'tstamp' => $now,
        ];

        if ($existingUid) {
            $connection->update('tx_ailabel_domain_model_meta', $data, ['uid' => (int)$existingUid]);
        } else {
            $connection->insert('tx_ailabel_domain_model_meta', $data + [
                'tablename' => $table,
                'uid_foreign' => $uid,
                'crdate' => $now,
                'pid' => 0,
            ]);
        }
    }
}
