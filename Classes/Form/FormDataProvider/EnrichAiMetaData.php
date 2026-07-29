<?php

declare(strict_types=1);

namespace B13\AiLabel\Form\FormDataProvider;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Loads the current ai_created / ai_modified / reviewed state from
// tx_ailabel_domain_model_meta into the record data, since these fields have
// no real column on the origin table. The meta table is a history log (see
// AiMetaDataHandlerHook), so this reads the most recent entry, i.e. the
// current state.
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
        $queryBuilder = $connection->createQueryBuilder();
        $row = $queryBuilder
            ->select('ai_created', 'ai_modified', 'reviewed')
            ->from('tx_ailabel_domain_model_meta')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($result['tableName'])),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $result['databaseRow']['ai_created'] = (int)($row['ai_created'] ?? 0);
        $result['databaseRow']['ai_modified'] = (int)($row['ai_modified'] ?? 0);
        $result['databaseRow']['reviewed'] = (int)($row['reviewed'] ?? 0);

        return $result;
    }
}
