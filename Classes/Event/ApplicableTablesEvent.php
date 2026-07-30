<?php

declare(strict_types=1);

namespace B13\AiLabel\Event;

// Allows other extensions to add or remove tables that should not receive
// the ai_created / ai_modified fields.
final class ApplicableTablesEvent
{
    public function __construct(private array $applicableTables)
    {
    }

    public function getApplicableTables(): array
    {
        return $this->applicableTables;
    }

    public function addApplicableTable(string $tableName): void
    {
        if (!in_array($tableName, $this->applicableTables, true)) {
            $this->applicableTables[] = $tableName;
        }
    }

    public function removeApplicableTable(string $tableName): void
    {
        $this->applicableTables = array_values(array_diff($this->applicableTables, [$tableName]));
    }
}
