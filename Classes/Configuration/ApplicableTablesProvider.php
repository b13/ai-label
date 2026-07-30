<?php

declare(strict_types=1);

namespace B13\AiLabel\Configuration;

use B13\AiLabel\Event\ModifyExcludedTablesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

// Shared by AddAiMetaFieldsToTca (TCA columns) and AddAiMetadataColumnToSchema
// (the ai_metadata schema column) so both stay in sync on which tables are affected.
final class ApplicableTablesProvider
{
    // Tables that never get ai_label fields, e.g. system tables.
    // Extend or shrink this list by listening to ModifyExcludedTablesEvent.
    private const DEFAULT_EXCLUDED_TABLES = [
        'be_users',
        'be_groups',
        'sys_log',
        'sys_history',
        'sys_redirect',
        'sys_registry',
    ];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    /** @return string[] */
    public function getApplicableTables(): array
    {
        /** @var ModifyExcludedTablesEvent $excludeEvent */
        $excludeEvent = $this->eventDispatcher->dispatch(
            new ModifyExcludedTablesEvent(self::DEFAULT_EXCLUDED_TABLES)
        );
        $excludedTables = $excludeEvent->getExcludedTables();

        $tables = [];
        foreach ($GLOBALS['TCA'] as $tableName => $tableConfig) {
            if (in_array($tableName, $excludedTables, true)) {
                continue;
            }
            // Skip tables without a proper record definition, e.g. plain MM tables
            if (empty($tableConfig['columns']) || empty($tableConfig['ctrl']['title'] ?? '')) {
                continue;
            }
            $tables[] = $tableName;
        }

        return $tables;
    }
}
