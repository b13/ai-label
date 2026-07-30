<?php

declare(strict_types=1);

namespace B13\AiLabel\Domain\Repository;

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Model\AiMetadata;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

// Collects every record across the applicable tables whose ai_metadata marks it
// as ai_created or ai_modified. Not an Extbase repository, no persistence layer
// in use here - just a plain query helper for the overview module.
final class AiMetadataRecordFinder
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
    ) {
    }

    /** @return list<array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata}> */
    public function findFlaggedRecords(): array
    {
        $records = [];
        foreach ($this->applicableTablesProvider->getApplicableTables() as $table) {
            $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
            $rows = $queryBuilder
                ->select('*')
                ->from($table)
                ->where($queryBuilder->expr()->isNotNull('ai_metadata'))
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $metadata = new AiMetadata($row['ai_metadata'] ?? null);
                if (!$metadata->isFlagged()) {
                    continue;
                }
                $records[] = [
                    'table' => $table,
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'title' => BackendUtility::getRecordTitle($table, $row),
                    'metadata' => $metadata,
                ];
            }
        }

        return $records;
    }
}
