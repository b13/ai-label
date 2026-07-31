<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Datahandler;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

class AiMetaDataHandlerHookTest extends AbstractDatahandler
{
    #[Test]
    public function newRecordWithAiCreatedSetsMetadata(): void
    {
        $data = [
            'tt_content' => [
                'NEW1' => [
                    'pid' => 1,
                    'header' => 'A new content element',
                    'CType' => 'text',
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    'reviewed' => 0,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/NewRecordWithAiCreatedResult.csv');
    }

    #[Test]
    public function newRecordWithoutAiFlagsStaysNull(): void
    {
        $data = [
            'tt_content' => [
                'NEW1' => [
                    'pid' => 1,
                    'header' => 'A plain content element',
                    'CType' => 'text',
                    'ai_created' => 0,
                    'ai_modified' => 0,
                    'reviewed' => 0,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/NewRecordWithoutAiFlagsResult.csv');
    }

    #[Test]
    public function updatingUnflaggedRecordStaysNull(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/UnflaggedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'header' => 'Updated content',
                    'ai_created' => 0,
                    'ai_modified' => 0,
                    'reviewed' => 0,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/UpdatingUnflaggedRecordStaysNullResult.csv');
    }

    #[Test]
    public function unflaggingRecordClearsMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndReviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'ai_created' => 0,
                    'ai_modified' => 0,
                    'reviewed' => 0,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/UnflaggingRecordClearsMetadataResult.csv');
    }

    #[Test]
    public function tickingReviewedSetsReviewedBy(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndUnreviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    'reviewed' => 1,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/TickingReviewedSetsReviewedByResult.csv');
    }

    #[Test]
    public function contentChangeOnReviewedRecordResetsReview(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndReviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'header' => 'Updated content',
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    // Stays checked (unchanged from the fixture) - the editor only
                    // fixed the header, they didn't touch the reviewed checkbox.
                    'reviewed' => 1,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/ContentChangeOnReviewedRecordResetsReviewResult.csv');
    }

    #[Test]
    public function contentChangeWithReviewedTickedInSameSaveWins(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndUnreviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'header' => 'Updated content',
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    // Newly ticked in the very same save that also changes the header.
                    'reviewed' => 1,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/ContentChangeWithReviewedTickedInSameSaveWinsResult.csv');
    }

    #[Test]
    public function contentChangeOnAlreadyPendingRecordStaysPending(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndUnreviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'header' => 'Updated content',
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    'reviewed' => 0,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/ContentChangeOnAlreadyPendingRecordStaysPendingResult.csv');
    }

    // Whole point of routing updates through AiLabelApi::aiMetadataUpdate() instead
    // of writing $fieldArray directly: compareFieldArrayWithCurrentAndUnset() (the
    // method that decides what gets logged) only ever sees the change this way -
    // this verifies a real sys_history entry actually shows up, not just that the
    // final ai_metadata value ends up correct.
    #[Test]
    public function updatingAnExistingRecordWritesToSysHistory(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndUnreviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'ai_created' => 1,
                    'ai_modified' => 0,
                    'reviewed' => 1,
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_history');
        $historyEntries = $queryBuilder
            ->select('history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertNotEmpty($historyEntries, 'Expected at least one sys_history entry for tt_content:1');
        self::assertStringContainsString('ai_metadata', $historyEntries[0]['history_data']);
    }
}
