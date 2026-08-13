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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AiMetaDataHandlerHookTest extends FunctionalTestCase
{
    protected ?DataHandler $dataHandler = null;

    protected ?BackendUserAuthentication $backendUser = null;

    // ai_label composer-requires typo3/cms-filelist (for the file list marker event
    // listener) and typo3/cms-fluid-styled-content - neither is part of testing-framework's
    // default sysext set, so both must be loaded explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        // Fixed "now" so the hook's reviewed_timestamp is deterministic. It has to be
        // frozen *after* setUpBackendUser(): on v14 the session it creates is validated
        // against $GLOBALS['EXEC_TIME'], and a value years in the past makes that session
        // look long expired, which surfaces as "Can not initialize backend user".
        // Context caches its date aspect the first time it is instantiated - which happens
        // during that authentication - so $GLOBALS['EXEC_TIME'] alone no longer reaches the
        // hook, and the aspect has to be replaced explicitly as well.
        $GLOBALS['EXEC_TIME'] = 1440000000;
        GeneralUtility::makeInstance(Context::class)->setAspect(
            'date',
            new DateTimeAspect(new \DateTimeImmutable('@1440000000'))
        );
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $this->dataHandler = GeneralUtility::makeInstance(DataHandler::class);
    }

    #[Test]
    public function newRecordWithAiCreatedSetsMetadata(): void
    {
        $data = [
            'tt_content' => [
                'NEW1' => [
                    'pid' => 1,
                    'header' => 'A new content element',
                    'CType' => 'text',
                    'tx_ailabel_origin' => 1,
                    'tx_ailabel_reviewed' => 0,
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
                    'tx_ailabel_origin' => 0,
                    'tx_ailabel_reviewed' => 0,
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
                    'tx_ailabel_origin' => 0,
                    'tx_ailabel_reviewed' => 0,
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
                    'tx_ailabel_origin' => 0,
                    'tx_ailabel_reviewed' => 0,
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
                    'tx_ailabel_origin' => 1,
                    'tx_ailabel_reviewed' => 1,
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
                    'tx_ailabel_origin' => 1,
                    // Stays checked (unchanged from the fixture) - the editor only
                    // fixed the header, they didn't touch the reviewed checkbox.
                    'tx_ailabel_reviewed' => 1,
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
                    'tx_ailabel_origin' => 1,
                    // Newly ticked in the very same save that also changes the header.
                    'tx_ailabel_reviewed' => 1,
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
                    'tx_ailabel_origin' => 1,
                    'tx_ailabel_reviewed' => 0,
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
    // final tx_ailabel_metadata value ends up correct.
    #[Test]
    public function updatingAnExistingRecordWritesToSysHistory(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiMetaDataHandlerHook/FlaggedAndUnreviewedRecord.csv');
        $data = [
            'tt_content' => [
                1 => [
                    'tx_ailabel_origin' => 1,
                    'tx_ailabel_reviewed' => 1,
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
        self::assertStringContainsString('tx_ailabel_metadata', $historyEntries[0]['history_data']);
    }
}
