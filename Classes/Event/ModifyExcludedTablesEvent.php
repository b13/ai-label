<?php

declare(strict_types=1);

namespace B13\AiLabel\Event;

// Allows other extensions to add or remove tables that should not receive
// the ai_created / ai_modified fields.
final class ModifyExcludedTablesEvent
{
    public function __construct(private array $excludedTables)
    {
    }

    public function getExcludedTables(): array
    {
        return $this->excludedTables;
    }

    public function addExcludedTable(string $tableName): void
    {
        if (!in_array($tableName, $this->excludedTables, true)) {
            $this->excludedTables[] = $tableName;
        }
    }

    public function removeExcludedTable(string $tableName): void
    {
        $this->excludedTables = array_values(array_diff($this->excludedTables, [$tableName]));
    }
}
