<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Loads the current ai_created / ai_modified state from tx_ailabel_domain_model_meta
// into the record data, since these fields have no real column on the origin table.
final class EnrichAiMetaData implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        $uid = (int)($result['databaseRow']['uid'] ?? 0);
        if ($uid <= 0 || !isset($result['processedTca']['columns']['ai_created'])) {
            return $result;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_ailabel_domain_model_meta');
        $row = $connection->select(
            ['ai_created', 'ai_modified'],
            'tx_ailabel_domain_model_meta',
            ['tablename' => $result['tableName'], 'uid_foreign' => $uid]
        )->fetchAssociative();

        $result['databaseRow']['ai_created'] = (int)($row['ai_created'] ?? 0);
        $result['databaseRow']['ai_modified'] = (int)($row['ai_modified'] ?? 0);

        return $result;
    }
}
