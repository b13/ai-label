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
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

// Adds the "ai_created" and "ai_modified" checkboxes to every editable table's TCA.
// The fields (and "reviewed") are not backed by their own database columns - their
// values are folded by AiMetaDataHandlerHook into the ai_metadata column added below,
// a real type=json TCA column (DefaultTcaSchema auto-creates the DB column for
// type=json fields) that is never part of any type's showitem/palette, so it never
// renders in the form.
#[AsEventListener(identifier: 'ai-label/add-ai-meta-fields-to-tca')]
final class AddAiMetaFieldsToTca
{
    public function __construct(private readonly ApplicableTablesProvider $applicableTablesProvider)
    {
    }

    public function __invoke(AfterTcaCompilationEvent $event): void
    {
        $tca = $event->getTca();

        foreach ($this->applicableTablesProvider->getApplicableTables() as $tableName) {
            $tableConfig = $tca[$tableName];

            // type=user (not type=check) so DefaultTcaSchema never auto-creates a real
            // database column for these - VirtualCheckboxElement renders them exactly
            // like a normal checkboxToggle field.
            $tca[$tableName]['columns']['ai_created'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_created',
                'onChange' => 'reload',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelVirtualCheckbox',
                ],
            ];
            $tca[$tableName]['columns']['ai_modified'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_modified',
                'onChange' => 'reload',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelVirtualCheckbox',
                ],
            ];
            // Only relevant while the record is flagged as AI-created/-modified
            $tca[$tableName]['columns']['reviewed'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.reviewed',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelVirtualCheckbox',
                ],
                'displayCond' => [
                    'OR' => [
                        'FIELD:ai_created:=:1',
                        'FIELD:ai_modified:=:1',
                    ],
                ],
            ];
            $tca[$tableName]['palettes']['aiLabelMetadata'] = [
                'showitem' => 'ai_created, ai_modified, --linebreak--, reviewed',
            ];
            $tca[$tableName]['columns']['ai_metadata'] = [
                'label' => 'ai_metadata',
                'config' => [
                    'type' => 'json',
                ],
            ];

            foreach ($tableConfig['types'] ?? [] as $typeKey => $typeConfig) {
                $tca[$tableName]['types'][$typeKey]['showitem'] = rtrim($typeConfig['showitem'] ?? '', ', ')
                    . ', --div--;LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:tabs.aiMetadata'
                    . ', --palette--;;aiLabelMetadata';
            }
        }

        $event->setTca($tca);
    }
}
