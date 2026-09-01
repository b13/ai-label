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
use B13\AiLabel\Configuration\ImageMarkerSettings;
use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Enum\ImageMarkerMode;
use B13\AiLabel\Domain\Enum\WatermarkColor;
use B13\AiLabel\Domain\Enum\WatermarkPosition;
use B13\AiLabel\Domain\Enum\WatermarkWidth;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

// Adds the per-file watermark position/color override fields to sys_file_metadata's
// TCA only - unlike AddAiMetaFieldsToTca, never derived from ApplicableTablesProvider,
// since watermark config is meaningless on other tables (tt_content, pages, ...).
// Runs after "ai-label/add-ai-meta-fields-to-tca" so the aiMetadata tab already exists.
#[AsEventListener(identifier: 'ai-label/add-watermark-fields-to-tca', after: 'ai-label/add-ai-meta-fields-to-tca')]
final class AddWatermarkFieldsToTca
{
    public function __construct(
        private readonly ImageMarkerSettings $imageMarkerSettings,
        private readonly ApplicableTablesProvider $applicableTablesProvider,
    ) {
    }

    public function __invoke(AfterTcaCompilationEvent $event): void
    {
        // Not registering the fields outside "baked" mode is what makes them "only
        // available if baked", since ext_conf_template has no conditional visibility.
        if ($this->imageMarkerSettings->getMode() !== ImageMarkerMode::Baked) {
            return;
        }
        // tx_ailabel_origin (our displayCond target) wouldn't exist otherwise.
        if (!$this->applicableTablesProvider->isTableApplicable('sys_file_metadata')) {
            return;
        }

        $tca = $event->getTca();

        $tca['sys_file_metadata']['columns']['tx_ailabel_watermark_position'] = [
            'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position',
            'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.description',
            'config' => [
                'type' => 'user',
                'renderType' => 'aiLabelVirtualSelect',
                'items' => [
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.inherit', 'value' => ''],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.top_left', 'value' => WatermarkPosition::TopLeft->value],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.top_right', 'value' => WatermarkPosition::TopRight->value],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.bottom_left', 'value' => WatermarkPosition::BottomLeft->value],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_position.bottom_right', 'value' => WatermarkPosition::BottomRight->value],
                ],
            ],
            'displayCond' => 'FIELD:tx_ailabel_origin:!=:' . AiOrigin::Human->value,
        ];
        $tca['sys_file_metadata']['columns']['tx_ailabel_watermark_color'] = [
            'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_color',
            'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_color.description',
            'config' => [
                'type' => 'user',
                'renderType' => 'aiLabelVirtualSelect',
                'items' => [
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_color.inherit', 'value' => ''],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_color.black', 'value' => WatermarkColor::Black->value],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_color.white', 'value' => WatermarkColor::White->value],
                ],
            ],
            'displayCond' => 'FIELD:tx_ailabel_origin:!=:' . AiOrigin::Human->value,
        ];
        $tca['sys_file_metadata']['columns']['tx_ailabel_watermark_width'] = [
            'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_width',
            'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_width.description',
            'config' => [
                'type' => 'user',
                'renderType' => 'aiLabelVirtualSelect',
                'items' => [
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_width.inherit', 'value' => ''],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_width.regular', 'value' => WatermarkWidth::Regular->value],
                    ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.watermark_width.small', 'value' => WatermarkWidth::Small->value],
                ],
            ],
            'displayCond' => 'FIELD:tx_ailabel_origin:!=:' . AiOrigin::Human->value,
        ];
        $tca['sys_file_metadata']['palettes']['aiLabelWatermark'] = [
            'showitem' => 'tx_ailabel_watermark_position, tx_ailabel_watermark_color, tx_ailabel_watermark_width',
            'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:palette.aiLabelWatermark.description',
        ];
        $tca['sys_file_metadata']['columns']['tx_ailabel_watermark'] = [
            'label' => 'tx_ailabel_watermark',
            'config' => [
                'type' => 'json',
                // Same nullable/default gotcha as tx_ailabel_metadata.
                'nullable' => true,
                'default' => null,
            ],
        ];

        foreach ($tca['sys_file_metadata']['types'] ?? [] as $typeKey => $typeConfig) {
            $tca['sys_file_metadata']['types'][$typeKey]['showitem'] = rtrim($typeConfig['showitem'] ?? '', ', ')
                . ', --palette--;;aiLabelWatermark';
        }

        $event->setTca($tca);
    }
}
