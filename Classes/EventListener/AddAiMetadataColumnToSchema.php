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
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;

// Adds the ai_metadata JSON column to every applicable table's schema, so
// AiMetaDataHandlerHook can write ai_created/ai_modified/reviewed_by into it
// directly as part of DataHandler's own insert/update - no separate table.
#[AsEventListener(identifier: 'ai-label/add-ai-metadata-column-to-schema')]
final class AddAiMetadataColumnToSchema
{
    public function __construct(private readonly ApplicableTablesProvider $applicableTablesProvider)
    {
    }

    public function __invoke(AlterTableDefinitionStatementsEvent $event): void
    {
        foreach ($this->applicableTablesProvider->getApplicableTables() as $tableName) {
            $event->addSqlData(sprintf('CREATE TABLE %s (ai_metadata json DEFAULT NULL);', $tableName));
        }
    }
}
