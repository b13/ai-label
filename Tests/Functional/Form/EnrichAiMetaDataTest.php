<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\Form;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\Form\FormDataProvider\EnrichAiMetaData;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDefaultValues;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaJson;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Reproduces the exact FormEngine data provider chain EnrichAiMetaData runs in,
// using the real, TCA-compiled ai_metadata column config (AddAiMetaFieldsToTca) -
// not a hand-rolled one - so a regression (e.g. someone removing 'nullable'/'default'
// from that listener) would break this test too.
class EnrichAiMetaDataTest extends FunctionalTestCase
{
    // ai_label composer-requires typo3/cms-filelist (for the file list marker event
    // listener) - not part of testing-framework's default sysext set, so it must be
    // loaded explicitly or PackageCollection throws "depends on package filelist".
    protected array $coreExtensionsToLoad = [
        'filelist',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/ai_label',
    ];

    /** @return array<string, array<string, mixed>> */
    private function getProcessedTcaColumns(): array
    {
        return [
            'ai_origin' => $GLOBALS['TCA']['tt_content']['columns']['ai_origin'],
            'ai_metadata' => $GLOBALS['TCA']['tt_content']['columns']['ai_metadata'],
        ];
    }

    #[Test]
    public function existingRecordWithGenuineNullAiMetadataDoesNotCrash(): void
    {
        // A record saved before ai_metadata was ever touched - real SQL NULL, already
        // converted to PHP null by BackendUtility::convertDatabaseRowValuesToPhp() as
        // DatabaseEditRow would do for an actual edit request.
        $result = [
            'command' => 'edit',
            'databaseRow' => ['uid' => 1, 'ai_metadata' => null],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        // isset() is false for a null value, so without 'nullable'/'default' on the TCA
        // column, this core provider would force ai_metadata to '' here - proving the
        // TCA config (not just EnrichAiMetaData itself) is what keeps it null.
        $result = (new DatabaseRowDefaultValues())->addData($result);
        self::assertNull($result['databaseRow']['ai_metadata']);

        $result = (new EnrichAiMetaData())->addData($result);
        self::assertSame(0, $result['databaseRow']['ai_origin']);
        self::assertSame(0, $result['databaseRow']['reviewed']);
    }

    #[Test]
    public function newRecordWithNoAiMetadataValueYetDoesNotCrash(): void
    {
        // Brand new record - the field isn't present in databaseRow at all yet.
        $result = [
            'command' => 'new',
            'databaseRow' => ['pid' => 1],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new DatabaseRowDefaultValues())->addData($result);
        // TcaJson only decodes/normalizes type=json fields for command=new.
        $result = (new TcaJson())->addData($result);
        self::assertNull($result['databaseRow']['ai_metadata']);

        $result = (new EnrichAiMetaData())->addData($result);
        self::assertSame(0, $result['databaseRow']['ai_origin']);
        self::assertSame(0, $result['databaseRow']['reviewed']);
    }

    #[Test]
    public function existingFlaggedRecordIsDecodedAsUsual(): void
    {
        // Sanity check: the normal, already-working case (real data, no NULL involved)
        // must keep working alongside the NULL/new fixes above.
        $result = [
            'command' => 'edit',
            'databaseRow' => [
                'uid' => 1,
                'ai_metadata' => ['ai_origin' => 1, 'reviewed_by' => 0, 'reviewed_timestamp' => 0],
            ],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new EnrichAiMetaData())->addData($result);
        self::assertSame(1, $result['databaseRow']['ai_origin']);
        self::assertSame(0, $result['databaseRow']['reviewed']);
    }
}
