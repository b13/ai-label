<?php

declare(strict_types=1);

namespace B13\AiLabel\Tests\Functional\EventListener;

/*
 * This file is part of TYPO3 CMS-based extension "ai_label" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use B13\AiLabel\EventListener\AddWatermarkFieldsToTca;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// Exercises the listener directly against a synthetic TCA array, rather than
// relying on full framework TCA (re)compilation mid-test - same approach
// EnrichAiMetaDataTest uses for the analogous FormDataProvider.
final class AddWatermarkFieldsToTcaTest extends FunctionalTestCase
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

    /** @return array<string, mixed> */
    private function buildTcaAfterAiMetaFields(): array
    {
        return [
            'sys_file_metadata' => [
                'columns' => [
                    'tx_ailabel_origin' => ['config' => ['type' => 'user']],
                ],
                'types' => [
                    '0' => ['showitem' => 'title, --div--;AI Metadata, --palette--;;aiLabelMetadata'],
                ],
            ],
        ];
    }

    #[Test]
    public function watermarkFieldsAreAddedWhenModeIsBaked(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'baked';

        $event = new AfterTcaCompilationEvent($this->buildTcaAfterAiMetaFields());
        $this->get(AddWatermarkFieldsToTca::class)->__invoke($event);
        $tca = $event->getTca();

        self::assertArrayHasKey('tx_ailabel_watermark_position', $tca['sys_file_metadata']['columns']);
        self::assertArrayHasKey('tx_ailabel_watermark_color', $tca['sys_file_metadata']['columns']);
        self::assertArrayHasKey('tx_ailabel_watermark_width', $tca['sys_file_metadata']['columns']);
        self::assertSame('json', $tca['sys_file_metadata']['columns']['tx_ailabel_watermark']['config']['type']);
        self::assertSame(
            'tx_ailabel_watermark_position, tx_ailabel_watermark_color, tx_ailabel_watermark_width',
            $tca['sys_file_metadata']['palettes']['aiLabelWatermark']['showitem']
        );
        self::assertStringContainsString('--palette--;;aiLabelWatermark', $tca['sys_file_metadata']['types']['0']['showitem']);
    }

    #[Test]
    public function watermarkFieldsAreNotAddedWhenModeIsOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'off';

        $event = new AfterTcaCompilationEvent($this->buildTcaAfterAiMetaFields());
        $this->get(AddWatermarkFieldsToTca::class)->__invoke($event);
        $tca = $event->getTca();

        self::assertArrayNotHasKey('tx_ailabel_watermark_position', $tca['sys_file_metadata']['columns']);
        self::assertArrayNotHasKey('tx_ailabel_watermark_color', $tca['sys_file_metadata']['columns']);
        self::assertArrayNotHasKey('tx_ailabel_watermark_width', $tca['sys_file_metadata']['columns']);
        self::assertArrayNotHasKey('tx_ailabel_watermark', $tca['sys_file_metadata']['columns']);
        self::assertArrayNotHasKey('aiLabelWatermark', $tca['sys_file_metadata']['palettes'] ?? []);
    }

    #[Test]
    public function watermarkFieldsAreNotAddedWhenModeIsOverlay(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ai_label']['imageMarker'] = 'overlay';

        $event = new AfterTcaCompilationEvent($this->buildTcaAfterAiMetaFields());
        $this->get(AddWatermarkFieldsToTca::class)->__invoke($event);
        $tca = $event->getTca();

        self::assertArrayNotHasKey('tx_ailabel_watermark_position', $tca['sys_file_metadata']['columns']);
    }
}
