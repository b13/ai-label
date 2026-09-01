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

use B13\AiLabel\Form\FormDataProvider\EnrichWatermarkOverride;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDefaultValues;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaJson;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EnrichWatermarkOverrideTest extends FunctionalTestCase
{
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

    // These columns only exist in $GLOBALS['TCA'] in "baked" mode, read too early
    // at bootstrap for a test method's $GLOBALS override - hand-built here instead,
    // same approach as AddWatermarkFieldsToTcaTest.
    /** @return array<string, mixed> */
    private function getProcessedTcaColumns(): array
    {
        return [
            'tx_ailabel_watermark_position' => ['config' => ['type' => 'user', 'renderType' => 'aiLabelVirtualSelect']],
            'tx_ailabel_watermark_width' => ['config' => ['type' => 'user', 'renderType' => 'aiLabelVirtualSelect']],
            'tx_ailabel_watermark' => ['config' => ['type' => 'json', 'nullable' => true, 'default' => null]],
        ];
    }

    #[Test]
    public function existingRecordWithGenuineNullWatermarkOverrideDoesNotCrash(): void
    {
        $result = [
            'command' => 'edit',
            'databaseRow' => ['uid' => 1, 'tx_ailabel_watermark' => null],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new DatabaseRowDefaultValues())->addData($result);
        self::assertNull($result['databaseRow']['tx_ailabel_watermark']);

        $result = (new EnrichWatermarkOverride())->addData($result);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_position']);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_color']);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_width']);
    }

    #[Test]
    public function newRecordWithNoWatermarkOverrideValueYetDoesNotCrash(): void
    {
        $result = [
            'command' => 'new',
            'databaseRow' => ['pid' => 1],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new DatabaseRowDefaultValues())->addData($result);
        $result = (new TcaJson())->addData($result);
        self::assertNull($result['databaseRow']['tx_ailabel_watermark']);

        $result = (new EnrichWatermarkOverride())->addData($result);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_position']);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_color']);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_width']);
    }

    #[Test]
    public function existingOverrideIsDecodedAsUsual(): void
    {
        $result = [
            'command' => 'edit',
            'databaseRow' => [
                'uid' => 1,
                'tx_ailabel_watermark' => ['position' => 'top-left', 'color' => 'white', 'width' => 80],
            ],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new EnrichWatermarkOverride())->addData($result);
        self::assertSame('top-left', $result['databaseRow']['tx_ailabel_watermark_position']);
        self::assertSame('white', $result['databaseRow']['tx_ailabel_watermark_color']);
        self::assertSame(80, $result['databaseRow']['tx_ailabel_watermark_width']);
    }

    // A stored width outside the closed {160, 80} set (e.g. from before this became a
    // fixed choice) decodes the same as a missing one - "inherit the global default".
    #[Test]
    public function anUnsupportedStoredWidthDecodesAsInherit(): void
    {
        $result = [
            'command' => 'edit',
            'databaseRow' => [
                'uid' => 1,
                'tx_ailabel_watermark' => ['position' => null, 'color' => null, 'width' => 200],
            ],
            'processedTca' => ['columns' => $this->getProcessedTcaColumns()],
        ];

        $result = (new EnrichWatermarkOverride())->addData($result);
        self::assertSame('', $result['databaseRow']['tx_ailabel_watermark_width']);
    }
}
