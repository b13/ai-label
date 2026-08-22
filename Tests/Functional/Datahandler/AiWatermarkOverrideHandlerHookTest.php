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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Simpler than AiMetaDataHandlerHookTest: no review-reset rule, no reconciliation.
final class AiWatermarkOverrideHandlerHookTest extends FunctionalTestCase
{
    protected ?DataHandler $dataHandler = null;

    protected ?BackendUserAuthentication $backendUser = null;

    // ai_label composer-requires typo3/cms-filelist and typo3/cms-fluid-styled-content -
    // neither is part of testing-framework's default sysext set, so both must be loaded
    // explicitly or PackageCollection throws.
    protected array $coreExtensionsToLoad = [
        'filelist',
        'fluid_styled_content',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    // These columns only exist in "baked" mode, read at TCA-compile/bootstrap time -
    // too early for a $GLOBALS override in a test method.
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ai_label' => [
                'imageMarker' => 'baked',
            ],
        ],
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/ai_label/Tests/Functional/Imaging/Fixtures/flagged.jpg' => 'fileadmin/plain.jpg',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        // DataHandler re-indexes the underlying sys_file on save, needing it to exist.
        copy(__DIR__ . '/../Imaging/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/flagged.jpg');
        $this->backendUser = $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $this->dataHandler = GeneralUtility::makeInstance(DataHandler::class);
    }

    #[Test]
    public function newRecordWithWatermarkOverrideSetsWatermarkColumn(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/FileWithoutMetadata.csv');
        $data = [
            'sys_file_metadata' => [
                'NEW1' => [
                    'pid' => 0,
                    'file' => 2,
                    'tx_ailabel_watermark_position' => 'top-left',
                    'tx_ailabel_watermark_color' => 'white',
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/NewRecordWithWatermarkOverrideResult.csv');
    }

    #[Test]
    public function newRecordWithoutWatermarkOverrideStoresInheritForBothFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/FileWithoutMetadata.csv');
        $data = [
            'sys_file_metadata' => [
                'NEW1' => [
                    'pid' => 0,
                    'file' => 2,
                    'tx_ailabel_watermark_position' => '',
                    'tx_ailabel_watermark_color' => '',
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/NewRecordWithoutWatermarkOverrideResult.csv');
    }

    #[Test]
    public function updatingWatermarkOverrideOnAFlaggedFileWritesJson(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/FlaggedFile.csv');
        $data = [
            'sys_file_metadata' => [
                1 => [
                    'tx_ailabel_watermark_position' => 'bottom-left',
                    'tx_ailabel_watermark_color' => 'black',
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/UpdatingWatermarkOverrideResult.csv');
    }

    #[Test]
    public function updatingUnrelatedFieldLeavesWatermarkOverrideUntouched(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/FlaggedFile.csv');
        $data = [
            'sys_file_metadata' => [
                1 => [
                    'alternative' => 'A new alt text',
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();
        self::assertCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/UpdatingUnrelatedFieldResult.csv');
    }

    // Guards against serving a stale cached processed variant after the override changes.
    #[Test]
    public function updatingWatermarkOverrideInvalidatesExistingProcessedFiles(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AiWatermarkOverrideHandlerHook/FlaggedFileWithProcessedVariant.csv');
        // ProcessedFileInvalidator only deletes a file that physically exists.
        mkdir(Environment::getPublicPath() . '/fileadmin/_processed_', 0777, true);
        copy(__DIR__ . '/../Imaging/Fixtures/flagged.jpg', Environment::getPublicPath() . '/fileadmin/_processed_/processed.jpg');

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_processedfile');
        $countBefore = $queryBuilder->count('uid')->from('sys_file_processedfile')
            ->where($queryBuilder->expr()->eq('original', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame(1, $countBefore);

        $data = [
            'sys_file_metadata' => [
                1 => [
                    'tx_ailabel_watermark_position' => 'top-right',
                    'tx_ailabel_watermark_color' => 'white',
                ],
            ],
        ];
        $this->dataHandler->start($data, [], $this->backendUser);
        $this->dataHandler->process_datamap();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_processedfile');
        $countAfter = $queryBuilder->count('uid')->from('sys_file_processedfile')
            ->where($queryBuilder->expr()->eq('original', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
        self::assertSame(0, $countAfter);
    }
}
