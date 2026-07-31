<?php

declare(strict_types=1);

namespace B13\AiLabel\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

// DatabaseRecordList only selects fields declared in TCA columns (it intersects
// its select field list against them), so ai_metadata - a schema-only column,
// never added to TCA - never ends up in $record->toArray() on its own. This adds
// it to the actual query before it runs, so MarkFlaggedRecordsInRecordList can
// read it without an extra query per row.
#[AsEventListener(identifier: 'ai-label/add-ai-metadata-to-record-list-query')]
final class AddAiMetadataToRecordListQuery
{
    public function __construct(private readonly ApplicableTablesProvider $applicableTablesProvider)
    {
    }

    public function __invoke(ModifyDatabaseQueryForRecordListingEvent $event): void
    {
        if (!in_array($event->getTable(), $this->applicableTablesProvider->getApplicableTables(), true)) {
            return;
        }

        $event->getQueryBuilder()->addSelect('ai_metadata');
    }
}
