<?php

declare(strict_types=1);

namespace B13\AiLabel\Event;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Domain\Model\AiMetadata;

// Dispatched once per record built by AiMetadataRecordFinder::buildRecord() - lets
// listeners add extra keys to (or change existing keys of) the record array used by
// the overview module and MarkFlaggedPageInLayoutModule, via setRecordProperty().
// $row is the raw DB row that record was built from, for listeners that need more
// than what's already in $record (which table it's from is in $record['table']).
final class AfterRecordIsBuiltEvent
{
    /**
     * @param array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, site: ?string, siteLabel: string} $record
     * @param array<string, mixed> $row
     */
    public function __construct(
        private array $record,
        private readonly array $row,
    ) {
    }

    /** @return array{table: string, uid: int, pid: int, title: string, metadata: AiMetadata, icon: string, tableLabel: string, author: string, reviewBadge: string, site: ?string, siteLabel: string, ...} */
    public function getRecord(): array
    {
        return $this->record;
    }

    public function setRecordProperty(string $key, mixed $value): void
    {
        $this->record[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function getRow(): array
    {
        return $this->row;
    }
}
