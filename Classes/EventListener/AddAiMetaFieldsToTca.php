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
use B13\AiLabel\Domain\Enum\AiOrigin;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

// Adds the "tx_ailabel_origin" select (Human/Generated/Manipulated, exclusive) to
// every editable table's TCA. The field (and "tx_ailabel_reviewed") is not backed by
// its own database column - its value is folded by AiMetaDataHandlerHook into the
// tx_ailabel_metadata column added below, a real type=json TCA column
// (DefaultTcaSchema auto-creates the DB column for type=json fields) that is never
// part of any type's showitem/palette, so it never renders in the form.
//
// All three field names are prefixed (tx_ailabel_...) to avoid colliding with a
// field of the same name some other extension might add to the same table -
// unlike the JSON keys inside the metadata column itself, which stay short
// (origin/reviewed_by/reviewed_timestamp) since that column is entirely private to
// this extension.
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

            // type=user (not type=select) so DefaultTcaSchema never auto-creates a real
            // database column for this - VirtualSelectElement renders it exactly like a
            // normal selectSingle field. 'items' is already in the plain shape
            // SelectSingleElement::render() reads directly - TcaSelectItems (the core
            // provider that normally resolves 'items') only runs for type=select, so it
            // never touches this field.
            $tca[$tableName]['columns']['tx_ailabel_origin'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin',
                'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.description',
                'onChange' => 'reload',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelVirtualSelect',
                    'items' => [
                        ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.human', 'value' => AiOrigin::Human->value],
                        ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.generated', 'value' => AiOrigin::Generated->value],
                        ['label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.ai_origin.manipulated', 'value' => AiOrigin::Manipulated->value],
                    ],
                ],
            ];
            // Only relevant while the record is flagged as AI-created/-modified
            $tca[$tableName]['columns']['tx_ailabel_reviewed'] = [
                'label' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.reviewed',
                'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:field.reviewed.description',
                'config' => [
                    'type' => 'user',
                    'renderType' => 'aiLabelVirtualCheckbox',
                ],
                'displayCond' => 'FIELD:tx_ailabel_origin:!=:' . AiOrigin::Human->value,
            ];
            // The palette description carries the "why does this exist at all" framing
            // (EU AI Act, Article 50) once, above both fields - the per-field descriptions
            // below it only explain how to fill that particular field in.
            $tca[$tableName]['palettes']['aiLabelMetadata'] = [
                'showitem' => 'tx_ailabel_origin, tx_ailabel_reviewed',
                'description' => 'LLL:EXT:ai_label/Resources/Private/Language/locallang_db.xlf:palette.aiMetadata.description',
            ];
            $tca[$tableName]['columns']['tx_ailabel_metadata'] = [
                'label' => 'tx_ailabel_metadata',
                'config' => [
                    'type' => 'json',
                    // Without this, FormEngine's DatabaseRowDefaultValues provider force-casts
                    // a NULL value to '' before EnrichAiMetaData ever sees it - both for an
                    // existing record whose column is genuinely NULL (isset() is false for a
                    // null value, so it never takes the "keep current value" branch) and for
                    // a brand new record (the field has no TCA default, so it falls back to
                    // the same '' cast). 'nullable' + a 'default' of null make that provider
                    // preserve/produce PHP null in both cases instead.
                    'nullable' => true,
                    'default' => null,
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
