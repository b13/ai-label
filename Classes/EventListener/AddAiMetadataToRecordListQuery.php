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

// DatabaseRecordList::getFieldsToSelect() only builds its SELECT list from the
// visible showitem columns plus a fixed set of ctrl-capability fields (uid, pid,
// tstamp, workspace/language/label fields, ...) - it never iterates all TCA
// columns. ai_metadata is a real TCA type=json column, but deliberately not part
// of any showitem/palette and not tied to a ctrl capability, so it's still never
// selected on its own even though it's TCA-registered now. This adds it to the
// actual query explicitly before it runs, so MarkFlaggedRecordsInRecordList can
// read it without an extra query per row.
#[AsEventListener(identifier: 'ai-label/add-ai-metadata-to-record-list-query')]
final class AddAiMetadataToRecordListQuery
{
    public function __construct(private readonly ApplicableTablesProvider $applicableTablesProvider)
    {
    }

    public function __invoke(ModifyDatabaseQueryForRecordListingEvent $event): void
    {
        if (!$this->applicableTablesProvider->isTableApplicable($event->getTable())) {
            return;
        }

        $event->getQueryBuilder()->addSelect('ai_metadata');
    }
}
